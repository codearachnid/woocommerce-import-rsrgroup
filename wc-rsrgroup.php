<?php

/**
 * Plugin Name: Import RSRGroup.com Inventory
 * Plugin URI:
 * Description: Import the rsrgroup.com database into your WooCommerce store.
 * Version: 1.0
 * Author: Timothy Wood (@codearachnid)
 * Author URI: http://www.codearachnid.com
 * Author Email: tim@imaginesimplicity.com
 * Text Domain: wc-rsrgroup
 * Requires: 3.5
 * License: GPLv3 or later
 * 
 * Notes: THIS FILE IS FOR LOADING THE LIBS ONLY
 * 
 * License:
 * 
 * Copyright 2013 Imagine Simplicity (tim@imaginesimplicity.com)
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * 
 */

class wc_import_rsrgroup {
	private static $_this;
	private $rsrgroup_path = array(
		'images' => 'http://dl.dropbox.com/u/17688322/RSR-Web-Images.zip',
		'inventory' => 'http://www.rsrgroup.com/dealer/ftpdownloads/fulfillment-inv-new.zip' );

	public $dir;
	public $path;
	public $url;
	public $plugin_data;

	const MIN_WP_VERSION = '3.5';

	function __construct() {

		// register lazy autoloading
		spl_autoload_register( 'self::lazy_loader' );

		$this->plugin_data = get_plugin_data( __FILE__ );

		$this->path = self::get_plugin_path();
		$this->dir = trailingslashit( basename( $this->path ) );
		$this->url = plugins_url() . '/' . $this->dir;

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
			sprintf( __( '%1$s requires WordPress v%2$s or higher.', 'wp-plugin-framework' ), 
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
function load_wc_import_rsrgroup() {

	// we assume class_exists( 'WPPluginFramework' ) is true
	if ( apply_filters( 'wc_rsrgroup_pre_check', wc_import_rsrgroup::prerequisites() ) ) {

		// when plugin is activated let's load the instance to get the ball rolling
		add_action( 'init', array( 'wc_import_rsrgroup', 'instance' ), -100, 0 );

	} else {

		// let the user know prerequisites weren't met
		add_action( 'admin_head', array( 'wc_import_rsrgroup', 'fail_notices' ), 0, 0 );

	}
}

// high priority so that it's not too late for addon overrides
add_action( 'plugins_loaded', 'load_wc_import_rsrgroup' );