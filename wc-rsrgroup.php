<?php

/**
 * Plugin Name: Import RSRGroup.com Inventory
 * Plugin URI:
 * Description: Import the rsrgroup.com database into your WooCommerce store.
 * Version: 1.0
 * Author: Timothy Wood (@codearachnid)
 * Author URI: http://www.codearachnid.com
 * Author Email: tim@imaginesimplicity.com
 * Text Domain: woocommerce_rsrgroup
 * Requires: 3.5
 * License: GPLv3 or later
 * 
 * Notes: 
 *
 *     Inventory: http://www.rsrgroup.com/dealer/ftpdownloads/fulfillment-inv-new.zip
 *     Images: http://dl.dropbox.com/u/17688322/RSR-Web-Images.zip
 *
 * License:
 * 
 * Copyright 2013 Imagine Simplicity (tim@imaginesimplicity.com)
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * 
 */

class WC_RSRGroup {

	private static $_this;
	private $rsrgroup;
	private $plugin_data;
	private $dir;
	private $path;
	private $url;

	const MIN_WP_VERSION = '3.5';

	function __construct() {

		if ( is_admin() ) {

			// register lazy autoloading
			spl_autoload_register( 'self::lazy_loader' );

			// enable the settings	
			$this->rsrgroup = new WC_RSRGroup_Integration();

			$this->plugin_data = get_plugin_data( __FILE__ );

			$this->path = self::get_plugin_path();
			$this->dir = trailingslashit( basename( $this->path ) );
			$this->url = plugins_url() . '/' . $this->dir;

			add_action('init', array( $this, 'load_textdomain' ) );

			add_action('woocommerce_rsrgroup_import_inventory', array( $this, 'import_inventory' ) );
			do_action( 'woocommerce_rsrgroup_import_inventory' );
		}

	}

	public function import_inventory(){

		// get local dir properties
		$wp_upload_dir = wp_upload_dir();

		// download remove archived inventory csv
		$remote_inventory = download_url( $this->rsrgroup->settings['remote_inventory'] );

		// die gracefully if we don't have a file downloaded
		if( empty($remote_inventory) )
			return false;

		// extract the csv from the zip
		if ( unzip_file( $remote_inventory, $wp_upload_dir['path'] ) ) {
			// Now that the zip file has been used, destroy it
			unlink($remote_inventory);
		} else {
			return false;
		}
		
	}

	public function load_textdomain() {
		load_plugin_textdomain('woocommerce_rsrgroup', false, dirname(plugin_basename(__FILE__)));
	}

	public function activation(){
		wp_schedule_event( time(), 'daily', 'woocommerce_rsrgroup_import_inventory');
	}

	public function deactivation(){
		wp_clear_scheduled_hook('woocommerce_rsrgroup_import_inventory');
	}

	public static function lazy_loader( $class_name ) {

		$file = self::get_plugin_path() . 'classes/' . $class_name . '.php';

		if ( file_exists( $file ) )
			require_once $file;

	}

	public static function get_plugin_path() {
		return trailingslashit( dirname( __FILE__ ) );
	}

	/**
	* Check the minimum WP version and if TribeEvents exists
	*
	* @static
	* @return bool Whether the test passed
	*/
	public static function prerequisites() {;
		$pass = TRUE;
		$pass = $pass && version_compare( get_bloginfo( 'version' ), self::MIN_WP_VERSION, '>=' );
		return $pass;
	}

	public static function fail_notices() {
		printf( '<div class="error"><p>%s</p></div>', 
			sprintf( __( '%1$s requires WordPress v%2$s or higher.', 'woocommerce_rsrgroup' ), 
				$this->plugin_data['Name'], 
				self::MIN_WP_VERSION 
			));
	}

	/**
	 * Static Singleton Factory Method
	 * 
	 * @return static $_this instance
	 * @readlink http://eamann.com/tech/the-case-for-singletons/
	 */
	public static function instance() {
		if ( !isset( self::$_this ) ) {
			$className = __CLASS__;
			self::$_this = new $className;
		}
		return self::$_this;
	}
}

/**
 * Instantiate class and set up WordPress actions.
 *
 * @return void
 */
function Load_WC_RSRGroup() {

	// we assume class_exists( 'WPPluginFramework' ) is true
	if ( apply_filters( 'wc_rsrgroup_pre_check', WC_RSRGroup::prerequisites() ) ) {

		// when plugin is activated let's load the instance to get the ball rolling
		add_action( 'init', array( 'WC_RSRGroup', 'instance' ), -100, 0 );

	} else {

		// let the user know prerequisites weren't met
		add_action( 'admin_head', array( 'WC_RSRGroup', 'fail_notices' ), 0, 0 );

	}
}

// high priority so that it's not too late for addon overrides
add_action( 'plugins_loaded', 'Load_WC_RSRGroup' );
register_deactivation_hook( __FILE__, array( 'WC_RSRGroup', 'deactivation' ));
register_activation_hook( __FILE__, array( 'WC_RSRGroup', 'activation' ));