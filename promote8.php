<?php
// The source code packaged with this file is Free Software, Copyright (C) 2005 by
// Ricardo Galli <gallir at uib dot es> and the Jonéame Development Team (admin@joneame.net)
// It's licensed under the AFFERO GENERAL PUBLIC LICENSE unless stated otherwise.
// You can get copies of the licenses here:
// 		http://www.affero.org/oagpl.html
// AFFERO GENERAL PUBLIC LICENSE is also included in the file called "COPYING".

$install_dir = "/srv/http/joneame.net";

include($install_dir.'/config.php');
include(mnminclude.'link.php');
include(mnminclude.'user.php');
include_once(mnminclude.'log.php');
include_once(mnminclude.'ban.php');
include_once(mnminclude.'annotation.php');

define('DEBUG', false);

echo '<html><head><title>promote8.php</title></head><body>';

$min_karma_coef = 0.87;

define('MAX', 1.15);
define ('MIN', 1.0);
define ('PUB_MIN', 20);
define ('PUB_MAX', 75);
define ('PUB_PERC', 0.10);

//publicadas y en cola (24 horas)
$links_queue = $db->get_var("SELECT SQL_NO_CACHE count(*) from links WHERE link_date > date_sub(now(), interval 24 hour) and link_status in ('published', 'queued')");

//todas 24 horas
$links_queue_all = $db->get_var("SELECT SQL_NO_CACHE count(*) from links WHERE link_date > date_sub(now(), interval 24 hour) and link_votes > 0");

$pub_estimation = intval(max(min($links_queue * PUB_PERC, PUB_MAX), PUB_MIN));
$interval = intval(86400 / $pub_estimation);

$now = time();
$output = "<p><b>Ejecutado</b>: ".get_date_time($now)."<br/><br/>\n";

$from_time = "date_sub(now(), interval 5 day)";
#$from_where = "FROM votes, links WHERE

//hora ultima publicada
$last_published = $db->get_var("SELECT SQL_NO_CACHE UNIX_TIMESTAMP(max(link_date)) from links WHERE link_status='published'");

if (!$last_published) $last_published = $now - 24*3600*30;

//cantidad de publicadas 24 horas
$links_published = (int) $db->get_var("select SQL_NO_CACHE count(*) from links where link_status = 'published' and link_date > date_sub(now(), interval 24 hour)");

$links_published_projection = 4 * (int) $db->get_var("select SQL_NO_CACHE count(*) from links where link_status = 'published' and link_date > date_sub(now(), interval 6 hour)");

$diff = $now - $last_published;
// If published and estimation are lower than projection then
// fasten decay
if ($diff < $interval && ($links_published_projection < $pub_estimation * 0.9 && $links_published < $pub_estimation * 0.9 )) {
    $diff = max($diff * 2, $interval);
}

$decay = min(MAX, MAX - ($diff/$interval)*(MAX-MIN) );

$decay = max($min_karma_coef, $decay);

$must_publish = false;

if ($diff > $interval * 2) {
    $must_publish = true;
    $output .= "Retrasado! <br/>";
}

$output .= "Publicada anterior a las: " . get_date_time($last_published) ."<br/><br/>\n";
$output .= "Cantidad de publicadas y pendientes en 24 horas: $links_queue Cantidad enviadas en 24 horas: $links_queue_all, Publicadas en 24 horas: $links_published <br/><br/>\n";

$continue = true;
$published=0;

//media de carisma de publicadas en los últimos 7 días
$past_karma_long = intval($db->get_var("SELECT SQL_NO_CACHE avg(link_karma) from links WHERE link_date >= date_sub(now(), interval 7 day) and link_status='published'"));

//media de carisma de publicadas últimas 12 horas
$past_karma_short = intval($db->get_var("SELECT SQL_NO_CACHE avg(link_karma) from links WHERE link_date >= date_sub(now(), interval 12 hour) and link_status='published'"));


$past_karma = 0.5 * max(40, $past_karma_long) + 0.5 * max($past_karma_long*0.8, $past_karma_short);
$min_past_karma = (int) ($past_karma * $min_karma_coef);
$last_resort_karma = (int) $past_karma * 0.8;


//////////////
$min_karma = round(max($past_karma * $decay, 20));

if ($decay >= 1) $max_to_publish = 5;
else $max_to_publish = 3;

$min_votes = 3;
/////////////

$limit_karma = round(min($past_karma,$min_karma) * 0.65);
$bonus_karma = round(min($past_karma,$min_karma) * 0.40);


