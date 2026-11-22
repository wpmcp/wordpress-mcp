<?php

declare(strict_types=1);

namespace WPMCP\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Rules S5 and S8, gate G3: module code creates only values; services are
 * injected through the constructor.
 *
 * In a module's code (ModuleMap::moduleOfScope), `new X` is allowed when X is:
 *
 * - self, static or parent, or an anonymous class;
 * - in the module's own Value\ namespace, or in a configured value
 *   namespace (WPMCP\Api\V1\Value\ by default);
 * - a \Throwable (the module's own exceptions, PHP's, WordPress's);
 * - a PHP builtin or a WordPress core class (SymbolOrigin).
 *
 * Reported (wpmcp.foreignNew): every other WPMCP class, which means the
 * module's own adapters, tools and helpers, the kernel, and legacy WPMCP
 * services such as Plugin. `new $name` is reported as
 * wpmcp.foreignNew.dynamic, since its class cannot be checked.
 *
 * Left to other rules, so one mistake is reported once: a class of another
 * module (NoCrossModuleReferenceRule) and a host plugin or vendor class
 * (LayerRule, which allows those in Adapter/ only).
 *
 * @implements Rule<New_>
 */
final class NoForeignNewRule implements Rule
{
    /** @param list<string> $valueNamespaces */
    public function __construct(
        private readonly ModuleMap $modules,
        private readonly SymbolOrigin $origin,
        private readonly ReflectionProvider $reflectionProvider,
        private readonly array $valueNamespaces,
    ) {
    }

    public function getNodeType(): string
    {
        return New_::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        $module = $this->modules->moduleOfScope($scope);
        if (null === $module || $node->class instanceof Node\Stmt\Class_) {
            return [];
        }
        if (! $node->class instanceof Node\Name) {
            return [
                RuleErrorBuilder::message(sprintf('Module "%s" creates an object from a dynamic class name. Inject the service instead (rule S5).', $module->id))
                    ->identifier('wpmcp.foreignNew.dynamic')->build(),
            ];
        }
        if ($node->class->isSpecialClassName()) {
            return [];
        }
        $class = $scope->resolveName($node->class);
        $owner = $this->modules->moduleOfClass($class);
        if (null !== $owner && $owner->id !== $module->id) {
            return [];
        }
        if (SymbolOrigin::WPMCP !== $this->origin->ofClass($class) && null === $owner) {
            return [];
        }
        if ($this->isValue($class, $module) || $this->isThrowable($class)) {
            return [];
        }
        return [
            RuleErrorBuilder::message(sprintf(
                'Module "%s" creates %s with new. A module creates only value objects (its Value\\ namespace, WPMCP\\Api\\V1\\Value), exceptions, and PHP or WordPress classes; services arrive through the constructor (rule S5).',
                $module->id,
                $class,
            ))->identifier('wpmcp.foreignNew')
                ->tip('Add a typed constructor parameter; the compiled container resolves it.')
                ->build(),
        ];
    }

    private function isValue(string $class, ModuleRef $module): bool
    {
        if ('Value' === $module->layerOf($class)) {
            return true;
        }
        foreach ($this->valueNamespaces as $prefix) {
            $prefix = trim($prefix, '\\') . '\\';
            if (0 === strncasecmp($class, $prefix, strlen($prefix))) {
                return true;
            }
        }
        return false;
    }

    private function isThrowable(string $class): bool
    {
        if (! $this->reflectionProvider->hasClass($class)) {
            return false;
        }
        $reflection = $this->reflectionProvider->getClass($class);
        return $reflection->implementsInterface(\Throwable::class);
    }
}
