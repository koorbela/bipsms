<?php

/**
 * BIPKampany HTTP API class
 * (c) Bip Communications Kft.
 * All rights reserved
 *
 * @package BipKampany
 * @subpackage API
 * @copyright 2010-2012 Bip Communications Kft. 
 * @link https://www.bipkampany.hu
 */

class BipKampanyAPI {

  /**
   * HTTP call method, possible values: 'file_get_contents' or 'curl'
   * 
   * @access public
   * @var string
   */
  var $callingMethod = 'file_get_contents';

  /**
   * Name of log file. The class creates log entries if this 
   * attribute is not null.
   *
   * @var string
   */
  var $logFilename = false;

  /**
   * This attribute holds the last API response (error code and message).
   * 
   * @access public
   * @var string
   */ 
  var $lastResult;

  /**
   * API URL
   *
   * @access private
   * @var string
   */
  var $url = 'https://api.bipkampany.hu/';

  /**
   * key => value pairs to use as GET parameters while calling the API
   *
   * @access private
   * @var string
   */
  var $params   = array();

  /**
   * BipKampanyAPI constructor
   *
   * @access public
   * @param string $email    your email address
   * @param string $password your password
   * @return void
   */
  function __construct( $email, $password ) {

    $this->params['email']    = $email;
    $this->params['password'] = $password;

  }

  /**
   * Send an SMS. Raises an exception upon errors.
   *
   * @access public
   * @param string $number      phone number in international format 
   * @param string $message     the message in UTF-8 coding (check available chars too!)
   * @param string $senderid    a sender ID (only sender IDs accepted by BIP are allowed)
   * @param string $timetosend  optional: timing of the SMS, YYYY-MM-DD hh:mm:ss (eg. 2015-11-25 12:08:59)
   * @param string $type        optional: message type. Currently only: unicode
   * @param string $callback    optional: callback types separated by a comma or ALL to receive all callback types.
   * @param string $referenceid optional: the identifier that's provided to you during callbacks. Only to be used together with $callback.
   * @return void
   */
 function sendSMS( $number, $message, $senderid, $timetosend = null, $type = null, $callback = null, $referenceid = null ) {

    $params             = $this->params;
    $params['message']  = rawurlencode(
        mb_convert_encoding( $message, 'UTF-8', mb_detect_encoding( $message ) )
    );
    $params['number']   = $number;
    $params['senderid'] = $senderid;

    if ( $timetosend !== null )
        $params['timetosend'] = rawurlencode( $timetosend ); // YYYY-MM-DD HH:MM:SS

    if ( $type !== null )
        $params['type'] = $type;


    if ( $referenceid !== null ) {
      if ( !strlen( $callback ) )
        throw new Exception('BipKampanyAPI: $referenceid is used without specifying the expected callback status(es) in $callback');
      $params['referenceid'] = $referenceid;
    }

    if ( $callback !== null )
      $params['callback'] = $callback;

    $result = $this->call( 'sendsms', $params );
    return $this->parseResult( $result );

  }

  /**
   * Querying the current balance. Raises an exception upon errors.
   *
   * @access public
   * @return array Returns: the API response as an array, including balance and currency holding the balance itself. 
   */
  function getBalance() {

    $result = $this->call( 'getbalance', $this->params );
    return $this->parseResult( $result );

  }

  /**
   * Get available SMS characters.
   *
   * @access public
   * @return string list of supported characters in UTF-8 coding
   */
  function getCharset() {

    $result = $this->call( 'getcharset', $this->params );
    return $this->parseResult( $result );

  }

  /**
   * Get incoming messages
   *
   * @access public
   * @return array The response as an array, including 'messages' holding incoming messages (timestamp desc)
   */
  function getIncomingSMSes( $limit = 10 ) {

    $params = $this->params;

    if ( $limit !== null )
      $params['limit'] = $limit;

    $result = $this->call( 'getincomingsmses', $params );
    return $this->parseResult( $result );

  }

  /**
   * Cancelling a timed SMS submitted earlier.
   *
   * @access public
   * @param string $referenceid the reference ID used when submitting the SMS. To use multiple reference IDs separate them with a comma. When using multiple reference IDs, make sure the URL gets no longer than 65000 bytes.
   * @return array the API response as an array
   */
  function cancelSMS( $referenceids ) {

    $params = $this->params;
    $params['referenceid'] = $referenceids;

    $result = $this->call( 'cancelsms', $params );
    return $this->parseResult( $result );

  }

