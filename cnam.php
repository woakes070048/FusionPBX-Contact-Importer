
<?php
/*
	GNU Public License
	Version: GPL 3
*/
require_once "root.php";
require_once "resources/require.php";
require_once __DIR__."/utils.php";

$settings = array();
$settings['authorized_hosts'] = array("127.0.0.1");

if(file_exists("./settings.php")) {
	require_once "./settings.php";
}

$external_lookup_sources = array();

function do_external_lookup($number) {
	foreach($external_lookup_sources as $provider=>$lookup_function) {
		$result = $lookup_function($number);
		if(is_array($result)) {
			$result['provider'] = $provider;
			return $result;
		}
	}
}

function do_lookup($number, $domain, $call_uuid=NULL) {
	global $db, $settings;
	$number = ltrim($number, '+');
	if(substr($number, 0, 1) != "1") {
		$number = "1".$number;
	}
	// First check if we already know about them
	$result = do_sql($db, "SELECT v_contacts.contact_name_given, v_contacts.contact_name_family FROM v_contacts, v_contact_phones WHERE v_contact_phones.contact_uuid = v_contacts.contact_uuid AND v_contact_phones.phone_number = :number LIMIT 1", array(':number' => $number));
	if(count($result) > 0) {
		echo $result[0]['contact_name_given']." ".$result[0]['contact_name_family'];
	} else {
		$cnam = do_external_lookup($number);
		if(!is_null($domain) && $domain != "_undef_") {
			try {
				$contact_uuid = uuid();
				do_sql($db, "INSERT INTO v_contacts(contact_uuid, domain_uuid, contact_name_given, contact_name_family) VALUES (:contact_uuid, :domain_uuid, :contact_name_given, :contact_name_family)", array(
					':contact_uuid' => $contact_uuid,
					':domain_uuid' => $domain,
					':contact_name_given' => $fname,
					':contact_name_family' => $lname
				));
				do_sql($db, "INSERT INTO v_contact_phones (contact_phone_uuid, domain_uuid, contact_uuid, phone_primary, phone_number) VALUES (:contact_phone_uuid, :domain_uuid, :contact_uuid, 1, :phone_numer)", array(
					':contact_uuid' => $contact_uuid,
					':contact_phone_uuid' => uuid(),
					':domain_uuid' => $domain,
					':phone_numer' => $number
				));
			} catch(Exception $e) {
				error_log("Failed to insert $fname $lname into the database: ".$e->getMessage());
			}
		}
		echo $fname." ".$lname;
	}
}

if(in_array($_SERVER['REMOTE_ADDR'], $settings['authorized_hosts'])) {
	if(isset($_REQUEST['number'])) {
		do_lookup($_REQUEST['number'], $settings['default_domain']);
	} elseif(isset($_REQUEST['call'])) {
		$fp = event_socket_create($_SESSION['event_socket_ip_address'], $_SESSION['event_socket_port'], $_SESSION['event_socket_password']);
		if (!$fp) {
			die("ERROR");
		}
		$number = trim(event_socket_request($fp, "api uuid_getvar ".$_REQUEST['call']." caller_id_number"));
		$domain = trim(event_socket_request($fp, "api uuid_getvar ".$_REQUEST['call']." domain_uuid"));
		if(isset($_GET['outbound'])) {
			$number = trim(event_socket_request($fp, "api uuid_getvar ".$_REQUEST['call']." callee_id_number"));
		}
		if(substr($number, 0, 4) == "-ERR") {
			error_log("Error from freeswitch when getting caller_id_number (".$number.") for call ".$_REQUEST['call']);
			die("UNKNOWN");
		} if(substr($domain, 0, 4) == "-ERR") {
			error_log("Error getting domain for call ".$_REQUEST['call']);
			if(isset($settings['default_domain'])) {
				$domain = $settings['default_domain'];
			} else {
				$domain = NULL;
				error_log("No default domain! We wont store this result :(");
			}
		}
		do_lookup($number, $domain, $_REQUEST['call']);
	}
} else {
	echo "UNAUTHORIZED";
}