/// Coeficients to balance metacategories
$days = 2;
$total_published = (int) $db->get_var("select SQL_NO_CACHE count(*) from links where link_status = 'published' and link_date > date_sub(now(), interval $days day)");
$db_metas = $db->get_results("select category_id, category_name, category_calculated_coef from categories where category_parent = 0 and category_id in (select category_parent from categories where category_parent > 0)");
foreach ($db_metas as $dbmeta) {
    $meta = $dbmeta->category_id;
    $meta_previous_coef[$meta] = $dbmeta->category_calculated_coef;
    $meta_names[$meta] = $dbmeta->category_name;
    $x = (int) $db->get_var("select SQL_NO_CACHE count(*) from links, categories where link_status = 'published' and link_date > date_sub(now(), interval $days day) and link_category = category_id and category_parent = $meta");
   $y = (int) $db->get_var("select SQL_NO_CACHE count(*) from links, categories where link_status in ('published', 'queued') and link_date > date_sub(now(), interval $days day) and link_category = category_id and category_parent = $meta");
  $z = (int) $db->get_var("select SQL_NO_CACHE count(*) from links, categories where link_date > date_sub(now(), interval $days day ) and link_category = category_id and category_parent = $meta");


if ($y == 0) $y = 1;
    $meta_coef[$meta] = $x/$y;
    if ($total_published == 0) $total_published = 1;
    $meta_coef[$meta] = 0.7 * $meta_coef[$meta] + 0.3 * $x / $total_published / count($db_metas) ;
$meta_avg = 0;
    $meta_avg += $meta_coef[$meta] / count($db_metas);
    $output .= "estadísticas de $days días para <b>$meta_names[$meta]</b> en cola y publicadas: $y,  De $z totales, $x han sido publicadas<br/>";

}
$output.= "<br/>";

foreach ($meta_coef as $m => $v) {
    if ($v == 0) $v = 1;
    $meta_coef[$m] = max(min($meta_avg/$v, 1.4), 0.7);
    if ($meta_previous_coef[$m]  > 0.6 && $meta_previous_coef[$m]  < 1.5) {
        //echo "Previous: $meta_previous_coef[$m], current: $meta_coef[$m] <br>";
        $meta_coef[$m] = 0.05 * $meta_coef[$m] + 0.95 * $meta_previous_coef[$m] ;
    }
    // Store current coef in DB
    if (! DEBUG) {
        $db->query("update categories set category_calculated_coef = $meta_coef[$m] where (category_id = $m || category_parent = $m)");
    }
}


// Karma average:  It's used for each link to check the balance of users' votes

$globals['users_karma_avg'] = (float) $db->get_var("select SQL_NO_CACHE avg(link_votes_avg) from links where link_status = 'published' and link_date > date_sub(now(), interval 72 hour)");

$output .= "<br/>Media de valor de votos en publicadas los últimos 7 días: ".$globals['users_karma_avg'].",<br/><br/> Media de carisma de publicadas de los últimos 7 días: $past_karma_long, Media de carisma de publicadas en las últimas 12 horas: $past_karma_short<br/>\n";

$output .= "<br/><b>Carisma mínimo requerido para publicarse: $min_karma</b>,  analizando desde $limit_karma<br/>\n";

$despues = $now + 300;
$output .= "<p><br/>Próxima ejecución a las: ".get_date_time($despues)."<br/><br/>\n";


$output .= "</p>\n";


$where = "link_date > $from_time AND link_status = 'queued' AND link_votes>=$min_votes  AND (link_karma > $limit_karma or (link_date > date_sub(now(), interval 2 hour) and link_karma > $bonus_karma)) and user_id = link_author and category_id = link_category";
$sort = "ORDER BY link_karma DESC, link_votes DESC";

$links = $db->get_results("SELECT SQL_NO_CACHE link_id, link_karma as karma from links, users, categories where $where $sort LIMIT 30");
$rows = $db->num_rows;

if (!$rows) {
    $output .= "No hay artículos<br/>\n";
    $output .= "--------------------------<br/>\n";
    echo $output;
    echo "</body></html>\n";
    if (! DEBUG) {
        $annotation = new Annotation('promote');
        $annotation->text = $output;
        $annotation->store();
    }
    die;
}

