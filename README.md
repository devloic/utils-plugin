During development with azuracast or when deploying a new station with lots of content it becomes boring to recreate stations, playlists and upload media
manually. This plugin will create stations, playlists and import media using a one-shot process with the azuracast_cli command.

## installing utils-plugin

Follow documentation of the (example-plugin)(https://github.com/AzuraCast/example-plugin).
You might need to run "composer install" inside /var/azuracast/www and inside /var/azuracast/www/plugins/utils-plugin for azuracast_cli to work and 
for azuracast_cli to include the plugin.

## usage

dry-run, prints what will be done:
```
azuracast_cli utils:import-media mymedia4
```

proceed with the import and underlying station and playlist creations:
```
azuracast_cli utils:import-media mymedia4 --proceed 
```


This plugin expects that the music files are organized under a simple directory tree:

```
mymedia4
├── station1
│   ├── myexistingplaylist
│   │   ├── musicfile1.mp3
│   │   └── musicfile2.flac
│   └── newplaylist
│       └── musicfile3.mp3
└── teststation5
    ├── myplaylist
    │   ├── skid_row27_2.cus
    └── myplaylist2
        ├── enigma.mod
        └── Paranoimia.sid
```

The import-media task will check for existing stations and related playlists. The "station" directories inside "mymedia4" refer to the station's "shortname" which are unique identifiers so if you want to add content to and existing station be sure to refer to their shortname when naming your station directory. Same naming logic applies to playlists inside stations.

The station's content will be copied under the stations media folder using azuracast internal functions.

Example for teststation5:

```
/var/azuracast/stations/teststation5/media/
├── myplaylist
│   ├── skid_row27_2.cus
    └── musicfile7.mp3
└── myplaylist2
    ├── enigma.mod
    └── Paranoimia.sid
```

Each playlist directory (ex ```/var/azuracast/stations/teststation5/media/myplaylist```) will be added to the new/existing azuracast playlist with ```StationPlaylistFolderRepository->addPlaylistsToFolder``` which means that any content that you might manually add later on to this folder 
using the azuracast web UI will automatically be part of the underlying azuracast playlist (a folder can be attached to several playlists so there might actually
be several underlying azuracast playlists).

Empty playlists will be ignored. 
Empty stations (a station which does not include at least 1 playlist containing at least 1 music file) will be ignored.

utils-plugin also expose a create-station task which creates a new station and starts it:
```
azuracast_cli utils:create-station mynewstation
```
proceed with the station creation and start it:
```
azuracast_cli utils:create-station mynewstation --proceed 
```

## azuracast plugins

This plugin was developed as part of the [azuracast-amiga](https://github.com/devloic/azuracast-amiga) work which 
integrates [uade](https://gitlab.com/uade-music-player/uade) to azuracast so more the 200 Amiga computer music formats
can be played with azuracast. 

Plugins are a good way to add funcionality to azuracast without modifying the main azuracast code.
It becomes easy to add an azuracast_cli command which this plugin does.
More information on plugins is available via
the [AzuraCast Documentation](https://www.azuracast.com/docs/developers/plugins/).
This plugin with built using the (example-plugin)(https://github.com/AzuraCast/example-plugin) as a base.
