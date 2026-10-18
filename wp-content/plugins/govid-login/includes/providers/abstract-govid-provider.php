<?php
/**
 * Base class for identity providers.
 *
 * Login flow driven by Govid_Auth:
 *   1. [optional] start form            has_start_form() / render_start_form() / validate_start_form()
 *   2. begin_authorization()            -> URL to redirect the browser to + context kept server-side
 *   3. parse_callback()                 -> validated callback data
 *   4. [optional] user confirmation     requires_confirmation()
 *   5. fetch_identity()                 -> Govid_Identity (must contain the PINFL)
 *   6. on_login() / on_logout()
 *
 * Each provider stores its settings in the option "govid_provider_{id}". Fields
 * flagged 'constant' can be overridden in wp-config.php with GOVID_{ID}_{KEY}.
 *
 * @package govid-login
 */

defined( 'ABSPATH' ) || exit;

abstract class Govid_Provider {

	/** @return string Lowercase id, [a-z0-9_]. Also the callback path: /{id}/callback/ */
	abstract public function id();

	/** @return string Human-readable name. */
	abstract public function label();

	/** @return array Provider-specific settings fields (see fields()). */
	abstract protected function provider_fields();

	/** @return string[] Setting keys that must be non-empty for the provider to work. */
	abstract protected function required_settings();

	/**
	 * @param string $state     Random state bound to the browser.
	 * @param array  $form_data Validated start form data (empty without a start form).
	 * @return array|WP_Error { url: string, context: array }
	 */
	abstract public function begin_authorization( $state, array $form_data );

	/**
	 * @param array  $query   Unslashed $_GET of the callback request.
	 * @param string $state   State from the browser cookie (already matched to the stored record).
	 * @param array  $context Context returned by begin_authorization().
	 * @return array|WP_Error Data for fetch_identity().
	 */
	abstract public function parse_callback( array $query, $state, array $context );

	/**
	 * @param array $callback_data Result of parse_callback().
	 * @param array $context       Context returned by begin_authorization().
	 * @return Govid_Identity|WP_Error
	 */
	abstract public function fetch_identity( array $callback_data, array $context );

	public function default_button_label() {
		/* translators: %s: provider name */
		return sprintf( __( 'Войти через %s', 'govid-login' ), $this->label() );
	}

	public function default_priority() {
		return 10;
	}

	/** HTML shown at the top of the provider's settings tab. */
	public function settings_intro() {
		return '';
	}

	/** Example paths for the "extra fields" setting. */
	public function extra_fields_example() {
		return '';
	}

	public function has_start_form() {
		return false;
	}

	/** Outputs the start form fields (inside a <form> rendered by Govid_Auth). */
	public function render_start_form( array $values ) {}

	/**
	 * @param array $post Unslashed $_POST.
	 * @return array|WP_Error Validated data.
	 */
	public function validate_start_form( array $post ) {
		return array();
	}

	/** Whether the user must press "Confirm" on our site after returning. */
	public function requires_confirmation() {
		return false;
	}

	public function on_login( WP_User $user, Govid_Identity $identity ) {}

	public function on_logout( $user_id ) {}

	// ------------------------------------------------------------------
	// Settings
	// ------------------------------------------------------------------

	public function option_name() {
		return 'govid_provider_' . $this->id();
	}

	/**
	 * Field definition keys: type (text|secret|checkbox|number|select|textarea), label,
	 * description, default, options (select), min/max (number), constant (bool).
	 */
	public function fields() {
		$common_top = array(
			'enabled' => array(
				'type'    => 'checkbox',
				'label'   => __( 'Включить', 'govid-login' ),
				/* translators: %s: provider name */
				'text'    => sprintf( __( 'Показывать кнопку «%s» и разрешить вход', 'govid-login' ), $this->label() ),
				'default' => 0,
			),
		);

		$common_bottom = array(
			'button_label' => array(
				'type'        => 'text',
				'label'       => __( 'Текст кнопки', 'govid-login' ),
				'description' => __( 'Пусто — по умолчанию:', 'govid-login' ) . ' ' . $this->default_button_label(),
				'default'     => '',
			),
			'priority'     => array(
				'type'        => 'number',
				'label'       => __( 'Порядок кнопки', 'govid-login' ),
				'description' => __( 'Кнопки выводятся по возрастанию.', 'govid-login' ),
				'default'     => $this->default_priority(),
				'min'         => 0,
				'max'         => 999,
			),
			'extra_fields' => array(
				'type'        => 'textarea',
				'label'       => __( 'Дополнительные поля', 'govid-login' ),
				'description' => __( 'ФИО и телефон сохраняются всегда. Здесь перечислите через запятую дополнительные поля из ответа сервиса (вложенные — через точку). По умолчанию значение сохраняется в мета-поле govid_{провайдер}_{поле}; свой ключ можно указать через двоеточие: путь:мета_ключ.', 'govid-login' )
					. ( $this->extra_fields_example() ? ' ' . __( 'Пример:', 'govid-login' ) . ' ' . $this->extra_fields_example() : '' ),
				'default'     => '',
			),
		);

		return array_merge( $common_top, $this->provider_fields(), $common_bottom );
	}

