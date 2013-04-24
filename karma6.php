<?php
// The source code packaged with this file is Free Software, Copyright (C) 2005 by
// Ricardo Galli <gallir at uib dot es> and the Jonéame Development Team (admin@joneame.net)
// It's licensed under the AFFERO GENERAL PUBLIC LICENSE unless stated otherwise.
// You can get copies of the licenses here:
// 		http://www.affero.org/oagpl.html
// AFFERO GENERAL PUBLIC LICENSE is also included in the file called "COPYING".

require(__DIR__.'/../config.php');
require(mnminclude.'user.php');
require(mnminclude.'annotation.php');

header("Content-Type: text/plain");

//recordatorio de cortos pendientes

$to      = 'admin@joneame.net';
$subject = 'Atención: cortos sin activar';
$cortos = $db->get_var("SELECT count(*) FROM cortos where activado=0");
$ediciones = $db->get_var("SELECT count(*) FROM edicion_corto");
$message = 'Hay '. $cortos .' cortos sin aprobar y '.$ediciones.' ediciones pendientes de aprobación';
$headers = 'From: admin@joneame.net' . "\r\n" .
    'Reply-To: admin@joneame.net' . "\r\n" .
    'X-Mailer: PHP/5';

if ($ediciones > 10 || $cortos > 15)

mail($to, $subject, $message, $headers);


// Delete old logs
$db->query("delete from logs where log_type in ('comment_new','login_failed') and log_date < date_sub(now(), interval 24 hour)");
$db->query("delete from logs where log_date < date_sub(now(), interval 30 day)");

// Delete not validated users
$db->query("delete from users where user_date < date_sub(now(), interval 24 hour) and user_date > date_sub(now(), interval 1 week) and user_validated_date is null");

// Delete old bad links
$db->query("delete from links where link_status='discard' and link_date < date_sub(now(), interval 20 minute) and link_date  > date_sub(now(), interval 1 week)and link_votes = 0");

$db->barrier();
// Delete email, names and url of invalidated users after three months
$dbusers = $db->get_col("select user_id from users where user_email not like '%@disabled' && user_level = 'disabled' and user_modification < date_sub(now(), interval 3 month)");
if ($dbusers) {
    foreach ($dbusers as $id) {
        $user = new User;
        $user->id = $id;
        $user->read();
        if ($user->level == 'disabled') { // Double check
            $user->disable();
            echo "Disabling: $id - $user->username\n";
        }
    }
}

// Lower karma of disabled users
$db->query("update users set user_karma = 7 where user_level='disabled' and user_karma >= 6");

$karma_base=7;
$karma_base_max=9; // If not penalised, older users can get up to this value as base for the calculus
$min_karma=1;
$max_karma=30;
$now = "'".$db->get_var("select now()")."'";
$history_from = "date_sub($now, interval 48 hour)";
$ignored_nonpublished = "date_sub($now, interval 12 hour)";
$points_per_published = 2;
$points_given = 8;
$comment_votes = 8;

// Following lines are for negative points given to links
// It takes in account just votes during 24 hours
$points_discarded = 0.10;
$discarded_history_from = "date_sub($now, interval 24 hour)";
$ignored_nondiscarded = "date_sub($now, interval 6 hour)";

// The formula to calculate the decreasing vote points
$sql_points_calc = 'sum((unix_timestamp(link_date) - unix_timestamp(vote_date))/(unix_timestamp(link_date) - unix_timestamp(link_sent_date))) as points';



$db->barrier();
$published_links = intval($db->get_var("SELECT SQL_NO_CACHE count(*) from links where link_status = 'published' and link_date > $history_from"));

$sum=0; $i=0;

$max_avg_positive_received = (int) $db->get_var("select avg(link_karma) from links where link_status='published' and link_date > $history_from");
$max_avg_positive_received = max(intval($max_avg_positive_received * 0.75), 1);

$max_avg_negative_received = (int) $db->get_var("select avg(link_karma) from links where link_karma < -20 and link_date > $history_from");
$max_avg_negative_received = min(intval($max_avg_negative_received), -20);



