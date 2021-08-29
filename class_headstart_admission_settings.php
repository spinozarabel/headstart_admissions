<?php
/**
 * 
 * @author Madhu <madhu.avasarala@gmail.com>
 * @author Mostafa <mostafa.soufi@hotmail.com>
 * 
 */
class class_headstart_admission_settings {

    /**
     * Holds the values to be used in the fields callbacks
     */
    public $options;

	/**
     * Autoload method
     * @return void
     */
    public function __construct() {
        add_action( 'admin_menu', array($this, 'create_headstart_admission_settings_page') );

		//call register settings function
	    add_action( 'admin_init', array($this, 'init_headstart_admission_settings' ) );
    }

    /**
     * Register woocommerce submenu trigered by add_action 'admin_menu'
     * @return void
     */
    public function create_headstart_admission_settings_page()
	{
        // add_submenu_page( string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, callable $function = '' )
		add_submenu_page(
            'users.php', 'Head Start Admission Settings', 'Head Start Admission Settings', 'manage_options', 'headstart_admission_settings', array($this, 'headstart_admission_settings_page')
        );
    }



    /**
     * Renders the form for getting settings values for plugin
	 * The settings consist of: cashfree merchant ID, key, Moodle API key
     * @return void
     */
    public function headstart_admission_settings_page()
	{

		?>
		<div class="wrap">
            <h1>Head Start Admission Settings</h1>
            <form method="post" action="options.php">
            <?php
                // https://codex.wordpress.org/Settings_API
                // following is for hidden fields and security of form submission per api
                settings_fields( 'headstart_admission_settings' );
                // prints out the sections and fields per API
                do_settings_sections( 'headstart_admission_settings' ); // slug of page
                submit_button();    // wordpress submit button for form
            ?>
            </form>
        </div>
        <?php
    }


	/**
	*
	*/
	public function init_headstart_admission_settings()
	{
		// register_setting( string $option_group, string $option_name, array $args = array() )
        $args = array(
                        'sanitize_callback' => array( $this, 'sanitize' ),  // function name for callback
            //          'default' => NULL,                  // default values when calling get_options
                     );
		register_setting( 'headstart_admission_settings', 'headstart_admission_settings', $args );

		// add_settings_section( $id, $title, $callback, $page );
		//add_settings_section( 'cashfree_api_section', 'cashfree API Settings', array( $this, 'print_section_info' ), 'headstart_admission_settings' );
		//add_settings_section( 'sritoni_api_section', 'Sritoni API Settings', array( $this, 'print_section_info' ), 'headstart_admission_settings' );
        add_settings_section( 'admin_section', 'Admission related Settings', array( $this, 'print_section_info' ), 'headstart_admission_settings' );


		// add_settings_field( $id, $title, $callback, $page, $section, $args );
        // add_settings_field( 'production', 'Check box if Production and Not Test', array( $this, 'production_callback' ), 'headstart_admission_settings', 'cashfree_api_section' );
        // add_settings_field( 'reconcile', 'Try Reconciling Payments?', array( $this, 'reconcile_callback' ), 'headstart_admission_settings', 'cashfree_api_section' );
        // add_settings_field( 'cashfree_secret', 'cashfree API client Secret', array( $this, 'cashfree_secret_callback' ), 'headstart_admission_settings', 'cashfree_api_section' );
		// add_settings_field( 'cashfree_key', 'cashfree API Client Key or ID', array( $this, 'cashfree_key_callback' ), 'headstart_admission_settings', 'cashfree_api_section' );
        // add_settings_field( 'beneficiary_name', 'Beneficiary Name of Cashfree Account', array( $this, 'cashfree_beneficiary_callback' ), 'headstart_admission_settings', 'cashfree_api_section' );
        //a dd_settings_field( 'ip_whitelist', 'comma separated IPs to be whitelisted for webhook', array( $this, 'ip_whitelist_callback' ), 'headstart_admission_settings', 'cashfree_api_section' );
        // add_settings_field( 'domain_whitelist', 'comma separated webhook domains to be whitelisted ', array( $this, 'domain_whitelist_callback' ), 'headstart_admission_settings', 'cashfree_api_section' );

        // added verify_webhook_ip setting in ver 1.3
		// add_settings_field( 'verify_webhook_ip', 'Verify if Webhook IP is in whitelist?', array( $this, 'verify_webhook_ip_callback' ), 'headstart_admission_settings', 'cashfree_api_section' );

        // add_settings_field( 'sritoni_url', 'Sritoni host URL', array( $this, 'sritoni_url_callback' ), 'headstart_admission_settings', 'sritoni_api_section' );
        // add_settings_field( 'sritoni_token', 'Sritoni API Token', array( $this, 'sritoni_token_callback' ), 'headstart_admission_settings', 'sritoni_api_section' );

        // add_settings_field( 'studentcat_possible', 'Comma separated list of permissible student categories', array( $this, 'studentcat_possible_callback' ), 'headstart_admission_settings', 'admin_section' );
        // add_settings_field( 'group_possible', 'Comma separated list of permissible student groups', array( $this, 'group_possible_callback' ), 'headstart_admission_settings', 'admin_section' );
        // add_settings_field( 'whitelist_idnumbers', 'Comma separated list of whitelisted user ID numbers', array( $this, 'whitelist_idnumbers_callback' ), 'headstart_admission_settings', 'admin_section' );
        // add_settings_field( 'courseid_groupingid', 'Comma separated pairs of course ID-grouping ID', array( $this, 'courseid_groupingid_callback' ), 'headstart_admission_settings', 'admin_section' );

        // add_settings_field( 'get_csv_fees_file', 'Check box to get CSV fees file and process', array( $this, 'get_csv_fees_file_callback' ), 'headstart_admission_settings', 'admin_section' );
        // add_settings_field( 'csv_fees_file_path', 'Full path of CSV fees file, can be published Google CSV file', array( $this, 'csv_fees_file_path_callback' ), 'headstart_admission_settings', 'admin_section' );
        add_settings_field( 'category_fee', 'Each line contains a pair of category:fee', array( $this, 'category_fee_callback' ), 'headstart_admission_settings', 'admin_section' );
        add_settings_field( 'category_paymentdescription', 'Each line contains a pair of category:payment description', array( $this, 'category_paymentdescription_callback' ), 'headstart_admission_settings', 'admin_section' );
    }

