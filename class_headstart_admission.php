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
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @author     Madhu Avasarala
 */

//setup for the WooCommerce REST API
require __DIR__ . '/vendor/autoload.php';

use Automattic\WooCommerce\Client;

class class_headstart_admission
{
	// The loader that's responsible for maintaining and registering all hooks that power
	protected $loader;

	// The unique identifier of this plugin.
	protected $plugin_name;

	 // The current version of the plugin.
	protected $version;

    //
    protected $config;

    /**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 */
	public function __construct()
    {
		if ( defined( 'HEADSTART_ADMISSION_VERSION' ) )
        {
			$this->version = HEADSTART_ADMISSION_VERSION;
		} else
        {
			$this->version = '1.0.0';
		}

		$this->plugin_name = 'headstart_admission';

		if (is_admin()) $this->define_admin_hooks();

		$this->define_public_hooks();
        $this->get_config();

	}

    /**
     *  reads in a config php file and gets the API secrets. The file has to be in gitignore and protected
     */
    private function get_config()
    {
      $this->config = include( __DIR__."/" . $this->plugin_name . "_config.php");
    }

    /**
     * Define all of the admin facing hooks and filters required for this plugin
     * @return null
     */
    private function define_admin_hooks()
    {
        // create a sub menu called Admissions in the Users menu
        add_action('admin_menu', [$this, 'add_my_menu']);
    }

    /**
     * Define all of the public facing hooks and filters required for this plugin
     * @return null
     */
    private function define_public_hooks()
    {
        // on admission form submission update user meta with form data
        // add_action( 'wpsc_ticket_created', [$this, 'update_user_meta_form'], 10, 1 );

        // do_action('wpsc_set_change_status', $ticket_id, $status_id, $prev_status);
        add_action('wpsc_set_change_status', [$this, 'action_on_ticket_status_changed'], 10,3);

        // after a NInja form submission, its data is mapped to a support ticket
        // This is the principal source of data for subsequent actions such as account creation
        add_action( 'ninja_forms_after_submission', [$this, 'map_ninja_form_to_ticket'] );

    }

    public function action_after_login($user_login, $user)
    {
        // check if user has a headstart mail. If not return;
        if (stripos($user->data->user_email, "headstart.edu.in") === false)
        {
            return;
        }
        // so we have a user who has logged in usinh a headstart emailID.
        // we have a chance to get the user's SRiToni data if needed to prefill forms with etc.
    }

    /**
     *  @return nul Nothing is returned
     *  The function takes the Ninja form immdediately after submission.
     *  The form data is captured into the fields of a new ticket that is to be created as a result of this submission.
     *  Ensure that the data captured into the ticket is adequate for creating a new Payment Shop Order
     *  and for creating  a new SriToni user account
     */

