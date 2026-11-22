<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Where a lesson or quiz sits in a MasterStudy LMS course (issue #1033): its
 * row of {prefix}stm_lms_curriculum_materials joined to its section's course.
 */
final class MasterStudy_Material
{
    public function __construct(
        public readonly int $post_id,
        public readonly int $section_id,
        public readonly int $order,
        public readonly int $course_id,
    ) {
    }
}
