<?php
// The source code packaged with this file is Free Software, Copyright (C) 2005 by
// Ricardo Galli <gallir at uib dot es>
// It's licensed under the AFFERO GENERAL PUBLIC LICENSE unless stated otherwise.
// You can get copies of the licenses here:
// 		http://www.affero.org/oagpl.html
// AFFERO GENERAL PUBLIC LICENSE is also included in the file called "COPYING".

include('../config.php');
include(mnminclude.'post.php');

header("Content-Type: text/plain");

$db->connect();
$sql = "select post_id from posts where post_date > date_sub(now(), interval 10 day) and post_content like '%@%'";
$result = mysql_query($sql, $db->dbh) or die('Query failed: ' . mysql_error());
while ($res = mysql_fetch_object($result)) {
	$comment = new Post;
	$comment->id = $res->post_id;
	$comment->read();
	echo "Updating $comment->id\n";
	$comment->update_conversation();
	usleep(1000);
}