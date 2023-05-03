<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 */

/**
 * The core plugin class.
 *  1. The ticket (non-defualt) custom field names MUST exactly match the admin label from the Ninja forms
 *  2. The ticket (non-default) custom field names are used to derive the custom slugs for the CF as SLugs are not user settable.
 *  3. The status names in the code must exactly match the names in the status settings of Support Candy plugin
 *  4. The Category names used on the code must exactly match the names of the categories in the Support Candy plugin.
 *  5. The fees and cohorts etc., in the settings must be keyed correctly to category names, not slugs.
 *
 *  Statusses used: 'Admission Payment Process Completed' , 'Interaction Completed' , 'School Accounts Being Created'
 *                  'Admission Payment Order Being Created', 'Admission Granted', 'Admission Confirmed'
 *                  
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @author     Madhu Avasarala
 */

 if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

//setup for the WooCommerce REST API
require MA_HSA_ABSPATH . 'vendor/autoload.php';

use Automattic\WooCommerce\Client;
use Automattic\WooCommerce\HttpClient\HttpClientException;

class headstart_admission
{
    // keeps the secret information for APIs etc.
    public static $config;

    // keeps the fees indexed by category name
    public static $category_fee_arr;

    // keeps the payment decsription keyed by category name
    public static $category_paymentdescription_arr;

    // keeps the cohortid that new students should be put into keyed by category name
    public static $category_cohortid_arr;

    // dictates the logging
    public static $verbose;

    // WP user object obtained by WooCommerce API from hset-payments intranet site
    public static $wp_user_hset_payments; // WooCommerce user object as returned by Woocommerce API

    // This is an array that is indexed by Status Name having value as status object
    public static $status_name_slug_array;

    // same thing indexed by status id with value as status object
     public static $status_id_name_array;

    // name of plugin
    public static $plugin_name;


    /**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 */
	public function __construct()
    {
        self::init();
	}

    public static function init()
    {
        // set the logging
        self::$verbose = true;
        
        self::$plugin_name = MA_HSA_PLUGIN_NAME;

        // load admin actions only if admin
		if ( is_admin() ) 
        {
            self::define_admin_hooks();
        }

        // load public facing actions
		self::define_public_hooks();

        // read the config file and build the secrets array
        self::get_config();

        // read the fee and description pairs from settings and form an associative array
        self::admission_settings();

        // Index ths status name vs slug and id vs name arrays
        // self::index_status_array();
    }

    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     */
    public static function index_status_array()
    {
        // get an array of all statuses
        $status_objects = WPSC_Status::find( array( 'items_per_page' => 0 ) )['results'];

        $status_name_slug_array = [];
        $status_id_name_array = [];

        foreach ($status_objects as $status_object):
            
            // for each status object add a row indexed by status name
            $status_name_slug_array[$status_object->name] = $status_object->slug;

            $status_id_name_array[$status_object->id]     = $status_object->name;

        endforeach;

        self::$status_name_slug_array = $status_name_slug_array;
        self::$status_id_name_array   = $status_id_name_array;
    }




    /**
     * VISUALLY CHECKED for SC 3.0 compatibility
     * 
     * This function is sually called once, from the class constructor
     * Get settings values for fee and description based on category
     * This array will be used to look up fee and payment description agent fields, based on category
     */
    public static function admission_settings() : void
    {
        $setting_category_fee = get_option('headstart_admission_settings')['category_fee'];

        // each key=>value pair is on a separate line. So by exploding on EOL we get a numerical array with key=>value pairs
        $array1 = explode(PHP_EOL, $setting_category_fee); 

        // array_map takes array1 and makes it into an array of arrays by exploding => separator.
        // finally use the array_column to index the column 1 of each subarray indexed by column 0
        $category_fee_arr = array_column(array_map(function($row){
                                                                    return explode("=>", $row);
                                                                }, $array1), 1, 0);
        self::$category_fee_arr = $category_fee_arr;

        self::$verbose ? error_log( print_r( $category_fee_arr, true ) ) : false;

        $setting_category_paymentdescription = get_option('headstart_admission_settings')['category_paymentdescription'];

        $array2 = explode(PHP_EOL, $setting_category_paymentdescription); 

        $category_paymentdescription_arr = array_column(array_map(function($row){
                                                                                    return explode("=>", $row);
                                                                                }, $array2), 1, 0);

        self::$category_paymentdescription_arr = $category_paymentdescription_arr;

        self::$verbose ? error_log( print_r( $category_paymentdescription_arr, true ) ) : false;

        $setting_category_cohortid = get_option('headstart_admission_settings')['category_cohortid'];

        // array is an array each entry corresponding to a line of text: category=>cohortid
        $array3 = explode(PHP_EOL, $setting_category_cohortid); 

        // array_map takes array3 and makes it into an array of arrays by exploding => separator.
        // finally use the array_column to index the column 1 of each subarray indexed by column 0
        $category_cohortid_arr = array_column(array_map(function($row){
                                                                        return explode("=>", $row);
                                                                    }, $array3), 1, 0);

        self::$category_cohortid_arr = $category_cohortid_arr;

        self::$verbose ? error_log( print_r( $category_cohortid_arr, true ) ) : false;
    }


    /**
     * VISUALLY CHECKED for SC 3.0 compatibility
     * 
     * @return bool
     * This processes the webhook coming from site hset-payments on any order that is completed
     * It extracts the order ID and then gets the order from the hset-payments site.
     * From the order the ticket_id is extracted and it's status is updated.
     * The server originating the webhook IP and domain are checked in addition to the webhook signature
     */
    public static function webhook_order_complete_process() : bool
    {
        if ( $_SERVER['REMOTE_ADDR']              == '68.183.189.119' &&
             $_SERVER['HTTP_X_WC_WEBHOOK_SOURCE'] == 'https://sritoni.org/hset-payments/'     
           )
        {
            // if you get here it means that origin IP and domain have been verified

            $signature = $_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'];

            $request_body = file_get_contents('php://input');

            $signature_verified = self::verify_webhook_signature($request_body, $signature);

            if ($signature_verified)
            {
                self::$verbose ? error_log("HSET order completed webhook signature verified") : false;

                // decoded as object
                $data = json_decode($request_body, false);  

                if ($data->action = "woocommerce_order_status_completed")
                {
                    $order_id = $data->arg;

                    self::$verbose ? error_log($data->action . " for Order: " . $order_id) : false;

                    // so we now have the order id for the completed order. Fetch the order object!
                    $order = self::get_wc_order($order_id);

                    // from the order, extract the admission number which is our ticket id
                    $ticket_id = $order->admission_number;

                    $new_status_name = 'Admission Payment Process Completed';

                    // change the status of the ticket to 'Admission Payment Process Completed'
                    self::change_ticket_status($ticket_id, $new_status_name);

                    // extract the transaction ID from the order
                    $transaction_id = str_replace (['{', '}'], ['', ''], $order->transaction_id);
 
                    // update the agent only fields payment-bank-reference which is really thee transaction_id
                    self::change_ticket_field($ticket_id, 'payment-bank-reference', $transaction_id);

                    // return after successful termination of webhook
                    return true;
                }
            }
            else 
            {
                self::$verbose ? error_log("HSET order completed webhook signature NOT verified") : false;
                die;
            }
        }
        else
        {
            self::$verbose ? error_log("HSET order completed webhook source NOT verified") : false;
            self::$verbose ? error_log($_SERVER['REMOTE_ADDR'] . " " . $_SERVER['HTTP_X_WC_WEBHOOK_SOURCE']) : false;

            die;
        }
    }

    /**
     * 
     */
    public static function verify_webhook_signature( $request_body, $signature ): bool
    {
        $secret = self::$config['wc_webhook_secret'];

        // true if verified, false if not verified
        $is_signature_verified = base64_encode(hash_hmac('sha256', $request_body, $secret, true)) == $signature;

        return $is_signature_verified;
    }

    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     *  reads in a config php file and gets the API secrets. The file has to be in gitignore and protected
     *  The information is read into an associative arrray automatically by the nature of the process
     *  1. Key and Secret of Payment Gateway involved needed to ccheck/create VA and read payments
     *  2. Moodle token to access Moodle Webservices
     *  3. Woocommerce Key and Secret for Woocommerce API on payment server
     *  4. Webhook secret for order completed, from payment server
     */
    public static  function get_config()
    {
      $config = include( MA_HSA_ABSPATH  . MA_HSA_PLUGIN_NAME . "_" . "config.php" );

      if ($config)
      {
        self::$verbose ? error_log("Plugin Config file successfully loaded") : false;
      }
      else
      {
        self::$verbose ? error_log("Could NOT load Plugin Config File correctley, check...") : false;
      }

      self::$config = $config;
    }



    /**
     * Define all of the admin facing hooks and filters required for this plugin
     * @return null
     */
    private  static function define_admin_hooks()
    {   // create a sub menu called Admissions in the Users menu
        add_action( 'admin_menu', array(__CLASS__, 'add_my_menu' ) );
    }

