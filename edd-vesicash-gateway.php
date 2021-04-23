<?php
/*
 * Plugin Name: Easy Digital Downloads - Vesicash Gateway
 * Plugin URI:  https://vesicash.com/vesicash-edd-gateway
 * Description: Vesicash extension for Easy Digital Downloads.
 * Version:     1.0.0
 * Author:      Vesicaash
 * Author URI:   https://vesicash.com
 * Text Domain: edd-vesicash-gateway
 * Domain Path: /languages/
 */

final class EDD_Vesicash_Gateway {

	/**
	 * Vesicash option for binding item numbers to downloads
	 *
	 * @var string
	 */
	public static $vesicash_option = 'vesicash_items';

	/**
	 * Construct
	 */
	public function __construct() {

		if ( class_exists( 'EDD_License' ) ) {
			$license = new EDD_License(
				__FILE__,
				'EDD Vesicash Gateway',
				'1.0.0',
				'Vesicash',
				null,
				null,
				39237
			);

			unset( $license );
		}

		add_filter( 'edd_settings_gateways', array( $this, 'vesicash_settings' ) );
		add_action( 'edd_Vesicash_cc_form', '__return_null' );
		add_action( 'add_meta_boxes',        array( $this, 'add_meta_box' ) );
		add_action( 'save_post',             array( $this, 'save_post' ) );
		add_filter( 'edd_purchase_link_defaults', array( $this, 'edd_purchase_link_defaults' ) );
		add_filter( 'edd_straight_to_gateway_purchase_data', array( $this, 'edd_straight_to_gateway_purchase_data' ) );
		add_action( 'edd_gateway_Vesicash', array( $this, 'edd_gateway_Vesicash' ) );
		add_action( 'init',                  array( $this, 'vesicash_process_payment' ) );
		add_action( 'init',                  array( $this, 'process_vesicash_purchase_confirmation_url' ) );
	}

	/**
	 * Vesicash settings
	 *
	 * @param array $settings
	 * @return array merged settings
	 */
	public function vesicash_settings( $settings ) {
		$vesicash_gateway_settings = array(
			array(
				'id'   => 'vesicash_settings',
				'name' => '<strong>' . __( 'Vesicash Settings', 'edd-vesicash-gateway' ) . '</strong>',
				'desc' => __( 'Configure the gateway settings', 'edd-vesicash-gateway' ),
				'type' => 'header'
			),
			array(
				'id'   => 'process_vesicash_purchase_confirmation_url',
				'name' => __( 'Business ID', 'edd-vesicash-gateway' ),
				'desc' => '',
				'type' => 'text',
				'size' => 'regular',
			),
			array(
				'id'   => 'vesicash_secret_key',
				'name' => __( 'Secret Key', 'edd-vesicash-gateway' ),
				'desc' => '',
				'type' => 'text',
				'size' => 'regular',
			),
		);

		return array_merge( $settings, $vesicash_gateway_settings );

	}

	/**
	 * Register Vesicash metabox.
	 */
	public function add_meta_box() {
		add_meta_box(
			'edd_vesicash',
			__( 'Vesicash Item Number', 'edd-vesicash-gateway' ),
			array( $this, 'render_meta_box' ),
			'download',
			'side'
		);
	}

