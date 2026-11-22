<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * MasterStudy LMS storage (issue #1033), as MasterStudy LMS 3.7.60 keeps it
 * (checked against its source: _core/libraries/db/tables/*.table.php and
 * includes/Repositories/Curriculum*Repository.php):
 *  - sections are rows of {prefix}stm_lms_curriculum_sections (id, title,
 *    course_id, a 1-based `order`);
 *  - each lesson or quiz is placed in a section by a row of
 *    {prefix}stm_lms_curriculum_materials (post_id, post_type, section_id,
 *    a 1-based `order`);
 *  - an enrollment is a row of {prefix}stm_lms_user_courses (user_id,
 *    course_id, status, a unix start_time).
 *
 * Only reads and writes rows; checks, snapshots and the op catalog live in
 * LMS_MasterStudy. MasterStudy caches none of these rows, so nothing is
 * purged after a write.
 */
final class MasterStudy_Curriculum_Store
{
    public const SECTIONS  = 'stm_lms_curriculum_sections';
    public const MATERIALS = 'stm_lms_curriculum_materials';
    public const ENROLLED  = 'stm_lms_user_courses';

    public function __construct(private readonly \wpdb $db)
    {
    }

    public function table(string $name): string
    {
        return $this->db->prefix . $name;
    }

    public function has_table(string $name): bool
    {
        $table = $this->table($name);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a presence check of a third-party table.
        return $table === $this->db->get_var($this->db->prepare('SHOW TABLES LIKE %s', $this->db->esc_like($table)));
    }

    public function curriculum_ready(): bool
    {
        return $this->has_table(self::SECTIONS) && $this->has_table(self::MATERIALS);
    }

    public function section(int $id): ?MasterStudy_Section
    {
        if ($id < 1) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MasterStudy LMS's own section table; the curriculum must be live.
        $row = $this->db->get_row($this->db->prepare('SELECT id, title, course_id, `order` FROM %i WHERE id = %d', $this->table(self::SECTIONS), $id), ARRAY_A);
        return is_array($row) ? MasterStudy_Section::from_row($row) : null;
    }

    /** @return MasterStudy_Section[] a course's sections in MasterStudy's order. */
    public function sections(int $course): array
    {
        if ($course < 1) {
            return [];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MasterStudy LMS's own section table.
        $rows = $this->db->get_results($this->db->prepare('SELECT id, title, course_id, `order` FROM %i WHERE course_id = %d ORDER BY `order` ASC, id ASC', $this->table(self::SECTIONS), $course), ARRAY_A);
        return array_map(static fn (array $row): MasterStudy_Section => MasterStudy_Section::from_row($row), (array) $rows);
    }

    /** @return int[] the post ids placed in a section, in MasterStudy's order. */
    public function material_ids(int $section): array
    {
        if ($section < 1) {
            return [];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MasterStudy LMS's own materials table.
        $ids = $this->db->get_col($this->db->prepare('SELECT post_id FROM %i WHERE section_id = %d ORDER BY `order` ASC, id ASC', $this->table(self::MATERIALS), $section));
        return array_values(array_unique(array_map('intval', (array) $ids)));
    }

    /** The first placement of a post (a lesson may sit in more than one course). */
    public function material(int $post): ?MasterStudy_Material
    {
        if ($post < 1) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MasterStudy LMS's own curriculum tables.
        $row = $this->db->get_row($this->db->prepare('SELECT m.section_id, m.`order`, s.course_id FROM %i m INNER JOIN %i s ON s.id = m.section_id WHERE m.post_id = %d ORDER BY m.id ASC LIMIT 1', $this->table(self::MATERIALS), $this->table(self::SECTIONS), $post), ARRAY_A);
        if (! is_array($row)) {
            return null;
        }
        return new MasterStudy_Material($post, (int) $row['section_id'], (int) $row['order'], (int) $row['course_id']);
    }

    public function insert_section(int $course, string $title, int $order): int
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- MasterStudy LMS's own section table, as CurriculumSectionRepository::create() inserts it.
        $this->db->insert($this->table(self::SECTIONS), [ 'title' => $title, 'course_id' => $course, 'order' => $order ]);
        return (int) $this->db->insert_id;
    }

    public function rename_section(int $section, string $title): bool
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MasterStudy LMS's own section table.
        return false !== $this->db->update($this->table(self::SECTIONS), [ 'title' => $title ], [ 'id' => $section ]);
    }

    public function insert_material(int $section, int $post, string $type, int $order): void
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- MasterStudy LMS's own materials table, as CurriculumMaterialRepository::create() inserts it.
        $this->db->insert($this->table(self::MATERIALS), [ 'post_id' => $post, 'post_type' => $type, 'section_id' => $section, 'order' => $order ]);
    }

    public function move_material(int $post, int $from, int $to): void
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MasterStudy LMS's own materials table.
        $this->db->update($this->table(self::MATERIALS), [ 'section_id' => $to ], [ 'section_id' => $from, 'post_id' => $post ]);
    }

    /** Write order 1..n for the sections in $ids where it differs; whether any changed. */
    public function renumber_sections(array $ids, bool $dry_run = false): bool
    {
        $changed = false;
        foreach (array_values($ids) as $i => $id) {
            $row = $this->section((int) $id);
            if (null === $row || $row->order === $i + 1) {
                continue;
            }
            $changed = true;
            if (! $dry_run) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MasterStudy LMS's own section table.
                $this->db->update($this->table(self::SECTIONS), [ 'order' => $i + 1 ], [ 'id' => (int) $id ]);
            }
        }
        return $changed;
    }

    /** Write order 1..n for the posts in $ids within $section where it differs; whether any changed. */
    public function renumber_materials(int $section, array $ids, bool $dry_run = false): bool
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MasterStudy LMS's own materials table.
        $rows    = $this->db->get_results($this->db->prepare('SELECT post_id, `order` FROM %i WHERE section_id = %d', $this->table(self::MATERIALS), $section), ARRAY_A);
        $orders  = array_map('intval', array_column((array) $rows, 'order', 'post_id'));
        $changed = false;
        foreach (array_values($ids) as $i => $post) {
            if (($orders[ (int) $post ] ?? -1) === $i + 1) {
                continue;
            }
            $changed = true;
            if (! $dry_run) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MasterStudy LMS's own materials table.
                $this->db->update($this->table(self::MATERIALS), [ 'order' => $i + 1 ], [ 'section_id' => $section, 'post_id' => (int) $post ]);
            }
        }
        return $changed;
    }

    /**
     * One page of a course's enrollments, newest first: the latest row per
     * student whose raw status is one of $statuses.
     *
     * @param string[] $statuses raw MasterStudy statuses
     * @return array{total: int, rows: array<int, array{user_id: int, status: string, start_time: int}>}
     */
    public function enrollment_page(int $course, array $statuses, int $page, int $per_page): array
    {
        if ([] === $statuses) {
            return [ 'total' => 0, 'rows' => [] ];
        }
        $table = $this->table(self::ENROLLED);
        $in    = implode(', ', array_fill(0, count($statuses), '%s'));
        $from  = $this->db->prepare("FROM %i uc INNER JOIN (SELECT user_id, MAX(user_course_id) AS latest FROM %i WHERE course_id = %d GROUP BY user_id) l ON uc.user_course_id = l.latest WHERE uc.status IN ({$in})", $table, $table, $course, ...$statuses); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is only %s placeholders, one per status.
        $total = (int) $this->db->get_var("SELECT COUNT(*) {$from}"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $from was prepared above; enrollments must be live.
        $rows  = $this->db->get_results($this->db->prepare("SELECT uc.user_id, uc.status, uc.start_time {$from} ORDER BY uc.start_time DESC, uc.user_course_id DESC LIMIT %d OFFSET %d", $per_page, ($page - 1) * $per_page), ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $from was prepared above; enrollments must be live.

        return [
            'total' => $total,
            'rows'  => array_map(static fn (array $r): array => [ 'user_id' => (int) $r['user_id'], 'status' => (string) $r['status'], 'start_time' => (int) $r['start_time'] ], (array) $rows),
        ];
    }
}
