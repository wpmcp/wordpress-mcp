<?php
/**
 * Bootstrap of the fast tier (phpunit-fast.xml.dist): no WordPress, no
 * database. The tier holds test classes that pass without WordPress loaded,
 * so they run per class under paratest, first in the parallel gate
 * (bin/test-local.sh --parallel), once per run instead of in each suite leg.
 * A serial run still runs them in the full suite on every WordPress leg.
 *
 * Several of them extend \WP_UnitTestCase only by habit. Without the WordPress
 * test library it is an empty PHPUnit test case here (with the Yoast
 * polyfills' snake_case fixtures, as WordPress's own base class has); any
 * class that calls into WordPress fails at once, and belongs in the full suite
 * only.
 */

if ( ! defined( 'WPMCP_TESTING' ) ) {
    define( 'WPMCP_TESTING', true );
}
// The plugin's files return early without it; nothing is loaded from here.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', sys_get_temp_dir() . '/wpmcp-fast-tier-no-wordpress/' );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once __DIR__ . '/support/edition-tree.php';

// build/registry first, so the suite never runs on a missing or stale module
// registry (a worktree whose vendor/ was copied has none):
// tools/registry/src/Freshness.php.
$wpmcp_registry_error = \WPMCP\Registry\Freshness::ensure( dirname( __DIR__ ) );
if ( null !== $wpmcp_registry_error ) {
    fwrite( STDERR, "WP MCP tests: build/registry is stale and could not be rebuilt: {$wpmcp_registry_error}\n" );
    exit( 1 );
}

if ( ! class_exists( 'WP_UnitTestCase', false ) ) {
    // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, PSR1.Classes.ClassDeclaration.MissingNamespace
    abstract class WP_UnitTestCase extends \Yoast\PHPUnitPolyfills\TestCases\TestCase {
    }
}
