#!/usr/bin/php
<?php
/**
 * Auto-download from RSS feeds
 * Copyright (C) 2011 Etienne Lachance <et@etiennelachance.com>
 *
 * Base on work of
 *      Johan Adriaans <johan.adriaans@gmail.com>, Bryce Chidester <bryce@cobryce.com>
 *      http://code.google.com/p/php-transmission-class/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

// Include RPC class
require_once( dirname( __FILE__ ) . '/lib/TransmissionRPC.class.php' );

if(!file_exists("config.ini"))
    die("Please create a config.ini file".PHP_EOL);

// config file
$config = parse_ini_file("config.ini", true);

// validate config file
if(!array_key_exists("destinationFolder", $config['general']))
        die("ERROR: distinationFolder is not set in config.ini".PHP_EOL);

// get Database
$db = new PDO('sqlite:shows.db');
// create table if not exist
$db->query("CREATE TABLE IF NOT EXISTS shows (id INTEGER PRIMARY KEY, show_name TEXT, season INTEGER, episode INTEGER, created_at TEXT)");

$toDownload = array();

foreach($config['shows'] as $show => $url) {
    // get RSS Feed
    echo "Fetching RSS for $show".PHP_EOL;
    $xml = simplexml_load_file($url);

    foreach($xml->channel->item as $item) {
        // Contruct $info array
        $infoTmp = explode(";", $item->description);
        $info = array();
        foreach($infoTmp as $attribute) {
            $tmp = explode(":", $attribute);
            $info[trim($tmp[0])] = trim($tmp[1]);
        }
        $info['link'] = $item->link;
        // end Construct $info array

        echo "Checking if ".$info['Show Name'].": S".$info['Season']."E".$info['Episode']."... ";

        $sql = "SELECT * FROM shows WHERE show_name = '".$info['Show Name']."' AND season = ".$info['Season']." AND episode = ".$info['Episode'].";";
        $res = $db->query($sql)->fetchAll();
        
        if(count($res) == 0) {
            echo "not exist in local DB, ask XBMC ...";
            echo "not in XBMC, mark to be downloaded".PHP_EOL;
            $toDownload[] = $info;
        } else {
            echo " already downloaded".PHP_EOL;
        }
    }
}


// Add torrents to Transmission
if ( count( $toDownload ) > 0 ) {

  // create new transmission communication class
  $rpc = new TransmissionRPC();
  
  // Set authentication when needed
  $rpc->url             = $config['transmission']['url'];
  $rpc->username  = $config['transmission']['username'];
  $rpc->password   = $config['transmission']['password'];
  
  // Loop through filtered results, add torrents and set download path to $series_folder/$show (e.g: /tmp/Futurama);
  foreach ( $toDownload as $episode ) {
      $name = $episode['Show Name'].".S".sprintf("%02d",$episode['Season'])."E".sprintf("%02d",$episode['Episode']);
        print "Adding: ".$name.".. ";

        $target = $config['general']['destinationFolder'] . '/' . $episode['Show Name'] .'/Season '. $episode['Season'];
        echo $target;
        if(!file_exists($target))
            mkdir($target, "0777", true);

        try {
            $result = $rpc->add( (string) $episode['link'], $target );
            
            if($result->result == "success") {
                $id = $result->arguments->torrent_added->id;
                print " [{$result->result}] (id=$id)\n";
                $db->query("INSERT INTO shows (show_name,season,episode,created_at) VALUES ('".$episode['Show Name']."',".$episode['Season'].",".$episode['Episode'].",'".$episode['Episode']."')");
            } else {
                print "warning/error from transmission\n";
            }
        } catch (Exception $e) {
            die('Caught exception: ' . $e->getMessage() . PHP_EOL);
        }
    } 
}