    /**
     * VISUALLY CHECKED for SC 3.0 compatibility
     * Define all of the public facing hooks and filters required for this plugin
     * @return null
     */
    private static function define_public_hooks() : void
    {
        // do_action( 'wpsc_change_ticket_status', self::$ticket, $prev, $new, $customer_id ); in class individual ticket SCandy
        // This is the main state control for this App
        add_action( 'wpsc_change_ticket_status',   array( __CLASS__, 'my_wpsc_change_ticket_status_action_callback' ), 10,4 );

        // check Ninja form data before it is saved
        // add_filter( 'ninja_forms_submit_data',      [$this, 'action_validate_ninja_form_data'] );

        // after a NInja form submission, its data is mapped to a support ticket
        // This is the principal source of data for subsequent actions such as account creation
        add_action( 'ninja_forms_after_submission', array( __CLASS__, 'map_ninja_form_to_ticket' ) );
    }

    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     *  @param int:$ticket_id
     *  @param string:$cf_name
     *  @param mixed:$value is the value to be assigned to the field whose name is passed in for the given ticket id.
     */
    public static function change_ticket_field( int $ticket_id, string $cf_name, mixed $value): void
    {
        // build the ticket  using pased id parameter
        $ticket = new WPSC_Ticket( $ticket_id );

        // get the slug of the custom field using the custom field name of ticket passed in as parameter
        $cf_slug = self::get_cf_slug_by_cf_name( $cf_name );

        if ( $cf_slug )
        {
            // if the slug of the ticket's custom field is valid then set the new value for the ticket field
            $ticket->$cf_slug = $value;

            // set the update date and time
            $ticket->date_updated = new DateTime();

            // save the modified ticket
            $ticket->save();
        }
        else {
            // slug was not found for the given ticket custom field name. Log the issue and return
            self::$verbose ? error_log( "CF Slug non-existent for Ticket:" . $ticket_id . " for CF Name->" . $cf_name): false;
        }
    }


    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     *  Build the ticket using supplied ID and extract the status ID and customer ID information.
     *  Use the supplied new status name to extract its ID and change status
     * 
     *  @param int:$ticket_id
     *  @param string:$new_status_name is the name of the desired status that ticket needs to be changed to
     */
    public static function change_ticket_status( int $ticket_id, string $new_status_name ) : void
    {

    $desired_status_id = self::get_status_id_given_name( $new_status_name );

        $ticket = new WPSC_Ticket( $ticket_id );

        if ( ! $ticket->id ) {

            self::$verbose ? error_log( "Could NOT get id for Ticket:" . $ticket_id ): false;

            return;
        }

        WPSC_Individual_Ticket::$ticket = $ticket;

        // only change status if new status id is valid AND not already same as existing status of ticket
        if ( $desired_status_id && $ticket->status->id != $desired_status_id ) {
            //                                   { prev status ID, new statusID, customer_id)}
            // includes the hook for 'wpsc_change_ticket_status'
            WPSC_Individual_Ticket::change_status( $ticket->status->id, $desired_status_id, $ticket->customer );
        }
    }



    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  Given a keyword, looks through the array and returns the index for a partial match. Returns false if no match found
     *  @param array:$arr is the array to be serached
     *  @param string:$keyword is the key to be serached for even a partial match
     *  @return integer:$index the index of the array whose value matches at least partially with the given keyword
     */
    public static function array_search_partial($arr, $keyword) 
    {   // Given a keyword, looks through the array and returns the index for a partial match. Returns false if no match found
        foreach($arr as $index => $string) 
        {
            if (stripos($string, $keyword) !== FALSE)
                return $index;
        }
        return false;
    }


    

    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  @param string:$cf_name is the exact name of the custom field including case and spaces, whose id we desire
     *  @return int$cf_id is the id of the desired custom field that is returned If not found null is returned
     * 
     *  Given the name of an existing category, its integer ID is returned if it exists. If not, null is returned
     */
    public static function get_cf_id_given_name(string  $cf_name): ? int
    {
        $cf_id = null;    // initialize the return object

        // get an array of all statuses
        $cf_objects = WPSC_Custom_Field::find( array( 'items_per_page' => 0 ) )['results'];

        foreach ($cf_objects as $cf_object):
        
            if ( $cf_name == $cf_object->name)
            {
                self::$verbose ? ("Custom Field name - " . $cf_name . " Corresponds to ID: " . $cf_object->id) : false;

                $cf_id = $cf_object->id;    // capture the CF ID from object
                
                break;                                  // we found our match and so we exit the loop
            } 
        endforeach;

        return $cf_id;
    }


    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  @param string:$status_name is the exact name of the status whose id we desire
     *  @return int$status_id is the id of the desired status that is returned If not found null is returned
     * 
     *  Given the name of an existing category, its integer ID is returned if it exists. If not, null is returned
     */
    public static function get_status_id_given_name(string  $status_name): ? int
    {
        $status_id = null;    // initialize the return variable

        // get an array of all statuses
        $status_objects = WPSC_Status::find( array( 'items_per_page' => 0 ) )['results'];

        foreach ($status_objects as $status_object):
        
            if ( $status_name == $status_object->name)
            {
                self::$verbose ? ("Status name - " . $status_name . " Corresponds to Status ID: " . $status_object->id) : false;

                $status_id = $status_object->id;    // capture the Status ID from Status object
                
                break;                              // we found our match and so we exit the loop
            } 
        endforeach;

        return $status_id;
    }



    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  @param string:$category_name is the exact name of the category whose id we desire
     *  @return int$category_id is the id of the desired categoy that is returned If not found null is returned
     * 
     *  Given the name of an existing category, its integer ID is returned if it exists. If not, null is returned
     */
    public static function get_category_id_given_name( string  $category_name ): ? int
    {
        $category_id = null;    // initialize the return object

        // get an array of all statuses
        $category_objects = WPSC_Category::find( array( 'items_per_page' => 0 ) )['results'];

        foreach ($category_objects as $category_object):
        
            if ( $category_name == $category_object->name)
            {
                self::$verbose ? ("Category name - " . $category_name . " Corresponds to Category ID: " . $category_object->id) : false;

                $category_id = $category_object->id;    // capture the category ID from object
                
                break;                                  // we found our match and so we exit the loop
            } 
        endforeach;

        return $category_id;
    }



    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  @return int:$ticket_id The ID of the ticket that was created based on the form submission.
     *  @param array:$form_data from the Ninja forms based on the  action below:
     * 
     *  add_action( 'ninja_forms_after_submission', array( __CLASS__, 'map_ninja_form_to_ticket' ) );
     *  The function takes the Ninja form data immdediately after submission
     *  The form data is captured into the fields of a new ticket that is to be created as a result of this submission.
     *  The agent-only fields are not updated by the form and will be null.
     *  The Admin needs to set these fields which are mostly for new users.
     *  The data is not modified in anyway except for residential-address where character "/" is replaced by "-"
     */
    public static function map_ninja_form_to_ticket( array $form_data ): ?int
    {
        // $form_data['fields']['id']['seetings']['admin_label']
        // $form_data['fields']['id'][''value']

        // get the current logged in user
        // $current_user = wp_get_current_user();

        // get customer's mail
        // $registered_email   = $current_user->user_email;

        // Initialize the new ticket values array needed for a new ticket creation
        $data = [];

        // extract the fields array from the Ninja form data
        $fields_ninjaforms = $form_data['fields'];

        // extract a single column from all fields containing the admin_label key from the Ninja form data
        $admin_label_array_ninjaforms = array_column(array_column($fields_ninjaforms, 'settings'), 'admin_label');

        // extract the corresponding value array. They both will share the same  numerical index.
        $value_array_ninjaforms       = array_column(array_column($fields_ninjaforms, 'settings'), 'value');

        // Get the logged in user's ID
        $current_user = wp_get_current_user();

        // Check to see if already a customer in Support Candy table
        $customer = WPSC_Customer::get_by_email( $current_user->user_email  );

        

        // Loop through all the custom fields of the ticket
        foreach ( WPSC_Custom_Field::$custom_fields as $cf ):

            // If the CF's field property is not ticket or agentonly, skip. TODO: Skip agentonly also????
            if ( ! in_array( $cf->field, array( 'ticket', 'agentonly' ) )  ) {
                continue;
            }

            // if the CF has default value set then get the default value into the ticket data array
            if ( method_exists( $cf->type, 'get_default_value' ) ) {
                $data[ $cf->slug ] = $cf->type::get_default_value( $cf );
            }

            // we have nothing to do with the ticket field priority so skip
            if ($cf->slug == 'priority') {  
                continue;     
            }

            // For each of the ticket CF we look at the matching form field using the admin label column
            // for non-default ticket CFs we match using CF name not CF slug since we cannot set the CF slug for these
            switch (true):

                // This is the admin label for applicant's name usually a parent
                case ($cf->name == 'customer_name'):

                    // look for array index in the ninja forms field's admin label for this
                    $key = array_search('customer_name', $admin_label_array_ninjaforms);

                    if ($key !== false)
                    {
                        // get the form-captured field value for customer-name
                        $customer_name = $value_array_ninjaforms[$key];

                        // We want to format it with 1st letter capital and rest in lowercase. Split the name using space
                        $customer_name_arr = explode(" ", $customer_name);

                        $name = "";
                        foreach ($customer_name_arr as $partname)
                        {
                            // Each subname such as First, Middle, and Last will have 1st letter Capitalized
                            $name .= " " . ucfirst(strtolower($partname));
                        }

                        // remove extraneous spaces at beginning and or end and index in data array by the CF slug always
                        // The slug will have some strange characters and so we will just use as is.
                        $data[$cf->slug]= trim($name);
                    }
                    else
                    {
                        self::$verbose ? error_log($cf->name . " -> index not found in Ninja forms value array") : false;  
                    }
                break;

                // ticket's custom field 'category is a default predefined field and slug can be used here
                // Thhere should be a hidden field in the Ninja forms with values corresponding to Support Candy Settings
                case ($cf->slug == 'category'):

                    // look for the mapping slug in the ninja forms field's admin label
                    $key                = array_search('ticket_category', $admin_label_array_ninjaforms);

                    // extract the ticket category usually a hidden field in the form
                    $category_name      = $value_array_ninjaforms[$key];

                    // now to get the category id using the name we got from the ninja form
                    $category_id        = self::get_category_id_given_name( $category_name) ;

                    // we give the category's id, not its name, when we create the ticket.
                    $data[$cf->slug]    = $category_id;

                break;

                // customer email in ticket maps to the user registered email.
                // This is done so that all communication is on the registered email.
                // This is not derived from the Ninja form but from the user object itself
                // This was a legacy feature since email was not included as part of ticket in ver1 of support candy
                // But we are keeing the feature for legacy and continuity
                case ($cf->name == 'customer_email'):

                    // look for the mapping slug in the ninja forms field's admin label
                    // $key = array_search('primary-email', $admin_label_array_ninjaforms);

                    // capture this into a local variable for later use to extract customer information
                    // $customer_registered_email = $value_array_ninjaforms[$key];

                    $data[$cf->slug] = $customer->email;

                break;


                // map the ticket field 'headstart-email' to Ninja forms 'primary-email' field
                // Only if it contains headstart domain. If not leave it undefined
                case ($cf->name == 'headstart-email'):

                    // look for the mapping slug in the ninja forms field's admin label
                    $key = array_search('primary-email', $admin_label_array_ninjaforms);

                    if ($key !== false)
                    {
                        // get the value of the primary email from the application.
                        $primary_email_ninja_form = $value_array_ninjaforms[$key];

                        // Check if mail contains headstart domain
                        if ( stripos( $primary_email_ninja_form, '@headstart.edu.in') !== false )
                        {
                            // the given email DOES contain  the headstart domain, so we can capture it
                            $data[$cf->slug]= $value_array_ninjaforms[$key];
                        }
                        else
                        {
                            self::$verbose ? error_log( "primary email in application form does NOT contain @headstart.edu.in" ) : false;
                        }
                    }
                    else
                    {
                        self::$verbose ? error_log( "index for: primary-email, not found in Ninja forms value array" ) : false;
                    }
                break;


                // the ticket custom field 'subject' is fixed to 'Admission'
                case ($cf->slug == 'subject'):

                    // default for all users
                    $data[$cf->slug]= 'Admission';

                break;

                // Description is a fixed string 'Admission' for all tickets
                case ($cf->slug == 'description'):

                    // default for all users
                    $data[$cf->slug]= 'Admission';

                break;

                    // ensure that the address does not contain forbidden characters.
                    // replace a / with a - otherwise the ticket creation will  result in error
                case ($cf->name == 'residential-address'):
                    // set the search and replace strings
                    $find       = "/";
                    $replace    = "-";

                    // look for the mapping slug in the ninja forms field's admin label
                    $key = array_search('residential-address', $admin_label_array_ninjaforms);

                    if ($key !== false)
                    {
                        // get the custmeer entered residential address from the form data array using the key
                        $value    = $value_array_ninjaforms[$key];

                        // set the value for the ticket custom field by search and replace of potential bad character "/"
                        $data[$cf->slug] = str_ireplace($find, $replace, $value);
                    }
                    else
                    {
                        self::$verbose ? error_log($cf->name  . " -> index not found in Ninja forms value array") : false;
                    } 
                break;


                case ($cf->name == 'student-first-name'):
                    // look for the mapping slug in the ninja forms field's admin label
                    $key = array_search('student-first-name', $admin_label_array_ninjaforms);

                    if ($key !== false)
                    {
                        // get the custmeer entered students first name
                        $value    = $value_array_ninjaforms[$key];

                        // Convert to lowercase and Capitalize the 1st letter
                        $data[$cf->slug] = ucfirst(strtolower($value));

                        // capture student's name for use in description later on
                        $student_first_name = $data[$cf->slug];
                    }
                    else
                    {
                        self::$verbose ? error_log($cf->name . " -> index not found in Ninja forms value array") : false;
                    }
                break;


                case ($cf->name == 'student-middle-name'):
                    // look for the mapping slug in the ninja forms field's admin label
                    $key = array_search('student-middle-name', $admin_label_array_ninjaforms);

                    if ($key !== false)
                    {
                        // get the custmeer entered students first name
                        $value    = $value_array_ninjaforms[$key];

                        // Convert to lowercase and Capitalize the 1st letter
                        $data[$cf->slug] = ucfirst(strtolower($value));

                        // capture student's name for use in description later on
                        $student_middle_name = $data[$cf->slug];
                    }
                    else
                    {
                        self::$verbose ? error_log($cf->name . " -> index not found in Ninja forms value array") : false;
                    }
                break;

                case ($cf->name == 'student-last-name'):
                    // look for the mapping slug in the ninja forms field's admin label
                    $key = array_search('student-last-name', $admin_label_array_ninjaforms);

                    if ($key !== false)
                    {
                        // get the custmeer entered students first name
                        $value    = $value_array_ninjaforms[$key];

                        // Convert to lowercase and Capitalize the 1st letter
                        $data[$cf->slug] = ucfirst(strtolower($value));

                        // capture student's name for use in description later on
                        $student_last_name = $data[$cf->slug];
                    }
                    else
                    {
                        self::$verbose ? error_log($cf->name . " -> index not found in Ninja forms value array") : false;
                    }
                break;


                default:

                    // from here on  we do not need to manipulate the value so this is generic
                    // look for the mapping name in the ninja forms field's admin label
                    $key = array_search($cf->name, $admin_label_array_ninjaforms);

                    if ($key !== false)
                    {
                        // no need to manipulate, just index using slug and populate the value extracted
                        $data[$cf->slug]= $value_array_ninjaforms[$key];
                    }
                    else
                    {
                        self::$verbose ? error_log($cf->name . " -> index not found in Ninja forms value array") : false;
                    }
                break;

            endswitch;          // end switching throgh the ticket fields looking for a match

        endforeach;             // finish looping through the ticket fields for mapping Ninja form data to ticket

        // set the cf field 'customer' of the ticket to the customer id as required
        $data['customer'] = $customer->id;

        $student_full_name = $student_first_name . ' ' . $student_middle_name . ' ' . $student_last_name;

        // Seperate the 'description' custom field from $data as required by Support Candy
        $description = "Application for Admission of " . $student_full_name . 'to ' . $category_name;
        unset( $data['description'] );

        // Seperate description attachments from $data.
        $description_attachments = $data['description_attachments'] ?? "";
        unset( $data['description_attachments'] );

        $data['last_reply_on'] = ( new DateTime() )->format( 'Y-m-d H:i:s' );

        $data['source']     = 'MA_HSA_plugin';
	    $data['ip_address'] = WPSC_DF_IP_Address::get_current_user_ip();
	    $data['browser']    = WPSC_DF_Browser::get_user_browser();
	    $data['os']         = WPSC_DF_OS::get_user_platform();

        // we have all the necessary ticket fields filled from the Ninja forms, now we can create a new ticket
        $ticket = WPSC_Ticket::insert( $data );

        if ( ! $ticket ) 
        {
            self::$verbose ? error_log('Could not create a new SC ticket from Ninja form submission, Investigate'): false;

            return null;
        }
        else 
        {
            self::$verbose ? error_log('A new SC ticket created from Ninja form for Customer ID:' . $customer->id . 
                                                                                ' and Ticket ID:' . $ticket->id): false;
        }

        // if we get here it means that the new ticket was successfully created. Lets add the thread and finish
        $ticket->last_reply_by = $ticket->customer->id;
		$ticket->save();

        // Create report thread.
        $thread = WPSC_Thread::insert(
            array(
                'ticket'      => $ticket->id,
                'customer'    => $ticket->customer->id,
                'type'        => 'report',
                'body'        => $description,
                'attachments' => $description_attachments,
                'source'      => 'MA_plugin_code',
            )
        );

        // Create an action hook for just after creation of a new ticket  in case the SC plugin needs to do something here
        do_action( 'wpsc_create_new_ticket', $ticket );

        return $ticket->id;
    }

    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  @param object:$ticket is the ticket object passed in
     *  @return string:$student_fullname is the full name formed from first, middle and last names of student in ticket
     */
    public static function get_student_full_name_from_ticket( object $ticket ) : ? string
    
