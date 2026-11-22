<?php

declare(strict_types=1);

namespace WPMCP\Registry;

/**
 * The rules that fail a build: unique names (modules, abilities, opt-in
 * filters, operations per dispatcher), valid names, flavors and schemas,
 * the per-tool description cap, labels for every opt-in gate, known
 * dispatchers, bound interfaces, single ownership of owned files, and the
 * tools/list size estimate per flavor.
 */
final class Validator
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config, private readonly string $repo_root)
    {
    }

    /**
     * @param array<string, mixed> $model
     * @return list<string>
     */
    public function validate(array $model, bool $addon): array
    {
        $errors = [];
        $flavors = array_keys(Flavors::all());

        foreach ($model['modules'] as $id => $module) {
            foreach (array_merge($module['flavors'], $module['exclude_flavors']) as $flavor) {
                if (! in_array($flavor, $flavors, true)) {
                    $errors[] = sprintf('Module %s names unknown flavor %s (known: %s).', $id, $flavor, implode(', ', $flavors));
                }
            }
        }

        $cap = (int) ($this->config['description_cap'] ?? 300);
        $long = (array) ($this->config['long_descriptions'] ?? []);
        $seen = [];
        foreach ($model['abilities'] as $a) {
            $name = (string) $a['name'];
            if (1 !== preg_match('#^[a-z0-9][a-z0-9-]*/[a-z0-9][a-z0-9-]*$#', $name)) {
                $errors[] = sprintf('Ability name "%s" is not vendor/name in lowercase letters, digits and hyphens.', $name);
            }
            if ($addon && str_starts_with($name, 'wpmcp/')) {
                $errors[] = sprintf('Ability name %s uses the reserved wpmcp/ prefix; add-on abilities use <vendor>/<name>.', $name);
            }
            if (isset($seen[ $name ])) {
                $errors[] = sprintf('Ability name %s is declared twice (%s and %s).', $name, $seen[ $name ], $a['class']);
            }
            $seen[ $name ] = $a['class'];
            foreach ($a['exclude_flavors'] as $flavor) {
                if (! in_array($flavor, $flavors, true)) {
                    $errors[] = sprintf('Ability %s names unknown flavor %s.', $name, $flavor);
                }
            }
            $bytes = strlen((string) $a['description']);
            if ('' === trim((string) $a['description'])) {
                $errors[] = sprintf('Ability %s has no description.', $name);
            } elseif ($bytes > $cap && ! in_array($name, $long, true)) {
                $errors[] = sprintf('Ability %s description is %d bytes, over the %d byte cap (move detail to get-tool-schema or docs).', $name, $bytes, $cap);
            }
            foreach (Schema_Rules::check((array) $a['input'], "Ability {$name}") as $problem) {
                $errors[] = $problem;
            }
        }

        $gates = [];
        foreach ($model['gate_rows'] as $g) {
            $filter = (string) $g['filter'];
            if (isset($gates[ $filter ])) {
                $errors[] = sprintf('Opt-in filter %s is declared twice (%s and %s).', $filter, $gates[ $filter ], $g['class']);
                continue;
            }
            $gates[ $filter ] = $g['class'];
            $label = null;
            if (is_string($g['labels']) && is_callable([$g['class'], $g['labels']])) {
                try {
                    $labels = call_user_func([$g['class'], $g['labels']]);
                    $label = is_array($labels) ? ($labels[ $filter ] ?? null) : null;
                } catch (\Throwable $e) {
                    $errors[] = sprintf('Opt-in labels %s::%s failed without WordPress: %s', $g['class'], $g['labels'], $e->getMessage());
                }
            }
            if (! is_string($label) || '' === trim($label)) {
                $errors[] = sprintf('Opt-in filter %s has no label: name a static labels method on %s returning [filter => __(...)].', $filter, $g['class']);
            }
        }

        $known = array_merge(array_keys($model['dispatchers']), (array) ($this->config['legacy_dispatchers'] ?? []));
        $allowed_dups = (array) ($this->config['duplicate_ops'] ?? []);
        $ops = [];
        foreach ($model['contributors'] as $c) {
            $dispatcher = (string) $c['dispatcher'];
            if (! in_array($dispatcher, $known, true)) {
                $errors[] = sprintf('Operations on %s name unknown dispatcher %s.', $c['class'], $dispatcher);
            }
            foreach (array_keys($c['ops']) as $op) {
                $where = $c['class'] . '::' . $c['method'];
                if (isset($ops[ $dispatcher ][ $op ]) && ! in_array("{$dispatcher}:{$op}", $allowed_dups, true)) {
                    $errors[] = sprintf('Operation %s on dispatcher %s is contributed twice (%s and %s); today array_merge would let the later one win.', $op, $dispatcher, $ops[ $dispatcher ][ $op ], $where);
                }
                $ops[ $dispatcher ][ $op ] = $where;
            }
        }

        $bound = array_keys($model['providers']);
        foreach ($model['factories'] as $class => $deps) {
            foreach ($deps as $dep) {
                if (interface_exists($dep) && ! in_array($dep, $bound, true) && ! str_starts_with($dep, 'WPMCP\\Kernel\\') && ! str_starts_with($dep, 'WPMCP\\Api\\V1\\Contract\\Plugin_Presence')) {
                    $errors[] = sprintf('%s needs %s, but no class declares #[Provides(%s::class)].', $class, $dep, substr((string) strrchr('\\' . $dep, '\\'), 1));
                }
            }
        }

        $owned = [];
        foreach ($model['modules'] as $id => $module) {
            foreach ($module['owns'] as $glob) {
                foreach ($this->expand((string) $glob) as $file) {
                    if (isset($owned[ $file ]) && $owned[ $file ] !== $id) {
                        $errors[] = sprintf('%s is owned by both %s and %s.', $file, $owned[ $file ], $id);
                    }
                    $owned[ $file ] = $id;
                }
            }
        }

        return $errors;
    }

    /**
     * The tools/list byte estimate of the given abilities: name, description
     * and input schema per tool, JSON encoded as the wire sends them.
     *
     * @param list<array<string, mixed>> $abilities
     */
    public static function tools_list_bytes(array $abilities): int
    {
        $bytes = 0;
        foreach ($abilities as $a) {
            $bytes += self::tool_bytes($a);
        }
        return $bytes;
    }

    /** @param array<string, mixed> $a */
    public static function tool_bytes(array $a): int
    {
        return strlen((string) json_encode(
            ['name' => str_replace('/', '-', (string) $a['name']), 'description' => $a['description'], 'inputSchema' => $a['input']],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }

    /** @return list<string> repository-relative files a glob matches */
    private function expand(string $glob): array
    {
        $root = rtrim($this->repo_root, '/');
        $cut = strcspn($glob, '*?[');
        $static = dirname(substr($glob, 0, $cut) . 'x');
        $dir = $root . '/' . $static;
        if (! is_dir($dir)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $rel = substr($file->getPathname(), strlen($root) + 1);
            if ($file->isFile() && fnmatch($glob, $rel)) {
                $out[] = $rel;
            }
        }
        sort($out);
        return $out;
    }
}