// "Unfair" negative votes max
$max_negative_comment_votes = (int) $db->get_var("select SQL_NO_CACHE count(*) as count from votes, comments where vote_type='comments' and vote_date > date_sub(now(), interval 30 hour) and vote_value < 0 and comment_id = vote_link_id and ((comment_karma-vote_value)/(comment_votes-1)) > 0 group by vote_user_id order by count desc limit 1");
$max_negative_comment_votes  = max($max_negative_comment_votes, 40);

print "Number of published links in period: $published_links\n";
print "Pos (top 10 average): $max_avg_positive_received, Neg: $max_avg_negative_received\n";
print "Max unfair comment votes: $max_negative_comment_votes\n";


/////////////////////////



echo "Starting...\n";
$no_calculated = 0;
$calculated = 0;

// We use mysql functions directly because  EZDB cannot hold all IDs in memory and the select fails miserably with about 40.000 users.

$users = "SELECT SQL_NO_CACHE user_id from users where user_level != 'disabled' order by user_modification desc";

$result = mysql_query($users, $db->dbh) or die('Query failed: ' . mysql_error());
while ($dbuser = mysql_fetch_object($result)) {
    $user = new User;
    $user->id=$dbuser->user_id;
    $user->read();
    printf ("%07d  %s\n", $user->id, $user->username);

    $total_comments = $sent_links = $karma0 = $karma1 = $karma2 = $karma3 = $karma4 = $karma5 = $penalized = 0;
    $output = '';

    //Base karma for the user
    $first_published = $db->get_var("select SQL_NO_CACHE UNIX_TIMESTAMP(min(link_date)) from links where link_author = $user->id and link_status='published';");
    if ($first_published > 0) {
        $karma_base_user = min($karma_base_max, $karma_base + ($karma_base_max - $karma_base) * (time()-$first_published)/(86400*365*2));
        $karma_base_user = round($karma_base_user, 2);
    } else {
        $karma_base_user = $karma_base;
    }

    $n = $db->get_var("SELECT SQL_NO_CACHE count(*) FROM  votes  WHERE vote_type in ('links', 'comments') and vote_user_id = $user->id and vote_date > $history_from");
    $n_events = $db->get_var("select SQL_NO_CACHE count(*) from logs where log_date > $history_from and log_user_id=$user->id");
    if ($n > 3 || $n_events > 0) {
        $output .= _('votos a historias y comentarios').': '. "$n, logs: $n_events\n";

        // Count the number of published links during the last period
        //$n_published = (int) $db->get_var("select SQL_NO_CACHE count(*) from links where link_author = $user->id and link_date > $history_from and link_status = 'published'");

        // Test with published during last three days
        $n_published = (int) $db->get_var("select SQL_NO_CACHE count(*) from links where link_author = $user->id and link_date > date_sub($now, interval 3 day) and link_status = 'published'");

        $karma0 = $points_per_published * $n_published;
        // Max: 4 published
        $karma0 = min($points_per_published * 4, $karma0);
        if ($karma0 > 0) {
            $output .= _('publicadas').": $n_published carisma->: $karma0\n";
        }
        $calculated++;

/////////////////////
////// Calculates karma received from votes to links

        $total_user_links=intval($db->get_var("SELECT SQL_NO_CACHE count(distinct link_id) FROM links, votes WHERE link_author = $user->id and vote_type='links' and vote_link_id = link_id and vote_date > $history_from and link_status not in ('autodiscard')"));
        
        if ($total_user_links > 0) {
            $positive_karma_received = $negative_karma_received = 0;
            $karmas = $db->get_col("SELECT SQL_NO_CACHE link_karma FROM links WHERE link_author = $user->id and link_date > $history_from and link_karma > 0 and link_status in ('published', 'queued')");
            if ($karmas) {
                foreach ($karmas as $k) {
                    $positive_karma_received += pow(min(1,$k/$max_avg_positive_received), 2) * 3;
                }
            }
            $karmas = $db->get_col("SELECT SQL_NO_CACHE link_karma FROM links WHERE link_author = $user->id and link_date > $history_from and link_karma < 0");
            if ($karmas) {
                foreach ($karmas as $k) {
                    $negative_karma_received += pow(min(1,$k/$max_avg_negative_received), 2) * 3;
                }
            }
            $karma_received = $positive_karma_received - $negative_karma_received;
            $karma1 = min(12, $karma_received);
            $karma1 = max(-12, $karma1);
            
            // Check if the user has links tagged as abuse
            $link_abuse = (int) $db->get_var("select count(*) from links where link_author = $user->id and link_date > $history_from and link_status = 'abuse'");
            if ($link_abuse > 0) {
                $pun =  4 * $link_abuse;
                $karma1 = max(-12, $karma1 - $pun);
                $output .= _('penalizado por enviar enlaces que violan las reglas')." ($link_abuse): $pun\n";
                $penalized = 1;
            }


            $output .= _('carisma recibido en envíos propios->').": ";
            $output .= sprintf("%4.2f\n", $karma_received);
        } 

        $user_votes = $db->get_row("SELECT SQL_NO_CACHE count(*) as count, $sql_points_calc FROM votes,links WHERE vote_type='links' and vote_user_id = $user->id and link_date > $history_from  and vote_value > 0 AND link_id = vote_link_id AND link_status = 'published' and vote_date < link_date and link_author != $user->id");
        $published_points = (int) $user_votes->points;
        $published_given = (int) $user_votes->count;
        if ($user_votes->points > 0) 
            $published_average = $published_points/$published_given;
        else 
            $published_average = 0;

        $nopublished_given = (int) $db->get_var("SELECT SQL_NO_CACHE count(*) FROM votes,links WHERE vote_type='links' and vote_user_id = $user->id and vote_date > $history_from and vote_date < $ignored_nonpublished and vote_value > 0 AND link_id = vote_link_id AND link_status != 'published' and link_author != $user->id");

        $discarded_given = (int) $db->get_var("SELECT SQL_NO_CACHE count(*) FROM votes,links WHERE vote_type='links' and vote_user_id = $user->id and vote_date > $discarded_history_from  and vote_value > 0 AND link_id = vote_link_id AND link_status in ('discard', 'autodiscard') and link_author != $user->id");

        $abuse_given = (int) $db->get_var("SELECT SQL_NO_CACHE count(*) FROM votes,links WHERE vote_type='links' and vote_user_id = $user->id and vote_date > $history_from  and vote_value > 0 AND link_id = vote_link_id AND link_status in ('abuse') and link_author != $user->id");

        $karma2 = min($points_given, $points_given * pow($published_average, 2) * ($published_points/($published_links/5) - ($nopublished_given/$published_links)/10) - 0.1 * $discarded_given);

        if ($abuse_given > 0) {
            $pun = $abuse_given * 2;
            $karma2 -= $pun;
            $output .= _('descuento por votar a enlaces que violan las reglas')." ($abuse_given):  $pun\n";
        }

        if ($karma2 > 0) {
            // Count the  comments of the users during the analised period
            $total_comments = intval($db->get_var("select SQL_NO_CACHE count(*) from comments where comment_user_id = $user->id and comment_date > $history_from"));
            // Count the numbers of link sent by the user in the last 60 days
            $sent_links = intval($db->get_var("select SQL_NO_CACHE count(*) from links where link_author = $user->id and link_date > date_sub(now(), interval 30 day) and link_status != 'discard' and link_status != 'abuse' and link_karma > 50 "));

        }

        //echo "Published giveN: $published_given Published links: $published_links No published: $nopublished_given Comments: $total_comments Links: $sent_links Average: $published_average\n";
        // Bot and karmawhoring warning!!!
        if ($karma2 > 0 && $published_given > $published_links/10 && $published_given > $nopublished_given*1.5 &&
                ($published_average < 0.50 || 
                ($total_comments < $published_given/2 && $sent_links == 0)) 
            ) {
            $penalized = 1;
/*            if ($total_comments == 0 && $sent_links == 0) {
                $output .= _('Coeficiente de votos muy bajos, posible bot, penalizado');
                $punish_coef = 5;
            } else {
               $output .= _('Coeficiente de votos muy bajos, ¿"carismawhore"?, penalizado');
                $punish_coef = 1;
            }*/
            $punishment = -(1 - $published_average) * $punish_coef;
            $output .= sprintf(" karma2 = %4.2f -> %4.2f\n", $karma2, $punishment);
           // $karma2 = $punishment;
        } elseif ($karma2 > 0 && ($sent_links == 0 || ($published_given > $nopublished_given && $published_points > $published_links/3 && $published_given > $published_links/5))) {
        // Limit karma to users that does not send any link
        // or "moderated" karma whores
            $karma2 = $karma2 * 0.5;
        }


        if ($karma2 != 0) {
            $output .= _('votos: a publicadas').": $published_given, "._('no publicadas').": $nopublished_given, "._('descartadas').": $discarded_given\n";
            $output .= sprintf(_('carisma por votos->').": %4.2f\n", $karma2);
        }


        $negative_discarded = (int) $db->get_var("SELECT SQL_NO_CACHE count(*) FROM votes,links WHERE vote_type='links' and vote_user_id = $user->id and vote_date > $discarded_history_from  and vote_value < 0 AND link_id = vote_link_id AND link_status in ('discard', 'autodiscard', 'abuse') and TIMESTAMPDIFF(MINUTE, link_date, vote_date) < 15 ");

        $negative_no_discarded = (int) $db->get_var("SELECT SQL_NO_CACHE count(*) FROM votes,links WHERE vote_type='links' and vote_user_id = $user->id and vote_date > $discarded_history_from and vote_date < $ignored_nondiscarded and vote_value < 0 AND link_id = vote_link_id AND link_status not in ('discard', 'autodiscard', 'abuse') and link_negatives < link_votes/15");

       /* if ($negative_no_discarded > $negative_discarded/4) { // To fight against karma whores and bots
            $karma3 = $points_discarded * ($negative_discarded - $negative_no_discarded);
        } */
        
        if ($karma3 != 0) {
            $output .= _('votos negativos a descartadas').": $negative_discarded, "._('no descartadas').": $negative_no_discarded: -> ";
            $output .= sprintf("%4.2f\n", $karma3);
        }

        // Check the user don't abuse voting only negative
        $max_allowed_negatives = round(($nopublished_given + $published_given + $negative_discarded) * $user->karma / 10);
        if($negative_no_discarded > 10 && $negative_no_discarded > $max_allowed_negatives) {
            $punishment = min(1+$negative_no_discarded/$max_allowed_negatives, 4);
            $karma3 -= $punishment;
            $penalized = 1;
            $output .= _('exceso de votos negativos a enlaces')." ($negative_no_discarded > $max_allowed_negatives), "._('penalización').": $punishment, Reducido a:  ";
            $output .= sprintf("%4.2f\n", $karma3);
        }

        $comment_votes_count = (int) $db->get_var("SELECT SQL_NO_CACHE count(*) from votes, comments where comment_user_id = $user->id and comment_date > $history_from and vote_type='comments' and vote_link_id = comment_id and  vote_date > $history_from and vote_user_id != $user->id");
        if ($comment_votes_count > 10)  {
            // It calculates a coefficient for the karma, 
            // if number of distinct votes comments >= 10 -> coef = 1, if comments = 1 -> coef = 0.1
            $distinct_votes_count = (int) $db->get_var("SELECT SQL_NO_CACHE count(distinct comment_id) from votes, comments where comment_user_id = $user->id and comment_date > $history_from and vote_type='comments' and vote_link_id = comment_id and vote_user_id != $user->id");
            $distinct_user_votes_count = (int) $db->get_var("SELECT SQL_NO_CACHE count(distinct vote_user_id) from votes, comments where comment_user_id = $user->id and comment_date > $history_from and vote_type='comments' and vote_link_id = comment_id and vote_user_id != $user->id");
            $comments_count = (int) $db->get_var("SELECT SQL_NO_CACHE count(*) from comments where comment_user_id = $user->id and comment_date > $history_from");
            $comment_coeff =  min($comments_count/10, 1) * min($distinct_votes_count/($comments_count*0.75), 1) * $distinct_user_votes_count/$comment_votes_count;
            //echo "Comment new coef: $comment_coeff ($distinct_votes_count,  $distinct_user_votes_count, $comments_count)\n";

            $comment_votes_sum = (int) $db->get_var("SELECT SQL_NO_CACHE sum(vote_value) from votes, comments where comment_user_id = $user->id and comment_date > $history_from and vote_type='comments' and vote_link_id = comment_id and vote_date > $history_from and vote_user_id != $user->id");
            $karma4 = max(-$comment_votes, min($comment_votes_sum / ($comment_votes_count*10) * $comment_votes, $comment_votes)) * $comment_coeff ;
        }
        
        // Limit karma to users that does not send links and does not vote
        if ( $karma4 > 0 && $karma1 == 0 && $karma2 == 0 && $karma3 == 0 ) $karma4 = $karma4 * 0.5;
        if ($karma4 != 0) {
            $output .= _('votos a tus comentarios').": $comment_votes_count (carisma: $comment_votes_sum)-> ";
            $output .= sprintf("%4.2f\n", $karma4);    
        }

        // Penalize to unfair negative comments' votes
        $negative_abused_comment_votes_count = (int) $db->get_var("select SQL_NO_CACHE count(*) from votes, comments where vote_type='comments' and vote_user_id = $user->id and vote_date > $history_from and vote_value < 0 and comment_id = vote_link_id and ((comment_karma-vote_value)/(comment_votes-1)) > 0 and (comment_votes < 5 or comment_karma > 5 * comment_votes)");
        if ($negative_abused_comment_votes_count > 3) {
            $karma5 = max(-$comment_votes, -$comment_votes * 2 * $negative_abused_comment_votes_count / $max_negative_comment_votes);
            $karma5 -= $karma0; // Take away karma0
            if ($karma4 > 0) {
                $karma5 -= $karma4 / 2; // Take away half karma4
            }
        }
        if ($karma5 != 0) {
            $penalized = 1;
            $output .= _('exceso de votos negativos injustos a comentarios.').": $negative_abused_comment_votes_count, ->reducido a la mitad: ";
            $output .= sprintf("%4.2f\n", $karma5);    
        }

        $karma_extra = $karma0+$karma_received+$karma2+$karma3+$karma4+$karma5;
        // If the new value is negative or the user is penalized do not use the highest calculated karma base
        if (($karma_extra < 0 && $user->karma <= $karma_base) || $penalized) $karma_base_user = $karma_base;
        $karma = max($karma_base_user+$karma_extra, $min_karma);
        $karma = min($karma, $max_karma);
    } else {
        $no_calculated++;
        $output = '';
        if (abs($user->karma - $karma_base) < 0.1) {
            $karma = $karma_base;
        } elseif ($user->karma > $karma_base) {
            $karma = max($karma_base, $user->karma - 1);
        } elseif ($user->karma < $karma_base) {
            $karma = min($karma_base, $user->karma + 0.1);
        } else {
            $karma = $user->karma;
        }
    }
    $karma = round($karma, 2);

    if ($user->karma != $karma) {
        $output .= sprintf("carisma base: %4.2f\n", $karma_base_user);
        $old_karma = $user->karma;
        if ($old_karma > $karma) {
            // Decrease slowly
            $user->karma = 0.9*$old_karma + 0.1*$karma;
        } else {
            // Increase faster
            $user->karma = 0.8*$old_karma + 0.2*$karma;
        }
        /* Admin manually carisma reduction */
        if ($old_karma != $user->previous_carisma) {
            $user->karma = $user->previous_carisma; //restore carisma 

        }
           $user->previous_carisma = $user->karma;
        if ($user->karma > 27 && $user->level == 'normal') { //$max_karma * 0.85
            $user->level = 'special';
        } else {
            if ($user->level == 'special' && $user->karma < 26) { //$max_karma * 0.6
                $user->level = 'normal';
            }
        }
        $output .= sprintf(_('carisma final').": %4.2f,  ".('cálculo actual').": %4.2f, ".('carisma anterior').": %4.2f\n", 
                    $user->karma, $karma, $old_karma);
        $user->store();
        // If we run in the same server as the database master, wait few milliseconds
        if (!$db->dbmaster) {
            usleep(5000); // wait 1/200 seconds
        }
    }
    if (!empty($output)) {
        $annotation = new Annotation("karma-$user->id");
        $annotation->text = $output;
        $annotation->store();
    }
    $db->barrier();
    echo $output;
}
mysql_free_result($result);
if ($annotation) $annotation->optimize();
echo "Calculados: $calculated, Ignorados: $no_calculated\n";