    {
        $student_fullname = self::get_ticket_value_given_cf_name( $ticket,  'student-first-name' )     . " " . 
                            self::get_ticket_value_given_cf_name( $ticket,  'student-middle-name' )    . " " . 
                            self::get_ticket_value_given_cf_name( $ticket,  'student-last-name' );
        
        return $student_fullname;
    }


    /**
     *  @param int:$ticket is the ticket concerned
     *  @param int:$prev is the previous status ID of the ticket
     *  @param int:$new is the ID of the current new status of the ticket
     *  do_action( 'wpsc_change_ticket_status', self::$ticket, $prev, $new, $customer_id ) in class_wpsc_individual_ticket
     *  This is the  callback that triggers the various tasks contingent upon ticket status change to desired one
     *  When the status changes to Admission Granted, the payment process is triggered immediately
     *  When the ststus is changed to Admission Confirmed the SriToni new user account creation is triggered
     *  More can be added here as needed.
     */
    public static function my_wpsc_change_ticket_status_action_callback( $ticket, $prev, $new, $customer_id)
    {   // status change triggers the main state machine of our plugin
        // add any logoc that you want here based on new status
        $prev_status_object = new WPSC_Status( $prev ); // rebuild status object using previous status id passed in
        $prev_status_name   = $prev_status_object->name;

        $new_status_object = new WPSC_Status( $new );   // rebuild status pbject using current status id passed in
        $new_status_name   = $new_status_object->name;

        switch (true):

            // If new status is "Interaction Completed" then calculate fees and payment description and update the ticket fields
            case ( $new_status_name === 'Interaction Completed' ):

                $student_fullname = self::get_student_full_name_from_ticket( $ticket );

                // get the object from category using category id from ticket
                $category_object_from_ticket = new WPSC_Category( $ticket->category );

                // get the ticket category name from ID
                $name_of_ticket_category = $category_object_from_ticket->name;

                // the category NAME (not slug as in prev version) is used as key to get the required data
                // make sure this is adjusted correctly in the settings for fees
                $admission_fee_payable      = self::$category_fee_arr[$name_of_ticket_category];

                $product_customized_name    = self::$category_paymentdescription_arr[$name_of_ticket_category] . " " . $student_fullname;

                // update the agent fields for fee and fee description. 1st form the slug of the custom field using name
                $cf_slug = self::get_cf_slug_by_cf_name( 'admission fee payable' );
                $ticket->$cf_slug = $admission_fee_payable;
                $ticket->save();

                $cf_slug = self::get_cf_slug_by_cf_name( 'product customized name' );
                $ticket->$cf_slug = $product_customized_name;
                $ticket->save();
                
            break;

            // If new status is 'School Accounts Being Created' Create a new user account in SriToni remotely using Moodle API
            case ( $new_status_name === 'School Accounts Being Created' ):

                // Create a new user account in SriToni remotely using Moodle API. If existing user, update the profile information
                $moodle_id = self::prepare_and_create_or_update_moodle_account( $ticket );

                // if successful in sritoni account creation, change to next status - @TODO
                if ($moodle_id)
                {
                    // TODO:
                }
            break;


            // This assumes that we have a valid sritoni account, and a valid hset-payment intranet account after ldap-sync
            case ( $new_status_name === 'Admission Payment Order Being Created' ):
                
                self::create_payment_shop_order( $ticket );

            break;


            case ( $new_status_name === 'Admission Granted' ):

                // Emails are sent to user for making payments.
                // Once payment is made the Order is set to Processing
                // Once Admin sets Order as completed, the webhook fires on the payment site and is captured on this site
                // The captured webhook sets the status as Payment completed
                // There is no other explicit code to be executed here
                // TODO:

             break;


            case ( $new_status_name === 'Admission Confirmed' ):

                // No explicit action in code here
                // The admin needs to trigger the next step by changing the status
            break;


            default:
                // all other cases come here and flow down with no action.
            break;

        endswitch;      // END of switch  status change actions
    }                  

    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  This routine is typically called by a scheduled task from outside the class using the instance so this is public
     *  No pre-requisites. Statuses have to  exist in ticket system settings
     *  1. Get a list of all tickets having status: 'school-accounts-being-created'
     *  2. For each ticket, poll the hset-payments site and check if ticket user's user account exists
     *  3. The accounts get created on payment site by a wp-cron driven  hourly ldap sync process from sritonildapsync plugin
     *  4. If user account exists, change status of that ticket to enable PO creation: 'admission-payment-order-being-created'
     */
    public function check_if_accounts_created() : void
    {   // check if (new) accounts are sync'd and exist on payment server - if so change status to admission-payment-order-being-created
        // $this->verbose ? error_log("In function check_if_accunts_created caused by scheduled event:"): false;
        // keep status id prepared in advance to change status of selected ticket in loop

        // get all tickets that have payment status as shown. 
        $tickets = self::get_all_active_tickets_by_status_name( "School Accounts Being Created" );

        self::$verbose ? error_log("Number of tickets being processed with status 'School Accounts Being Created':" . count($tickets)): false;

        foreach ($tickets as $ticket):
        
            $ticket_id = $ticket->id;

            // since we are checking for creation of new SriToniaccounts we cannot use ticket email.
            // we have to use admin given username (agent field) with our domain. So username has to be set for this.
            // since this comes after sritoni account creation we know this would have been set.
            $headstart_email = $ticket->{ self::get_cf_slug_by_cf_name( 'headstart-email' ) };

            if ( !empty($headstart_email) && stripos( $headstart_email, "@headstart.edu.in" ) !== false )
            {
                // This is a continuing head start user so use existing headstart email
                $email = $headstart_email;
            }
            else
            {
                // this is a new headstart user so compose the email
                $email = $ticket->{ self::get_cf_slug_by_cf_name( 'username' ) } . '@headstart.edu.in';
            }
            

            // check if wpuser with this email exists in site hset-payments
            $wp_user_hset_payments = self::get_wp_user_hset_payments($email, $ticket_id);

            if ($wp_user_hset_payments)
            {
                $new_status_name_desired = "Admission Payment Order Being Created";

                // we have a valid customer so go ahead and change status of this ticket to enable PO creation
                self::change_ticket_status( $ticket_id, $new_status_name_desired );

                // log the user id and displayname
                self::$verbose ? error_log("User Account with id:" . $wp_user_hset_payments->id 
                                                    . " And name:" . $wp_user_hset_payments->data->display_name): false;

            }

        endforeach;
    }


    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  @param string:$email
     *  @return object:$customers[0]
     * Pre-requisites: None
     * 1. Get wpuser object from site hset-payments with given email using Woocommerce API
     * 2. If site is unreacheable change status of ticket to error
     */
    public static function get_wp_user_hset_payments($email, $ticket_id)
    {   // Get wpuser object from site hset-payments with given email using Woocommerce API

        // instantiate woocommerce API class
        $woocommerce = new Client(
                                    'https://sritoni.org/hset-payments/',
                                    self::$config['wckey'],
                                    self::$config['wcsec'],
                                    [
                                        'wp_api'            => true,
                                        'version'           => 'wc/v3',
                                        'query_string_auth' => true,

                                    ]);


        $endpoint   = "customers";

        $params     = array( 'role'  =>'subscriber', 'email' => $email );
        // get customers with given parameters as above, there should be a maximum of 1
        try
        {
            $customers = $woocommerce->get($endpoint, $params);
        }
        catch (HttpClientException $e)
        {   // if cannot access hset-payments server error message and return 1
            $error_message = "Intranet Site: hset-payments not reacheable: " . $e->getMessage();
            self::change_status_error_creating_payment_shop_order($ticket_id, $error_message);

            return null;
        }
            
         // if you get here then you got something back from hset-payments site
         return $customers[0];
    }


