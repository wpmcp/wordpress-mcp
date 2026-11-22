<?php

declare(strict_types=1);

namespace WPMCP\Registry;

use WPMCP\Api\V1\Attribute;
use WPMCP\Api\V1\Value\Context;
use WPMCP\Api\V1\Value\Op;
use WPMCP\Api\V1\Value\Requires;
use WPMCP\Api\V1\Value\Tier;

/**
 * Reflects the attributed classes the Scanner found and compiles them into
 * a flavor-independent model: plain arrays only (enums become their
 * values, Requires its array form, closures are dropped). It runs the pure
 * static *_factory, hosts and Operation_Provider methods for their scalar
 * metadata. Problems are collected as errors, never thrown, so one run
 * reports all of them.
 */
final class Compiler
{
    private const KERNEL_SERVICES = [
        'WPMCP\\Kernel\\Registry', 'WPMCP\\Kernel\\Container', 'WPMCP\\Kernel\\Presence',
        'WPMCP\\Kernel\\Request_Context', 'WPMCP\\Kernel\\Operation_Registry', 'WPMCP\\Kernel\\Hook_Loader',
        'WPMCP\\Kernel\\Ability_Loader', 'WPMCP\\Api\\V1\\Contract\\Plugin_Presence',
    ];

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $notes = [];

    /** @var array<string, array<string, mixed>> */
    private array $modules = [];

    /** @var array<string, string> class => module id */
    private array $owner = [];

    /** @var array<string, mixed> */
    private array $model = [];

