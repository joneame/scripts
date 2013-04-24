<?php
// The source code packaged with this file is Free Software, Copyright (C) 2005 by
// David Martí <neikokz@gmail.com>
// It's licensed under the AFFERO GENERAL PUBLIC LICENSE unless stated otherwise.
// You can get copies of the licenses here:
// 		http://www.affero.org/oagpl.html
// AFFERO GENERAL PUBLIC LICENSE is also included in the file called "COPYING".

/*
	neiko: 09/04/2013
	hay muchos thumbs muertos.
	con este script se comprueba la existencia
	de los thumbs y de no existir se eliminan.
	no tengo muchas ganas de usar las clases de
	menéame así es que...
	(tarda la puta vida en ejecutarse, es lo
	que hay)
*/

include(__DIR__.'/../config.php');

$thumbs = $db->get_results('SELECT link_id, link_thumb FROM links WHERE link_thumb LIKE "/%"');

foreach ($thumbs as $thumb) {
	if (!file_exists(__DIR__."/..".$thumb->link_thumb)) {
		echo "http://joneame.net".$thumb->link_thumb." does not seem to exist, removing\n";
		$db->query('UPDATE links SET link_thumb_status = "error", link_thumb_x = 0, link_thumb_y = 0, link_thumb = "" WHERE link_id = '.$thumb->link_id);
	}
}