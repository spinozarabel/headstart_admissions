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
            
            default:
                // do nothing
                break;
        }
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
        $ticket_id = 1;
        $ticket_data = $wpscfunction->get_ticket($ticket_id);
        echo "<pre>" . print_r($ticket_data, true) ."</pre>";
    }

    public function test_get_ticket_data()
    {
        // the folowing piece of code dumps details of ticket id =1;
        global $wpscfunction;
        $ticket_id = 1;
        
        $fields = get_terms([
            'taxonomy'   => 'wpsc_ticket_custom_fields',
            'hide_empty' => false,
            'orderby'    => 'meta_value_num',
            'meta_key'	 => 'wpsc_tf_load_order',
            'order'    	 => 'ASC',
            'meta_query' => array(
                array(
                    'key'       => 'agentonly',
                    'value'     => '0',
                    'compare'   => '='
                )
            ),
        ]);

        foreach ($fields as $field) 
        {
            $value = $wpscfunction->get_ticket_meta($ticket_id, $field->slug, true);
            if($value)
            {
                $fields_format->get_field_val($field);

                echo nl2br($field->namne . ": " . $fields_format . "\n");
            }
            
      }
    }

}   // end of class bracket