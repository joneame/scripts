<?php
// The source code packaged with this file is Free Software, Copyright (C) 2005 by
// Ricardo Galli <gallir at uib dot es>
// It's licensed under the AFFERO GENERAL PUBLIC LICENSE unless stated otherwise.
// You can get copies of the licenses here:
// 		http://www.affero.org/oagpl.html
// AFFERO GENERAL PUBLIC LICENSE is also included in the file called "COPYING".

include('../config.php');
include(mnminclude.'user.php');

header("Content-Type: text/plain");

$users = $db->get_results("SELECT SQL_NO_CACHE user_id from users");
$no_calculated = 0;
$calculated = 0;
foreach($users as $dbuser) {
	$user = new User;
	$user->id=$dbuser->user_id;
	$user->read();
	if (strlen($user->pass) != 32 ) {
		$user->pass = md5($user->pass);
		$user->store();
		echo "Wrong, forcing conversion: ". $user->username . "\n";
	} else {
		echo "Converted: ". $user->username . "\n";
	}
}