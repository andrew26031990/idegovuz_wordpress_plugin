<?php
/**
 * Cleanup on plugin deletion.
 *
 * Settings and stored provider tokens are removed. PINFL links and profile data on
 * user accounts are kept on purpose: deleting the links would create duplicate
 * accounts if the plugin is installed again.
 *
 * @package govid-login
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'govid_settings' );
delete_option( 'govid_db_version' );

foreach ( array( 'oneid', 'myid' ) as $govid_provider ) {
	delete_option( 'govid_provider_' . $govid_provider );
}

delete_metadata( 'user', 0, 'govid_oneid_access_token', '', true );
