<?php

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
    'handmadeimap' => false,
    'accounts' => false,
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

?>