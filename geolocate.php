<?php 
/*

EXIF GPS to KML
Author: SnakeByte Studios
License: GPLv3

This script will traverse the path set below to find all images with GPS tags 
and create a KML file for use with Google Earth. This will allow one to map 
all their photos and view them by just clicking on their icons.

*/

//set the following line to the path to search
$path = 'C:\Users\Snake\Pictures';


//set this to the file to write the KML to
//or leave null to write to the current directory
$output_file = null;

//if you have a lot of photos, you may need to increase the script timeout
set_time_limit(600);



$base_kml = <<<KMLBLOCK
<?xml version="1.0" encoding="UTF-8"?>
<kml xmlns="http://www.opengis.net/kml/2.2" xmlns:kml="http://www.opengis.net/kml/2.2" xmlns:atom="http://www.w3.org/2005/Atom">
<Document>
	<name></name>
	<Style id="photo-icon_n">
		<IconStyle>
			<scale>1.1</scale>
			<Icon>
				<href>http://maps.google.com/mapfiles/kml/paddle/blu-square.png</href>
			</Icon>
			<hotSpot x="20" y="2" xunits="pixels" yunits="pixels"/>
		</IconStyle>
	</Style>
	<StyleMap id="photo-icon">
		<Pair>
			<key>normal</key>
			<styleUrl>#photo-icon_n</styleUrl>
		</Pair>
		<Pair>
			<key>highlight</key>
			<styleUrl>#photo-icon_hl</styleUrl>
		</Pair>
	</StyleMap>
	<Style id="photo-icon_hl">
		<IconStyle>
			<scale>1.3</scale>
			<Icon>
				<href>http://maps.google.com/mapfiles/kml/paddle/blu-square.png</href>
			</Icon>
			<hotSpot x="20" y="2" xunits="pixels" yunits="pixels"/>
		</IconStyle>
	</Style>
</Document>
</kml>
KMLBLOCK;

//extended SimpleXML so that we can add CData elements - why the f doesn't 
//simplexml support this to begin with
class ExSimpleXMLElement extends SimpleXMLElement { 
	private function addCData($cdata_text) { 
		$node= dom_import_simplexml($this); 
		$no = $node->ownerDocument; 
		$node->appendChild($no->createCDATASection($cdata_text));
	} 

	public function addChildCData($name, $cdata_text) { 
		$child = $this->addChild($name); 
		$child->addCData($cdata_text); 
	} 
}

//converts a fractional number to a floating point number
function eval_fraction($fraction) {
	if (preg_match('%(\d+)/(\d+)%i', $fraction, $matches)) {
		if ($matches[2] == 0) return (int)$matches[1];
		return (int)$matches[1] / (int)$matches[2];
	} else return $fraction;
}

//takes the EXIF library's GPS DMS (degrees minutes seconds) output and returns
//a floating point version suitable for kml
function convert_DMS($coord) {
	$return_coord = 0;
	if (count($coord)) {
		foreach ($coord as $i => $DMS) {
			if ($i == 0) {
				$return_coord = eval_fraction($DMS);
			} elseif ($i == 1) {
				$return_coord += eval_fraction($DMS) / 60;
			} elseif ($i == 2) {
				$return_coord += eval_fraction($DMS) / 3600;
			}
		}
		return $return_coord;
	}
	return 0;
}

//our main recursive function to traverse the directories in the given path
//returns an array of images with the keys:
//	file: the full path to the image
//	latitude: the latitude in decimal notation
//	longitude: the longitude in decimal notation
function traverse_directory($path) {
	$return_images = [];
	
	if ($handle = opendir($path)) {
		while (false !== ($filename = readdir($handle))) {
			//if not parent or current directory
			if ($filename != "." && $filename != "..") {
				$filepath = "$path\\$filename";
				if (is_dir($filepath)) {
					//if directory, traverse and then merge the results
					print "$filepath<br/>\n";
					ob_flush();
					flush();
					$return_images = array_merge($return_images, traverse_directory($filepath));
				} else {
					//if JPEG file
					if (preg_match('/\.jpe?g$/i', $filename)) {
						//if has any GPS data
						if (($exif = @exif_read_data($filepath, "GPS")) !== false) {
							//has the specific GPS data we need
							if (isset($exif['GPSLatitude']) && isset($exif['GPSLongitude'])) {
								$latitude = convert_DMS($exif['GPSLatitude']);
								$longitude = convert_DMS($exif['GPSLongitude']);
								
								//in decimal notation, S and W are negative
								if ($exif['GPSLatitudeRef'] == "S") $latitude *= -1;
								if ($exif['GPSLongitudeRef'] == "W") $longitude *= -1;
								
								//printf("%f, %f\n", $latitude, $longitude);
								
								$return_images[] = ["file" => $filepath, "latitude" => $latitude, "longitude" => $longitude];
							}
						}
					}
				}
			}
		}
		closedir($handle);
	}
	return $return_images;
}

$images = traverse_directory($path);

//output as KML
if (count($images)) {
	$kml = new ExSimpleXMLElement($base_kml);
	
	foreach ($images as $image) {
		$placemark = $kml->Document->addChild('Placemark');
		
		//relative path with no slashes
		$title = str_replace($path, "", $image['file']);
		$title = str_replace("\\", " ", $title);
		$placemark->name = $title;
		
		//google earth doesn't like file extensions in caps?
		$placemark->addChildCData("description", '<img src="file:///' . str_replace(".JPG", ".jpg", $image['file']) . '" width="640" />');
		
		$placemark->styleUrl ='#photo-icon';
		$placemark->Point->coordinates = $image['longitude'] . "," . $image['latitude'] . ",0";
	}

	//write to file
	$kml->asXML((isset($output_file)) ? $output_file : basename($path) . ".kml");
}

print count($images) . " images with GPS found";
