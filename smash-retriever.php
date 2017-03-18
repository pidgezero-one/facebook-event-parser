<?php
/*
Plugin Name: Smash Retriever

*/
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

register_activation_hook(__FILE__, 'my_activation');
add_action('my_hourly_event', 'update_db_hourly');

function my_activation() {
	wp_schedule_event(time(), 'hourly', 'my_hourly_event');
	update_db_hourly();
}

function update_db_hourly() {
	require_once __DIR__ . '/../../themes/themify-base/facebook-php-sdk-v4-5.0-dev/src/Facebook/autoload.php';
	session_start();
	$fb = new Facebook\Facebook([
	  'app_id' => 'YOUR_APP_ID',
	  'app_secret' => 'YOUR_APP_SECRET',
	  'default_graph_version' => 'v2.4',
	  ]);
	  
	require_once(__DIR__ . '/../../../wp-load.php');
	require_once(__DIR__ . '/../../../wp-includes/pluggable.php');
	global $wpdb;
	$results = $wpdb->get_results( 'SELECT token FROM access_tokens ORDER BY created_at DESC LIMIT 1', OBJECT );
	$token = $results[0]->token;

	$fb->setDefaultAccessToken($token);

	try {
	  $response = $fb->get('/YOUR_FACEBOOK_ID/events/attending?fields=description,name,start_time,id,place,end_time,type,category,timezone,updated_time&since= ' . time() . '&limit=1000');
	  $graphEdge = $response->getGraphEdge();
	} catch(Facebook\Exceptions\FacebookResponseException $e) {
	  // When Graph returns an error
	  //echo 'Graph returned an error: ' . $e->getMessage();
	  exit;
	} catch(Facebook\Exceptions\FacebookSDKException $e) {
	  // When validation fails or other local issues
	  //echo 'Facebook SDK returned an error: ' . $e->getMessage();
	  exit;
	}

	$allevents = $graphEdge->asArray();

	try {
	  $response2 = $fb->get('/YOUR_FACEBOOK_ID/events/not_replied?fields=description,name,start_time,id,place,end_time,type,category,timezone,updated_time&since= ' . time() . '&limit=1000');
	  $graphEdge2 = $response2->getGraphEdge();
	} catch(Facebook\Exceptions\FacebookResponseException $e) {
	  // When Graph returns an error
	  //echo 'Graph returned an error: ' . $e->getMessage();
	  exit;
	} catch(Facebook\Exceptions\FacebookSDKException $e) {
	  // When validation fails or other local issues
	  //echo 'Facebook SDK returned an error: ' . $e->getMessage();
	  exit;
	}
	$notreplied = $graphEdge2->asArray();
	foreach ($notreplied as $nr)
		array_push($allevents, $nr);


	  //ignore events in the past
	$eventCheck = array();
	$eventDel = array();
	$now = new DateTime("now", new DateTimeZone('America/Toronto'));
	$gmdate = new DateTime ();
	$query = "SELECT facebook_id FROM wp_ai1ec_events ";
	$firstresult = true;
	//get a unique identifier in case of duplicate event names
	try {
	$t = $wpdb->get_results('SELECT id FROM wp_posts
	ORDER BY id DESC
	LIMIT 1' );
	} catch (Exception $e) {
	}
	$start = intval($t[0]->id);
	$check_deleted = array();
	if ($start) {
		
		//build query to find existing events
		foreach ($allevents as $event) {
			//echo "<br>" . $event["id"] . " " . $event["name"] . "<br>";
		  if (($event["type"] == "public") && ((($event["place"]["location"]["latitude"] != NULL) && ($event["place"]["location"]["longitude"] != NULL) && ($event["place"]["location"]["state"] == 'ON')) || ((preg_match("/^((Flat [1-9][0-9]*, )?([1-9][0-9]* ))?([A-Z][a-z]* )*([A-Z][a-z]*)/", $event["place"]["name"]) ) && ($event["place"]["location"]["state"] == NULL)))) {
			//echo "Event is valid ";
			if ($event["end_time"]) {
				//echo "End time not null ";
				$enddate = $event["end_time"];
				if ($event["end_time"] > $now) 
					$insert = true;
				else
					$insert = false;
				//echo $insert . " ";
			}
			else {
				//echo "End time is null ";
				$eventdate = $event["start_time"]->format('Y-m-d 23:59:59');
				$nowdate = $now;
				$enddate = new DateTime($eventdate, new DateTimeZone('America/Toronto'));
				if ($enddate >= $now)
					$insert = true;
				else
					$insert = false;  	
				//echo $insert . " ";		
			}
			if ($insert) {
				$eventCheck[] = $event;
				if ($firstresult) {
					$query .= "WHERE facebook_id = '" . $event["id"] . "' ";
					$firstresult = false;
				}
				else {
					$query .= "OR facebook_id = '" . $event["id"] . "' ";
				}
			}
			else
				$eventDel[] = $event;
		  }
		  else
			$eventDel[] = $event;
		}
		//update DB with new info
		try {
			$r = $wpdb->get_results($query, ARRAY_N);
			foreach ($eventCheck as $event){
				if ($event["end_time"]) {
					//echo "End time not null ";
					$enddate = $event["end_time"];
				}
				else {
					//echo "End time is null ";
					$eventdate = $event["start_time"]->format('Y-m-d 23:59:59');
					$nowdate = $now;
					$enddate = new DateTime($eventdate, new DateTimeZone('America/Toronto'));
				}
				$old = false;
				foreach ($r as $row) {
				//see if event already exists in DB
				if (in_array($event["id"], $row))
					$old = true;
				}
				$eventname = preg_replace('/\W+/', '-', $event["name"]);
				if (substr($eventname, -1) != '-') 
					$eventname .= '-'; 
				$eventname .= $start;
				//if it doesn't exist in db, add it
				if (!$old) {
					$start++;
					try {
					if (!$event["place"]["location"]) {
						$latitude = NULL;
						$longitude = NULL;
						$city = NULL;
						$province = NULL;
						$postal_code = NULL;
						$address = $event["place"]["name"];
						$venue = NULL;
						$country = NULL;
						$coordinates = 1;
						$post_status = 'draft';
						wp_mail("ADMIN_EMAIL", "Draft event requires approval", "");
					}
					else {
						$latitude = $event["place"]["location"]["latitude"];
						$longitude = $event["place"]["location"]["longitude"];
						$city = $event["place"]["location"]["city"];
						$province = $event["place"]["location"]["state"];
						$postal_code = $event["place"]["location"]["zip"];
						$address = $event["place"]["location"]["street"];
						$venue = $event["place"]["name"];
						$country = $event["place"]["location"]["country"];
						$coordinates = 1;
						$post_status = 'publish';
					}
					$wpdb->insert( 'wp_posts', array('post_date' => $now->format('Y-m-d G:i:s'), 
									'post_author' => 1,
									'post_date_gmt' => $gmdate->format('Y-m-d G:i:s'),
									'post_content' => $event["description"],
									'post_title' => "[FB] " . $event["name"],
									'post_status' => $post_status,
									'comment_status' => 'closed',
									'ping_status' => 'closed',
									'post_name' => $eventname,
									'post_modified' => $now->format('Y-m-d G:i:s'), 
									'post_modified_gmt' => $gmdate->format('Y-m-d G:i:s'),
									'post_parent' => 0,
									'post_type' => 'ai1ec_event',
									'comment_count' => 0
									 ));
					$postid = $wpdb->insert_id;
					$wpdb->insert( 'wp_ai1ec_events', array('post_id' => $postid, 
										'start' => $event["start_time"]->getTimestamp(),
										'end' => $enddate->getTimestamp(),
										'timezone_name' => 'America/New York',
										'allday' => 0,
										'instant_event' => 0,
										'venue' => $venue,
										'country' => $country,
										'address' => $address,
										'city' => $city,
										'province' => $province,
										'postal_code' => $postal_code,
										'show_map' => 1,
										'contact_url' => 'https://www.facebook.com/events/' . $event["id"] . "/",
										'ical_organizer' => NULL,
										'ical_contact' => NULL,
										'ical_uid' => 'ai1ec-' . $start . '@ontario-smash.com',
										'show_coordinates' => $coordinates,
										'latitude' => $latitude,
										'longitude' => $longitude,
										'force_regenerate' => 0,
										'facebook_id' => $event["id"]
									 ));
					$wpdb->insert( 'wp_ai1ec_event_instances', array('post_id' => $postid, 
										'start' => $event["start_time"]->getTimestamp(),
										'end' => $enddate->getTimestamp()
									 ));
					//echo "event inserted: " . $event["id"] . "<br>";
					$start++;
					} catch (Exception $e) {
					//echo "Failed to insert " . $event["id"] . "<br>";
					}
				}
				//if it does, update it if the event's modified time is newer than the post's modified time
				else {
					try {
						$q = $wpdb->get_results('SELECT post_modified, id FROM wp_posts WHERE id IN (SELECT post_id FROM wp_ai1ec_events WHERE facebook_id = ' . $event["id"] . ')' );
					} catch (Exception $e) {
					}
					$pm = DateTime::createFromFormat('Y-m-d G:i:s', $q[0]->post_modified, new DateTimeZone('America/Toronto'));
					if ($pm < $event["updated_time"]) {
						$id = $q[0]->id;
						try {
						$wpdb->update( 'wp_ai1ec_event_instances', array(
										'start' => $event["start_time"]->getTimestamp(),
										'end' => $enddate->getTimestamp(),
										'timezone_name' => 'America/New York',
										'venue' => $event["place"]["name"],
										'country' => $event["place"]["location"]["country"],
										'address' => $event["place"]["location"]["street"],
										'city' => $event["place"]["location"]["city"],
										'province' => $event["place"]["location"]["state"],
										'postal_code' => $event["place"]["location"]["zip"],
										'latitude' => $event["place"]["location"]["latitude"],
										'longitude' => $event["place"]["location"]["longitude"],
									 ), array('post_id' => $id));
						$wpdb->update( 'wp_posts', array(
									'post_content' => $event["description"],
									'post_title' => $event["name"],
									'post_modified' => $now->format('Y-m-d G:i:s'), 
									'post_modified_gmt' => $gmdate->format('Y-m-d G:i:s'),
									 ), array('id' => $id));
									 
						$wpdb->update( 'wp_ai1ec_event_instances', array(
										'start' => $event["start_time"]->getTimestamp(),
										'end' => $enddate->getTimestamp()
									 ), array('post_id' => $id));
						//echo $id . " event updated<br>";
						}
						catch (Exception $e) {
							//echo "failed to update " . $id . "<br>";
						}
						
					}
				}
			}
		} catch (Exception $e) {
			//echo "Failed to fetch rows";
		}
		
		//delete all events that have been marked for deletion (either insufficient metadata or erroneously pulled event from the past)
		foreach ($eventDel as $db_event) {
			try {
				$qdel = $wpdb->get_results("SELECT post_id FROM wp_ai1ec_events WHERE facebook_id = '" . $db_event["id"] . "'" );
				if ($qdel) {
					try {
						$wpdb->delete( 'wp_ai1ec_events', array('post_id' => $qdel->post_id));
						$wpdb->delete( 'wp_posts', array('id' => $qdel->post_id));
						$wpdb->delete( 'wp_ai1ec_event_instances', array('post_id' => $qdel->post_id));
						//echo "<br>Event deleted from eventDel: " . $db_event["id"];
					} catch (Exception $e) {
						//echo "<br>Failed to delete " . $db_event["id"] . ": " . $e->getMessage();
					}
				}
			} catch (Exception $e) {
			}
		}
				
		//check all events in db for ones that have been cancelled, and delete them
		try {
			$qu = $wpdb->get_results('SELECT facebook_id, post_id, start, end FROM wp_ai1ec_events WHERE facebook_id IS NOT NULL AND start >= ' . $now->getTimestamp());
			foreach ($qu as $db_event) {
				try {
				  $res = $fb->get('/' . $db_event->facebook_id . '/');
				  $ge = $res->getGraphNode();
				  if ($db_event->start > $db_event->end) {
					  //echo "<br>" . $db_event->start . " " . $db_event->end;
					try {
						$wpdb->delete( 'wp_ai1ec_events', array('post_id' => $db_event->post_id));
						$wpdb->delete( 'wp_posts', array('id' => $db_event->post_id));
						$wpdb->delete( 'wp_ai1ec_event_instances', array('post_id' => $db_event->post_id));
						//echo " Event deleted: " . $db_event->facebook_id . " start time is greater than end time<br>";
					} catch (Exception $e) {
						//echo "<br>Failed to delete " . $db_event->facebook_id;
					}
				  }
				} catch(Facebook\Exceptions\FacebookResponseException $e) {
				  // When Graph returns an error
				  //echo 'Graph returned an error: ' . $e->getMessage();
				  if ((stripos($e->getMessage(), "Unsupported get request") !== NULL)) {
					try {
						$wpdb->delete( 'wp_ai1ec_events', array('post_id' => $db_event->post_id));
						$wpdb->delete( 'wp_posts', array('id' => $db_event->post_id));
						$wpdb->delete( 'wp_ai1ec_event_instances', array('post_id' => $db_event->post_id));
						//echo "<br>Event deleted due to facebook error: " . $db_event->facebook_id . "<br>";
					} catch (Exception $e) {
						//echo "<br>Failed to delete " . $db_event->facebook_id;
					}
				  }
				  exit;
				} catch(Facebook\Exceptions\FacebookSDKException $e) {
				  // When validation fails or other local issues
				  //echo 'Facebook SDK returned an error: ' . $e->getMessage();
				  exit;
				}
			}
		} catch (Exception $e) {
		}
		
		//Delete all manually declined events
		try {
			$response3 = $fb->get('/YOUR_FACEBOOK_ID/events/declined?fields=id&since= ' . time() . '&limit=1000');
			$ge3 = $response3->getGraphEdge();
			$decevents = $ge3->asArray();
			$query = "SELECT post_id FROM wp_ai1ec_events WHERE ";
			$first = true;
			foreach ($decevents as $dec) {
				if ($first){
					$query .= "facebook_id = '" . $dec["id"] . "'";
					$first = false;
				}
				else
					$query .= " OR facebook_id = '" . $dec["id"] . "'";
			}
			$r = $wpdb->get_results($query);
			foreach ($r as $row) {
				try {
					$wpdb->delete( 'wp_ai1ec_events', array('post_id' => $row->post_id));
					$wpdb->delete( 'wp_posts', array('id' => $row->post_id));
					$wpdb->delete( 'wp_ai1ec_event_instances', array('post_id' => $row->post_id));
					//echo "<br>Event deleted (decline): " . $dec["id"];
				} catch (Exception $e) {
					//echo "<br>Failed to delete " . $dec["id"] . "<br>";
				}
			}
		} catch(Facebook\Exceptions\FacebookResponseException $e) {
		  // When Graph returns an error
		  //echo 'Graph returned an error: ' . $e->getMessage();
		  exit;
		} catch(Facebook\Exceptions\FacebookSDKException $e) {
		  // When validation fails or other local issues
		  //echo 'Facebook SDK returned an error: ' . $e->getMessage();
		  exit;
		}

		
		
	}
}

register_deactivation_hook(__FILE__, 'my_deactivation');

function my_deactivation() {
	wp_clear_scheduled_hook('my_hourly_event');
}


?>