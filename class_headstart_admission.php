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
use Automattic\WooCommerce\HttpClient\HttpClientException;

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

        // load actions for admin
		if (is_admin()) $this->define_admin_hooks();

        // load public facing actions
		$this->define_public_hooks();

        // read the config file and build the secrets array
        $this->get_config();

        $this->verbose = true;

        // read the fee and description pairs from settings and form an associative array
        $this->admission_fee_description();

	}

    /**
     * Get settings values for fee and description based on category
     * This array will be used to look up fee and payment description agent fields, based on category
     */

    private function admission_fee_description()
    {
        $setting_category_fee = get_option('headstart_admission_settings')['category_fee'];

        $chunks = array_chunk(preg_split('/(:|,)/', $setting_category_fee), 2);

		$this->category_fee_arr = array_combine(array_column($chunks, 0), array_column($chunks, 1));

        //$this->verbose ? error_log(print_r($this->category_fee_arr, true)) : false;

        $setting_category_paymentdescription = get_option('headstart_admission_settings')['category_paymentdescription'];

        $chunks = array_chunk(preg_split('/(:|,)/', $setting_category_paymentdescription), 2);

        $this->category_paymentdescription_arr = array_combine(array_column($chunks, 0), array_column($chunks, 1));

        //$this->verbose ? error_log(print_r($this->category_paymentdescription_arr, true)) : false;
    }

    /**
     * @return nul
     * This is the function that processes the webhook coming from hset-payments on any order that is completed
     * It extracts the order ID and then gets the order from the hset-payments site.
     * From the order the ticket_id is extracted and it's status is updated.
     */
    public function webhook_order_complete_process()
    {
        global $wpscfunction;

        // add these as properties of object
		$this->wc_webhook_secret    = $this->config['wc_webhook_secret'];

        if ( $_SERVER['REMOTE_ADDR']              == '68.183.189.119' &&
             $_SERVER['HTTP_X_WC_WEBHOOK_SOURCE'] == 'https://sritoni.org/hset-payments/'     
           )
        {

            $this->signature = $_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'];

            $request_body = file_get_contents('php://input');

            $signature_verified = $this->verify_webhook_signature($request_body);

            if ($signature_verified)
            {
                $this->verbose ? error_log("HSET order completed webhook signature verified") : false;

                $data = json_decode($request_body, false);  // decoded as object

                if ($data->action = "woocommerce_order_status_completed")
                {
                    $order_id = $data->arg;

                    $this->verbose ? error_log($data->action . " " . $order_id) : false;

                    // so we now have the order id for the completed order. Fetch the order object!
                    $order = $this->get_wc_order($order_id);

                    // from the order, extract the admission number which is our ticket id
                    $ticket_id = $order->admission_number;

                    // using the ticket id, update the ticket status to payment process completed
                    $status_id =  136; // admission-payment-process-completed
                    $wpscfunction->change_status($ticket_id, $status_id);

                    $transaction_id = str_replace (['{', '}'], ['', ''], $order->transaction_id);
 
                    // update the agent only fields payment-bank-reference which is really thee transaction_id
                    $wpscfunction->change_field($ticket_id, 'payment-bank-reference', $transaction_id);

                    // return after successful termination of webhook
                    return;
                }
            }
            else 
            {
                $this->verbose ? error_log("HSET order completed webhook signature NOT verified") : false;
                die;
            }
        }
        else
        {
            $this->verbose ? error_log("HSET order completed webhook source NOT verified") : false;
            $this->verbose ? error_log($_SERVER['REMOTE_ADDR'] . " " . $_SERVER['HTTP_X_WC_WEBHOOK_SOURCE']) : false;

            die;
        }
    }

    private function verify_webhook_signature($request_body)
    {
        $signature    = $this->signature;
        $secret       = $this->wc_webhook_secret;

        return (base64_encode(hash_hmac('sha256', $request_body, $secret, true)) == $signature);
    }

    /**
     *  reads in a config php file and gets the API secrets. The file has to be in gitignore and protected
     */
    private function get_config()
    {
      $this->config = include( __DIR__."/" . $this->plugin_name . "_config.php");
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


        add_action('wpsc_set_change_fields', [$this, 'action_on_ticket_field_changed'], 10,4);

    }

    /**
     * 
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



    /**
     *  @return nul Nothing is returned
     *  The function takes the Ninja form immdediately after submission
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

            if ($ticket_field->slug == 'ticket_priority')
            {
                continue;     // we don't modify these fields so skip
            }

            // capture the ones of interest to us
            switch (true):

                // customer_name ticket field mapping.
                case ($ticket_field->slug == 'customer_name'):

                    // look for the mapping slug in the ninja forms field's admin label
                    $key = array_search('customer_name', $admin_label_array);

                    if ($key !== false)
                    {
                        $ticket_args[$ticket_field->slug]= $value_array[$key];
                    }
                    else
                    {
                        $this->verbose ? error_log($ticket_field->slug . " index not found in Ninja forms value array") : false;
                        
                    }
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
                    $key = array_search('primary-email', $admin_label_array);

                    if ($key !== false)
                    {
                        $ticket_args[$ticket_field->slug]= $value_array[$key];
                    }
                    else
                    {
                        $this->verbose ? error_log($ticket_field->slug . " index not found in Ninja forms value array") : false;
                    }

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


                    default:

                        // from here on the ticket slug is same as form field slug so mapping is  easy.
                        // look for the mapping slug in the ninja forms field's admin label
                        $key = array_search($ticket_field->slug, $admin_label_array);

                        if ($key !== false)
                        {
                            $ticket_args[$ticket_field->slug]= $value_array[$key];
                        }
                        else
                        {
                            $this->verbose ? error_log($ticket_field->slug . " index not found in Ninja forms map to Ticket") : false;
                        }

                        break;

            endswitch;          // end switching throgh the ticket fields looking for a match

        endforeach;             // finish looping through the ticket fields for mapping Ninja form data to ticket

        // we have all the necessary ticket fields filled from the Ninja forms, now we can create a new ticket
        $ticket_id = $wpscfunction->create_ticket($ticket_args);
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

        // add any logoc that you want here based on new status
        switch (true):

            case ($wpscfunction->get_status_name($status_id) === 'Interaction Completed'):

                $this->get_data_object_from_ticket($ticket_id);

                // get the category id from the ticket
                $ticket_category_id = $this->data_object->ticket_meta['ticket_category'];
                $fullname           = $this->data_object->ticket_meta['student-first-name']  . " " . 
                                      $this->data_object->ticket_meta['student-middle-name'] . " " .
                                      $this->data_object->ticket_meta['student-last-name'];
                // get the ticket category name from ID
                $term_category              = get_term_by('id', $ticket_category_id, 'wpsc_categories');

                $admission_fee_payable      = $this->category_fee_arr[$term_category->slug];

                $product_customized_name    = $this->category_paymentdescription_arr[$term_category->slug] . " " . $fullname;

                // update the agent fields for fee and fee description
                $wpscfunction->change_field($ticket_id, 'admission-fee-payable', $admission_fee_payable);

                $wpscfunction->change_field($ticket_id, 'product-customized-name', $product_customized_name);
                
                 break;


            case ($wpscfunction->get_status_name($status_id) === 'Admission Granted'):

                // A new payment shop order is remotely created on hset-payments
                // $this->create_payment_shop_order($ticket_id);

             break;


            case ($wpscfunction->get_status_name($status_id) === 'Admission Confirmed'):

                // A new SriToni user account is created for this child using ticket dataa.
                $this->create_user_account($ticket_id);
            break;


            default:
                // all other cases come here and flow down with no action.
            break;

        endswitch;      // END of switch  status change actions
    }                  


    /**
     * 
     */

    public function create_payment_shop_order($ticket_id)
    {
        global $wpscfunction;

        // buuild an object containing all relevant data from ticket useful for crating user accounts and payments
        $this->get_data_object_from_ticket($ticket_id);

        // The payment process needs to be triggered
        // if the applicant email is a headstart one, then we will use the associated VA details for the order
        // can also return null if server error or user dows not exist on payment site
        $wp_user_hset_payments = $this->get_wpuser_from_site_hsetpayments();

        switch (true):
            
                case ($wp_user_hset_payments === 1):
                    // could not access hset-payments site. Status already changed to error with message
                    return;
                case ($wp_user_hset_payments === 2):
                    // could not create VA account for head start user. Status already changed to error with message
                    return;
                case ($wp_user_hset_payments === 3):
                    // not a head start user, detect this null and assign customer 5 in the create order process
                    $wp_user_hset_payments  = null;
                    // use sritoni1's customer ID in site hset-payments for order
                    $customer_id            = 5;           
                    break;

                default:
                    // we have a customer ID that is valid
                    $customer_id            = $wp_user_hset_payments->id;
        endswitch;

        // if you got here you must be a head start user with a valid VA and customer_id and valid customer object OR
        // a non-headstart user with a customer object of null

        // let's write the customer id to the agent only field for easy reference
        $wpscfunction->change_field($ticket_id, 'wp-user-id-hset-payments', $customer_id);
        
        // Assign the customer as a property of the class
        $this->data_object->wp_user_hset_payments = $wp_user_hset_payments;

        // check that admission-fee-payable and product-customized-name  fields are set
        $product_customized_name    = $this->data_object->ticket_meta["product-customized-name"];
        $regular_price              = $this->data_object->ticket_meta["admission-fee-payable"];

        if (empty($regular_price) || empty($product_customized_name))
        {
            // change ticket status to error with an error message
            $error_message = "Admission-fee-payable and or the product-customized-name fields need to be set";

            $this->change_status_error_creating_payment_shop_order($ticket_id, $error_message);

            return;
        }

        $new_order = $this->create_wc_order_site_hsetpayments();

        $this->verbose ? error_log($new_order->id . " ID of newly created payment SHOP Order") : false;

        // update the agent field with the newly created order ID
        $wpscfunction->change_field($ticket_id, 'order-id', $new_order->id);
    }


    /**
     * This function grabs the ticket fields and data from a given ticket id
     * It then creates a new data_object that contains all of the ticket data, to be used anywhere needed
     * This data_object is also set as a property of $this
     *  @param int:$ticket_id
     *  @return obj:$data_object
     */

    private function get_data_object_from_ticket($ticket_id)
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
     * 1. checks to see if logged in user has headstart email
     * 2.   If YES, checks for valid VA. If this exists, returns the customer object from hset-payments site.
     *                                   If VA doesn't exist, creates a new one and update the  user meta of hset-payments
     *                                   and returns the updated customer object.
     * 3.   If NO then, a null object is returned.
     * 
     *  1 is returned for error condition of hset-payments site not aaccessible
     *  2 is returned for error condition not able to create a new VA for Head Start account holder
     *  3 is returned for error condition, user is not a Head Start account holder
     *
     * @return obj:woocommerce customer object - null returned in case of server error or if user does not exist
     */
    private function get_wpuser_from_site_hsetpayments()
    {
        global $wpscfunction;

        $data_object = $this->data_object;

        // does this user have a head start email ID?
        if (stripos($data_object->ticket_data["customer_email"], 'headstart.edu.in') !== false)
        {   // YES. Head Start email holder, Get the wp userid from the hset-payments site

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

            $params     = array(
                                'role'  =>'subscriber',
                                'email' => $data_object->ticket_data["customer_email"]
                                );
            // get customers with given parameters as above, there should be a maximum of 1
            try
            {
                $customers = $woocommerce->get($endpoint, $params);
            }
            catch (HttpClientException $e)
            {   // if cannot access hset-payments server error message and return 1
                $error_message = "Could NOT access hset-payments site to get customer details: " . $e->getMessage();
                $this->change_status_error_creating_payment_shop_order($data_object->ticket_id, $error_message);

                return 1;
            }
            
            // if you get here then  you got something back from hset-payments site

            // form arrays and array_values from user meta to search for user meta data
            $array_meta_key    = array_column($customers[0]->meta_data, "key");
            $array_meta_value  = array_column($customers[0]->meta_data, "value");

            $index = array_search("va_id", $array_meta_key);

            // if this exists, then the head start user does have a valid VA
            if ($index !== false)
            {
                $va_id = $array_meta_value[$index] ?? null;
            }
            else
            {
                $va_id = null;
            }


            if (empty($va_id))
            {   // the VA ID does not exist. However, let us check if the VA exists but not just updated in our records
                
                // pad the moodleuserid with leading 0's if length less than 4. If not leave alone
                $vAccountId = str_pad($customers[0]->username, 4, "0", STR_PAD_LEFT);

                // instantiate the cashfree API
                $configfilepath  = $this->plugin_name . "_config.php";
                $cashfree_api    = new CfAutoCollect($configfilepath); // new cashfree Autocollect API object

                // get the VA if it exists
                $vAccount = $cashfree_api->getvAccountGivenId($vAccountId );
                if ($vAccount->vAccountId == $vAccountId)
                {
                    // A valid account exists, so no need to create a new account
                    // However need to update the user's meta in the hset-payments site using the WC API
                    $user_meta_data = array(
                                            "meta_data" => array(   array(
                                                                        "key"   => "beneficiary_name",
                                                                        "value" => "Head Start Educational Trust",
                                                                        ),
                                                                    array(
                                                                        "key"   => "account_number",
                                                                        "value" => $vAccount->virtualAccountNumber,
                                                                        ),
                                                                    array(
                                                                        "key"   => "va_ifsc_code",
                                                                        "value" => $vAccount->ifsc,
                                                                        ),
                                                                    array(
                                                                        "key"   => "va_id",
                                                                        "value" => $vAccountId,
                                                                        ),
                                                                )
                                                );
                    $endpoint           = "customers/" . $customers[0]->id;

                    $updated_customer   = $woocommerce->put($endpoint, $user_meta_data);

                    // also update SriToni profile field virtualaccouonts with the newly created data

                    $this->verbose? error_log("Valid VA existed but was not updated - hset-payment updated for VA of Head Start email: " . $customers[0]->email) : false;
                    $this->verbose? error_log("Updated WC customer object being returned for Head Start email: " . $customers[0]->email) : false;
                    return $updated_customer;
                }
                else
                {
                    // A valid VA does not exist for this Head Start account holder. So create a new one
                    $name   = $customers[0]->first_name . " " . $customers[0]->last_name;

                    // extract the phone from the WC user's meta data using the known key
                    $phone  = $array_meta_value[array_search("sritoni_telephonenumber", $array_meta_key)] ?? '1234567890';

                    // per rigid requirements of Cashfree for a phone number to be 10 numbers and non-blank
                    if (strlen($phone) !=10)
                    {
                        $phone  = "1234567890";     // phone dummy number
                    }

                    // create a new VA
                    $new_va_created = $cashfree_api->createVirtualAccount(  $vAccountId,
                                                                            $name,
                                                                            $phone,
                                                                            $customers[0]->email);

                    // update the hset-payments user meta with the newly created VA info needed for email for payments
                    if ($new_va_created)
                    {
                        $account_number         = $new_va_created->accountNumber;
                        $ifsc                   = $new_va_created->ifsc;

                        $user_meta_data = array(
                                                "meta_data" => array(
                                                                        array(
                                                                            "key"   => "beneficiary_name",
                                                                            "value" => "Head Start Educational Trust",
                                                                            ),
                                                                        array(
                                                                            "key"   => "account_number",
                                                                            "value" => $account_number,
                                                                            ),
                                                                        array(
                                                                            "key"   => "va_ifsc_code",
                                                                            "value" => $ifsc,
                                                                            ),
                                                                        array(
                                                                            "key"   => "va_id",
                                                                            "value" => $vAccountId,
                                                                            ),
                                                                    )
                                                    );
                        $endpoint           = "customers/" . $customers[0]->id;
                        $updated_customer   = $woocommerce->put($endpoint, $user_meta_data);

                        $this->verbose? error_log("Valid VA needed to be created - hset-payment updated for VA of Head Start email: " . $customers[0]->email) : false;
                        $this->verbose? error_log("Updated WC customer object being returned for Head Start email: " . $customers[0]->email) : false;

                        return $updated_customer;

                    }
                    else
                    {
                        // Failure in creating a new VA for this Head Start user

                        $this->verbose? error_log("Could NOT create a new VA for user email: " . $customers[0]->email) : false;
                        $error_message = "Could NOT access hset-payments site to get customer details: ";
                        $this->change_status_error_creating_payment_shop_order($data_object->ticket_id, $error_message);
                        return 2;
                    }
                }


            }
            else
            {
                // the VA for this user already exists in the user meta. So all good.
                $this->verbose? error_log("Valid VA exists for Head Start email: " . $customers[0]->email) : false;
                $this->verbose? error_log("Valid VAID exists for Head Start email: " . $va_id) : false;

                return $customers[0];
            }
        }
        else
        {
            // Not a Head Start account holder, so return 3 to be decoded by calling function
            return 3;
        }
    }



    /**
     *  Creates a data object from a given ticket_id. Thhis is used for creating orders, user accounts etc.
     *  make sure to run $this->get_data_object_from_ticket($ticket_id) before calling this  method
     *  @return obj:$order_created
     */
    private function create_wc_order_site_hsetpayments()
    {
        $array_meta_key     = [];
        $array_meta_value   = [];

        $index              = null;

        // before coming here the create account object is already created. We jsut use it here.
        $data_object = $this->data_object;

        // fix the customer_id and corresponding va_id
        if ($this->data_object->wp_user_hset_payments)
        {
            // customer object is not null, so should contain valid customer ID and va_id
            $customer_id = $this->data_object->wp_user_hset_payments->id;

            // to get the va_id we need to search through the meta data arrays of the customer object
            $array_meta_key     = array_column($data_object->wp_user_hset_payments->meta_data, 'key');
            $array_meta_value   = array_column($data_object->wp_user_hset_payments->meta_data, 'value');

            $index = array_search('va_id', $array_meta_key);

            $va_id = $array_meta_value[$index];
        }
        else
        {
            $va_id = "0073";

            $customer_id = 5;
        }
        

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
                            'name'          => $data_object->ticket_meta["product-customized-name"],
                            'regular_price' => $data_object->ticket_meta["admission-fee-payable"],
                        ];
        // TODO use try catch here
        $product = $woocommerce->put($endpoint, $product_data);

        // lets now prepare the data for the new order to be created
        $order_data = [
            'customer_id'           => $customer_id,
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
                'email'         => $data_object->ticket_meta['customer_email'],
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
            return $order_created;
        }
        else
        {
            // there was an error in creating the prder. Update the status and the error message for the ticket
            $this->change_status_error_creating_payment_shop_order($data_object->ticket_id, 'could NOT create payment order, check');
        }
    }


    /**
     * 
     */
    public function update_wc_order_site_hsetpayments($order_id, $order_data)
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
                                    

        $endpoint       = "orders/" . $order_id;

        $order_updated  = $woocommerce->put($endpoint, $order_data);

        return $order_updated;
    }


    /**
     * 
     */

    public function create_user_account($ticket_id)
    {
        global $wpscfunction;

        // buuild an object containing all relevant data from ticket useful for crating user accounts and payments
        $this->get_data_object_from_ticket($ticket_id);

        if (stripos($this->data_object->ticket_meta['customer_email'], 'headstart.edu.in') !== false)
        {
            $this->verbose ? error_log("User already has a Head Start EMAIL, so no new account created") : false;

            return;
        }
        // check if all required data for new account creation is set
        elseif 
        (   !empty($this->data_object->ticket_meta['username'])      &&
            !empty($this->data_object->ticket_meta['idnumber'])      &&
            !empty($this->data_object->ticket_meta['studentcat'])    &&
            !empty($this->data_object->ticket_meta['department'])    &&
            //!empty($this->data_object->ticket_meta['class'])         &&
            //!empty($this->data_object->ticket_meta['environment'])   &&
            !empty($this->data_object->ticket_meta['institution']))
        {
            // go create a new SriToni user account for this child using ticket dataa.
            $this->create_sritoni_account();

            return;
        }
        else
        {
            $error_message = "Sritoni Account NOT CREATED! Ensure username, idnumber, studentcat, department, and institution fields are Set";
            $this->change_status_error_creating_sritoni_account($this->data_object->ticket_id, $error_message);

            return;
        }
    }


    /**
     *  This is called after the create_account object has been already created  so need to call it.
     */
    private function create_sritoni_account()
    {
        // before coming here the create account object is already created. We jsut use it here.
        $data_object = $this->data_object;

        // if you get here, you DO NOT have a username and DO NOT have an idnumber in the SriToni Moodle system

        // run this again since we may be changing API keys. Once in production remove this
        $this->get_config();

        // read in the Moodle API config array
        $config			= $this->config;
        $moodle_url 	= $config["moodle_url"] . '/webservice/rest/server.php';
        $moodle_token	= $config["moodle_token"];

        $moodle_username = $data_object->ticket_meta["username"];

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
            // An account with this username already exssts. So add  a number to the username and retry
            for ($i=1; $i < 5; $i++):
            
                $moodle_username = $data_object->username . $i;
                $parameters   = array("criteria" => array(array("key" => "username", "value" => $moodle_username)));
                $moodle_users = $MoodleRest->request('core_user_get_users', $parameters, MoodleRest::METHOD_GET);
                if ( !( $moodle_users["users"][0] )  )
                {
                    // we can use this username, it is not taken. Break out of the for loop
                    break;
                }
                elseif ($i == 4)
                {
                    $error_message = "Couldnt find username, the account exists for upto username + 4 ! check and retry change of status";

                    $this->verbose ? error_log($error_message) : false;

                    // change the ticket status to error
                    $this->change_status_error_creating_sritoni_account($data_object->ticket_id, $error_message);

                    return;
                }
                else 
                {
                    continue; //loop
                }
            endfor;

        // came out the for loop with a valid user name that can be created
        }

        // if you are here it means you came here after breaking through the forloop above
        // so create a new moodle user account with the successful username

        // write the data back to Moodle using REST API
        // create the users array in format needed for Moodle RSET API
        $fees_array = [];
        $fees_json  = json_encode($fees_array);

    	$users = array("users" => array(
                                            array(	"username" 	    => $moodle_username,
                                                    "idnumber"      => $data_object->ticket_meta["idnumber"],
                                                    "auth"          => "oauth2",
                                                    "firstname"     => $data_object->ticket_meta["student-first-name"],
                                                    "lastname"      => $data_object->ticket_meta["student-last-name"],
                                                    "email"         => $moodle_username . "@headstart.edu.in",
                                                    "middlename"    => $data_object->ticket_meta["student-middle-name"],
                                                    "institution"   => $data_object->ticket_meta["institution"],
                                                    "department"    => $data_object->ticket_meta["department"],
                                                    "phone1"        => $data_object->ticket_meta["emergency-contact-number"],
                                                    "phone2"        => $data_object->ticket_meta["emergency-alternate-contact"],
                                                    "address"       => $data_object->ticket_meta["residential-address"],
                                                    "maildisplay"   => 0,
                                                    "createpassword"=> 0,

                                                    "customfields" 	=> array(
                                                                                array(	"type"	=>	"class",
                                                                                        "value"	=>	$data_object->ticket_meta["class"],
                                                                                    ),
                                                                                array(	"type"	=>	"environment",
                                                                                        "value"	=>	$data_object->ticket_meta["environment"],
                                                                                    ),
                                                                                array(	"type"	=>	"studentcat",
                                                                                        "value"	=>	$data_object->ticket_meta["studentcat"],
                                                                                    ),
                                                                                array(	"type"	=>	"bloodgroup",
                                                                                        "value"	=>	$data_object->ticket_meta["blood-group"],
                                                                                    ),
                                                                                array(	"type"	=>	"motheremail",
                                                                                        "value"	=>	$data_object->ticket_meta["mothers-email"],
                                                                                    ),
                                                                                array(	"type"	=>	"fatheremail",
                                                                                        "value"	=>	$data_object->ticket_meta["fathers-email"],
                                                                                    ),
                                                                                array(	"type"	=>	"motherfirstname",
                                                                                        "value"	=>	$data_object->ticket_meta["mothers-first-name"],
                                                                                    ),
                                                                                array(	"type"	=>	"motherlastname",
                                                                                        "value"	=>	$data_object->ticket_meta["mothers-last-name"],
                                                                                    ),
                                                                                array(	"type"	=>	"fatherfirstname",
                                                                                        "value"	=>	$data_object->ticket_meta["fathers-first-name"],
                                                                                    ),
                                                                                array(	"type"	=>	"fatherlastname",
                                                                                        "value"	=>	$data_object->ticket_meta["fathers-last-name"],
                                                                                    ),
                                                                                array(	"type"	=>	"mothermobile",
                                                                                        "value"	=>	$data_object->ticket_meta["mothers-contact-number"],
                                                                                    ),
                                                                                array(	"type"	=>	"fathermobile",
                                                                                        "value"	=>	$data_object->ticket_meta["fathers-contact-number"],
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
            $this->verbose ? error_log("Create new user did NOT return expected username: " . $moodle_username) : false;
            $this->verbose ? error_log(print_r($ret, true)) : false;

            // change the ticket status to error
            $this->change_status_error_creating_sritoni_account($data_object->ticket_id, $ret["message"]);

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
                <input type="submit" name="button" 	value="test_get_ticket_data"/>
                <input type="submit" name="button" 	value="test_get_wc_order"/>
                <input type="submit" name="button" 	value="test_get_data_object_from_ticket"/>
                <input type="submit" name="button" 	value="test_custom_code"/>
                <input type="submit" name="button" 	value="trigger_payment_order_for_error_tickets"/>
                <input type="submit" name="button" 	value="trigger_sritoni_account_creation_for_error_tickets"/>

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

            case 'test_get_ticket_data':
                $this->test_get_ticket_data();
                break;

            case 'test_get_wc_order':
                $order_id = "590";
                $this->get_wc_order($order_id);
                break;

            case 'trigger_payment_order_for_error_tickets':
                $this->trigger_payment_order_for_error_tickets();
                break;

            case 'trigger_sritoni_account_creation_for_error_tickets':
                $this->trigger_sritoni_account_creation_for_error_tickets();
                break;

            case 'test_get_data_object_from_ticket':
                $this->test_get_data_object_from_ticket();
                break;

            case 'test_custom_code':
                $this->test_custom_code();
                break;

            default:
                // do nothing
                break;
        }
    }

    


    /**
     * @return nul
     * Get a list of all tickets that have error's while creating payment orders
     * The errors could be due to server down issues or data setup issues
     * For each ticket get the user details and create a payment order in the hset-payments site
     * After successful completion, change the status of the ticket appropriately
     */

    public function trigger_sritoni_account_creation_for_error_tickets()
    {
        global $wpscfunction, $wpdb;


        // get all tickets that have payment error status
        $status_slug = 'error-creating-sritoni-account';

        $term = get_term_by('slug', $status_slug, 'wpsc_statuses');

        // get all tickets with this status
        $meta_query[] = array(
            'key'     => 'ticket_status',
            'value'   => $term->term_id,
            'compare' => '='
        );
        
        $meta_query[] = array(
            'key'     => 'active',
            'value'   => 1,
            'compare' => '='
        );
        
        $select_str   = 'SQL_CALC_FOUND_ROWS DISTINCT t.*';
        $sql          = $wpscfunction->get_sql_query( $select_str, $meta_query);
        $tickets      = $wpdb->get_results($sql);

        foreach ($tickets as $ticket):
        
            $ticket_id = $ticket->id;

            $this->create_user_account($ticket_id);
            
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


        // get all tickets that have payment error status
        $status_slug = 'error-creating-payment-shop-order';

        $term = get_term_by('slug', $status_slug, 'wpsc_statuses');

        // get all tickets with this status
        $meta_query[] = array(
            'key'     => 'ticket_status',
            'value'   => $term->term_id,
            'compare' => '='
        );
        
        $meta_query[] = array(
            'key'     => 'active',
            'value'   => 1,
            'compare' => '='
        );
        
        $select_str   = 'SQL_CALC_FOUND_ROWS DISTINCT t.*';
        $sql          = $wpscfunction->get_sql_query( $select_str, $meta_query);
        $tickets      = $wpdb->get_results($sql);

        echo nl2br ("List of tickets found (showing 10 only): " . count($tickets) . "\n");

        // print out an HTML of the ticket information and at the bottom put in a button for continue

        foreach ($tickets as $ticket):
        
            $ticket_id = $ticket->id;

            $this->create_payment_shop_order($ticket_id);
            
        endforeach;
    }

    


    /**
     * 
     */

    public function test_woocommerce_customer()
    {
        $this->get_data_object_from_ticket(5);

        //$this->data_object->ticket_data['customer_email'] = "aadhya.hibare@headstart.edu.in";

        $wpuserobj = $this->get_wpuser_from_site_hsetpayments();

        echo "<pre>" . print_r($wpuserobj, true) ."</pre>";

    }

    public function test_custom_code()
    {
        global $wpscfunction;

        $ticket_id = 5;

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
                    'value'     => ['0', '1'],
                    'compare'   => 'IN'
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

        $term = get_term_by('slug','error-creating-payment-shop-order','wpsc_statuses');
        echo "<pre>" . print_r($term, true) ."</pre>";

        $this->get_data_object_from_ticket($ticket_id);

        // get the category id from the ticket
        $ticket_category_id = $this->data_object->ticket_meta['ticket_category'];
        $fullname           = $this->data_object->ticket_meta['student-first-name']  . " " . 
                                $this->data_object->ticket_meta['student-middle-name'] . " " .
                                $this->data_object->ticket_meta['student-last-name'];
        // get the ticket category name from ID
        $term_category              = get_term_by('id', $ticket_category_id, 'wpsc_categories');

        $admission_fee_payable      = $this->category_fee_arr[$term_category->slug];

        $product_customized_name    = $this->category_paymentdescription_arr[$term_category->slug] . " " . $fullname;

        // update the agent fields for fee and fee description
        $wpscfunction->change_field($ticket_id, 'admission-fee-payable', $admission_fee_payable);

        $wpscfunction->change_field($ticket_id, 'product-customized-name', $product_customized_name);
        
        echo "<pre>" . "desired category id: " . $ticket_category_id ."</pre>";
        echo "<pre>" . "desired category slug: " . $term_category->slug ."</pre>";
        echo "<pre>" . "fee: " . $admission_fee_payable ."</pre>";
        echo "<pre>" . "description: " . $product_customized_name ."</pre>";

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



    private function test_create_wc_order()
    {
        $ticket_id = 3;

        $this->get_data_object_from_ticket($ticket_id);

        $order_created = $this->create_wc_order_site_hsetpayments();

        echo "<pre>" . print_r($order_created, true) ."</pre>";
    }



    private function test_get_data_object_from_ticket()
    {
        $ticket_id = 23;

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
    private function change_status_error_creating_payment_shop_order($ticket_id, $error_message)
    {
        global $wpscfunction;

        $status_id = 94;    // corresponds to status error creating payment order

        $wpscfunction->change_status($ticket_id, $status_id);

				$ticket_slug = "error";

        // update agent field error message with the passed in error message
        if (!empty($error_message))
        {
            $wpscfunction->change_field($ticket_id, $ticket_slug, $error_message);
        }
    }

    /**
     *
     */
    private function change_status_error_creating_sritoni_account($ticket_id, $error_message)
    {
        global $wpscfunction;

        $status_id = 95;    // corresponds to status error creating sritoni account

        $wpscfunction->change_status($ticket_id, $status_id);

				$ticket_slug = "error";

        // update agent field error message with the passed in error message
        if (!empty($error_message))
        {
            $wpscfunction->change_field($ticket_id, $ticket_slug, $error_message);
        }

    }

    /**
     *
     */
    private function get_status_id_by_slug($slug)
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
                                        'value'     => ["0", "1"],  // get all ticket meta fields
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
     *
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

    public function test_get_ticket_data()
    {
        global $wpscfunction;

        $ticket_id =23;

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

        echo "<pre>" . print_r($fields, true) ."</pre>";

    }



}   // end of class bracket
