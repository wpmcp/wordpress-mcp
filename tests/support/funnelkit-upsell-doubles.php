<?php
/**
 * Stand-ins for Funnel Builder Pro's one-click upsell routes and the
 * WFOCU_Common methods the upsell offer writes use (issues #1021, #500),
 * for the stub-backed suite, where Funnel Builder Pro is not installed.
 * Call wpmcp_test_funnelkit_upsell_doubles() just before
 * wpmcp_test_funnelkit_rest_doubles() in setUp(), and
 * wpmcp_test_funnelkit_upsell_reset() in tearDown().
 *
 * Each answers at the path and method Funnel Builder Pro 3.16 registers and
 * writes the storage it writes, read from its source:
 *  - POST funnel-upsell/{step_id}/offers (modules/one-click-upsells/
 *    compatibilities/funnel-builder/class-wffn-rest-upsell-api-endpoint.php
 *    wfocu_add_offer()): JSON body with title and step_type; a published
 *    wfocu_offer post carrying _funnel_id (the upsell step) and _offer_type,
 *    an empty _wfocu_setting, and an entry (type, url, slug, name, state,
 *    id) appended to the upsell step's _funnel_steps;
 *  - DELETE funnel-upsell/{step_id}/offers (wfocu_remove_offer(), step_id
 *    is the offer): the offer post deleted for good and its entry taken out
 *    of _funnel_steps;
 *  - POST funnel-offer/save-state/{step_id} (free admin/rest-api/
 *    class-wffn-rest-funnel-modules.php save_state() and
 *    update_offer_meta_in_upsell()): status publishes or drafts the post and
 *    sets the entry's state, title renames the post and the entry.
 *
 * WFOCU_Common stands in for the upsell module's class with the methods the
 * product write calls, as modules/one-click-upsells/includes/
 * class-wfocu-common.php defines them. is_add_on_exist() answers from
 * $GLOBALS['wpmcp_test_wfocu_addons'] (the class names the real one checks
 * for, without the WFOCU_ prefix).
 *
 * They follow the source closely enough to exercise the ops; they are not
 * FunnelKit.
 */

