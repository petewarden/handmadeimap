<?php
/** 
 * This class implements a subset of the IMAP protocol, using PHP's socket
 * interface rather than relying on an extension like php-imap. Why would
 * you want to do such a thing?
 *
 * o This supports pulling down all 'to' recipients when grabbing just headers
 * o You can use it to log into ordinary Yahoo accounts, using their mangled
 *   version of IMAP:
 *   http://groups.google.com/group/mozilla.dev.apps.thunderbird/browse_thread/thread/546356554c73f8ca
 * o It's a lot more hackable to support extensions like oAuth for Gmail:
 *   http://sites.google.com/site/oauthgoog/Home/oauthimap
 *
 * Unfortunately it doesn't implement the complete IMAP protocol, only the
 * pieces I've needed for my projects. This includes logging in, getting
 * lists of mailboxes, messages since a certain date and complete header
 * information, but not downloading actual message bodies.
 *
 * To extend it yourself, check out the RFC spec at:
 * http://www.faqs.org/rfcs/rfc3501.html
 * The basic process is sending a text command using
 * handmade_send_command() and getting the result back from
 * handmadeimap_get_command_result().
 *
 * It uses globals for error checking and parsing, so interleaving commands for 
 * different connections may cause problems.
 *
 * By Pete Warden <pete@petewarden.com> - March 8th 2010
 *
 */

require_once("peteutils.php");

$g_currentidcounter = 0;
$g_resultlines = array();
$g_infolines = array();

function handmadeimap_send_command($connection, $command)
{
    global $g_currentidcounter;
    $commandid = "A".$g_currentidcounter;
    $g_currentidcounter += 1;
    
    $commandline = "$commandid $command\r\n";    
    fwrite($connection, $commandline);
    
    pete_log('handmadeimap', "Sent '$commandline'");
    
    return $commandid;
}

function handmadeimap_get_command_result($connection, $commandid)
{
    global $g_resultlines;
    global $g_infolines;

    $g_infolines = array();

    $insidequotes = false;
    $insideliteral = false;
    $chewingliteral = false;
    $literallength = 0;

    $outputline = '';
    $previouschar = '';

    while (!feof($connection))
    {
        if (isset($g_resultlines[$commandid]))
        {
            return $g_resultlines[$commandid];
        }
            
        while (!feof($connection)) {
            $outputchar = fgetc($connection);
            if ($outputchar===FALSE)
                break;
        
            if ($chewingliteral)
            {
                $literallength -= 1;
                
                if ($outputchar=='"')
                    $outputline .= '\\';
                
                if ($literallength<1)
                {
                    $chewingliteral = false;
                    $outputline .= $outputchar;
                    $outputline .= '"';
                }
                else if (($outputchar!=chr(10))&&($outputchar!=chr(13)))
                {
                    $outputline .= $outputchar;
                }
            }
            else
            {
                if ($outputchar=='"')
                {
                    $insidequotes = !$insidequotes;
                    $outputline .= $outputchar;
                }
                else if (!$insidequotes && ($outputchar=='{'))
                {
                    $insideliteral = true;
                    $literallength = 0;
                }
                else if ($insideliteral && ctype_digit($outputchar))
                {
                    $literallength *= 10;
                    $literallength += $outputchar;                    
                }
                else if ($insideliteral && ($outputchar=='}'))
                {
                    $literallength += 2;
                    $chewingliteral = true;
                    $outputline .= '"';
                }
                else if (($outputchar==chr(10))&&($previouschar==chr(13)))
                {            
                    $outputline = trim($outputline);

                    pete_log('handmadeimap', "Received '$outputline'");
                    
                    $spacearray = explode(" ", $outputline);
                    $resultid = $spacearray[0];
                    
                    if ($resultid=='*')
                    {
                        $g_infolines[] = $outputline;
                    }
                    else
                    {
                        $g_resultlines[$resultid] = array( 
                            'resultline' => $outputline,
                            'infolines' => $g_infolines,
                        );
                    }
                    
                    $outputline = '';
                    $insidequotes = false;
                    $insideliteral = false;
                    $chewingliteral = false;
                    $literallength = 0;
                }
                else
                {
                    $outputline .= $outputchar;
                }
            }
            
            $previouschar = $outputchar;
        }
        
        time_nanosleep(0, 500000);
    }

    return null;
}

function handmadeimap_was_command_ok($commandresult)
{
    if (!isset($commandresult))
        return false;
        
    if ($commandresult=='')
        return false;
        
    $commandparts = split(' ', $commandresult);
    if (count($commandparts)<2)
        return false;
        
    return ($commandparts[1]=='OK');
}

