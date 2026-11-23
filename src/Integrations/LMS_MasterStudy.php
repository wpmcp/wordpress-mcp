<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * MasterStudy LMS course ops on the LMS pack (issue #1033), detected by
 * STM_LMS_VERSION (filterable through wpmcp_masterstudy_active), tier pro.
 *
 * Courses, lessons and quizzes are stm-courses, stm-lessons and stm-quizzes
 * posts, but the curriculum is not post based: sections and the placement of
 * each lesson and quiz are rows of MasterStudy's own tables, read and
 * written through MasterStudy_Curriculum_Store. Because a section is a row,
 * its id lives in another number space than the posts: the ops take it as
 * parent_id (add-lesson, add-quiz, move-item) or section_id (update-item and
 * move-item of a section), never as id. A section has only a title.
 *
 * Every curriculum write runs under a plugin_table_rows snapshot keyed by the
 * course (kind masterstudy_sections) or by the section (kind
 * masterstudy_materials), so the undo also removes the rows the write
 * inserted; created posts are recorded for rollback as in every LMS.
 * Enrollments are rows of {prefix}stm_lms_user_courses and are read only.
 */
final class LMS_MasterStudy extends LMS_Adapter
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    /** MasterStudy user course status => normalized status. '' is how older rows store an active enrollment. */
    private const STATUS_MAP = [ 'enrolled' => 'enrolled', '' => 'enrolled', 'completed' => 'enrolled', 'pending' => 'pending', 'cancelled' => 'cancelled', 'expired' => 'expired' ];

    private readonly MasterStudy_Curriculum_Store $store;

    public function __construct(?MasterStudy_Curriculum_Store $store = null)
    {
        global $wpdb;
        $this->store = $store ?? new MasterStudy_Curriculum_Store($wpdb);
    }

    public function slug(): string
    {
        return 'masterstudy';
    }

    public function label(): string
    {
        return 'MasterStudy LMS';
    }

    public function active(): bool
    {
        return (bool) apply_filters('wpmcp_masterstudy_active', defined('STM_LMS_VERSION'));
    }

    protected function section_kind(): string
    {
        return 'section';
    }

    protected function types(): array
    {
        return [
            'course' => 'stm-courses',
            'lesson' => 'stm-lessons',
            'quiz'   => 'stm-quizzes',
        ];
    }

    protected function movable_kinds(): array
    {
        return [ 'section', 'lesson', 'quiz' ];
    }

    protected function parent_kind(string $kind): string
    {
        return 'section';
    }

    // ------------------------------------------------------------------
    // Storage hooks of LMS_Adapter
    // ------------------------------------------------------------------

    /** A section of a live course of this plugin, or null. */
    private function live_section(int $section): ?MasterStudy_Section
    {
        $row = $this->store->curriculum_ready() ? $this->store->section($section) : null;
        return null !== $row && $this->is_course($row->course_id) ? $row : null;
    }

    private function material(int $post): ?MasterStudy_Material
    {
        return $this->store->curriculum_ready() ? $this->store->material($post) : null;
    }

    protected function linked_course(\WP_Post $post, string $kind): int
    {
        $row = $this->material((int) $post->ID);
        return null !== $row && $this->is_course($row->course_id) ? $row->course_id : 0;
    }

    protected function section_of(int $item): int
    {
        return $this->material($item)?->section_id ?? 0;
    }

    /** @return MasterStudy_Section[] */
    private function sections(int $course): array
    {
        return $this->store->curriculum_ready() ? $this->store->sections($course) : [];
    }

    protected function section_ids(int $course, bool $every_row = false): array
    {
        return array_map(static fn (MasterStudy_Section $s): int => $s->id, $this->sections($course));
    }

    /** @return int[] every post placed in the section. */
    private function material_ids(int $section): array
    {
        return $this->store->curriculum_ready() ? $this->store->material_ids($section) : [];
    }

    protected function item_posts(int $section, bool $every_row = false): array
    {
        $ids = $this->material_ids($section);
        if ([] === $ids) {
            return [];
        }
        // Only the items the caller may read (issue #465). The write path
        // never lists posts: it renumbers every row of the materials table.
        return Readable_Posts::get_posts([
            'post_type'      => array_values($this->types()),
            'post__in'       => $ids,
            'orderby'        => 'post__in',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ]);
    }

    protected function stored_order(int $id): int
    {
        return $this->material($id)?->order ?? 0;
    }

    protected function store_order(int $id, int $order): void
    {
        $row = $this->material($id);
        if (null !== $row) {
            $list = $this->material_ids($row->section_id);
            $this->store->renumber_materials($row->section_id, $this->reordered_ids($list, $id, $order - 1));
        }
    }

    protected function store_section(int $item, int $section): void
    {
        $row = $this->material($item);
        if (null !== $row) {
            $this->store->move_material($item, $row->section_id, $section);
        }
    }

    protected function link_args(string $kind, int $parent, int $course, int $order): array
    {
        return [];
    }

    protected function questions(int $quiz): ?array
    {
        $raw = (string) get_post_meta($quiz, 'questions', true);
        $ids = array_values(array_filter(array_map('intval', explode(',', $raw)), static fn (int $id): bool => $id > 0));
        if ([] === $ids) {
            return [];
        }
        // Only the questions the caller may read (issue #465).
        $posts = Readable_Posts::get_posts([
            'post_type'      => 'stm-questions',
            'post__in'       => $ids,
            'orderby'        => 'post__in',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ]);
        $order = array_flip($ids);

        return array_map(static fn (\WP_Post $q): array => [
            'id'     => (int) $q->ID,
            'title'  => (string) $q->post_title,
            'type'   => (string) get_post_meta((int) $q->ID, 'type', true),
            'points' => null,
            'order'  => ($order[ (int) $q->ID ] ?? 0) + 1,
        ], $posts);
    }

    protected function enrollment_page(int $course, ?string $status, int $page, int $per_page): array
    {
        if (! $this->store->has_table(MasterStudy_Curriculum_Store::ENROLLED)) {
            throw new Operation_Error('masterstudy_table_missing', 'The MasterStudy LMS user courses table is missing on this site.');
        }
        $raw    = null === $status ? array_keys(self::STATUS_MAP) : array_keys(self::STATUS_MAP, $status, true);
        $result = $this->store->enrollment_page($course, array_map('strval', $raw), $page, $per_page);

        return [
            'total' => $result['total'],
            'rows'  => array_map(static fn (array $r): array => [
                'user_id'     => $r['user_id'],
                'status'      => self::STATUS_MAP[ $r['status'] ] ?? $r['status'],
                'enrolled_at' => $r['start_time'] > 0 ? gmdate('Y-m-d H:i:s', $r['start_time']) : null,
            ], $result['rows']),
        ];
    }

    protected function enrolled_count(int $course): ?int
    {
        try {
            return $this->enrollment_page($course, 'enrolled', 1, 1)['total'];
        } catch (Operation_Error $e) {
            return null;
        }
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    protected function get_course(int $course, bool $questions): array
    {
        $post   = get_post($course);
        $counts = [ 'sections' => 0, 'lessons' => 0, 'quizzes' => 0, 'assignments' => 0, 'questions' => $questions ? 0 : null ];
        $kinds  = array_flip($this->types());

        $sections = [];
        foreach ($this->sections($course) as $section) {
            ++$counts['sections'];
            $items = [];
            foreach ($this->item_posts($section->id) as $item) {
                $kind = $kinds[ $item->post_type ] ?? null;
                if (null === $kind || 'course' === $kind) {
                    continue;
                }
                $node = $this->node($item, $kind);
                if ('quiz' === $kind) {
                    ++$counts['quizzes'];
                    if ($questions) {
                        $node['questions']    = (array) $this->questions((int) $item->ID);
                        $counts['questions'] += count($node['questions']);
                    }
                } else {
                    ++$counts['lessons'];
                }
                $items[] = $node;
            }
            $sections[] = $section->node() + [ 'items' => $items ];
        }

        return [
            'course'   => $this->node($post, 'course') + [ 'excerpt' => (string) $post->post_excerpt ],
            'sections' => $sections,
            'counts'   => $counts,
        ];
    }

    // ------------------------------------------------------------------
    // Catalog: sections are rows, so the ops that name one take its id as
    // parent_id or section_id and check edit_post on its course.
    // ------------------------------------------------------------------

    public function operations(): array
    {
        $p   = $this->slug();
        $ops = parent::operations();
        $id  = [ 'type' => 'integer', 'minimum' => 1 ];

        $ops["{$p}-add-section"]['description'] = 'Add a section (a MasterStudy LMS curriculum row with a title only) to course_id at position (0-based; default last), renumbering the ones after it. Undo: rollback-session';
        $ops["{$p}-add-section"]['validate']    = fn (array $args): ?array => $this->no_section_content($args) ?? $this->tables_refusal() ?? $this->course_refusal((int) $args['course_id'], true);
        $ops["{$p}-add-section"]['handler']     = fn (array $args, array $context): array => $this->add_section($args, $context);

        foreach ([ 'lesson', 'quiz' ] as $kind) {
            $op = "{$p}-add-{$kind}";
            unset($ops[ $op ]['objects']);
            $ops[ $op ]['description'] = "Add a {$kind} to the section parent_id (a section id from get-course) at position (0-based; default last); status defaults to publish. Undo: rollback-session";
            $ops[ $op ]['validate']    = fn (array $args): ?array => $this->item_add_refusal($kind, (int) $args['parent_id'], (string) ($args['status'] ?? 'publish'));
            $ops[ $op ]['handler']     = fn (array $args, array $context): array => $this->add_item($kind, $args, $context);
        }

        $update                                = &$ops["{$p}-update-item"];
        $update['description']                 = 'Edit title, content, excerpt or status of a MasterStudy LMS course, lesson or quiz (id), or the title of a section (section_id). Undo: rollback-operation';
        $update['input_schema']['properties'] += [ 'section_id' => $id ];
        $update['input_schema']['required']    = [];
        $update['validate']                    = fn (array $args): ?array => $this->ms_update_refusal($args);
        $update['snapshot']                    = fn (array $args): array => isset($args['section_id'])
            ? [ 'object_type' => Plugin_Table_Rows_Snapshot::TYPE, 'object_id' => Plugin_Table_Rows_Snapshot::key('masterstudy_sections', 'curriculum_sections', [ (int) $this->store->section((int) $args['section_id'])?->course_id ]) ]
            : [ 'object_type' => 'post', 'object_id' => (int) $args['id'] ];
        $update['handler']                     = fn (array $args): array => isset($args['section_id']) ? $this->update_section($args) : $this->update_item($args);
        unset($update);

        $move                                = &$ops["{$p}-move-item"];
        $move['description']                 = 'Move a section (section_id) to position in its course, or a lesson or quiz (id) to position in the section parent_id (same course; default its own). 0-based; siblings renumbered. Undo: rollback-session';
        $move['objects']                     = [ 'id' => [ 'type' => 'post', 'own_type' => true ] ];
        $move['input_schema']['properties'] += [ 'section_id' => $id ];
        $move['input_schema']['required']    = [ 'position' ];
        $move['validate']                    = fn (array $args): ?array => $this->ms_move_plan($args)['refusal'] ?? null;
        $move['handler']                     = fn (array $args, array $context): array => $this->ms_move($args, $context);
        unset($move);

        return $ops;
    }

    // ------------------------------------------------------------------
    // Refusals (run before any snapshot)
    // ------------------------------------------------------------------

    private function tables_refusal(): ?array
    {
        return $this->store->curriculum_ready() ? null : self::refusal('masterstudy_table_missing', 'The MasterStudy LMS curriculum tables are missing on this site.');
    }

    private function no_section_content(array $args): ?array
    {
        return array_key_exists('content', $args) ? self::refusal('invalid_argument', 'A MasterStudy LMS section has only a title.') : null;
    }

    private function item_add_refusal(string $kind, int $section, string $status): ?array
    {
        $row = $this->live_section($section);
        if (null === $row) {
            return self::refusal('invalid_parent', sprintf('%d is not a MasterStudy LMS section.', $section), [ 'parent_id' => $section ]);
        }
        return $this->create_refusal($kind, $status) ?? $this->edit_refusal([ $row->course_id ]);
    }

    private function ms_update_refusal(array $args): ?array
    {
        if (! isset($args['section_id'])) {
            return isset($args['id']) ? $this->update_refusal($args) : self::refusal('invalid_argument', 'Pass id (a course, lesson or quiz) or section_id (a section).');
        }
        if (isset($args['id'])) {
            return self::refusal('invalid_argument', 'Pass id (a course, lesson or quiz) or section_id (a section), not both.');
        }
        $section = (int) $args['section_id'];
        $row     = $this->live_section($section);
        if (null === $row) {
            return self::refusal('item_not_found', sprintf('%d is not a MasterStudy LMS section.', $section), [ 'section_id' => $section ]);
        }
        if ([] !== array_intersect_key($args, array_flip([ 'excerpt', 'status', 'content' ]))) {
            return self::refusal('invalid_argument', 'A MasterStudy LMS section has only a title.');
        }
        if (! array_key_exists('title', $args)) {
            return self::refusal('nothing_to_update', 'Pass title.');
        }
        return $this->edit_refusal([ $row->course_id ]);
    }

    /** $list with $id taken out and put back at $pos. @return int[] */
    private function reordered_ids(array $list, int $id, int $pos): array
    {
        $list = array_values(array_diff(array_map('intval', $list), [ $id ]));
        array_splice($list, max(0, min($pos, count($list))), 0, [ $id ]);
        return $list;
    }

    /**
     * What a move changes, or a refusal. Shared by validate (before any
     * snapshot) and the handler.
     *
     * @return array{refusal?: array, kind?: string, id?: int, course?: int, from?: int, to?: int, dest?: int[], source?: int[]}
     */
    private function ms_move_plan(array $args): array
    {
        $pos = (int) $args['position'];
        if (isset($args['section_id'])) {
            if (isset($args['id'])) {
                return [ 'refusal' => self::refusal('invalid_argument', 'Pass id (a lesson or quiz) or section_id (a section), not both.') ];
            }
            $section = (int) $args['section_id'];
            $row     = $this->live_section($section);
            if (null === $row) {
                return [ 'refusal' => self::refusal('item_not_found', sprintf('%d is not a MasterStudy LMS section.', $section), [ 'section_id' => $section ]) ];
            }
            $course = $row->course_id;
            if (isset($args['parent_id']) && (int) $args['parent_id'] !== $course) {
                return [ 'refusal' => self::refusal('cross_course_move', sprintf('A section stays in its course (%d).', $course), [ 'course_id' => $course ]) ];
            }
            $refusal = $this->edit_refusal([ $course ]);
            return null !== $refusal ? [ 'refusal' => $refusal ] : [ 'kind' => 'section', 'id' => $section, 'course' => $course, 'dest' => $this->reordered_ids($this->section_ids($course), $section, $pos) ];
        }

        if (! isset($args['id'])) {
            return [ 'refusal' => self::refusal('invalid_argument', 'Pass id (a lesson or quiz) or section_id (a section).') ];
        }
        $id   = (int) $args['id'];
        $kind = $this->kind_of($id);
        $item = $this->material($id);
        if (null === $kind || 'course' === $kind || null === $item) {
            return [ 'refusal' => self::refusal('item_not_found', sprintf('%d is not a MasterStudy LMS lesson or quiz in a course.', $id), [ 'id' => $id ]) ];
        }
        $course = $item->course_id;
        $from   = $item->section_id;
        $to     = isset($args['parent_id']) ? (int) $args['parent_id'] : $from;
        $target = $this->live_section($to);
        if (null === $target) {
            return [ 'refusal' => self::refusal('invalid_parent', sprintf('%d is not a MasterStudy LMS section.', $to), [ 'parent_id' => $to ]) ];
        }
        if ($target->course_id !== $course) {
            return [ 'refusal' => self::refusal('cross_course_move', sprintf('Items move only between sections of their own course (%d).', $course), [ 'course_id' => $course ]) ];
        }
        $refusal = $this->edit_refusal([ $course, $id ]);
        if (null !== $refusal) {
            return [ 'refusal' => $refusal ];
        }
        return [
            'kind'   => $kind,
            'id'     => $id,
            'course' => $course,
            'from'   => $from,
            'to'     => $to,
            'dest'   => $this->reordered_ids($this->material_ids($to), $id, $pos),
            'source' => $from === $to ? [] : array_values(array_diff($this->material_ids($from), [ $id ])),
        ];
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /** Run $write under a snapshot of MasterStudy curriculum rows; returns the operation id. */
    private function rows_snapshotted(string $kind, string $suffix, array $ids, array $context, array $args, callable $write): string
    {
        $out = Safe_Mutation::run(
            [
                'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                'object_id'   => Plugin_Table_Rows_Snapshot::key($kind, $suffix, $ids),
                'session_id'  => (string) $context['session_id'],
                'tool_name'   => (string) $context['tool_name'],
                'args'        => [ 'operation' => (string) $context['operation'], 'args' => $args ],
            ],
            static function () use ($write): bool {
                $write();
                return true;
            }
        );
        return (string) $out['operation_id'];
    }

    private function add_section(array $args, array $context): array
    {
        $course  = (int) $args['course_id'];
        $list    = $this->section_ids($course);
        $pos     = min((int) ($args['position'] ?? count($list)), count($list));
        $created = 0;

        $op = $this->rows_snapshotted('masterstudy_sections', 'curriculum_sections', [ $course ], $context, $args, function () use ($course, $list, $pos, $args, &$created): void {
            $created = $this->store->insert_section($course, (string) $args['title'], $pos + 1);
            if ($created < 1) {
                throw new Operation_Refused('insert_failed', 'MasterStudy LMS could not create the section.');
            }
            array_splice($list, $pos, 0, [ $created ]);
            $this->store->renumber_sections($list);
        });

        return [ 'result' => [ 'item' => $this->store->section($created)?->node() ], 'operation_ids' => [ $op ] ];
    }

    private function add_item(string $kind, array $args, array $context): array
    {
        $sanitized = Kses_Check::report(0, $args['content'] ?? null);
        $section = (int) $args['parent_id'];
        $list    = $this->material_ids($section);
        $pos     = min((int) ($args['position'] ?? count($list)), count($list));
        $type    = $this->types()[ $kind ];

        $created = wp_insert_post([
            'post_type'    => $type,
            'post_title'   => wp_slash((string) $args['title']),
            'post_content' => wp_slash((string) ($args['content'] ?? '')),
            'post_excerpt' => wp_slash((string) ($args['excerpt'] ?? '')),
            'post_status'  => (string) ($args['status'] ?? 'publish'),
            'post_author'  => get_current_user_id(),
        ], true);
        if (is_wp_error($created) || 0 === (int) $created) {
            throw new Operation_Refused('insert_failed', sprintf('MasterStudy LMS could not create the %s.', $kind));
        }
        $created = (int) $created;
        $ids     = [ Post_Creation_Snapshot::record((string) $context['tool_name'], [ $created ], [ 'operation' => $context['operation'], 'args' => $args ], (string) $context['session_id']) ];

        $ids[] = $this->rows_snapshotted('masterstudy_materials', 'curriculum_materials', [ $section ], $context, $args, function () use ($section, $created, $type, $list, $pos): void {
            $this->store->insert_material($section, $created, $type, $pos + 1);
            array_splice($list, $pos, 0, [ $created ]);
            $this->store->renumber_materials($section, $list);
        });

        // @phpstan-ignore argument.type (get_post() of the post this call just wrote, as on the line before #1243.)
        return [ 'result' => [ 'item' => $this->node(get_post($created), $kind) ] + $sanitized, 'operation_ids' => $ids ];
    }

    private function update_section(array $args): array
    {
        $section = (int) $args['section_id'];
        if (! $this->store->rename_section($section, (string) $args['title'])) {
            throw new Operation_Refused('update_failed', 'MasterStudy LMS could not update the section.');
        }
        return [ 'item' => $this->store->section($section)?->node() ];
    }

    private function ms_move(array $args, array $context): array
    {
        $plan = $this->ms_move_plan($args);
        if (isset($plan['refusal'])) {
            throw new Operation_Refused((string) $plan['refusal']['code'], (string) $plan['refusal']['message'], (array) $plan['refusal']['data']);
        }
        $id     = (int) $plan['id'];
        $course = (int) $plan['course'];
        $dest   = array_map('intval', $plan['dest']);
        $ids    = [];

        if ('section' === $plan['kind']) {
            if ($this->store->renumber_sections($dest, true)) {
                $ids[] = $this->rows_snapshotted('masterstudy_sections', 'curriculum_sections', [ $course ], $context, $args, fn () => $this->store->renumber_sections($dest));
            }
            $item = $this->store->section($id)?->node();
        } else {
            $from   = (int) $plan['from'];
            $to     = (int) $plan['to'];
            $source = array_map('intval', $plan['source']);
            if ($from !== $to || $this->store->renumber_materials($to, $dest, true)) {
                $ids[] = $this->rows_snapshotted('masterstudy_materials', 'curriculum_materials', array_unique([ $from, $to ]), $context, $args, function () use ($id, $from, $to, $dest, $source): void {
                    if ($from !== $to) {
                        $this->store->move_material($id, $from, $to);
                        $this->store->renumber_materials($from, $source);
                    }
                    $this->store->renumber_materials($to, $dest);
                });
            }
            $item = $this->node(get_post($id), (string) $plan['kind']);
        }

        return [ 'result' => [ 'item' => $item, 'order' => $dest ], 'operation_ids' => $ids ];
    }
    // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
