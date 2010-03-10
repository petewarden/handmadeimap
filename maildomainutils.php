<?php

// Takes an email address and tries to extract the domain identifier for the organization. It mostly means taking everything
// to the right of the @, but I also try to ignore any host more than one step below the top for known TLDs, so that
// 'user@mail.somecompany.com' becomes 'somecompany.com' for example
function get_canonical_domain_from_email_address($emailaddress)
{
	$emailaddress = strtolower($emailaddress);
	
	$emailparts = explode('@', $emailaddress);
	if (count($emailparts)!=2)
		return 'Unknown';
	
	$domain = $emailparts[1];
	$domainparts = explode('.', $domain);

	$domainparts = array_reverse($domainparts);
	
	$tld = $domainparts[0];
	
	if (($tld=='com')||($tld=='net')||($tld=='org')||($tld=='edu'))
	{
		$result = $domainparts[1].'.'.$tld;
	}
	else
	{
		$result = $domain;
	}

	return $result;
}

$g_domainimapinfo = array(
	'gmail.com' => array( 
        'host' => 'imap.gmail.com', 
        'port' => 993, 
        'protocol' => 'imaps', 
        'mainbox' => '[Gmail]/All Mail', 
        'sentbox' => '[Gmail]/Sent Mail',
        'dontindexsent' => true,
		'sendyahoocommand' => false,
    ),
	'fastmail.fm' => array( 
        'host' => 'mail.messagingengine.com', 
        'port' => 993, 
        'protocol' => 'imaps', 
        'mainbox' => 'INBOX.Deleted Messages', 
        'sentbox' => 'INBOX.Sent Messages',
        'dontindexsent' => false,
		'sendyahoocommand' => false,
    ),
	'mac.com' => array( 
        'host' => 'mail.mac.com', 
        'port' => 993, 
        'protocol' => 'imaps', 
        'mainbox' => 'INBOX', 
        'sentbox' => 'Sent Messages',
        'dontindexsent' => false,
		'sendyahoocommand' => false,
    ),
	'yahoo.com' => array( 
        'host' => 'imap.next.mail.yahoo.com', 
        'port' => 993, 
        'protocol' => 'imaps', 
        'mainbox' => 'Inbox', 
        'sentbox' => 'Sent',
        'dontindexsent' => false,
		'sendyahoocommand' => true,
    ),
	'hotmail.com' => array( 
        'host' => 'pop3.live.com', 
        'port' => 995, 
        'protocol' => 'pop3s', 
        'mainbox' => 'Inbox', 
        'sentbox' => 'Sent',
        'dontindexsent' => false,
		'sendyahoocommand' => true,
    ),
);

$g_domainaliases = array(
    'google.com' => 'gmail.com',
    'psmtp.com' => 'gmail.com',
    'me.com' => 'mac.com',
    'messagingengine.com' => 'fastmail.fm',
	'live.com' => 'hotmail.com',
);

function get_mailers_for_domain($domain)
{
    $mailers = array();
    if(getmxrr($domain, $mxhosts, $mxweight)) 
    {    
        for($i=0;$i<count($mxhosts);$i++)
        {
            $mxs[strtolower($mxhosts[$i])] = $mxweight[$i];
        }
        asort($mxs);
        $mailers = array_keys($mxs);
    } 
    elseif(checkdnsrr($domain, 'A')) 
    {
        $mailers[] = gethostbyname($domain);
    }

    pete_log('accounts', 'Found MX hosts: '.print_r($mailers, true));
    
    return $mailers;
}

function get_imap_domain_from_domain($domain)
{
    global $g_domainimapinfo;
    global $g_domainaliases;

	if (isset($g_domainimapinfo[$domain]))
		return $domain;

    $mailers = get_mailers_for_domain($domain);
    foreach ($mailers as $mxdomain)
    {
        $reversemxdomain = strrev($mxdomain);
        foreach ($g_domainaliases as $candidatealias => $actualdomain)
        {
            $reversealias = strrev($candidatealias);
            if (strncmp($reversemxdomain, $reversealias, strlen($reversealias))==0)
            {
                pete_log('accounts', 'Found alias '.$actualdomain.' for '.$mxdomain);
                $mxdomain = $actualdomain;
                break;
            }
            else
            {
                pete_log('accounts', 'Alias '.$actualdomain.' doesn\'t match '.$mxdomain);            
            }
        }

        $reversemxdomain = strrev($mxdomain);

        foreach ($g_domainimapinfo as $domainkey => $info)
        {
            $reversekey = strrev($domainkey);
            if (strncmp($reversemxdomain, $reversekey, strlen($reversekey))==0)
            {
                pete_log('accounts', 'Found host '.$domainkey.' for '.$domain);
                return $domainkey;
            }
            else
            {
                pete_log('accounts', 'Host '.$domainkey.' doesn\'t match '.$domain);
            }
        }
    }
    
    return null;
}

function get_imap_info_for_address($address)
{
    global $g_domainimapinfo;

    $domain = get_canonical_domain_from_email_address($address);
    $imapdomain = get_imap_domain_from_domain($domain);
    
    if ($imapdomain==null)
    {
        pete_log('accounts', 'No host found for '.$address);
        return null;
    }

    pete_log('accounts', 'Host '.$imapdomain.' found for '.$address);
        
    $result = $g_domainimapinfo[$imapdomain];
    
    $result['username'] = $address;
    
    return $result;
}

function get_service_name_for_address($address)
{
	$parts = explode('@', $address);
	if (!isset($parts[1]))
		return 'email';
		
	$domain = $parts[1];
	if (strpos($domain, '.'))
		$result = 'email';
	else
		$result = $domain;
		
	return $result;
}

function get_local_name_for_address($address)
{
	$parts = explode('@', $address);
	return $parts[0];
}

?>