$g_handmadeimap_error = null;
function handmadeimap_set_error($error)
{
    global $g_handmadeimap_error;
    $g_handmadeimap_error = $error;
}

function handmadeimap_get_error()
{
    global $g_handmadeimap_error;
    return $g_handmadeimap_error;    
}

function handmadeimap_was_ok()
{
    return (handmadeimap_get_error()==null);
}

function handmadeimap_open_connection($serverurl, $serverport)
{
    $fp = fsockopen($serverurl, $serverport, $errno, $errstr, 30);
    if ($fp==null)
    {
        handmadeimap_set_error($errstr);
        return null;
    }
    
    stream_set_blocking($fp,0);
    
    return $fp;
}

function handmadeimap_close_connection($connection)
{
    fclose($connection);
}

function handmadeimap_yahoo_command($connection)
{
	$yahoocommandid = handmadeimap_send_command($connection, 'ID ("GUID" "1")');
	$yahoocommandresult = handmadeimap_get_command_result($connection, $yahoocommandid);
	$yahoocommandwasok = handmadeimap_was_command_ok($yahoocommandresult['resultline']);
	if (!$yahoocommandwasok)
		handmadeimap_set_error("Yahoo Command failed with '".$yahoocommandresult['resultline']."'");
}

function handmadeimap_login($connection, $user, $password)
{
    $loginid = handmadeimap_send_command($connection, "LOGIN $user $password");
    $loginresult = handmadeimap_get_command_result($connection, $loginid);
    $loginwasok = handmadeimap_was_command_ok($loginresult['resultline']);
    if (!$loginwasok)
        handmadeimap_set_error("LOGIN failed with '".$loginresult['resultline']."'");
}

function handmadeimap_list($connection)
{
    $listid = handmadeimap_send_command($connection, 'LIST "" "*"');
    $listresult = handmadeimap_get_command_result($connection, $listid);
    $listwasok = handmadeimap_was_command_ok($listresult['resultline']);
    if (!$listwasok)
        handmadeimap_set_error("LIST failed with '".$listresult['resultline']."'");

    $result = array();
    foreach ($listresult['infolines'] as $infoline)
    {
        print "$infoline\n";
    }
    
    return $result;
}

function handmadeimap_select($connection, $mailboxname)
{
    $selectid = handmadeimap_send_command($connection, 'SELECT "'.$mailboxname.'"');
    $selectresult = handmadeimap_get_command_result($connection, $selectid);
    $selectwasok = handmadeimap_was_command_ok($selectresult['resultline']);
    if (!$selectwasok)
        handmadeimap_set_error("SELECT failed with '".$selectresult['resultline']."'");
    else
        handmadeimap_set_error(null);
        
    $result = array();
    foreach ($selectresult['infolines'] as $infoline)
    {
        $infoparts = split(' ', $infoline);
                
        if (isset($infoparts[2]))
        {
            if ($infoparts[1]=='FLAGS')
            {
                // do nothing
            }
            else if ($infoparts[2]=='EXISTS')
            {
                $result['totalcount'] = $infoparts[1];
            }
            else if ($infoparts[2]=='RECENT')
            {
                $result['recentcount'] = $infoparts[1];            
            }
        }
        
    }
    
    return $result;
}

function handmadeimap_search_since_date($connection, $date)
{
    $searchid = handmadeimap_send_command($connection, 'SEARCH SINCE '.$date);
    $searchresult = handmadeimap_get_command_result($connection, $searchid);
    $searchwasok = handmadeimap_was_command_ok($searchresult['resultline']);
    if (!$searchwasok)
        handmadeimap_set_error("SEARCH failed with '".$searchresult['resultline']."'");
    else
        handmadeimap_set_error(null);

    $result = array();
    foreach ($searchresult['infolines'] as $infoline)
    {
        $parts = split(' ', $infoline);
        // Remove "* SEARCH"
        unset($parts[0]);
        unset($parts[1]);
    
        $result = array_merge($result, $parts);
    }
    
    return $result;
}

function handmadeimap_earliest_index_since_time($connection, $unixtime, $totalcount)
{
    // The IMAP search ignores the time zone, so bump the time backwards by a day to avoid missing any
    $conservativetime = ($unixtime-24*60*60);
	$date = date( 'd-M-Y', $conservativetime );	
    
    $messageindices = handmadeimap_search_since_date($connection, $date);
    $minindex = $totalcount;
    foreach ($messageindices as $index)
        $minindex = min($minindex, $index);
        
    return $minindex;
}

