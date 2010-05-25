<?php

require_once ('./config.php');
require_once ('../peteutils.php');
require_once ('./oauth/gmailoauth.php');
require_once ('../handmadeimap.php');
require_once ('../maildomainutils.php');

function gmail_login($emailaddress, $accesstoken, $accesstokensecret)
{
    $to = new GmailOAuth(
        GOOGLE_API_KEY_PUBLIC, 
        GOOGLE_API_KEY_PRIVATE,
        $accesstoken,
        $accesstokensecret
    );
    
    $loginstring = $to->getLoginString($emailaddress);

    $imapinfo = get_imap_info_for_address($emailaddress);
    if ($imapinfo==null)
        die("Can't find info for $emailaddress\n");

    $host = $imapinfo['host'];
    $mailserver = 'ssl://'.$host;
    $port = $imapinfo['port'];
    $protocol = $imapinfo['protocol'];
    $mailbox = '[Gmail]/All Mail';

    $connection = handmadeimap_open_connection($mailserver, $port);
    if ($connection==null)
        die("Connection failed: ".handmadeimap_get_error()."\n");

    handmadeimap_capability($connection);
    if (!handmadeimap_was_ok())
        die("CAPABILITY failed: ".handmadeimap_get_error()."\n");

    handmadeimap_login_xoauth($connection, $loginstring);
    if (!handmadeimap_was_ok())
        die("LOGIN failed: ".handmadeimap_get_error()."\n");

    return $connection;
}

function fetch_senders_and_recipients($connection, $mailbox, $count)
{
    $selectresult = handmadeimap_select($connection, $mailbox);
    if (!handmadeimap_was_ok())
        die("SELECT failed: ".handmadeimap_get_error()."\n");

    $totalcount = $selectresult['totalcount'];

    $startindex = ($totalcount-$count);
    $endindex = $totalcount;
    
    $fetchresult = handmadeimap_fetch_envelopes($connection, $startindex, $endindex);
    if (!handmadeimap_was_ok())
        die("FETCH failed: ".handmadeimap_get_error()."\n");
    
    $addresslist = array(
        'from' => array(),
        'to' => array(),
        'cc' => array(),
        'bcc' => array(),
    );
    $addresstodisplay = array();
    foreach ($fetchresult as $envelope)
    {
        $from = $envelope['from'];
        $fromcomponents = $from[0];
        $fromaddress = $fromcomponents['address'];
        $fromdisplay = $fromcomponents['display'];
        
        $addresstodisplay[$fromaddress] = $fromdisplay;
        $addresslist['from'][] = $fromaddress;
        
        foreach ($envelope['to'] as $tocomponents)
        {
            $toaddress = $tocomponents['address'];
            $todisplay = $tocomponents['display'];            

            $addresstodisplay[$toaddress] = $todisplay;
            $addresslist['to'][] = $toaddress;
        }

        foreach ($envelope['cc'] as $cccomponents)
        {
            $ccaddress = $cccomponents['address'];
            $ccdisplay = $cccomponents['display'];            

            $addresstodisplay[$ccaddress] = $ccdisplay;
            $addresslist['cc'][] = $ccaddress;
        }

        foreach ($envelope['bcc'] as $bcccomponents)
        {
            $bccaddress = $bcccomponents['address'];
            $bccdisplay = $bcccomponents['display'];            

            $addresstodisplay[$bccaddress] = $bccdisplay;
            $addresslist['bcc'][] = $bccaddress;
        }
    }
    
    $addresscounts = array(
        'from' => array_count_values($addresslist['from']),
        'to' => array_count_values($addresslist['to']),
        'cc' => array_count_values($addresslist['cc']),
        'bcc' => array_count_values($addresslist['bcc']),
    );
    
    $result = array();
    foreach ($addresscounts as $role => $countmap)
    {
        $result[$role] = array();
        foreach ($countmap as $address => $count)
        {
            $result[$role][$address] = array(
                'count' => $count,
                'display' => $addresstodisplay[$address],
            );
        }
    }
        
    return $result;
}

function suppress_date_warning()
{
    if (function_exists("date_default_timezone_set") && function_exists("date_default_timezone_get"))
        date_default_timezone_set(@date_default_timezone_get());
}

?>