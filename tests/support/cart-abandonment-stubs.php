<?php
/**
 * The Cart Abandonment Recovery for WooCommerce tables (wordpress.org slug
 * woo-cart-abandonment-recovery), as Cartflows_Ca_Database creates them in
 * 2.2.x, for CartAbandonmentPackTest (issue #1096). The foreign keys the
 * plugin declares are left out: the tests insert the rows the plugin would.
 */

if ( ! function_exists( 'wpmcp_test_create_cart_abandonment_tables' ) ) {
	function wpmcp_test_create_cart_abandonment_tables(): void {
		global $wpdb;
		$p = $wpdb->prefix;
		$c = $wpdb->get_charset_collate();
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}cartflows_ca_cart_abandonment (
			id BIGINT(20) NOT NULL AUTO_INCREMENT,
			checkout_id BIGINT(20) UNSIGNED NOT NULL,
			email VARCHAR(100),
			cart_contents LONGTEXT,
			cart_total DECIMAL(10,2),
			session_id VARCHAR(60) NOT NULL,
			other_fields LONGTEXT,
			order_status ENUM( 'normal','abandoned','completed','lost') NOT NULL DEFAULT 'normal',
			unsubscribed boolean DEFAULT 0,
			coupon_code VARCHAR(50),
			time DATETIME DEFAULT NULL,
			PRIMARY KEY (id, session_id),
			UNIQUE KEY session_id_UNIQUE (session_id)
		) {$c}" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}cartflows_ca_email_templates (
			id BIGINT(20) NOT NULL AUTO_INCREMENT,
			template_name text NOT NULL,
			email_subject text NOT NULL,
			email_body mediumtext NOT NULL,
			is_activated tinyint(1) NOT NULL DEFAULT '0',
			frequency int(11) NOT NULL,
			frequency_unit ENUM( 'MINUTE','HOUR','DAY') NOT NULL DEFAULT 'MINUTE',
			PRIMARY KEY (id)
		) {$c}" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}cartflows_ca_email_history (
			id BIGINT(20) NOT NULL AUTO_INCREMENT,
			template_id BIGINT(20) NOT NULL,
			ca_session_id VARCHAR(60),
			coupon_code VARCHAR(50),
			scheduled_time DATETIME,
			email_sent boolean DEFAULT 0,
			PRIMARY KEY (id)
		) {$c}" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}cartflows_ca_email_templates_meta (
			id BIGINT(20) NOT NULL AUTO_INCREMENT,
			email_template_id BIGINT(20) NOT NULL,
			meta_key varchar(255) NOT NULL,
			meta_value longtext NOT NULL,
			PRIMARY KEY (id)
		) {$c}" );
	}
}

if ( ! function_exists( 'wpmcp_test_drop_cart_abandonment_tables' ) ) {
	function wpmcp_test_drop_cart_abandonment_tables(): void {
		global $wpdb;
		foreach ( [ 'cartflows_ca_email_history', 'cartflows_ca_email_templates_meta', 'cartflows_ca_email_templates', 'cartflows_ca_cart_abandonment' ] as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
		}
	}
}