	public function defaults() {
		return wp_list_pluck( $this->fields(), 'default' );
	}

	public function settings() {
		return wp_parse_args( (array) get_option( $this->option_name(), array() ), $this->defaults() );
	}

	/** @return mixed */
	public function get( $key ) {
		if ( $this->is_constant( $key ) ) {
			return (string) constant( $this->constant_name( $key ) );
		}
		$settings = $this->settings();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	public function constant_name( $key ) {
		return 'GOVID_' . strtoupper( $this->id() ) . '_' . strtoupper( $key );
	}

	public function is_constant( $key ) {
		$fields = $this->fields();
		return ! empty( $fields[ $key ]['constant'] ) && defined( $this->constant_name( $key ) );
	}

	public function is_configured() {
		foreach ( $this->required_settings() as $key ) {
			if ( '' === trim( (string) $this->get( $key ) ) ) {
				return false;
			}
		}
		return true;
	}

	public function is_active() {
		return $this->get( 'enabled' ) && $this->is_configured();
	}

	public function redirect_uri() {
		return (string) apply_filters( 'govid_redirect_uri', home_url( '/' . $this->id() . '/callback/' ), $this->id() );
	}

	public function button_label() {
		$label = trim( (string) $this->get( 'button_label' ) );
		return '' !== $label ? $label : $this->default_button_label();
	}

	public function priority() {
		return (int) $this->get( 'priority' );
	}

	/**
	 * Parsed "extra fields" setting.
	 *
	 * @return array path => meta key
	 */
	public function extra_field_map() {
		return Govid_Users::parse_field_map( (string) $this->get( 'extra_fields' ), $this->id() );
	}

	/**
	 * @param mixed $input Raw form input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();
		$old   = $this->settings();
		$out   = array();

		foreach ( $this->fields() as $key => $field ) {
			$raw = isset( $input[ $key ] ) ? $input[ $key ] : null;

			switch ( $field['type'] ) {
				case 'checkbox':
					$out[ $key ] = empty( $raw ) ? 0 : 1;
					break;

				case 'secret':
					// Empty means "keep the current value".
					$raw         = trim( (string) $raw );
					$out[ $key ] = '' === $raw ? $old[ $key ] : sanitize_text_field( $raw );
					break;

				case 'number':
					$value       = null === $raw ? (int) $old[ $key ] : (int) $raw;
					$min         = isset( $field['min'] ) ? $field['min'] : PHP_INT_MIN;
					$max         = isset( $field['max'] ) ? $field['max'] : PHP_INT_MAX;
					$out[ $key ] = max( $min, min( $max, $value ) );
					break;

				case 'select':
					$value       = null === $raw ? $old[ $key ] : (string) $raw;
					$out[ $key ] = isset( $field['options'][ $value ] ) ? $value : $field['default'];
					break;

				case 'textarea':
					$out[ $key ] = null === $raw ? $old[ $key ] : sanitize_textarea_field( $raw );
					break;

				default:
					$out[ $key ] = null === $raw ? $old[ $key ] : sanitize_text_field( $raw );
			}
		}

		return $out;
	}

	/**
	 * Reads an opaque token-like parameter (code, state, session id).
	 */
	protected static function read_token( array $source, $key ) {
		if ( ! isset( $source[ $key ] ) || ! is_string( $source[ $key ] ) ) {
			return '';
		}
		return preg_match( '~^[A-Za-z0-9+/=._\-]{1,1024}$~', $source[ $key ] ) ? $source[ $key ] : '';
	}
}
