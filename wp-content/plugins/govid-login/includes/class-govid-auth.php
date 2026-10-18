<?php
/**
 * Provider-independent login flow.
 *
 *   wp-login.php?action=govid&provider={id}   start (optionally shows the provider's start form)
 *   /{id}/callback/                            provider callback (rewrite rule)
 *   wp-login.php?action=govid_confirm         confirmation step, for providers that require it
 *
 * A random state is stored in a transient (server side) and in an HttpOnly cookie
 * (browser side). Both must match on the callback — this prevents login CSRF and replay.
 *
 * @package govid-login
 */

defined( 'ABSPATH' ) || exit;

class Govid_Auth {

	const QUERY_VAR    = 'govid_callback';
	const STATE_COOKIE = 'govid_state';
	const STATE_TTL    = 600;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'parse_request', array( __CLASS__, 'parse_request' ) );
		add_action( 'login_form_govid', array( __CLASS__, 'start' ) );
		add_action( 'login_form_govid_confirm', array( __CLASS__, 'confirm' ) );
		add_filter( 'wp_login_errors', array( __CLASS__, 'login_errors' ) );
		add_action( 'wp_logout', array( __CLASS__, 'logout' ) );
	}

	public static function add_rewrite_rules() {
		$ids = array_keys( Govid_Providers::all() );
		if ( $ids ) {
			add_rewrite_rule( '^(' . implode( '|', $ids ) . ')/callback/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * URL that starts a login with the given provider.
	 */
	public static function login_url( $provider_id, $redirect_to = '' ) {
		return add_query_arg(
			array(
				'action'   => 'govid',
				'provider' => $provider_id,
			),
			wp_login_url( $redirect_to )
		);
	}

	// ------------------------------------------------------------------
	// Step 1: start
	// ------------------------------------------------------------------

	public static function start() {
		// phpcs:disable WordPress.Security.NonceVerification -- public entry point; the form below is nonce-protected.
		$provider_id = isset( $_REQUEST['provider'] ) ? sanitize_key( wp_unslash( $_REQUEST['provider'] ) ) : '';
		$redirect_to = isset( $_REQUEST['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ), '' ) : '';
		// phpcs:enable

		$provider = Govid_Providers::get( $provider_id );
		if ( ! $provider || ! $provider->is_active() ) {
			self::fail( 'not_configured' );
		}

		$form_data = array();

		if ( $provider->has_start_form() ) {
			$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
			if ( ! $is_post ) {
				self::render_start_form( $provider, $redirect_to );
			}

			$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'govid_start_' . $provider->id() ) ) {
				self::render_start_form( $provider, $redirect_to, array(), __( 'Срок действия формы истёк. Отправьте её ещё раз.', 'govid-login' ) );
			}

			$form_data = $provider->validate_start_form( wp_unslash( $_POST ) );
			if ( is_wp_error( $form_data ) ) {
				$values = $form_data->get_error_data();
				self::render_start_form( $provider, $redirect_to, is_array( $values ) ? $values : array(), $form_data->get_error_message() );
			}
		}

		$state = wp_generate_password( 32, false );
		$begin = $provider->begin_authorization( $state, $form_data );
		if ( is_wp_error( $begin ) ) {
			self::fail( $begin->get_error_code(), $begin );
		}

		set_transient(
			self::state_key( $state ),
			array(
				'provider'    => $provider->id(),
				'redirect_to' => $redirect_to,
				'context'     => isset( $begin['context'] ) ? (array) $begin['context'] : array(),
			),
			self::STATE_TTL
		);
		self::set_state_cookie( $state, time() + self::STATE_TTL );

		nocache_headers();
		wp_redirect( $begin['url'] ); // phpcs:ignore WordPress.Security.SafeRedirect -- external identity provider.
		exit;
	}

	/**
	 * Renders the provider's start form inside the wp-login.php layout and exits.
	 */
	private static function render_start_form( Govid_Provider $provider, $redirect_to, array $values = array(), $error = '' ) {
		$errors = new WP_Error();
		if ( '' !== $error ) {
			$errors->add( 'govid_form', $error );
		}

		nocache_headers();
		login_header( $provider->button_label(), '', $errors );
		?>
		<form method="post" action="<?php echo esc_url( self::login_url( $provider->id(), $redirect_to ) ); ?>" class="govid-start-form">
			<?php wp_nonce_field( 'govid_start_' . $provider->id() ); ?>
			<?php $provider->render_start_form( $values ); ?>
			<p class="submit">
				<input type="submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Продолжить', 'govid-login' ); ?>" />
			</p>
		</form>
		<p id="nav"><a href="<?php echo esc_url( wp_login_url( $redirect_to ) ); ?>">&larr; <?php esc_html_e( 'Другие способы входа', 'govid-login' ); ?></a></p>
		<?php
		login_footer();
		exit;
	}

	// ------------------------------------------------------------------
	// Step 2: callback
	// ------------------------------------------------------------------

	/**
	 * @param WP $wp Current request.
	 */
	public static function parse_request( $wp ) {
		if ( empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}

		$provider = Govid_Providers::get( $wp->query_vars[ self::QUERY_VAR ] );
		if ( $provider ) {
			self::callback( $provider );
		}
	}

	private static function callback( Govid_Provider $provider ) {
		nocache_headers();

		list( $state, $stored ) = self::load_state();
		if ( ! $stored || $stored['provider'] !== $provider->id() ) {
			self::fail( 'invalid_state' );
		}

		if ( ! $provider->is_active() ) {
			self::fail( 'not_configured' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification -- validated by the state/session binding.
		$data = $provider->parse_callback( wp_unslash( $_GET ), $state, $stored['context'] );
		if ( is_wp_error( $data ) ) {
			self::fail( $data->get_error_code(), $data );
		}

		if ( $provider->requires_confirmation() ) {
			$stored['callback'] = $data;
			set_transient( self::state_key( $state ), $stored, self::STATE_TTL );
			wp_safe_redirect( add_query_arg( 'action', 'govid_confirm', wp_login_url() ) );
			exit;
		}

		self::complete( $provider, $state, $stored, $data );
	}

	// ------------------------------------------------------------------
	// Step 3 (optional): explicit confirmation by the user
	// ------------------------------------------------------------------

	public static function confirm() {
		nocache_headers();

		list( $state, $stored ) = self::load_state();
		$provider               = $stored ? Govid_Providers::get( $stored['provider'] ) : null;

		if ( ! $provider || ! isset( $stored['callback'] ) ) {
			self::fail( 'invalid_state' );
		}

		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		$nonce   = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( $is_post && wp_verify_nonce( $nonce, 'govid_confirm_' . $state ) ) {
			self::complete( $provider, $state, $stored, $stored['callback'] );
		}

		login_header( __( 'Подтверждение входа', 'govid-login' ) );
		?>
		<form method="post" action="<?php echo esc_url( add_query_arg( 'action', 'govid_confirm', wp_login_url() ) ); ?>">
			<?php wp_nonce_field( 'govid_confirm_' . $state ); ?>
			<p>
				<?php
				/* translators: %s: provider name */
				echo esc_html( sprintf( __( 'Проверка в %s пройдена. Нажмите «Войти», чтобы завершить вход на сайт.', 'govid-login' ), $provider->label() ) );
				?>
			</p>
			<p class="submit">
				<input type="submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Войти', 'govid-login' ); ?>" />
			</p>
		</form>
		<?php
		login_footer();
		exit;
	}

	// ------------------------------------------------------------------
	// Step 4: identify and log in
	// ------------------------------------------------------------------

	private static function complete( Govid_Provider $provider, $state, array $stored, array $data ) {
		delete_transient( self::state_key( $state ) );
		self::clear_state_cookie();

		$identity = $provider->fetch_identity( $data, $stored['context'] );
		if ( is_wp_error( $identity ) ) {
			self::fail( $identity->get_error_code(), $identity );
		}

		$user = Govid_Users::find_or_create( $identity, $provider );
		if ( is_wp_error( $user ) ) {
			self::fail( $user->get_error_code(), $user );
		}

		/**
		 * Allows denying a login, e.g. to require a verified identity:
		 * add_filter( 'govid_allow_login', fn( $ok, $user, $identity ) => $ok && $identity->verified, 10, 3 );
		 *
		 * @param bool            $allowed  Whether to log the user in.
		 * @param WP_User         $user     WordPress user.
		 * @param Govid_Identity $identity Identity returned by the provider.
		 */
		if ( true !== apply_filters( 'govid_allow_login', true, $user, $identity ) ) {
			self::fail( 'not_allowed' );
		}

		$provider->on_login( $user, $identity );

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, false );
		do_action( 'wp_login', $user->user_login, $user );

		$requested = isset( $stored['redirect_to'] ) ? (string) $stored['redirect_to'] : '';
		$redirect  = apply_filters( 'login_redirect', $requested ? $requested : home_url( '/' ), $requested, $user );

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * @param int $user_id User ID.
	 */
	public static function logout( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return;
		}
		foreach ( Govid_Providers::all() as $provider ) {
			$provider->on_logout( $user_id );
		}
	}

	// ------------------------------------------------------------------
	// Errors
	// ------------------------------------------------------------------

	/**
	 * @param WP_Error $errors Login errors.
	 * @return WP_Error
	 */
	public static function login_errors( $errors ) {
		// phpcs:ignore WordPress.Security.NonceVerification
		$code = isset( $_GET['govid_error'] ) ? sanitize_key( wp_unslash( $_GET['govid_error'] ) ) : '';
		if ( '' === $code ) {
			return $errors;
		}

		$messages = self::error_messages();
		$errors->add( 'govid_' . $code, isset( $messages[ $code ] ) ? $messages[ $code ] : $messages['provider'] );

		return $errors;
	}

	private static function error_messages() {
		return array(
			'not_configured'        => __( 'Этот способ входа недоступен. Обратитесь к администратору сайта.', 'govid-login' ),
			'invalid_state'         => __( 'Сессия входа устарела или недействительна. Попробуйте войти ещё раз.', 'govid-login' ),
			'cancelled'             => __( 'Вход был отменён.', 'govid-login' ),
			'verification_failed'   => __( 'Проверка личности не пройдена. Убедитесь, что данные документа и дата рождения указаны верно, и попробуйте ещё раз.', 'govid-login' ),
			'rate_limited'          => __( 'Слишком много попыток. Повторите позже.', 'govid-login' ),
			'provider'              => __( 'Не удалось получить данные от сервиса идентификации. Попробуйте позже.', 'govid-login' ),
			'registration_disabled' => __( 'Пользователь не зарегистрирован на сайте.', 'govid-login' ),
			'create_failed'         => __( 'Не удалось создать учётную запись. Обратитесь к администратору сайта.', 'govid-login' ),
			'not_allowed'           => __( 'Вход для этой учётной записи запрещён.', 'govid-login' ),
		);
	}

	/**
	 * Redirects to wp-login.php with an error code and stops execution.
	 */
	private static function fail( $code, $error = null ) {
		if ( $error instanceof WP_Error ) {
			Govid_Plugin::log( $code . ': ' . $error->get_error_message() . ' ' . wp_json_encode( $error->get_error_data() ) );
		}

		$known = self::error_messages();
		$code  = isset( $known[ $code ] ) ? $code : 'provider';

		self::clear_state_cookie();
		wp_safe_redirect( add_query_arg( 'govid_error', $code, wp_login_url() ) );
		exit;
	}

	// ------------------------------------------------------------------
	// State
	// ------------------------------------------------------------------

	/**
	 * @return array [ string $state, array|null $stored ]
	 */
	private static function load_state() {
		$state = isset( $_COOKIE[ self::STATE_COOKIE ] ) && is_string( $_COOKIE[ self::STATE_COOKIE ] )
			? sanitize_text_field( wp_unslash( $_COOKIE[ self::STATE_COOKIE ] ) )
			: '';

		if ( ! preg_match( '/^[A-Za-z0-9]{32}$/', $state ) ) {
			return array( '', null );
		}

		$stored = get_transient( self::state_key( $state ) );
		if ( ! is_array( $stored ) || empty( $stored['provider'] ) ) {
			return array( $state, null );
		}

		$stored['context'] = isset( $stored['context'] ) ? (array) $stored['context'] : array();

		return array( $state, $stored );
	}

	private static function state_key( $state ) {
		return 'govid_state_' . $state;
	}

	private static function clear_state_cookie() {
		self::set_state_cookie( '', time() - YEAR_IN_SECONDS );
	}

	private static function set_state_cookie( $value, $expires ) {
		if ( headers_sent() ) {
			return;
		}

		setcookie(
			self::STATE_COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax', // Sent on the top-level redirect back from the provider.
			)
		);
	}
}
