<?php
/**
 * OneID (id.egov.uz / sso.egov.uz).
 *
 * Source: operator's technical guide "Texnologik yo'riqnoma" (id.egov.uz -> for developers).
 * Single endpoint, step selected by response_type / grant_type:
 *   GET  response_type=one_code                -> ?code&state
 *   POST grant_type=one_authorization_code     -> access_token
 *   POST grant_type=one_access_token_identify  -> user data, ret_cd "0" = OK
 *   POST grant_type=one_log_out                -> end OneID session
 *
 * @package govid-login
 */

defined( 'ABSPATH' ) || exit;

class Govid_Provider_OneID extends Govid_Provider {

	const ENDPOINT   = 'https://sso.egov.uz/sso/oauth/Authorization.do';
	const META_TOKEN = 'govid_oneid_access_token';

	public function id() {
		return 'oneid';
	}

	public function label() {
		return 'OneID';
	}

	public function default_priority() {
		return 10;
	}

	protected function provider_fields() {
		return array(
			'client_id'     => array(
				'type'     => 'text',
				'label'    => __( 'Client ID', 'govid-login' ),
				'default'  => '',
				'constant' => true,
			),
			'client_secret' => array(
				'type'     => 'secret',
				'label'    => __( 'Client Secret', 'govid-login' ),
				'default'  => '',
				'constant' => true,
			),
			'scope'         => array(
				'type'        => 'text',
				'label'       => __( 'Scope', 'govid-login' ),
				'description' => __( 'Выдаётся оператором. Обычно совпадает с Client ID.', 'govid-login' ),
				'default'     => '',
				'constant'    => true,
			),
			'sso_logout'    => array(
				'type'    => 'checkbox',
				'label'   => __( 'Выход', 'govid-login' ),
				'text'    => __( 'При выходе с сайта завершать сессию OneID', 'govid-login' ),
				'default' => 0,
			),
		);
	}

	protected function required_settings() {
		return array( 'client_id', 'client_secret', 'scope' );
	}

	public function settings_intro() {
		return esc_html__( 'Доступ выдаёт оператор OneID по соглашению (id.egov.uz → «Разработчикам»: технологическая инструкция и шаблон соглашения). В заявке укажите домен, Redirect URI и внешний IP-адрес сервера — запросы принимаются только с IP из белого списка. localhost в Redirect URI не допускается.', 'govid-login' );
	}

	public function extra_fields_example() {
		return 'pport_no, birth_date, user_type, legal_info';
	}

	private function endpoint() {
		return (string) apply_filters( 'govid_oneid_endpoint', self::ENDPOINT );
	}

	public function begin_authorization( $state, array $form_data ) {
		$args = array(
			'response_type' => 'one_code',
			'client_id'     => $this->get( 'client_id' ),
			'redirect_uri'  => $this->redirect_uri(),
			'scope'         => $this->get( 'scope' ),
			'state'         => $state,
		);

		return array(
			'url'     => $this->endpoint() . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 ),
			'context' => array(),
		);
	}

	public function parse_callback( array $query, $state, array $context ) {
		$returned_state = self::read_token( $query, 'state' );
		if ( '' === $returned_state || ! hash_equals( $state, $returned_state ) ) {
			return new WP_Error( 'invalid_state', 'OneID: state mismatch' );
		}

		$code = self::read_token( $query, 'code' );
		if ( '' === $code ) {
			return new WP_Error( 'cancelled', 'OneID: no code in callback' );
		}

		return array( 'code' => $code );
	}

	public function fetch_identity( array $callback_data, array $context ) {
		$token = $this->call(
			'one_authorization_code',
			array(
				'redirect_uri' => $this->redirect_uri(),
				'code'         => $callback_data['code'],
			)
		);
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		if ( empty( $token['access_token'] ) || ! is_string( $token['access_token'] ) ) {
			return new WP_Error( 'provider', 'OneID: access_token missing' );
		}

		$info = $this->call(
			'one_access_token_identify',
			array(
				'access_token' => $token['access_token'],
				'scope'        => $this->get( 'scope' ),
			)
		);
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		if ( ! isset( $info['ret_cd'] ) || '0' !== (string) $info['ret_cd'] ) {
			return new WP_Error( 'provider', 'OneID: ret_cd=' . ( isset( $info['ret_cd'] ) ? (string) $info['ret_cd'] : 'missing' ) );
		}

		$identity = new Govid_Identity( $this->id(), isset( $info['pin'] ) ? $info['pin'] : '', $info );
		if ( '' === $identity->pin ) {
			return new WP_Error( 'provider', 'OneID: pin missing' );
		}

		$identity->first_name   = Govid_Identity::text( $info, 'first_name' );
		$identity->last_name    = Govid_Identity::text( $info, 'sur_name' );
		$identity->middle_name  = Govid_Identity::text( $info, 'mid_name' );
		$identity->display_name = Govid_Identity::text( $info, 'full_name' );
		$identity->login_hint   = Govid_Identity::text( $info, 'user_id' );
		// Not in the documented response, but returned for some accounts.
		$identity->phone = Govid_Identity::text( $info, 'mob_phone_no' );
		$identity->email = Govid_Identity::text( $info, 'email' );

		$identity->verified = ( ! empty( $info['validation_method'] ) && is_array( $info['validation_method'] ) )
			|| ( isset( $info['valid'] ) && ( true === $info['valid'] || 'true' === $info['valid'] ) );

		$identity->session = array( 'access_token' => $token['access_token'] );

		return $identity;
	}

	public function on_login( WP_User $user, Govid_Identity $identity ) {
		if ( $this->get( 'sso_logout' ) && ! empty( $identity->session['access_token'] ) ) {
			update_user_meta( $user->ID, self::META_TOKEN, $identity->session['access_token'] );
		}
	}

	public function on_logout( $user_id ) {
		$token = get_user_meta( $user_id, self::META_TOKEN, true );
		if ( ! $token ) {
			return;
		}

		delete_user_meta( $user_id, self::META_TOKEN );

		if ( $this->get( 'sso_logout' ) && $this->is_configured() ) {
			$this->call(
				'one_log_out',
				array(
					'access_token' => $token,
					'scope'        => $this->get( 'scope' ),
				),
				false
			);
		}
	}

	/**
	 * Form-encoded POST with client credentials.
	 *
	 * @return array|WP_Error
	 */
	private function call( $grant_type, array $params, $blocking = true ) {
		$body = array_merge(
			array(
				'grant_type'    => $grant_type,
				'client_id'     => $this->get( 'client_id' ),
				'client_secret' => $this->get( 'client_secret' ),
			),
			$params
		);

		$response = Govid_Http::request(
			'POST',
			$this->endpoint(),
			array(
				'form'     => $body,
				'blocking' => $blocking,
			)
		);

		if ( ! $blocking ) {
			return array();
		}

		return Govid_Http::expect_ok( $response, 'OneID ' . $grant_type );
	}
}
