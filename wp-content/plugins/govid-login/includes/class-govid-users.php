<?php
/**
 * Mapping identities to WordPress users.
 *
 * Users are matched by PINFL, stored in user meta, so the same person gets the
 * same account regardless of the provider (OneID, MyID, ...). Users are never
 * linked by e-mail or login: an existing account with the same e-mail is not
 * taken over automatically.
 *
 * Always saved: first/last/middle name, display name, phone.
 * Optionally saved: provider-specific "extra fields" configured in the settings.
 *
 * @package govid-login
 */

defined( 'ABSPATH' ) || exit;

class Govid_Users {

	const META_PIN           = 'govid_pin';
	const META_MIDDLE_NAME   = 'govid_middle_name';
	const META_VERIFIED      = 'govid_verified';
	const META_LAST_LOGIN    = 'govid_last_login';
	const META_LAST_PROVIDER = 'govid_last_provider';
	const META_PHONE         = 'phone';

	/** Meta key used by plugin version 1.x. */
	const LEGACY_META_PIN = 'pinfl';

	/**
	 * @return WP_User|WP_Error
	 */
	public static function find_or_create( Govid_Identity $identity, Govid_Provider $provider ) {
		if ( '' === $identity->pin ) {
			return new WP_Error( 'provider', 'Identity without PINFL' );
		}

		$user    = self::find_by_pin( $identity->pin );
		$created = false;

		if ( ! $user ) {
			if ( ! Govid_Settings::get( 'allow_registration' ) ) {
				return new WP_Error( 'registration_disabled', 'Registration disabled' );
			}

			$user = self::create( $identity );
			if ( is_wp_error( $user ) ) {
				return $user;
			}
			$created = true;
		}

		if ( $created || Govid_Settings::get( 'sync_profile' ) ) {
			self::sync_profile( $user->ID, $identity );
			self::save_extra_fields( $user->ID, $identity, $provider );
		}

		update_user_meta( $user->ID, self::META_LAST_LOGIN, time() );
		update_user_meta( $user->ID, self::META_LAST_PROVIDER, $provider->id() );

		return $user;
	}

	/**
	 * @return WP_User|null
	 */
	public static function find_by_pin( $pin ) {
		foreach ( array( self::META_PIN, self::LEGACY_META_PIN ) as $key ) {
			$ids = get_users(
				array(
					'blog_id'    => 0,
					'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value' => $pin, // phpcs:ignore WordPress.DB.SlowDBQuery
					'fields'     => 'ID',
					'orderby'    => 'ID',
					'order'      => 'ASC',
					'number'     => 2,
				)
			);

			if ( empty( $ids ) ) {
				continue;
			}

			if ( count( $ids ) > 1 ) {
				Govid_Plugin::log( 'Several users share the same PINFL, using the oldest one: ' . implode( ',', $ids ) );
			}

			$user = get_user_by( 'id', (int) $ids[0] );
			if ( ! $user ) {
				continue;
			}

			if ( self::LEGACY_META_PIN === $key ) {
				update_user_meta( $user->ID, self::META_PIN, $pin );
			}

			return $user;
		}

		return null;
	}

	public static function get_pin( $user_id ) {
		$pin = get_user_meta( $user_id, self::META_PIN, true );
		return $pin ? $pin : (string) get_user_meta( $user_id, self::LEGACY_META_PIN, true );
	}

	/**
	 * Removes the link between a user and their PINFL.
	 */
	public static function unlink( $user_id ) {
		delete_user_meta( $user_id, self::META_PIN );
		delete_user_meta( $user_id, self::LEGACY_META_PIN );
		delete_user_meta( $user_id, self::META_VERIFIED );
		foreach ( Govid_Providers::all() as $provider ) {
			$provider->on_logout( $user_id );
		}
	}

	/**
	 * @return WP_User|WP_Error
	 */
	private static function create( Govid_Identity $identity ) {
		$email = sanitize_email( $identity->email );
		if ( '' !== $email && ( ! is_email( $email ) || email_exists( $email ) ) ) {
			$email = '';
		}

		$userdata = array(
			'user_login' => self::unique_login( $identity->login_hint, $identity->provider ),
			'user_pass'  => wp_generate_password( 32, true, true ),
			'user_email' => $email,
			'role'       => Govid_Settings::default_role(),
		);

		/**
		 * Filters wp_insert_user() arguments for a user registered via a provider.
		 *
		 * @param array           $userdata User data.
		 * @param Govid_Identity $identity Identity.
		 */
		$userdata = apply_filters( 'govid_new_user_data', $userdata, $identity );

		$user_id = wp_insert_user( $userdata );
		if ( is_wp_error( $user_id ) ) {
			return new WP_Error( 'create_failed', $user_id->get_error_message() );
		}

		update_user_meta( $user_id, self::META_PIN, $identity->pin );

		/**
		 * Fires after a user was registered via a provider.
		 *
		 * @param int             $user_id  User ID.
		 * @param Govid_Identity $identity Identity.
		 */
		do_action( 'govid_user_created', $user_id, $identity );

		return get_user_by( 'id', $user_id );
	}

