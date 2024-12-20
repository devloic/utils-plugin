<?php

declare(strict_types=1);

namespace Plugin\ExamplePlugin\Command;

use App\Console\Command\CommandAbstract;
use App\Container\EntityManagerAwareTrait;
use App\Exception\CannotProcessMediaException;
use App\Utilities\Types;

use App\Radio\Enums\BackendAdapters;
use App\Radio\Enums\FrontendAdapters;
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
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use App\Entity\StationPlaylist;

use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use App\Container\LoggerAwareTrait;
use App\Controller\Api\Admin\StationsController;
use App\Doctrine\ReloadableEntityManagerInterface;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Plugin\ExamplePlugin\Commons\Utils;
use App\Radio\Adapters;

//wrapper class to remap protected editRecord method in order to make it public
class StationsController2 extends StationsController



    {
        use LoggerAwareTrait;

        public function __construct(
            protected StationRepository $stationRepo,
            protected StorageLocationRepository $storageLocationRepo,
            protected StationQueueRepository $queueRepo,
            protected Configuration $configuration,
            protected Nginx $nginx,
            Serializer $serializer,
            ValidatorInterface $validator,

            protected ReloadableEntityManagerInterface $em,


        ) {
            parent::__construct($stationRepo, $storageLocationRepo,$queueRepo,$configuration,$nginx,$serializer,$validator);
        }

    public function editRecord(?array $data, object $record = null, array $context = []): object

    {
        return parent::editRecord($data,  $record ,  $context  );

    }



            
    }

#[AsCommand(
    name: 'utils:create-station',
    description: 'create a new station',
)]