	/**
	 * Render Vesicash metabox.
	 *
	 * @param object $post
	 */
	public function render_meta_box( $post ) {
		global $edd_options;

		$item = self::get_vesicash_item( $post->ID );
		wp_nonce_field( plugin_basename( __FILE__ ), 'vesicash_nonce' );
		?>
		<div class="tagsdiv">
			<?php if ( empty( $edd_options['process_vesicash_purchase_confirmation_url'] ) || empty( $edd_options['vesicash_secret_key'] ) ) : ?>
				<p><a href="<?php echo admin_url( 'edit.php?post_type=download&page=edd-settings&tab=gateways' ); ?>"><?php _e( 'Update your Vesicash payment gateway settings.', 'edd-vesicash-gateway' ); ?></a></p>
			<?php else : ?>
				<p><input type="text" autocomplete="off" class="widefat" name="_item" id="vesicash_item" value="<?php echo esc_attr( $item ); ?>"></p>
				<p class="howto"><?php _e('Redirect user to the given Vesicash item during checkout.', 'edd-vesicash-gateway'); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save post data.
	 *
	 * @param string $post_id
	 */
	public function save_post( $post_id ) {
		if (
			isset( $_POST['vesicash_nonce'] )
			&& wp_verify_nonce( $_POST['vesicash_nonce'], plugin_basename( __FILE__ ) )
			&& ! defined( 'DOING_AUTOSAVE' )
			&& current_user_can( 'edit_post', $post_id )
		) {
			$item = ! empty( $_POST['vesicash_item'] ) ? esc_html( $_POST['vesicash_item'] ) : null;
			self::update_vesicash_items( $item, $post_id );
		}
	}

	/**
	 * Alter Vesicash product purchase links to post directly to payment gateway.
	 *
	 * @since  1.0.0
	 *
	 * @param  array $args EDD Purchase Link args.
	 * @return array       Updated EDD Purchase Link args.
	 */
	public function edd_purchase_link_defaults( $args ) {

		$item = self::get_vesicash_item( $args['download_id'] );

		if ( ! empty( $item ) ) {
			$args['direct'] = true;
			add_filter( 'edd_shop_supports_buy_now', '__return_true' );
		}

		return $args;
	}

	/**
	 * Alter straight_to_gateway to force Vesicash as gateway for products.
	 *
	 * @since  1.0.0
	 *
	 * @param  array $purchase_data EDD Purchase Data.
	 * @return array                Updated EDD Purchase Data.
	 */
	public function edd_straight_to_gateway_purchase_data( $purchase_data ) {
		// edd_send_to_gateway() calls action "edd_gateway_{$gateway}" and sends $payment_data
		// $payment_data is built via edd_build_straight_to_gateway_data( $download_id, $options, $quantity )
		// download post ID is stored in $payment_data['downloads'][0]['id']

		$item = self::get_vesicash_item( $purchase_data['downloads'][0]['id'] );

		if ( ! empty( $item ) ) {
			$purchase_data['gateway'] = 'Vesicash';
			add_filter( 'edd_enabled_payment_gateways', array( $this, 'edd_enabled_payment_gateways' ) );
		}

		return $purchase_data;
	}

	/**
	 * Include Vesicash as an enabled payment gateway.
	 *
	 * @since  1.0.0
	 *
	 * @param  array $gateways EDD Payment Gateways.
	 * @return array           Updated EDD Payment Gateways.
	 */
	function edd_enabled_payment_gateways( $gateways ) {
		$gateways['Vesicash'] = array(
			'admin_label'    => __( 'Vesicash', 'edd-vesicash-gateway' ),
			'checkout_label' => __( 'Vesicash', 'edd-vesicash-gateway' ),
			'supports' => array(
				'Pay With Vesicash Escrow',
			),
		);
		return $gateways;
	}

	/**
	 * Redirect customers to Vesicash during checkout.
	 *
	 * @since  1.0.0
	 *
	 * @param  array $payment_data EDD Purchase Data.
	 */
	public function edd_gateway_Vesicash( $payment_data ) {
		global $edd_options;

		$item = self::get_vesicash_item( $payment_data['downloads'][0]['id'] );

		if ( ! empty( $item ) && ! empty( $edd_options['process_vesicash_purchase_confirmation_url'] ) && ! empty( $edd_options['vesicash_secret_key'] ) ) {
				
				if ($this->api_url == 'https://sandbox.api.vesicash.com/v1/') {
					return array(
						'result' => 'success',
						'redirect' => sprintf( "%s%s", 'https://sandbox.vesicash.com/checkout/', $this->trans_details->data->transaction->transaction_id )
					);
				}
			wp_redirect( sprintf(
				'https://sandbox.vesicash.com/checkout/',
				$item
			) );
			die; 
			die;
		}
	}

	
	/**
	 * Send Request to Vesicash API
	 */
	private function call_vesicash_api($request, $endpoint) {
    
		// Get properties relevant to the API call.
		$v_private_key = $edd_options['vesicash_secret_key'];
		$api_url = $edd_options['api_url'];
		
		$response = wp_remote_post( $api_url . $endpoint, array(
			'method' => 'POST',
			'headers' => array(
				'Content-Type' => 'application/json',
				'V-PRIVATE-KEY'=> $v_private_key,
			),
			// 'sslverify' => true,
			'body' => json_encode($request)
		));
		
		// if ( is_wp_error( $response ) ) {
		//     wc_add_notice( __('Transaction Request error:', 'vesicash') . $response->get_error_message(), 'error' );
		//     return false;
		// }
	
	   $body = json_decode($response['body']);
	
	   $this->trans_details = $body;
	
	   if( $body && $body->status == "ok" ) {
			return true;
	   } else {
			   // $this->processError($body->data, 'Transaction Error');
	   }
	
	   return false;
	}
	/**
	 * Process payment for Vesicash gateway.
	 */
	public function vesicash_process_payment() {
		global $edd_options;

		if ( self::vesicash_data_received() && ! empty( $edd_options['vesicash_secret_key'] ) ) {

			$this->log( 'Vesicash data received. GET: ' . print_r( $_GET, true ) );

			$order = $edd_options['order'];
			// Get the single-vendor non-broker request for the order.
			$request = $order->details;
			
			// Create a draft transaction on vesicash API.
			$response = $this->call_vesicash_api($request, 'transactions/create');

			if ($response == false && !$this->trans_details) {
				return;
			} elseif( !$response && @$this->trans_details->status == "error" ) {
				
				// Return related API error to user
				// $errmsg = current( current( $this->trans_details ) );

				// $this->processError($this->trans_details->data, 'Payment error:');

				// wc_add_notice( sprintf( "%s %s", __('Payment error:', 'vesicash'), $errmsg ), 'error' );
				return;
			}

		
			
		}
	}

	private static function vesicash_data_received() {
		return ( ! empty( $_GET['item'] ) && ! empty( $_GET['vesicash_receipt'] ) && ! empty( $_GET['time'] ) && ! empty( $_GET['cbpop'] ) && ! empty( $_GET['cname'] ) && ! empty( $_GET['cemail'] ) );
	}


	private static function set_current_session( $product_id = 0 ) {
		EDD()->session->set( 'edd_cart', array(
			array(
				'id' => absint( $product_id ),
				'options' => array(),
			),
		) );
	}
	
	private static function get_edd_product_id( $item = 0 ) {
		$vesicash_items = self::get_vesicash_items();
		return array_search( $item, $vesicash_items );
	}

	private static function get_vesicash_item( $product_id = 0 ) {
		$vesicash_items = self::get_vesicash_items();
		return isset( $vesicash_items[ $product_id ] ) ? esc_html( $vesicash_items[ $product_id ] ) : null;
	}

	private static function update_vesicash_items( $item = 0, $post_id = 0 ) {
		$vesicash_items = self::get_vesicash_items();

		// Only save the item ID if it's not already set for another post
		if ( ! empty( $item ) && false === self::get_edd_product_id( $item ) ) {
			$vesicash_items[ $post_id ] = $item;
			edd_debug_log( 'Vesicash metabox saved/updated. Item value submitted: ' . $item . '. Array of Vesicash items is now: . ' . json_encode( $vesicash_items ) );
		}else{
			edd_debug_log( 'Vesicash metabox not saved/updated. Item value submitted: ' . $item . '. Array of pre-existing Vesicash items is: . ' . json_encode( $vesicash_items ) );
		}

		// Delete setting for this post if we no longer have an item ID
		if ( empty( $item ) && isset( $vesicash_items[ $post_id ] ) ) {
			unset( $vesicash_items[ $post_id ] );
		}

		self::set_vesicash_items( $vesicash_items );
	}

	private static function get_vesicash_items() {

		// Get the Vesicash items that have been saved to the wp_option
		$inaccurate_vesicash_items = get_option( self::$vesicash_option, array() );

		// Set the arrays up that we will use to rebuild an accurate list of Vesicash products
		$vesicash_post_ids = array();
		$accurate_vesicash_items = array();

		// Loop through each innacurate Vesicash EDD Product to make sure it wasn't deleted
		foreach( $inaccurate_vesicash_items as $post_id => $vesicash_item_number ) {

			$post = new EDD_Download( $post_id );

			// If this product doesn't exist, don't add it to the list of accurate Vesicash products
			if ( ! $post->ID ) {
				continue;
			}

			// If this post still exists and it has a valid value saved for Vesicash, re-add it
			if ( isset( $inaccurate_vesicash_items[$post_id] ) && ! empty( $inaccurate_vesicash_items[$post_id] ) ) {
				$accurate_vesicash_items[$post_id] = $inaccurate_vesicash_items[$post_id];
			}

		}

		// If something went wrong, don't make any changes
		if ( empty( $accurate_vesicash_items ) ) {
			$accurate_vesicash_items = $inaccurate_vesicash_items;
		}

		return $accurate_vesicash_items;
	}

	private static function set_vesicash_items( $vesicash_items = array() ) {
		return update_option( self::$vesicash_option, $vesicash_items );
	}

	private function log( $message = '' ) {
		if( function_exists( 'edd_debug_log' ) ) {
			edd_debug_log( $message );
		}
	}
}
$edd_vesicash_gateway = new EDD_Vesicash_Gateway;
