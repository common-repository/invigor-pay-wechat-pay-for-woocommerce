<?php
/**
 * Plugin Name: Invigor Pay - WeChat Pay for WooCommerce
 * Description: Allow your customers to purchase online using WeChat Pay.
 * Version: 0.9.0
 * Author: Invigor
 * Author URI: https://www.invigorgroup.com
 */

define('WC_IVO_Gateway_API_URL', 'https://pay-api.invigor.io');
define('WC_IVO_Gateway_CLIENT_URL', 'https://pay.invigor.io');

/*
 * This action hook registers WC_IVO_Gateway as a WooCommerce payment gateway
 */
add_filter( 'woocommerce_payment_gateways', 'ivo_add_gateway_class' );

function ivo_add_gateway_class( $gateways ) {
	$gateways[] = 'WC_IVO_Gateway';
	return $gateways;
}
 
add_action( 'plugins_loaded', 'ivo_init_gateway_class' );
function ivo_init_gateway_class() {
 
	class WC_IVO_Gateway extends WC_Payment_Gateway {
 
 		/**
 		 * Class constructor, more about it in Step 3
 		 */
 		public function __construct() {
 
			$this->id = 'ivo_payment'; 
			$this->icon = plugin_dir_url(__FILE__).'/assets/images/wechatpay_icon.png';
			$this->has_fields = true;
			$this->method_description = 'Allow your customers to purchase online using WeChat Pay.'; 
		 
			$this->supports = array(
				'products'
			);
		 
			// Method with all the options fields
			$this->init_form_fields();
		 
			// Load the settings.
			$this->init_settings();
			$this->title = 'WeChat Pay';
			$this->description = 'Allow your customers to purchase online using WeChat Pay.';
			$this->publishable_key = $this->get_option( 'publishable_key' );
			$this->private_key = $this->get_option( 'private_key' );

			// This action hook saves the settings
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		 
			// We need custom JavaScript to obtain a token
			add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
		 
			// webhook to update payment
			add_action( 'woocommerce_api_ivo-update-payment', array( $this, 'ivo_update_payment' ));
 		}
 
		/**
 		 * Plugin options
 		 */
 		public function init_form_fields(){
 
 			/**
 			 * Options below will be provided by Invigor
 			 */ 
			$this->form_fields = array(
				'publishable_key' => array(
					'title'       => 'Public Key',
					'type'        => 'text'
				),
				'private_key' => array(
					'title'       => 'Private Key',
					'type'        => 'text'
				),
			);
 
	 	}
 
		/*
		 * We're processing the payments here
		 */
		public function process_payment( $orderId ) {
 
			global $woocommerce;
 
			// we need it to get any order details
			$order = wc_get_order( $orderId );

			// we initialize the payment first
			// get the token from the API
			$timestamp = time();
			$auth = md5($this->private_key.$this->publishable_key.$timestamp);
			$data = array(
				'headers' => array(
					'x-request-time' => $timestamp,
					'Content-Type' => 'application/json'
				),
				'body' => json_encode(array(
					'accessKey' => $this->publishable_key,
					'auth' => $auth
				))
			);
			
			$token_response = wp_remote_post(WC_IVO_Gateway_API_URL.'/auth', $data);
			$token_json_response = json_decode($token_response['body'], true);
			if (!isset($token_json_response['token'])) {
				return array(
					'result' => 'fail',
					'message' => 'Unable to reach WeChat services.'
				);
			}

			// once token is retrieved, we need to instantiate the payment making another request
			$data = array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'x-access-token' => $token_json_response['token']
				),
				'body' => json_encode(array(
					'transactionId' => $orderId,
					'currency' => $order->get_currency(),
					'method' => 'wechat',
					'amount' => $order->get_total(),
					'success_url' => $order->get_checkout_order_received_url(),
					'cancel_url' => wc_get_checkout_url(),
					'callback_url' => get_site_url().'/?wc-api=ivo-update-payment&auth='.md5($this->private_key.$orderId).'&orderid='.$orderId
				))
			);

			$response = wp_remote_post(WC_IVO_Gateway_API_URL.'/payments', $data);
			$json_response = json_decode($response['body'], true);	
			if (!isset($json_response['id'])) {
				return array(
					'result' => 'fail',
					'messages' => 'Unable to initiate WeChatPay payment.'
				);
			}

			// woocommerce only redirect through GET requests which is a security risk
			// so what we'll do is make sure that a payment is already created in invigor
			// and redirect via GET passing the base64 of token and id
			$token = base64_encode(json_encode(array( 'token' =>$token_json_response['token'], 'id' => $json_response['id'])));

			return array(
				'result'   => 'success',
				'redirect' => WC_IVO_Gateway_CLIENT_URL.'?token='.$token,
			); 
	 	}
 
		/*
		 * webhook to be invoked when payment is done in ivo
		 */
		public function ivo_update_payment() {

			$orderId = isset($_GET['orderid']) ? sanitize_text_field(trim($_GET['orderid'])) : null;
			$auth = isset($_GET['auth']) ? sanitize_text_field(trim($_GET['auth'])) : null; 
 
			// we're expecting two url parameters
			// auth: md5(private key + orderId)
			// orderId: the order identifier
			
			if ( $orderId && $auth) {

				// we verify if it came from ivo via "auth"
				if (md5($this->private_key.$orderId) === $auth) {

					// we get the order
					$order = wc_get_order( $orderId );

					if ($order) {

						error_log('INFO: Received request to update order '.$orderId.'. Current status is '.$order->get_status());

						// we only update pending payment status
						if (in_array(strtolower($order->get_status()), ['pending', 'pending payment'])) {
							
							// complete the payment
							$order->payment_complete();

							// we reduce the stock
							$order->reduce_order_stock();

							error_log('INFO: completed payment for '.$orderId.' and reduced stock.');
						}
					}
				}
			}
	 	}
 	}
}