	/**
     * Print the Section text 
     */
    public function print_section_info()
    {
        print 'Enter your settings below:';
    }


    /**
     * Get the settings option array and print the full path of theCSV fees file
     */
    public function csv_fees_file_path_callback()
    {

    $settings = (array) get_option( 'headstart_admission_settings' );
    $field = "csv_fees_file_path";
    $value = esc_attr( $settings[$field] );

    echo "<input type='text' name='headstart_admission_settings[$field]' id='headstart_admission_settings[$field]'
            value='$value'  size='80' class='code' />";

    }

    /**
     * Get the settings option array and print get_csv_fees_file value
     */
    public function get_csv_fees_file_callback()
    {
        $settings = (array) get_option( 'headstart_admission_settings' );
        $field = "get_csv_fees_file";
        $checked = $settings[$field] ?? 0;

        ?>
            <input name="headstart_admission_settings[get_csv_fees_file]" id="headstart_admission_settings[get_csv_fees_file]" type="checkbox"
                value="1" class="code"<?php checked( $checked, 1, true ); ?>/>
        <?php
    }

    /**
    *  Comma separated list of category - fee 
    * for example: hsea-g1-internal:60000,next-category:next-fee-
    * This specifies a grouping ID for a given course ID from the calling activity
    */
    public function category_paymentdescription_callback()
    {

        $settings = (array) get_option( 'headstart_admission_settings' );
        $field = "category_paymentdescription";
        $value = esc_attr( $settings[$field] );

        echo "<textarea name='headstart_admission_settings[$field]' id='headstart_admission_settings[$field]'
            rows='10' cols='100'>" . $value . "</textarea>";

    }

