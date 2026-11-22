<?php
/**
 * A stand-in for WP Activity Log's WSAL\Controllers\Alert::get_message()
 * (issue #949), for tests that run without the plugin loaded.
 *
 * It builds the message the way WP Activity Log 5.7.0 does in its default
 * (HTML) context: the alert message with every %Token% replaced by the meta
 * value in <strong>, or NULL when the meta has none, then each metadata
 * line and each link, joined with <br />. The free version's extra
 * "Premium" link and the security tip link are added as the plugin adds
 * them (classes/Controllers/class-alert.php, defaults.php).
 */

namespace WSAL\Controllers;

if ( ! class_exists( '\\WSAL\\Controllers\\Alert' ) ) {
	class Alert {
		/** alert id => [ message, [ label => token ], [ label => url token or url ] ] */
		private const ALERTS = [
			2000 => [
				'Created the post %PostTitle%.',
				[ 'Post ID' => '%PostID%', 'Post type' => '%PostType%', 'Post status' => '%PostStatus%' ],
				[ 'View the post in editor' => '%EditorLinkPost%' ],
			],
			1002 => [
				'Failed login attempt - wrong password.',
				[ 'Login URL' => '%LoginPageURL%' ],
				[ 'limit users failed login attempts with Melapress Login Security' => 'https://wordpress.org/plugins/melapress-login-security/' ],
			],
			5001 => [
				'Activated the plugin %PluginData->Name%.',
				[ 'Version' => '%PluginData->Version%', 'Install location' => '%PluginFile%' ],
				[ 'View plugin information' => '%PluginData->PluginRepoUrl%' ],
			],
			5002 => [
				'Deactivated the plugin %PluginData->Name%.',
				[ 'Version' => '%PluginData->Version%', 'Install location' => '%PluginFile%' ],
				[ 'View plugin information' => '%PluginData->PluginRepoUrl%' ],
			],
			1004 => [
				'Login blocked because other session(s) already exist for this user.',
				[ 'IP address' => '%ClientIP%' ],
				[ 'Get instant alerts for events like this with Premium' => 'https://melapress.com/wordpress-activity-log/pricing/?utm_source=plugin&utm_medium=wsal&utm_campaign=viewer-cta-1004' ],
			],
		];

		/**
		 * A %Meta% or %Meta->Prop% token's value, read the way
		 * Alert::get_meta_expression_value() reads it (WP Activity Log 5.7.0):
		 * an array key or an object property, NULL when there is none. A
		 * __PHP_Incomplete_Class has no readable property, so it reads NULL.
		 */
		private static function expression( string $token, $meta_data ): string {
			$parts = explode( '->', trim( $token, '%' ) );
			$meta  = $meta_data[ array_shift( $parts ) ] ?? null;
			foreach ( $parts as $part ) {
				if ( is_scalar( $meta ) || null === $meta ) {
					break;
				}
				$meta = is_array( $meta ) && array_key_exists( $part, $meta ) ? $meta[ $part ] : ( isset( $meta->$part ) ? $meta->$part : 'NULL' );
			}
			return null === $meta ? 'NULL' : ( is_scalar( $meta ) ? (string) $meta : 'NULL' );
		}

		public static function get_message( $meta_data, $message = null, $alert_id = 0, $occurrence_id = 0, $context = 'default' ) {
			unset( $message, $occurrence_id, $context );
			if ( ! isset( self::ALERTS[ $alert_id ] ) ) {
				return false;
			}
			list( $text, $metadata, $links ) = self::ALERTS[ $alert_id ];
			$value = static function ( string $token ) use ( $meta_data ): string {
				return '<strong>' . esc_html( self::expression( $token, $meta_data ) ) . '</strong>';
			};
			$parts = [ preg_replace_callback( '/%[A-Za-z]+(?:->[A-Za-z]+)*%/', static fn ( $m ) => $value( $m[0] ), $text ) ];
			foreach ( $metadata as $label => $token ) {
				$parts[] = $label . ': ' . $value( $token );
			}
			foreach ( $links as $label => $url ) {
				if ( '%' === substr( $url, 0, 1 ) ) {
					$url = self::expression( $url, $meta_data );
					if ( '' === $url || 'NULL' === $url ) {
						continue;
					}
				}
				$parts[] = '<strong><a  href="' . esc_url( $url ) . '"  target="_blank">' . $label . '</a></strong>';
			}
			return implode( '<br />', $parts );
		}
	}
}
