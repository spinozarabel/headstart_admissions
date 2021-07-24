<?php
/**
*Plugin Name: Head Start Admission
*Plugin URI:
*Description: Head Start Admissions Plugin - Takes care of Registration, forms, SriToni account, Cashfree Account and payment using Woocommerce REST API
*Version: 2021070500
*Author: Madhu Avasarala
*Author URI: http://sritoni.org
*Text Domain: headstart_admission
*Domain Path:
*/
// no direct access allowed
defined( 'ABSPATH' ) or die( 'No direct access allowed' );

define( 'HEADSTART_ADMISSION_VERSION', '1.0.0' );

require_once(__DIR__."/MoodleRest.php");   				           // Moodle REST API driver for PHP
// file containing class for settings submenu and page
require_once(__DIR__."/class_headstart_admission_settings.php");

// this is tile containing the class definition for virtual accounts e-commerce
require_once(__DIR__."/class_headstart_admission.php");

require_once(__DIR__."/cfAutoCollect.inc.php");         // contains cashfree api class

require_once(__DIR__."/class_hset_order_complete_webhook.php");         // contains cashfree api class

// setup for the WooCommerce REST API
require __DIR__ . '/vendor/autoload.php';

use Automattic\WooCommerce\Client;
use Automattic\WooCommerce\HttpClient\HttpClientException;

if ( is_admin() )
{
  // This is to be done only once!!!!
  $admission_settings = new class_headstart_admission_settings();
}



// wait for all plugins to be loaded before initializing the new VABACS gateway
add_action('plugins_loaded', 'init_headstart_admission');

function  action_after_login($user_login, $user)
{
  //  if user email is a headstart one then get that student's user data from SriTooni and savee
  // data as JSOn in user meta. Check if user meta exists, if it does do not get the data gaian. Just once 1st time login.

}

function init_headstart_admission()
{
  // instantiate the class for head start admission
  $admission       = new class_headstart_admission();

  add_action('admin_post_nopriv_hset_admission_order_complete_webhook', 
                                    [$admission, 'webhook_order_complete_process'], 10);

  // add_action( 'wp_login', [$admission, 'action_after_login'], 10,2 );
}

/*
function webhook_init()
{
  global $wpscfunction;

  $hset_order_complete_webhook = new class_hset_order_complete_webhook();

  // get the id of theorder that was completed  
  $order_id = $hset_order_complete_webhook->process();

  $order = $hset_order_complete_webhook->get_order($order_id);

  $ticket_id = get_post_meta($order->id, 'admission_number', true);

  // change the ticket status to payment process completed
  $status_id =  136; // admission-payment-process-completed
  $wpscfunction->change_status($ticket_id, $status_id_order_completed);

}
*/

