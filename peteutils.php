<?php
/** 
 * This is a fairly arbitrary collection of helper functions pulled from my production
 * code that were needed by other files in this project. 

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

function get_url_input($inputname, $default=null)
{
	if (isset($_POST[$inputname]))
		return $_POST[$inputname];

	if (isset($_GET[$inputname]))
		return $_GET[$inputname];

	if (isset($_COOKIE[$inputname]))
		return $_COOKIE[$inputname];
	
	return $default;
}

$g_start_time = 0;
$g_end_time = 0;

function pete_start_timer()
{
	global $g_start_time;
	list($usec, $sec) = explode(' ',microtime());
	$g_start_time = ((float)$usec + (float)$sec);
}

function pete_end_timer($dolog=true)
{
	global $g_start_time;
	global $g_end_time;
	list($usec, $sec) = explode(' ',microtime());
	$g_end_time = ((float)$usec + (float)$sec);
	$duration = ($g_end_time - $g_start_time);

	if ($dolog)
	{
		$durationstring = 'pete_timer: %01.4f sec';
		error_log(sprintf($durationstring, $duration));
	}
	
	return $duration;
}

$log_enabled_modules = array(
    'handmadeimap' => true,
    'accounts' => false,
    'oauth' => false,
);

$log_print = false;

function pete_log_enable($module)
{
	global $log_enabled_modules;
    $log_enabled_modules[$module] = true;
}

function pete_log_disable($module)
{
	global $log_enabled_modules;
    unset($log_enabled_modules[$module]);
}

function pete_log($module, $message)
{
	global $log_enabled_modules;
	if (array_key_exists($module, $log_enabled_modules) && 
		$log_enabled_modules[$module])
    {
        error_log($module.': '.$message);
    }
        
    global $log_print;
    if ($log_print)
        print htmlspecialchars("$module: $message")."<br/>";
}

function pete_remove_illegal_characters($input)
{
	return iconv("ISO-8859-1", "ISO-8859-1//IGNORE", $input);
}

function make_tag($tagname, $text)
{
	return "<".$tagname.">".$text."</".$tagname.">";
}

function make_cdata($text)
{
	return htmlspecialchars(pete_remove_illegal_characters($text));
}

function make_cdatatag($tagname, $text)
{
	return make_tag($tagname, make_cdata($text));
}

// Returns the current page's full URL. From http://blog.taragana.com/index.php/archive/how-to-find-the-full-url-of-the-page-in-php-in-a-platform-independent-and-configuration-independent-way/
function get_current_url()
{
	$result = 'http';
	$script_name = "";
	if(isset($_SERVER['REQUEST_URI'])) 
	{
		$script_name = $_SERVER['REQUEST_URI'];
	} 
	else 
	{
		$script_name = $_SERVER['PHP_SELF'];
		if($_SERVER['QUERY_STRING']>' ') 
		{
			$script_name .=  '?'.$_SERVER['QUERY_STRING'];
		}
	}
	
	if(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']=='on') 
	{
		$result .=  's';
	}
	$result .=  '://';
	
	if($_SERVER['SERVER_PORT']!='80')  
	{
		$result .= $_SERVER['HTTP_HOST'].':'.$_SERVER['SERVER_PORT'].$script_name;
	} 
	else 
	{
		$result .=  $_SERVER['HTTP_HOST'].$script_name;
	}

	return $result;
}

?>