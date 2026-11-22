<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/masterstudy-stubs.php';

/**
 * MasterStudy LMS course structure and enrollments (issue #1033): paid-tier
 * ops on the plugin-data dispatcher pair, the same op names as the other LMS
 * adapters.
 *
 * MasterStudy LMS is not loaded in the shared test core. Presence is driven
 * through wpmcp_masterstudy_active, and the storage is seeded the way
 * MasterStudy LMS 3.7.60 keeps it (tests/support/masterstudy-stubs.php): an
 * stm-courses post; its sections are rows of
 * {prefix}stm_lms_curriculum_sections (course_id, title, 1-based order), not
 * posts; stm-lessons and stm-quizzes posts are placed in a section by rows of
 * {prefix}stm_lms_curriculum_materials (post_id, post_type, section_id,
 * order); a quiz lists its stm-questions in its `questions` meta (comma
 * separated ids), each with its type in `type`; an enrollment is a row of
 * {prefix}stm_lms_user_courses with a unix start_time.
 */
class MasterStudyLmsPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'masterstudy-list-courses'     => 'read',
        'masterstudy-get-course'       => 'read',
        'masterstudy-list-enrollments' => 'read',
        'masterstudy-create-course'    => 'write',
        'masterstudy-add-section'      => 'write',
        'masterstudy-add-lesson'       => 'write',
        'masterstudy-add-quiz'         => 'write',
        'masterstudy-update-item'      => 'write',
        'masterstudy-move-item'        => 'write',
    ];

    /** The other plugin-data plugins, off so only this LMS decides whether the pair is available. */
    private const OTHER_FILTERS = [ 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active', 'wpmcp_buddypress_active', 'wpmcp_action_scheduler_active', 'wpmcp_tutor_active', 'wpmcp_lifterlms_active', 'wpmcp_learndash_active', 'wpmcp_learnpress_active', 'wpmcp_sensei_active' ];

    private int $admin;

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_masterstudy_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_masterstudy_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wpmcp_test_register_masterstudy_types();
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        $user        = get_userdata($this->admin);
        foreach (wpmcp_test_masterstudy_admin_caps() as $cap) {
            $user->add_cap($cap);
        }
        wp_set_current_user($this->admin);
        add_filter('wpmcp_masterstudy_active', '__return_true');
        foreach (self::OTHER_FILTERS as $filter) {
            add_filter($filter, '__return_false');
        }
    }

    protected function tearDown(): void
    {
        foreach (self::OTHER_FILTERS as $filter) {
            remove_all_filters($filter);
        }
        remove_all_filters('wpmcp_masterstudy_active');
        wpmcp_test_unregister_masterstudy_types();
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args, ?string $session = null): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if (null !== $session) {
            $call['session_id'] = $session;
        }
        return (new Plugin_Data_Integration())->handle_write($call);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function post(string $type, string $title, array $meta = []): int
    {
        $id = self::factory()->post->create([ 'post_type' => $type, 'post_title' => $title, 'post_status' => 'publish' ]);
        foreach ($meta as $key => $value) {
            update_post_meta($id, $key, $value);
        }
        return $id;
    }

    private function section(int $course, string $name, int $order): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'stm_lms_curriculum_sections', [ 'title' => $name, 'course_id' => $course, 'order' => $order ]);
        return (int) $wpdb->insert_id;
    }

    private function place(int $section, int $item, int $order): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'stm_lms_curriculum_materials', [ 'section_id' => $section, 'post_id' => $item, 'order' => $order, 'post_type' => get_post_type($item) ]);
    }

    /** section id => [order, title] of a course, by id. */
    private function sections_of(int $course): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, `order`, title FROM {$wpdb->prefix}stm_lms_curriculum_sections WHERE course_id = %d ORDER BY id", $course), ARRAY_A);
        $out  = [];
        foreach ($rows as $row) {
            $out[ (int) $row['id'] ] = [ (int) $row['order'], (string) $row['title'] ];
        }
        return $out;
    }

    /** post id => [section_id, order] for the items of the given sections. */
    private function items_in(array $sections): array
    {
        global $wpdb;
        $in   = implode(',', array_map('intval', $sections));
        $rows = $wpdb->get_results("SELECT section_id, post_id, `order` FROM {$wpdb->prefix}stm_lms_curriculum_materials WHERE section_id IN ({$in}) ORDER BY post_id", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
        $out  = [];
        foreach ($rows as $row) {
            $out[ (int) $row['post_id'] ] = [ (int) $row['section_id'], (int) $row['order'] ];
        }
        return $out;
    }

    /** [course, s1, s2, l1, l2, quiz, l3, q1, q2]: s1 holds l1, l2 and the quiz; s2 holds l3. */
    private function seed_course(): array
    {
        global $wpdb;
        $course = $this->post('stm-courses', 'Bread Baking');
        $s2     = $this->section($course, 'Shaping', 2);
        $s1     = $this->section($course, 'Starters', 1);
        $l2     = $this->post('stm-lessons', 'Feeding');
        $l1     = $this->post('stm-lessons', 'Capturing yeast');
        $quiz   = $this->post('stm-quizzes', 'Starter check');
        $l3     = $this->post('stm-lessons', 'Boules');
        $this->place($s1, $l2, 2);
        $this->place($s1, $l1, 1);
        $this->place($s1, $quiz, 3);
        $this->place($s2, $l3, 1);
        $q2 = $this->post('stm-questions', 'How often to feed?', [ 'type' => 'single_choice' ]);
        $q1 = $this->post('stm-questions', 'What is a starter?', [ 'type' => 'true_false' ]);
        update_post_meta($quiz, 'questions', $q1 . ',' . $q2);
        return [ $course, $s1, $s2, $l1, $l2, $quiz, $l3, $q1, $q2 ];
    }

    private function enrollment(int $user, int $course, string $status, string $start): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'stm_lms_user_courses', [ 'user_id' => $user, 'course_id' => $course, 'progress_percent' => 0, 'status' => $status, 'start_time' => (int) strtotime($start . ' UTC') ]);
    }

    public function test_the_catalog_lists_every_masterstudy_op_with_its_mode(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
        }
        $this->assertSame('list_users', $ops['masterstudy-list-enrollments']['capability']);
    }

    public function test_while_masterstudy_is_inactive_its_ops_answer_masterstudy_inactive(): void
    {
        remove_all_filters('wpmcp_masterstudy_active');
        add_filter('wpmcp_masterstudy_active', '__return_false');
        remove_all_filters('wpmcp_buddypress_active');
        add_filter('wpmcp_buddypress_active', '__return_true');
        $out = $this->read('masterstudy-list-courses');
        $this->assertSame('masterstudy_inactive', $out['error']['code'] ?? null);
    }

    public function test_get_course_reads_sections_and_items_from_the_masterstudy_tables_in_order(): void
    {
        [$course, $s1, $s2, $l1, $l2, $quiz, $l3, $q1, $q2] = $this->seed_course();

        $out = $this->ok($this->read('masterstudy-get-course', [ 'id' => $course ]))['result'];
        $this->assertSame([ $s1, $s2 ], array_column($out['sections'], 'id'));
        $this->assertSame([ 'section', 'section' ], array_column($out['sections'], 'kind'));
        $this->assertSame([ 'Starters', 'Shaping' ], array_column($out['sections'], 'title'));
        $this->assertSame([ 1, 2 ], array_column($out['sections'], 'order'));
        $this->assertSame([ $l1, $l2, $quiz ], array_column($out['sections'][0]['items'], 'id'));
        $this->assertSame([ 'lesson', 'lesson', 'quiz' ], array_column($out['sections'][0]['items'], 'kind'));
        $this->assertSame([ 1, 2, 3 ], array_column($out['sections'][0]['items'], 'order'));
        $this->assertSame([ $l3 ], array_column($out['sections'][1]['items'], 'id'));

        $questions = $out['sections'][0]['items'][2]['questions'];
        $this->assertSame([ $q1, $q2 ], array_column($questions, 'id'));
        $this->assertSame([ 'true_false', 'single_choice' ], array_column($questions, 'type'));
        $this->assertSame([ null, null ], array_column($questions, 'points'), 'MasterStudy LMS keeps no points per question');
        $this->assertSame([ 'sections' => 2, 'lessons' => 3, 'quizzes' => 1, 'assignments' => 0, 'questions' => 2 ], $out['counts']);

        $listed = array_column($this->ok($this->read('masterstudy-list-courses'))['result']['courses'], null, 'id');
        $this->assertSame([ 2, 3, 1 ], [ $listed[ $course ]['sections'], $listed[ $course ]['lessons'], $listed[ $course ]['quizzes'] ]);
    }

    public function test_a_draft_lesson_of_another_author_is_not_listed_to_a_contributor(): void
    {
        [$course, $s1, , $l1] = $this->seed_course();
        wp_update_post([ 'ID' => $l1, 'post_status' => 'draft' ]);
        wp_update_post([ 'ID' => $course, 'post_author' => $this->admin ]);
        $reader = self::factory()->user->create([ 'role' => 'subscriber' ]);
        wp_set_current_user($reader);
        get_userdata($reader)->add_cap('read');

        $out = (new \WPMCP\Integrations\LMS_MasterStudy())->operations()['masterstudy-get-course']['handler']([ 'id' => $course, 'questions' => false ]);
        $this->assertNotContains($l1, array_column($out['sections'][0]['items'], 'id'));
        $this->assertSame($s1, $out['sections'][0]['id']);
    }

    public function test_list_enrollments_takes_the_latest_row_per_student_and_masks_it(): void
    {
        [$course] = $this->seed_course();
        $alice    = self::factory()->user->create([ 'display_name' => 'Alice', 'user_email' => 'alice@example.com' ]);
        $bob      = self::factory()->user->create([ 'display_name' => 'Bob', 'user_email' => 'bob@example.com' ]);
        $carol    = self::factory()->user->create([ 'display_name' => 'Carol', 'user_email' => 'carol@example.com' ]);
        $this->enrollment($alice, $course, 'enrolled', '2026-09-01 10:00:00');
        $this->enrollment($bob, $course, 'enrolled', '2026-09-02 10:00:00');
        $this->enrollment($bob, $course, 'cancelled', '2026-09-05 10:00:00');
        $this->enrollment($carol, $course, 'completed', '2026-09-03 10:00:00');

        $out  = $this->ok($this->read('masterstudy-list-enrollments', [ 'course_id' => $course ]))['result'];
        $rows = array_column($out['enrollments'], null, 'user_id');
        $this->assertSame(3, $out['total']);
        $this->assertSame('enrolled', $rows[ $alice ]['status']);
        $this->assertSame('cancelled', $rows[ $bob ]['status']);
        $this->assertSame('enrolled', $rows[ $carol ]['status'], 'a student who completed the course is still enrolled');
        $this->assertSame('2026-09-01 10:00:00', $rows[ $alice ]['enrolled_at']);
        $this->assertSame('b***@example.com', $rows[ $bob ]['email'], 'a list masks the email');
        $this->assertTrue($out['masked']);

        $only = $this->ok($this->read('masterstudy-list-enrollments', [ 'course_id' => $course, 'status' => 'enrolled' ]))['result'];
        $this->assertEqualsCanonicalizing([ $alice, $carol ], array_column($only['enrollments'], 'user_id'));

        $listed = array_column($this->ok($this->read('masterstudy-list-courses'))['result']['courses'], null, 'id');
        $this->assertSame(2, $listed[ $course ]['enrolled']);

        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertSame('operation_denied', $this->read('masterstudy-list-enrollments', [ 'course_id' => $course ])['error']['code'] ?? null);
    }

    public function test_building_a_course_writes_masterstudy_rows_and_rollback_session_undoes_all_of_it(): void
    {
        global $wpdb;
        $session = wp_generate_uuid4();
        $course  = $this->ok($this->write('masterstudy-create-course', [ 'title' => 'Knife Skills' ], $session))['result']['item']['id'];
        $section = $this->ok($this->write('masterstudy-add-section', [ 'course_id' => $course, 'title' => 'Grips' ], $session))['result']['item'];
        $lesson  = $this->ok($this->write('masterstudy-add-lesson', [ 'parent_id' => $section['id'], 'title' => 'Pinch grip' ], $session))['result']['item']['id'];
        $quiz    = $this->ok($this->write('masterstudy-add-quiz', [ 'parent_id' => $section['id'], 'title' => 'Grip quiz', 'position' => 0 ], $session))['result']['item']['id'];

        $this->assertSame('stm-courses', get_post_type($course));
        $this->assertSame('section', $section['kind']);
        $this->assertSame([ $section['id'] => [ 1, 'Grips' ] ], $this->sections_of($course));
        $this->assertSame('stm-lessons', get_post_type($lesson));
        $this->assertSame('stm-quizzes', get_post_type($quiz));
        $this->assertEquals([ $lesson => [ $section['id'], 2 ], $quiz => [ $section['id'], 1 ] ], $this->items_in([ $section['id'] ]));
        $this->assertSame('stm-quizzes', $wpdb->get_var($wpdb->prepare("SELECT post_type FROM {$wpdb->prefix}stm_lms_curriculum_materials WHERE post_id = %d", $quiz)));

        $tree = $this->ok($this->read('masterstudy-get-course', [ 'id' => $course ]))['result'];
        $this->assertSame([ $quiz, $lesson ], array_column($tree['sections'][0]['items'], 'id'));

        Rollback_Service::restore_session($session);
        foreach ([ $course, $lesson, $quiz ] as $id) {
            $this->assertSame('trash', get_post_status($id), "post {$id}");
        }
        $this->assertSame([], $this->sections_of($course), 'the section row is gone');
        $this->assertSame([], $this->items_in([ $section['id'] ]), 'the item rows are gone');
    }

    public function test_add_section_at_a_position_renumbers_the_others_and_its_session_undoes_that(): void
    {
        [$course, $s1, $s2] = $this->seed_course();
        $out                = $this->ok($this->write('masterstudy-add-section', [ 'course_id' => $course, 'title' => 'Tools', 'position' => 0 ]));
        $new                = $out['result']['item']['id'];
        $this->assertTrue($out['recoverable']);
        $this->assertSame([ $s2 => [ 3, 'Shaping' ], $s1 => [ 2, 'Starters' ], $new => [ 1, 'Tools' ] ], $this->sections_of($course));

        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame([ $s2 => [ 2, 'Shaping' ], $s1 => [ 1, 'Starters' ] ], $this->sections_of($course));
    }

    public function test_add_lesson_refuses_a_parent_that_is_not_a_masterstudy_section(): void
    {
        [$course] = $this->seed_course();
        $out      = $this->write('masterstudy-add-lesson', [ 'parent_id' => 999999, 'title' => 'Orphan' ]);
        $this->assertSame('invalid_parent', $out['error']['code'] ?? null);
        $out = $this->write('masterstudy-add-section', [ 'course_id' => $this->post('post', 'Not a course'), 'title' => 'X' ]);
        $this->assertSame('course_not_found', $out['error']['code'] ?? null);
        $this->assertCount(2, $this->sections_of($course));
    }

    public function test_an_author_cannot_add_to_a_course_they_cannot_edit(): void
    {
        [$course, $s1] = $this->seed_course();
        $author        = self::factory()->user->create([ 'role' => 'author' ]);
        foreach ([ 'edit_stm_lms_posts', 'publish_stm_lms_posts' ] as $cap) {
            get_userdata($author)->add_cap($cap);
        }
        wp_set_current_user($author);
        $this->assertSame('operation_denied', $this->write('masterstudy-add-lesson', [ 'parent_id' => $s1, 'title' => 'Sneaky' ])['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->write('masterstudy-update-item', [ 'section_id' => $s1, 'title' => 'Mine' ])['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->write('masterstudy-move-item', [ 'section_id' => $s1, 'position' => 1 ])['error']['code'] ?? null);
        $this->assertSame('Starters', $this->sections_of($course)[ $s1 ][1]);
    }

    public function test_move_item_moves_a_lesson_between_sections_and_rollback_puts_both_back(): void
    {
        [$course, $s1, $s2, $l1, $l2, $quiz, $l3] = $this->seed_course();

        $out = $this->ok($this->write('masterstudy-move-item', [ 'id' => $l2, 'parent_id' => $s2, 'position' => 0 ]));
        $this->assertEquals([ $l1 => [ $s1, 1 ], $l2 => [ $s2, 1 ], $quiz => [ $s1, 2 ], $l3 => [ $s2, 2 ] ], $this->items_in([ $s1, $s2 ]));

        $tree = $this->ok($this->read('masterstudy-get-course', [ 'id' => $course ]))['result'];
        $this->assertSame([ $l1, $quiz ], array_column($tree['sections'][0]['items'], 'id'));
        $this->assertSame([ $l2, $l3 ], array_column($tree['sections'][1]['items'], 'id'));

        Rollback_Service::restore_session($out['session_id']);
        $this->assertEquals([ $l1 => [ $s1, 1 ], $l2 => [ $s1, 2 ], $quiz => [ $s1, 3 ], $l3 => [ $s2, 1 ] ], $this->items_in([ $s1, $s2 ]));
    }

    public function test_move_item_refuses_a_section_of_another_course(): void
    {
        [, , , , $l2] = $this->seed_course();
        $other        = $this->post('stm-courses', 'Other');
        $foreign      = $this->section($other, 'Elsewhere', 1);
        $out          = $this->write('masterstudy-move-item', [ 'id' => $l2, 'parent_id' => $foreign, 'position' => 0 ]);
        $this->assertSame('cross_course_move', $out['error']['code'] ?? null);
    }

    public function test_move_item_reorders_sections_and_rollback_restores_the_order(): void
    {
        [$course, $s1, $s2] = $this->seed_course();
        $out                = $this->ok($this->write('masterstudy-move-item', [ 'section_id' => $s2, 'position' => 0 ]));
        $this->assertSame([ $s2 => [ 1, 'Shaping' ], $s1 => [ 2, 'Starters' ] ], $this->sections_of($course));
        $this->assertSame([ $s2, $s1 ], $out['result']['order']);

        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame([ $s2 => [ 2, 'Shaping' ], $s1 => [ 1, 'Starters' ] ], $this->sections_of($course));
    }

    public function test_update_item_renames_a_section_and_rollback_operation_restores_it(): void
    {
        [$course, $s1, $s2] = $this->seed_course();
        $out                = $this->ok($this->write('masterstudy-update-item', [ 'section_id' => $s1, 'title' => 'Wild yeast' ]));
        $this->assertSame('Wild yeast', $this->sections_of($course)[ $s1 ][1]);
        $this->assertSame('Wild yeast', $out['result']['item']['title']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame([ $s2 => [ 2, 'Shaping' ], $s1 => [ 1, 'Starters' ] ], $this->sections_of($course));

        $this->assertSame('invalid_argument', $this->write('masterstudy-update-item', [ 'section_id' => $s1, 'status' => 'draft' ])['error']['code'] ?? null);
        $this->assertSame('invalid_argument', $this->write('masterstudy-update-item', [ 'section_id' => $s1, 'content' => 'A section has no content' ])['error']['code'] ?? null);
        $this->assertSame('invalid_argument', $this->write('masterstudy-add-section', [ 'course_id' => $course, 'title' => 'X', 'content' => 'No' ])['error']['code'] ?? null);
        $this->assertSame('invalid_argument', $this->write('masterstudy-update-item', [ 'section_id' => $s1, 'id' => $course, 'title' => 'Both' ])['error']['code'] ?? null);
        $this->assertSame('item_not_found', $this->write('masterstudy-update-item', [ 'section_id' => 999999, 'title' => 'Ghost' ])['error']['code'] ?? null);
    }

    public function test_update_item_edits_a_lesson_under_a_post_snapshot(): void
    {
        [, , , $l1] = $this->seed_course();
        $out        = $this->ok($this->write('masterstudy-update-item', [ 'id' => $l1, 'title' => 'Catching yeast' ]));
        $this->assertSame('Catching yeast', get_the_title($l1));
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('Capturing yeast', get_the_title($l1));
    }

    public function test_a_lesson_in_no_masterstudy_section_is_not_an_item(): void
    {
        $stray = $this->post('stm-lessons', 'Stray');
        $out   = $this->write('masterstudy-update-item', [ 'id' => $stray, 'title' => 'Claimed' ]);
        $this->assertSame('item_not_found', $out['error']['code'] ?? null);
    }

    public function test_section_item_rows_are_keyed_by_section_and_restore_needs_edit_on_the_course(): void
    {
        global $wpdb;
        [$course, $s1, $s2, $l1, $l2, $quiz, $l3] = $this->seed_course();
        $key      = Plugin_Table_Rows_Snapshot::key('masterstudy_materials', 'curriculum_materials', [ $s1 ]);
        $snapshot = Snapshot::capture(Plugin_Table_Rows_Snapshot::TYPE, $key);
        $this->assertCount(3, $snapshot['data']['rows']);
        $this->assertNull(Plugin_Table_Rows_Snapshot::table('masterstudy_materials', 'user_courses'), 'only the curriculum materials table');

        $wpdb->delete($wpdb->prefix . 'stm_lms_curriculum_materials', [ 'section_id' => $s1 ]);
        Rollback_Service::apply_snapshot($snapshot);
        $this->assertEquals([ $l1 => [ $s1, 1 ], $l2 => [ $s1, 2 ], $quiz => [ $s1, 3 ], $l3 => [ $s2, 1 ] ], $this->items_in([ $s1, $s2 ]));

        wp_set_current_user(self::factory()->user->create([ 'role' => 'subscriber' ]));
        $this->expectException(\WPMCP\Safety\Mutation_Failed::class);
        Plugin_Table_Rows_Snapshot::restore($snapshot);
        unset($course);
    }
}
