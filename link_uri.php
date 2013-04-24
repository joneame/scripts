<?php
// The source code packaged with this file is Free Software, Copyright (C) 2005 by
// Ricardo Galli <gallir at uib dot es>
// It's licensed under the AFFERO GENERAL PUBLIC LICENSE unless stated otherwise.
// You can get copies of the licenses here:
// 		http://www.affero.org/oagpl.html
// AFFERO GENERAL PUBLIC LICENSE is also included in the file called "COPYING".

include('../config.php');
include(mnminclude.'uri.php');
include(mnminclude.'link.php');

header("Content-Type: text/plain");

$link = new Link;
$ids = $db->get_results("SELECT link_id from links where link_uri is null order by link_id");

if (!$ids) {
	echo "OK\n\n";
	die;
}

foreach($ids as $dbid) {
	$link->id = $dbid->link_id;
	$link->read();
	if (!empty($link->title)) {
		$link->get_uri();
		echo "$link->title -> $link->uri\n";
		$link->store();
	}
}