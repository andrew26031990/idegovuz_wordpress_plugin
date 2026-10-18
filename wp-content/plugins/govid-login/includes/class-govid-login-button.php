<?php
/**
 * Login buttons on wp-login.php and via the [govid_login] shortcode.
 *
 * @package govid-login
 */

defined( 'ABSPATH' ) || exit;

class Govid_Login_Button {

	const HANDLE = 'govid-login';

	/** Query flag that shows the password form when it is hidden by default. */
	const PASSWORD_FLAG = 'govid_password';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_assets' ) );
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'enqueue_login_assets' ) );
		add_action( 'login_form', array( __CLASS__, 'render_login_buttons' ) );
		add_filter( 'login_body_class', array( __CLASS__, 'login_body_class' ) );
		add_shortcode( 'govid_login', array( __CLASS__, 'shortcode' ) );
	}

	public static function register_assets() {
		wp_register_style( self::HANDLE, GOVID_URL . 'assets/css/login.css', array(), GOVID_VERSION );
		wp_register_script( self::HANDLE, GOVID_URL . 'assets/js/login.js', array(), GOVID_VERSION, true );
	}

	public static function enqueue_login_assets() {
		if ( ! Govid_Providers::active() ) {
			return;
		}
		wp_enqueue_style( self::HANDLE );
		if ( self::hide_password_form() ) {
			wp_enqueue_script( self::HANDLE );
		}
	}

	public static function login_body_class( $classes ) {
		if ( self::hide_password_form() ) {
			$classes[] = 'govid-only';
		}
		return $classes;
	}

	public static function render_login_buttons() {
		$providers = Govid_Providers::active();
		if ( ! $providers ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification
		$redirect_to = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';

		echo self::buttons_html( $providers, $redirect_to ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in buttons_html().

		if ( self::hide_password_form() ) {
			printf(
				'<div class="govid-login__toggle-wrap"><a href="%s" class="govid-login__toggle">%s</a></div>',
				esc_url( add_query_arg( self::PASSWORD_FLAG, 1, wp_login_url( $redirect_to ) ) ),
				esc_html__( 'Войти с логином и паролем', 'govid-login' )
			);
		}
	}

	/**
	 * [govid_login providers="oneid,myid" redirect_to="/account/"]
	 */
	public static function shortcode( $atts ) {
		if ( is_user_logged_in() ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'providers'   => '',
				'redirect_to' => '',
			),
			$atts,
			'govid_login'
		);

		$only      = array_filter( array_map( 'sanitize_key', explode( ',', (string) $atts['providers'] ) ) );
		$providers = Govid_Providers::active( $only );
		if ( ! $providers ) {
			return '';
		}

		$redirect_to = (string) $atts['redirect_to'];
		if ( '' === $redirect_to ) {
			$redirect_to = is_singular() ? get_permalink() : home_url( '/' );
		}

		wp_enqueue_style( self::HANDLE );

		return self::buttons_html( $providers, esc_url_raw( $redirect_to ) );
	}

	/**
	 * @param Govid_Provider[] $providers   Providers to show.
	 * @param string            $redirect_to Where to go after login.
	 */
	public static function buttons_html( array $providers, $redirect_to = '' ) {
		$html = '';
		foreach ( $providers as $provider ) {
			$html .= sprintf(
				'<a class="govid-login__button govid-login__button--%1$s" href="%2$s">%3$s</a>',
				esc_attr( $provider->id() ),
				esc_url( Govid_Auth::login_url( $provider->id(), $redirect_to ) ),
				esc_html( $provider->button_label() )
			);
		}
		return '<div class="govid-login">' . $html . '</div>';
	}

	private static function hide_password_form() {
		if ( ! Govid_Settings::get( 'hide_password_form' ) || ! Govid_Providers::active() ) {
			return false;
		}

		// Keep the form visible after a password login attempt so errors appear next to the fields.
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( ! empty( $_GET[ self::PASSWORD_FLAG ] ) || isset( $_POST['log'] ) ) {
			return false;
		}

		return true;
	}
}