$max_karma_found = 0;
$best_link = 0;
$best_karma = 0;
$output .= "<table>\n";
if ($links) {
    $output .= "<tr class='thead'><th>votos</th><th>anonimos</th><th>negativos</th><th>carisma</th><th>categoria</th><th>titulo</th><th></th></tr>\n";
    $i=0;
    foreach($links as $dblink) {
        $link = new Link;
        $link->id=$dblink->link_id;
        $link->read();
        $user = new User;
        $user->id = $link->author;
        $user->read();

        $karma_pos_user = 0;
        $karma_neg_user = 0;
        $karma_pos_ano = 0;

        // Otherwise use normal decayed min_karma
        $karma_threshold = $min_karma;


        $karma_new = $link->karma;
        $link->message = '';
        $changes = 0;
        if (DEBUG ) $link->message .= "previous carisma: $link->old_karma calculated carisma: $link->karma new carisma: $karma_new<br>\n";

        // Verify last published from the same site
        $hours = 8;
        $min_pub_coef = 0.8;
        $last_site_published = (int) $db->get_var("select SQL_NO_CACHE UNIX_TIMESTAMP(max(link_date)) from links where link_blog = $link->blog and link_status = 'published' and link_date > date_sub(now(), interval $hours hour)");
        if ($last_site_published > 0) {
            $pub_coef = $min_pub_coef  + ( 1- $min_pub_coef) * (time() - $last_site_published)/(3600*$hours);
            $karma_new *= $pub_coef;
            $link->message .= 'Ultima publicada del mismo sitio: Hace '. intval((time() - $last_site_published)/3600) . ' horas.<br/>';
        }


        if(($ban = check_ban($link->url, 'hostname', false, true))) {
            // Check if the  domain is banned
            $karma_new *= 0.5;
            $link->message .= 'Dominio baneado.<br/>';
            $link->annotation .= _('Dominio baneado').": ".$ban['comment']."<br/>";
        } elseif ($user->level == 'disabled' ) {
            // Check if the user is banned disabled
            if (preg_match('/^_+[0-9]+_+$/', $user->username)) {
                $link->message .= "$user->username dado de baja, penalizado.<br/>";
            } else {
                $link->message .= "$user->username baneado, penalizado.<br/>";
            }
            $karma_new *= 0.5;
            $link->annotation .= _('Cuenta deshabilitada'). "<br/>";
        } elseif (check_ban($link->url, 'punished_hostname', false, true)) {
            // Check domain and user punishments
            $karma_new *= 0.75;
            $link->message .= $globals['ban_message'].'<br/>';
        } elseif ($meta_coef[$dblink->parent] < 1.02 && ($link->content_type == 'image')) {
            // check if it's "media" and the metacategory coefficient is low
            $karma_new *= 1;
            $link->message .= 'Imagen o Video <br/>';
        }

        $link->karma = round($karma_new);

        if (! DEBUG && $link->thumb_status == 'unknown')
		$link->get_thumb();

        if (($link->votes >= $min_votes && $karma_new >= $karma_threshold && $published < $max_to_publish)) {
            $published++;
            $link->karma = round($karma_new);

            publish($link);
            $changes = 1; // to show a "published" later
        } else {
            if (( $must_publish || $link->karma > $min_past_karma)
                        && $link->karma > $limit_karma && $link->karma > $last_resort_karma &&
                        $link->votes > $link->negatives*20) {
                $last_resort_id = $link->id;
                $last_resort_karma = $link->karma;
            }
        }
        print_row($link, $changes);

        usleep(10000);
        $i++;
    }

    if (! DEBUG && $published == 0 && $links_published_projection < $pub_estimation * 0.9
            && $must_publish && $last_resort_id  > 0) {
        // Publish last resort
        $link = new Link;
        $link->id = $last_resort_id;
        if ($link->read()) {
            $link->message = "Seleccionada por ser la noticia con mayor carisma";
            print_row($link, 3);
            publish($link);
        }
    }
}

$output .= "</table>\n";

echo $output;
echo "</body></html>\n";
if (! DEBUG) {
    $annotation = new Annotation('promote');
    $annotation->text = $output;
    $annotation->store();
}

function print_row($link, $changes, $log = '') {
    global $globals, $output;
    static $row = 0;

    $mod = $row%2;
    $link->coef = 1.3;
    $output .= "<tr><td class='tnumber$mod'>".$link->votes."</td><td class='tnumber$mod'>".$link->anonymous."</td><td class='tnumber$mod'>".$link->negatives."</td><td class='tnumber$mod'>".intval($link->karma)."</td>";
    $output .= "<td class='tdata$mod'>$link->meta_name</td>\n";
    $output .= "<td class='tdata$mod'><a href='".$link->get_relative_permalink()."'>$link->title</a>\n";
    if (!empty($link->message)) {
        $output .= "<br/>$link->message";
    }
    $link->message = '';
    if (DEBUG) $output .= "Annotation: $link->annotation";

    $output .= "</td>\n";
    $output .= "<td class='tnumber$mod'>";
    switch ($changes) {
        case 1:
    $output .= '<img src="'.$globals['base_url'].'img/estructura/pixel.gif" width="24" height="20" style="background: url(\'img/iconos/coti.png\') 0 -120px;" alt="'. _('publicada') .'"/>';
            break;
    }
    $output .= "</td>";
    $output .= "</tr>\n";
    flush();
    $row++;

}