function pull_string($line)
{
    $result = '';
    $countline = strlen($line);
    $index = 0;
    while ($index<$countline)
    {
        $char = $line{$index};
        if (ctype_space($char))
            return $result;
    
        $result .= $char;
        $index += 1;
    }
    
    return $result;
}

function pull_quoted($line)
{
    $result = '';
    $countline = strlen($line);
    $index = 1;
    $lastchar = '';
    $charbeforelast = '';
    while ($index<$countline)
    {
        $char = $line{$index};
        if (($char=='"')&&($lastchar!='\\'))
            return $result;
    
        $result .= $char;
        $index += 1;
        
        $lastchar = $char;
    }
    
    echo "Missing end quotes for $line";
    
    return $result;
}

function pull_parens($line)
{
    $result = '';
    $parensdepth = 0;
    $isinquotes = false;
    $countline = strlen($line);
    $index = 1;
    $lastchar='';
    while ($index<$countline)
    {
        $char = $line{$index};
        if ($isinquotes)
        {
            if (($char=='"')&&($lastchar!='\\'))
            {
                $isinquotes = false;
            }
        }
        else
        {
            if (($char=='"')&&($lastchar!='\\'))
            {
                $isinquotes = true;
            }
            else if ($char=='(')
            {
                $parensdepth += 1;
            }
            else if ($char==')')
            {
                $parensdepth -= 1;
                if ($parensdepth<0)
                    return $result;
            }
        }
    
        $result .= $char;
        $index += 1;
        
        $lastchar = $char;
    }
    
    global $g_handmadeimap_currentline;
    echo "Missing end parens for $g_handmadeimap_currentline";
    
    return $result;
}

function string_to_expression_tree($line)
{
    $result = array();

    $countline = strlen($line);
    $index = 0;
    while ($index<$countline)
    {
        $char = $line[$index];
        
        if ($char=='"')
        {
            $currentchunk = pull_quoted(substr($line, $index));
            $result[] = $currentchunk;
            $index += strlen($currentchunk)+2;
        }
        else if ($char=='(')
        {
            $currentchunk = pull_parens(substr($line, $index));
            $result[] = string_to_expression_tree($currentchunk);
            $index += strlen($currentchunk)+2;
        }
        else if (ctype_space($char))
        {
            $index += 1;
        }
        else
        {
            $currentchunk = pull_string(substr($line, $index));
            $result[] = $currentchunk;
            $index += strlen($currentchunk);        
        }
    }
    
    return $result;
}

function get_addresses_from_tree($tree)
{
    if (is_string($tree)&&($tree=="NIL"))
        return array();
    
    if (!is_array($tree))
    {
        error_log("Not an array: $tree");
        global $g_handmadeimap_currentline;
        error_log($g_handmadeimap_currentline);
    }
    
    $result = array();
    foreach ($tree as $current)
    {
        $currentdisplay = $current[0];
        $currentusername = $current[2];
        $currentdomain = $current[3];
        
        if ($currentdisplay=="NIL")
            $currentdisplay = "";
            
        $currentaddress = $currentusername."@".$currentdomain;
        
        $result[] = array(
            'display' => $currentdisplay,
            'address' => $currentaddress,
        );
    }
    
    return $result;
}

$g_handmadeimap_currentline = '';

function handmadeimap_parse_envelope_line($infoline)
{
    global $g_handmadeimap_currentline;
    $g_handmadeimap_currentline = $infoline;
    $infotree = string_to_expression_tree($infoline);

    $messagenumber = $infotree[1];
    
    $envelopetree = $infotree[3][1];
    
    $date = $envelopetree[0];
    $subject = $envelopetree[1];
    
    $fromtree = $envelopetree[2];
    $fromlist = get_addresses_from_tree($fromtree);

    $sendertree = $envelopetree[3];
    $senderlist = get_addresses_from_tree($sendertree);

    $replytotree = $envelopetree[4];
    $replytolist = get_addresses_from_tree($replytotree);

    $totree = $envelopetree[5];
    $tolist = get_addresses_from_tree($totree);

    $cctree = $envelopetree[6];
    $cclist = get_addresses_from_tree($cctree);
    
    $bcctree = $envelopetree[7];
    $bcclist = get_addresses_from_tree($bcctree);
    
    $inreplyto = $envelopetree[8];
    $messageuid = $envelopetree[9];
    
    return array(
        'messagenumber' => $messagenumber,
        'date' => $date,
        'subject' => $subject,
        'from' => $fromlist,
        'sender' => $senderlist,
        'replyto' => $replytolist,
        'to' => $tolist,
        'cc' => $cclist,
        'bcc' => $bcclist,
        'inreplyto' => $inreplyto,
        'messageuid' => $messageuid,
    );

}

