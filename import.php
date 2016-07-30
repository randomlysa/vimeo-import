<?php
// two slashes = comment, save (don't delete)
/// three slashes = debug lines (mostly print/print_r, etc)
//// four = temporarily disabled, probably for testing purposes 

/*
	goes through ****all*** the videos in an album, imports oldest to newest. 
	* perhaps in the future, allow the ability to import only one page, to speed up adding new videos?
	* import2 will : 
		get all albums, 
		get number of videos in each album, 
		compare to number of videos in DB for that album, 
		ask for (number of videos in album) - (number of videos in DB)
		import all new videos for all albums
*/

// todo - select album and add album info into database (don't remember what this means)
// check 2015 07 05 - what if I make a new album in vimeo? is it automatically added/imported?


require("autoload.php");

use Vimeo\Vimeo;
/**
* Copyright 2013 Vimeo
*
* Licensed under the Apache License, Version 2.0 (the "License");
* you may not use this file except in compliance with the License.
* You may obtain a copy of the License at
*
* http://www.apache.org/licenses/LICENSE-2.0
*
* Unless required by applicable law or agreed to in writing, software
* distributed under the License is distributed on an "AS IS" BASIS,
* WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
* See the License for the specific language governing permissions and
* limitations under the License.
*/

include_once('config.inc.php');

// two lines for vimeo api authentication
$lib = new Vimeo($config['vimeo_client_id'], $config['vimeo_client_secret']);
$lib->setToken($config['vimeo_access_token']);


// a small bit of security.
$key = $_GET["key"];
	if ($key != "bdg8irYIIG1VrptlTAH2") {exit;}

// connect to database
	$mysqli = new mysqli($config['dbhost'], $config['dbuser'], $config['dbpass'], $config['database']);

	if ($mysqli->connect_error) {
		die('Error : ('. $mysqli->connect_errno .') '. $mysqli->connect_error);
	}

// get all albums from Vimeo 
$getAlbumsResponse = $lib->request('/me/albums/', 'GET');

// pull name/url for each album to make a form
$albumInfo = array();
$i = 0;
while ($getAlbumsResponse["body"]["data"][$i]["name"]) {
	// get the full album uri
	$uri = $getAlbumsResponse["body"]["data"][$i]["uri"];
	$uriPieces = explode("/", $uri);

	// get just the unique part of the URI - the digits that indicate the actual album
	$finalURI = $uriPieces[4];
	$albumName = $getAlbumsResponse["body"]["data"][$i]["name"];
	
	$albumInfo[] = array("name" => $albumName, "uri" => $finalURI);
	$i++;
}

// make a form here from array albumInfo() to pick which albums to import.
print "
		<form method=\"post\">\n
		<h1>List of Albums</h1>
	";

foreach ($albumInfo as $album) {
	// $getAlbum = $album['uri'];
	$albumURI = $album['uri'];
	$title = $album['name'];
	
	$albumNameInsert = $title;
	
	print "
			<label>
			<input type=\"checkbox\" name=\"$title\" value=\"$albumURI\">$title
			</label><br>\n
			
	";
}
print "
		<input type=\"submit\" value=\"Check Selected Albums for New Videos\">
	</form>
	";


$selectedAlbumsArray = array();
// see if there are any albums in $_POST[]
foreach ($_POST as $param_name => $param_val) {
    echo "Param: $param_name; Value: $param_val<br />\n";
    // add selected albums to an array using same format as $albumInfo (all albums from vimeo)
	// selectedAlbumsArray["$param_name"] = $param_val;
	$selectedAlbumsArray[] = array("name" => $param_name, "uri" => $param_val);
}


