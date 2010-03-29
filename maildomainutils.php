<?php

/** 
 * This module implements helper functions for dealing with mail server addresses:
 *
 * o Extracting a mail server domain from a full email address
 * o Guessing the ISP and so the IMAP/POP settings needed for a given address
 * o Looking at a domain's DNS records to tell if it's actually hosted by Google
 *
 * It currently only automatically recognizes the settings for Gmail, Yahoo, Hotmail
 * and Fastmail accounts. It would be great to get more ISPs listed, if you do add
 * your own please let me know and I'll make sure the master copy is updated. To add
 * them edit $g_domaininfo
 
 Licensed under the 2-clause (ie no advertising requirement) BSD license,
 making it easy to reuse for commercial or GPL projects:
 
 (c) Pete Warden <pete@petewarden.com> http://petewarden.typepad.com/ - Mar 11th 2010
 
 Redistribution and use in source and binary forms, with or without modification, are
 permitted provided that the following conditions are met:

   1. Redistributions of source code must retain the above copyright notice, this 
      list of conditions and the following disclaimer.
   2. Redistributions in binary form must reproduce the above copyright notice, this 
      list of conditions and the following disclaimer in the documentation and/or 
      other materials provided with the distribution.
   3. The name of the author may not be used to endorse or promote products derived 
      from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE AUTHOR ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY
DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, 
BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR 
PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, 
WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) 
ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY
OF SUCH DAMAGE.
 
 */

// Update this array to have other ISPs be automatically recognized from their address
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

// This array contains synonyms that actually point at known domains
$g_domainaliases = array(
    'google.com' => 'gmail.com',
    'psmtp.com' => 'gmail.com',
    'me.com' => 'mac.com',
    'messagingengine.com' => 'fastmail.fm',
	'live.com' => 'hotmail.com',
);

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

function extract_address_from_string($full)
{
	$matchcount = preg_match_all("/(.*)<[^\._a-zA-Z0-9-]*([\._a-zA-Z0-9-]+@[\._a-zA-Z0-9-]+).*>/i", $full, $matches);
	if ($matchcount)
	{
		$address = $matches[2][0];
		$display = $matches[1][0];
	}
	else
	{
		$matchcount = preg_match_all("/[\._a-zA-Z0-9-#+]+@[\._a-zA-Z0-9-]+/i", $full, $matches);
		if ($matchcount)
		{
			$address = $matches[0][0];
			$display = $address;
		}
		else
		{
			$matchcount = preg_match_all("/([a-z]).* ([a-z]+)/i", $full, $matches);
			if ($matchcount)
			{
				$address = strtolower($matches[1][0].$matches[2][0])."@local";
				$display = $full;
			}
			else
			{
				$address = $full;
				$display = $full;
			}
		}
	}
	
	return array( "address" => $address, "display" => $display);
}

?>