<?php
/*
 * A class handling the IMAP verification procedure for Gmail
 *
 * Based on the Twitter version by Abraham Williams (abraham@abrah.am) http://abrah.am
 *
 */

/* Load OAuth lib. You can find it at http://oauth.net */
require_once('oauth.php');

class GmailOAuth {
    // Contains the last HTTP status code returned
    private $http_status;

    // Contains the last API call
    private $last_api_call;

    // The base of the Gmail OAuth URLs
    public static $GMAIL_API_PREFIX = 'https://mail.google.com/mail/b/';
    public static $GMAIL_API_SUFFIX = '/imap/';
    
    public $GMAIL_API_ROOT = 'https://www.google.com/accounts/';

    public $request_options = array(
        'scope' => 'https://mail.google.com/',
    );

    /**
    * Set API URLS
    */
    function requestTokenURL() { return $this->GMAIL_API_ROOT.'OAuthGetRequestToken'; }
    function authorizeURL() { return $this->GMAIL_API_ROOT.'OAuthAuthorizeToken'; }
    function accessTokenURL() { return $this->GMAIL_API_ROOT.'OAuthGetAccessToken'; }

    /**
    * Debug helpers
    */
    function lastStatusCode() { return $this->http_status; }
    function lastAPICall() { return $this->last_api_call; }

    function __construct($consumer_key, $consumer_secret, $oauth_token = NULL, $oauth_token_secret = NULL) {/*{{{*/
        $this->sha1_method = new OAuthSignatureMethod_HMAC_SHA1();
        $this->consumer = new OAuthConsumer($consumer_key, $consumer_secret);
        if (!empty($oauth_token) && !empty($oauth_token_secret)) {
            $this->token = new OAuthConsumer($oauth_token, $oauth_token_secret);
        } else {
            $this->token = NULL;
        }
    }/*}}}*/


    /**
    * Get a request_token from Gmail
    *
    * @returns a key/value array containing oauth_token and oauth_token_secret
    */
    function getRequestToken() {/*{{{*/
        $requesturl = $this->requestTokenURL();
        $r = $this->oAuthRequest($requesturl, $this->request_options, 'GET');
        error_log('OAuth request: '.$requesturl);
        error_log('OAuth Response: '.print_r($r, true));
        $token = $this->oAuthParseResponse($r);
        $this->token = new OAuthConsumer($token['oauth_token'], $token['oauth_token_secret']);
        return $token;
    }/*}}}*/

    /**
    * Parse a URL-encoded OAuth response
    *
    * @return a key/value array
    */
    function oAuthParseResponse($responseString) {
        $r = array();
        foreach (explode('&', $responseString) as $param) {
            $pair = explode('=', $param, 2);
            if (count($pair) != 2) continue;
            $r[urldecode($pair[0])] = urldecode($pair[1]);
        }
        return $r;
    }

    /**
    * Get the authorize URL
    *
    * @returns a string
    */
    function getAuthorizeURL($token, $callbackurl) {/*{{{*/
        if (is_array($token)) $token = $token['oauth_token'];
        $result = $this->authorizeURL();
        $result .= '?oauth_token=' . $token;
        $result .= '&oauth_callback=' . urlencode($callbackurl);
        
        return $result;
    }/*}}}*/

    /**
    * Exchange the request token and secret for an access token and
    * secret, to sign API calls.
    *
    * @returns array("oauth_token" => the access token,
    *                "oauth_token_secret" => the access secret)
    */
    function getAccessToken($token = NULL) {/*{{{*/
        $r = $this->oAuthRequest($this->accessTokenURL());
        $token = $this->oAuthParseResponse($r);
        $this->token = new OAuthConsumer($token['oauth_token'], $token['oauth_token_secret']);
        return $token;
    }/*}}}*/

    /**
    * Format and sign an OAuth / API request
    */
    function oAuthRequest($url, $args = array(), $method = NULL) {/*{{{*/
        if (empty($method)) $method = empty($args) ? "GET" : "POST";
        $req = OAuthRequest::from_consumer_and_token($this->consumer, $this->token, $method, $url, $args);
        $req->sign_request($this->sha1_method, $this->consumer, $this->token);
        switch ($method) {
            case 'GET': return $this->http($req->to_url());
            case 'POST': return $this->http($req->get_normalized_http_url(), $req->to_postdata());
        }
    }/*}}}*/

    /**
    * Get the base64 string to pass in to the IMAP authentication method
    */
    function getLoginString($email) {/*{{{*/
        $method = 'GET';
        $url = 'https://mail.google.com/mail/b/'.$email.'/imap/';
        $args = array();
        $req = OAuthRequest::from_consumer_and_token($this->consumer, $this->token, $method, $url, $args);
        $req->sign_request($this->sha1_method, $this->consumer, $this->token);

        $requesturl = $req->to_url();
        
        $requestparts = explode('?', $requesturl);
        $oauthparamslist = explode('&', $requestparts[1]);
        $oauthparamsstring = implode(',', $oauthparamslist);
        
        $clientrequest = 'GET '.$url.' '.$oauthparamsstring;
        
        $result = base64_encode($clientrequest);
        
        return $result;
    }/*}}}*/

    /**
    * Make an HTTP request
    *
    * @return API results
    */
    function http($url, $post_data = null) {/*{{{*/
        $ch = curl_init();
        if (defined("CURL_CA_BUNDLE_PATH")) curl_setopt($ch, CURLOPT_CAINFO, CURL_CA_BUNDLE_PATH);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
        //////////////////////////////////////////////////
        ///// Set to 1 to verify Twitter's SSL Cert //////
        //////////////////////////////////////////////////
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        if (isset($post_data)) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        }
        $response = curl_exec($ch);
        $this->http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->last_api_call = $url;
        curl_close ($ch);
        return $response;
    }/*}}}*/
}/*}}}*/