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
	private $file_check_pattern = array(
		'archive' => '/[^\?]+\.(zip|ZIP|txt|TXT)/',
		'image' => '/[^\?]+\.(jpg|JPG|gif|GIF)/' );

	const MIN_WP_VERSION = '3.5';

	function __construct() {

		// register lazy autoloading
		spl_autoload_register( 'self::lazy_loader' );

		// filter for s3 images
		add_filter( 'wp_get_attachment_url', array( $this, 'wp_get_attachment_url' ), 10, 2 );
		add_filter( 'image_downsize', array( $this, 'image_downsize'), 10, 2);

		// admin only functionality
		if ( is_admin() ) {

			// ensure that $wp_filesystem is activated for this plugin
			if ( !function_exists( 'download_url' ) )
				require_once ABSPATH . 'wp-admin/includes/file.php';

			global $wp_filesystem;
			WP_Filesystem();
			
			// enable the settings
			$this->rsrgroup = new WC_RSRGroup_Integration();

			// TODO: fix " Call to undefined function get_plugin_data() "
			// $this->plugin_data = get_plugin_data( __FILE__ );

			$this->path = self::get_plugin_path();
			$this->dir = trailingslashit( basename( $this->path ) );
			$this->url = plugins_url() . '/' . $this->dir;

			add_action( 'init', array( $this, 'load_textdomain' ) );
			add_action( 'init', array( $this, 'load_custom_fields' ) );

			add_action('admin_menu', array( $this, 'admin_menu') );
			add_filter( 'manage_upload_columns', array( $this, 'manage_upload_columns' ) );
			add_action( 'manage_media_custom_column', array( $this, 'manage_media_custom_column' ), 10, 2 );

			add_action( 'woocommerce_rsrgroup_import_inventory', array( $this, 'import_inventory' ) );

			// WooCommerce actions
			add_action( 'woocommerce_product_options_pricing', array( $this, 'product_options_pricing' ) );
			add_action( 'woocommerce_product_options_sku', array( $this, 'product_options_sku' ) );
			add_action( 'woocommerce_process_product_meta_simple', array( $this, 'process_product_meta' ) );

		}

	}

	function manage_upload_columns( $columns ) {
		$columns['rsrgroup'] = 'RSR Image';    
		return $columns;
	}

	function manage_media_custom_column( $column_name, $post_id ) {
		if( 'rsrgroup' != $column_name || !wp_attachment_is_image( $post_id ) )
			return;

		$_rsrgroup_media = get_post_meta( $post_id, '_rsrgroup_media', true );
		
		echo $_rsrgroup_media ? 'Yes' : 'No';
	}

	function manual_import(){
		do_action( 'woocommerce_rsrgroup_import_inventory' );
		include 'views/manual-import.php';
	}

	function admin_menu(){
		add_submenu_page( 'woocommerce', __('RSR Group Manual Import'), __('RSR Group Import'), 'manage_woocommerce', 'woocommerce_rsrgroup', array( $this, 'manual_import' ));
	}

	/**
	 * wp_get_attachment_url if it is an rsrgroup image attachment we assume it's hosted on AWS S3
	 * so link to the image by _wp_attached_file
	 * 
	 * @param string  $url
	 * @param int     $attachment_id
	 * @return string $url
	 */
	public function wp_get_attachment_url( $url, $attachment_id ) {
		if ( get_post_meta( $attachment_id, '_rsrgroup_media', true ) ) {
			$url = get_post_meta( $attachment_id, '_wp_attached_file', true );
		}
		return $url;
	}

	/**
	 * image_downsize if it is an rsrgroup image attachment we assume it's hosted on AWS S3,
	 * cue taken from image_downsize for getting image size
	 * 
	 * @param string  $image
	 * @param int     $attachment_id
	 * @return string $url
	 */
	public function image_downsize( $image = false, $attachment_id ) {
		if ( get_post_meta( $attachment_id, '_rsrgroup_media', true ) ) {
			$img_url = get_post_meta( $attachment_id, '_wp_attached_file', true );

			// cue taken from image_downsize for getting image size
			$meta = wp_get_attachment_metadata($attachment_id);
			$width = !empty($meta['width']) ? $meta['width']: null;
			$height = !empty($meta['height']) ? $meta['height']: null;
			$image = array( $img_url, $width, $height );
		}
		return $image;
	}

	/**
	 * product_options_sku adds a UPC and Part # field for RSR Group
	 *
	 * @return void
	 */
	function product_options_sku() {
		woocommerce_wp_text_input( array( 'id' => '_rsrgroup_upc', 'label' => '<abbr title="'. __( 'UPC', 'woocommerce_rsrgroup' ) .'">' . __( 'RSR UPC', 'woocommerce_rsrgroup' ) . '</abbr>', 'desc_tip' => 'true' ) );
		woocommerce_wp_text_input( array( 'id' => '_rsrgroup_manufacturer_part_num', 'label' => '<abbr title="'. __( 'Part Num', 'woocommerce_rsrgroup' ) .'">' . __( 'RSR Manufacturer Part #', 'woocommerce_rsrgroup' ) . '</abbr>', 'desc_tip' => 'true' ) );
	}

	/**
	 * product_options_pricing adds a pricing field for the custom RSR Group price (product editor display only)
	 *
	 * @return void
	 */
	function product_options_pricing() {
		// RSR Group Price
		woocommerce_wp_text_input( array( 'id' => '_rsrgroup_price', 'class' => 'wc_input_price short', 'label' => __( 'RSR Regular Price', 'woocommerce_rsrgroup' ) . ' ('.get_woocommerce_currency_symbol().')', 'type' => 'number', 'custom_attributes' => array(
					'step'  => 'any',
					'min' => '0'
				) ) );

	}

	/**
	 * process_product_meta updates the post meta field for the `product_options_pricing()` fields
	 *
	 * @param int     $post_id
	 * @return void
	 */
	function process_product_meta( $post_id ) {

		// relates to product_options_sku()
		update_post_meta( $post_id, '_rsrgroup_upc', stripslashes( $_POST['_rsrgroup_upc'] ) );
		update_post_meta( $post_id, '_rsrgroup_manufacturer_part_num', stripslashes( $_POST['_rsrgroup_manufacturer_part_num'] ) );

		// relates to product_options_pricing()
		update_post_meta( $post_id, '_rsrgroup_price', stripslashes( $_POST['_rsrgroup_price'] ) );
	}

	public function import_inventory() {
		global $wp_filesystem, $woocommerce, $wpdb;

		$wp_upload_dir = wp_upload_dir();
		$inventory_file = $this->remote_inventory_request();

		// WARNING: this next step could use a chunk of memory in case your server throttles

		// retrieve the raw data from the file
		$inventory_data = $wp_filesystem->get_contents( $inventory_file['path_file'] );

		// split out new rows
		$inventory_rows = explode( "\n", $inventory_data );

		foreach ( new LimitIterator( new ArrayIterator($inventory_rows), 4300) as $key => $row ) {
			// see rsr_inventory_file_layout.txt for specifics to var + position
			list( $sku,
				$upc,
				$title,
				$rsrgroup_cat_id,
				$manufacturer_id,
				$regular_price,
				$rsrgroup_price,
				$weight,
				$inventory,
				$tag,
				$manufacturer_name,
				$manufacturer_part_num,
				$status,
				$description,
				$image_file ) = str_getcsv( $row, ';', '', '' );

			$sku = trim( strtoupper( $sku ) );
			$image_file = trim( strtoupper( $image_file ) );

			$product_id = $wpdb->get_col( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_sku' AND meta_value = '{$sku}' LIMIT 1;" );

			if ( empty( $product_id ) ) {

				switch ( strtolower( $status ) ) {
					case 'allocated':
						$status = 'pending';
						break;
					case 'deleted':
						$status = 'publish';
						break;
					case 'closeout':
					default :
						$status = 'publish';
						break;
				}

				// Create post object
				$import_product = array(
					'post_type' => 'product',
					'ping_status' => 'closed',
					'post_title' => $title,
					'post_content' => $description,
					'post_status' => $status,
					'tax_input' => array( 'product_tag' => array( $tag ) )
				);

				// insert the product into the database
				$product_id = wp_insert_post( $import_product );

				// only needed for new product imports
				add_post_meta( $product_id, '_visibility', 'visible', true );
				add_post_meta( $product_id, '_regular_price', $regular_price, true );
				add_post_meta( $product_id, '_price', $regular_price, true );

			} else if( $this->rsrgroup->settings['cloudfront'] != 'yes' ) {
				$product_id = $product_id[0];
			}

			if ( !empty( $product_id ) && $this->rsrgroup->settings['cloudfront'] != 'yes' ) {

				$remote_image = $this->remote_media_file( $image_file );
				$attach_id = $this->import_image( $remote_image, $product_id, $title );

				// on reimport update product attributes
				update_post_meta( $product_id, '_stock_status', 'instock' );
				update_post_meta( $product_id, '_weight', $weight );
				update_post_meta( $product_id, '_sku', $sku );
				update_post_meta( $product_id, '_stock', $inventory );
				update_post_meta( $product_id, '_rsrgroup_price', $rsrgroup_price );
				update_post_meta( $product_id, '_rsrgroup_upc', $upc );
				update_post_meta( $product_id, '_rsrgroup_manufacturer_part_num', $manufacturer_part_num );
			}
		}
	}

	function import_image( $image, $post_id, $title = '' ) {
		global $wpdb;

		$attachment_id = $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND post_parent = '{$post_id}';" );
		$full_path = trailingslashit( $image['path'] ) . $image['name'];
		$title = empty( $title ) ? $image['name'] : $title;

		if ( empty( $attachment_id ) ) {

			$attachment = array(
				'guid' => $full_path,
				'post_mime_type' => $image['type'],
				'post_title' => $title,
				'post_content' => '',
				'post_status' => 'inherit'
			);
			$attachment_id = wp_insert_attachment( $attachment, $full_path, $post_id );

		} else {
			$attachment_id = $attachment_id[0];
		}

		set_post_thumbnail( $post_id, $attachment_id );

		// attach metadata for attachment
		if ( !function_exists( 'wp_generate_attachment_metadata' ) )
			require_once ABSPATH . 'wp-admin/includes/image.php';
		$attachment_data = wp_generate_attachment_metadata( $attachment_id, $full_path );
		wp_update_attachment_metadata( $attachment_id, $attachment_data );

		// set key to flag as custom media hosted on s3
		update_post_meta( $attachment_id, '_rsrgroup_media', true );

		return $attachment_id;
	}

	function remote_media_file( $image_file ) {
		// S3 and some linux installs choke on capitalized filenames
		$image_file = str_replace( '.JPG', '.jpg', $image_file );

		// set encoded # for digit folders
		$folder = ctype_digit( $image_file[0] ) ? '%23' : strtoupper( $image_file[0] );

		$image['path'] = trailingslashit( $this->rsrgroup->settings['cloudfront'] ) . $folder;
		$image['name'] = $image_file;
		$image = array_merge( $image, wp_check_filetype_and_ext( trailingslashit( $image['path'] ) . $image['name'], $image['name'] ) );

		return $image;
	}

	function get_file( $remote_file, $type = 'archive' ) {
		global $wp_filesystem;

		// get local dir properties
		$wp_upload_dir = wp_upload_dir();
		$inventory_file = array();

		// download remove archived inventory csv
		$tmp_remote_inventory = download_url( $remote_file );

		// regex match file type from the remote request url
		preg_match( $this->file_check_pattern[ $type ], $remote_file, $file_check );

		// check if the file type is compatible
		if ( empty( $file_check[1] ) && ! in_array( strtolower( $file_check[1] ) , array( 'zip', 'txt' ) ) ) {
			return new WP_Error( 'incompatible_archive', __( 'Incompatible inventory file type.', 'woocommerce_rsrgroup' ) );
		} else {
			$inventory_file['name'] = basename( $file_check[0] );
			$inventory_file['type'] = $file_check[1];
			$inventory_file['path_file'] = trailingslashit( $wp_upload_dir['path'] ) . $inventory_file['name'];
		}

		// store the temp file path in array
		if ( is_wp_error( $tmp_remote_inventory ) ) {
			@unlink( $tmp_remote_inventory );
			return new WP_Error( 'archive_storage_error', __( 'There was an issue storing the temporary file to import.', 'woocommerce_rsrgroup' ) );
		} else {
			$inventory_file['tmp_file'] = $tmp_remote_inventory;
		}
		return $inventory_file;
	}

	function remote_inventory_request() {
		global $wp_filesystem;

		// get local dir properties
		$wp_upload_dir = wp_upload_dir();

		$inventory_file = $this->get_file( $this->rsrgroup->settings['remote_inventory'] );

		// merge current inventory file arrays with filetype + ext check
		$inventory_file = array_merge( $inventory_file, wp_check_filetype_and_ext( $inventory_file['tmp_file'], $inventory_file['name'] ) );

		// unarchive the inventory file if needed
		if ( $inventory_file['ext'] == 'zip' && unzip_file( $inventory_file['tmp_file'], $wp_upload_dir['path'] ) ) {

			// remove the tmp file upon successful unarchive
			@unlink( $inventory_file['tmp_file'] );
			$inventory_file['tmp_file'] = '';

			// we assume the text file will be the same name as the archive given the RSRGroup.com data consistency (to date)
			$inventory_file['name'] = str_replace( '.zip', '.txt', $inventory_file['name'] );

			// reset the path to the file for use during parse
			$inventory_file['path_file'] = trailingslashit( $wp_upload_dir['path'] ) . $inventory_file['name'];

			// merge current inventory file arrays with filetype + ext check
			$inventory_file = array_merge( $inventory_file, wp_check_filetype_and_ext( $inventory_file['path_file'], $inventory_file['name'] ) );

			// skip erroring if direct txt file is retrieved
		} else if ( $inventory_file['ext'] == 'zip' ) {
				return new WP_Error( 'unarchive_error', __( 'There was an issue extracting the inventory to import.', 'woocommerce_rsrgroup' ) );
			}

		return $inventory_file;
	}

	/**
	 * load_custom_fields sets "Advanced Custom Fields" groups for the plugin
	 *
	 * @return void
	 */
	public function load_custom_fields() {
		/**
		 * Register field groups
		 * The register_field_group function accepts 1 array which holds the relevant data to register a field group
		 * You may edit the array as you see fit. However, this may result in errors if the array is not compatible with ACF
		 * This code must run every time the functions.php file is read
		 */

		if ( function_exists( "register_field_group" ) ) {
			register_field_group( array(
					'id' => '5148745a32ec5',
					'title' => 'RSRGroup Brands Fields',
					'fields' =>
					array(
						0 =>
						array(
							'key' => 'field_2',
							'label' => 'RSR Group Brand',
							'name' => 'rsrgroup_brand_id',
							'type' => 'select',
							'order_no' => 0,
							'instructions' => 'Select a RSR Group manufacture to associate during import.',
							'required' => 0,
							'conditional_logic' =>
							array(
								'status' => 0,
								'rules' =>
								array(
									0 =>
									array(
										'field' => 'field_2',
										'operator' => '==',
										'value' => '',
									),
								),
								'allorany' => 'all',
							),
							'choices' =>
							array(
								511 => '5.11 Tactical',
							),
							'default_value' => '',
							'allow_null' => 1,
							'multiple' => 0,
						),
					),
					'location' =>
					array(
						'rules' =>
						array(
							0 =>
							array(
								'param' => 'ef_taxonomy',
								'operator' => '==',
								'value' => 'product_brand',
								'order_no' => 0,
							),
						),
						'allorany' => 'all',
					),
					'options' =>
					array(
						'position' => 'normal',
						'layout' => 'no_box',
						'hide_on_screen' =>
						array(
						),
					),
					'menu_order' => 0,
				) );
			register_field_group( array(
					'id' => '5148745a338d0',
					'title' => 'RSRGroup Category Fields',
					'fields' =>
					array(
						0 =>
						array(
							'key' => 'field_1',
							'label' => 'RSR Group Category',
							'name' => 'rsrgroup_cat_id',
							'type' => 'select',
							'order_no' => 0,
							'instructions' => 'Select a RSR Group category to associate during import.',
							'required' => 0,
							'conditional_logic' =>
							array(
								'status' => 0,
								'rules' =>
								array(
									0 =>
									array(
										'field' => 'field_1',
										'operator' => '==',
										'value' => 1,
									),
								),
								'allorany' => 'all',
							),
							'choices' =>
							array(
								1 => 'Handguns',
								2 => 'Used Handguns',
								3 => 'Used Long Guns',
								4 => 'Tasers',
								5 => 'Sporting Long Guns',
								6 => 'Not Used',
								7 => 'Black Powder Firearms',
								8 => 'Scopes',
								9 => 'Scope Mounts',
								10 => 'Magazines',
								11 => 'Grips/Pads/Stocks',
								12 => 'Soft Gun Cases',
								13 => 'Misc. Accessories',
								14 => 'Holsters',
								15 => 'Reloading Equipment',
								16 => 'Black Powder Accessories',
								17 => 'Closeout Accessories',
								18 => 'Ammunition',
								19 => 'Not Used',
								20 => 'Flashlights & Batteries',
								21 => 'Cleaning Equipment',
								22 => 'Airguns',
								23 => 'Knives',
								24 => 'High Capacity Magazines',
								25 => 'Safes/Security',
								26 => 'Safety/Protection',
								27 => 'Non:Lethal Defense',
								28 => 'Binoculars',
								29 => 'Spotting Scopes',
								30 => 'Sights/Lasers/Lights',
								31 => 'Optical Accessories',
								32 => 'Barrels/Choke Tubes',
								33 => 'Clothing',
								34 => 'Parts',
								35 => 'Slings/Swivels',
								36 => 'Electronics',
								37 => 'Not Used',
								38 => 'Books/Software',
								39 => 'Targets',
								40 => 'Hard gun Cases',
								41 => 'Upper Receivers/Conv Kits',
								42 => 'Not Used',
								43 => 'Upper/Conv Kits:High Cap',
							),
							'default_value' => '',
							'allow_null' => 1,
							'multiple' => 0,
						),
					),
					'location' =>
					array(
						'rules' =>
						array(
							0 =>
							array(
								'param' => 'ef_taxonomy',
								'operator' => '==',
								'value' => 'product_cat',
								'order_no' => 0,
							),
						),
						'allorany' => 'all',
					),
					'options' =>
					array(
						'position' => 'normal',
						'layout' => 'no_box',
						'hide_on_screen' =>
						array(
						),
					),
					'menu_order' => 0,
				) );
		}


	}

	/**
	 * load_textdomain load the l18n textdomain
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'woocommerce_rsrgroup', false, dirname( plugin_basename( __FILE__ ) ) );
	}

	public function activation() {
		wp_schedule_event( time(), 'daily', 'woocommerce_rsrgroup_import_inventory' );
	}

	public function deactivation() {
		wp_clear_scheduled_hook( 'woocommerce_rsrgroup_import_inventory' );
	}

	/**
	 * lazy_loader because I would rather lazy load than explicitely call require_once
	 *
	 * @param string  $class_name
	 * @return void
	 */
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
			) );
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
// register_deactivation_hook( __FILE__, array( 'WC_RSRGroup', 'deactivation' ) );
// register_activation_hook( __FILE__, array( 'WC_RSRGroup', 'activation' ) );
