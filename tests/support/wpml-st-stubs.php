<?php
/**
 * WPML String Translation's two string tables (issue #1053), for tests that
 * run without WPML loaded. The columns follow WPML String Translation's
 * documented schema: {prefix}icl_strings (one row per registered string,
 * its domain in `context`) and {prefix}icl_string_translations (one row per
 * string and language, status 10 when complete).
 *
 * DDL commits implicitly, so the tables are created in wpSetUpBeforeClass()
 * and dropped in wpTearDownAfterClass().
 */

if ( ! function_exists( 'wpmcp_test_create_wpml_st_tables' ) ) {
	function wpmcp_test_create_wpml_st_tables(): void {
		global $wpdb;

		wpmcp_test_drop_wpml_st_tables();
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}icl_strings (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			language varchar(7) NOT NULL,
			context varchar(160) NOT NULL,
			name varchar(160) NOT NULL,
			value longtext NOT NULL,
			string_package_id bigint(20) unsigned NULL,
			location bigint(20) unsigned NULL,
			wrap_tag varchar(16) NOT NULL DEFAULT '',
			type varchar(40) NOT NULL DEFAULT 'LINE',
			title varchar(160) NULL,
			status tinyint(4) NOT NULL DEFAULT 0,
			gettext_context text NOT NULL,
			domain_name_context_md5 varchar(32) NOT NULL DEFAULT '',
			translation_priority varchar(160) NOT NULL DEFAULT '',
			word_count int(10) unsigned NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uc_domain_name_context_md5 (domain_name_context_md5)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}icl_string_translations (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			string_id bigint(20) unsigned NOT NULL,
			language varchar(10) NOT NULL,
			status tinyint(4) NOT NULL,
			value longtext NULL,
			mo_string longtext NULL,
			translator_id bigint(20) unsigned DEFAULT NULL,
			translation_service varchar(16) NOT NULL DEFAULT '',
			batch_id int(11) NOT NULL DEFAULT 0,
			translation_date timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY string_language (string_id, language)
		) {$charset}" );
		// phpcs:enable
	}

	function wpmcp_test_drop_wpml_st_tables(): void {
		global $wpdb;
		foreach ( [ 'icl_strings', 'icl_string_translations' ] as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixture table name.
		}
	}

	/** Insert one icl_strings row the way WPML registers a string; its id. */
	function wpmcp_test_wpml_string( string $domain, string $name, string $value, string $language = 'en' ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'icl_strings', [
			'language'                => $language,
			'context'                 => $domain,
			'name'                    => $name,
			'value'                   => $value,
			'gettext_context'         => '',
			'domain_name_context_md5' => md5( $domain . $name ),
			'status'                  => 0,
		] );
		return (int) $wpdb->insert_id;
	}

	function wpmcp_test_wpml_translation( int $string_id, string $language, string $value, int $status = 10 ): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'icl_string_translations', [
			'string_id' => $string_id,
			'language'  => $language,
			'status'    => $status,
			'value'     => $value,
		] );
	}
}
