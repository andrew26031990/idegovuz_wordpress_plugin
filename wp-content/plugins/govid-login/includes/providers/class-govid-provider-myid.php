<?php
/**
 * MyID (myid.uz, UZINFOCOM) — biometric identification, WebSDK "Redirect Flow".
 *
 * Source: https://docs.myid.uz/ru/websdk.md (+ _api/v1/oauth2/*, _api/v1/users/me__res.md).
 * NOTE: written from the public documentation without test access. Verify against
 * the dev environment (devmyid.uz) before enabling in production.
 *
 * Flow:
 *   0. User enters PINFL or passport series/number + birth date on our site.
 *   1. POST {api}/api/v1/oauth2/access-token  grant_type=client_credentials  -> client token (cached)
 *   2. POST {api}/api/v1/web/sessions         Bearer, JSON                    -> session_id
 *   3. Redirect to {web}/?session_id&pinfl|pass_data&birth_date&redirect_uri&lang
 *   4. Callback ?auth_code&session_id -> user confirms on our site (anti-fraud requirement)
 *   5. POST {api}/api/v1/oauth2/access-token  grant_type=authorization_code   -> user token
 *   6. GET  {api}/api/v1/users/me             Bearer                          -> profile.common_data.pinfl
 *
 * @package govid-login
 */

defined( 'ABSPATH' ) || exit;

class Govid_Provider_MyID extends Govid_Provider {

	const ENVIRONMENTS = array(
		'prod' => array(
			'api' => 'https://myid.uz/',
			'web' => 'https://web.myid.uz/',
		),
		'dev'  => array(
			'api' => 'https://devmyid.uz/',
			'web' => 'https://web.devmyid.uz/',
		),
	);

	public function id() {
		return 'myid';
	}

	public function label() {
		return 'MyID';
	}

	public function default_priority() {
		return 20;
	}

