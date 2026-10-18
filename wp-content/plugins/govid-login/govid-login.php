<?php
/**
 * Plugin Name:       Gov ID Login (OneID, MyID)
 * Plugin URI:        https://github.com/andrew26031990/idegovuz_wordpress_plugin
 * Description:       Вход и регистрация на сайте через государственные сервисы идентификации Узбекистана: OneID (id.egov.uz) и MyID (myid.uz).
 * Version:           2.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Andrew Magzumov
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       govid-login
 */

defined( 'ABSPATH' ) || exit;

define( 'GOVID_VERSION', '2.0.0' );
define( 'GOVID_FILE', __FILE__ );
define( 'GOVID_DIR', plugin_dir_path( __FILE__ ) );
define( 'GOVID_URL', plugin_dir_url( __FILE__ ) );

require_once GOVID_DIR . 'includes/class-govid-http.php';
require_once GOVID_DIR . 'includes/class-govid-identity.php';
require_once GOVID_DIR . 'includes/providers/abstract-govid-provider.php';
require_once GOVID_DIR . 'includes/providers/class-govid-provider-oneid.php';
require_once GOVID_DIR . 'includes/providers/class-govid-provider-myid.php';
require_once GOVID_DIR . 'includes/class-govid-providers.php';
require_once GOVID_DIR . 'includes/class-govid-settings.php';
require_once GOVID_DIR . 'includes/class-govid-users.php';
require_once GOVID_DIR . 'includes/class-govid-auth.php';
require_once GOVID_DIR . 'includes/class-govid-login-button.php';
require_once GOVID_DIR . 'includes/class-govid-profile.php';
require_once GOVID_DIR . 'includes/class-govid-plugin.php';

register_activation_hook( __FILE__, array( 'Govid_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Govid_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Govid_Plugin', 'init' ) );