    /**
     * TODO: Obsolete function, delete once flow is finalized
     * @param object:$ticket
     * @return void
     * Takes the data object from the ticket using the ticket_id
     * Get customer_id from hset-payments site using Woocommerce API
     * Create a new Payment Order using all the data for the given customer_id
     * Update the ticket field with the order_id created
     */
    public static function create_payment_shop_order( object $ticket ) : void
    {   // from email get customer_id and create a new Payment Order - update ticket field with order_id

        // get the category id from the ticket
        $ticket_category_id = $ticket->category;

        $category_object = new WPSC_Category( $ticket_category_id );
        
        // get the ticket category name from ID
        $category_name_of_ticket = $category_object->name;

        /*
        1. Get customer object from site hset-payments using the email
        2. Check if VA data in the user meta is valid. If not update if VA exists. If VA does not exist, create new VA
        3. If VA updated or created, update the site hset-payments with updated user meta for VA data.
        */
        $wp_user_hset_payments = self::get_wpuser_hset_payments_check_create_cfva();

        if (empty($wp_user_hset_payments)) return;  // safety catch

        $customer_id = $wp_user_hset_payments->id;

        // if you got here you must be a head start user with a valid VA and customer_id and valid customer object

        // let's write this customer id to the ticket's agent only field for easy reference
        self::change_ticket_field( $ticket_id, 'wp-user-id-hset-payments', $customer_id);

        // check that admission-fee-payable and product-customized-name  fields are set
        $product_customized_name    = $ticket->{ self::get_cf_slug_by_cf_name( 'product-customized-name' ) };
        if ( empty( $product_customized_name ) )
        {
            // means agent has not set it so get the value from the settings
            $fullname       = self::get_student_full_name_from_ticket( $ticket );
            $product_customized_name = self::$category_paymentdescription_arr[$category_name_of_ticket] . " " . $fullname;
        }
        
        $regular_price = $ticket->{ self::get_cf_slug_by_cf_name( 'admission-fee-payable' ) };
        if ( empty( $regular_price ) )
        {
            // agent has not set this so use the value from settings based on ticket category
            $regular_price = self::$category_fee_arr[$category_name_of_ticket];
        }


        if (empty($regular_price) || empty($product_customized_name))
        {
            // change ticket status to error with an error message
            $error_message = "Admission-fee-payable and or the product-customized-name fields need to be set";

            self::change_status_error_creating_payment_shop_order( $ticket->id, $error_message);

            return;
        }

        $new_order = self::create_wc_order_site_hsetpayments( $ticket );

        self::$verbose ? error_log($new_order->id . " ID of newly created payment SHOP Order") : false;

        // update the agent field with the newly created order ID
        self::change_ticket_field( $ticket_id, 'order-id', $new_order->id);
    }


    /**
     *    OBSOLETE TODO: Delete this function
     * 1. This function grabs all ticket fields (agent and non-agent) data from a given ticket id
     * 2. It then creates a new data_object that contains all of the ticket data,for ease of access
     * 3. This data_object is also set as a property of $this class
     *  @param object:$ticket_id
     *  @return object:$data_object
     */
    private function get_data_object_from_ticket( $ticket )
    {   // create a data object from ticket fields from ticket using ticket_id

        $this->ticket_id    = $ticket_id;
        $this->ticket_data  = $wpscfunction->get_ticket($ticket_id);

        $data_object = new stdClass;

        $fields = get_terms([
            'taxonomy'   => 'wpsc_ticket_custom_fields',
            'hide_empty' => false,
            'orderby'    => 'meta_value_num',
            'meta_key'	 => 'wpsc_tf_load_order',
            'order'    	 => 'ASC',

            'meta_query' => array(
                                    array(
                                        'key'       => 'agentonly',
                                        'value'     => ["0", "1"],  // get all ticket meta fields
                                        'compare'   => 'IN',
                                        ),
                                ),

        ]);

        // create a new associative array that holds the ticket field object keyed by slug. This way we can get it on demand
        $ticket_meta = [];

        foreach ($fields as $field)
        {
            if ($field->slug == "ticket_category"   ||
                $field->slug == "ticket_status"     ||
                $field->slug == "ticket_priority"   ||
                $field->slug == "ticket_subject"    ||
                $field->slug == "customer_name"     ||
                $field->slug == "customer_email"
            )
            {
                // this data is avaulable directly from ticket data and is blank in ticket meta so this work around
                $ticket_meta[$field->slug] = $this->ticket_data[$field->slug];
            }
            else
            {
                $ticket_meta[$field->slug] = $wpscfunction->get_ticket_meta($ticket_id, $field->slug, true);
            }
        }

        $data_object->ticket_id      = $ticket_id;
        $data_object->ticket_data    = $this->ticket_data;
        $data_object->ticket_meta    = $ticket_meta;        // to access: $data_object->ticket_meta[fieldslug]

        // ticket_meta data for category, status, subject, description, applicant name and email are blank
        // use only for stuff like student name, etc.
        // get that other data from ticket_data array if needed.
        $this->data_object           = $data_object;

        return $data_object;
    }



    /**
     *  TODO: Obsolete, delete when flow is finalized
     * 
     *  Gets customer user object from payments site and returns it. No Cashfree account check or creation.
     *
     * @return obj:woocommerce customer object - null returned in case of server error or if user does not exist or bad email
     */
    public static function get_wpuser_hset_payments_check_create_cfva( $ticket ) : ? object
    {   // Get customer User Object from payments site using email. No Cashfree API used anymore

        // instantiate woocommerce API class
        $woocommerce = new Client(
                                    'https://sritoni.org/hset-payments/',
                                    self::$config['wckey'],
                                    self::$config['wcsec'],
                                    [
                                        'wp_api'            => true,
                                        'version'           => 'wc/v3',
                                        'query_string_auth' => true,

                                    ]);


        $category_id = $ticket->category;

        $category_object = new WPSC_Category( $category_id );

        // from category ID get the category name
        $category_name = $category_object->name;

        // for Internal users get email directly from ticket->headstart-email - for new users, use agent assigned username
        if (stripos($category_name, "internal") === false)
        {       // Category slug does NOT contain 'internal' so external user application so use agent assigned username
            $email = $ticket->{ self::get_cf_slug_by_cf_name( 'username' ) } . '@headstart.edu.in'; 
        }
        else
        {       // headstart user so use form/ticket email directly
            $email = $ticket->{ self::get_cf_slug_by_cf_name( 'headstart-email' ) };

            if (stripos($email, "headstart.edu.in") === false)
            {   // email is NOT headstart domain, cannot process further
                self::$verbose? error_log("Email is NOT of Head Start Domain: " . $email) : false;
                $error_message = "Email is NOT Head Start Domain: " . $email;
                self::change_status_error_creating_payment_shop_order( $ticket->id, $error_message );

                return null;
            }
        }
        

        // get the  WP user object from the hset-payments site using woocommerce API, set error status if not successfull
        $wp_user_hset_payments = self::get_wp_user_hset_payments( $email, $ticket->id) ;

        self::$wp_user_hset_payments = $wp_user_hset_payments;

        return $wp_user_hset_payments;
    }



    /**
     *  TODO: Obsolete, delete once flow is finalized
     *  Creates a new Order on the payments site
     *  Prerequisites:
     *  
     *  1. $this->get_wpuser_hset_payments_check_create_cfva() to get valid customet object and also valid VA
     *  
     *  This function creates a new Order at the payments site using information derived from ticket and customer object
     *  @return obj:$order_created
     */
    public static function create_wc_order_site_hsetpayments( $ticket )
    {   // creates a new Order at the payments site using information derived from ticket and customer object
        
        $array_meta_key     = [];
        $array_meta_value   = [];

        $index              = null;

        // fix the customer_id and corresponding va_id
        if (self::$wp_user_hset_payments)
        {
            // customer object is not null, so should contain valid customer ID and va_id
            // customer ID is the WP id of this user in site hset-payments
            $customer_id = $data_object->wp_user_hset_payments->id;

            $moodle_id = $data_object->wp_user_hset_payments->username;

            $va_id = str_pad($moodle_id, 4, "0", STR_PAD_LEFT);
        }
        else
        {
            self::change_status_error_creating_payment_shop_order($data_object->ticket_id, 'Null customer object found at line 1054 -  No PO created');
            self::$verbose ? error_log("Null wp user object found at line 1045 -  No PO created for ticket:" . $data_object->ticket_id): false;
            return;
        }
        
        // derive the fee and description from ticket field/settings based on agent override
        // get the category id from the data object ticket meta
        $ticket_category_id = $ticket->category;

        // check that admission-fee-payable and product-customized-name  fields are set
        $product_customized_name    = $ticket->{ self::get_cf_slug_by_cf_name( 'product-customized-name' ) };
        if ( empty( $product_customized_name ) )
        {
            // means agent has not set it so get the value from the settings
            $fullname       = self::get_student_full_name_from_ticket( $ticket );
            $product_customized_name = self::$category_paymentdescription_arr[$category_name_of_ticket] . " " . $fullname;
        }
        
        $regular_price = $ticket->{ self::get_cf_slug_by_cf_name( 'admission-fee-payable' ) };
        if ( empty( $regular_price ) )
        {
            // agent has not set this so use the value from settings based on ticket category
            $regular_price = self::$category_fee_arr[$category_name_of_ticket];
        }

        // instantiate woocommerce API class
        $woocommerce = new Client(
                                    'https://sritoni.org/hset-payments/',
                                    self::$config['wckey'],
                                    self::$config['wcsec'],
                                    [
                                        'wp_api'            => true,
                                        'version'           => 'wc/v3',
                                        'query_string_auth' => true,

                                    ]);

        // Admission fee to HSET product ID. This is the admission product whose price and description can be customized
        $product_id = 581;

        $endpoint   = "products/" . $product_id;

        // customize the Admission product for this user
        $product_data = [
                            'name'          => $product_customized_name,
                            'regular_price' => $regular_price,
                        ];
        // TODO use try catch here
        $product = $woocommerce->put($endpoint, $product_data);

        // lets now prepare the data for the new order to be created for this user
        // we use customer_email as billing email to not acommunicate to headstart-mail.
        $order_data = [
            'customer_id'           => $customer_id,        // this is important, needs to pre-exist on site
            'payment_method'        => 'vabacs',
            'payment_method_title'  => 'Offline Direct bank transfer to Head Start Educational Trust',
            'set_paid'              => false,
            'status'                => 'on-hold',
            'billing' => [
                'first_name'    => $data_object->ticket_meta['customer_name'],
                'last_name'     => '',
                'address_1'     => $data_object->ticket_meta['residential-address'],
                'address_2'     => '',
                'city'          => $data_object->ticket_meta['city'],
                'state'         => $data_object->ticket_meta['state'],
                'postcode'      => $data_object->ticket_meta['pin-code'],
                'country'       => $data_object->ticket_meta['country'],
                'email'         => $data_object->ticket_meta['customer_email'], // used for payment communications
                'phone'         => $data_object->ticket_meta['emergency-contact-number'],
            ],
            'shipping' => [
                'first_name'    => $data_object->ticket_meta['customer_name'],
                'last_name'     => '',
                'address_1'     => $data_object->ticket_meta['residential-address'],
                'address_2'     => '',
                'city'          => $data_object->ticket_meta['city'],
                'state'         => $data_object->ticket_meta['state'],
                'postcode'      => $data_object->ticket_meta['pin-code'],
                'country'       => $data_object->ticket_meta['country'],
            ],
            'line_items' => [
                [
                    'product_id'    => 581,
                    'quantity'      => 1
                ],
            ],
            'meta_data' => [
                [
                    'key' => 'va_id',
                    'value' => $va_id
                ],
                [
                    'key' => 'sritoni_institution',
                    'value' => 'admission'
                ],
                [
                    'key' => 'grade_for_current_fees',
                    'value' => 'admission'
                ],
                [
                    'key' => 'name_on_remote_order',
                    'value' => $data_object->ticket_meta['student-first-name']  . " "   .
                               $data_object->ticket_meta['student-middle-name'] . " "   .
                               $data_object->ticket_meta['student-last-name']
                ],
                [
                    'key' => 'payer_bank_account_number',
                    'value' => $data_object->ticket_meta['payer-bank-account-number']
                ],
                [
                    'key' => 'admission_number',
                    'value' => $data_object->ticket_id,
                ],

            ],
        ];

        // finally, lets create the new order using the Woocommerce API on the remote payment server
        $order_created = $woocommerce->post('orders', $order_data);

        // check if the order has been created and if so what is the order ID
        if (!empty($order_created->id))
        {
            // Blank any pre-existing error message since we are successful
            if ( !empty($data_object->ticket_meta["error"]) )
            {
                $wpscfunction->change_field($data_object->ticket_id, 'error', "");
            }
            return $order_created;
        }
        else
        {
            // there was an error in creating the prder. Update the status and the error message for the ticket
            self::change_status_error_creating_payment_shop_order($data_object->ticket_id, 'could NOT create payment order, check');
        }
    }