	protected function provider_fields() {
		return array(
			'environment'   => array(
				'type'    => 'select',
				'label'   => __( 'Среда', 'govid-login' ),
				'options' => array(
					'prod' => __( 'Боевая (myid.uz)', 'govid-login' ),
					'dev'  => __( 'Тестовая (devmyid.uz)', 'govid-login' ),
				),
				'default' => 'prod',
			),
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
				'description' => __( 'Через запятую: common_data, contacts, doc_data, address. contacts нужен для телефона. Должен соответствовать договору с MyID.', 'govid-login' ),
				'default'     => 'common_data,contacts',
			),
			'max_retries'   => array(
				'type'        => 'number',
				'label'       => __( 'Попыток проверки', 'govid-login' ),
				'description' => __( 'Сколько раз пользователь может повторить селфи в рамках одной сессии.', 'govid-login' ),
				'default'     => 3,
				'min'         => 1,
				'max'         => 5,
			),
			'ip_source'     => array(
				'type'        => 'select',
				'label'       => __( 'IP пользователя', 'govid-login' ),
				'description' => __( 'MyID требует реальный IP пользователя. Если сайт за прокси, балансировщиком или Cloudflare, выберите соответствующий заголовок.', 'govid-login' ),
				'options'     => array(
					'remote_addr'      => 'REMOTE_ADDR',
					'x_forwarded_for'  => 'X-Forwarded-For',
					'x_real_ip'        => 'X-Real-IP',
					'cf_connecting_ip' => 'CF-Connecting-IP (Cloudflare)',
				),
				'default'     => 'remote_addr',
			),
		);
	}

	protected function required_settings() {
		return array( 'client_id', 'client_secret', 'scope' );
	}

	public function settings_intro() {
		return esc_html__( 'Доступ выдаёт MyID (UZINFOCOM) по договору: заявка на myid.uz → NDA → тестовый доступ → договор. Зарегистрируйте у MyID Redirect URI. Перед входом пользователь вводит на сайте ПИНФЛ или серию и номер паспорта и дату рождения, затем проходит проверку лица на web.myid.uz.', 'govid-login' );
	}

	public function extra_fields_example() {
		return 'common_data.birth_date, common_data.gender, doc_data.pass_data:passport, address.permanent_address';
	}

	public function requires_confirmation() {
		return true;
	}

	private function base( $kind ) {
		$env  = (string) $this->get( 'environment' );
		$urls = isset( self::ENVIRONMENTS[ $env ] ) ? self::ENVIRONMENTS[ $env ] : self::ENVIRONMENTS['prod'];
		return (string) apply_filters( 'govid_myid_' . $kind . '_url', $urls[ $kind ], $env );
	}

	// ------------------------------------------------------------------
	// Start form: PINFL or passport + birth date
	// ------------------------------------------------------------------

	public function has_start_form() {
		return true;
	}

	public function render_start_form( array $values ) {
		$document   = isset( $values['document'] ) ? $values['document'] : '';
		$birth_date = isset( $values['birth_date'] ) ? $values['birth_date'] : '';
		?>
		<p>
			<label for="govid_myid_document"><?php esc_html_e( 'ПИНФЛ или серия и номер паспорта', 'govid-login' ); ?></label>
			<input type="text" name="govid_document" id="govid_myid_document" class="input" value="<?php echo esc_attr( $document ); ?>"
				autocomplete="off" autocapitalize="characters" spellcheck="false" required
				pattern="\s*([0-9]{14}|[A-Za-z]{2}\s?[0-9]{7})\s*" placeholder="12345678901234 / AA1234567" />
		</p>
		<p>
			<label for="govid_myid_birth_date"><?php esc_html_e( 'Дата рождения', 'govid-login' ); ?></label>
			<input type="date" name="govid_birth_date" id="govid_myid_birth_date" class="input" value="<?php echo esc_attr( $birth_date ); ?>"
				max="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required />
		</p>
		<?php
	}

	public function validate_start_form( array $post ) {
		$document   = isset( $post['govid_document'] ) && is_string( $post['govid_document'] ) ? strtoupper( preg_replace( '/\s+/', '', $post['govid_document'] ) ) : '';
		$birth_date = isset( $post['govid_birth_date'] ) && is_string( $post['govid_birth_date'] ) ? trim( $post['govid_birth_date'] ) : '';

		$data = array(
			'document'   => $document,
			'birth_date' => $birth_date,
		);

		if ( preg_match( '/^\d{14}$/', $document ) ) {
			$data['pinfl'] = $document;
		} elseif ( preg_match( '/^[A-Z]{2}\d{7}$/', $document ) ) {
			$data['pass_data'] = $document;
		} else {
			return new WP_Error( 'invalid_form', __( 'Введите ПИНФЛ (14 цифр) или серию и номер паспорта (например, AA1234567).', 'govid-login' ), $data );
		}

		$date = DateTime::createFromFormat( '!Y-m-d', $birth_date );
		if ( ! $date || $date->format( 'Y-m-d' ) !== $birth_date || $date > new DateTime( 'today' ) ) {
			return new WP_Error( 'invalid_form', __( 'Укажите корректную дату рождения.', 'govid-login' ), $data );
		}

		return $data;
	}

	// ------------------------------------------------------------------
	// Flow
	// ------------------------------------------------------------------

	public function begin_authorization( $state, array $form_data ) {
		$session = $this->create_session();
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$args = array( 'session_id' => $session['session_id'] );
		if ( ! empty( $form_data['pinfl'] ) ) {
			$args['pinfl'] = $form_data['pinfl'];
		} else {
			$args['pass_data'] = $form_data['pass_data'];
		}
		$args['birth_date']   = $form_data['birth_date'];
		$args['redirect_uri'] = $this->redirect_uri();
		$args['lang']         = $this->lang();

		return array(
			'url'     => $this->base( 'web' ) . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 ),
			// Only identifiers are kept server-side; the document number is not stored.
			'context' => array(
				'session_id'  => $session['session_id'],
				'external_id' => $session['external_id'],
			),
		);
	}

	public function parse_callback( array $query, $state, array $context ) {
		$session_id = self::read_token( $query, 'session_id' );
		if ( '' === $session_id || empty( $context['session_id'] ) || ! hash_equals( (string) $context['session_id'], $session_id ) ) {
			return new WP_Error( 'invalid_state', 'MyID: session_id mismatch' );
		}

		$code = self::read_token( $query, 'auth_code' );
		if ( '' === $code ) {
			$this->log_session_result( $session_id );
			return new WP_Error( 'verification_failed', 'MyID: no auth_code in callback' );
		}

		return array(
			'auth_code'  => $code,
			'session_id' => $session_id,
		);
	}

	public function fetch_identity( array $callback_data, array $context ) {
		$token = $this->exchange_code( $callback_data['auth_code'] );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = Govid_Http::request(
			'GET',
			$this->base( 'api' ) . 'api/v1/users/me',
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $token['access_token'] ),
				'timeout' => 30,
			)
		);
		$me = Govid_Http::expect_ok( $response, 'MyID users/me' );
		if ( is_wp_error( $me ) ) {
			return $me;
		}

		$profile = isset( $me['profile'] ) && is_array( $me['profile'] ) ? $me['profile'] : null;
		if ( ! $profile ) {
			return new WP_Error( 'provider', 'MyID: profile missing in users/me' );
		}

		$common   = isset( $profile['common_data'] ) && is_array( $profile['common_data'] ) ? $profile['common_data'] : array();
		$contacts = isset( $profile['contacts'] ) && is_array( $profile['contacts'] ) ? $profile['contacts'] : array();

		$identity = new Govid_Identity( $this->id(), isset( $common['pinfl'] ) ? $common['pinfl'] : '', $profile );
		if ( '' === $identity->pin ) {
			return new WP_Error( 'provider', 'MyID: common_data.pinfl missing' );
		}

		$identity->first_name  = Govid_Identity::text( $common, 'first_name' );
		$identity->last_name   = Govid_Identity::text( $common, 'last_name' );
		$identity->middle_name = Govid_Identity::text( $common, 'middle_name' );
		$identity->phone       = Govid_Identity::text( $contacts, 'phone' );
		$identity->email       = Govid_Identity::text( $contacts, 'email' );
		$identity->verified    = true; // Passed biometric verification.

		$first_en = Govid_Identity::text( $common, 'first_name_en' );
		$last_en  = Govid_Identity::text( $common, 'last_name_en' );
		if ( '' !== $first_en || '' !== $last_en ) {
			$identity->login_hint = strtolower( trim( $first_en . '.' . $last_en, '.' ) );
		}

		return $identity;
	}

	// ------------------------------------------------------------------
	// API calls
	// ------------------------------------------------------------------

	/**
	 * Client-credentials token, cached until shortly before expiry.
	 *
	 * @return string|WP_Error
	 */
	private function client_token( $force_refresh = false ) {
		$cache_key = 'govid_myid_ct_' . md5( $this->get( 'environment' ) . '|' . $this->get( 'client_id' ) );

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		$response = Govid_Http::request(
			'POST',
			$this->base( 'api' ) . 'api/v1/oauth2/access-token',
			array(
				'form' => array(
					'grant_type'    => 'client_credentials',
					'client_id'     => $this->get( 'client_id' ),
					'client_secret' => $this->get( 'client_secret' ),
				),
			)
		);
		$data = Govid_Http::expect_ok( $response, 'MyID client token' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['access_token'] ) || ! is_string( $data['access_token'] ) ) {
			return new WP_Error( 'provider', 'MyID: client access_token missing' );
		}

		$ttl = isset( $data['expires_in'] ) ? (int) $data['expires_in'] - 60 : 0;
		if ( $ttl > 0 ) {
			set_transient( $cache_key, $data['access_token'], $ttl );
		}

		return $data['access_token'];
	}

	/**
	 * Authenticated call with the client token; retries once with a fresh token on 401/403.
	 *
	 * @return array|WP_Error Raw response from Govid_Http::request().
	 */
	private function client_request( $method, $path, array $args ) {
		$response = null;

		foreach ( array( false, true ) as $force_refresh ) {
			$token = $this->client_token( $force_refresh );
			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$args['headers'] = array( 'Authorization' => 'Bearer ' . $token );
			$response        = Govid_Http::request( $method, $this->base( 'api' ) . $path, $args );

			if ( is_wp_error( $response ) || ! in_array( $response['status'], array( 401, 403 ), true ) ) {
				break;
			}
		}

		return $response;
	}

	/**
	 * @return array|WP_Error { session_id, external_id }
	 */
	private function create_session() {
		$external_id = wp_generate_uuid4();

		$response = $this->client_request(
			'POST',
			'api/v1/web/sessions',
			array(
				'json' => array(
					'max_retries' => (int) $this->get( 'max_retries' ),
					'external_id' => $external_id,
					'ip_address'  => $this->user_ip(),
				),
			)
		);
		$data = Govid_Http::expect_ok( $response, 'MyID create session' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['session_id'] ) || ! is_string( $data['session_id'] ) ) {
			return new WP_Error( 'provider', 'MyID: session_id missing' );
		}

		return array(
			'session_id'  => $data['session_id'],
			'external_id' => $external_id,
		);
	}

	/**
	 * Exchanges the auth_code (valid 5 minutes, single use). The API may answer
	 * 202 while the verification is still being processed.
	 *
	 * @return array|WP_Error Token response.
	 */
	private function exchange_code( $code ) {
		$args = array(
			'form' => array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => $this->get( 'client_id' ),
				'client_secret' => $this->get( 'client_secret' ),
				'method'        => 'strong',
				'scope'         => $this->scope(),
			),
		);

		$attempts = 3;
		do {
			$response = Govid_Http::request( 'POST', $this->base( 'api' ) . 'api/v1/oauth2/access-token', $args );
			$pending  = ! is_wp_error( $response ) && 202 === $response['status'];
			if ( $pending && --$attempts > 0 ) {
				sleep( 2 );
			}
		} while ( $pending && $attempts > 0 );

		$data = Govid_Http::expect_ok( $response, 'MyID code exchange' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( $pending || empty( $data['access_token'] ) || ! is_string( $data['access_token'] ) ) {
			return new WP_Error( 'provider', 'MyID: user access_token missing' );
		}

		return $data;
	}

	/**
	 * Logs the verification result when the user came back without a code.
	 */
	private function log_session_result( $session_id ) {
		$response = $this->client_request( 'POST', 'api/v1/web/sessions/' . rawurlencode( $session_id ) . '/result', array( 'json' => new stdClass() ) );
		$data     = Govid_Http::expect_ok( $response, 'MyID session result' );
		if ( is_wp_error( $data ) ) {
			Govid_Plugin::log( $data->get_error_message() );
			return;
		}

		$codes = array();
		if ( ! empty( $data['jobs'] ) && is_array( $data['jobs'] ) ) {
			foreach ( $data['jobs'] as $job ) {
				if ( isset( $job['result_code'] ) ) {
					$codes[] = $job['result_code'] . ( isset( $job['result_note'] ) ? ' (' . $job['result_note'] . ')' : '' );
				}
			}
		}
		Govid_Plugin::log( 'MyID verification failed, result codes: ' . ( $codes ? implode( ', ', $codes ) : 'none' ) );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	private function scope() {
		$parts = array_filter( array_map( 'trim', explode( ',', (string) $this->get( 'scope' ) ) ) );
		return implode( ',', $parts );
	}

	private function lang() {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		if ( 0 === strpos( $locale, 'uz' ) ) {
			return 'uz';
		}
		if ( 0 === strpos( $locale, 'en' ) ) {
			return 'en';
		}
		return 'ru';
	}

	/**
	 * The end user's public IP, as required by MyID's anti-fraud checks.
	 */
	private function user_ip() {
		$headers = array(
			'x_forwarded_for'  => 'HTTP_X_FORWARDED_FOR',
			'x_real_ip'        => 'HTTP_X_REAL_IP',
			'cf_connecting_ip' => 'HTTP_CF_CONNECTING_IP',
		);

		$ip     = '';
		$source = (string) $this->get( 'ip_source' );

		if ( isset( $headers[ $source ] ) && ! empty( $_SERVER[ $headers[ $source ] ] ) ) {
			$values = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $headers[ $source ] ] ) ) );
			$ip     = trim( $values[0] );
		}

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		}

		return (string) apply_filters( 'govid_myid_user_ip', $ip );
	}
}
