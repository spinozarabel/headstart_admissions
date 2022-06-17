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



// wait for all plugins to be loaded before initializing our code
add_action('plugins_loaded', 'init_headstart_admission');

function  action_after_login($user_login, $user)
{
  //  if user email is a headstart one then get that student's user data from SriTooni and savee
  // data as JSOn in user meta. Check if user meta exists, if it does do not get the data gaian. Just once 1st time login.

}

/**
 *  Instantiate the main class that the plugin uses
 *  Setup webhook to be cauught when Order is COmpleted on the WooCommerce site.
 *  Setup wp-cron schedule and eveent for hourly checking to see if SriToni Moodle Accounts have been created
 *  Setup wp-cron schedule and event for hourly checking to see if user has replied to ticket with payment UTR
 */
function init_headstart_admission()
{
  add_action('init','custom_login');

  // instantiate the class for head start admission
  $admission       = new class_headstart_admission();

  // setup process to catch webhook sent from WooCommerce when any order has been marked complete.
  add_action('admin_post_nopriv_hset_admission_order_complete_webhook', [$admission, 'webhook_order_complete_process'], 10);

  // setup an hourly wp-cron-task to check if accounts exist on SriToni, especially for new users to SriToni
  add_action ( 'check_if_accounts_created_task_hook',                   [$admission, 'check_if_accounts_created'], 10 );
  if (!wp_next_scheduled('check_if_accounts_created_task_hook')) 
  {
      wp_schedule_event( time(), 'hourly', 'check_if_accounts_created_task_hook' );
  }
  
  // setup an hourly wp-cron-task to check eligible tickets if transaction utr number has been input in reply after payment
  add_action ( 'check_if_payment_utr_input_task_hook',                   [$admission, 'check_if_payment_utr_input'], 10 );
  if (!wp_next_scheduled('check_if_payment_utr_input_task_hook')) 
  {
      wp_schedule_event( time(), 'hourly', 'check_if_payment_utr_input_task_hook' );
  }

  // add_action( 'wp_login', [$admission, 'action_after_login'], 10,2 );
}

/**
 *  This function gets called by an action on 'init'<
 *  It checks to see if the user is NOT looged in and is on the login page. 
 *  If so it redirects the user to the home page so that the user can get started cleanly
 */
function custom_login()
{
  global $pagenow;
  if( 'wp-login.php' == $pagenow && !is_user_logged_in()) 
  {
   wp_redirect('https://headstartschools.in/');
   exit();
  }
 }
