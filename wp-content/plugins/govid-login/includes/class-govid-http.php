<?php
/**
 * Thin wrapper around the WordPress HTTP API for JSON-speaking identity providers.
 *
 * @package govid-login
 */

defined( 'ABSPATH' ) || exit;

class Govid_Http {

	/**
	 * @param string $method HTTP method.
	 * @param string $url    URL.
	 * @param array  $args   {
	 *     @type array $form     Form-encoded body.
	 *     @type array $json     JSON body.
	 *     @type array $headers  Extra headers.
	 *     @type int   $timeout  Seconds, default 15.
	 *     @type bool  $blocking Wait for the response, default true.
	 * }
	 * @return array|WP_Error { status: int, data: array|null, request_id: string }
	 */
	public static function request( $method, $url, array $args = array() ) {
		$request = array(
			'method'   => $method,
			'timeout'  => isset( $args['timeout'] ) ? (int) $args['timeout'] : 15,
			'blocking' => isset( $args['blocking'] ) ? (bool) $args['blocking'] : true,
			'headers'  => array_merge( array( 'Accept' => 'application/json' ), isset( $args['headers'] ) ? $args['headers'] : array() ),
		);

		if ( isset( $args['form'] ) ) {
			$request['body'] = $args['form'];
		} elseif ( isset( $args['json'] ) ) {
			$request['body']                    = wp_json_encode( $args['json'] );
			$request['headers']['Content-Type'] = 'application/json';
		}

		$response = wp_remote_request( $url, $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! $request['blocking'] ) {
			return array(
				'status'     => 0,
				'data'       => null,
				'request_id' => '',
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return array(
			'status'     => (int) wp_remote_retrieve_response_code( $response ),
			'data'       => is_array( $data ) ? $data : null,
			'request_id' => (string) wp_remote_retrieve_header( $response, 'x-request-id' ),
		);
	}

	/**
	 * Turns a response into its decoded body, or a WP_Error whose code is one of
	 * the login error codes understood by Govid_Auth ("provider", "rate_limited").
	 *
	 * @param array|WP_Error $response Result of request().
	 * @param string         $label    Name of the call, for the debug log.
	 * @return array|WP_Error
	 */
	public static function expect_ok( $response, $label ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'provider', $label . ': ' . $response->get_error_message() );
		}

		$status = $response['status'];
		$data   = $response['data'];
		$meta   = array(
			'status'     => $status,
			'request_id' => $response['request_id'],
		);

		if ( 429 === $status ) {
			return new WP_Error( 'rate_limited', $label . ': HTTP 429', $meta );
		}

		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			$reason = '';
			if ( is_array( $data ) ) {
				foreach ( array( 'error', 'message', 'detail' ) as $key ) {
					if ( ! empty( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
						$reason = (string) $data[ $key ];
						break;
					}
				}
			}
			return new WP_Error( 'provider', trim( $label . ': HTTP ' . $status . ' ' . $reason ), $meta );
		}

		return $data;
	}
}