final class CreateStation extends CommandAbstract 
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
        private readonly Adapters $adapters,


    ) {
         parent::__construct();
    }

  
    private   OutputInterface $output;


    public function createStation(String $stationame): void
    {
        $stationController2=new StationsController2($this->stationRepo,
        $this->storageLocationRepo,
        $this->queueRepo,
        $this->configuration,
        $this->nginx,
        $this->serializer,
        $this->validator,
        $this->em);
       
        $stationController2->setEntityManager($this->em);

        $station = new Station();
        $record=null;

        $name="$stationame";
        $station->setName($name);

        $name=$station->getName();
        $short_name=$station->getShortName();
   
        $this->logger->error(
            $short_name
        );
        //$data was copy/pasted from a log output  in StationsController->editRecord() 
        //(except $name value , $short_name field and value, and "has_started"=>true,)
        
        //don't insert two stations with the same short_name as this will raise a
        //ValidationException.php  "Object(App\Entity\Station).short_name: This value is already used."
     
        //will need upgrades in the future if new fields are created for stations

        $data=array(
            "name"=>"$name",
            "description"=>"",
            "short_name"=>"$short_name",
            "genre"=>"",
            "url"=>"",
            "timezone"=>"UTC",
            "enable_public_page"=>true,
            "enable_on_demand"=>false,"enable_on_demand_download"=>true,"frontend_type"=>"icecast",
            "frontend_config"=>array("sc_license_id"=>"","sc_user_id"=>"","source_pw"=>"","admin_pw"=>""),
            "backend_type"=>"liquidsoap",
            "backend_config"=>array(
                "crossfade_type"=>"normal","crossfade"=>2,"write_playlists_to_liquidsoap"=>true,
                "audio_processing_method"=>"none","post_processing_include_live"=>true,
                "master_me_preset"=>"music_general","master_me_loudness_target"=>-16,"stereo_tool_license_key"=>"","enable_auto_cue"=>false,
                "enable_replaygain_metadata"=>false,"hls_enable_on_public_player"=>false,
                "hls_is_default"=>false,"record_streams"=>false,"record_streams_format"=>"mp3",
                "record_streams_bitrate"=>128,"dj_buffer"=>5,"live_broadcast_text"=>"Live Broadcast"
            ),
            "enable_hls"=>false,"enable_requests"=>false,"request_delay"=>5,
            "request_threshold"=>15,"enable_streamers"=>false,
            "disconnect_deactivate_streamer"=>0,"media_storage_location"=>"",
            "recordings_storage_location"=>"","podcasts_storage_location"=>"",
            "is_enabled"=>true,"max_bitrate"=>0,"max_mounts"=>0,"max_hls_streams"=>0,
            "has_started"=>true,
           
            );
            $context = []   ;

       $stationController2->editRecord($data, $record ,  $context );

       $station= $this->getStationByShortname($station->getShortName());

       $this->runCommand(
        $this->output,
        'azuracast:radio:restart',
        [
            'station-name' => $station->getShortName(),
            
        ]
    );
  

/*       
//other methods
//backend/src/Entity/Fixture/StationFixture.php

        $station = new Station();
        $station->setName('AzuraTest Radio');
        $station->setDescription('A test radio station.');
   
        $station->setEnableRequests(false);
        $station->setFrontendType(FrontendAdapters::Icecast);
        $station->setBackendType(BackendAdapters::Liquidsoap);
        $station->setEnableHls(false);
        $station->setRadioBaseDir('/var/azuracast/stations/azuratest_radio');
        $station->setHasStarted(true);
        $station->ensureDirectoriesExist();

        $mediaStorage = $station->getMediaStorageLocation();
        $recordingsStorage = $station->getRecordingsStorageLocation();
        $podcastsStorage = $station->getPodcastsStorageLocation();

        $stationQuota = getenv('INIT_STATION_QUOTA');
        if (!empty($stationQuota)) {
            $mediaStorage->setStorageQuota($stationQuota);
            $recordingsStorage->setStorageQuota($stationQuota);
            $podcastsStorage->setStorageQuota($stationQuota);
        }

        $this->em->persist($station);
        $this->em->persist($mediaStorage);
        $this->em->persist($recordingsStorage);
        $this->em->persist($podcastsStorage);
        
        $this->em->flush();
*/
    }
   
    protected function configure(): void
    {
        $this->addArgument("name", InputArgument::REQUIRED)->addOption('proceed', null, InputOption::VALUE_NONE);

    }


    private function getStationByShortname($shortname): ?Station
    {   
        
        return Utils::getStationByShortname($shortname,$this->stationRepo);
      
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {   
        $this->output=$output;

        $io = new SymfonyStyle($input, $output);
        $io->title('Utils plugin: create-station');
        
        $stationName = $input->getArgument("name");
        $options = $input->getOptions();

        $station = $this->getStationByShortname($stationName);
        $rows = array();
        $headers = [
           
        ];
        $rows[] = ["", "", ""];
        if ($options["proceed"]) {

          
            $rows[] = ["Station", "State", "Result"];
            $rows[] = ["", "", ""];
            if ($station !==null){
                $rows[] = ["    $stationName", "already exists", "ignoring"];
                $rows[] = ["", "", ""];

                $io->table($headers, $rows);
               return 1;
            }else{
                $this->createStation($stationName);
                $rows[] = ["    $stationName", "new", "created"];
                $rows[] = ["", "", ""];

                $io->table($headers, $rows);
                return 0;
            }

           
        } else {

          

            $headers = [
              
            ];

            $rows[] = ["You ran a dry-run", "to proceed with the station creation add --proceed", "azuracast_cli utils:create-station --proceed $stationName"];
            $rows[] = ["", "", ""];
            $rows[] = ["", "", ""];

            $rows[] = ["Station", "State", "Result"];
            $rows[] = ["", "", ""];
            
            if ($station !==null){
                $rows[] = ["    $stationName", "already exists", "will skip creation"];
            }else{
                $rows[] = ["    $stationName", "is new", "will be created"];
            }
            $rows[] = ["", "", ""];

            $io->table($headers, $rows);
            return 1;
        }


      
    }
}