function publish($link) {
    global $globals, $db;

    if (DEBUG) return;

    // Calculate votes average

    $votes_avg = (float) $db->get_var("select SQL_NO_CACHE avg(vote_value) from votes, users where vote_type='links' AND vote_link_id=$link->id and vote_user_id > 0 and vote_value > 0 and vote_user_id = user_id and user_level !='disabled'");

    if ($votes_avg < $globals['users_karma_avg']) $link->votes_avg = max($votes_avg, $globals['users_karma_avg']*0.97);
    else $link->votes_avg = $votes_avg;


    $db->query("update links set link_status='published', link_date=now(), link_votes_avg=$link->votes_avg where link_id=$link->id");

    // Increase user's karma
    $user = new User;
    $user->id = $link->author;
    if ($user->read()) {
        $user->karma = min(30, $user->karma + 1);
        $user->previous_carisma = $user->karma;
        $user->store();
        $annotation = new Annotation("karma-$user->id");
        $annotation->append(_('Noticia publicada').": +1, carisma: $user->karma\n");
    }

    // Recheck for images, some sites add images after the article has been published
    if ($link->thumb_status != 'local' && $link->thumb_status != 'deleted') $link->get_thumb();

    //Add the publish event/log
    log_insert('link_publish', $link->id, $link->author);
    twitter_post($link);

}

function request_image($url) {
    /* downloads the link, checks whether it's an image and saves and returns its path (or not) */
    $max_size = 1024 * 1024 * 3;
    $promote_tmp_dir = "/tmp/jnm-promote";
    if (!is_dir($promote_tmp_dir)) {
        mkdir($promote_tmp_dir, 755);
    }
    $file = $promote_tmp_dir."/".md5($url);

    $fh = fopen($file, "wb");
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_HEADER, 0);
    curl_setopt($curl, CURLOPT_FILE, $fh);
    curl_setopt($curl, CURLOPT_PROGRESSFUNCTION, function($dl_size, $dl_progress, $ul_size, $ul_progress) {
        return ($dl_progress > $max_size);
    });
    $status = curl_exec($curl);
    curl_close($curl);
    fclose($fh);

    /* twitter does not let us post images larger than 3MB */
    if ($status === false || filesize($file) > $max_size) {
        return false;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimetype = $finfo->file($file);

    if (!in_array($mimetype, ['image/jpeg', 'image/png', 'image/gif'])) {
        return false;
    }

    return $file;
}

function twitter_post($link) {
    global $globals;

    if (!class_exists("OAuth")) {
        syslog(LOG_NOTICE, "Joneame: pecl/oauth is not installed");
        return;
    }

    $consumer_key = $globals['oauth']['twitter']['consumer_key'];
    $consumer_secret = $globals['oauth']['twitter']['consumer_secret'];
    $oauth_token = $globals['oauth']['twitter']['oauth_token'];
    $oauth_token_secret = $globals['oauth']['twitter']['oauth_token_secret'];
    $req_url = 'http://twitter.com/oauth/request_token';
    $acc_url = 'http://twitter.com/oauth/access_token';
    $authurl = 'http://twitter.com/oauth/authorize';
    $oauth = new OAuth($consumer_key, $consumer_secret, OAUTH_SIG_METHOD_HMACSHA1, OAUTH_AUTH_TYPE_URI);
    $oauth->setRequestEngine(OAUTH_REQENGINE_CURL);
    $oauth->setToken($oauth_token, $oauth_token_secret);

    $image = request_image($link->url);
    if ($image) {
        $title_len = 90;
        $api_url = 'https://api.twitter.com/1.1/statuses/update_with_media.json';
        $api_args['@media[]'] = "@{$image}";
    } else {
        $title_len = 110;
        $api_url = 'https://api.twitter.com/1.1/statuses/update.json';
    }

    $title = text_sub_text($link->title, $title_len);
    $permalink = $link->get_permalink();
    $message = "{$title} | {$permalink}";
    $api_args['status'] = $message;

    $oauth->fetch($api_url, $api_args, OAUTH_HTTP_METHOD_POST);
}

function catlink($url) {
    if (!function_exists('curl_init')) {
        syslog(LOG_NOTICE, "Joneame: curl is not installed");
        return $url;
    }
    $gs_url = 'http://catlink.eu/api.php?link='.urlencode($url);
    $session = curl_init();
    curl_setopt($session, CURLOPT_URL, $gs_url);
    curl_setopt($session, CURLOPT_USERAGENT, "joneame.net");
    curl_setopt($session, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($session, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($session, CURLOPT_TIMEOUT, 20);
    $result = curl_exec($session);
    curl_close($session);
    if (preg_match('/^OK/', $result)) {
        $array = explode(' ', $result);
        return $array[1];
    } else return $url;
}