  /**
   * Returns null, if there was no API response yet (before any API calls). 
   * Otherwise it returns the response as an array.
   * (array indexes: 'result' ('OK' or 'ERR'), 'code' es 'message')
   *
   * @access public
   * @return mixed last API response as an array, or null
   */
  function getLastResult() {

    return $this->lastResult;

  }

  /**
   * Execute API call
   *
   * @access private
   * @param string $params Parameters to be passed to the API
   * @return string Response string
   */
  function call( $action, $params ) {

    // arg_separator.output may be "&amp;", to avoid this we'll
    // set up http_build_query entirely
    $url = $this->url . $action . '?' . http_build_query( $params, '', '&' );

    $this->log( $this->callingMethod . ': ' . $url );

    switch ( $this->callingMethod ) {
      case 'file_get_contents':
        if ( !ini_get('allow_url_fopen') )
          throw new Exception('BipKampanyAPI: - allow_url_fopen must be On to use "file_get_contents" callingMethod. You may use curl callingMethod if you have CURL extension installed.');
        $result = file_get_contents( $url );
        break;
      case 'curl':
        if ( !extension_loaded('curl') )
          throw new Exception('BipKampanyAPI: CURL extension must be installed to use "curl" callingMethod.');
        $result = $this->callCURL( $url );
        break;
      default:
        throw new Exception('BipKampanyAPI: unimplemented callingMethod: "' . $this->callingMethod . '"');
        break;
    }

    if ( $result === false )
      throw new Exception( 'BipKampanyAPI: file_get_contents("' . $url . '") failed' );

    return $result;
      
  }

  /**
   * Calling the API using CURL
   *
   * @access private
   * @param string $params Parameters to be passed to the API
   * @return string Response string
   */
  function callCURL( $url ) {

    $curl = curl_init();

    curl_setopt_array( $curl, array(
      CURLOPT_URL               => $url,
      // CURLOPT_CONNECTTIMEOUT_MS => 2000,
      // CURLOPT_TIMEOUT           => 10,
      CURLOPT_FAILONERROR       => true,
      CURLOPT_RETURNTRANSFER    => true
    ) );

    $html     = curl_exec( $curl );
    $httpcode = curl_getinfo( $curl, CURLINFO_HTTP_CODE );

    if ( $html === false ) {

      throw new Exception(
        'BipKampanyAPI: CURL error, HTTP error code: ' . $httpcode . ', ' . curl_error( $curl )
      );

    } 
    else
      return $html;

  }
  
  /**
   * Parse the response of the API
   *
   * @access private
   * @param string $result API response string
   * @return array Response as an array
   */
  function parseResult( $result ) {

    parse_str( $result, $resultparts );
    $this->lastResult = $resultparts;
    $this->log( var_export( $resultparts, true ) );

    switch ( @$resultparts['result'] ) {

      case 'OK':
        // SMS submitted successfully
        return $resultparts;
        break;

      case 'ERR':
        // error during SMS submit
        throw new BipKampanyAPIException(
          $resultparts['message'],
          $resultparts['code']
        );
        break;

      default:
        throw new Exception(
          'BipKampanyAPI: unimplemented result '.
          '"' . @$resultparts['code'] . '", ' .
          '"' . @$resultparts['message'] . '", '.
          'raw result: "' . $result . '"'
        );
        break;

    }

  }

  /**
   * Log entries into logfile if needed.
   * No logging takes place unless $this->filename is specified.
   *
   * @access private
   * @param string $result String to log
   * @return void
   */
  function log( $string ) {

    if ( $this->logFilename ) {

      $f = fopen( $this->logFilename, 'a' );

      if ( !$f )
        throw new Exception('BipKampanyAPI: fopen( "' . $this->logFilename . '" ) failed' );

      fputs( $f, date("Y-m-d H:i:s") . ' - ' . $string . "\n" );
      fclose( $f );

    }

  }

}

/**
 * BIP API Exception
 */
class BipKampanyAPIException extends Exception {

  public function __construct( $message, $code ) {
    $this->message = $message;
    $this->code    = $code;
    parent::__construct( $message, $code );
  }

  public function __toString() {
    return __CLASS__ . ": BIP_API_ERROR #{$this->code}, {$this->message}\n";
  }

}

?>