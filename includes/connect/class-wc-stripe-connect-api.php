<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WOOCOMMERCE_CONNECT_SERVER_URL' ) ) {
	define( 'WOOCOMMERCE_CONNECT_SERVER_URL', 'https://api.woocommerce.com/' );
}

if ( ! class_exists( 'WC_Stripe_Connect_API' ) ) {
	/**
	 * Stripe Connect API class.
	 */
	class WC_Stripe_Connect_API {

		const WOOCOMMERCE_CONNECT_SERVER_API_VERSION = '3';

		/**
		 * Send GET request for Stripe account details
		 *
		 * @return array
		 */
		public function get_stripe_account_details() {
			return $this->request( 'GET', '/stripe/account' );
		}

		/**
		 * Send request to Connect Server to initiate Stripe OAuth
		 *
		 * @param  string $return_url return address.
		 *
		 * @return array
		 */
		public function get_stripe_oauth_init( $return_url ) {

			$current_user                   = wp_get_current_user();
			$business_data                  = array();
			$business_data['url']           = get_site_url();
			$business_data['business_name'] = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$business_data['first_name']    = $current_user->user_firstname;
			$business_data['last_name']     = $current_user->user_lastname;
			$business_data['phone']         = '';
			$business_data['currency']      = get_woocommerce_currency();

			$wc_countries = WC()->countries;

			if ( method_exists( $wc_countries, 'get_base_address' ) ) {
				$business_data['country']        = $wc_countries->get_base_country();
				$business_data['street_address'] = $wc_countries->get_base_address();
				$business_data['city']           = $wc_countries->get_base_city();
				$business_data['state']          = $wc_countries->get_base_state();
				$business_data['zip']            = $wc_countries->get_base_postcode();
			} else {
				$base_location                   = wc_get_base_location();
				$business_data['country']        = $base_location['country'];
				$business_data['street_address'] = '';
				$business_data['city']           = '';
				$business_data['state']          = $base_location['state'];
				$business_data['zip']            = '';
			}

			$request = array(
				'returnUrl'    => $return_url,
				'businessData' => $business_data,
			);

			return $this->request( 'POST', '/stripe/oauth-init', $request );
		}

		/**
		 * Send request to Connect Server for Stripe keys
		 *
		 * @param  string $code OAuth server code.
		 *
		 * @return array
		 */
		public function get_stripe_oauth_keys( $code ) {

			$request = array( 'code' => $code );

			return $this->request( 'POST', '/stripe/oauth-keys', $request );
		}

		/**
		 * Send request to Connect Server to deauthorize account
		 *
		 * @return array
		 */
		public function deauthorize_stripe_account() {

			return $this->request( 'POST', '/stripe/account/deauthorize' );
		}

		/**
		 * General OAuth request method.
		 *
		 * @param string $method request method.
		 * @param string $path   path for request.
		 * @param array  $body   request body.
		 *
		 * @return array|WP_Error
		 */
		protected function request( $method, $path, $body = array() ) {

			if ( ! is_array( $body ) ) {
				return new WP_Error(
					'request_body_should_be_array',
					__( 'Unable to send request to WooCommerce Connect server. Body must be an array.', 'woocommerce-gateway-stripe' )
				);
			}

			$url = trailingslashit( WOOCOMMERCE_CONNECT_SERVER_URL );
			$url = apply_filters( 'wc_connect_server_url', $url );
			$url = trailingslashit( $url ) . ltrim( $path, '/' );

			// Add useful system information to requests that contain bodies.
			if ( in_array( $method, array( 'POST', 'PUT' ), true ) ) {
				$body = $this->request_body( $body );
				$body = wp_json_encode( apply_filters( 'wc_connect_api_client_body', $body ) );

				if ( ! $body ) {
					return new WP_Error(
						'unable_to_json_encode_body',
						__( 'Unable to encode body for request to WooCommerce Connect server.', 'woocommerce-gateway-stripe' )
					);
				}
			}

			$headers = $this->request_headers();
			if ( is_wp_error( $headers ) ) {
				return $headers;
			}

			$http_timeout = 60; // 1 minute
			if ( function_exists( 'wc_set_time_limit' ) ) {
				wc_set_time_limit( $http_timeout + 10 );
			}
			$args = array(
				'headers'     => $headers,
				'method'      => $method,
				'body'        => $body,
				'redirection' => 0,
				'compress'    => true,
				'timeout'     => $http_timeout,
			);

			$args          = apply_filters( 'wc_connect_request_args', $args );
			$response      = wp_remote_request( $url, $args );
			$response_code = wp_remote_retrieve_response_code( $response );
			$content_type  = wp_remote_retrieve_header( $response, 'content-type' );

			if ( false === strpos( $content_type, 'application/json' ) ) {
				if ( 200 !== $response_code ) {
					return new WP_Error(
						'wcc_server_error',
						sprintf(
							// Translators: HTTP error code.
							__( 'Error: The WooCommerce Connect server returned HTTP code: %d', 'woocommerce-gateway-stripe' ),
							$response_code
						)
					);
				}
				return $response;
			}

			$response_body = wp_remote_retrieve_body( $response );
			if ( ! empty( $response_body ) ) {
				$response_body = json_decode( $response_body );
			}

			if ( 200 !== $response_code ) {
				if ( empty( $response_body ) ) {
					return new WP_Error(
						'wcc_server_empty_response',
						sprintf(
							// Translators: HTTP error code.
							__( 'Error: The WooCommerce Connect server returned ( %d ) and an empty response body.', 'woocommerce-gateway-stripe' ),
							$response_code
						)
					);
				}

				$error   = property_exists( $response_body, 'error' ) ? $response_body->error : '';
				$message = property_exists( $response_body, 'message' ) ? $response_body->message : '';
				$data    = property_exists( $response_body, 'data' ) ? $response_body->data : '';

				return new WP_Error(
					'wcc_server_error_response',
					sprintf(
						/* translators: %1$s: error code, %2$s: error message, %3$d: HTTP response code */
						__( 'Error: The WooCommerce Connect server returned: %1$s %2$s ( %3$d )', 'woocommerce-gateway-stripe' ),
						$error,
						$message,
						$response_code
					),
					$data
				);
			}

			return $response_body;
		}

		/**
		 * Adds useful WP/WC/WCC information to request bodies.
		 *
		 * @param array $initial_body body of initial request.
		 *
		 * @return array
		 */
		protected function request_body( $initial_body = array() ) {

			$default_body = array(
				'settings' => array(),
			);

			$body = array_merge( $default_body, $initial_body );

			// Add interesting fields to the body of each request.
			$body['settings'] = wp_parse_args(
				$body['settings'],
				array(
					'base_city'      => WC()->countries->get_base_city(),
					'base_country'   => WC()->countries->get_base_country(),
					'base_state'     => WC()->countries->get_base_state(),
					'base_postcode'  => WC()->countries->get_base_postcode(),
					'currency'       => get_woocommerce_currency(),
					'stripe_version' => WC_STRIPE_VERSION,
					'wc_version'     => WC()->version,
					'wp_version'     => get_bloginfo( 'version' ),
				)
			);

			return $body;
		}

		/**
		 * Generates headers for request to the WooCommerce Connect Server.
		 *
		 * @return array|WP_Error
		 */
		protected function request_headers() {

			$headers                    = array();
			$locale                     = strtolower( str_replace( '_', '-', get_locale() ) );
			$locale_elements            = explode( '-', $locale );
			$lang                       = $locale_elements[0];
			$headers['Accept-Language'] = $locale . ',' . $lang;
			$headers['Content-Type']    = 'application/json; charset=utf-8';
			$headers['Accept']          = 'application/vnd.woocommerce-connect.v' . self::WOOCOMMERCE_CONNECT_SERVER_API_VERSION;

			return $headers;
		}
	}
}
