<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The undo point of a Visual Composer structural write (issue #1112): the
 * post snapshot (post row and every postmeta row, so post_content,
 * vcv-pageContent and the CSS postmeta) taken by Safe_Mutation before the
 * write runs. rollback-operation restores it exactly.
 */
final class Visual_Composer_Structure_Snapshot
{
    /**
     * @param array<string,mixed>  $args  the tool call, recorded with the undo point
     * @param callable(): void     $write
     * @return string the operation id
     */
    public function run(int $post_id, array $args, callable $write): string
    {
        $session = $args['session_id'] ?? 'default';
        $out     = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => is_scalar($session) ? (string) $session : 'default',
                'tool_name'   => 'update-builder-content',
                'args'        => $args,
            ],
            static function () use ($write): bool {
                $write();

                return true;
            }
        );

        $operation_id = $out['operation_id'] ?? null;

        return is_string($operation_id) ? $operation_id : '';
    }
}
