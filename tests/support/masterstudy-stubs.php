<?php
/**
 * Post types, capabilities and tables for the MasterStudy LMS ops (issue
 * #1033) on the plugin-data pair, for tests that run without MasterStudy LMS
 * loaded.
 *
 * Copied from MasterStudy LMS 3.7.60:
 *  - _core/includes/post_type/posts.php: stm-courses, stm-lessons,
 *    stm-quizzes and stm-questions on capability_type stm_lms_post;
 *  - _core/libraries/db/tables/curriculum_sections.table.php,
 *    curriculum_materials.table.php and user_courses.table.php: the DDL.
 *
 * DDL commits implicitly, so the tables are created in wpSetUpBeforeClass()
 * and dropped in wpTearDownAfterClass().
 */

if ( ! function_exists( 'wpmcp_test_register_masterstudy_types' ) ) {
	function wpmcp_test_register_masterstudy_types(): void {
		foreach ( [ 'stm-courses', 'stm-lessons', 'stm-quizzes', 'stm-questions' ] as $type ) {
			register_post_type( $type, [
				'public'          => 'stm-questions' !== $type,
				'capability_type' => 'stm_lms_post',
				'map_meta_cap'    => true,
				'supports'        => [ 'title', 'editor', 'excerpt' ],
			] );
		}
	}

	function wpmcp_test_unregister_masterstudy_types(): void {
		foreach ( [ 'stm-courses', 'stm-lessons', 'stm-quizzes', 'stm-questions' ] as $type ) {
			if ( post_type_exists( $type ) ) {
				unregister_post_type( $type );
			}
		}
	}

	/** The primitive capabilities of the stm_lms_post capability type. */
	function wpmcp_test_masterstudy_admin_caps(): array {
		$caps = [];
		foreach ( (array) get_post_type_object( 'stm-courses' )->cap as $meta => $cap ) {
			if ( ! in_array( $meta, [ 'read_post', 'edit_post', 'delete_post', 'read' ], true ) ) {
				$caps[] = $cap;
			}
		}
		return array_values( array_unique( $caps ) );
	}

	function wpmcp_test_create_masterstudy_tables(): void {
		global $wpdb;

		wpmcp_test_drop_masterstudy_tables();
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}stm_lms_curriculum_sections (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			title varchar(255) NOT NULL DEFAULT '',
			course_id bigint NOT NULL,
			`order` SMALLINT NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY ix_curriculum_sections_course (course_id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}stm_lms_curriculum_materials (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			post_id bigint NOT NULL,
			post_type varchar(50) NOT NULL DEFAULT '',
			section_id mediumint(9) NOT NULL DEFAULT 0,
			`order` SMALLINT NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY ix_curriculum_materials_section_order (section_id, `order`),
			KEY ix_curriculum_materials_post (post_id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}stm_lms_user_courses (
			user_course_id mediumint(9) NOT NULL AUTO_INCREMENT,
			user_id bigint NOT NULL,
			course_id int(11) NOT NULL,
			current_lesson_id int(11),
			progress_percent mediumint(9) NOT NULL,
			final_grade tinyint(4) DEFAULT NULL,
			status varchar(45) NOT NULL DEFAULT '',
			lng_code varchar(45) NOT NULL DEFAULT 'en_US',
			is_gradable tinyint(1) NOT NULL DEFAULT 0,
			subscription_id int(11),
			enterprise_id int(11) DEFAULT 0,
			bundle_id int(11) DEFAULT 0,
			start_time INT NOT NULL,
			end_time INT DEFAULT 0,
			for_points VARCHAR(255) DEFAULT '',
			PRIMARY KEY (user_course_id)
		) {$charset}" );
		// phpcs:enable
	}

	function wpmcp_test_drop_masterstudy_tables(): void {
		global $wpdb;
		foreach ( [ 'stm_lms_curriculum_sections', 'stm_lms_curriculum_materials', 'stm_lms_user_courses' ] as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixture table name.
		}
	}
}
