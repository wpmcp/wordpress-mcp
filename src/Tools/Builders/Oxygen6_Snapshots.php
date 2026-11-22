<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Undo points for the Oxygen 6 writes of issue #805. A change to an
 * existing post (a Component's property values, a template's conditions, a
 * layout imported into a blank post) is one post snapshot: the row and all
 * its postmeta, `_oxygen_data` and `_oxygen_template_settings` included.
 * New posts (create_page, a saved template, an import) are one creation row
 * listing every post the call made, primary first, so rollback-operation
 * trashes them all.
 */
final class Oxygen6_Snapshots
{
    private const TOOL = 'update-builder-content';

    /**
     * Snapshot the post, then run the write.
     *
     * @param array<string,mixed> $args
     * @param callable():void $write
     * @return string the operation id
     */
    public function write_post(int $post_id, array $args, callable $write): string
    {
        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => $this->session($args),
                'tool_name'   => self::TOOL,
                'args'        => $args,
            ],
            static function () use ($write): bool {
                $write();

                return true;
            }
        );

        return Oxygen6_Values::str($out['operation_id'] ?? null);
    }

    /**
     * Record posts this call created. When the row cannot be written the
     * posts are removed again (Post_Creation_Snapshot::record()).
     *
     * @param int[] $post_ids primary first
     * @param array<string,mixed> $args
     * @return string the operation id
     */
    public function record_creation(array $post_ids, array $args): string
    {
        return Post_Creation_Snapshot::record(self::TOOL, $post_ids, $args, $this->session($args));
    }

    /** @param array<string,mixed> $args */
    private function session(array $args): string
    {
        $session = Oxygen6_Values::str($args['session_id'] ?? null);

        return '' === $session ? 'default' : $session;
    }
}
