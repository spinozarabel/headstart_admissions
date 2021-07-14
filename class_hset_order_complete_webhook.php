<?php
/* Modified by Madhu Avasarala 06/28/2021
* ver 1.8 cleaned up code a little
* ver 1.7 added change active status of VPA
* ver 1.6 added params to getcurl
* ver 1.5 added prod_cosnt as variable and not a constant
* ver 1.4 make the site settings generic instead of hset, etc.
* ver 1.3 add Moodle and WP compatibility and get settings appropriately
*         all data returned as objects instead of arrays in json_decode
*/

// if directly called with both unset, die. If any one is set then proceed internally
if (!defined( "ABSPATH" ) )
    {
    	die( 'No script kiddies please!' );
    }

// class definition begins
class class_hset_order_complete_webhook
{
    protected $token;
    protected $baseUrl;
    protected $clientId;
    protected $clientSecret;
	protected $siteName;
    protected $config;

    //const TEST_PRODUCTION  = "TEST";
    const VERBOSE          = false;

    public function __construct()
    {
        $this->verbose      = self::VERBOSE;

        $this->get_config_wp();

        $wckey  = $this->config['wckey'];
        $wcsec  = $this->config['wcsec'];

        // add these as properties of object
		$this->wc_webhook_secret    = $this->config['wc_webhook_secret'];

        // sets timezone object to IST
		//$this->timezone =  new DateTimeZone(self::TIMEZONE);

    }       // end construct function

    /**
     * Process a Cashfree Webhook. We exit in the following cases:
     * - Check that the IP is whitelisted
     * . Extract the signature and verify /**
     * . Once IP is in whitelist and signature is verified process the webhook
     * . only event 'amount_colected' is processed
     */
    public function process()
    {
        if ( $_SERVER['REMOTE_ADDR']              == '68.183.189.119' &&
             $_SERVER['HTTP_X_WC_WEBHOOK_SOURCE'] == 'https://sritoni.org/hset-payments/'     
           )
        {
            // passes all conditions, process further
            error_log("Webhook passes all source restrictions");

            $this->signature = $_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'];

            $request_body = file_get_contents('php://input');

            error_log(print_r($request_body,true));

            $signature_verified = $this->verify_webhook_signature($request_body);

            error_log("signature verified True or False: " . $signature_verified);
        }
        else
        {
            die;
        }

        $data = file_get_contents('php://input');

    }

    private function verify_webhook_signature($request_body)
    {
        $signature    = $this->signature;
        $secret       = $this->wc_webhook_secret;

        return (base64_encode(hash_hmac('sha256', $request_body, $secret, true)) == $signature);
    }

    // function to read in the configuration file for WP case
    private function get_config_wp()
    {
      $this->config = include( __DIR__."/headstart_admission_config.php");
    }

	
}       // class definition ends
