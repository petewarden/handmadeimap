<?php
/**
 * This file contains the API keys needed to access the Twitter via oAuth
 *
 * Before you can use this example, you'll need to replace the two values below with your own
 * keys. To do this, go to https://www.google.com/accounts/ManageDomains and register.
 * Then, copy the value under the heading 'Consumer key' into GOOGLE_API_KEY_PUBLIC and the
 * value from 'Consumer secret' into GOOGLE_API_KEY_PRIVATE. 
 *
 */

define ('GOOGLE_API_KEY_PUBLIC', '');
define ('GOOGLE_API_KEY_PRIVATE', '');

if (GOOGLE_API_KEY_PUBLIC === '')
    die('You need to edit config.php to add your own API keys before you can use this example');

?>