    /**
    *  Comma separated list of category - fee 
    * for example: hsea-g1-internal:60000,next-category:next-fee-
    * This specifies a grouping ID for a given course ID from the calling activity
    */
    public function category_fee_callback()
    {

    $settings = (array) get_option( 'headstart_admission_settings' );
    $field = "category_fee";
    $value = esc_attr( $settings[$field] );

    echo "<textarea name='headstart_admission_settings[$field]' id='headstart_admission_settings[$field]'
            rows='10' cols='100'>" . $value . "</textarea>Do not enter extra spaces, No need for New line after last entry";

    }

    /**
    *  Comma separated list of ID numbers of users who need to be whitelsited
    * for these users no checks are done regarding their group or student category
    */
    public function whitelist_idnumbers_callback()
    {

    $settings = (array) get_option( 'headstart_admission_settings' );
    $field = "whitelist_idnumbers";
    $value = esc_attr( $settings[$field] );

    echo "<input type='text' name='headstart_admission_settings[$field]' id='headstart_admission_settings[$field]'
            value='$value' size='80' class='code' />example:HSEA001,WHS1234";

    }

    /**
    *  Comma separated list of permissible groups that should correspond to product categories
    * if student's group extracted from grouping is not in this user is rejected
    */
    public function group_possible_callback()
    {

    $settings = (array) get_option( 'headstart_admission_settings' );
    $field = "group_possible";
    $value = esc_attr( $settings[$field] );

    echo "<input type='text' name='headstart_admission_settings[$field]' id='headstart_admission_settings[$field]'
            value='$value' size='80' class='code' />example:grade4,grade5,grade6,grade7";

    }

    public function studentcat_possible_callback()
    {

    $settings = (array) get_option( 'headstart_admission_settings' );
    $field = "studentcat_possible";
    $value = strtolower(esc_attr( $settings[$field] ));

    echo "<input type='text' name='headstart_admission_settings[$field]' id='headstart_admission_settings[$field]'
            value='$value' size='80' class='code' />These should be exactly as defined in Moodle but in lower case";

    }

    /**
     * Get the settings option array and print comma separated ip_whitelsit string
    */
    public function domain_whitelist_callback()
    {

	$settings = (array) get_option( 'headstart_admission_settings' );
	$field = "domain_whitelist";
	$value = esc_attr( $settings[$field] );

    echo "<input type='text' name='headstart_admission_settings[$field]' id='headstart_admission_settings[$field]'
            value='$value' size='80' class='code' />example:cashfree.com,madhu.ddns.net";

    }

    /**
     * Get the settings option array and print comma separated ip_whitelsit string
    */
    public function ip_whitelist_callback()
    {

	$settings = (array) get_option( 'headstart_admission_settings' );
	$field = "ip_whitelist";
	$value = esc_attr( $settings[$field] );

	echo "<input type='text' name='headstart_admission_settings[$field]' id='headstart_admission_settings[$field]'
            value='$value' size='80' class='code' />example:24.12.10.1,30.18.27.1";

    }

	/**
     * Get the settings option array and print cashfree_key value
     */
    public function cashfree_key_callback()
    {

	$settings = (array) get_option( 'headstart_admission_settings' );
	$field = "cashfree_key";
	$value = esc_attr( $settings[$field] );

	echo "<input type='text' name='headstart_admission_settings[$field]' id='headstart_admission_settings[$field]'
            value='$value'  size='50' class='code' />Cashfree Account API access Key";

    }


	/**
     * Get the settings option array and print cashfree_secret value
     */
    public function cashfree_secret_callback()
    {
		$settings = (array) get_option( 'headstart_admission_settings' );
		$field = "cashfree_secret";
		$value = esc_attr( $settings[$field] );

        echo "<input type='password' name='headstart_admission_settings[$field]' id='headstart_admission_settings[$field]'
                value='$value'  size='50' class='code' />Cashfree Account API access Secret";
    }

    /**
     * Get the settings option array and print cashfree beneficiary name
     */
    public function cashfree_beneficiary_callback()
    {
        $settings = (array) get_option( 'headstart_admission_settings' );
		$field = "beneficiary_name";
		$value = esc_attr( $settings[$field] );

        echo "<input type='text' name='headstart_admission_settings[$field]' id='headstart_admission_settings[$field]'
                value='$value'  size='50' class='code' />Cashfree Account Beneficiary Name, ex: Head Start Educational Trust";
    }

