<?php

if ( !defined( 'ABSPATH' ) )
	die( '-1' );

/**
 * WC_RSRGroup_Integration class.
 *
 * @extends WC_Integration
 */
class WC_RSRGroup_Integration extends WC_Integration {

	private $default;
	protected static $_this;

	const PREFIX = 'woocommerce_rsrgroup_';

	public function __construct() {

		$this->id = 'rsrgroup';
        $this->method_title = __( 'RSRGroup.com Inventory', 'woocommerce_rsrgroup' );
        $this->method_description = __( 'Import the RSRGroup.com product inventory.', 'woocommerce_rsrgroup' );

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Actions
		add_action( 'woocommerce_update_options_integration_rsrgroup', array( $this, 'process_admin_options') );

	}

	/**
     * Initialise Settings Form Fields
     *
     * @access public
     * @return void
     */
    function init_form_fields() {

    	$this->form_fields = array(
			'remote_inventory' => array(
				'title' 			=> __( 'Remote Inventory Feed', 'woocommerce_rsrgroup' ),
				'description' 		=> __( 'Provide the link to the inventory feed. Note: we assume that it will be in zip format.', 'woocommerce_rsrgroup' ),
				'type' 				=> 'text',
		    	'default' 			=> 'http://www.rsrgroup.com/dealer/ftpdownloads/fulfillment-inv-new.zip'
			),
			'cloudfront' => array(
				'title' 			=> __( 'Image Domain', 'woocommerce_rsrgroup' ),
				'description' 		=> __( 'Consider setting up a CloudFront domain and uploading the images for inventory there for speed.', 'woocommerce_rsrgroup' ),
				'type' 				=> 'text',
		    	'default' 			=> 'http://rsrgroup.imaginesimplicity.com/'
			),
			'load_images' => array(
				'title' 			=> __( 'Load images on import', 'woocommerce_rsrgroup' ),
				'description' 		=> __( 'Will use a predefined CloudFront archive to import media references.', 'woocommerce_rsrgroup' ),
				'default'	=> 'yes',
				'type' 		=> 'checkbox'
			)
		);

    } // End init_form_fields()

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
 * Add the integration to WooCommerce.
 *
 * @package		WooCommerce/Classes/Integrations
 * @access public
 * @param array $integrations
 * @return array
 */
function add_rsrgroup_integration( $integrations ) {
	$integrations[] = 'WC_RSRGroup_Integration';
	return $integrations;
}

add_filter('woocommerce_integrations', 'add_rsrgroup_integration' );