// if there is post data (selected albums), run the rest of the script.
if ($selectedAlbumsArray) {

// foreach starts here for individual albums
foreach ($selectedAlbumsArray as $album) {
	// albuminfo keys are uri and name
	// i think $getAlbum used the uri, so let's set $getAlbum to album['uri']
	$getAlbum = $album['uri'];
	$title = $album['name'];
	
	$albumNameInsert = $title;
	
	print "<h1>Album title: $title</h1>";
	
	
	///print "<pre>";
	///print_r (array_values($album));
	///print "</pre>";
		
	
	$numberOfVideosInAlbumResponse = $lib->request("/me/albums/$getAlbum", "GET");
	// compare albumTotalVideosVimeo (vimeo) to number of videos in DB for that album ($title = album name)
	$albumTotalVideosVimeo =  $numberOfVideosInAlbumResponse["body"]["metadata"]["connections"]["videos"]["total"];
	$checkAlbumTotalVideosInDB = $mysqli->query("SELECT * FROM `streaming`.`fbcvimeo` WHERE `album` = \"$title\"");
	$albumTotalVideosInDB = $mysqli->affected_rows;

	// number of videos to import = (vimeo total) - (db total)
	$numberOfVideosToImport = $albumTotalVideosVimeo - $albumTotalVideosInDB;
	// print "Number of videos in vimeo: ${albumTotalVideosVimeo}. Number of videos in DB ${albumTotalVideosInDB}.";
	///print "Need to import ${numberOfVideosToImport}<br />";
	
	// to make stats 
	$statsAdded = 0;
	$statsSkipped = 0;
	$listAdded = array();
	$listSkipped = array();


	// if $numberOfVideosToImport > 50 I can use the while loop. if there are less than 50, I can request up to 50 videos at a time, so I only need 1 "page"
	if ($numberOfVideosToImport <= 50) {
		///print "Importing under 50 videos for $title.";
		
		$response = array_reverse($lib->request("/me/albums/$getAlbum/videos", array('per_page' => $numberOfVideosToImport, 'sort' => 'alphabetical', 'direction' => 'desc'), 'GET'));

		for ($i = $numberOfVideosToImport - 1; $i >= 0; $i--) {		
		
		///print "DEBUG: Inside the for loop. $i. <br />";
		
		$url =  $response["body"]["data"][$i]["uri"];			
		$title = addslashes ($response["body"]["data"][$i]["name"]);
		$embed = htmlspecialchars($response["body"]["data"][$i]["embed"]["html"]);

		// check if video exists
		$checkDuplicate = $mysqli->query("SELECT * from `streaming`.`fbcvimeo` WHERE `album` = \"$albumNameInsert\" AND `videourl` = \"$url\"");
			if($checkDuplicate){} else {die('Error : ('. $mysqli->errno .') '. $mysqli->error);}
		$resultsDuplicate = $mysqli->affected_rows;
		
		/// print "<br />URL to add: $url<br />";
		// if $resultsDuplicate == 0, the url does not exist in the db. add it.
		if ($resultsDuplicate == "0") {
			// if url is not empty, add it
			if ($url) {
				/// print "URL not empty, adding";
				$albumNameInsert2 = addslashes($albumNameInsert);
				$addToDB = $mysqli->query("INSERT INTO `streaming`.`fbcvimeo` (`album`, `videourl`, `title`, `embedcode`) VALUES ('$albumNameInsert2', '$url', '$title', '$embed')");
					
				if($addToDB){
					/// print "$title added to database.<br />";
					$listAdded[] = $title;
					$statsAdded++;
				}else{
					die('Error : ('. $mysqli->errno .') '. $mysqli->error);
				}
			}
			else { 
			/// print "URL empty?!"; 
			}
		}
		else {
			/// print "<strike>$title</strike> exists, not added to database.<br />";
			$listSkipped[] = $title;
			$statsSkipped++;
		}		
	}
		
	}
	if ($numberOfVideosToImport >= 51) {
		print "videos over 50, break or exit";
		exit;
		while ($lastPage > 0) {

			// get videos, starting with the last page, starting with the last video
			$response = array_reverse($lib->request("/me/albums/$getAlbum/videos", array('page' => $lastPage, 'per_page' => '50', 'sort' => 'alphabetical', 'direction' => 'desc'), 'GET'));
				// DEBUG
				//
				//print "<pre>";
				//print_r ($response);
				//print "</pre>";
				
			
				
			// start with the "last" video on this page and work your way to the first
			// $i = $numberOfVideosOnPage; 
			for ($i = 49; $i >= 0; $i--) {		
				
				// print "DEBUG: Inside the for loop. $i. <br />";
				
				$url =  $response["body"]["data"][$i]["uri"];			
				$title = addslashes ($response["body"]["data"][$i]["name"]);
				$embed = htmlspecialchars($response["body"]["data"][$i]["embed"]["html"]);

				// check if video exists
				$checkDuplicate = $mysqli->query("SELECT * from `streaming`.`fbcvimeo` WHERE `album` = \"$albumNameInsert\" AND `videourl` = \"$url\"");
					if($checkDuplicate){} else {die('Error : ('. $mysqli->errno .') '. $mysqli->error);}
				$resultsDuplicate = $mysqli->affected_rows;
				
				// if $resultsDuplicate == 0, the url does not exist in the db. add it.
				if ($resultsDuplicate == "0") {
					// if url is not empty, add it
					if ($url) {
						//// $albumNameInsert2 = addslashes($albumNameInsert);
						//// $addToDB = $mysqli->query("INSERT INTO `streaming`.`fbcvimeo` (`album`, `videourl`, `title`, `embedcode`) VALUES ('$albumNameInsert2', '$url', '$title', '$embed')");	
						$addToDB = "true";
							
						if($addToDB){
							// print "$title added to database.<br />";
							$listAdded[] = $title;
							$statsAdded++;
						}else{
							die('Error : ('. $mysqli->errno .') '. $mysqli->error);
						}
					}
				}
				else {
					//print "<strike>$title</strike> exists, not added to database.<br />";
					$listSkipped[] = $title;
					$statsSkipped++;
				}
				// $i--;
			}
			$lastPage = $lastPage - 1;
			
		//end while
		}
	}
	
	/// print_r ($response["body"]["data"]);

	// print stats	
	print "Total videos: $albumTotalVideosVimeo<br />";
	// print "Last page: $lastPage<br />";
	print "Videos added: $statsAdded. Videos skipped: $statsSkipped.<br />";
	
	if ($listAdded) {
	?>
		<h3>List of added videos</h3>
		<div style="height: 100px; overflow: auto;">
			<?php foreach ($listAdded as $video) { print "$video<br />"; } ?>
		</div>

	<?php
	}
	if ($listSkipped) {	
	?>
		<h3>List of skipped videos</h3>
		<div style="height: 100px; overflow: auto;">
			<?php foreach ($listSkipped as $video) { print "$video<br />"; } ?>
		</div>
<?php
		}
	}
}
?>
