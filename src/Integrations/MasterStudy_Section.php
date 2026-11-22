<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One row of MasterStudy LMS's {prefix}stm_lms_curriculum_sections (issue
 * #1033): a course section, which is a table row rather than a post.
 */
final class MasterStudy_Section
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly int $course_id,
        public readonly int $order,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function from_row(array $row): self
    {
        return new self((int) $row['id'], (string) $row['title'], (int) $row['course_id'], (int) $row['order']);
    }

    /** @return array{id: int, kind: string, title: string, order: int} */
    public function node(): array
    {
        return [ 'id' => $this->id, 'kind' => 'section', 'title' => $this->title, 'order' => $this->order ];
    }
}
