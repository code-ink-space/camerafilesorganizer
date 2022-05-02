<?php
/*
 * PHP script for Linux to batch set file's modified date to original photo's date
 * reads EXIF DateTimeOriginal
 */
$path = askPath();
if(is_dir($path)) {
	define('SRC_PATH', $path);
} else {
	print "\n -- Path does not exist or permissions error!\n";
	exit();
}

$file_array = array();
foreach (glob(SRC_PATH.'/*.{JPG}', GLOB_BRACE) as $filename) {
	array_push($file_array, $filename);
}

if (count($file_array) == 0) {
	print "\n -- No JPEG files found.\n";
	exit();
} else {
	define('FILE_COUNT', count($file_array));
	print "\n -- " . FILE_COUNT . " JPEG files found.\n";
	$input = trim(strtolower( readline("\n> Continue? (y/n) ") ));
	if ($input != 'y') {
		exit();
	}
}

$GLOBALS['success_count'] = $GLOBALS['error_count'] = 0;
processFiles($file_array);

print "\n -- Success: {$GLOBALS['success_count']} file(s).";
print "\n -- Failure: {$GLOBALS['error_count']} file(s).\n";
print "\n -- DONE!\n";

function processFiles($file_array = array()) {
	print "\n -- Processing " . FILE_COUNT . " file(s)...\n";
	for ($i = 0; $i < FILE_COUNT; $i++) {
		$file_path = $file_array[$i];
		$photo_datetime = getDateTimeOriginal($file_path); // 2022:01:22 19:34:55
		if ($photo_datetime) {
			$pdt_arr = explode(' ', $photo_datetime);
			list($pic_year, $pic_month, $pic_day) = explode(':', $pdt_arr[0]);
			$photo_ymd = implode('-', array($pic_year, $pic_month, $pic_day));
			$photo_datetime_formatted = $photo_ymd . ' ' . $pdt_arr[1]; // 2022-04-26 09:23:40
			exec('touch -m -d "' . $photo_datetime_formatted . '" ' . $file_path);
			$GLOBALS['success_count']++;
		} else {
			$GLOBALS['error_count']++;
			continue;
		}
	}
}

function askPath() {
	print "\nEnter absolute path to location of photos.\n";
	do {
		$input = trim(readline("\n> "));
	} while ( strlen(trim($input)) == 0 );
	return rtrim($input, '/');
}

function getDateTimeOriginal($file_path = '') {
	$fp = fopen($file_path, 'rb');
	if (!$fp) {
	    return false;
	}
	// not the best solution but works for me, see https://stackoverflow.com/q/5184748
	$headers = @exif_read_data($fp, 'EXIF', $as_arrays = true);
	if (!$headers) {
	    return false;
	}
	foreach ($headers['EXIF'] as $header => $value) {
	    if ($header == 'DateTimeOriginal') // DateTimeOriginal => 2022:01:22 19:34:55
	    	return $value;
	}
	return false;
}
