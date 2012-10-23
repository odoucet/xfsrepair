#!/usr/bin/env php
<?php

/***************************
 * Restore files when XFS metadata is wrong
 * Based on http://oss.sgi.com/archives/xfs/2012-02/msg00561.html
 *
 * End of files will contain garbage, but at least you have 
 * 99% of the file correct
 * @author Olivier Doucet <odoucet@php.net>
 * File coded in PHP because it is my main language ... Feel free
 * to rewrite it in any language you want ...
 ***********************/


// these arrays are kept here because they are used globally
$mknodArray = array();
$agblocks   = array();
$sectSize   = array();


// Multiple checks
if (!defined('PHP_VERSION_ID') || PHP_VERSION_ID < 50300) {
	echo "ERROR: this script requires PHP 5.3\n";
	echo "You can use lower version by rewriting lines ~70+ and ";
	echo "replace RecursiveDirectoryIterator by glob() or something else\n";
	exit;
}

if (getmyuid () !== 0) {
	echo "ERROR: This script requires root (because we use mknod)\n";
	echo "You can comment this warning if you replace the exec(mknod)\n";
	show_syntax();
	exit;
}

if (!isset($argv[1]) || !isset($argv[2])) {
	// syntax
	show_syntax();
	exit;
}

if (!file_exists($argv[1])) {
	echo "First arg is neither a file nor a dir\n";
	show_syntax();
	exit;
}

// sanitize
$argv[1] = realpath($argv[1]);
$argv[2] = realpath($argv[2]);

if (is_file($argv[1])) {
	if (is_dir($argv[2])) {
		$argv[2] = $argv[2].'/'.basename($argv[1]);
	} elseif (!is_dir(dirname($argv[2]))) {
		echo "Destination does not exist: ".$argv[2]."\n";
		exit;
	} elseif ($argv[2] == $argv[1]) {
		echo "Destination cannot be equal to source !!!\n";
		exit;
	}
	//@todo test is_writable
	
	
	if (file_exists($argv[2])) {
		echo "Destination file already exists: ".$argv[2]."\n";
		exit;
	}
	repair_file($argv[1], $argv[2]);
	
} elseif (is_dir($argv[1])) {
	if (!is_dir($argv[2])) {
		echo "ERROR: Destination is not a directory: ".$argv[2]."\n";
		exit;
	} elseif ($argv[2] == $argv[1]) {
		echo "Destination cannot be equal to source !!!\n";
		exit;
	}
	
	// restore whole dir
	$it = new RecursiveDirectoryIterator($argv[1]);
	foreach (new RecursiveIteratorIterator($it, 1) as $path) {
		if ($path->isFile()) {
			// create target
			$diffPath = substr($path->__toString(), strlen($argv[1]));
			if (!file_exists(dirname($argv[2]."/".$diffPath))) {
				mkdir(dirname($argv[2]."/".$diffPath), null, true);
			}
			repair_file($path, $argv[2]."/".$diffPath);
		}
	}
	
} else {
	echo "should never get here, there is a bug in this script :(\n";
	exit;
}


// Flush mknod
foreach ($mknodArray as $ver => $name) {
	unlink($name);
}




function repair_file($file, $destination) {
	global $mknodArray, $agblocks, $sectSize;
	
	// Stat
	$stat = stat($file);
	if ($stat === false) {
		echo "ERROR: cannot stat() file ".$file."\n";
		return;
	}
	
	// some checks
	if (!preg_match('@^[0-9]{1,}$@', $stat['ino']) || $stat['ino'] == 0) {
		echo "Cannot extract inode number\n";
		return;
	}
	
	/* DEBUG
	echo "File inode:   ".$stat['ino']."\n";
	echo "Blocks used:  ".$stat['blocks']."\n";
	echo "IO Blocksize: ".$stat['blksize']."\n";	
	*/
	
	// Find device : $stat['dev'] is major,minor
	// Example : fd08 == 253,08
	$minor = $stat['dev'] % 256;
	$major = floor($stat['dev']/256);

	// mknod  
	if (!isset($mknodArray[$major.','.$minor])) {
		$tmpName = sys_get_temp_dir().'/'.uniqid('nod');
		
		/***
		 * if you don't have access to mknod, comment next shell_exec
		 * and set $tmpName to your device block path (like /dev/sda1)
		 **/
		$s = shell_exec('mknod '.$tmpName.' b '.$major.' '.$minor);
		$mknodArray[$major.','.$minor] = $tmpName;
		
		// agblocks
		$s = shell_exec("xfs_db -r ".$tmpName." -c sb -c p | grep ^agblocks | sed 's/.* = //'");
		if ($s == 0) {
			echo "ERROR checking agblocks in device\n";
			
			// cleanup
			unlink($mknodArray[$major.','.$minor]);
			unset($mknodArray[$major.','.$minor]);
			return;
		}
		$agblocks[$major.','.$minor] = (int) $s;
        
        // sector size
        $s = shell_exec("xfs_db -r ".$tmpName." -c sb -c p | grep ^sectsize | sed 's/.* = //'");
        if ($s == 0) {
            echo "Error getting sector size from xfs_db\n";
            
			// cleanup
			unlink($mknodArray[$major.','.$minor]);
			unset($mknodArray[$major.','.$minor]);
			return;
        }
        $sectSize[$major.','.$minor] = (int) $s;
	}
	
	// now xfs_db for this specific file
	$cmd = 'xfs_db -r '.$mknodArray[$major.','.$minor].' -c "inode '.$stat['ino'].'" -c "bmap"';
	$s = explode("\n", trim(shell_exec($cmd)));
	// Output is like : 
	// string(60) "data offset 0 startblock 4910152 (0/4910152) count 1 flag 0"

	foreach ($s as $string) {
		if (!preg_match('@data offset ([0-9]{1,}) startblock ([0-9]{1,}) '
						.'\(([0-9]{1,})/([0-9]{1,})\) count ([0-9]{1,}) flag ([0-9]{1,})@', $string, $preg)) {
			echo "ERROR: xfs_db output unknown : \n---------\n".$string."\n---------\n for file ".$file."\n";
			echo "Please debug me near line ".__LINE__."\n";
			return;
		}
		
		// we have a file on different place on device. We need to append data
		if (count($s) > 1) {
			$flags = ' oflag=append ';
		} else $flags = '';
		
		// Copy action
		printf('%30s ', $file);
		echo "restore ".number_format($stat['blocks']*$stat['blksize'])." bytes of data ...\r";
		$cmd = 'dd if='.$mknodArray[$major.','.$minor].' bs='.$sectSize[$major.','.$minor].' skip=$(('.$agblocks[$major.','.$minor]
			.' * '.$preg[3].' + '.$preg[4].')) count='.$stat['blocks'].' of='.$destination.' '.$flags.' 2>&1';
		$r = shell_exec($cmd);
	}
	
	// print final file
	printf("%10s KB  %s      \n", filesize($destination)/1024, $destination);	
}

function show_syntax() {
	echo "
	XFSREPAIR.PHP  (by Olivier Doucet <odoucet at php dot net>
	Restore files when XFS metadata report 0-size file but you know it's wrong ...
	(Based on http://oss.sgi.com/archives/xfs/2012-02/msg00561.html)

	";
	echo "Syntax: ".$argv[0]." <dir_or_file> <destination_dir_or_file>\n";
	
}
