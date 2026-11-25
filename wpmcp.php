<?php
/**
 * Plugin Name: WPMCP - MCP Server with Snapshot Undo for AI Agents
 * Description: AI builds and edits your WordPress site over MCP, with a snapshot before each supported write and one-click rollback.
 * Version: 2.0.1
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: Fahad Murtaza
 * Author URI: https://wpmcp-pro.com/
 * Update URI: https://wpmcp-pro.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wpmcp
 * Domain Path: /languages
 * WPMCP Flavor: full
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/src/flavor-guard.php';
// The premium zip (a .wpmcp-pro file beside this one) replaces the free
// zip: it deactivates any free copy on its own activation and on admin_init.
// Wired before the guard, because a copy that stands down returns below.
if ( wpmcp_is_pro_build( __FILE__ ) ) {
	register_activation_hook( __FILE__, static function () { wpmcp_pro_deactivate_free_copies( __FILE__ ); } );
	add_action( 'admin_init', static function () { wpmcp_pro_deactivate_free_copies( __FILE__ ); } );
}
// Only one WPMCP build may boot per request: they share the WPMCP_* constants,
// the \WPMCP\ namespace and all persisted state. The guard ranks builds by the
// WPMCP Flavor header above, and 'full' ranks highest, so this file only
// stands down for a copy that already booted this request (a second install
// of the full plugin in another directory, or a vertical booted in the same
// request that activates this file) or, as the free zip, for an active
// premium copy. That return also skips Plugin::boot(),
// so register_activation_hook() is not reached and Activator::activate does
// not run for this activation. Acceptable: every table it would create is
// shared with the copy that booted, which created them on its own activation.
// A schema change only this copy knows about waits until it is deactivated
// and reactivated after the other build is gone.
if ( wpmcp_flavor_should_defer( __FILE__, 'full', defined( 'WPMCP_VERSION' ) ) ) {
	// No load_plugin_textdomain() here: Plugin::load_textdomain() is the one
	// loader (ListingRulesTest pins it), the copy that booted loads the
	// 'wpmcp' domain itself, and core's just-in-time loader covers the notice.
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'This copy of WPMCP is inactive because another WPMCP build already loaded on this site. Deactivate one of them.', 'wpmcp' );
		echo '</p></div>';
	} );
	return;
}
// After the guard, so only the copy that boots defines it.
if ( ! defined( 'WPMCP_PREMIUM_BUILD' ) ) {
	define( 'WPMCP_PREMIUM_BUILD', is_readable( __DIR__ . '/.wpmcp-pro' ) );
}
define( 'WPMCP_VERSION', '2.0.1' );
// Must match the Text Domain header above: Plugin::load_textdomain() loads
// the self-hosted .mo from languages/ into this domain (issue #184).
define( 'WPMCP_TEXT_DOMAIN', 'wpmcp' );
define( 'WPMCP_FILE', __FILE__ );
define( 'WPMCP_DIR', plugin_dir_path( __FILE__ ) );
// Freemius credentials (registered on freemius.com; the public key is public by design).
define( 'WPMCP_FS_ID', 34955 );
define( 'WPMCP_FS_PUBLIC_KEY', 'pk_198c5294157bf7068fd2ffd493957' );
require_once __DIR__ . '/vendor/autoload.php';
// Never let the bundled MCP Adapter shadow the canonical plugin or another
// plugin's copy (issue #386); see src/adapter-guard.php.
require_once __DIR__ . '/src/adapter-guard.php';
wpmcp_prefer_shared_mcp_adapter( __DIR__ . '/vendor' );
\WPMCP\Freemius\Bootstrap::init();
// Free installs of this build hear about new versions from GitHub releases;
// a licensed site is updated by the licensing SDK instead. The wordpress.org
// builds ship their own loader and no src/Updates (src/Updates/GitHub_Releases.php).
// The premium zip is updated by the licensing SDK only.
if ( ! WPMCP_PREMIUM_BUILD ) {
	\WPMCP\Updates\GitHub_Releases::register();
}
// Pro, when this copy carries it (pro/ in a dev checkout or the premium zip);
// the free zip has no pro/ and boots with every edition slot empty.
( new \WPMCP\Edition\Pro_Loader( __DIR__ ) )->load();
\WPMCP\Plugin::instance()->boot();
