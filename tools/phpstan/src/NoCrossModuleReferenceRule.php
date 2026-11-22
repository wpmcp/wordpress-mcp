<?php

declare(strict_types=1);

namespace WPMCP\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Rule S8, gate G2: a module names only its own classes, the public API and
 * the platform namespaces; the kernel and the API never name a module.
 *
 * Every class name NameCollector finds (new, static call, static property,
 * class constant and ::class, instanceof, parameter, return, property and
 * constant types, catch, attributes and their arguments, extends,
 * implements, trait use) is checked:
 *
 * - wpmcp.crossModule: in a module's code, the name resolves to a class
 *   owned by a different module (ModuleMap: the compiled registry, else the
 *   WPMCP\Modules\<Id>\ convention).
 * - wpmcp.crossModule.internal: in a module's code, the name is a WPMCP\
 *   class owned by no module and outside the public namespaces (by default
 *   WPMCP\Api\ plus the phase A platform namespaces of design section 1.5).
 *   The kernel itself is internal: modules reach it only through the API.
 * - wpmcp.kernelNamesModule: in a kernel or API namespace, the name resolves
 *   to a class owned by any module.
 *
 * "A module's code" is a namespace section the map assigns to a module, by
 * namespace or by one of its legacy path globs. Names in docblocks or in
 * strings are not checked.
 *
 * @implements Rule<FileNode>
 */
final class NoCrossModuleReferenceRule implements Rule
{
    private readonly NameCollector $names;

    private readonly FileSections $sections;

    /**
     * @param list<string> $kernelNamespaces
     * @param list<string> $publicNamespaces
     */
    public function __construct(
        private readonly ModuleMap $modules,
        private readonly array $kernelNamespaces,
        private readonly array $publicNamespaces,
    ) {
        $this->names = new NameCollector();
        $this->sections = new FileSections();
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];
        foreach ($this->sections->of($node->getNodes()) as [$namespace, $stmts]) {
            $module = '' === $namespace ? null : $this->modules->moduleOfNamespace($namespace);
            $module ??= $this->modules->moduleOfFile($scope->getFile());
            $kernel = null === $module && $this->under($namespace . '\\', $this->kernelNamespaces);
            if (null === $module && ! $kernel) {
                continue;
            }
            foreach ($this->names->classes($stmts) as $ref) {
                $owner = $this->modules->moduleOfClass($ref->class);
                if ($kernel) {
                    if (null !== $owner) {
                        $errors[] = RuleErrorBuilder::message(sprintf(
                            '%s names %s (%s), a class of module "%s". The kernel and the API never name a module; modules register through attributes and the compiled registry.',
                            $namespace,
                            $ref->class,
                            $ref->kind,
                            $owner->id,
                        ))->identifier('wpmcp.kernelNamesModule')->line($ref->line)->build();
                    }
                    continue;
                }
                if (null !== $owner) {
                    if ($owner->id !== $module->id) {
                        $errors[] = RuleErrorBuilder::message(sprintf(
                            'Module "%s" names %s (%s), a class of module "%s". Modules never name each other (rule S8).',
                            $module->id,
                            $ref->class,
                            $ref->kind,
                            $owner->id,
                        ))->identifier('wpmcp.crossModule')->line($ref->line)
                            ->tip('Depend on an interface in WPMCP\\Api, or bind one with #[Provides] and inject it.')
                            ->build();
                    }
                    continue;
                }
                if (0 === strncasecmp($ref->class, 'WPMCP\\', 6) && ! $this->under($ref->class, $this->publicNamespaces)) {
                    $errors[] = RuleErrorBuilder::message(sprintf(
                        'Module "%s" names %s (%s), an internal WPMCP class. Modules depend only on WPMCP\\Api and the platform namespaces (rule S8).',
                        $module->id,
                        $ref->class,
                        $ref->kind,
                    ))->identifier('wpmcp.crossModule.internal')->line($ref->line)->build();
                }
            }
        }
        return $errors;
    }

    /** @param list<string> $prefixes */
    private function under(string $class, array $prefixes): bool
    {
        $class = ltrim($class, '\\');
        foreach ($prefixes as $prefix) {
            $prefix = ltrim($prefix, '\\');
            // A prefix ending in a backslash is a namespace, otherwise a class name.
            if (str_ends_with($prefix, '\\') ? 0 === strncasecmp($class, $prefix, strlen($prefix)) : 0 === strcasecmp($class, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
