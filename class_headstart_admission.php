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
        add_action( 'wpsc_ticket_created', [$this, 'update_user_meta_form'], 10, 1 );

        // do_action('wpsc_set_change_status', $ticket_id, $status_id, $prev_status);
        add_action('wpsc_set_change_status', [$this, 'action_on_ticket_status_changed'], 10,3);

        // what happens after
        add_action( 'ninja_forms_after_submission', 'my_ninja_forms_after_submission', 10, 1 );

    }

    public function my_ninja_forms_after_submission( $form_data )
    {
        error_log("logging form_data from Ninja forms just submitted");
        error_log(print_r($form_data));
    }


    /**
     *
     */
    public function update_ticket_status_paid($ticket_id)
    {
        global $wpscfunction;

        // get the below value from the WP tables using Heidi. This is hard coded and needs to change accordingly
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

        // error_log("ticket id: " . $ticket_id . " Previous status_id: " . $prev_status . " Current status: " . $status_id . "\n");

        // add any logoc that you want here based on status
        switch (true)
        {
            case ($wpscfunction->get_status_name($status_id) === 'Admission Granted'):
                // status changed to Admission Granted.
                // The payment process needs to be triggered
                // extract the ticket details and pass parameters to hset-payments site for order creation
                // error_log("yes, i came to the right place for Admission Granted for ticket ID: , " . $ticket_id);

                $new_order = $this->create_wc_order_hset_payments();

                // update the agent field with the newly created order ID

                break;

            case ($wpscfunction->get_status_name($status_id) === 'Admission Confirmed'):
                // status changed to Admission confirmed.
                // create a user account on SriToni for the user in this ticket
                // extract the ticket details and pass parameters to sritoni create account function
                // error_log("yes, i came to the right place for sritoni user creation for ticket ID: , " . $ticket_id);
                $this->create_update_sritoni_account();
                break;


            default:
                error_log("No, the changed status has NOT triggered any action for ticket ID: , " . $ticket_id);
                break;
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
                <input type="submit" name="button" 	value="test_get_ticket"/>
                <input type="submit" name="button" 	value="test_get_ticket_data"/>
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

            case 'test_get_ticket':
                $this->test_get_ticket();
                break;

            case 'test_get_ticket_data':
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

    private function update_user_meta_form($ticket_id)
    {
        //
    }

    public function test_get_ticket()
    {
        // tthe following piece of code gets executed and results displayed in the  SriToni tools page when button is pressed
        global $wpscfunction;
        $ticket_id = 3;
        $ticket_data = $wpscfunction->get_ticket($ticket_id);
        echo "<pre>" . print_r($ticket_data, true) ."</pre>";
    }

    public function test_get_ticket_data()
    {
        // the folowing piece of code dumps details of ticket id =1;
        global $wpscfunction;
        $ticket_id = 3;

        $fields = get_terms([
            'taxonomy'   => 'wpsc_ticket_custom_fields',
            'hide_empty' => false,
            'orderby'    => 'meta_value_num',
            'meta_key'	 => 'wpsc_tf_load_order',
            'order'    	 => 'ASC',

        ]);

        foreach ($fields as $field)
        {
            if (empty($field)) continue;
            $value = $wpscfunction->get_ticket_meta($ticket_id,$field->slug,true);

            echo nl2br($field->slug . ": " . $value . "\n");

        }
    }



    /**
     *  @return void:nul
     *  Creates a new account of not a existing user.
     *  If an existing user then just updates the SriToni user account with profiel details.
     */

    private function create_update_sritoni_account()
    {
        // before coming here the create account object is already created. We jsut use it here.
        $create_account_obj = $this->create_account_obj;

        if (empty($create_account_obj->existing_sritoni_username) && empty($create_account_obj->existing_sritoni_idnumber))
        {
            // New account needs to be created
            $this->create_sritoni_account();

            return;
        }
        else
        {
            // Account needs to be updated with data from form and agents
            $this->update_sritoni_account();

            return;
        }
    }

    /**
     *  This is called after the create_account object has been already created  so need to call it.
     */
    private function create_sritoni_account()
    {
        // run this again since we may be changing API keys. Once in production remove this
        $this->get_config();

        // read in the Moodle API config array
        $config			= $this->config;
        $moodle_url 	= $config["moodle_url"] . '/webservice/rest/server.php';
        $moodle_token	= $config["moodle_token"];

        $create_account_obj = $this->create_account_obj;

        $moodle_username = $create_account_obj->username;

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
                $moodle_username = $create_account_obj->username . $i;
                $parameters   = array("criteria" => array(array("key" => "username", "value" => $moodle_username)));
                $moodle_users = $MoodleRest->request('core_user_get_users', $parameters, MoodleRest::METHOD_GET);
                if ( !( $moodle_users["users"][0] ) )
                {
                    // we can use this username, it is not taken
                    break;
                }

                error_log("Couldnt find username, the account exists for upto username 4 ! check");

                // change the ticket status to error
                $this->change_ticket_status_to_error($create_account_obj->ticket_id);

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
                                                    "idnumber"      => $create_account_obj->idnumber,
                                                    "auth"          => "oauth2",
                                                    "firstname"     => $create_account_obj->student_firstname,
                                                    "lastname"      => $create_account_obj->student_lastname,
                                                    "email"         => $moodle_username . "@headstart.edu.in",
                                                    "middlename"    => $create_account_obj->student_middlename,
                                                    "institution"   => $create_account_obj->institution,
                                                    "department"    => $create_account_obj->department,
                                                    "phone1"        => $create_account_obj->principal_phone_number,
                                                    "address"       => $create_account_obj->student_address,
                                                    "maildisplay"   => 0,
                                                    "createpassword"=> 0,

                                                    "customfields" 	=> array(
                                                                                array(	"type"	=>	"class",
                                                                                        "value"	=>	$create_account_obj->class,
                                                                                    ),
                                                                                array(	"type"	=>	"environment",
                                                                                        "value"	=>	$create_account_obj->environment,
                                                                                    ),
                                                                                array(	"type"	=>	"studentcat",
                                                                                        "value"	=>	$create_account_obj->studentcat,
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
            $this->change_ticket_status_to_error($create_account_obj->ticket_id, $ret["message"]);

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
        // run this since we may be changing API keys. Once in production remove this
        $this->get_config();

        // before coming here the create account object is already created. We jsut use it here.
        $create_account_obj = $this->create_account_obj;

        // instantiate woocommerce API class
        $woocommerce = new Client(
                                    'https://sritoni.org/hset-payments/',
                                    $this->config['wckey'],
                                    $this->config['wcsec'],
                                    [
                                        'wp_api'            => true,
                                        'version'           => 'wc/v3',
                                        'query_string_auth' => true,

                                    ]
        );

        // Admission fee to HSET product ID. This is the admission product whose price and description can be customized
        $product_id = 581;

        $endpoint   = "products/" . $product_id;

        // customize the Admission product for this user
        $product_data = [
                            'name'          => $create_account_obj->product_customized_name,
                            'regular_price' => $create_account_obj->admission_fee_payable
                        ];
        $product = $woocommerce->put($endpoint, $product_data);

        // lets now prepare the data for the new order to be created
        $order_data = [
            'customer_id'           => 5,       // order assigned to user sritoni1 by this id. This is fixed
            'payment_method'        => 'vabacs',
            'payment_method_title'  => 'Offline Direct bank transfer to Head Start Educational Trust',
            'set_paid'              => false,
            'status'                => 'on-hold',
            'billing' => [
                'first_name'    => $create_account_obj->customer_name,
                'last_name'     => '',
                'address_1'     => $create_account_obj->student_address,
                'address_2'     => '',
                'city'          => 'Bangalore',
                'state'         => 'Karnataka',
                'postcode'      => $create_account_obj->student_pin,
                'country'       => 'India',
                'email'         => $create_account_obj->customer_email,
                'phone'         => $create_account_obj->principal_phone_number,
            ],
            'shipping' => [
                'first_name'    => $create_account_obj->customer_name,
                'last_name'     => '',
                'address_1'     => $create_account_obj->student_address,
                'address_2'     => '',
                'city'          => 'Bangalore',
                'state'         => 'Karnataka',
                'postcode'      => $create_account_obj->student_pin,
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
                    'value' => '0073'
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
                    'value' => $create_account_obj->student_fullname,
                ],
                [
                    'key' => 'payer_bank_account_number',
                    'value' => $create_account_obj->payer_bank_account_number,
                ],
                [
                    'key' => 'admission_number',
                    'value' => $create_account_obj->ticket_id,
                ],

            ],
        ];

        // finally, lets create the new order using the Woocommerce API on the remote payment server
        $order_created = $woocommerce->post('orders', $order_data);

        // check if the order has been created and if so what is the order ID

        return $order_created;
    }

    private function get_data_for_sritoni_account_creation($ticket_id)
    {
        global $wpscfunction;

        $this->ticket_id    = $ticket_id;
        $this->ticket_data  = $wpscfunction->get_ticket($ticket_id);

        $create_account_obj = new stdClass;

        $fields = get_terms([
            'taxonomy'   => 'wpsc_ticket_custom_fields',
            'hide_empty' => false,
            'orderby'    => 'meta_value_num',
            'meta_key'	 => 'wpsc_tf_load_order',
            'order'    	 => 'ASC',
            /*
            'meta_query' => array(
                                    array(
                                        'key'       => 'agentonly',
                                        'value'     => '1',
                                        'compare'   => '<='             // get all ticket meta fields
                                    ),
                                ),
            */
        ]);

        $create_account_obj->ticket_id      = $ticket_id;
        $create_account_obj->customer_name  = $this->ticket_data['customer_name'];
        $create_account_obj->customer_email = $this->ticket_data['customer_email'];

        foreach ($fields as $field):
            if (empty($field)) continue;

            $value = $wpscfunction->get_ticket_meta($ticket_id, $field->slug, true);

            switch ($field->slug):

                case 'student-firstname':
                    $create_account_obj->student_firstname = $value;
                    break;

                case 'student-middlename':
                    $create_account_obj->student_middlename = $value;
                    break;

                case 'student-lastname':
                    $create_account_obj->student_lastname = $value;
                    break;

                case 'student-dob':
                    $create_account_obj->student_dob = $value;
                    break;

                case 'existing-sritoni-idnumber':
                    $create_account_obj->existing_sritoni_idnumber = $value;
                    break;

                case 'existing-sritoni-username':
                    $create_account_obj->existing_sritoni_username = $value;
                    break;

                case 'bank-account-number':
                    $create_account_obj->payer_bank_account_number = $value;
                    break;

                case 'admission-fee-payable':
                    $create_account_obj->admission_fee_payable = $value;
                    break;

                case 'product-customized-name':
                    $create_account_obj->product_customized_name = $value;
                    break;

                case 'username':
                    $create_account_obj->username = $value;
                    break;

                case 'idnumber':
                    $create_account_obj->idnumber = $value;
                    break;

                case 'studentcat':
                    $create_account_obj->studentcat = $value;
                    break;

                case 'class':
                    $create_account_obj->class = $value;
                    break;

                case 'environment':
                    $create_account_obj->environment = $value;
                    break;

                case 'department':
                    $create_account_obj->department = $value;
                    break;

                case 'cohort':
                    $create_account_obj->cohort = $value;
                    break;

                case 'institution':
                    $create_account_obj->institution = $value;
                    break;

                case 'blood-group':
                    $create_account_obj->blood_group = $value;
                    break;

                case 'father-name':
                    $create_account_obj->father_name = $value;
                    break;

                case 'mother-name':
                    $create_account_obj->mother_name = $value;
                    break;

                case 'email-father':
                    $create_account_obj->email_father = $value;
                    break;

                case 'email-mother':
                    $create_account_obj->email_mother = $value;
                    break;

                case 'principal-phone-number':
                    $create_account_obj->principal_phone_number = $value;
                    break;

                case 'address':
                    $create_account_obj->student_address = $value;
                    break;

                case 'mother-phone-number':
                    $create_account_obj->mother_phone_number = $value;
                    break;

                case 'father-phone-number':
                    $create_account_obj->father_phone_number = $value;
                    break;

                case 'student-pin':
                    $create_account_obj->student_pin = $value;
                    break;

                default:
                    // do nothing for now
                    break;
            endswitch;
        endforeach;         // processed all ticket fields

        $create_account_obj->student_fullname = $create_account_obj->student_firstname  . " "
                                              . $create_account_obj->student_middlename . " "
                                              . $create_account_obj->student_lastname;

        $this->create_account_obj = $create_account_obj;
    }

    private function get_wc_order($order_id)
    {
        // run this since we may be changing API keys. Once in production remove this
        $this->get_config();

        // instantiate woocommerce API class
        $woocommerce = new Client(
            'https://sritoni.org/hset-payments/',
            $this->config['wckey'],
            $this->config['wcsec'],
            [
                'wp_api'            => true,
                'version'           => 'wc/v3',
                'query_string_auth' => true,

            ]
        );

        $endpoint   = "orders/" . $order_id;
        $params     = array($order_id);
        $order      = $woocommerce->get($endpoint);

        echo "<pre>" . print_r($order, true) ."</pre>";

        return $order;
    }

    private function test_update_wc_product()
    {
        // run this since we may be changing API keys. Once in production remove this
        $this->get_config();

        $ticket_id = 3;

        $this->get_data_for_sritoni_account_creation($ticket_id);

        $create_account_obj = $this->create_account_obj;

        // instantiate woocommerce API class
        $woocommerce = new Client(
            'https://sritoni.org/hset-payments/',
            $this->config['wckey'],
            $this->config['wcsec'],
            [
                'wp_api'            => true,
                'version'           => 'wc/v3',
                'query_string_auth' => true,

            ]
        );

        // Admission fee to HSET product ID
        $product_id = 581;

        $endpoint   = "products/" . $product_id;

        $product_data = [
                            'name'          => $create_account_obj->product_customized_name,
                            'regular_price' => $create_account_obj->admission_fee_payable
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
        $ticket_id = 3;

        $this->get_data_for_sritoni_account_creation($ticket_id);

        echo "<pre>" . print_r($this->create_account_obj, true) ."</pre>";
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
    private function change_ticket_status_to_error($ticket_id, $error_message)
    {
        global $wpscfunction;


    }

}   // end of class bracket
