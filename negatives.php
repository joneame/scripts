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
$ids = $db->get_results("SELECT link_id from links where link_negatives=0");
foreach($ids as $dbid) {
	$link->id = $dbid->link_id;
	$link->read();
	echo "$link->id\n";
	$link->negatives = $db->get_var("select count(*) from votes where vote_type='links' and vote_link_id=$link->id and vote_value < 0");
	$link->store();
	usleep(1000);
}