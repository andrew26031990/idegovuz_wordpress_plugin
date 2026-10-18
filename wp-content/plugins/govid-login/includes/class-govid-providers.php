<?php
/**
 * Provider registry.
 *
 * Third-party providers can be added with the "govid_providers" filter (hook it
 * before "init"):
 *
 *     add_filter( 'govid_providers', function ( $providers ) {
 *         $providers[] = new My_Provider(); // extends Govid_Provider
 *         return $providers;
 *     } );
 *
 * @package govid-login
 */

defined( 'ABSPATH' ) || exit;

class Govid_Providers {

	/** @var Govid_Provider[]|null */
	private static $providers = null;

	/**
	 * @return Govid_Provider[] id => provider
	 */
	public static function all() {
		if ( null === self::$providers ) {
			$list = apply_filters(
				'govid_providers',
				array(
					new Govid_Provider_OneID(),
					new Govid_Provider_MyID(),
				)
			);

			self::$providers = array();
			foreach ( (array) $list as $provider ) {
				if ( $provider instanceof Govid_Provider && preg_match( '/^[a-z0-9_]+$/', $provider->id() ) ) {
					self::$providers[ $provider->id() ] = $provider;
				}
			}
		}

		return self::$providers;
	}

	/**
	 * @return Govid_Provider|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return is_string( $id ) && isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Enabled and configured providers, ordered by the "priority" setting.
	 *
	 * @param string[] $only Restrict to these ids (keeps priority order).
	 * @return Govid_Provider[]
	 */
	public static function active( array $only = array() ) {
		$active = array_filter(
			self::all(),
			static function ( Govid_Provider $provider ) use ( $only ) {
				return $provider->is_active() && ( ! $only || in_array( $provider->id(), $only, true ) );
			}
		);

		uasort(
			$active,
			static function ( Govid_Provider $a, Govid_Provider $b ) {
				return $a->priority() <=> $b->priority();
			}
		);

		return $active;
	}
}