function handmadeimap_fetch_envelopes($connection, $firstindex, $lastindex)
{
    $fetchcommand = "FETCH $firstindex:$lastindex (ENVELOPE)";
    $fetchid = handmadeimap_send_command($connection, $fetchcommand);
    $fetchresult = handmadeimap_get_command_result($connection, $fetchid);
    $fetchwasok = handmadeimap_was_command_ok($fetchresult['resultline']);
    if (!$fetchwasok)
        handmadeimap_set_error("FETCH failed with '".$fetchresult['resultline']."'");
    else
        handmadeimap_set_error(null);
    
    $result = array();
    foreach ($fetchresult['infolines'] as $infoline)
        $result[] = handmadeimap_parse_envelope_line($infoline);
    
    return $result;
}

function create_envelope_recipients_xml($recipients, $role)
{
	$messagexml = "";
	
	if (!$recipients)
		return $messagexml;
	
	foreach ($recipients as $object) {
		$display = $object['display'];
		$address = $object['address'];
		$messagexml .= "<recipient>\n";
		$messagexml .= "<address>".$address."</address>\n";
		$messagexml .= "<display>".make_cdata($display)."</display>\n";
		$messagexml .= "<role>".$role."</role>\n";
		$messagexml .= "</recipient>\n";
	}

	return $messagexml;
}

function envelopes_to_xml($envelopes, $sentorreceived)
{
    $messagexml = '';
    foreach ($envelopes as $headerinfo)
    {
        $subject = $headerinfo["subject"];

        $imapdate = $headerinfo["date"];
        $phpdate = strtotime($imapdate);
        $date = date( 'Y-m-d H:i:s', $phpdate );

        $from = $headerinfo["from"];
        $fromcomponents = $from[0];
        $fromaddress = $fromcomponents["address"];
        $fromdisplay = $fromcomponents["display"];

        $sourcefolder = $sentorreceived;

        $to = $headerinfo["to"];
        $cc = $headerinfo["cc"];
        $bcc = $headerinfo["bcc"];

        $contenttext = "";
        $contenthtml = "";

        $sourceuid = $headerinfo['messageuid'];

        $messagexml .= "<message>\n";
        $messagexml .= make_tag("messageuid", uniqid("",TRUE));
        $messagexml .= make_cdatatag("sourceuid", $sourceuid);
        $messagexml .= make_cdatatag("subject", $subject);
        
        $messagexml .= make_cdatatag("fromaddress", $fromaddress);
        $messagexml .= make_cdatatag("fromdisplay", $fromdisplay);
        
        $messagexml .= make_tag("deliverytime", $date);

        $messagexml .= "<recipients>\n";

		$messagexml .= create_envelope_recipients_xml($to,"to");
		$messagexml .= create_envelope_recipients_xml($cc,"cc");
		$messagexml .= create_envelope_recipients_xml($bcc,"bcc");
     
        $messagexml .= "</recipients>\n";

        $messagexml .= make_cdatatag("contenttext", $contenttext);
        $messagexml .= make_cdatatag("contenthtml", $contenthtml);
            
        $messagexml .= make_tag("sourcefolder", $sourcefolder);
            
        $messagexml .= "</message>\n";

    }
    
    return $messagexml;
}

function handmadeimap_test_envelope_parsing()
{
    $testline = '* 123 FETCH (ENVELOPE ("Fri, 05 Jun 2009 06:39:46 -0400 (EDT)" "ViewSonic 24\" 1080p LCD $229.99, Flip Video Mino 60M Camcorder $89.99, Plantronics Bluetooth Headset $16.99,.." (("Buy.com Deals" NIL "buy.com_offers" "enews.buy.com")) (("Buy.com Deals" NIL "buy.com_offers" "enews.buy.com")) ((NIL NIL "Buycom-s7ou4ipo6sh" "checkout.l.google.com")) ((NIL NIL "Christophe-b7ou4ipo6sh" "checkout.l.google.com")) NIL NIL NIL "<22389195.945191244198463993.JavaMail.sierra-prod@cgl35.prod.google.com>"))';
    
    $testresult = handmadeimap_parse_envelope_line($testline);
    
    print_r($testresult);
}

?>
