<?php

declare(strict_types=1);

namespace WPMCP\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Rule S1, gate G5: the layer map inside a module.
 *
 * A file's layer is the first namespace segment below its module namespace
 * (WPMCP\Modules\Woo\Tools\List_Orders is in Tools). Files the module owns
 * only by a legacy path glob have no layer and are skipped. Checks, in a
 * module's code:
 *
 * 1. wpmcp.layer: a class of the same module in another layer is named
 *    (NameCollector's list) where the map does not allow it. A layer may
 *    always name its own folder, Value\ and anything outside the module.
 *       Tools, Ops, Snapshots -> Adapter, Value
 *       Hooks, Admin          -> Tools, Ops, Adapter, Value
 *       Adapter               -> Value
 *       Value                 -> (nothing else)
 *       module root           -> anything (registration only, see 4)
 *    Admin is not in the design's table; it is treated like Hooks.
 * 2. wpmcp.layer.adapterImplementation: outside Adapter\, an Adapter\ class
 *    that is not an interface is named (type against <Id>_Adapter, never the
 *    Wp_* implementation).
 * 3. wpmcp.layer.host: outside Adapter\, a host plugin symbol is named: a
 *    class (SymbolOrigin FOREIGN: not PHP, not WordPress core, not WPMCP,
 *    unknown classes included) or a called function of FOREIGN origin.
 *    WordPress core functions and classes are allowed in every layer; the
 *    rule cannot tell WordPress storage calls from other WordPress calls.
 * 4. wpmcp.layer.moduleClass: the class carrying #[Module] (the module
 *    root) holds no properties, no trait uses and no methods, except the
 *    static method its `availability:` argument names, boot() when it
 *    implements Has_Boot and is_available() when it implements
 *    Has_Availability (the registry contract needs those). Constants are
 *    allowed. Its file holds only declare, use, the class and the ABSPATH
 *    guard (`if (! defined('ABSPATH')) { exit; }`).
 * 5. wpmcp.layer.unknown: a file in a subfolder outside the layer list
 *    (Tools, Ops, Snapshots, Hooks, Admin, Adapter, Value), once per file.
 *    Other classes at the module root are not constrained.
 *
 * Classes of other modules are left to NoCrossModuleReferenceRule.
 *
 * @implements Rule<FileNode>
 */
final class LayerRule implements Rule
{
    private const ALLOWED = [
        'Tools' => ['Adapter', 'Value'],
        'Ops' => ['Adapter', 'Value'],
        'Snapshots' => ['Adapter', 'Value'],
        'Hooks' => ['Tools', 'Ops', 'Adapter', 'Value'],
        'Admin' => ['Tools', 'Ops', 'Adapter', 'Value'],
        'Adapter' => ['Value'],
        'Value' => [],
    ];

    private const MODULE_ATTRIBUTE = 'WPMCP\\Api\\V1\\Attribute\\Module';
    private const HAS_BOOT = 'WPMCP\\Api\\V1\\Contract\\Has_Boot';
    private const HAS_AVAILABILITY = 'WPMCP\\Api\\V1\\Contract\\Has_Availability';

    private readonly NameCollector $names;

    private readonly FileSections $sections;