	/**
     * Get the settings option array and print moodle_token value
     */
    public function sritoni_token_callback()
    {
        $settings = (array) get_option( 'headstart_admission_settings' );
		$field = "sritoni_token";
		$value = esc_attr( $settings[$field] );

        echo "<input type='password' name='headstart_admission_settings[$field]' id='headstart_admission_settings[$field]'
                value='$value'  size='50' class='code' />Token is an alphanumeric string, and not displayed due to security";
    }

    /**
     * Get the settings option array and print moodle_token value
     */
    public function sritoni_url_callback()
    {
        $settings = (array) get_option( 'headstart_admission_settings' );
		$field = "sritoni_url";
		$value = esc_attr( $settings[$field] );

        echo "<input type='text' name='headstart_admission_settings[$field]' id='headstart_admission_settings[$field]'
                value='$value'  size='50' class='code' />example:https://sritonilearningservices.com/sritoni no slash at end";
    }

	/**
     * Get the settings option array and print reconcile value
     */
    public function reconcile_callback()
    {
        $settings = (array) get_option( 'headstart_admission_settings' );
		$field = "reconcile";
		$checked = $settings[$field] ?? 0;

		?>
			<input name="headstart_admission_settings[reconcile]" id="headstart_admission_settings[reconcile]" type="checkbox"
                value="1" class="code"<?php checked( $checked, 1, true ); ?>/>
		<?php
    }

    /**
     *
     */
    public function production_callback()
    {
        $settings = (array) get_option( 'headstart_admission_settings' );
		$field = "production";
		$checked = $settings[$field] ?? 0;

		?>
			<input name="headstart_admission_settings[production]" id="headstart_admission_settings[production]" type="checkbox"
                value="1" class="code"<?php checked( $checked, 1, true ); ?>/>
		<?php
    }

    /**
     *  added in ver 1.3
     */
    public function verify_webhook_ip_callback()
    {
        $settings = (array) get_option( 'headstart_admission_settings' );
        $field = "verify_webhook_ip";
        $checked = $settings[$field] ?? 0;

        ?>
            <input name="headstart_admission_settings[verify_webhook_ip]" id="headstart_admission_settings[verify_webhook_ip]" type="checkbox"
                value="1" class="code"<?php checked( $checked, 1, true ); ?>/>
        <?php
    }

	/**
     * Sanitize each setting field as needed
     *
     * @param array $input Contains all settings fields as array keys
     */
    public function sanitize( $input )
    {

		$new_input = array();
        if( isset( $input['category_fee'] ) )
            $new_input['category_fee'] = sanitize_textarea_field( $input['category_fee'] );

        if( isset( $input['category_paymentdescription'] ) )
            $new_input['category_paymentdescription'] = sanitize_textarea_field( $input['category_paymentdescription'] );

		if( isset( $input['sritoni_token'] ) )
            $new_input['sritoni_token'] = sanitize_text_field( $input['sritoni_token'] );

        if( isset( $input['sritoni_url'] ) )
            $new_input['sritoni_url'] = sanitize_text_field( $input['sritoni_url'] );

        if( isset( $input['ip_whitelist'] ) )
            $new_input['ip_whitelist'] = sanitize_text_field( $input['ip_whitelist'] );

        if( isset( $input['domain_whitelist'] ) )
            $new_input['domain_whitelist'] = sanitize_text_field( $input['domain_whitelist'] );

		if( empty($input['reconcile']) )
            $new_input['reconcile'] = 0;

        if( empty($input['production']) )
            $new_input['production'] = 0;
		// added in ver 1.3
        if( empty($input['verify_webhook_ip']) )
            $new_input['verify_webhook_ip'] = 0;

        // added in ver 6
        if( isset( $input['beneficiary_name'] ) )
            $new_input['beneficiary_name'] = sanitize_text_field( $input['beneficiary_name'] );

        if( empty($input['get_csv_fees_file']) )
            $new_input['get_csv_fees_file'] = 0;

        if( isset( $input['csv_fees_file_path'] ) )
            $new_input['csv_fees_file_path'] = sanitize_text_field( $input['csv_fees_file_path'] );

        return $new_input;

    }



}
