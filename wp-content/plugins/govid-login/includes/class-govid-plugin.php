<?php
/**
 * Bootstrap, activation and upgrade routines.
 *
 * @package govid-login
 */

defined( 'ABSPATH' ) || exit;

class Govid_Plugin {

	/** Bump when an upgrade routine must run on existing installs. */
	const DB_VERSION = 2;

	const DB_VERSION_OPTION = 'govid_db_version';

	/**
	 * Password that version 1.x assigned to every user created via OneID.
	 */
	const LEGACY_PASSWORD = '123456789';

	/**
	 * Line that version 1.x injected into wp-login.php.
	 */
	const LEGACY_LOGIN_HOOK = "do_action('id_egov_button_shortcode');";

	/**
	 * Main file of version 1.x.
	 */
	const LEGACY_PLUGIN = 'idegovplugin/idegovplugin.php';

	public static function init() {
		Govid_Settings::init();
		Govid_Auth::init();
		Govid_Login_Button::init();
		Govid_Profile::init();

		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( 'admin_notices', array( __CLASS__, 'legacy_plugin_notice' ) );
	}

	/**
	 * Version 1.x lived in another folder ("idegovplugin") and is a separate plugin
	 * from WordPress' point of view. Ask the admin to remove it.
	 */
	public static function legacy_plugin_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active( self::LEGACY_PLUGIN ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Активна старая версия плагина входа через OneID (idegovplugin). Деактивируйте и удалите её: Gov ID Login полностью её заменяет, а пользователи и их привязки переносятся автоматически.', 'govid-login' )
		);
	}

	public static function activate() {
		Govid_Auth::add_rewrite_rules();
		flush_rewrite_rules();
		self::maybe_upgrade();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Runs one-time migrations for sites upgrading from 1.x.
	 */
	public static function maybe_upgrade() {
		$installed = (int) get_option( self::DB_VERSION_OPTION, 0 );
		if ( $installed >= self::DB_VERSION ) {
			return;
		}

		if ( $installed < 2 ) {
			self::remove_legacy_login_hook();
			self::migrate_legacy_users();
			// The callback URL is served by a rewrite rule since 2.0.
			Govid_Auth::add_rewrite_rules();
			flush_rewrite_rules();
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Version 1.x patched wp-login.php directly. Remove our line if it is still there.
	 */
	private static function remove_legacy_login_hook() {
		$file = ABSPATH . 'wp-login.php';
		if ( ! is_readable( $file ) || ! is_writable( $file ) ) {
			return;
		}

		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $contents || false === strpos( $contents, self::LEGACY_LOGIN_HOOK ) ) {
			return;
		}

		$contents = str_replace( self::LEGACY_LOGIN_HOOK . PHP_EOL, '', $contents );
		$contents = str_replace( self::LEGACY_LOGIN_HOOK, '', $contents );
		file_put_contents( $file, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Users created by 1.x:
	 *  - store the PINFL in the "pinfl" meta key -> copy it to the new key;
	 *  - all share the password "123456789" -> replace it with a random one.
	 */
	private static function migrate_legacy_users() {
		$users = get_users(
			array(
				'blog_id'  => 0,
				'meta_key' => Govid_Users::LEGACY_META_PIN, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'   => 'all',
			)
		);

		foreach ( $users as $user ) {
			if ( ! get_user_meta( $user->ID, Govid_Users::META_PIN, true ) ) {
				$pin = get_user_meta( $user->ID, Govid_Users::LEGACY_META_PIN, true );
				if ( $pin ) {
					update_user_meta( $user->ID, Govid_Users::META_PIN, $pin );
				}
			}

			if ( wp_check_password( self::LEGACY_PASSWORD, $user->user_pass, $user->ID ) ) {
				wp_set_password( wp_generate_password( 32, true, true ), $user->ID );
			}
		}
	}

	/**
	 * Writes a debug message to the PHP error log when WP_DEBUG is on.
	 * Never pass secrets or tokens here.
	 *
	 * @param string $message Message.
	 */
	public static function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[govid-login] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