    public function __construct(
        private readonly ModuleMap $modules,
        private readonly SymbolOrigin $origin,
        private readonly ReflectionProvider $reflectionProvider,
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
            if ('' === $namespace) {
                continue;
            }
            $module = $this->modules->moduleOfNamespace($namespace);
            $layer = $module?->layerOf($namespace . '\\X');
            if (null === $module || null === $layer) {
                continue;
            }
            if ('' !== $layer && ! isset(self::ALLOWED[ $layer ])) {
                $errors[] = RuleErrorBuilder::message(sprintf(
                    'Module "%s" has code in %s\\, which is not a module layer. Use Tools, Ops, Snapshots, Hooks, Admin, Adapter or Value (rule S1).',
                    $module->id,
                    $layer,
                ))->identifier('wpmcp.layer.unknown')->line($this->firstLine($stmts))->build();
                continue;
            }
            array_push($errors, ...$this->references($module, $layer, $namespace, $stmts));
            if ('' === $layer) {
                array_push($errors, ...$this->moduleClass($module, $stmts));
            }
        }
        return $errors;
    }

    /**
     * @param array<Node> $stmts
     * @return list<IdentifierRuleError>
     */
    private function references(ModuleRef $module, string $layer, string $namespace, array $stmts): array
    {
        $errors = [];
        foreach ($this->names->classes($stmts) as $ref) {
            $owner = $this->modules->moduleOfClass($ref->class);
            if (null !== $owner) {
                if ($owner->id !== $module->id) {
                    continue;
                }
                $target = $module->layerOf($ref->class);
                if (null === $target || $target === $layer || '' === $layer || ! isset(self::ALLOWED[ $target ]) && '' !== $target) {
                    continue;
                }
                if (! in_array($target, self::ALLOWED[ $layer ], true)) {
                    $errors[] = RuleErrorBuilder::message(sprintf(
                        '%s\\ code of module "%s" names %s (%s) from %s. %s\\ may depend on %s (rule S1).',
                        $layer,
                        $module->id,
                        $ref->class,
                        $ref->kind,
                        '' === $target ? 'the module root' : $target . '\\',
                        $layer,
                        $this->describe($layer),
                    ))->identifier('wpmcp.layer')->line($ref->line)->build();
                    continue;
                }
                if ('Adapter' === $target && ! $this->isInterface($ref->class)) {
                    $errors[] = RuleErrorBuilder::message(sprintf(
                        '%s\\ code of module "%s" names the adapter implementation %s (%s). Outside Adapter\\, type against the adapter interface (rule S1).',
                        $layer,
                        $module->id,
                        $ref->class,
                        $ref->kind,
                    ))->identifier('wpmcp.layer.adapterImplementation')->line($ref->line)->build();
                }
                continue;
            }
            if ('Adapter' !== $layer && SymbolOrigin::FOREIGN === $this->origin->ofClass($ref->class)) {
                $errors[] = $this->host($module, $layer, $ref->class . ' (' . $ref->kind . ')', $ref->line);
            }
        }
        if ('Adapter' !== $layer) {
            foreach ($this->names->functionNames($stmts) as $name) {
                if (SymbolOrigin::FOREIGN === $this->origin->ofFunction($name, $namespace)) {
                    $errors[] = $this->host($module, $layer, $name->toString() . '() (function call)', $name->getStartLine());
                }
            }
        }
        return $errors;
    }

    private function host(ModuleRef $module, string $layer, string $what, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            '%s code of module "%s" uses %s, a host plugin symbol (not PHP, WordPress or WPMCP). Only Adapter\\ touches the host plugin (rule S1).',
            '' === $layer ? 'Module root' : $layer . '\\',
            $module->id,
            $what,
        ))->identifier('wpmcp.layer.host')->line($line)
            ->tip('Add a method to the module adapter interface and implement it in Adapter\\.')
            ->build();
    }

    /**
     * @param array<Node> $stmts
     * @return list<IdentifierRuleError>
     */
    private function moduleClass(ModuleRef $module, array $stmts): array
    {
        $class = null;
        foreach ($stmts as $candidate) {
            if ($candidate instanceof Stmt\Class_ && $this->hasModuleAttribute($candidate)) {
                $class = $candidate;
            }
        }
        if (null === $class) {
            return [];
        }
        $errors = [];
        $name = null === $class->name ? 'Module' : $class->name->toString();
        foreach ($stmts as $stmt) {
            if ($stmt === $class || $stmt instanceof Stmt\Use_ || $stmt instanceof Stmt\GroupUse || $stmt instanceof Stmt\Declare_ || $stmt instanceof Stmt\Nop || $this->isAbspathGuard($stmt)) {
                continue;
            }
            $errors[] = $this->moduleError($module, sprintf('a %s statement next to the class', $this->stmtName($stmt)), $stmt->getStartLine());
        }
        $allowed = $this->allowedMethods($class);
        foreach ($class->stmts as $member) {
            if ($member instanceof Stmt\Property) {
                $errors[] = $this->moduleError($module, sprintf('a property in %s', $name), $member->getStartLine());
            } elseif ($member instanceof Stmt\TraitUse) {
                $errors[] = $this->moduleError($module, sprintf('a trait use in %s', $name), $member->getStartLine());
            } elseif ($member instanceof Stmt\ClassMethod && ! in_array($member->name->toLowerString(), $allowed, true)) {
                $errors[] = $this->moduleError($module, sprintf('method %s::%s()', $name, $member->name->toString()), $member->getStartLine());
            }
        }
        return $errors;
    }

    private function moduleError(ModuleRef $module, string $what, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            'The Module class of module "%s" declares %s. Module.php declares and never executes (rule S1).',
            $module->id,
            $what,
        ))->identifier('wpmcp.layer.moduleClass')->line($line)
            ->tip('Move the logic to Hooks\\ or a service; only the availability method, boot() and is_available() may live on the Module class.')
            ->build();
    }

    /** @return list<string> lower-case method names the registry contract needs on the Module class */
    private function allowedMethods(Stmt\Class_ $class): array
    {
        $allowed = [];
        foreach ($class->implements as $interface) {
            $interface = $interface->toString();
            if (0 === strcasecmp($interface, self::HAS_BOOT)) {
                $allowed[] = 'boot';
            }
            if (0 === strcasecmp($interface, self::HAS_AVAILABILITY)) {
                $allowed[] = 'is_available';
            }
        }
        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if (0 !== strcasecmp($attr->name->toString(), self::MODULE_ATTRIBUTE)) {
                    continue;
                }
                foreach ($attr->args as $arg) {
                    if (null !== $arg->name && 'availability' === $arg->name->toString() && $arg->value instanceof Node\Scalar\String_) {
                        $allowed[] = strtolower($arg->value->value);
                    }
                }
            }
        }
        return $allowed;
    }

    private function hasModuleAttribute(Stmt\Class_ $class): bool
    {
        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if (0 === strcasecmp($attr->name->toString(), self::MODULE_ATTRIBUTE)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** `if (! defined('ABSPATH')) { exit; }` (or return), the house guard. */
    private function isAbspathGuard(Node $stmt): bool
    {
        if (! $stmt instanceof Stmt\If_ || [] !== $stmt->elseifs || null !== $stmt->else || 1 !== count($stmt->stmts)) {
            return false;
        }
        $cond = $stmt->cond;
        if (! $cond instanceof Expr\BooleanNot || ! $cond->expr instanceof Expr\FuncCall) {
            return false;
        }
        $call = $cond->expr;
        if (! $call->name instanceof Node\Name) {
            return false;
        }
        $arg = $call->args[0] ?? null;
        if ('defined' !== strtolower($call->name->toString()) || ! $arg instanceof Node\Arg || ! $arg->value instanceof Node\Scalar\String_ || 'ABSPATH' !== $arg->value->value) {
            return false;
        }
        $body = $stmt->stmts[0];
        return $body instanceof Stmt\Return_ || $body instanceof Stmt\Expression && $body->expr instanceof Expr\Exit_;
    }

    private function stmtName(Node $stmt): string
    {
        $tail = strrchr('\\' . $stmt::class, '\\');
        $short = false === $tail ? '' : substr($tail, 1);
        return strtolower(rtrim($short, '_'));
    }

    /** @param array<Node> $stmts */
    private function firstLine(array $stmts): int
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\ClassLike || $stmt instanceof Stmt\Function_) {
                return $stmt->getStartLine();
            }
        }
        return [] === $stmts ? 1 : $stmts[array_key_first($stmts)]->getStartLine();
    }

    private function describe(string $layer): string
    {
        $allowed = self::ALLOWED[ $layer ] ?? [];
        if ([] === $allowed) {
            return 'nothing else in its module';
        }
        $list = implode(', ', array_map(static fn (string $l): string => $l . '\\', $allowed));
        return 'Adapter' !== $layer && in_array('Adapter', $allowed, true) ? $list . ' (Adapter\\ interfaces only)' : $list;
    }

    private function isInterface(string $class): bool
    {
        return $this->reflectionProvider->hasClass($class) && $this->reflectionProvider->getClass($class)->isInterface();
    }
}
