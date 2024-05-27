<?php
/**
*Plugin Name: Head Start Admission by SriToni Learning Services
*Plugin URI:
*Description: Head Start Admissions Plugin - Takes care of Registration, forms, SriToni account, Admission payment
*Version: 2023042900
*Author: Madhu Avasarala
*Author URI: http://sritoni.org
*Text Domain: MA_HSA_headstart_admission
*Domain Path:
*/
// no direct access allowed
defined( 'ABSPATH' ) or die( 'No direct access allowed' );

if ( ! class_exists( 'MA_HSA_headstart_admission' ) ) :

	final class MA_HSA_headstart_admission {

    /**
		 * Plugin version
		 *
		 * @var string
		 */
		public static $version = '3.0.0';

    /**
		 * Constructor for main class
		 */
		public static function init() {

      

			self::define_constants();

			// add_action( 'init', array( __CLASS__, 'load_textdomain' ), 1 );

			self::load_files();
		}

    /**
		 * Defines global constants that can be availabel anywhere in WordPress
		 * MAHSA stands for Madhu Avasarala and HSA for Head Start Admissions
		 * @return void
		 *
		 */
		public static function define_constants() {

			self::define( 'MA_HSA_PLUGIN_FILE',      __FILE__ );
			self::define( 'MA_HSA_ABSPATH',          dirname( __FILE__ ) . '/' );
			self::define( 'MA_HSA_PLUGIN_URL',       plugin_dir_url( __FILE__ ) );
			self::define( 'MA_HSA_PLUGIN_BASENAME',  plugin_basename( __FILE__ ) );
			self::define( 'MA_HSA_VERSION',          self::$version );
			self::define( 'MA_HSA_PLUGIN_NAME',      'headstart_admission' );
		}

    /**
		 * Load all classes
		 *
		 * @return void
		 */
		private static function load_files() {

      		// Load common classes that are in the includes subdirectory
			foreach ( glob( MA_HSA_ABSPATH . 'includes/*.php' ) as $filename ) {

				include_once $filename;
			}
    	}

		/**
		 * Define constants
		 *
		 * @param string $name - name of global constant.
		 * @param string $value - value of constant.
		 * @return void
		 */
		private static function define( $name, $value ) {

			if ( ! defined( $name ) ) {
				define( $name, $value );
			}
		}
  }
endif;

// Initialize the complete plugin environment and classes
if ( is_admin() )
			{
				// This is to be done only once!!!!
				include_once  dirname( __FILE__ ) . '/class_headstart_admission_settings.php';
			}
MA_HSA_headstart_admission::init();

$admission_settings = new class_headstart_admission_settings();



use Automattic\WooCommerce\Client;
use Automattic\WooCommerce\HttpClient\HttpClientException;

