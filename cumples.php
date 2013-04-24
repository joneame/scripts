<?php
// The source code packaged with this file is Free Software, Copyright (C) 2005 by
// Jonéame Development Team (admin@joneame.net)
// It's licensed under the AFFERO GENERAL PUBLIC LICENSE unless stated otherwise.
// You can get copies of the licenses here:
// 		http://www.affero.org/oagpl.html
// AFFERO GENERAL PUBLIC LICENSE is also included in the file called "COPYING".

include '../config.php';
include mnminclude.'post.php';

echo "Starting...\n";

$users = $db->get_results("SELECT SQL_NO_CACHE user_id, user_birth, user_login from users where user_birth != '' ");

$dia_hoy = date('j');
$mes_hoy = date('n');
$juntos_hoy = $dia_hoy.','.$mes_hoy;

foreach ($users as $dbuser) {
	echo $dbuser->user_login."\n";

    $partes = explode(',', $dbuser->user_birth);
    $mes = $partes[1];
    $dia = $partes[0];

    $juntos = $dia.','.$mes;
	if ($juntos ==$juntos_hoy) {

	include_once('posts.php');
 	$post = new Post; 		
	$post->date = $globals['now']; 		
	$post->author = 73; 		
	$post->src = 'api'; 		
	$post->karma = 0; 		
	$post->randkey = rand(1000000,100000000); 		
	$post->tipo = 'normal'; 		
	$post->content = 'Hoy por ser el día de tu cumple 
                         este gato guapetón te va a permitir jonear hasta reventar 
                         puedes ser un troll, 
                         puedes spamear, 
                         que el día de hoy nadie te va a censurar, 
                         pero no te acostumbres amiguito 
                         que lo bueno se te va acabar.
                         Zorionak @'.$dbuser->user_login;       ; 		
	$post->store();

	}
}