    /**
     *  TODO: Obsolete, delete once flow is finalized
     *  @param int:$order_id
     *  @param array:$order_data
     *  Update the order with the given order data. The order_data has to be constructed as per requirements
     */
    public function update_wc_order_site_hsetpayments($order_id, $order_data)
    {   // Update the order with the given order data. The order_data has to be constructed as per requirements
        // instantiate woocommerce API class
        $woocommerce = new Client(
                                    'https://sritoni.org/hset-payments/',
                                    $this->config['wckey'],
                                    $this->config['wcsec'],
                                    [
                                        'wp_api'            => true,
                                        'version'           => 'wc/v3',
                                        'query_string_auth' => true,

                                    ]);
                                    

        $endpoint       = "orders/" . $order_id;

        $order_updated  = $woocommerce->put($endpoint, $order_data);

        return $order_updated;
    }


    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  This works for any ticket field wether default predefined type or custom type with slug like cust_35 etc.
     */
    public static function get_ticket_value_given_cf_name( object $ticket, string $cf_name )
    {
        $cf_slug = self::get_cf_slug_by_cf_name( $cf_name );

        $cf_value = $ticket->$cf_slug ?? null;

        return $cf_value;
    }



    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  @param string:$cf_name is the name of the ticket custom field that is not of the default pre-defined type
     *  This is to be used for custom ticket fields that end up having slug like cust_45 where 45 is the cf id.
     */
    public static function get_cf_slug_by_cf_name( string $cf_name ) : string
    {
        // get the object using the name
        $cf = self::get_cf_object_by_cf_name( $cf_name );

        // extract the slug from the ststus object
        $cf_slug = $cf->slug;

        return $cf_slug;
    }

    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  @param string:$cf_name is the name of the ticket custom field that is not of the default pre-defined type
     *  This is to be used for custom ticket fields that end up having slug like cust_45 where 45 is the cf id.
     */
    public static function get_cf_object_by_cf_name( string $cf_name ) : ? object
    {
        foreach ( WPSC_Custom_Field::$custom_fields as $cf ):

            // get only ticket fields, and agent only fields
            if ( ! in_array( $cf->field, array( 'ticket', 'agentonly' ) )  ) {
                continue;
            }
            if ( $cf->name === $cf_name )   // Does the field name match passed in field name?
            {
                // we have a match, so return this object
                return $cf;
            }

        endforeach;

        // could not find a match so return null
        return null;
    }



