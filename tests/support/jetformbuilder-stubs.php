<?php
/**
 * Tables for the JetFormBuilder ops (issue #676) on the plugin-data pair,
 * for tests that run without JetFormBuilder loaded.
 *
 * Taken from JetFormBuilder 3.6.8.2 (wordpress.org): every table is named
 * {prefix}jet_fb_<table_name()> (Base_Db_Model::DB_TABLE_PREFIX), with the
 * columns of modules/form-record/models/record-model.php (records),
 * record-field-model.php (records_fields), record-action-result-model.php
 * (records_actions), record-error-model.php (records_errors) and
 * modules/gateways/db-models/payment-to-record.php (payments_to_records).
 *
 * DDL commits implicitly, so the tables are created in set_up_before_class()
 * and dropped in tear_down_after_class().
 */

if ( ! function_exists( 'wpmcp_test_create_jetformbuilder_tables' ) ) {
	function wpmcp_test_create_jetformbuilder_tables(): void {
		global $wpdb;

		wpmcp_test_drop_jetformbuilder_tables();
		$p = $wpdb->prefix . 'jet_fb_';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE {$p}records (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			form_id bigint(20) UNSIGNED NOT NULL,
			user_id bigint(20),
			from_content_id bigint(20) NOT NULL,
			from_content_type varchar(20) NOT NULL,
			status varchar(255),
			ip_address varchar(255),
			user_agent text,
			referrer text,
			submit_type varchar(20),
			is_viewed tinyint(1),
			created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP NULL,
			PRIMARY KEY (id),
			KEY form_id (form_id)
		)" );
		$wpdb->query( "CREATE TABLE {$p}records_fields (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			record_id bigint(20) NOT NULL,
			field_name varchar(100) NOT NULL,
			field_value longtext,
			field_type varchar(40) NOT NULL,
			field_attrs longtext,
			PRIMARY KEY (id),
			KEY record_id (record_id)
		)" );
		$wpdb->query( "CREATE TABLE {$p}records_actions (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			record_id bigint(20) NOT NULL,
			action_slug varchar(255) NOT NULL,
			action_id int(11) NOT NULL,
			action_name varchar(255) NULL DEFAULT NULL,
			on_event varchar(155) NULL DEFAULT 'DEFAULT.PROCESS',
			status varchar(255) NOT NULL,
			created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP NULL,
			PRIMARY KEY (id)
		)" );
		$wpdb->query( "CREATE TABLE {$p}records_errors (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			record_id bigint(20) NOT NULL,
			name varchar(100),
			message varchar(255),
			PRIMARY KEY (id)
		)" );
		$wpdb->query( "CREATE TABLE {$p}payments_to_records (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			payment_id bigint(20) NOT NULL,
			record_id bigint(20) NOT NULL,
			PRIMARY KEY (id)
		)" );
		// phpcs:enable
	}

	function wpmcp_test_drop_jetformbuilder_tables(): void {
		global $wpdb;
		foreach ( array( 'records_fields', 'records_actions', 'records_errors', 'payments_to_records', 'records' ) as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'jet_fb_' . $table ) );
		}
	}
}
