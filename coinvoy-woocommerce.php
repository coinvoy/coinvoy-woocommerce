<?php
/**
 * Plugin Name: coinvoy-woocommerce
 * Plugin URI: https://github.com/coinvoy/coinvoy-woocommerce
 * Description: Accept Bitcoin on your WooCommerce-powered website with Coinvoy.
 * Version: 0.9
 * Author: Coinvoy
 * Author URI: https://coinvoy.net
 * License: MIT
 * Text Domain: coinvoy-woocommerce
 */

/*  Copyright 2014 Coinvoy

MIT License

Permission is hereby granted, free of charge, to any person obtaining
a copy of this software and associated documentation files (the
"Software"), to deal in the Software without restriction, including
without limitation the rights to use, copy, modify, merge, publish,
distribute, sublicense, and/or sell copies of the Software, and to
permit persons to whom the Software is furnished to do so, subject to
the following conditions:

The above copyright notice and this permission notice shall be
included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

*/


	if (!defined('ABSPATH')) {
		exit; // Exit if accessed directly
	}	
	
	if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
		exit;
	}

	function coinvoy_gateway_init() {


		class Coinvoy_Gateway extends WC_Payment_Gateway {

			public function __construct() {
				$this->id           = 'coinvoy';
				$this->icon         = WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/bitcoin.png';

				$this->has_fields   = false;
				$this->method_title = 'Coinvoy';

				$this->init_form_fields();
				$this->init_settings();

				$this->title        = $this->settings['title'];
				$this->description  = $this->settings['description'];

				add_action( 'woocommerce_update_options_payment_gateways_coinvoy', array( $this, 'process_admin_options' ) );
				add_action('woocommerce_api_coinvoy_gateway', array( $this, 'ipn_handler' ));
			} 

			public function init_form_fields() {
				$this->form_fields = array(
					'enabled' => array(
						'title'   => __( 'Enable/Disable', 'woocommerce' ),
						'type'    => 'checkbox',
						'label'   => 'Enable Coinvoy',
						'default' => 'yes'
					),
					'title' => array(
						'title'       => __( 'Coinvoy', 'woocommerce' ),
						'type'        => 'text',
						'description' => __( 'Payment Gateway title in checkout page.', 'woocommerce' ),
						'default'     => __( 'Coinvoy', 'woocommerce' )
					),
					'description' => array(
						'title'       => __( 'Customer Message', 'woocommerce' ),
						'type'        => 'textarea',
						'description' => 'Message in checkout page',
						'default'     => 'You will be redirected to a payment page to complete your purchase.'
					),
					'rAddress' => array(
						'title'       => __( 'Receiving Address', 'woocommerce' ),
						'type'        => 'text',
						'description' => 'Your bitcoin receiving address.',
						'default'     => ''
					),
					'email' => array(
						'title'   => __( 'Email(optional)', 'woocommerce' ),
						'type'    => 'text',
						'description' => 'Fill if you want to be notified by email about customer payments',
						'default' => ''
					)
				);
			}


			public function process_payment($order_id) {

				global $woocommerce;

				$order = new WC_Order($order_id);

				$order->update_status('on-hold', __( 'Awaiting bitcoin transaction', 'woocommerce' ));

				$order->reduce_order_stock();


				require(plugin_dir_path(__FILE__) . 'php-client/coinvoy.php');

				$amount = $order->get_total();
				$address = $this->settings['rAddress'];
				$currency = 'BTC';

				$secret = hash('sha256', $address . mt_rand());

				$coinvoy = new Coinvoy();

				$options = array(
					'orderID'  => ''.$order_id,
					'callback' => WC()->api_request_url('Coinvoy_Gateway'),
					'secret'   => $secret
				);

				if ($this->settings['email'] != '') $options['email'] = $this->settings['email'];

				$payment = $coinvoy->payment($amount, $currency, $address, $options);
                
				if (!$payment['success']) {
					$order->add_order_note(__('Error while processing coinvoy payment: '. $payment['message'], 'coinvoy-woocommerce'));
					$woocommerce->add_error(__('Sorry, but there was an error processing your order. Please try again or try a different payment method.', 'coinvoy-woocommerce'));
					return;
				}
				
				return array(
					'result'   => 'success',
					'redirect' => $this->get_redirect_url($payment)
				);
			}

			public function ipn_handler() {
                $entityBody = file_get_contents('php://input');
                
                $info = json_decode($entityBody);

				$order_id = $info['orderID'];
				$status   = $info['status'];
				
				$order = new WC_Order($order_id);

				switch ($status) {
					case 'cancelled':
						$order->update_status('failed', __( 'Awaiting bitcoin transaction', 'woocommerce' ));
						break;
					case 'confirmed':
						add_order_note(__('Coinvoy payment confirmed', 'coinvoy-woocommerce'));
						$order->payment_complete();
						break;

				}

			}

			private function get_redirect_url($payment) {
				return 'https://coinvoy.net/paymentPage/' . $payment['id'] . '?redirect=' . urlencode($this->get_return_url());
			}
		}	
	}	

	function add_coinvoy_gateway() {
		$methods[] = 'Coinvoy_Gateway';

		return $methods;
	}

	add_action( 'plugins_loaded', 'coinvoy_gateway_init' );
	add_filter( 'woocommerce_payment_gateways', 'add_coinvoy_gateway');


?>
