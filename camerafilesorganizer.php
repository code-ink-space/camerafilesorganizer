<?php
/*
 * PHP CLI script to organize camera pictures and videos
 * sort camera file by date folders: 2022 > 2022-01 > 2022-01-02
 * specifically for NIKON cameras: DSC_XXXX.JPG/MOV
 *
 * with --dry-run option levels:
 * 1 = no mkdir, chmod or move is done. just shows old and new paths
 * 2 = everything done except actually moving the file
 *
 * logs output summary to text file and details to CSV file
 */

// define('DRY_RUN', true);
askDryRun();
if (DRY_RUN) {
	// define('DRY_RUN_LEVEL', 1);
	askDryRunLevel();
}
$paths_arr = askPaths();
print_r($paths_arr);

if (!validatePaths($paths_arr)) {
	exit();
} else {
	print "\n -- Checking paths... Looks good.\n";
}

// camera files absolute path to import
define('SRC_PATH', $paths_arr[0]);

// camera files absolute path destination, where YEAR folder will go under
define('DEST_PATH', $paths_arr[1]);

// fetch into array .JPG, .MOV and .NEF filenames in src folder
$file_array = array();

foreach (glob(SRC_PATH.'/*.{JPG}', GLOB_BRACE) as $filename) {
	array_push($file_array, $filename);
}

$GLOBALS['summary_arr'] = array();
$GLOBALS['details_arr'] = array();

print_r($file_array);

processFiles($file_array);

// print_r($GLOBALS['details_arr']);

csvLogger($GLOBALS['details_arr']);

exit();


function processFiles($file_array = array()) {
	for ($i = 0; $i < count($file_array); $i++) {
		$arr_detail = array();
		$oldfile_path = $file_array[$i];
		$filename = basename($file_array[$i]);
		$filetype = explode('.', $filename)[1];
		$filesize = filesize($oldfile_path);
		$filesize_pretty = formatFileSize(filesize($oldfile_path));

		if ($filetype == 'JPG') {
			// parse EXIF DateTimeOriginal
			$photo_datetime = getDateTimeOriginal($oldfile_path);
			$pdt_arr = explode(' ', $photo_datetime);
			list($pic_year, $pic_month, $pic_day) = explode(':', $pdt_arr[0]);
			$photo_ymd = implode('-', array($pic_year, $pic_month, $pic_day));
		}

		// sort camera file by date folders: 2022 > 2022-01 > 2022-01-02
		$newdest_path = DEST_PATH . '/' . $pic_year . '/' . $pic_year . '-' . $pic_month . '/' . $pic_year . '-' . $pic_month . '-' . $pic_day;
		$newfile_path = $newdest_path . '/' . $filename;
		/*if (createFolders($newdest_path)) {
			if (!moveFile($oldfile_path, $newfile_path)){
				// failed to move file or DRY_RUN skipped it
			} else {
				// success move!
			}
		} else {
			// directory access failure or skipped mkdir, chmod
		}*/

		$arr_detail = array(
			$filename,
			$filetype,
			$photo_ymd,
			$oldfile_path,
			$newfile_path,
			$photo_datetime,
			$filesize_pretty,
			$filesize
		);
		logDetail($arr_detail);
	}
}

function formatFileSize($filesize = 0) {
	if ($filesize < 1073741824) {
		$filesize_pretty = round($filesize / 1048576, 2) . ' MB';
	} else {
		$filesize_pretty = round($filesize / 1073741824, 2) . ' GB';
	}
	return $filesize_pretty;
}

function getDateTimeOriginal($file_path = '') {
	$photo_date = false;

	// Open a the file, this should be in binary mode
	$fp = fopen($file_path, 'rb');

	if (!$fp) {
	    echo 'Error: Unable to open image for reading';
	    exit;
	}

	// Attempt to read the exif headers
	$headers = exif_read_data($fp, 'EXIF', $as_arrays = true);

	if (!$headers) {
	    echo 'Error: Unable to read exif headers';
	    exit;
	}

	// Print the 'COMPUTED' headers
	// echo 'EXIF Headers:' . PHP_EOL;

	foreach ($headers['EXIF'] as $header => $value) {
	    // printf(' %s => %s%s', $header, $value, PHP_EOL);
	    if ($header == 'DateTimeOriginal') // DateTimeOriginal => 2022:01:22 19:34:55
	    	$photo_date = $value;
	}

	return $photo_date;
}

