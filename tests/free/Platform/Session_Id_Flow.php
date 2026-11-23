<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Platform;

/**
 * Follows an ability's input array through the plugin's code to find where
 * the caller's session id is read (issue #1255).
 *
 * A method reads it when its body indexes its input parameter with
 * 'session_id' ($args['session_id'], including inside a closure or a
 * Safe_Mutation::run() context), or when it passes that parameter whole to
 * a method that does: through self::, static::, parent::, $this-> (private
 * parent methods included), a typed property ($this->store->write($args)),
 * a new object ((new Create_Comment())->insert($args)) or a static call on a
 * class the file names (Element_Tree::write(..., $args)). Only code under
 * the given roots is followed (src/ and pro/src/ for the guard).
 *
 * Read from source with reflection, so nothing runs. A path it cannot follow
 * (the array copied to another variable first, a call through an interface
 * or a local object) is missed, never invented.
 */
final class Session_Id_Flow
{
    private const MAX_DEPTH = 8;

    /** A call head: Name::method(, (new Name(...))->method(, $this->prop->method( or $this->method(. */
    private const CALL = '/(?<static>\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)::(?<static_method>[A-Za-z_][A-Za-z0-9_]*)\s*\(|\(\s*new\s+(?<new>\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)\s*(?:\([^()]*\))?\s*\)->(?<new_method>[A-Za-z_][A-Za-z0-9_]*)\s*\(|\$this->(?<prop>[A-Za-z_][A-Za-z0-9_]*)->(?<prop_method>[A-Za-z_][A-Za-z0-9_]*)\s*\(|\$this->(?<own_method>[A-Za-z_][A-Za-z0-9_]*)\s*\(/';

    /** @var array<string, ?string> "Class::method#index" => path, or null when it does not read the id. */
    private array $memo = [];

    /** @var array<string, array{namespace: string, uses: array<string, string>}> */
    private array $files = [];

    /** @param list<string> $roots absolute directories whose code is followed */
    public function __construct(private readonly array $roots)
    {
    }

    /**
     * How $class::$method reaches a read of the session id from its
     * parameter at $index, as "A::handle > B::write", or null.
     */
    public function path(string $class, string $method, int $index = 0): ?string
    {
        return $this->follow($class, $method, $index, 0);
    }

    private function follow(string $class, string $method, int $index, int $depth): ?string
    {
        $key = $class . '::' . $method . '#' . $index;
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }
        // Marked first, so a cycle ends here instead of recursing forever.
        $this->memo[$key] = null;

        $ref = $depth > self::MAX_DEPTH ? null : self::method($class, $method);
        if (null === $ref) {
            return null;
        }
        $file = (string) $ref->getFileName();
        if (! $this->followed($file)) {
            return null;
        }
        $param = $ref->getParameters()[ $index ] ?? null;
        if (null === $param) {
            return null;
        }

        $here = $ref->getDeclaringClass()->getShortName() . '::' . $method;
        $var  = '$' . $param->getName();
        $body = $this->body($ref);
        if (1 === preg_match('/' . preg_quote($var, '/') . '\s*\[\s*([\'"])session_id\1\s*\]/', $body)) {
            return $this->memo[ $key ] = $here;
        }

        foreach ($this->calls_passing($body, $var) as [ $target, $callee, $position ]) {
            $callee_class = $this->resolve($target, $class, $ref);
            if (null === $callee_class) {
                continue;
            }
            $callee_index = $this->position($callee_class, $callee, $position);
            if (null === $callee_index) {
                continue;
            }
            $tail = $this->follow($callee_class, $callee, $callee_index, $depth + 1);
            if (null !== $tail) {
                return $this->memo[ $key ] = $here . ' > ' . $tail;
            }
        }