    private int $seq = 0;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly string $repo_root, private readonly array $config = [])
    {
    }

    /**
     * @param array<class-string, string> $classes class => file, from Scanner
     * @return array<string, mixed>
     */
    public function compile(array $classes): array
    {
        $this->model = [
            'modules' => [], 'abilities' => [], 'contributors' => [], 'hooks' => [], 'opt_in_gates' => [],
            'gate_rows' => [], 'providers' => [], 'snapshot_types' => [], 'table_kinds' => [], 'families' => [],
            'dispatchers' => [], 'admin_pages' => [], 'ajax' => [], 'admin_post' => [], 'direct_writes' => [],
            'factories' => [], 'files' => [],
        ];
        $reflections = [];
        foreach ($classes as $class => $file) {
            $ref = $this->reflect($class, $file);
            if (null !== $ref) {
                $reflections[ $class ] = $ref;
                $this->model['files'][ $this->relative($file) ] = sha1_file($file) ?: '';
            }
        }

        // Pass 1: modules.
        foreach ($reflections as $class => $ref) {
            foreach ($this->attributes($ref, Attribute\Module::class) as $module) {
                $this->module($ref, $module);
            }
        }
        // Pass 2: ownership of every other attributed class.
        foreach ($reflections as $class => $ref) {
            if ([] !== $ref->getAttributes(Attribute\Module::class)) {
                $this->owner[ $class ] = $this->module_of_module_class($class);
                continue;
            }
            $owner = $this->find_owner($ref);
            if (null === $owner) {
                $this->errors[] = sprintf('Class %s carries kernel attributes but belongs to no module (no module namespace contains it and no owns: glob matches %s).', $class, $this->relative((string) $ref->getFileName()));
                continue;
            }
            $this->owner[ $class ] = $owner;
        }
        // Pass 3: everything else.
        foreach ($reflections as $class => $ref) {
            if (! isset($this->owner[ $class ])) {
                continue;
            }
            $this->rule_seven($ref);
            $this->class_attributes($ref, $this->owner[ $class ]);
            $this->method_attributes($ref, $this->owner[ $class ]);
        }
        $this->skills();
        $this->model['modules'] = $this->modules;

        return $this->model;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return list<string> */
    public function notes(): array
    {
        return $this->notes;
    }

    /** @return \ReflectionClass<object>|null */
    private function reflect(string $class, string $file): ?\ReflectionClass
    {
        try {
            if (! class_exists($class, false)) {
                require_once $file;
            }
            if (! class_exists($class, false)) {
                $this->errors[] = sprintf('%s declares %s, but loading it did not define the class.', $this->relative($file), $class);
                return null;
            }
            return new \ReflectionClass($class);
        } catch (\Throwable $e) {
            $this->errors[] = sprintf('Loading %s failed without WordPress: %s', $class, $e->getMessage());
            return null;
        }
    }

    /**
     * @template T of object
     * @param \ReflectionClass<object>|\ReflectionMethod $ref
     * @param class-string<T> $attribute
     * @return list<T>
     */
    private function attributes(\ReflectionClass|\ReflectionMethod $ref, string $attribute): array
    {
        $out = [];
        foreach ($ref->getAttributes($attribute) as $attr) {
            try {
                $out[] = $attr->newInstance();
            } catch (\Throwable $e) {
                $name = $ref instanceof \ReflectionMethod ? $ref->class . '::' . $ref->getName() : $ref->getName();
                $this->errors[] = sprintf('Attribute %s on %s is invalid: %s', self::short($attribute), $name, $e->getMessage());
            }
        }
        return $out;
    }

    /** @param \ReflectionClass<object> $ref */
    private function module(\ReflectionClass $ref, Attribute\Module $m): void
    {
        $class = $ref->getName();
        if (1 !== preg_match('/^[a-z0-9][a-z0-9-]*$/', $m->id)) {
            $this->errors[] = sprintf('Module id "%s" on %s must be lowercase letters, digits and hyphens.', $m->id, $class);
            return;
        }
        if (isset($this->modules[ $m->id ])) {
            $this->errors[] = sprintf('Module id %s is declared twice (%s and %s).', $m->id, $this->modules[ $m->id ]['class'], $class);
            return;
        }
        $tier = $this->tier($m->tier, "module {$m->id}");
        $contexts = $this->contexts($m->contexts, "module {$m->id}");
        $requires = $this->requires($m->requires, "module {$m->id}");
        $data = null;
        foreach ($this->attributes($ref, Attribute\Owns_Data::class) as $owns) {
            $data = ['options' => $owns->options, 'tables' => $owns->tables, 'cron' => $owns->cron, 'post_types' => $owns->post_types, 'user_meta' => $owns->user_meta];
        }
        $features = array_map(static fn (Attribute\Feature $f): string => $f->text, $this->attributes($ref, Attribute\Feature::class));
        if (null !== $m->availability && ! $this->static_method($ref, $m->availability)) {
            $this->errors[] = sprintf('Module %s names availability method %s, which is not a static method of %s.', $m->id, $m->availability, $class);
        }

        $this->modules[ $m->id ] = [
            'id' => $m->id,
            'class' => $class,
            'namespace' => $ref->getNamespaceName() . '\\',
            'dir' => $this->relative(dirname((string) $ref->getFileName())),
            'group' => '' === $m->group ? $m->id : $m->group,
            'order' => $m->order,
            'tier' => $tier,
            'flavors' => array_values($m->flavors),
            'exclude_flavors' => array_values($m->exclude_flavors),
            'requires' => $requires,
            'owns' => array_values($m->owns),
            'test_plugins' => array_values($m->test_plugins),
            'contexts' => $contexts,
            'availability' => $m->availability,
            'boot' => $ref->implementsInterface(\WPMCP\Api\V1\Contract\Has_Boot::class) ? $class : null,
            'features' => array_values($features),
            'data' => $data,
        ];
    }

    private function module_of_module_class(string $class): string
    {
        foreach ($this->modules as $id => $module) {
            if ($module['class'] === $class) {
                return $id;
            }
        }
        return '';
    }

    /** @param \ReflectionClass<object> $ref */
    private function find_owner(\ReflectionClass $ref): ?string
    {
        $class = $ref->getName();
        $best = null;
        $best_len = -1;
        foreach ($this->modules as $id => $module) {
            $ns = (string) $module['namespace'];
            if (str_starts_with($class, $ns) && strlen($ns) > $best_len) {
                $best = $id;
                $best_len = strlen($ns);
            }
        }
        if (null !== $best) {
            return $best;
        }
        $file = $this->relative((string) $ref->getFileName());
        $matches = [];
        foreach ($this->modules as $id => $module) {
            foreach ((array) $module['owns'] as $glob) {
                if (fnmatch((string) $glob, $file)) {
                    $matches[ $id ] = true;
                }
            }
        }
        if (count($matches) > 1) {
            $this->errors[] = sprintf('%s is owned by several modules (%s).', $file, implode(', ', array_keys($matches)));
        }
        return [] === $matches ? null : (string) array_key_first($matches);
    }

    /** @param \ReflectionClass<object> $ref */
    private function rule_seven(\ReflectionClass $ref): void
    {
        $parent = $ref->getParentClass();
        if (false !== $parent && ! str_starts_with($parent->getName(), 'WPMCP\\')) {
            $this->errors[] = sprintf('%s extends %s; attributed classes may extend only kernel or WPMCP classes (rule 7). Reach it through a factory instead.', $ref->getName(), $parent->getName());
        }
    }

    /** @param \ReflectionClass<object> $ref */
    private function class_attributes(\ReflectionClass $ref, string $module): void
    {
        $class = $ref->getName();
        foreach ($this->attributes($ref, Attribute\Ability::class) as $a) {
            $this->ability($ref, $a, $module);
        }
        foreach ($this->attributes($ref, Attribute\Operations::class) as $o) {
            $this->contributor($ref, $o, $module);
        }
        foreach ($this->attributes($ref, Attribute\Not_Object::class) as $n) {
            foreach ($this->model['contributors'] as $i => $row) {
                if ($row['class'] === $class) {
                    $this->model['contributors'][ $i ]['not_objects'][ $n->key ] = $n->reason;
                }
            }
        }
        foreach ($this->attributes($ref, Attribute\Opt_In_Gate::class) as $g) {
            $this->model['gate_rows'][] = ['filter' => $g->filter, 'module' => $module, 'class' => $class, 'destructive' => $g->destructive, 'labels' => $g->labels, 'group' => $g->group];
        }
        foreach ($this->attributes($ref, Attribute\Dispatcher::class) as $d) {
            if (isset($this->model['dispatchers'][ $d->slug ])) {
                $this->errors[] = sprintf('Dispatcher %s is declared twice.', $d->slug);
                continue;
            }
            $this->model['dispatchers'][ $d->slug ] = ['module' => $module, 'class' => $class, 'registers_only_when_available' => $d->registers_only_when_available];
            $this->factory($class);
        }
        foreach ($this->attributes($ref, Attribute\Provides::class) as $p) {
            if (! interface_exists($p->interface) || ! $ref->implementsInterface($p->interface)) {
                $this->errors[] = sprintf('%s provides %s but does not implement that interface.', $class, $p->interface);
                continue;
            }
            $this->model['providers'][ $p->interface ][] = ['module' => $module, 'class' => $class, 'key' => $p->key, 'priority' => $p->priority];
            $this->factory($class);
        }
        foreach ($this->attributes($ref, Attribute\Snapshot_Type::class) as $s) {
            if (! $ref->implementsInterface(\WPMCP\Api\V1\Contract\Snapshot_Restorer::class)) {
                $this->errors[] = sprintf('%s declares snapshot type %s but does not implement Snapshot_Restorer.', $class, $s->type);
            }
            if (isset($this->model['snapshot_types'][ $s->type ])) {
                $this->errors[] = sprintf('Snapshot type %s is declared twice.', $s->type);
                continue;
            }
            $this->model['snapshot_types'][ $s->type ] = ['module' => $module, 'class' => $class, 'capability' => $s->capability];
            $this->factory($class);
        }
        foreach ($this->attributes($ref, Attribute\Table_Kind::class) as $t) {
            if (isset($this->model['table_kinds'][ $t->kind ])) {
                $this->errors[] = sprintf('Table kind %s is declared twice.', $t->kind);
                continue;
            }
            $this->model['table_kinds'][ $t->kind ] = ['module' => $module, 'class' => $class, 'table' => $t->table, 'key' => $t->key, 'order' => $t->order, 'suffixes' => $t->suffixes, 'binary' => $t->binary, 'companions' => $t->companions, 'capability' => $t->capability, 'after_restore' => $t->after_restore];
        }
        foreach ($this->attributes($ref, Attribute\Family_Member::class) as $f) {
            $this->model['families'][ $f->family ][] = ['module' => $module, 'class' => $class, 'key' => $f->key, 'label' => $f->label, 'order' => $f->order, 'requires' => $this->requires($f->requires, "family member {$class}")];
            $this->factory($class);
        }
        foreach ($this->attributes($ref, Attribute\Admin_Page::class) as $p) {
            $this->model['admin_pages'][] = ['module' => $module, 'class' => $class, 'slug' => $p->slug, 'parent' => $p->parent, 'capability' => $p->capability, 'position' => $p->position, 'title_method' => $p->title_method, 'method' => $p->method];
            if (! $ref->hasMethod($p->method)) {
                $this->errors[] = sprintf('Admin page %s names method %s, which %s does not have.', $p->slug, $p->method, $class);
            }
            $this->factory($class);
        }
    }

    /** @param \ReflectionClass<object> $ref */
    private function method_attributes(\ReflectionClass $ref, string $module): void
    {
        $class = $ref->getName();
        $contexts = (array) ($this->modules[ $module ]['contexts'] ?? []);
        foreach ($ref->getMethods() as $method) {
            if ($method->class !== $class) {
                continue;
            }
            $static = $method->isStatic();
            $base = ['module' => $module, 'class' => $class, 'method' => $method->getName(), 'static' => $static];
            foreach ($this->attributes($method, Attribute\Hook::class) as $h) {
                $own = $this->contexts($h->contexts, "hook {$h->hook} on {$class}");
                $this->hook($base + ['kind' => 'hook', 'hook' => $h->hook, 'priority' => $h->priority, 'args' => $h->args, 'filter' => $h->filter, 'contexts' => [] === $own ? $contexts : $own]);
            }
            foreach ($this->attributes($method, Attribute\Cron::class) as $c) {
                $this->hook($base + ['kind' => 'cron', 'hook' => $c->hook, 'priority' => 10, 'args' => $c->args, 'filter' => false, 'contexts' => []]);
            }
            foreach ($this->attributes($method, Attribute\On_Rollback::class) as $r) {
                $this->hook($base + ['kind' => 'rollback', 'hook' => $r->hook, 'priority' => $r->priority, 'args' => $r->args, 'filter' => false, 'contexts' => []]);
            }
            foreach ($this->attributes($method, Attribute\Ajax_Handler::class) as $a) {
                $this->model['ajax'][] = $base + ['action' => $a->action, 'nopriv' => $a->nopriv];
                if (! $static) {
                    $this->factory($class);
                }
            }
            foreach ($this->attributes($method, Attribute\Admin_Post_Handler::class) as $a) {
                $this->model['admin_post'][] = $base + ['action' => $a->action];
                if (! $static) {
                    $this->factory($class);
                }
            }
            foreach ($this->attributes($method, Attribute\Allows_Direct_Write::class) as $w) {
                $this->model['direct_writes'][] = ['module' => $module, 'class' => $class, 'method' => $method->getName(), 'reason' => $w->reason];
            }
        }
    }

    /** @param array<string, mixed> $row */
    private function hook(array $row): void
    {
        $row['seq'] = ++$this->seq;
        $this->model['hooks'][] = $row;
        if (! $row['static']) {
            $this->factory((string) $row['class']);
        }
    }

    /** @param \ReflectionClass<object> $ref */
    private function ability(\ReflectionClass $ref, Attribute\Ability $a, string $module): void
    {
        $class = $ref->getName();
        $label = "ability {$a->name}";
        $input = $a->input;
        if (null !== $a->input_factory) {
            $input = $this->run_static($ref, $a->input_factory, $label);
            if (! is_array($input)) {
                $this->errors[] = sprintf('Ability %s input_factory %s::%s must return an array.', $a->name, $class, $a->input_factory);
                $input = [];
            }
        }
        $description = $a->description;
        if (null !== $a->description_factory) {
            $description = $this->run_static($ref, $a->description_factory, $label);
            if (! is_string($description)) {
                $this->errors[] = sprintf('Ability %s description_factory %s::%s must return a string.', $a->name, $class, $a->description_factory);
                $description = '';
            }
        }
        if (! $ref->hasMethod($a->method) || ! $ref->getMethod($a->method)->isPublic() || $ref->getMethod($a->method)->isStatic()) {
            $this->errors[] = sprintf('Handler %s for ability %s has no method %s (public, not static).', $class, $a->name, $a->method);
        }
        foreach (['objects' => $a->objects, 'op_capability' => $a->op_capability] as $kind => $method) {
            if (null !== $method && (! $ref->hasMethod($method) || $ref->getMethod($method)->isStatic())) {
                $this->errors[] = sprintf('Ability %s names %s method %s, which %s does not have as an instance method.', $a->name, $kind, $method, $class);
            }
        }
        if (null !== $a->condition && ! is_callable($a->condition)) {
            $this->errors[] = sprintf('Ability %s condition is not a static callable.', $a->name);
        }
        $tier = null === $a->tier ? null : $this->tier($a->tier, $label);

        $this->model['abilities'][] = [
            'name' => $a->name,
            'module' => $module,
            'tier' => $tier,
            'description' => $description,
            'input' => $input,
            'capability' => $a->capability,
            'domain' => $a->domain,
            'operation' => $a->operation->value,
            'read_only' => $a->read_only,
            'destructive' => $a->destructive,
            'idempotent' => $a->idempotent,
            'read_keys' => array_values($a->read_keys),
            'class' => $class,
            'method' => $a->method,
            'objects' => $a->objects,
            'op_capability' => $a->op_capability,
            'requires' => $this->requires($a->requires, $label),
            'condition' => $a->condition,
            'output' => $a->output,
            'exposure' => $a->exposure->value,
            'default_disabled_since' => $a->default_disabled_since,
            'exclude_flavors' => array_values($a->exclude_flavors),
            'position' => $a->position,
            'seq' => ++$this->seq,
        ];
        $this->factory($class);
    }

    /** @param \ReflectionClass<object> $ref */
    private function contributor(\ReflectionClass $ref, Attribute\Operations $o, string $module): void
    {
        $class = $ref->getName();
        $label = "operations {$class}::{$o->method}";
        if (! $ref->hasMethod($o->method)) {
            $this->errors[] = sprintf('Operations on %s names method %s, which the class does not have.', $class, $o->method);
            return;
        }
        $method = $ref->getMethod($o->method);
        $static = $method->isStatic();
        $ops = [];
        if ($static) {
            $defs = $this->run_static($ref, $o->method, $label);
            foreach (is_array($defs) ? $defs : [] as $name => $def) {
                $ops[ (string) $name ] = self::op_meta(is_array($def) ? $def : []);
            }
        } else {
            $this->notes[] = sprintf('%s is an instance method; its op names are not compiled (make it static or list the ops in the attribute).', $label);
            $this->factory($class);
        }
        $hosts = null;
        if (null !== $o->hosts_method) {
            $hosts = $this->run_static($ref, $o->hosts_method, $label);
            if (! is_array($hosts)) {
                $this->errors[] = sprintf('%s hosts_method %s must return [op => host].', $label, $o->hosts_method);
                $hosts = null;
            }
        }
        if (null !== $o->availability && ! $this->static_method($ref, $o->availability)) {
            $this->errors[] = sprintf('%s names availability %s, which is not a static method of the class.', $label, $o->availability);
        }

        $this->model['contributors'][] = [
            'dispatcher' => $o->dispatcher,
            'module' => $module,
            'class' => $class,
            'method' => $o->method,
            'static' => $static,
            'host' => $o->host,
            'hosts' => $hosts,
            'several' => $o->several,
            'summary' => $o->summary,
            'summary_order' => $o->summary_order ?? $o->order,
            'order' => $o->order,
            'tier' => $this->tier($o->tier, $label),
            'availability' => $o->availability,
            'requires' => $this->requires($o->requires, $label),
            'variant' => $o->variant,
            'prefix' => $o->prefix,
            'not_objects' => $o->not_objects,
            'ops' => $ops,
            'seq' => ++$this->seq,
        ];
    }

    /**
     * @param array<string, mixed> $def
     * @return array<string, mixed>
     */
    private static function op_meta(array $def): array
    {
        $meta = ['mode' => (string) ($def['mode'] ?? '')];
        foreach (['tier', 'capability', 'opt_in_filter'] as $key) {
            if (isset($def[ $key ]) && is_string($def[ $key ])) {
                $meta[ $key ] = $def[ $key ];
            }
        }
        if (array_key_exists('enabled_by_default', $def)) {
            $meta['enabled_by_default'] = (bool) $def['enabled_by_default'];
        }
        if (isset($def['objects']) && is_array($def['objects'])) {
            $meta['objects'] = array_map('strval', array_keys($def['objects']));
        }
        // Args that look like object ids, for the conformance check that
        // each is declared in objects or not_objects (issue #450).
        $props = $def['input_schema']['properties'] ?? null;
        if (is_array($props)) {
            $ids = array_values(array_filter(array_map('strval', array_keys($props)), static fn (string $k): bool => 1 === preg_match('/_ids?$/', $k)));
            if ([] !== $ids) {
                $meta['id_args'] = $ids;
            }
        }
        return $meta;
    }

    /** @param \ReflectionClass<object> $ref */
    private function run_static(\ReflectionClass $ref, string $method, string $label): mixed
    {
        if (! $this->static_method($ref, $method)) {
            $this->errors[] = sprintf('%s: %s::%s is not a public static method.', ucfirst($label), $ref->getName(), $method);
            return null;
        }
        try {
            return $ref->getMethod($method)->invoke(null);
        } catch (\Throwable $e) {
            $this->errors[] = sprintf('%s: %s::%s could not run without WordPress (%s). Build-time methods must be pure.', ucfirst($label), $ref->getName(), $method, $e->getMessage());
            return null;
        }
    }

    /** @param \ReflectionClass<object> $ref */
    private function static_method(\ReflectionClass $ref, string $method): bool
    {
        return $ref->hasMethod($method) && $ref->getMethod($method)->isStatic() && $ref->getMethod($method)->isPublic();
    }

    /**
     * Records a class in the compiled factory map with its constructor
     * dependencies, recursively for concrete dependencies.
     */
    private function factory(string $class): void
    {
        if (array_key_exists($class, $this->model['factories']) || in_array($class, self::KERNEL_SERVICES, true)) {
            return;
        }
        $this->model['factories'][ $class ] = [];
        try {
            $ref = new \ReflectionClass($class);
        } catch (\ReflectionException $e) {
            $this->errors[] = sprintf('Class %s does not exist.', $class);
            return;
        }
        if ($ref->isInterface() || $ref->isAbstract()) {
            unset($this->model['factories'][ $class ]);
            return;
        }
        $deps = [];
        foreach ($ref->getConstructor()?->getParameters() ?? [] as $param) {
            $type = $param->getType();
            if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                if ($param->isOptional()) {
                    break;
                }
                $this->errors[] = sprintf('Constructor parameter $%s of %s is not an injectable class or interface type.', $param->getName(), $class);
                continue;
            }
            $dep = $type->getName();
            $deps[] = $dep;
            if (! interface_exists($dep)) {
                $this->factory($dep);
            }
        }
        $this->model['factories'][ $class ] = $deps;
    }

    private function skills(): void
    {
        foreach ($this->modules as $id => $module) {
            $dir = $this->absolute((string) $module['dir']) . '/skills';
            foreach (glob($dir . '/*/SKILL.md') ?: [] as $file) {
                $this->model['skills'][ basename(dirname($file)) ][] = ['module' => $id, 'tier' => $module['tier'], 'path' => $this->relative($file)];
            }
        }
    }

    private function tier(Tier|string $tier, string $where): string
    {
        try {
            return Tier::normalize($tier);
        } catch (\InvalidArgumentException $e) {
            $this->errors[] = sprintf('%s on %s', $e->getMessage(), $where);
            return 'free';
        }
    }

    /**
     * @param list<Context|string> $contexts
     * @return list<string>
     */
    private function contexts(array $contexts, string $where): array
    {
        $out = [];
        foreach ($contexts as $context) {
            $value = $context instanceof Context ? $context->value : (string) $context;
            if (null === Context::tryFrom($value)) {
                $this->errors[] = sprintf('Unknown context "%s" on %s.', $value, $where);
                continue;
            }
            $out[] = $value;
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    private function requires(?Requires $requires, string $where): ?array
    {
        if (null === $requires) {
            return null;
        }
        if (null !== $requires->name && null === $requires->slug && null === $requires->url) {
            $this->errors[] = sprintf('Requires on %s names %s but neither a slug nor a url, so a refusal cannot say where to get it.', $where, $requires->name);
        }
        return $requires->to_array();
    }

    public function relative(string $path): string
    {
        $root = rtrim($this->repo_root, '/');
        $real = realpath($path) ?: $path;
        $root_real = '' === $root ? '' : (realpath($root) ?: $root);
        return ('' !== $root_real && str_starts_with($real, $root_real . '/')) ? substr($real, strlen($root_real) + 1) : $real;
    }

    private function absolute(string $relative): string
    {
        return str_starts_with($relative, '/') ? $relative : rtrim($this->repo_root, '/') . '/' . $relative;
    }

    private static function short(string $class): string
    {
        return substr((string) strrchr('\\' . $class, '\\'), 1);
    }
}
