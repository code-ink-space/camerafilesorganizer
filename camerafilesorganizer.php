<?php
/*
 * PHP CLI script to organize camera files
 * move files into date folders: 2022 > 2022-01 > 2022-01-02
 * specifically for NIKON cameras: DSC_XXXX.JPG/NEF
 * reads EXIF DateTimeOriginal
 *
 * with dry-run option levels:
 * 1 = no mkdir, chmod or move is done. just shows old and new paths
 * 2 = everything done except actually moving the file
 *
 * logs output summary to text file and details, messages to CSV files
 *
 * @todo: MOV video files
 */
define('TEST_MODE', 0);
if (TEST_MODE)
	print "\n -- TEST MODE: process first file only\n";

define('DEFAULT_DESTINATION', '');
define('DEFAULT_DESTINATION_NEF', '');

define('START_TIME', date("Y-m-d H:i:s"));

askDryRun();
if (DRY_RUN) {
	askDryRunLevel();
} else {
	define('DRY_RUN_LEVEL', 0);
}

askFileType();

$paths_arr = askPaths();
if (!validatePaths($paths_arr)) {
	exit();
} else {
	print "\n -- Checking paths... Looks good.\n";
}

// camera files absolute path to import
define('SRC_PATH', $paths_arr[0]);
// camera files absolute path destination, where YEAR folder will go under
define('DEST_PATH', $paths_arr[1]);

// fetch into array select filenames in src folder
$file_array = array();
foreach (glob(SRC_PATH.'/*.{'.FILE_TYPE.'}', GLOB_BRACE) as $filename) {
	array_push($file_array, $filename);
}

$GLOBALS['summary_arr'] = $GLOBALS['details_arr'] = $GLOBALS['messages_arr'] = array();
$GLOBALS['success_count'] = $GLOBALS['error_count'] = 0;

processFiles($file_array);
csvLogger($GLOBALS['details_arr'], 'details.csv');
logSummary();

print "\n -- Moved {$GLOBALS['success_count']} file(s).\n";
print "\n -- DONE!\n";

function processFiles($file_array = array()) {
	define('FILE_COUNT', count($file_array));
	print "\n -- Processing " . FILE_COUNT . " file(s)...";
	for ($i = 0; $i < FILE_COUNT; $i++) {
		$arr_detail = array();
		$oldfile_path = $file_array[$i];
		$filename = basename($file_array[$i]);
		$filetype = explode('.', $filename)[1];
		$filesize = filesize($oldfile_path);
		$filesize_pretty = formatFileSize(filesize($oldfile_path));

		$photo_datetime = getDateTimeOriginal($oldfile_path);
		if ($photo_datetime) {
			$pdt_arr = explode(' ', $photo_datetime);
			list($pic_year, $pic_month, $pic_day) = explode(':', $pdt_arr[0]);
			$photo_ymd = implode('-', array($pic_year, $pic_month, $pic_day));
		} else {
			continue;
		}

		$newdest_path = DEST_PATH . '/' . $pic_year . '/' . $pic_year . '-' . $pic_month . '/' . $pic_year . '-' . $pic_month . '-' . $pic_day;
		$newfile_path = $newdest_path . '/' . $filename;
		if (createFolders($newdest_path)) {
			moveFile($oldfile_path, $newfile_path);
		}

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

		if (TEST_MODE)
			break;
	}
}

function createFolders($path) {
	if (pathExists($path)) {
		if (!makePathWritable($path)) {
			return false;
		} else {
			logMsg($path, 'path is good');
			return true;
		}
	} else {
		if (!DRY_RUN || DRY_RUN_LEVEL != 1) {
			if (!mkdir($path, 0755, true)) {
				logMsg($path, 'failed to create directories', 'error');
				return false;
			} else {
				logMsg($path, 'mkdir success');
				return true;
			}
		} else {
			logMsg($path, 'skip mkdir on DRY_RUN_LEVEL 1');
			return false;
		}
	}
}

