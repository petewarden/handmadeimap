<?php

/**
 * This is a test harness and example code for the handmade IMAP and POP3
 * implementations. You call it passing in the details of the user's email
 * address and password as URL parameters. If you don't give it mail server
 * details it will attempt to guess it from the domain given for the email
 * address. This is obviously simple for @gmail.com, @yahoo.com, etc, but
 * it also will check the mail server DNS records to see if it's a hosted
 * google account like my work pete@mailana.com address, and correctly logs
 * in to Gmail for access.
 *
 * The input parameters are:
 *
 * o user - The email address of the user
 * o password - Password to the account
 * o host - The ip address or domain name of the server the account is on
 * o port - The port to connect to the server on
 * o protocol - What method to connect with, either imaps or pop3s
 * o sendyahoocommand - Whether to use the special IMAP command needed for Yahoo
 * o mailbox - The mailbox folder to operate on
 * o action - The operation to perform:
 *   + list - Show the mailbox folders (not available for POP3)
 *   + totalcount - Display the number of messages in the folder
 *   + fetchheaders - Pulls down header information for the messages specified
 *       in the startindex and endindex arguments, eg 1 & 10 gets the first 10.
 *       The information is printed out in an XML format. The POP3 version uses
 *       the earliesttime argument instead of the startindex/endindex.
 *   + fetchsince - Like fetchheaders, but only pulls messages after the date
 *       argument, where date is of the form 1-Jan-2010 (not POP3)
 *   + indexsince - Prints the message number of the first message after the
 *       date argument (not POP3)
 *
 * Usage examples:
 *
 * curl "http://localhost/handmadeimap/handmadeimaptest.php?user=searchbrowser%40gmail.com&password=yourpasswordhere&action=fetchheaders&startindex=1&endindex=10"
 * curl "http://localhost/handmadeimap/handmadeimaptest.php?user=pete%40mailana.com&password=yourpasswordhere&action=list&debug=true"
 * curl "http://localhost/handmadeimap/handmadeimaptest.php?user=petercwarden%40yahoo.com&password=yourpasswordhere&mailbox=inbox&action=totalcount"
 *
 */

require_once("peteutils.php");
require_once("handmadeimap.php");
require_once("handmadepop3.php");
require_once("maildomainutils.php");

// A helper function to wrap the initial login and error checking for IMAP
function create_imap_connection($mailserver, $port, $sendyahoocommand, $user, $password)
{
	$connection = handmadeimap_open_connection($mailserver, $port);
	if ($connection==null)
		die("Connection failed: ".handmadeimap_get_error()."\n");

	if ($sendyahoocommand)
	{
		handmadeimap_yahoo_command($connection);
		if (!handmadeimap_was_ok())
			die("Yahoo command failed: ".handmadeimap_get_error()."\n");
	}

	handmadeimap_login($connection, $user, $password);
	if (!handmadeimap_was_ok())
		die("LOGIN failed: ".handmadeimap_get_error()."\n");
        
    return $connection;
}

// A helper function to wrap the initial login and error checking for POP3
function create_pop3_connection($mailserver, $port, $user, $password)
{
	$connection = handmadepop3_open_connection($host, $port);
	if ($connection==null)
		die("Connection failed: ".handmadepop3_get_error()."\n");

	handmadepop3_login($connection, $user, $password);
	if (!handmadepop3_was_ok())
		die("LOGIN failed: ".handmadepop3_get_error()."\n");
}

// The URL arguments, with defaults for the optional ones
$user = get_url_input('user');
$password = get_url_input('password');
$host = get_url_input('host', '');
$port = get_url_input('port', '993');
$protocol = get_url_input('protocol', 'imaps');
$sendyahoocommand = (get_url_input('sendyahoocommand', 'false')=='true');
$mailbox = get_url_input('mailbox', '[Gmail]/All Mail');
$action = get_url_input('action');
$dotiming = (get_url_input('dotiming', 'false')=='true');
$debug = (get_url_input('debug', 'false')=='true');

if ($debug)
{
    pete_log_enable('handmadeimap');
    pete_log_enable('accounts');
}

if ($host=='')
{
    $imapinfo = get_imap_info_for_address($user);
    if ($imapinfo==null)
        die("Can't find info for $user\n");

    $host = $imapinfo['host'];
    $port = $imapinfo['port'];
	$protocol = $imapinfo['protocol'];
	$sendyahoocommand = $imapinfo['sendyahoocommand'];
}

pete_start_timer();

if ($protocol=='imaps')
{
	$mailserver = "ssl://$host";

    $connection = create_imap_connection($mailserver, $port, $sendyahoocommand, $user, $password);
		
	if ($action=='list')
	{
		$listresult = handmadeimap_list($connection);
		if (!handmadeimap_was_ok())
			die("LIST failed: ".handmadeimap_get_error()."\n");
		
		exit;
	}
		
	$selectresult = handmadeimap_select($connection, $mailbox);
	if (!handmadeimap_was_ok())
		die("SELECT failed: ".handmadeimap_get_error()."\n");

	if ($action=='totalcount')
	{
		$totalcount = $selectresult['totalcount'];
		echo $totalcount;
	}
	else if ($action=='fetchheaders')
	{
		$startindex = get_url_input('startindex', 1);
		$endindex = get_url_input('endindex', 10);

		$fetchresult = handmadeimap_fetch_envelopes($connection, $startindex, $endindex);
		if (!handmadeimap_was_ok())
			die("FETCH failed: ".handmadeimap_get_error()."\n");
		
		$messagexml = envelopes_to_xml($fetchresult, 'received');

		print '<?xml version="1.0" encoding="ISO-8859-1"?><messagelist>'."\n"; //'
		print $messagexml;
		print "</messagelist>\n";
	}
	else if ($action=='fetchsince')
	{
		$date = get_url_input('date', '1-Jan-2010');

		$searchresult = handmadeimap_search_since_date($connection, $date);
		if (!handmadeimap_was_ok())
			die("SEARCH failed: ".handmadeimap_get_error()."\n");
		
		print_r($searchresult);
	}
	else if ($action=='indexsince')
	{
		$time = get_url_input('time');
		$totalcount = $selectresult['totalcount'];

		$searchresult = handmadeimap_earliest_index_since_time($connection, $time, $totalcount);
		if (!handmadeimap_was_ok())
			die("handmadeimap_earliest_index_since_time() failed: ".handmadeimap_get_error()."\n");
		
		print "$searchresult:$totalcount\n";
	}

	handmadeimap_close_connection($connection);
}
else if ($protocol=='pop3s')
{
    $connection = create_pop3_connection($mailserver, $port, $user, $password);

	if ($action=='list')
	{
		die('Action "list" not supported for POP3');
	}

	if ($action=='totalcount')
	{
		$totalcount = handmadepop3_get_message_count($connection);
		echo $totalcount;
	}
	else if ($action=='fetchheaders')
	{
		$earliesttime = get_url_input('earliesttime');

		$fetchresult = handmadepop3_fetch_message_headers($connection, $earliesttime);
		if (!handmadepop3_was_ok())
			die("FETCH failed: ".handmadepop3_get_error()."\n");
		
		$messagexml = message_headers_to_xml($fetchresult, 'received');

		print '<?xml version="1.0" encoding="ISO-8859-1"?><messagelist>'."\n"; //'
		print $messagexml;
		print "</messagelist>\n";
	}

	handmadepop3_close_connection($connection);
}
else
{
	die("Unknown protocol $protocol");
}

pete_end_timer($dotiming);

?>
