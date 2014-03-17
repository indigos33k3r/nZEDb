<?php
// To troubleshoot what's actually on usenet.
require_once dirname(__FILE__) . '/../../../www/config.php';
$c = new ColorCLI();

if (!isset($argv[2]) || !is_numeric($argv[2])) {
	exit($c->error("\nTest your nntp connection, get group information and postdate for specific article.\n\n"
		. "php $argv[0] alt.binaries.teevee 595751142    ...: To test nntp on alt.binaries.teevee with artivle 595751142.\n"));
}
$nntp = new NNTP();
$nntp->doConnect();

$first = $argv[2];
$group = $argv[1];

// Select a group.
$groupArr = $nntp->selectGroup($group);
print_r($groupArr);

// Insert actual local part numbers here.
$msg = $nntp->getOverview($first.'-'.$first,true,false);

// Print out the array of headers.
print_r($msg);

// get postdate for an article
$backfill = new Backfill();
$newdate = $backfill->postdate($nntp, $first, false, $group, true, 'normal');

if ($newdate != false) {
	echo $c->primary("The posted date for ".$group.", article ".$first." is ".date('Y-m-d H:i:s', $newdate));
} else {
	echo $c->info("Server failed to return postdate.");
}
$nntp->doQuit();
