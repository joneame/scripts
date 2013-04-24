<?php
// The source code packaged with this file is Free Software, Copyright (C) 2005 by
// Jonéame Development Team (admin@joneame.net)
// It's licensed under the AFFERO GENERAL PUBLIC LICENSE unless stated otherwise.
// You can get copies of the licenses here:
// 		http://www.affero.org/oagpl.html
// AFFERO GENERAL PUBLIC LICENSE is also included in the file called "COPYING".

include '../config.php';
include mnminclude.'link.php';
ini_set('display_errors', false);

$todos = $db->get_results("SELECT * FROM links WHERE link_id > 25233 ");
foreach ($todos as $id) {
	$link = new Link;
	$link->id = $id->link_id;
	if ($link->get_thumb()) echo "obtenido: ".$id->link_id."\n";
	else echo "error: ".$id->link_id."\n";
}