        return null;
    }

    private function followed(string $file): bool
    {
        foreach ($this->roots as $root) {
            if (str_starts_with($file, rtrim($root, '/') . '/')) {
                return true;
            }
        }
        return false;
    }

    private function body(\ReflectionMethod $ref): string
    {
        $lines = file((string) $ref->getFileName());
        if (false === $lines) {
            return '';
        }
        $start = (int) $ref->getStartLine();
        $end   = (int) $ref->getEndLine();
        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }

    /**
     * Calls in $body that pass $var whole, as [target, method, position]:
     * target is "self", "static", "parent", "$this", "$this->prop" or a
     * class name as written; position is an argument index or a named
     * argument's name.
     *
     * @return list<array{0: string, 1: string, 2: int|string}>
     */
    private function calls_passing(string $body, string $var): array
    {
        $calls = [];
        if (false === preg_match_all(self::CALL, $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }
        foreach ($matches as $match) {
            $open = (int) $match[0][1] + strlen((string) $match[0][0]) - 1;
            if (isset($match['own_method']) && '' !== $match['own_method'][0]) {
                $target = '$this';
                $method = (string) $match['own_method'][0];
            } elseif (isset($match['new']) && '' !== $match['new'][0]) {
                $target = (string) $match['new'][0];
                $method = (string) $match['new_method'][0];
            } elseif (isset($match['prop']) && '' !== $match['prop'][0]) {
                $target = '$this->' . (string) $match['prop'][0];
                $method = (string) $match['prop_method'][0];
            } else {
                $target = (string) $match['static'][0];
                $method = (string) $match['static_method'][0];
            }
            foreach ($this->arguments($body, $open) as $position => $argument) {
                $passes = $this->passes($argument, $var);
                if (null !== $passes) {
                    $calls[] = [ $target, $method, $passes === '' ? $position : $passes ];
                }
            }
        }
        return $calls;
    }

    /**
     * Whether one argument passes $var whole: '' for a positional argument,
     * the parameter name for a named one, null when it does not pass it.
     */
    private function passes(string $argument, string $var): ?string
    {
        $argument = trim($argument);
        $named    = '';
        if (1 === preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*:(?!:)\s*(.*)$/s', $argument, $parts)) {
            $named    = $parts[1];
            $argument = trim($parts[2]);
        }
        $quoted = preg_quote($var, '/');
        if ($argument === $var || 1 === preg_match('/^' . $quoted . '\s*\+/', $argument) || 1 === preg_match('/^array_merge\(\s*' . $quoted . '\s*[,)]/', $argument)) {
            return $named;
        }
        return null;
    }

    /**
     * The top-level arguments of the call whose "(" is at $open.
     *
     * @return list<string>
     */
    private function arguments(string $body, int $open): array
    {
        $arguments = [];
        $current   = '';
        $depth     = 0;
        $length    = strlen($body);
        for ($i = $open + 1; $i < $length; $i++) {
            $char = $body[ $i ];
            if ("'" === $char || '"' === $char) {
                $end      = $this->string_end($body, $i, $char);
                $current .= substr($body, $i, $end - $i + 1);
                $i        = $end;
                continue;
            }
            if ('(' === $char || '[' === $char || '{' === $char) {
                $depth++;
            } elseif (')' === $char || ']' === $char || '}' === $char) {
                if (0 === $depth) {
                    $arguments[] = $current;
                    return $arguments;
                }
                $depth--;
            } elseif (',' === $char && 0 === $depth) {
                $arguments[] = $current;
                $current     = '';
                continue;
            }
            $current .= $char;
        }
        return $arguments;
    }

    private function string_end(string $body, int $start, string $quote): int
    {
        $length = strlen($body);
        for ($i = $start + 1; $i < $length; $i++) {
            if ('\\' === $body[ $i ]) {
                $i++;
                continue;
            }
            if ($quote === $body[ $i ]) {
                return $i;
            }
        }
        return $length - 1;
    }

    /** The class a call target names, or null when it cannot be told. */
    private function resolve(string $target, string $class, \ReflectionMethod $from): ?string
    {
        $declaring = $from->getDeclaringClass();
        $lower     = strtolower($target);
        if ('$this' === $target || 'static' === $lower) {
            return $class;
        }
        if ('self' === $lower) {
            return $declaring->getName();
        }
        if ('parent' === $lower) {
            $parent = $declaring->getParentClass();
            return false === $parent ? null : $parent->getName();
        }
        if (str_starts_with($target, '$this->')) {
            return $this->property_class($class, substr($target, 7));
        }

        $name = $this->qualify($target, (string) $declaring->getFileName(), $declaring->getNamespaceName());
        return class_exists($name) ? $name : null;
    }

    private function property_class(string $class, string $property): ?string
    {
        if (! property_exists($class, $property)) {
            return null;
        }
        $type = (new \ReflectionProperty($class, $property))->getType();
        if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }
        $name = $type->getName();
        return class_exists($name) ? $name : null;
    }

    private function qualify(string $name, string $file, string $namespace): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        $uses  = $this->file_info($file)['uses'];
        $first = strtolower(explode('\\', $name)[0]);
        if (isset($uses[ $first ])) {
            $rest = substr($name, strlen($first));
            return $uses[ $first ] . $rest;
        }
        return ('' === $namespace ? '' : $namespace . '\\') . $name;
    }

    /** @return array{namespace: string, uses: array<string, string>} */
    private function file_info(string $file): array
    {
        if (isset($this->files[ $file ])) {
            return $this->files[ $file ];
        }
        $source = (string) file_get_contents($file);
        $uses   = [];
        if (false !== preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/m', $source, $found, PREG_SET_ORDER)) {
            foreach ($found as $use) {
                $fqn   = ltrim($use[1], '\\');
                $alias = isset($use[2]) && '' !== $use[2] ? $use[2] : substr((string) strrchr('\\' . $fqn, '\\'), 1);
                $uses[ strtolower($alias) ] = $fqn;
            }
        }
        $namespace = 1 === preg_match('/^namespace\s+([^;]+);/m', $source, $ns) ? trim($ns[1]) : '';
        return $this->files[ $file ] = [ 'namespace' => $namespace, 'uses' => $uses ];
    }

    /** The parameter index a positional index or a named argument maps to on the callee. */
    private function position(string $class, string $method, int|string $position): ?int
    {
        if (is_int($position)) {
            return $position;
        }
        $ref = self::method($class, $method);
        if (null === $ref) {
            return null;
        }
        foreach ($ref->getParameters() as $param) {
            if ($param->getName() === $position) {
                return $param->getPosition();
            }
        }
        return null;
    }

    /**
     * The method as reflection sees it. Unlike method_exists(), this finds a
     * private method of a parent class, which $this-> calls from that parent
     * reach.
     */
    private static function method(string $class, string $method): ?\ReflectionMethod
    {
        try {
            return new \ReflectionMethod($class, $method);
        } catch (\ReflectionException) {
            return null;
        }
    }
}