    public function map_ninja_form_to_ticket( $form_data )
    {
        global $wpscfunction;

        // $form_data['fields']['id']['seetings']['admin_label']
        // $form_data['fields']['id'][''value']
        // Loop through each of the ticket fields, match its slug to the admin_label and get its corresponding value

        // Initialize the new ticket values array needed for a new ticket creation
        $ticket_args = [];  

        // extract the fields array from the form data
        $fields_ninjaforms = $form_data['fields'];

        // extract a single column from all fields containing the admin_label key
        $admin_label_array = array_column(array_column($fields_ninjaforms, 'settings'), 'admin_label');

        // extract the corresponding value array. They both will share the same  numerical index.
        $value_array       = array_column(array_column($fields_ninjaforms, 'settings'), 'value');

        // get the ticket field objects using term search. We are getting only the non-agent ticket fields here for mapping
        $ticket_fields = get_terms([
            'taxonomy'   => 'wpsc_ticket_custom_fields',
            'hide_empty' => false,
            'orderby'    => 'meta_value_num',
            'meta_key'	 => 'wpsc_tf_load_order',
            'order'    	 => 'ASC',
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key'       => 'agentonly',
                    'value'     => '0',
                    'compare'   => '='
                ),
            )
        ]);

        foreach ($ticket_fields as $ticket_field):
        
            if ($ticket_field->slug == 'ticket_priority' || 
                $ticket_field->slug == 'wp-user-id-hset-payments')
            {
                continue;     // we don't modify this field in the ticket - unused.
            } 

            // capture the ones of interest to us
            switch (true):
                
                // customer_name ticket field mapping.
                case ($ticket_field->slug == 'customer_name'):

                    // look for the mapping slug in the ninja forms field's admin label
                    $key = array_search('customer-name', $admin_label_array);
                
                    $ticket_args[$ticket_field->slug]= $value_array[$key];

                    break;



                    // ticket_category field mapping. The slug has to pre-eexist with an id.
                case ($ticket_field->slug == 'ticket_category'):

                    // look for the mapping slug in the ninja forms field's admin label
                    $key = array_search('ticket_category', $admin_label_array);

                    $category_name = $value_array[$key];

                    // now to get the category id using the slug we got from the ninja form field
                    $term = get_term_by('slug', $category_name, 'wpsc_categories');

                    // we give the category's term_id, not its name, when we create the ticket.
                    $ticket_args[$ticket_field->slug]= $term->term_id;

                    break;



                    // customer email ticket field mapping.
                case ($ticket_field->slug == 'customer_email'):

                    // look for the mapping slug in the ninja forms field's admin label
                    $key = array_search('email', $admin_label_array);

                    $ticket_args[$ticket_field->slug]= $value_array[$key];

                    break;


                        // the subject is fixed to Admission
                    case ($ticket_field->slug == 'ticket_subject'):

                        // default for all users
                        $ticket_args[$ticket_field->slug]= 'Admission';
    
                        break;

                        // Description is a fixed string
                    case ($ticket_field->slug == 'ticket_description'):

                        // default for all users
                        $ticket_args[$ticket_field->slug]= 'Admission';
    
                        break;    


                        // Student's first name
                    case ($ticket_field->slug == 'student-first-name'):

                        // look for the mapping slug in the ninja forms field's admin label
                        $key = array_search('student-first-name', $admin_label_array);

                        $ticket_args[$ticket_field->slug]= $value_array[$key];

                        break;



                    // Student's last name
                    case ($ticket_field->slug == 'student-last-name'):

                        // look for the mapping slug in the ninja forms field's admin label
                        $key = array_search('student-last-name', $admin_label_array);

                        $ticket_args[$ticket_field->slug]= $value_array[$key];

                        break;



                    // student's middle name
                    case ($ticket_field->slug == 'student-middle-name'):

                        // look for the mapping slug in the ninja forms field's admin label
                        $key = array_search('student-middle-name', $admin_label_array);

                        $ticket_args[$ticket_field->slug]= $value_array[$key];

                        break;


                    
                    // student's date of birth in YYYY-mm-dd format
                    case ($ticket_field->slug == 'date-of-birth'):

                        // look for the mapping slug in the ninja forms field's admin label
                        $key = array_search('date-of-birth', $admin_label_array);

                        $ticket_args[$ticket_field->slug]= $value_array[$key];

                        break;

                    
                    // 
                    case ($ticket_field->slug == 'blood-group'):

                        // look for the mapping slug in the ninja forms field's admin label
                        $key = array_search('blood-group', $admin_label_array);

                        $ticket_args[$ticket_field->slug]= $value_array[$key];

                        break;

                    // 
                    case ($ticket_field->slug == 'address'):

                        // look for the mapping slug in the ninja forms field's admin label
                        $key = array_search('address', $admin_label_array);

                        $ticket_args[$ticket_field->slug]= $value_array[$key];

                        break;

                    // rest of the fields come here
                    
            
            endswitch;          // end switching throgh the ticket fields looking for a match

        endforeach;             // finish looping through the ticket fields for mapping Ninja form data to ticket

        // we have all the necessary ticket fields filled from the Ninja forms, now we can create a new ticket
        $ticket_id = $wpscfunction->create_ticket($ticket_args);
    }


    /**
     *
     */
    public function update_ticket_status_paid($ticket_id)
    {
        global $wpscfunction;

        // get the below value from the WP tables using Heidi. This is hard coded and needs to change accordingly
        // TODO get the status id just from slug rather than hard coding it here like this
        $status_id_order_completed = 136;   // this is the term_id and the slug is payment-process-completed

        $wpscfunction->change_status($ticket_id, $status_id_order_completed);
    }

    /**
     *  This is the  callback that triggers the various tasks contingent upon ticket status change to desired one
     *  When the status changes to Admission Granted, the payment process is triggered immediately
     *  When the ststus is changed to Admission Confirmed the SriToni new user account creation is triggered
     *  More can be added here as needed.
     */
    public function action_on_ticket_status_changed($ticket_id, $status_id, $prev_status)
    {
        global $wpscfunction;

        // buuild an object containing all relevant data from ticket useful for crating user accounts and payments
        $this->get_data_for_sritoni_account_creation($ticket_id);

        // add any logoc that you want here based on new status
        switch (true)
        {
            case ($wpscfunction->get_status_name($status_id) === 'Admission Granted'):
                // status changed to Admission Granted.
                // The payment process needs to be triggered

                // if the applicant email is a headstart one, then we will use the VA details for the payment
                $this->data_object->wp_user_hset_payments = $this->get_wp_user_hset_payments();


                $new_order = $this->create_wc_order_hset_payments();

                // TO DO: pdate the agent field with the newly created order ID

                break;

            case ($wpscfunction->get_status_name($status_id) === 'Admission Confirmed'):
                
                $this->create_sritoni_account();

                break;


            default:
                // error_log("No, the changed status has NOT triggered any action for ticket ID: , " . $ticket_id);
                break;
        }
    }


    private function get_wp_user_hset_payments()
    {
        if (stripos($data_object->ticket_data["customer_email"], 'headstart.edu.in') !== false)
        {
            // this user has a headstart account. Get the wp userid from the hset-payments site
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


            $endpoint   = "customers";

            $customers = $woocommerce->get($endpoint, array(
                                                            'role'  =>'subscriber',
                                                            'email' => $data_object->ticket_data["customer_email"]));

            $customer = $customers[0];

            // now let us check to see if this iser has a valid va_id.
            $array_va_id = array_column($customer->meta_data, "va_id");
        }
        else
        {
            // we use the default id=5 for non-headstart admission payments
            return null;
        }
    }

    /**
     *
     */
    public function add_my_menu()
    {
        add_submenu_page(
            'users.php',	                    // string $parent_slug
            'Admissions',	                    // string $page_title
            'Admissions',                       // string $menu_title
            'manage_options',                   // string $capability
            'admissions',                       // string $menu_slug
            [$this, 'render_admissions_page'] );// callable $function = ''

        // add submenu page for testing various application API needed for SriToni operation
        add_submenu_page(
            'users.php',	                     // parent slug
            'SriToni Tools',                     // page title
            'SriToni Tools',	                 // menu title
            'manage_options',	                 // capability
            'sritoni-tools',	                 // menu slug
            [$this, 'sritoni_tools_render']);    // callback
    }

    /**
     *
     */
    public function render_admissions_page()
    {
        echo "This is the admissions page where stuff about admissions is displayed";
    }

    public function sritoni_tools_render()
    {
        // this is for rendering the API test onto the sritoni_tools page
        ?>
            <h1> Click on button to test corresponding Server connection and API</h1>
            <form action="" method="post" id="form1">
                <input type="submit" name="button" 	value="test_SriToni_connection"/>
                <input type="submit" name="button" 	value="test_cashfree_connection"/>
                <input type="submit" name="button" 	value="test_woocommerce_customer"/>
                <input type="submit" name="button" 	value="test_available1"/>
                <input type="submit" name="button" 	value="test_get_wc_order"/>
                <input type="submit" name="button" 	value="test_update_wc_product"/>
                <input type="submit" name="button" 	value="test_create_wc_order"/>
                <input type="submit" name="button" 	value="test_get_data_for_sritoni_account_creation"/>
                <input type="submit" name="button" 	value="test_sritoni_account_creation"/>
                <input type="submit" name="button" 	value="test_custom_code"/>
            </form>


        <?php

        $button = sanitize_text_field( $_POST['button'] );
        switch ($button)
        {
            case 'test_SriToni_connection':
                $this->test_sritoni_connection();
                break;

            case 'test_cashfree_connection':
                $this->test_cashfree_connection();
                break;

            case 'test_woocommerce_customer':
                $this->test_woocommerce_customer();
                break;

            case 'test_available2':
                $this->test_get_ticket_data();
                break;

            case 'test_get_wc_order':
                $order_id = "590";
                $this->get_wc_order($order_id);
                break;

            case 'test_update_wc_product':
                $this->test_update_wc_product();
                break;

            case 'test_create_wc_order':
                $this->test_create_wc_order();
                break;

            case 'test_get_data_for_sritoni_account_creation':
                $this->test_get_data_for_sritoni_account_creation();
                break;

            case 'test_sritoni_account_creation':
                $this->test_sritoni_account_creation();
                break;

                case 'test_custom_code':
                    $this->test_custom_code();
                    break;

            default:
                // do nothing
                break;
        }
    }

    public function test_woocommerce_customer()
    {
        if (stripos('aadhya.hibare@headstart.edu.in', 'headstart.edu.in') !== false)
        {
            // this user has a headstart account. Get the wp userid from the hset-payments site
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


            $endpoint   = "customers";

            $params = array(
                                "role"  => "subscriber",
                                "email" => "sriton21@headstart.edu.in",
                            );

            $customers = $woocommerce->get($endpoint, $params);

            $array_va_id_key = array_column($customers[0]->meta_data, "key");
            $array_va_id_value = array_column($customers[0]->meta_data, "value");

            $index = array_search("va_id", $array_va_id_key);
            $va_id = $array_va_id_value[$index];


            echo "<pre>" . print_r($customers[0], true) ."</pre>";   
            echo "<pre>" . print_r($va_id, true) ."</pre>";                       

        }
    }

    public function test_custom_code()
    {
        global $wpscfunction;
        echo "<h1>" . "List of ALL Ticket custom fields" . "</h1>";

        $custom_fields = get_terms([
            'taxonomy'   => 'wpsc_ticket_custom_fields',
            'hide_empty' => false,
            'orderby'    => 'meta_value_num',
            'meta_key'	 => 'wpsc_tf_load_order',
            'order'    	 => 'ASC',
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key'       => 'agentonly',
                    'value'     => '0',
                    'compare'   => '='
                ),
            )
        ]);
        echo "<pre>" . print_r($custom_fields, true) ."</pre>";

        $category_ids = array();
        $categories = get_terms([
        'taxonomy'   => 'wpsc_categories',
        'hide_empty' => false,
        'orderby'    => 'meta_value_num',
        'order'    	 => 'ASC',
        'meta_query' => array('order_clause' => array('key' => 'wpsc_category_load_order')),
        ]);
        echo "<pre>" . print_r($categories, true) ."</pre>";
    }

    public function test_sritoni_connection()
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
        $parameters   = array("criteria" => array(array("key" => "id", "value" => 73)));

        // get moodle user satisfying above criteria
        $moodle_users = $MoodleRest->request('core_user_get_users', $parameters, MoodleRest::METHOD_GET);
        if ( !( $moodle_users["users"][0] ) )
        {
            // failed to communicate effectively to moodle server so exit
            echo nl2br("couldn't communicate to moodle server. \n");
            return;
        }
        echo "<h3>Connection to moodle server was successfull: Here are the details of Moodle user object for id:73</h3>";
        $moodle_user   = $moodle_users["users"][0];
	    echo "<pre>" . print_r($moodle_user, true) ."</pre>";
    }

    private function test_cashfree_connection()
    {
        // since wee need to interact with Cashfree , create a new API instamve.
        // this will also take care of getting  your API creedentials automatically.
        // the configfile path must always be the plugin name
        $configfilepath  = $this->plugin_name . "_config.php";
        $cashfree_api    = new CfAutoCollect($configfilepath); // new cashfree Autocollect API object

        $va_id = "0073";	// VAID of sritoni1 moodle1 user

        // So first we get a list of last 3 payments made to the VAID contained in this HOLD order
        $payments        = $cashfree_api->getPaymentsForVirtualAccount($va_id, 1);
        echo "<h3> Payments made by userid 0073:</h3>";
        echo "<pre>" . print_r($payments, true) ."</pre>";

        echo "<h3> PaymentAccount details of userid 0073:</h3>";
        $vAccount = $cashfree_api->getvAccountGivenId($va_id);
        echo "<pre>" . print_r($vAccount, true) ."</pre>";
    }

    public function update_user_meta_form($ticket_id)
    {
        //
    }


    /**
     *  This is called after the create_account object has been already created  so need to call it.
     */
    private function create_sritoni_account()
    {
        // before coming here the create account object is already created. We jsut use it here.
        $data_object = $this->data_object;

        if (!empty($data_object->existing_sritoni_username) || !empty($data_object->existing_sritoni_idnumber))
        {
            return;
        }

        // if you get here, you DO NOT have a username and DO NOT have an idnumber

        // run this again since we may be changing API keys. Once in production remove this
        $this->get_config();

        // read in the Moodle API config array
        $config			= $this->config;
        $moodle_url 	= $config["moodle_url"] . '/webservice/rest/server.php';
        $moodle_token	= $config["moodle_token"];

        $moodle_username = $data_object->username;

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

        if ( ( $moodle_users["users"][0] ) )
        {
            // An account with this user already exssts. So add  a number to the username and retry
            for ($i=0; $i < 5; $i++)
            {
                $moodle_username = $data_object->username . $i;
                $parameters   = array("criteria" => array(array("key" => "username", "value" => $moodle_username)));
                $moodle_users = $MoodleRest->request('core_user_get_users', $parameters, MoodleRest::METHOD_GET);
                if ( !( $moodle_users["users"][0] ) )
                {
                    // we can use this username, it is not taken
                    break;
                }

                $error_message = "Couldnt find username, the account exists for upto username + 4 ! check and retry change of status";

                error_log($error_message);

                // change the ticket status to error
                $this->change_status_error_creating_sritoni_account($data_object->ticket_id, $error_message);

                return;
            }

        // came out the for loop with a valid user name that can be created
        }

        // if you are here it means you came here after breaking through the forloop above
        // so create a new moodle user account with the successful username that has been incremented

        // write the data back to Moodle using REST API
        // create the users array in format needed for Moodle RSET API
        $fees_array = [];
        $fees_json  = json_encode($fees_array);

    	$users = array("users" => array(
                                            array(	"username" 	    => $moodle_username,
                                                    "idnumber"      => $data_object->idnumber,
                                                    "auth"          => "oauth2",
                                                    "firstname"     => $data_object->student_firstname,
                                                    "lastname"      => $data_object->student_lastname,
                                                    "email"         => $moodle_username . "@headstart.edu.in",
                                                    "middlename"    => $data_object->student_middlename,
                                                    "institution"   => $data_object->institution,
                                                    "department"    => $data_object->department,
                                                    "phone1"        => $data_object->principal_phone_number,
                                                    "address"       => $data_object->student_address,
                                                    "maildisplay"   => 0,
                                                    "createpassword"=> 0,

                                                    "customfields" 	=> array(
                                                                                array(	"type"	=>	"class",
                                                                                        "value"	=>	$data_object->class,
                                                                                    ),
                                                                                array(	"type"	=>	"environment",
                                                                                        "value"	=>	$data_object->environment,
                                                                                    ),
                                                                                array(	"type"	=>	"studentcat",
                                                                                        "value"	=>	$data_object->studentcat,
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
            // the returned user has same name as one given to create new user so OK
            return $ret[0]['id'];
        }
        else
        {
            error_log("Create new user didnt return expected username: " . $moodle_username);
            error_log(print_r($ret, true));

            // change the ticket status to error
            $this->change_status_error_creating_sritoni_account($data_object->ticket_id, $ret["message"]);

            return null;
        }

    }

    /**
     *  Creates a data object from a given ticket_id. Thhis is used for creating orders, user accounts etc.
     *  make sure to run $this->get_data_for_sritoni_account_creation($ticket_id) before calling this  method
     *  @return obj:$order_created
     */
    private function create_wc_order_hset_payments()
    {
        // before coming here the create account object is already created. We jsut use it here.
        $data_object = $this->data_object;
        $customer_id = $this->data_object->wp_user_hset_payments->id    ?? 5;
        $va_id       = $this->data_object->wp_user_hset_payments->va_id ?? "0073";

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
        
        // Admission fee to HSET product ID. This is the admission product whose price and description can be customized
        $product_id = 581;

        $endpoint   = "products/" . $product_id;

        // customize the Admission product for this user
        $product_data = [
                            'name'          => get_term_by('slug', $data_object->ticket_field["product_customized_name"], true),
                            'regular_price' => get_term_by('slug', $data_object->ticket_field["admission-fee-payable"], true),
                        ];
        $product = $woocommerce->put($endpoint, $product_data);

        // lets now prepare the data for the new order to be created
        $order_data = [
            'customer_id'           => $customer_id,
            'payment_method'        => 'vabacs',
            'payment_method_title'  => 'Offline Direct bank transfer to Head Start Educational Trust',
            'set_paid'              => false,
            'status'                => 'on-hold',
            'billing' => [
                'first_name'    => $data_object->customer_name,
                'last_name'     => '',
                'address_1'     => $data_object->student_address,
                'address_2'     => '',
                'city'          => 'Bangalore',
                'state'         => 'Karnataka',
                'postcode'      => $data_object->student_pin,
                'country'       => 'India',
                'email'         => $data_object->customer_email,
                'phone'         => $data_object->principal_phone_number,
            ],
            'shipping' => [
                'first_name'    => $data_object->customer_name,
                'last_name'     => '',
                'address_1'     => $data_object->student_address,
                'address_2'     => '',
                'city'          => 'Bangalore',
                'state'         => 'Karnataka',
                'postcode'      => $data_object->student_pin,
                'country'       => 'India'
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
                    'value' => $data_object->student_fullname,
                ],
                [
                    'key' => 'payer_bank_account_number',
                    'value' => $data_object->payer_bank_account_number,
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

        return $order_created;
    }

    /**
     * This function grabs the ticket fields and data from a given ticket id
     * It then creates a new data_object that contains all of the ticket data, to be used anywhere needed
     * This data_object is also set as a property of this
     *  @param str:$ticket_id
     *  @return obj:$data_object
     */

    private function get_data_for_sritoni_account_creation($ticket_id)
    {
        global $wpscfunction;

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
        $ticket_fields = [];

        foreach ($fields as $field)
        {
            if ($field->slug == "ticket_category"   ||
                $field->slug == "ticket_status"     ||
                $field->slug == "ticket_priority"   ||
                $field->slug == "ticket_subject"    ||
                $field->slug == "customer_name"     ||
                $field->slug == "customer_email"    ||
                $field->slug == "ticket_email"
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
     *  @param int:$order_id
     *  @return obj:$order
     * Uses the WooCommerce API to get back the order object for a given order_id
     * It prints outthe order object but this is only visible in a test page and gets overwritten by a short code elsewhere
     */

    private function get_wc_order($order_id)
    {
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
        

        $endpoint   = "orders/" . $order_id;
        $params     = array($order_id);
        $order      = $woocommerce->get($endpoint);

        echo "<pre>" . print_r($order, true) ."</pre>";

        return $order;
    }


    /**
     * 
     */
    private function test_update_wc_product()
    {
        // run this since we may be changing API keys. Once in production remove this
        $this->get_config();

        $ticket_id = 3;

        $this->get_data_for_sritoni_account_creation($ticket_id);

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



    private function test_create_wc_order()
    {
        $ticket_id = 3;

        $this->get_data_for_sritoni_account_creation($ticket_id);

        $order_created = $this->create_wc_order_hset_payments();

        echo "<pre>" . print_r($order_created, true) ."</pre>";
    }



    private function test_get_data_for_sritoni_account_creation()
    {
        $ticket_id = 8;

        $data_object = $this->get_data_for_sritoni_account_creation($ticket_id);

        echo "<pre>" . print_r($data_object, true) ."</pre>";

        $category_id = $data_object->ticket_meta['ticket_category'];

        // from category ID get the category name
        $term = get_term_by('id',$category_id,'wpsc_categories');
        echo "<pre>" . "Category slug and category name - " . $term->slug . " : " . $term->name . "</pre>";
    }

    private function test_sritoni_account_creation()
    {
        $ticket_id = 3;

        $this->get_data_for_sritoni_account_creation($ticket_id);

        $ret = $this->create_sritoni_account();

        echo "<pre>" . print_r($ret, true) ."</pre>";
    }

    /**
     *
     */
    private function change_status_error_creating_sritoni_account($ticket_id, $error_message)
    {
        global $wpscfunction;

        $status_id = 95;    // corresponds to status error creating sritoni account

        $wpscfunction->change_status($ticket_id, $status_id);

        // update agent field error message with the passed in error message





    }

}   // end of class bracket