function pathExists($path) {
	if (file_exists($path) && is_dir($path))
		return true;
	else
		return false;
}

function makePathWritable($path, $mode = 'regular') {
	if (is_writable($path)) {
		return true;
	} else {
		if ($mode == 'regular') {
			if (!DRY_RUN && DRY_RUN_LEVEL != 1) {
				// try change directory permissions
				if (!chmod($path, 0755)) {
					print "\n -- Path Error: $path -- Failed to change permissions.\n";
					return false;
				}
			} else {
				print "\n -- Path: $path -- Skipping chmod for DRY_RUN_LEVEL 1\n";
				return false; // skip chmod for DRY_RUN_LEVEL 1
			}
		} else { // i.e. validate_only
			return false;
		}
	}
}

function validatePaths($paths = array()) {
	if ($paths[0] == $paths[1]) {
		print "\n -- Path Error: Source and Destination cannot be the same.\n";
		return false;
	}
	for ($i = 0; $i < count($paths); $i++) {
		if (!pathExists($paths[$i])) {
			print "\n -- Path Error: $paths[$i] -- does not exist.\n";
			return false;
		}
		if (!makePathWritable($paths[$i], 'validate_only')) {
			print "\n -- Path Error: $paths[$i] -- is not writable.\n";
			return false;
		}
	}
	return true;
}

function askDryRun() {
	do {
		$input = trim(strtolower( readline("\n> Dry Run? (y/n) ") ));
		readline_add_history($input);
		switch ($input) {
			case 'y':
				define('DRY_RUN', true);
				print "\n -- DRY-RUN Mode is ON\n";
				break;
			case 'n':
				define('DRY_RUN', false);
				print "\n -- Okay. NOT on Dry-Run Mode!\n";
				break;
			default:
				print "\n -- please input 'y' or 'n'\n";
				break;
		}
	} while ( !in_array($input, array('y', 'n')) );
}

function askDryRunLevel() {
	$ask = <<<EOD
	\n> Dry-Run Level? (1 or 2)
	\n 1 = no mkdir, chmod or move is done. just shows old and new paths
	\n 2 = everything done except actually moving the file
	\n>
	EOD;

	do {
		$input = trim(strtolower( readline($ask . " ") ));
		readline_add_history($input);
		switch ($input) {
			case '1':
				define('DRY_RUN_LEVEL', 1);
				print "\n -- DRY-RUN Level 1";
				print "\n -- no mkdir, chmod or move will be done. just show old and new paths\n";
				break;
			case '2':
				define('DRY_RUN_LEVEL', 2);
				print "\n -- DRY-RUN Level 2";
				print "\n -- everything will be done except actually moving the file\n";
				break;
			default:
				print "\n -- please input '1' or '2'\n";
				break;
		}
	} while ( !in_array($input, array('1', '2')) );
}

function askPaths() {
	$paths_arr = array();
	do {
		$input = trim(readline("\n> Source Path: "));
		readline_add_history($input);
		if (strlen(trim($input)) > 0)
			array_push($paths_arr, rtrim($input, '/'));
	} while ( strlen(trim($input)) == 0 );
	do {
		$input = trim(readline("\n> Destination Path: "));
		readline_add_history($input);
		if (strlen(trim($input)) > 0)
			array_push($paths_arr, rtrim($input, '/'));
	} while ( strlen(trim($input)) == 0 );
	return $paths_arr;
}

function logDetail($arr = array()) {
	array_push($GLOBALS['details_arr'], $arr);
}

function csvLogger($arr = array()) {
	$csvfile = fopen('details.csv', 'w');
	foreach ($arr as $line) {
		fputcsv($csvfile, $line);
	}
	fclose($csvfile);
}

