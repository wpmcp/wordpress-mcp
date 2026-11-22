<?php

declare(strict_types=1);

namespace WPMCP\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Finds every class a piece of code names, with its resolved name:
 * `new X`, `X::m()`, `X::$p`, `X::C`, `X::class`, `instanceof X`, parameter,
 * return, property and class constant types (nullable, union and
 * intersection types included), `catch (X)`, attribute names (and, since
 * the walk covers attribute arguments, any `new` or `X::class` inside them),
 * `extends`, `implements` and `use Trait`.
 *
 * Not covered: docblock types, strings holding class names, dynamic names
 * (`new $x`, `$x::m()`). self, static and parent are skipped. Names are the
 * ones PHPStan's parser resolved, so `use` aliases are already expanded.
 */
final class NameCollector
{
    private readonly NodeFinder $finder;

    public function __construct()
    {
        $this->finder = new NodeFinder();
    }

    /**
     * @param Node|array<Node> $nodes
     * @return list<ClassReference>
     */
    public function classes(Node|array $nodes): array
    {
        $out = [];
        foreach ($this->finder->find($nodes, static fn (Node $n): bool => true) as $node) {
            foreach ($this->namesIn($node) as [$name, $kind]) {
                $class = $this->resolved($name);
                if (null !== $class) {
                    $out[] = new ClassReference($class, $name->getStartLine(), $kind);
                }
            }
        }
        return $out;
    }

    /**
     * Function calls by a static name (`foo()`, not `$f()`).
     *
     * @param Node|array<Node> $nodes
     * @return list<Node\Name>
     */
    public function functionNames(Node|array $nodes): array
    {
        $out = [];
        foreach ($this->finder->findInstanceOf($nodes, Expr\FuncCall::class) as $call) {
            if ($call->name instanceof Node\Name) {
                $out[] = $call->name;
            }
        }
        return $out;
    }

    /** @return list<array{Node\Name, string}> */
    private function namesIn(Node $node): array
    {
        if ($node instanceof Expr\New_) {
            return $this->one($node->class, 'new');
        }
        if ($node instanceof Expr\StaticCall) {
            return $this->one($node->class, 'static call');
        }
        if ($node instanceof Expr\StaticPropertyFetch) {
            return $this->one($node->class, 'static property');
        }
        if ($node instanceof Expr\ClassConstFetch) {
            $kind = $node->name instanceof Node\Identifier && 'class' === $node->name->toLowerString() ? '::class' : 'class constant';
            return $this->one($node->class, $kind);
        }
        if ($node instanceof Expr\Instanceof_) {
            return $this->one($node->class, 'instanceof');
        }
        if ($node instanceof Node\Param) {
            return $this->types($node->type, 'parameter type');
        }
        if ($node instanceof Node\FunctionLike) {
            return $this->types($node->getReturnType(), 'return type');
        }
        if ($node instanceof Stmt\Property) {
            return $this->types($node->type, 'property type');
        }
        if ($node instanceof Stmt\ClassConst) {
            return $this->types($node->type, 'constant type');
        }
        if ($node instanceof Stmt\Catch_) {
            return $this->each($node->types, 'catch');
        }
        if ($node instanceof Node\Attribute) {
            return [[$node->name, 'attribute']];
        }
        if ($node instanceof Stmt\Class_) {
            $out = null === $node->extends ? [] : [[$node->extends, 'extends']];
            foreach ($node->implements as $name) {
                $out[] = [$name, 'implements'];
            }
            return $out;
        }
        if ($node instanceof Stmt\Interface_) {
            return $this->each($node->extends, 'extends');
        }
        if ($node instanceof Stmt\Enum_) {
            return $this->each($node->implements, 'implements');
        }
        if ($node instanceof Stmt\TraitUse) {
            return $this->each($node->traits, 'trait');
        }
        return [];
    }

    /**
     * @param array<Node\Name> $names
     * @return list<array{Node\Name, string}>
     */
    private function each(array $names, string $kind): array
    {
        $out = [];
        foreach ($names as $name) {
            $out[] = [$name, $kind];
        }
        return $out;
    }

    /** @return list<array{Node\Name, string}> */
    private function one(Node $class, string $kind): array
    {
        return $class instanceof Node\Name ? [[$class, $kind]] : [];
    }

    /** @return list<array{Node\Name, string}> */
    private function types(?Node $type, string $kind): array
    {
        if ($type instanceof Node\Name) {
            return [[$type, $kind]];
        }
        if ($type instanceof Node\NullableType) {
            return $this->types($type->type, $kind);
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $out = [];
            foreach ($type->types as $inner) {
                array_push($out, ...$this->types($inner, $kind));
            }
            return $out;
        }
        return [];
    }

    private function resolved(Node\Name $name): ?string
    {
        if ($name->isSpecialClassName()) {
            return null;
        }
        if ($name->isFullyQualified()) {
            return $name->toString();
        }
        $resolved = $name->getAttribute('resolvedName');
        return $resolved instanceof Node\Name ? $resolved->toString() : $name->toString();
    }
}