	/**
	 * Standard fields: names and phone. Empty values never overwrite existing data.
	 */
	private static function sync_profile( $user_id, Govid_Identity $identity ) {
		$userdata = array( 'ID' => $user_id );

		if ( '' !== $identity->first_name ) {
			$userdata['first_name'] = $identity->first_name;
		}
		if ( '' !== $identity->last_name ) {
			$userdata['last_name'] = $identity->last_name;
		}

		$display = $identity->get_display_name();
		if ( '' !== $display ) {
			$userdata['display_name'] = $display;
			$userdata['nickname']     = $display;
		}

		if ( count( $userdata ) > 1 ) {
			wp_update_user( $userdata );
		}

		if ( '' !== $identity->middle_name ) {
			update_user_meta( $user_id, self::META_MIDDLE_NAME, $identity->middle_name );
		}

		$phone = preg_replace( '/[^0-9+]/', '', $identity->phone );
		if ( '' !== $phone ) {
			update_user_meta( $user_id, self::META_PHONE, $phone );
		}

		if ( $identity->verified ) {
			update_user_meta( $user_id, self::META_VERIFIED, 1 );
		}

		/**
		 * Fires after a user's profile was synced.
		 *
		 * @param int             $user_id  User ID.
		 * @param Govid_Identity $identity Identity (raw provider data in $identity->raw).
		 */
		do_action( 'govid_profile_synced', $user_id, $identity );
	}

	/**
	 * Fields configured in the provider's "extra fields" setting.
	 */
	private static function save_extra_fields( $user_id, Govid_Identity $identity, Govid_Provider $provider ) {
		foreach ( $provider->extra_field_map() as $path => $meta_key ) {
			$value = $identity->get_path( $path );
			if ( null === $value || '' === $value || array() === $value ) {
				continue;
			}

			$value = is_array( $value ) ? map_deep( $value, 'sanitize_text_field' ) : sanitize_text_field( (string) $value );
			update_user_meta( $user_id, $meta_key, $value );
		}
	}

	/**
	 * Parses "path, path:meta_key, ..." into [ path => meta_key ].
	 * Invalid entries and protected meta keys are skipped.
	 *
	 * @return array
	 */
	public static function parse_field_map( $text, $provider_id ) {
		$map = array();

		foreach ( preg_split( '/[\s,;]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY ) as $item ) {
			$parts = explode( ':', $item, 2 );
			$path  = trim( $parts[0] );

			if ( ! preg_match( '/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)*$/', $path ) ) {
				continue;
			}

			$meta_key = isset( $parts[1] ) && '' !== trim( $parts[1] )
				? sanitize_key( $parts[1] )
				: 'govid_' . $provider_id . '_' . strtolower( str_replace( '.', '_', $path ) );

			if ( '' === $meta_key || self::is_protected_meta_key( $meta_key ) ) {
				continue;
			}

			$map[ $path ] = $meta_key;
		}

		return $map;
	}

	/**
	 * Meta keys that must never be written from provider data (roles, sessions, links).
	 */
	public static function is_protected_meta_key( $key ) {
		global $wpdb;

		if ( 0 === strpos( $key, $wpdb->base_prefix ) ) {
			return true; // {prefix}capabilities, {prefix}user_level, per-site variants.
		}

		return in_array(
			$key,
			array(
				'session_tokens',
				'default_password_nag',
				self::META_PIN,
				self::LEGACY_META_PIN,
				self::META_VERIFIED,
				self::META_LAST_PROVIDER,
				self::META_LAST_LOGIN,
				Govid_Provider_OneID::META_TOKEN,
			),
			true
		);
	}

	private static function unique_login( $hint, $provider_id ) {
		$base = substr( sanitize_user( (string) $hint, true ), 0, 50 );

		if ( '' === $base ) {
			$base = $provider_id . '_' . strtolower( wp_generate_password( 8, false ) );
		}

		$login  = $base;
		$suffix = 1;
		while ( username_exists( $login ) ) {
			++$suffix;
			$login = $base . '_' . $suffix;
		}

		return $login;
	}
}