if ( ! class_exists( 'WFOCU_Common' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, PEAR.NamingConventions.ValidClassName.Invalid -- mirrors the upsell module's class.
	class WFOCU_Common {
		public static function get_offer_post_type_slug() {
			return 'wfocu_offer';
		}

		public static function get_offer( $offer_id ) {
			return get_post_meta( $offer_id, '_wfocu_setting', true );
		}

		public static function update_offer( $offer_id, $data, $funnel_id = 0 ) {
			unset( $funnel_id );
			update_post_meta( $offer_id, '_wfocu_setting', $data );
		}

		public static function get_product_id_hash( $funnel_id, $offer_id, $product_id ) {
			return md5( ( $funnel_id * $offer_id * $product_id ) . microtime() );
		}

		public static function update_funnel_time( $funnel_id ) {
			wp_update_post( [ 'ID' => $funnel_id ] );
		}

		public static function is_add_on_exist( $add_on = 'MultiProduct' ) {
			return in_array( $add_on, (array) ( $GLOBALS['wpmcp_test_wfocu_addons'] ?? [] ), true );
		}
	}
}

if ( ! function_exists( 'wpmcp_test_funnelkit_upsell_doubles' ) ) {
	/** Add the upsell routes to the next REST server; call before wpmcp_test_funnelkit_rest_doubles(). */
	function wpmcp_test_funnelkit_upsell_doubles(): void {
		add_action( 'rest_api_init', 'wpmcp_test_funnelkit_register_upsell_routes' );
	}

	function wpmcp_test_funnelkit_upsell_reset(): void {
		remove_action( 'rest_api_init', 'wpmcp_test_funnelkit_register_upsell_routes' );
		unset( $GLOBALS['wpmcp_test_wfocu_addons'] );
	}

	/** An upsell step's stored offer list. */
	function wpmcp_test_funnelkit_offer_list( int $upsell_id ): array {
		$list = get_post_meta( $upsell_id, '_funnel_steps', true );
		return is_array( $list ) ? array_values( $list ) : [];
	}

	function wpmcp_test_funnelkit_register_upsell_routes(): void {
		$ns   = 'funnelkit-app';
		$perm = static fn (): bool => current_user_can( 'manage_options' );

		register_rest_route( $ns, '/funnel-upsell/(?P<step_id>[\d]+)/offers', [
			[
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => static function ( WP_REST_Request $request ) {
					$upsell_id = (int) $request['step_id'];
					$body      = (array) json_decode( $request->get_body(), true );
					$title     = (string) ( $body['title'] ?? 'New Sub Step' );
					$type      = '' !== (string) ( $body['step_type'] ?? '' ) ? (string) $body['step_type'] : 'upsell';
					$offer_id  = wp_insert_post( [ 'post_type' => 'wfocu_offer', 'post_title' => $title, 'post_status' => 'publish' ] );
					$list      = wpmcp_test_funnelkit_offer_list( $upsell_id );
					$list[]    = [
						'type'  => $type,
						'url'   => get_permalink( $offer_id ),
						'slug'  => get_post( $offer_id )->post_name,
						'name'  => $title,
						'state' => 1,
						'id'    => $offer_id,
					];
					update_post_meta( $offer_id, '_funnel_id', $upsell_id );
					update_post_meta( $offer_id, '_offer_type', $type );
					update_post_meta( $upsell_id, '_funnel_steps', $list );
					$setting                 = new stdClass();
					$setting->products       = new stdClass();
					$setting->fields         = new stdClass();
					$setting->template       = '';
					$setting->template_group = '';
					$setting->settings       = new stdClass();
					update_post_meta( $offer_id, '_wfocu_setting', $setting );
					update_post_meta( $upsell_id, '_wfocu_is_rules_saved', 'yes' );
					WFOCU_Common::update_funnel_time( $upsell_id );
					return [ 'status' => true, 'msg' => 'Offer Added Successfully', 'data' => [ 'id' => $offer_id ] ];
				},
			],
			[
				'methods'             => 'DELETE',
				'permission_callback' => $perm,
				'callback'            => static function ( WP_REST_Request $request ) {
					$offer_id  = (int) $request['step_id'];
					$body      = (array) json_decode( $request->get_body(), true );
					$upsell_id = (int) ( $body['upsell_id'] ?? get_post_meta( $offer_id, '_funnel_id', true ) );
					$list      = wpmcp_test_funnelkit_offer_list( $upsell_id );
					wp_delete_post( $offer_id, true );
					WFOCU_Common::update_funnel_time( $upsell_id );
					update_post_meta( $upsell_id, '_funnel_steps', array_values( array_filter( $list, static fn ( $entry ): bool => (int) $entry['id'] !== $offer_id ) ) );
					return [ 'status' => true, 'msg' => 'Deleted' ];
				},
			],
		] );

		register_rest_route( $ns, '/funnel-offer/save-state/(?P<step_id>[\d]+)', [
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => static function ( WP_REST_Request $request ) {
				$id   = (int) $request['step_id'];
				$body = (array) json_decode( $request->get_body(), true );
				if ( isset( $body['status'] ) ) {
					$body['status'] = wp_validate_boolean( $body['status'] );
					wp_update_post( [ 'ID' => $id, 'post_status' => $body['status'] ? 'publish' : 'draft' ] );
				}
				if ( isset( $body['title'] ) ) {
					wp_update_post( [ 'ID' => $id, 'post_title' => (string) $body['title'], 'post_name' => sanitize_title( (string) $body['title'] ) ] );
				}
				$upsell_id = (int) get_post_meta( $id, '_funnel_id', true );
				$list      = wpmcp_test_funnelkit_offer_list( $upsell_id );
				foreach ( $list as $i => $entry ) {
					if ( (int) $entry['id'] !== $id ) {
						continue;
					}
					if ( isset( $body['status'] ) ) {
						$entry['state'] = $body['status'] ? '1' : '0';
					}
					$entry['name'] = $body['title'] ?? $entry['name'];
					$entry['state'] = in_array( $entry['state'], [ '1', 'true', true, 1 ], true ) ? '1' : '0';
					wp_update_post( [ 'ID' => $id, 'post_status' => '1' === $entry['state'] ? 'publish' : 'draft' ] );
					unset( $entry['url'] );
					$list[ $i ] = $entry;
				}
				update_post_meta( $upsell_id, '_funnel_steps', $list );
				return [ 'success' => true, 'msg' => 'Status changed successfully' ];
			},
		] );
	}
}