function moveFile($old_path, $new_path) {
	if (!DRY_RUN) {
		if (file_exists($new_path)) {
			logMsg($new_path, 'file already exists', 'error');
			return false;
		} else if (!rename($old_path, $new_path)) {
			logMsg($new_path, 'failed to move file to this location', 'error');
			return false;
		} else {
			logMsg($new_path, 'Successfully moved file.');
			$GLOBALS['success_count']++;
			return true;
		}
		return false;
	} else {
		logMsg($new_path, 'skip move file on DRY-RUN');
		return false;
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
	$fp = fopen($file_path, 'rb');
	if (!$fp) {
	    logMsg($file_path, 'unable to open image for reading', 'error');
	    return false;
	}
	// not the best solution but works for me, see https://stackoverflow.com/q/5184748
	$headers = @exif_read_data($fp, 'EXIF', $as_arrays = true);
	if (!$headers) {
	    logMsg($file_path, 'unable to read EXIF headers', 'error');
	    return false;
	}
	foreach ($headers['EXIF'] as $header => $value) {
	    if ($header == 'DateTimeOriginal') // DateTimeOriginal => 2022:01:22 19:34:55
	    	return $value;
	}
	return false;
}

/*function getDateTimeOriginalNEF($file_path = '') {
	$fp = fopen($file_path, 'rb');
	if (!$fp) {
	    logMsg($file_path, 'unable to open image for reading', 'error');
	    return false;
	}
	$img = new Imagick($file_path);
	$allProp = $img->getImageProperties();
	$exifProp = $img->getImageProperties("exif:*");
	print_r($allProp);

	if(isset($exifProp['exif:DateTimeOriginal'])) {
		return $exifProp['exif:DateTimeOriginal'];
	} else {
		logMsg($file_path, 'unable to read NEF EXIF DateTimeOriginal', 'error');
	    return false;
	}
}*/

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
					// print "\n -- Path Error: $path -- Failed to change permissions.\n";
					logMsg($path, 'chmod failed to change permissions', 'error');
					return false;
				}
			} else {
				// print "\n -- Path: $path -- Skipping chmod for DRY_RUN_LEVEL 1\n";
				logMsg($path, 'skip chmod on DRY_RUN_LEVEL 1');
				return false; // skip chmod for DRY_RUN_LEVEL 1
			}
		} else { // i.e. validate_only
			logMsg($path, 'skipped chmod');
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

function askFileType() {
	do {
		$input = trim(strtolower( readline("\n> File Type? (j = JPEG, n = NEF) ") ));
		readline_add_history($input);
		switch ($input) {
			case 'j':
				define('FILE_TYPE', 'JPG');
				print "\n -- searching JPEG files\n";
				break;
			case 'n':
				define('FILE_TYPE', 'NEF');
				print "\n -- searching NEF files\n";
				break;
			default:
				print "\n -- please select: j = JPEG, n = NEF\n";
				break;
		}
	} while ( !in_array($input, array('j', 'n')) );
}

function askPaths() {
	$paths_arr = array();
	print "\nEnter absolute paths.\n";
	do {
		$input = trim(readline("\n> Source Path: "));
		readline_add_history($input);
		if (strlen(trim($input)) > 0)
			array_push($paths_arr, rtrim($input, '/'));
	} while ( strlen(trim($input)) == 0 );

	if (FILE_TYPE == 'NEF')
		$def_dest = DEFAULT_DESTINATION_NEF;
	else
		$def_dest = DEFAULT_DESTINATION;

	if (strlen(trim($def_dest)) > 0) {
		print "\n -- DEFAULT DESTINATION: " . $def_dest;
		$input = trim(strtolower( readline("\n> Use Default? (y/n) ") ));
		readline_add_history($input);
		switch ($input) {
			case 'y':
				array_push($paths_arr, rtrim($def_dest, '/'));
				return $paths_arr;
			default:
				break;
		}
	}

	do {
		$input = trim(readline("\n> Destination Path (YEAR folder will go under this): "));
		readline_add_history($input);
		if (strlen(trim($input)) > 0)
			array_push($paths_arr, rtrim($input, '/'));
	} while ( strlen(trim($input)) == 0 );
	return $paths_arr;
}

function logMsg($item = '', $message = '', $type = 'info') {
	array_push($GLOBALS['messages_arr'], array($item, $type, $message));
	if ($type == 'error')
		$GLOBALS['error_count']++;
}

function logDetail($arr = array()) {
	array_push($GLOBALS['details_arr'], $arr);
}

function csvLogger($arr = array(), $filename = 'output.csv') {
	$csvfile = fopen($filename, 'w');
	foreach ($arr as $line) {
		fputcsv($csvfile, $line);
	}
	fclose($csvfile);
}

function logSummary() {
	list($start, $file_type, $src, $dest, $dryrun, $level, $file_count) = array(
		START_TIME, FILE_TYPE, SRC_PATH, DEST_PATH,
		!DRY_RUN ? 'No' : 'Yes',
		!DRY_RUN ? '0' : DRY_RUN_LEVEL,
		FILE_COUNT
	);
	$end = date("Y-m-d H:i:s");
	$summary = <<<SUMMARY
Start Time: {$start}\n
End Time: {$end}\n
File Type: {$file_type}\n
Source: {$src}\n
Destination: {$dest}\n
Dry-Run? {$dryrun}\n
Dry-Run Level: {$level}\n
File to Process: {$file_count}\n
Files Moved: {$GLOBALS['success_count']}\n
Errors: {$GLOBALS['error_count']}\n
SUMMARY;
	file_put_contents('summary.txt', $summary);
	csvLogger($GLOBALS['messages_arr'], 'messages.csv');
}
