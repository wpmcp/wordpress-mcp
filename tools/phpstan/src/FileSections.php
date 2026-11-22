<?php

declare(strict_types=1);

namespace WPMCP\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\Namespace_;

/**
 * Splits a parsed file into its namespace sections: one per `namespace`
 * statement, or one global section ('') for a file without any. Top-level
 * statements before the first namespace (declare, comments) belong to the
 * global section.
 */
final class FileSections
{
    /**
     * @param array<Node> $nodes
     * @return list<array{string, array<Node>}>
     */
    public function of(array $nodes): array
    {
        $sections = [];
        $global = [];
        foreach ($nodes as $node) {
            if ($node instanceof Namespace_) {
                $sections[] = [null === $node->name ? '' : $node->name->toString(), $node->stmts];
            } else {
                $global[] = $node;
            }
        }
        if ([] !== $global) {
            array_unshift($sections, ['', $global]);
        }
        return $sections;
    }
}
