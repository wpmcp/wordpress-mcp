<?php

declare(strict_types=1);

namespace WPMCP\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Rule S7, gate G4: no static helpers in module code.
 *
 * In a module's code (ModuleMap::moduleOfScope), a static method call
 * `X::m()` (first-class callables `X::m(...)` included) is reported as
 * wpmcp.staticCall when X is a WPMCP class (the module's own classes, the
 * kernel, legacy WPMCP code such as Plugin::instance() or a pack's
 * operations()), unless:
 *
 * - X is self, static or parent;
 * - X is an enum (Op::from(), Tier::cases(), an enum's own static methods);
 * - X is in the module's own Value\ namespace or a configured value
 *   namespace (WPMCP\Api\V1\Value\ by default): value-object named
 *   constructors. Every static method of such a class counts as one;
 * - `X::m` matches the allowlist (parameter wpmcp.staticCallAllowlist):
 *   fnmatch patterns `Class::method`, case-insensitive, `*` allowed; a
 *   pattern without `::` allows every method of the class. It exists only
 *   for grandfathered legacy packs and may only shrink.
 *
 * Not reported: PHP builtin classes, WordPress core classes, host plugin and
 * vendor classes (those are LayerRule's: allowed in Adapter/ only), another
 * module's class (NoCrossModuleReferenceRule), and a call on a dynamic class
 * (`$class::m()`), whose target is unknown here.
 *
 * @implements Rule<StaticCall>
 */
final class NoStaticHelperCallRule implements Rule
{
    /**
     * @param list<string> $valueNamespaces
     * @param list<string> $allowlist
     */
    public function __construct(
        private readonly ModuleMap $modules,
        private readonly SymbolOrigin $origin,
        private readonly ReflectionProvider $reflectionProvider,
        private readonly array $valueNamespaces,
        private readonly array $allowlist,
    ) {
    }

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->class instanceof Node\Name || $node->class->isSpecialClassName()) {
            return [];
        }
        $module = $this->modules->moduleOfScope($scope);
        if (null === $module) {
            return [];
        }
        $class = $scope->resolveName($node->class);
        $method = $node->name instanceof Node\Identifier ? $node->name->toString() : '*';
        $owner = $this->modules->moduleOfClass($class);
        if (null !== $owner && $owner->id !== $module->id) {
            return [];
        }
        if (null === $owner && SymbolOrigin::WPMCP !== $this->origin->ofClass($class)) {
            return [];
        }
        if ($this->isEnum($class) || $this->isValue($class, $module) || $this->allowed($class, $method)) {
            return [];
        }
        return [
            RuleErrorBuilder::message(sprintf(
                'Module "%s" calls %s::%s() statically. Module code calls static methods only on self or static, enums and value objects; inject a service instead (rule S7).',
                $module->id,
                $class,
                $method,
            ))->identifier('wpmcp.staticCall')
                ->tip('Make it an instance method of a service the constructor receives.')
                ->build(),
        ];
    }

    private function isEnum(string $class): bool
    {
        return $this->reflectionProvider->hasClass($class) && $this->reflectionProvider->getClass($class)->isEnum();
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

    private function allowed(string $class, string $method): bool
    {
        $subject = strtolower(ltrim($class, '\\') . '::' . $method);
        foreach ($this->allowlist as $pattern) {
            $pattern = strtolower(ltrim($pattern, '\\'));
            if (! str_contains($pattern, '::')) {
                $pattern .= '::*';
            }
            if (fnmatch($pattern, $subject, FNM_NOESCAPE)) {
                return true;
            }
        }
        return false;
    }
}
