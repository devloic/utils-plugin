<?php

declare(strict_types=1);

namespace Plugin\ExamplePlugin\Command;

use App\Console\Command\CommandAbstract;
use App\Container\EntityManagerAwareTrait;
use App\Exception\CannotProcessMediaException;


use App\Entity\Repository\StationRepository;
use App\Entity\Station;
use App\Nginx\Nginx;
use App\Radio\Configuration;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\Exception\StorageLocationFullException;
use App\Entity\StationMedia;
use App\Media\MediaProcessor;
use App\Entity\Repository\StationPlaylistFolderRepository;
use App\Entity\Repository\StationPlaylistMediaRepository;

use App\Entity\Repository\StationQueueRepository;
use App\Entity\Repository\StorageLocationRepository;
use App\Entity\StationPlaylist;

use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use App\Container\LoggerAwareTrait;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Filesystem\Filesystem;
use Plugin\ExamplePlugin\Commons\Utils;

#[AsCommand(
    name: 'utils:import-media',
    description: 'import media to stations',
)]

final class ImportMedia extends CommandAbstract
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;

    public function __construct(
        protected StationRepository $stationRepo,
        protected StorageLocationRepository $storageLocationRepo,
        protected StationQueueRepository $queueRepo,
        protected Configuration $configuration,
        protected Nginx $nginx,
        protected Serializer $serializer,
        protected ValidatorInterface $validator,
        private readonly MediaProcessor $mediaProcessor,
        private readonly StationPlaylistMediaRepository $spmRepo,
        private readonly StationPlaylistFolderRepository $spfRepo,

    ) {
        parent::__construct();
    }

    private   OutputInterface $output;
    private   InputInterface $input;

    private function getStationByShortname($shortname): ?Station
    {

        return Utils::getStationByShortname($shortname, $this->stationRepo);
    }

    private function getPlaylistByName($station, $playlistname): ?StationPlaylist
    {
        $found = null;
        $playlists = $station->getPlaylists();
        $playlists = $playlists->toArray();
        foreach ($playlists as $playlist) {
            if ($playlist->getShortName() == $playlistname) {
                $found = $playlist;
            }
        }
        return $found;
    }

    public function runPlan($plan) : int
    {

        $all_stations = array_merge($plan["mediapath"]["existing_valid_stations"], $plan["mediapath"]["new_valid_stations"]);

        foreach ($all_stations as $key => $a_station) {
            if (!$a_station["exists"]) {
                $a_station_name = $a_station["shortname"];
                $this->runCommand(
                    $this->output,
                    'utils:create-station',
                    [
                        'name' => $a_station_name,
                        '--proceed' => true,
                    ]
                );

                $station = $this->getStationByShortname($a_station_name);
                $a_station["station"] = $station;
                $a_station["exists"] = true;
            } else {
                $station = $a_station["station"];
            }


            $mediaStorage = $station->getMediaStorageLocation();

            if ($mediaStorage->isLocal()) {
                $mediaStoragePath = $mediaStorage->getPath();

                $all_playlists = array_merge($a_station["existing_valid_playlists"], $a_station["new_valid_playlists"]);

                foreach ($all_playlists as $key2 => $playlist) {
                    $folder_path = $playlist["shortname"];
                    $current_playlist_obj = $playlist["playlist"];
                    if (!$playlist["exists"]) {
                        //create the playlist if it's new
                        $current_playlist_obj = new StationPlaylist($station);
                        $current_playlist_obj->setName($playlist["shortname"]);
                        $this->em->persist($current_playlist_obj);
                        $this->em->flush();
                    }

                    //make it "assigned to the directory"
                    $this->spfRepo->addPlaylistsToFolder($station, $folder_path, array($current_playlist_obj->getId() => 3));

                    try {

                        foreach ($playlist["files"] as $name => $file) {
                            $filesize=filesize($file);
                            if (!$mediaStorage->canHoldFile($filesize)) {
                                throw new StorageLocationFullException();
                            }
                            $stationMedia = $this->mediaProcessor->processAndUpload(
                                $mediaStorage,
                                $folder_path . "/$name",
                                $file

                            );
                            $mediaStorage->addStorageUsed($filesize);
                                $this->em->persist($mediaStorage);
                                $this->em->flush();

                            if ($stationMedia instanceof StationMedia) {
                                // If the user is viewing a regular directory, check for playlists assigned to the directory and assign
                                // them to this media immediately.
                                $playlistIds = $this->spfRepo->getPlaylistIdsForFolderAndParents($a_station["station"], $playlist["shortname"]);
                                if (!empty($playlistIds)) {
                                    foreach ($playlistIds as $playlistId) {
                                        $xplaylist = $this->em->find(StationPlaylist::class, $playlistId);
                                        if (null !== $xplaylist) {
                                            $this->spmRepo->addMediaToPlaylist($stationMedia, $xplaylist);
                                        }
                                    }
                                    $this->em->flush();
                                }
                               
                        
                                
                            }
                        }
                    } catch (CannotProcessMediaException $e) {
                        $this->logger->error(
                            $e->getMessageWithPath(),
                            [
                                'exception' => $e,
                            ]
                        );
                        //throw $e;
                        return 1;
                    }
                    catch(StorageLocationFullException $e)
                        {
                            $this->logger->error(
                                $e->getFormattedMessage(),
                                [
                                    'exception' => $e,
                                ]
                            );
                            //throw $e;
                            return 1;
                        }
                }
               
            }
        }

        return 0;
        
    }


    public function createPlan(
        String $mediapath

    ): array {

        $plan = array();
        $mediapath_hasfiles = false;
        $mediapath_hasstations = false;
        $mediapath_hasplaylists = false;
        $mediapath_has_new_and_empty_playlist = false;
        $mediapath_has_new_and_empty_station = false;

        $plan["mediapath"] = array("mediapath" => $mediapath, "mediapath_hasfiles" => $mediapath_hasfiles, "mediapath_hasstations" => $mediapath_hasstations, "mediapath_hasplaylists" => $mediapath_hasplaylists);
        $plan["mediapath"]["new_and_empty_playlists"] = array();
        $plan["mediapath"]["new_and_empty_stations"] = array();
        $plan["mediapath"]["new_non_empty_stations"] = array();
        $plan["mediapath"]["stations_to_ignore"] = array();
        $plan["mediapath"]["new_valid_stations"] = array();
        $plan["mediapath"]["existing_valid_stations"] = array();

        $plan["stations"] = array();
        $plan["fatal"] = false;

        $filesystem = new Filesystem();
        if (!$filesystem->exists($mediapath)) {
            $plan["error"][] = array("msg" => "'" . $mediapath . "'" . " : No such file or directory", "fatal" => true);
            $plan["fatal"] = true;
            return $plan;
        }

        $finder = new Finder();
        // find all files in the current directory
        $finder->directories()->depth('== 0')->in($mediapath);

        //check all directories, should be the shortname of each station, add to plan

        foreach ($finder as $dir) {
            $mediapath_hasstations = true;
            $absoluteFilePath = $dir->getRealPath();
            /* if (! $file->isDir()){
                    $plan["error"][]=array("msg"=>"'".$absoluteFilePath."'"." : orphan file, ignoring" ,"fatal"=>false);
                }else{*/
            $dirname = $dir->getRelativePathname();
            $station = $this->getStationByShortname($dirname);


            $finder2 = new Finder();
            $station_hasplaylists = false;
            $station_hasfiles = false;
            $finder2->directories()->depth('== 0')->in($absoluteFilePath);
            $plan["stations"][$dirname] = array("name" => $station === null ?  $dirname : $station->getName(), "shortname" => $dirname, "hasfiles" => $station_hasfiles, "hasplaylists" => $station_hasplaylists, "exists" => $station === null ? false : true, "path" => $absoluteFilePath, "station" => $station, "playlists" => array());
            //loop on playlists (directories)
            foreach ($finder2 as $dir2) {
                $mediapath_hasplaylists = true;
                $dirname2 = $dir2->getRelativePathname();
                $playlist = $station == null ? null : $this->getPlaylistByName($station, $dirname2);
                $absolutePlaylistFilePath = $dir2->getRealPath();
                //check if playlist directory has files
                $finder3 = new Finder();
                $finder3->files()->depth('== 0')->in($absolutePlaylistFilePath);
                $plan["stations"][$dirname]["playlists"][$dirname2] = array("shortname" => $dirname2, "hasfiles" => false, "files" => array(), "playlist" => $playlist, "path" => $absolutePlaylistFilePath, "exists" => $playlist !== null ? true : false);
                //$plan["error"][] = array("msg" => "'" . $absolutePlaylistFilePath . "'" . " : found empty playlist $dirname2, ignoring", "fatal" => false);
                foreach ($finder3 as $file) {
                    $filename = $file->getFilename();
                    $plan["stations"][$dirname]["playlists"][$dirname2]["files"][$filename] = $file->getRealPath();

                    $plan["stations"][$dirname]["playlists"][$dirname2]["hasfiles"] = true;
                    $station_hasfiles = true;
                    $mediapath_hasfiles = true;
                }
                $station_hasplaylists = true;

                if (!$station_hasfiles && $playlist === null) {
                    $mediapath_has_new_and_empty_playlist = true;
                    $plan["mediapath"]["new_and_empty_playlists"][] = $plan["stations"][$dirname]["playlists"][$dirname2];
                }



                //empty and new playlist
                if ($plan["stations"][$dirname]["playlists"][$dirname2]["hasfiles"] == false && $playlist == null) {
                    $plan["stations"][$dirname]["new_and_empty_playlists"][] = $plan["stations"][$dirname]["playlists"][$dirname2];
                } else {
                    if ($playlist === null) {
                        //new playlist, non empty
                        $plan["stations"][$dirname]["new_non_empty_playlists"][] = $plan["stations"][$dirname]["playlists"][$dirname2];
                    } elseif ($plan["stations"][$dirname]["playlists"][$dirname2]["hasfiles"] == false) {   //existing playlist, empty
                        //nothing to do
                        $plan["stations"][$dirname]["existing_empty_playlists"][] = $plan["stations"][$dirname]["playlists"][$dirname2];
                    } else {
                        //existing playlist, non empty
                        $plan["stations"][$dirname]["existing_non_empty_playlists"][] = $plan["stations"][$dirname]["playlists"][$dirname2];
                    }
                }
            }
            //new station may have playlists but no files
            //should be ignored by default
            if (!$station_hasfiles  && $station === null) {
                $mediapath_has_new_and_empty_station = true;
                $plan["mediapath"]["new_and_empty_stations"][] = $plan["stations"][$dirname];
            }

            $plan["stations"][$dirname]["hasfiles"] = $station_hasfiles;
            $plan["stations"][$dirname]["hasplaylists"] = $station_hasplaylists;


            if (!$station_hasfiles) {
                $plan["mediapath"]["stations_to_ignore"][] = $plan["stations"][$dirname];
            } else {
                if ($plan["stations"][$dirname]["exists"] == false) {
                    $plan["mediapath"]["new_valid_stations"][] = $plan["stations"][$dirname];
                } else {
                    $plan["mediapath"]["existing_valid_stations"][] = $plan["stations"][$dirname];
                }
            }
        }

        $plan["mediapath"]["mediapath_hasfiles"] = $mediapath_hasfiles;
        $plan["mediapath"]["mediapath_hasstations"] = $mediapath_hasstations;
        $plan["mediapath"]["mediapath_hasplaylists"] = $mediapath_hasplaylists;
        $plan["mediapath"]["mediapath_has_new_and_empty_playlist"] = $mediapath_has_new_and_empty_playlist;

        $plan["mediapath"]["mediapath_has_new_and_empty_station"] = $mediapath_has_new_and_empty_station;

        $rows = array();
        $rows[] = ["You ran a dry-run", "to proceed with the import add --proceed", "azuracast_cli utils:import-media --proceed $mediapath"];
        $rows[] = ["", "", "", ""];
        $rows[] = ["Stations (name/shortname)", "Playlists name/shortname (music files)", ""];
        $rows[] = ["", "", "", ""];
        $rows[] = ["Existing stations", "", ""];
        $rows[] = ["", "", "", ""];
        $this->updateRowsAndStations($plan["mediapath"]["existing_valid_stations"], $rows);
        $rows[] = ["New stations", "", ""];
        $rows[] = ["", "", "", ""];
        $this->updateRowsAndStations($plan["mediapath"]["new_valid_stations"], $rows);
        $rows[] = ["", "", "", ""];
        $rows[] = ["Ignored stations (no music files)", "", ""];
        $rows[] = ["", "", "", ""];
        $this->updateRowsAndStations($plan["mediapath"]["stations_to_ignore"], $rows);
        $rows[] = ["", "", "", ""];

        return  array($plan, $rows);
    }

    protected function configure(): void
    {
        $this->addOption('proceed', null, InputOption::VALUE_NONE)
            ->addArgument("path_to_media", InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;

        $io = new SymfonyStyle($input, $output);
        $io->title('Utils Plugin: Import Media');

        $options = $input->getOptions();
        $mediapath = $input->getArgument("path_to_media");

        [$plan, $rows] = $this->createPlan($mediapath);

        if ($plan["fatal"]) {
            $headers = [
                'error',
                'description',
            ];
            foreach ($plan["error"] as $error) {
                $rows[] = [$error["fatal"], $error["msg"]];
            }
            $io->table($headers, $rows);
            return 1;
        }
        if ($options["proceed"]) {
            $this->runPlan($plan);
            return 0;
        } else {
            $headers = ['', '', '',];
            $io->table($headers, $rows);
            return 1;
        }
    }

    //updates $rows for cli table view and groups playlists inside stations : new_valid_playlists, existing_valid_playlists,playlists_to_ignore
    // modifies provideds $stations variable !
    public function updateRowsAndStations(&$stations, &$rows): array
    {


        foreach ($stations as $key => $a_station) {
            $station_name = $a_station["name"];
            $rows[] = ["      " . $station_name . "/" . $a_station["shortname"], "", ""];

            $existing_valid_playlists = "";
            $new_valid_playlists = "";
            $playlists_to_ignore = "";
            $stations[$key]["new_valid_playlists"] = array();
            $stations[$key]["existing_valid_playlists"] = array();
            $stations[$key]["playlists_to_ignore"] = array();

            foreach ($a_station["playlists"] as $key2 => $playlist) {

                if ($playlist["hasfiles"]) {
                    if ($playlist["exists"] == false) {
                        $new_valid_playlists = $new_valid_playlists . $playlist["shortname"] . "/" . $playlist["shortname"] . " (" . sizeof($playlist["files"]) . ") ";
                        $stations[$key]["new_valid_playlists"][] = $playlist;
                    } else {
                        $existing_valid_playlists = $existing_valid_playlists . $playlist["playlist"]->getName() . "/" . $playlist["shortname"] . " (" . sizeof($playlist["files"]) . ") ";
                        $stations[$key]["existing_valid_playlists"][] = $playlist;
                    }
                } else {
                    if ($playlist["exists"] == false) {
                        $playlists_to_ignore = $playlists_to_ignore . $playlist["shortname"] . "/" . $playlist["shortname"] . " ";
                    } else {
                        $playlists_to_ignore = $playlists_to_ignore . $playlist["playlist"]->getName() . "/" . $playlist["shortname"] . " ";
                    }
                    $stations[$key]["playlists_to_ignore"][] = $playlist;
                }
            }

            if ($new_valid_playlists != "") $rows[] = ["                  ", "create  : " . $new_valid_playlists, "", ""];
            if ($existing_valid_playlists != "") $rows[] = ["                  ", "existing: " . $existing_valid_playlists, "", ""];
            if ($playlists_to_ignore != "") $rows[] = ["                  ", "ignore  : " . $playlists_to_ignore, "", ""];

            $rows[] = ["", "", "", ""];
        }



        return $rows;
    }
}