    /**
     * VISUALLY CHECKED for SC 3.0 compatibility
     * 
     * @param object:$ticket
     * @return bool or int
     * 1. If user already has a Head Start account (detected by their email domain) update user
     * 2. Check that required data is not empty. If so, change ticket status to error
     * 3. Processd for SriToni account creation
     */
    public static function prepare_and_create_or_update_moodle_account( object $ticket )
    {   // update existing or create a new SriToni user account using data from ticket. Add user to cohort based on ticket data

        // get the headstart email from the ticket using the cf name 'headstart-email'
        $headstart_email = self::get_ticket_value_given_cf_name( $ticket, 'headstart-email' );
 
        if (stripos( $headstart_email, 'headstart.edu.in') !== false)
        {   // User already has an exising SriToni email ID and Head Start Account, just update with form info

            self::update_sritoni_account( $ticket );
            
            // add user to relevant incoming cohort for easy cohort management
            self::add_user_to_cohort( $ticket );

            return false;
        }

        // if you are here you don't have an existing Head Start email. So Create a new SriToni account using username
        // username must be set by an agent from the support UI.
        // check if all required data for new account creation is set
        
        $username       = self::get_ticket_value_given_cf_name( $ticket, 'username' );
        $idnumber       = self::get_ticket_value_given_cf_name( $ticket, 'idnumber' );
        $studentcat     = self::get_ticket_value_given_cf_name( $ticket, 'studentcat' );
        $department     = self::get_ticket_value_given_cf_name( $ticket, 'department' );
        $institution    = self::get_ticket_value_given_cf_name( $ticket, 'institution' );
        $class          = self::get_ticket_value_given_cf_name( $ticket, 'class' );
        $moodle_phone1  = self::get_ticket_value_given_cf_name( $ticket, 'emergency-contact-number' );
        $moodle_phone2  = self::get_ticket_value_given_cf_name( $ticket, 'emergency-alternate-contact' );

        if 
        (   ! empty( $username )      &&
            ! empty( $idnumber )      &&
            ! empty( $studentcat )    &&
            ! empty( $department )    &&
            ! empty( $institution )   &&
            ! empty( $class )         &&
            ! empty( $moodle_phone1 ) &&
            ! empty( $moodle_phone2 )
        )        
        {
            // go create a new SriToni user account for this child using ticket dataa. Return the moodle id if successfull
            $moodle_id = self::create_sritoni_account( $ticket );

            if ($moodle_id)
            {
                // IF new user account is valid, add the user to appropriate cohort for easy management later on
                self::add_user_to_cohort( $ticket );
            }
            
            return $moodle_id;
        }
        else
        {
            $error_message = "Error in user creation! Ensure username, idnumber, studentcat, department, institution, class, phone1, 2 are Set";
            self::change_status_error_creating_sritoni_account( $ticket->id, $error_message);

            return null;
        }
    }


    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     *  @param object:$ticket
     *  @return int:moodle user id from table mdl_user. null if error in creation
     *  1. The data is extracted from the ticket and checked to ensure it is set and not empty
     *  2. Function checks if username is not taken, only then creates new account.
     *  3. If error in creating new accoount or username is taken, ticket status changed to error.
     */
    private static function create_sritoni_account( object $ticket ) : ? int
    {   // checks if username is not taken, only then creates new account -sets ticket error status if account not created

        // read in the Moodle API config array
        $config			= self::$config;
        $moodle_url 	= $config["moodle_url"] . '/webservice/rest/server.php';
        $moodle_token	= $config["moodle_token"];

        // username is to be set by agent for the agentonly field. This is a new user so required to be set correctly
        $moodle_username    = self::get_ticket_value_given_cf_name( $ticket, 'username' );

        // Check to see if a user with this username already exists. A pre-existing account is an error that is handled here
        $existing_moodle_user_array = self::get_user_account_from_sritoni( $ticket, $moodle_username,  false);

        if ( $existing_moodle_user_array )
        {
            // An account with this username already exssts. So set error status so Admin can set a different username and try again
            $error_message = "This username already exists! Is this an existing user? If not change username and retry";

            self::$verbose ? error_log($error_message) : false;

            // change the ticket status to error
            self::change_status_error_creating_sritoni_account( $ticket, $error_message );

            return null; 
        }

        // if you are here we have a username that does not exist yet so create a new moodle user account with this username

        // moodle email for a new user is username@domain
        $moodle_email       = $moodle_username . "@headstart.edu.in";   // new account so this is the rule

        $moodle_environment = self::get_ticket_value_given_cf_name( $ticket, 'environment' );
        if (empty($moodle_environment)) $moodle_environment = "NA";

        // write the data back to Moodle using REST API
        // create the users array in format needed for Moodle RSET API
        $fees_array = [];
        $fees_json  = json_encode($fees_array);

        $payments   = [];
        $payments_json = json_encode($payments);

        $virtualaccounts = [];
        $virtualaccounts_json = json_encode($virtualaccounts);

    	$users = array("users" => array(
                                        array(	"username" 	    => $moodle_username,
                                                "idnumber"      => self::get_ticket_value_given_cf_name( $ticket, "idnumber"),
                                                "auth"          => "oauth2",
                                                "firstname"     => self::get_ticket_value_given_cf_name( $ticket, "student-first-name"),
                                                "lastname"      => self::get_ticket_value_given_cf_name( $ticket, "student-last-name"),
                                                "email"         => $moodle_email,
                                                "middlename"    => self::get_ticket_value_given_cf_name( $ticket, "student-middle-name"),
                                                "institution"   => self::get_ticket_value_given_cf_name( $ticket, "institution"),
                                                "department"    => self::get_ticket_value_given_cf_name( $ticket, 'department' ),
                                                "phone1"        => self::get_ticket_value_given_cf_name( $ticket, 'emergency-contact-number' ),
                                                "phone2"        => self::get_ticket_value_given_cf_name( $ticket, 'emergency-alternate-contact' ),
                                                "address"       => self::get_ticket_value_given_cf_name( $ticket, "residential-address"),
                                                "maildisplay"   => 0,
                                                "createpassword"=> 0,
                                                "city"          => self::get_ticket_value_given_cf_name( $ticket, "city"),

                                                "customfields" 	=> array(
                                                                        array(	"type"	=>	"class",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "class"),
                                                                            ),
                                                                        array(	"type"	=>	"environment",
                                                                                "value"	=>	$moodle_environment,
                                                                            ),
                                                                        array(	"type"	=>	"emergencymob",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, 'emergency-contact-number' ),
                                                                            ),
                                                                        array(	"type"	=>	"fees",
                                                                                "value"	=>	$fees_json,
                                                                            ),
                                                                        array(	"type"	=>	"payments",
                                                                                "value"	=>	$payments_json,
                                                                            ),
                                                                        array(	"type"	=>	"virtualaccounts",
                                                                                "value"	=>	$virtualaccounts_json,
                                                                            ),
                                                                        array(	"type"	=>	"studentcat",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "studentcat"),
                                                                            ),
                                                                        array(	"type"	=>	"bloodgroup",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "blood-group"),
                                                                            ),
                                                                        array(	"type"	=>	"motheremail",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "mothers-email"),
                                                                            ),
                                                                        array(	"type"	=>	"fatheremail",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "fathers-email"),
                                                                            ),
                                                                        array(	"type"	=>	"motherfirstname",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "mothers-first-name"),
                                                                            ),
                                                                        array(	"type"	=>	"motherlastname",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "mothers-last-name"),
                                                                            ),
                                                                        array(	"type"	=>	"fatherfirstname",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "fathers-first-name"),
                                                                            ),
                                                                        array(	"type"	=>	"fatherlastname",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "fathers-last-name"),
                                                                            ),
                                                                        array(	"type"	=>	"mothermobile",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "mothers-contact-number"),
                                                                            ),
                                                                        array(	"type"	=>	"fathermobile",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "fathers-contact-number"),
                                                                            ),
                                                                        array(	"type"	=>	"allergiesillnesses",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "allergies-illnesses"),
                                                                            ),
                                                                        array(	"type"	=>	"birthplace",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "birthplace"),
                                                                            ),
                                                                        array(	"type"	=>	"nationality",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "nationality"),
                                                                            ),
                                                                        array(	"type"	=>	"languages",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "languages-spoken"),
                                                                            ),
                                                                        array(	"type"	=>	"dob",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "date-of-birth"),
                                                                            ),
                                                                        array(	"type"	=>	"pin",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "pin-code"),
                                                                            ),           
                                                                        )
                                            )
                                        )
        );

        // now to uuser  with form and agent fields
        $ret = $MoodleRest->request('core_user_create_users', $users, MoodleRest::METHOD_POST);

        // let us check to make sure that the user is created
        if ($ret[0]['username'] == $moodle_username && empty($ret["exception"]))
        {
            // Blank any pre-existing error message since we are successful
            if ( ! empty( self::get_ticket_value_given_cf_name( $ticket, "error" )) )
            {
                self::change_ticket_field( $ticket->id, 'error', "");
            }
            // the returned user has same name as one given to create new user so new user creation was successful
            return $ret[0]['id'];
        }
        else
        {
            self::$verbose ? error_log("Create new user did NOT return expected username: " . $moodle_username) : false;
            self::$verbose ? error_log(print_r($ret, true)) : false;

            // change the ticket status to error
            self::change_status_error_creating_sritoni_account( $ticket->id, $ret["message"] );

            return null;
        }

    }


    /**
     * @param object:$ticket is the ticket object passed in
     * @param string:$moodle_username is the username of the user we are interested in getting from SriToni server
     * @param array: The user account array object from SriToni indexed by fields such as id, username, etc.
     * If user account exists, the user account array is returned
     * If account does not exist, a null is returned
     * If account exists and flag is true then ticket error status is set that account already exists
     * If account does NOT exist flag is don't care and no ticket status is set
     */
    private static function get_user_account_from_sritoni( object $ticket, string  $moodle_username, bool $err_flag = false ) : ? array
    {
        // read in the Moodle API config array
        $config			= self::$config;
        $moodle_url 	= $config["moodle_url"] . '/webservice/rest/server.php';
        $moodle_token	= $config["moodle_token"];

        // prepare the Moodle Rest API object
        $MoodleRest = new MoodleRest();
        $MoodleRest->setServerAddress($moodle_url);
        $MoodleRest->setToken( $moodle_token ); // get token from ignore_key file
        $MoodleRest->setReturnFormat(MoodleRest::RETURN_ARRAY); // Array is default. You can use RETURN_JSON or RETURN_XML too.
        // $MoodleRest->setDebug();
        // get moodle user details associated with this completed order from SriToni
        $parameters   = array("criteria" => array(array("key" => "username", "value" => $moodle_username)));

        // get moodle user satisfying above criteria if any
        $moodle_users = $MoodleRest->request('core_user_get_users', $parameters, MoodleRest::METHOD_GET);

        // let us check to make sure that the user exists
        if ($moodle_users[0]['username'] == $moodle_username && empty($moodle_users["exception"]))
        {   // A user exists with given username.

            self::$verbose ? error_log("SriToni user query has existing account with username: " . $moodle_username) : false;

            // Now depending on the error flag set the error and change status for ticket
            if ( $err_flag)
            {
                // Account exists and flag is true so set error status for ticket
                self::change_status_error_creating_sritoni_account( $ticket->id, 'A SriToni account with this username already exists' );

                self::$verbose ? error_log(print_r($moodle_users[0], true)) : false; 
            }
            
            // independent of error flag return auser account array of existing user
            return $moodle_users[0];
        }
        else
        {
            // A user Does NOT exist with given username.
            self::$verbose ? error_log("A SriToni account Does NOT exist with username: " . $moodle_username) : false;  
            
            return null;
        }
    }



    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  @param object:$ticket
     *  @return array:$ret returned array from Moodle update user API call
     *  1. Extracts the username from the headstart email field in ticket
     *  2. If the username is empty returns with an error and changes the ticket status
     *  3. An API call is made to the SriToni server to get the user account array based on username
     *  4. If the call is successful default data for idnumber, department, etc., is extracted
     *  5. Ticket data is used to update the user account. For some fields if ticket data is empty, defaults from above are used
     *  6. The updated user array is returned
     *  The calling routine should check for $ret['exception'] to verify is call was OK or not
     */
    private static function update_sritoni_account( object $ticket ) : ? array
    {
        // Presume Existing user, so the username needs to be extracted from the headstart-email ticket field
        $moodle_email       = self::get_ticket_value_given_cf_name( $ticket, "headstart-email" );
        
        if (($moodle_email  = filter_var($moodle_email, FILTER_VALIDATE_EMAIL)) !== false) 
        {   // validate the email extract everything before @headstart.edu.in. Following is case-sensitive
            $moodle_username = strstr($moodle_email, '@', true);
        }

        if ( empty( $moodle_username ) )
        {
            // Something wrong with extraction of email from user supplied headstart email
            self::$verbose ? error_log("Something wrong with extraction of username from email: " . $moodle_email) : false;

            $error_message = 'Problem extracting username from email - check user supplied headstart email';

            // change the ticket status to error
            self::change_status_error_creating_sritoni_account( $ticket->id, $error_message );

            return null;
        }

        // get the  WP user object from the hset-payments site using woocommerce API. TODO: Delete this if Moodle get user works
        // $wp_user_hset_payments = self::get_wp_user_hset_payments( $moodle_email, $ticket->id );

        // get the moodle_id which is same as wpuser's login at the hset-payments site TODO: Delete if moodle get user works
        // $moodle_id = $wp_user_hset_payments->username;

        // return user if username matches in extracted record and no exception happened. We will handle errors here
        $moodle_user_array = self::get_user_account_from_sritoni( $ticket, $moodle_username, false );

        if ( ! $moodle_user_array )
        {
            // The user account does not exist as expected!!!
            $error_message = 'Problem extracting SriToni account using username from email - check user supplied headstart email';

            // change the ticket status to error
            self::change_status_error_creating_sritoni_account( $ticket->id, $error_message );

            return null;
        }

        // before coming here, check that ticket fields such as idnumber, department, etc., are not empty
        // if ticket values are not set, the user's previous values are reused in the update for these fields
        $moodle_idnumber    = self::get_ticket_value_given_cf_name( $ticket, "idnumber") ?? $moodle_user_array['idnumber'];

        $moodle_department  = self::get_ticket_value_given_cf_name( $ticket, 'department' ) ?? $moodle_user_array['department'];

        $moodle_id = $moodle_user_array['id'];

        // read in the Moodle API config array
        $config			= self::$config;
        $moodle_url 	= $config["moodle_url"] . '/webservice/rest/server.php';
        $moodle_token	= $config["moodle_token"];

        // prepare the Moodle Rest API object
        $MoodleRest = new MoodleRest();
        $MoodleRest->setServerAddress($moodle_url);
        $MoodleRest->setToken( $moodle_token ); // get token from ignore_key file
        $MoodleRest->setReturnFormat(MoodleRest::RETURN_ARRAY); // Array is default. You can use RETURN_JSON or RETURN_XML too.

        
        // We have a valid Moodle user id, form the array to updatethis user. The ticket custom field name must be exactly as shown.
        $users = array("users" => array(
                                        array(	"id" 	        => $moodle_id,          // extracted from Moodle using username
                                                "idnumber"      => $moodle_idnumber,    // can change
                                        //      "auth"          => "oauth2",            // no change
                                                "firstname"     => self::get_ticket_value_given_cf_name( $ticket, "student-first-name" ),
                                                "lastname"      => self::get_ticket_value_given_cf_name( $ticket, "student-last-name" ),
                                        //      "email"         => $moodle_email,       // no change
                                                "middlename"    => self::get_ticket_value_given_cf_name( $ticket, "student-middle-name" ),
                                                "institution"   => self::get_ticket_value_given_cf_name( $ticket, "institution"),
                                                "department"    => $moodle_department,
                                                "phone1"        => self::get_ticket_value_given_cf_name( $ticket, "emergency-contact-number" ),
                                                "phone2"        => self::get_ticket_value_given_cf_name( $ticket, "emergency-alternate-contact" ),
                                                "address"       => self::get_ticket_value_given_cf_name( $ticket, "residential-address" ),
                                         //     "maildisplay"   => 0,                   // no change
                                         //     "createpassword"=> 0,                   // no change
                                                "city"          => self::get_ticket_value_given_cf_name( $ticket, "city" ),

                                                "customfields" 	=> array(                                                                            
                                                                        array(	"type"	=>	"bloodgroup",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "blood-group" ),
                                                                            ),
                                                                        array(	"type"	=>	"motheremail",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "mothers-email" ),
                                                                            ),
                                                                        array(	"type"	=>	"fatheremail",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "fathers-email" ),
                                                                            ),
                                                                        array(	"type"	=>	"motherfirstname",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "mothers-first-name" ),
                                                                            ),
                                                                        array(	"type"	=>	"motherlastname",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "mothers-last-name" ),
                                                                            ),
                                                                        array(	"type"	=>	"fatherfirstname",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "fathers-first-name" ),
                                                                            ),
                                                                        array(	"type"	=>	"fatherlastname",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "fathers-last-name" ),
                                                                            ),
                                                                        array(	"type"	=>	"mothermobile",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "mothers-contact-number" ),
                                                                            ),
                                                                        array(	"type"	=>	"fathermobile",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "fathers-contact-number" ), 
                                                                            ),
                                                                        array(	"type"	=>	"allergiesillnesses",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "allergies-illnesses" ),
                                                                            ),
                                                                        array(	"type"	=>	"birthplace",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "birthplace" ), 
                                                                            ),
                                                                        array(	"type"	=>	"nationality",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "nationality" ),
                                                                            ),
                                                                        array(	"type"	=>	"languages",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "languages-spoken" ),
                                                                            ),
                                                                        array(	"type"	=>	"dob",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "date-of-birth" ),
                                                                            ),
                                                                        array(	"type"	=>	"pin",
                                                                                "value"	=>	self::get_ticket_value_given_cf_name( $ticket, "pin-code" ),
                                                                            ),           
                                                                        )
                                            )
                                    )
                            );
        $ret = $MoodleRest->request('core_user_update_users', $users, MoodleRest::METHOD_POST);

        if ($ret["exception"])
        {
            // There was a problem with the user update
            self::$verbose ? error_log(print_r($ret, true)) : false;

            // change the ticket status to error
            self::change_status_error_creating_sritoni_account( $ticket->id, 'There was a problem with the SriToni user update' );
        }
        else
        {
            // Update went well but we are printing the result anyway. TODO: Remove the print message later on
            self::$verbose ? error_log(print_r($ret, true)) : false;
        }
            
        return $ret;
    }

    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  @param object:$ticket
     *  @param array:$cohort_ret is the array returned after adding the member to the cohort from SriToni server
     *  You must have the data pbject ready before coming here
     *  The user could be a new user or a continuing user
     */
    private static function add_user_to_cohort( object $ticket ) : ? array
    {   // adds user to cohort based on settings cohortid array indexed by ticket category

        // read in the Moodle API config array
        $config			= self::$config;

        $moodle_url 	= $config["moodle_url"] . '/webservice/rest/server.php';
        $moodle_token	= $config["moodle_token"];

        $ticket_headstart_email       = self::get_ticket_value_given_cf_name( $ticket, "headstart-email" );        


        // check if ticket field for headstart email contains the domain name
        if (stripos( $ticket_headstart_email, '@headstart.edu.in') !==false)
        {
            // Continuing user, the username needs to be extracted from the headstart-email
            $moodle_email       = $ticket_headstart_email;

            // get 1st part of the email as the username
            $moodle_username    = explode( "@headstart.edu.in", $moodle_email, 2 )[0];
        }
        else
        {
            // New account just created so get info from ticket fields
            $moodle_username    = self::get_ticket_value_given_cf_name( $ticket, "username" );
            $moodle_email       = $moodle_username . "@headstart.edu.in";
        }
        

        $category_id = $ticket->category;   // since this is a default ticket field the slug is pre-defined

        // from category ID get the category name
        $category_object = new WPSC_Category( $category_id );

        $category_name = $category_object->name;

        // get the cohortid for this ticket based on category-cohortid mapping from settings
        $cohortidnumber = self::$category_cohortid_arr[$category_name];

        // prepare the Moodle Rest API object
        $MoodleRest = new MoodleRest();
        $MoodleRest->setServerAddress($moodle_url);
        $MoodleRest->setToken( $moodle_token ); // get token from ignore_key file
        $MoodleRest->setReturnFormat(MoodleRest::RETURN_ARRAY); // Array is default. You can use RETURN_JSON or RETURN_XML too.

        // the cohort id is the id in the cohort table. Nothing else seems to work. This is what needs to be in the settings
        $parameters   = array("members"  => array(array("cohorttype"    => array(  'type' => 'id',
                                                                                   'value'=> $cohortidnumber
                                                                                ),
                                                        "usertype"      => array(   'type' => 'username',
                                                                                    'value'=> $moodle_username
                                                                                )
                                                        )
                                                )
                            );  

        $cohort_ret = $MoodleRest->request('core_cohort_add_cohort_members', $parameters, MoodleRest::METHOD_GET);

        error_log(print_r($cohort_ret, true));

        return $cohort_ret;
    }

    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     */
    public static function add_my_menu()
    {
        add_submenu_page(
            'users.php',	                    // string $parent_slug
            'Admissions',	                    // string $page_title
            'Admissions',                       // string $menu_title
            'manage_options',                   // string $capability
            'admissions',                       // string $menu_slug
            [__CLASS__, 'render_admissions_page'] );// callable $function = ''

        // add submenu page for testing various application API needed for SriToni operation
        add_submenu_page(
            'users.php',	                     // parent slug
            'SriToni Tools',                     // page title
            'SriToni Tools',	                 // menu title
            'manage_options',	                 // capability
            'sritoni-tools',	                 // menu slug
            [__CLASS__, 'sritoni_tools_render']);    // callback
    }

    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     */
    public function render_admissions_page()
    {
        echo "This is the admissions page where stuff about admissions is displayed";
    }


    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     */
    public static function sritoni_tools_render()
    {
        // this is for rendering the API test onto the sritoni_tools page
        ?>
            <h1> Input appropriate ID (moodle/customer/Ticket/Order) and or username and Click on desired button to test</h1>
            <form action="" method="post" id="mytoolsform">
                <input type="text"   id ="id" name="id"/>
                <input type="text"   id ="username" name="username"/>
                <input type="submit" name="button" 	value="get_sritoni_user_using_username"/>
                <input type="submit" name="button" 	value="test_cashfree_connection"/>
                <input type="submit" name="button" 	value="test_woocommerce_customer"/>
                <input type="submit" name="button" 	value="test_custom_code"/>
                <input type="submit" name="button" 	value="test_get_data_object_from_ticket"/>

                
                <input type="submit" name="button" 	value="test_get_ticket_data"/>
                <input type="submit" name="button" 	value="test_get_wc_order"/>
                
                
                <input type="submit" name="button" 	value="trigger_payment_order_for_error_tickets"/>
                <input type="submit" name="button" 	value="trigger_sritoni_account_creation_for_error_tickets"/>

            </form>


        <?php

        $id = sanitize_text_field( $_POST['id'] );
        $username = sanitize_text_field( $_POST['username'] );

        switch ($_POST['button'])
        {
            case 'get_sritoni_user_using_username':
                self::test_sritoni_connection($username);
            break;

            case 'test_cashfree_connection':
                // $this->test_cashfree_connection($id);
            break;

            case 'test_woocommerce_customer':
                self::test_woocommerce_customer($id);
            break;

            case 'test_get_ticket_data':
                self::test_get_ticket_data($id);
            break;

            case 'test_get_wc_order':
                self::get_wc_order($id);
            break;

            case 'trigger_payment_order_for_error_tickets':
                self::trigger_payment_order_for_error_tickets();
            break;

            case 'trigger_sritoni_account_creation_for_error_tickets':
                self::trigger_sritoni_account_creation_for_error_tickets();
            break;

            case 'test_get_data_object_from_ticket':
                // $this->test_get_data_object_from_ticket($id);
            break;

            case 'test_custom_code':
                self::test_custom_code($id);
            break;

            default:
                // do nothing
                break;
        }
        
    }

    /**
     * 
     */
    public static function test_get_ticket_data($ticket_id)
    {
        $ticket = new WPSC_Ticket( $ticket_id );

        echo '<pre>';
        print_r($ticket);
        echo  '</pre>';

        $status_id = $ticket->status->id;

        $status_object = new WPSC_Status( $status_id );

        echo '<pre>';
        print( "The status object of above ticket is displayed below using its ID obtained from ticket details" );
        print_r($status_object);
        echo  '</pre>';

        $category_id = $ticket->category->id;

        $category_object = new WPSC_Category( $category_id );

        echo '<pre>';
        print( "The Category object of above ticket is displayed below using its ID obtained from ticket details" );
        print_r($category_object);
        echo  '</pre>';
    }




    /**
     * 
     */
    public function action_on_reply_submission( array $args, int $thread_id ): void
    {
        $reply_text = $args['reply_body'];

        $utr = $this->extract_utr( $reply_text );

        if ( $utr )
        {
            $ticket_id  = $args['ticket_id'];

            $this->update_field_bank_reference( $ticket_id, $utr );
        }
    }

    

    /**
     *  @param string:$reply
     *  @return string:$utr can also return null and therefore the ? in the decalaration of function
     *  The given string is searched for space, :, -. and _ and EOL. The characters found are replaced with a space
     *  Next the string is broken up into an an array of sub strings when separated by a space
     *  Each of the sub-strings are searched to see if 12, 16, or 22 characters long. If so, the sub-string is returned as UTR
     */
    public function extract_utr(string $reply): ?string
    {   // sub-strings are searched to see if 12, 16, or 22 characters long. If so, the sub-string is returned as UTR
        $utr = null;    // initialize. 

        // array of possible word separators to look for in reply message text
        //$search = array(" ", ":", "-", "_", ",", ")", "(", ";", ".", PHP_EOL);

        // replace string space
        $replace = " ";

        // replace the desirable word separators if found with a space
        //$modified_reply = str_replace($search, $replace, $reply);

        // form an array of words using the space as a separator
        //$words_arr      = explode($replace, $modified_reply);
        $words_arr = explode(" ", preg_replace("/[\W_]+/", $replace, $reply));

        // check each word for length: IMPS has 12, RTGS 16 and NEFT 22. Also word should have at least 1 digit
        foreach ($words_arr as $word)
        {
            $word_length = iconv_strlen($word);
            if ( ( $word_length === 12 || $word_length === 16 || $word_length === 22 ) 
                 && preg_match('/^(?=.*[\d]).+$/', $word) === 1 )
            {
                $utr = $word;
                
                return $utr;
            }
        }

        // return a null value for utr since no value was found
        return $utr;
    }


    /**
     * This function is called by a wp-cron outside this class usually.
     * For all tickets of specified status, it checks if any thread contains a possible UTR and updates ticket field
     * @return void
     */
    public static function check_if_payment_utr_input()
    {   // For all tickets of specified status, it checks if any thread contains a possible UTR and updates ticket field
        // get all tickets that have payment status as shown. 

        return;

        $tickets = self::get_all_active_tickets_by_status_name('Admission Payment Order Being Created');

        foreach ($tickets as $ticket)
        {
            $payment_bank_reference = self::get_ticket_value_given_cf_name( $ticket, 'payment-bank-reference');

                if ( ! empty($payment_bank_reference) )
                {
                    continue; // skip this ticket
                }

            // initialize $utr for each ticket so as not to carry over from previous tickets
            $utr = null;

            // get the  ticket history of this ticket
            $threads = self::get_ticket_threads($ticket->id);

            // process all threads of this ticket
            foreach ($threads as $thread)
            {
                $thread_content = strip_tags($thread->post_content);
                if ($thread_content)
                {
                    // null value returned if utr doesnt exist in this thread
                    $utr_thisthread = self::extract_utr($thread_content);
                }

                if ($utr_thisthread)
                {
                    $utr = $utr_thisthread;
                }
                // check next thread and update utr if present
            }
            
            // if $utr is not null from above processing of threads update this ticket provided existing value for field is empty
            if ( $utr )
            {   
                self::change_ticket_field( $ticket->id, $utr );
            }
        }
    }

        
    

    /**
     * @param integer:$ticket_id
     * @param array:$threads
     */
    public function get_ticket_threads( int $ticket_id): ?array
    {   // returns all threads of a ticket from the given ticket_id
        $threads = get_posts(array(
            'post_type'      => 'wpsc_ticket_thread',
            'post_status'    => 'publish',
            'posts_per_page' => '1',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => array(
                                        'relation' => 'AND',
                                        array(
                                            'key'     => 'ticket_id',
                                            'value'   => $ticket_id,
                                            'compare' => '='
                                            ),
                                        array(
                                            'key'     => 'thread_type',
                                            'value'   => 'reply',
                                            'compare' => '='
                                            ),
                                    ),
            )
        );

        return $threads;
    }

    /**
     * @return void
     * Get a list of all tickets that have error's while creating payment orders
     * The errors could be due to server down issues or data setup issues
     * For each ticket get the user details and create a payment order in the hset-payments site
     * After successful completion, change the status of the ticket appropriately
     */

    public function trigger_sritoni_account_creation_for_error_tickets()
    {   // for all tickets with error status for sritoni, initiate an update-create sritoni account again
        global $wpscfunction, $wpdb;

        // get all tickets that have payment status as shown. 
        $tickets = $this->get_all_active_tickets_by_status_slug('error-creating-sritoni-account');

        foreach ($tickets as $ticket):
        
            $ticket_id = $ticket->id;

            $this->prepare_and_create_or_update_moodle_account($ticket_id);
            
        endforeach;
    }


    /**
     * @return nul
     * Get a list of all tickets that have error's while creating payment orders
     * The errors could be due to server down issues or data setup issues
     * For each ticket get the user details and create a payment order in the hset-payments site
     * After successful completion, change the status of the ticket appropriately
     */

    public function trigger_payment_order_for_error_tickets()
    {
        global $wpscfunction, $wpdb;

        // get all tickets that have payment status as shown. 
        $tickets = $this->get_all_active_tickets_by_status_slug('error-creating-payment-shop-order');

        foreach ($tickets as $ticket):
        
            $ticket_id = $ticket->id;

            $this->create_payment_shop_order($ticket_id);
            
        endforeach;
    }

    


    /**
     * 
     */

    public function test_woocommerce_customer($customer_id)
    {
        $wpuserobj = $this->get_wp_user_hset_payments("sritoni2@headstart.edu.in", $customer_id);

        echo "<pre>" . print_r($wpuserobj, true) ."</pre>";
    }



    public static function test_custom_code($ticket_id)
    {
        // TEst out our custom code here. Input is just an integer from the form inpot
        // Get the logged in user's ID
        $current_user = wp_get_current_user();

        $email = 'cutelabmallika@gmail.com';

        // Check to see if already a customer in Support Candy table
        $customer = WPSC_Customer::get_by_email( $email  );

        echo "<pre>" . print_r($customer, true) ."</pre>";

        if ( ! $customer )
        {
            echo "<pre>" . print( 'This customer does not exist in the customer table of Support Candy') ."</pre>";
        }


    }

    public function test_sritoni_connection( string $moodle_username )
    {
        $this->get_config();
        // read in the Moodle API config array
        $config			= $this->config;
        $moodle_url 	= $config["moodle_url"] . '/webservice/rest/server.php';
        $moodle_token	= $config["moodle_token"];

        // prepare the Moodle Rest API object
        $MoodleRest = new MoodleRest();
        $MoodleRest->setServerAddress($moodle_url);
        $MoodleRest->setToken( $moodle_token ); // get token from ignore_key file
        $MoodleRest->setReturnFormat(MoodleRest::RETURN_ARRAY); // Array is default. You can use RETURN_JSON or RETURN_XML too.
        // $MoodleRest->setDebug();
        // get moodle user details associated with this completed order from SriToni
        $parameters   = array("criteria" => array(array("key" => "id", "value" => $moodle_id)));

        // get moodle user satisfying above criteria
        $moodle_users = $MoodleRest->request('core_user_get_users', $parameters, MoodleRest::METHOD_GET);
        if ( !( $moodle_users["users"][0] ) )
        {
            // failed to communicate effectively to moodle server so exit
            echo nl2br("couldn't communicate to moodle server. \n");
            return;
        }
        echo "<h3>Connection to moodle server was successfull: Here are the details of Moodle user object for id:" . $moodle_id ."</h3>";
        $moodle_user   = $moodle_users["users"][0];
	    echo "<pre>" . print_r($moodle_user, true) ."</pre>";
    }

    private function test_cashfree_connection($moodle_id)
    {
        // since wee need to interact with Cashfree , create a new API instamve.
        // this will also take care of getting  your API creedentials automatically.
        // the configfile path must always be the plugin name
        $configfilepath  = $this->plugin_name . "_config.php";
        $cashfree_api    = new CfAutoCollect($configfilepath); // new cashfree Autocollect API object

        $vAccountId = str_pad($moodle_id, 4, "0", STR_PAD_LEFT);

        // $va_id = "0073";	// VAID of sritoni1 moodle1 user

        // So first we get a list of last 3 payments made to the VAID contained in this HOLD order
        $payments        = $cashfree_api->getPaymentsForVirtualAccount($vAccountId, 1);

        
        echo "<h3> Payments made by userid: " . $vAccountId . "</h3>";
        echo "<pre>" . print_r($payments, true) ."</pre>";

        echo "<h3> PaymentAccount details of userid: " . $vAccountId . "</h3>";
        $vAccount = $cashfree_api->getvAccountGivenId($vAccountId);
        echo "<pre>" . print_r($vAccount, true) ."</pre>";
    }

    

 


    /**
     *
     */
    private function test_update_wc_product()
    {
        // run this since we may be changing API keys. Once in production remove this
        $this->get_config();

        $ticket_id = 23;

        $this->get_data_object_from_ticket($ticket_id);

        $data_object = $this->data_object;

        // instantiate woocommerce API class
        $woocommerce = new Client(
                                    'https://sritoni.org/hset-payments/',
                                    $this->config['wckey'],
                                    $this->config['wcsec'],
                                    [
                                        'wp_api'            => true,
                                        'version'           => 'wc/v3',
                                        'query_string_auth' => true,

                                    ]);

        // Admission fee to HSET product ID
        $product_id = 581;

        $endpoint   = "products/" . $product_id;

        $product_data = [
                            'name'          => $data_object->product_customized_name,
                            'regular_price' => $data_object->admission_fee_payable
                        ];

        $product = $woocommerce->put($endpoint, $product_data);
        echo "<pre>" . print_r($product, true) ."</pre>";
    }



    private function test_create_wc_order($ticket_id)
    {
        // $ticket_id = 3;

        $this->get_data_object_from_ticket($ticket_id);

        $order_created = $this->create_wc_order_site_hsetpayments();

        echo "<pre>" . print_r($order_created, true) ."</pre>";
    }



    private function test_get_data_object_from_ticket($ticket_id)
    {
        // $ticket_id = 8;

        $data_object = $this->get_data_object_from_ticket($ticket_id);

        echo "<pre>" . print_r($data_object, true) ."</pre>";

        $category_id = $data_object->ticket_meta['ticket_category'];

        // from category ID get the category name
        $term = get_term_by('id',$category_id,'wpsc_categories');
        echo "<pre>" . "Category slug and category name - " . $term->slug . " : " . $term->name . "</pre>";
    }




    private function test_sritoni_account_creation()
    {
        $ticket_id = 3;

        $this->get_data_object_from_ticket($ticket_id);

        $ret = $this->create_sritoni_account();

        echo "<pre>" . print_r($ret, true) ."</pre>";
    }

    /**
     *
     */
    private static function change_status_error_creating_payment_shop_order( $ticket_id, $error_message )
    {
        $new_status_name = "Error Creating Payment Shop Order";

        self::change_ticket_status( $ticket_id, $new_status_name );

        // update agent field error message with the passed in error message
        if ( ! empty($error_message) )
        {
            self::change_ticket_field( $ticket_id, 'error', $error_message );
        }
    }

    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     */
    private static function change_status_error_creating_sritoni_account( int $ticket_id,  string $error_message ):void
    {
        $new_status_name = "Error Creating SriToni Account";

        self::change_ticket_status( $ticket_id, $new_status_name );

        // update agent field error message with the passed in error message
        if (!empty($error_message))
        {
            self::change_ticket_field( $ticket_id, 'error', $error_message );
        }

    }


    /**
     *  VISUALLY CHECKED for SC 3.0 compatibility
     * 
     *  @param integer:$order_id
     *  @return object:$order
     * Uses the WooCommerce API to get back the order object for a given order_id
     * It prints outthe order object but this is only visible in a test page and gets overwritten by a short code elsewhere
     */
    public static function get_wc_order( int $order_id): ? object
    {
        // instantiate woocommerce API class
        $woocommerce = new Client(
                                    'https://sritoni.org/hset-payments/',
                                    self::$config['wckey'],
                                    self::$config['wcsec'],
                                    [
                                        'wp_api'            => true,
                                        'version'           => 'wc/v3',
                                        'query_string_auth' => true,

                                    ]);


        $endpoint   = "orders/" . $order_id;
        $params     = array($order_id);
        $order      = $woocommerce->get($endpoint);

        echo "<pre>" . print_r($order, true) ."</pre>";

        return $order;
    }



    

    /**
     *  Since SUpport Candy update 3.0 the ueser cannot set the slug of the custom field. So we are forced to use the status name instead
     *  @param string:$status_name
     *  @return array:$tickets
     */
    public static function get_all_active_tickets_by_status_name( $status_name ) : array
    {
        $tickets = [];  // initialize empty array

        // first we need to get the id of the desired status based on its name passed in as the parameter
        $status_id_of_ticket = self::get_status_id_given_name( $status_name );

        $filter_array = array(
                                'meta_query' => array(
                                                        'relation' => 'AND',
                                                        array(
                                                            'slug'    => 'status',
                                                            'compare' => '=',
                                                            'val'     => $status_id_of_ticket,
                                                        ),
                            )
        );
    
        $tickets = WPSC_Ticket::find( $filter_array );

        return $tickets;
    }
    
    
    
    
    /**
     *  NOT USED (For old version of Support Candy pre 3.0)
     */
    private function get_ticket_meta_key_by_slug($slug)
    {
        $fields = get_terms([
            'taxonomy'   => 'wpsc_ticket_custom_fields',
            'hide_empty' => false,
            'orderby'    => 'meta_value_num',
            'meta_key'	 => 'wpsc_tf_load_order',
            'order'    	 => 'ASC',

            'meta_query' => array(
                                    array(
                                        'key'       => 'agentonly',
                                        'value'     => [0, 1],  // get all ticket meta fields
                                        'compare'   => 'IN',
                                        ),
                                ),

        ]);
        foreach ($fields as $field)
        {
            if ($field->slug == $slug)
            {
                return $field->term_id;
            }
        }
    }


    /**
     *  NOT USED
     *  The AJAX in the form was not working correctly, so abandoned
     *  @param array:$form_data is the form data Ninja forms
     *  1. if category contains internal then the email must contain headstart.edu.in, otherwise form should be corrected
     */
    public function action_validate_ninja_form_data( $form_data )
    {
        // extract the fields array from the form data
        $fields_ninjaforms = $form_data['fields'];

        // extract a single column from all fields containing the admin_label key
        $key_array         = array_column($fields_ninjaforms, 'key');

        // extract the corresponding value array. They both will share the same  numerical index.
        $value_array       = array_column($fields_ninjaforms, 'value');

        $field_id_array    = array_column($fields_ninjaforms, 'id');

        // Check if form category contains internal or not. The category is a hidden field
        // look for the mapping slug in the ninja forms field's admin label
        $index_ticket_category  = $this->array_search_partial( $key_array, 'ticket_category' );
        $category_name          = $value_array[$index_ticket_category];

        $index_address  = $this->array_search_partial( $key_array, 'residential_address' );
        $address        = $value_array[$index_address];

        $index_email    = $this->array_search_partial( $key_array, 'primary_email' );
        $email          = $value_array[$index_email];


        if ( stripos($category_name, "internal") !== false )
        {
            // the forms's hidden field for category does contain substring internal so we need to check  for headstart domain
            // look for the mapping slug in the ninja forms field's admin label for email field

            // check if the email contains headstart.edu.in
            if ( stripos( $email, "headstart.edu.in") === false)
            {
                $this->verbose ? error_log("validating email - Internal user, expecting @headstart.edu.in, didnt find it"): false;
                // our form's category is internal but does not contain desired domain so flag an error in form

                //
                $form_data['errors']['fields'][$field_id_array[$index_email]] = 'Email must be of Head Start domain, because continuing student';
            }

        }
        if ( stripos($address, "/") !== false )
        {
            $this->verbose ? error_log("validating address - does contain forbidden character '/'."): false;

            $errors = [
                'fields' => [
                $field_id_array[$index_address]   => 'Addtress must not contain "/" please correct'
                ],
              ];

            $response = [
            'errors' => $errors,
            ];
            
            wp_send_json( $response );
            wp_die(); // this is required to terminate immediately and return a proper response
        }
        
        return $form_data;
    }


    /**
     *  NOT USED
     * 
     *  Was meant to be used when a given ticket field was updated and some action needed to be taken on that
     */
    public function action_on_ticket_field_changed($ticket_id, $field_slug, $field_val, $prev_field_val)
    {
        // we are only interested if the field that changed is non-empty payment-bank-reference. For all others return
        if ($field_slug !== "payment-bank-reference" || empty($field_val)) return;

        // if you get here this field is payment-bank-reference ad the value is non-empty
        // so we can go ahead and change the associated order's meta value to this new number
        // get the order-id for this ticket. If it doesn't exist then return
        $this->get_data_object_from_ticket($ticket_id);

        $order_id = $this->data_object->ticket_meta['order-id'];

        if (empty($order_id)) return;

        // check status of order to make sure it is still ON-HOLD
        $order = $this->get_wc_order($order_id);

        if (empty($order)) return;

        if ($order->status == "completed"  || $order->status == "processing")
        {
            return;
        }
        else
        {
            // lets now prepare the data for the new order to be created
            $order_data = [
                            'meta_data' => [
                                                [
                                                    'key' => 'bank_reference',
                                                    'value' => $field_val
                                                ]
                                            ]
                        ];
            $order_updated = $this->update_wc_order_site_hsetpayments($order_id, $order_data);

            $this->verbose ? error_log("Read back bank reference from Updated Order ID:" . $order_id . " is: " . $order_updated->bank_reference): false;
        }
    }



}   // end of class bracket

headstart_admission::init();
