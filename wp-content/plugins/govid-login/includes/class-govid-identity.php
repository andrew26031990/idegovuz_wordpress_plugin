<?php
/**
 * Provider-independent result of a successful identification.
 *
 * @package govid-login
 */

defined( 'ABSPATH' ) || exit;

final class Govid_Identity {

	/** @var string Provider id ("oneid", "myid", ...). */
	public $provider;

	/** @var string PINFL (14 digits). Used to match WordPress users across providers. */
	public $pin;

	/** @var string */
	public $first_name = '';

	/** @var string */
	public $last_name = '';

	/** @var string */
	public $middle_name = '';

	/** @var string Falls back to "Last First Middle" when empty. */
	public $display_name = '';

	/** @var string */
	public $phone = '';

	/** @var string */
	public $email = '';

	/** @var string Suggested WordPress username for new accounts. */
	public $login_hint = '';

	/** @var bool Identity confirmed by digital signature / Mobile-ID / biometrics. */
	public $verified = false;

	/** @var array Raw provider data. Source for the "extra fields" setting. */
	public $raw = array();

	/** @var array Provider session data (tokens), not stored unless the provider decides to. */
	public $session = array();

	public function __construct( $provider, $pin, array $raw ) {
		$this->provider = (string) $provider;
		$this->pin      = is_scalar( $pin ) ? preg_replace( '/[^0-9A-Za-z]/', '', (string) $pin ) : '';
		$this->raw      = $raw;
	}

	public function get_display_name() {
		if ( '' !== $this->display_name ) {
			return $this->display_name;
		}
		return trim( preg_replace( '/\s+/', ' ', $this->last_name . ' ' . $this->first_name . ' ' . $this->middle_name ) );
	}

	/**
	 * Reads a scalar text value from provider data.
	 */
	public static function text( $data, $key ) {
		return is_array( $data ) && isset( $data[ $key ] ) && is_scalar( $data[ $key ] )
			? sanitize_text_field( (string) $data[ $key ] )
			: '';
	}

	/**
	 * Reads a value by dot-separated path, e.g. "doc_data.pass_data".
	 *
	 * @return mixed|null
	 */
	public function get_path( $path ) {
		$value = $this->raw;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return null;
			}
			$value = $value[ $segment ];
		}
		return $value;
	}
}
