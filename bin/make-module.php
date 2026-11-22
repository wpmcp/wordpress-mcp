<?php

/**
 * Scaffolds a WPMCP 2.0 module (dev only, never shipped): the module
 * folder, its fake, unit, conformance, integration and live tests, then
 * builds its surface.lock.php and runs its fast lane, so the scaffold is
 * green as generated (module architecture, addendum section 9).
 *
 *   composer make:module -- <Id> [--type=integration|feature]
 *       [--dispatcher=plugin-data|theme|woo|block-suites]
 *       [--plugin=pretty-link/pretty-link.php --plugin-name="Pretty Links" --slug=pretty-link]
 *       [--tier=free|pro|addon:<slug>] [--writes] [--flavors=woocommerce,elementor]
 *       [--root=src/Modules] [--tests-root=tests/modules]
 *       [--namespace=WPMCP\Modules] [--tests-namespace=WPMCP\Tests\Modules]
 *       [--order=9000] [--no-run] [--force]
 *
 * <Id> is the folder and class prefix, for example Pretty_Links; the module
 * id is its lowercase hyphenated form (pretty-links).
 */

declare(strict_types=1);

$repo = dirname(__DIR__);

$positional = [];
$opt = [];
foreach (array_slice($argv, 1) as $arg) {
    if (1 === preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m)) {
        $opt[ $m[1] ] = $m[2] ?? true;
    } else {
        $positional[] = $arg;
    }
}
$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, "make:module: {$message}\n");
    exit($code);
};
$known = ['type', 'dispatcher', 'plugin', 'plugin-name', 'slug', 'tier', 'writes', 'flavors', 'root', 'tests-root', 'namespace', 'tests-namespace', 'order', 'no-run', 'force'];
foreach (array_keys($opt) as $key) {
    in_array($key, $known, true) || $fail("unknown option --{$key}");
}
$name = $positional[0] ?? '';
if (1 !== preg_match('/^[A-Z][A-Za-z0-9]*(?:_[A-Z0-9][A-Za-z0-9]*)*$/', $name)) {
    $fail('give the module Id as the first argument, in Upper_Snake form (for example Pretty_Links)');
}
$str = static fn (string $key, string $default): string => is_string($opt[ $key ] ?? null) && '' !== $opt[ $key ] ? (string) $opt[ $key ] : $default;
$abs = static fn (string $p): string => str_starts_with($p, '/') ? rtrim($p, '/') : $repo . '/' . rtrim($p, '/');

$id = strtolower(str_replace('_', '-', $name));
$type = $str('type', 'integration');
in_array($type, ['integration', 'feature'], true) || $fail('--type is integration or feature');
$dispatcher = $str('dispatcher', 'plugin-data');
in_array($dispatcher, ['plugin-data', 'theme', 'woo', 'block-suites'], true) || $fail('--dispatcher is plugin-data, theme, woo or block-suites');
$tier = $str('tier', 'free');
1 === preg_match('/^(free|pro|addon:[a-z0-9][a-z0-9-]*)$/', $tier) || $fail('--tier is free, pro or addon:<slug>');
$writes = isset($opt['writes']);
$plugin = $str('plugin', $id . '/' . $id . '.php');
$plugin_name = $str('plugin-name', str_replace('_', ' ', $name));
$slug = $str('slug', $id);
$flavors = array_values(array_filter(array_map('trim', explode(',', $str('flavors', '')))));
$order = (int) $str('order', '9000');
$root = $abs($str('root', 'src/Modules'));
$tests_root = $abs($str('tests-root', 'tests/modules'));
$ns_base = trim($str('namespace', 'WPMCP\\Modules'), '\\');
$tests_ns_base = trim($str('tests-namespace', 'WPMCP\\Tests\\Modules'), '\\');
$ns = $ns_base . '\\' . $name;
$tests_ns = $tests_ns_base . '\\' . $name;
$dir = $root . '/' . $name;
$tests_dir = $tests_root . '/' . $name;
$op_prefix = $id;
$filter = 'wpmcp_enable_' . str_replace('-', '_', $id) . '_write';
$tier_expr = match ($tier) {
    'free' => 'Tier::Free',
    'pro' => 'Tier::Pro',
    default => var_export($tier, true),
};
$q = static fn (string $s): string => var_export($s, true);
$flavors_expr = '[' . implode(', ', array_map($q, $flavors)) . ']';

if ((is_dir($dir) || is_dir($tests_dir)) && ! isset($opt['force'])) {
    $fail("{$dir} or {$tests_dir} already exists (pass --force to overwrite)");
}

$guard = "if (! defined('ABSPATH')) {\n    exit;\n}\n";
$php = static function (string $namespace, string $uses, string $body, bool $guarded = true) use ($guard): string {
    return "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\n" . ('' === $uses ? '' : $uses . "\n") . ($guarded ? $guard . "\n" : '') . $body;
};

$files = [];

// Module.php: declarations only.
$files["{$dir}/Module.php"] = $php($ns, "use WPMCP\\Api\\V1\\Attribute\\Feature;\nuse WPMCP\\Api\\V1\\Attribute\\Module as Module_Declaration;\nuse WPMCP\\Api\\V1\\Attribute\\Owns_Data;\nuse WPMCP\\Api\\V1\\Value\\Requires;\nuse WPMCP\\Api\\V1\\Value\\Tier;\n", <<<PHP
/**
 * {$plugin_name}: module declaration only (no executable logic).
 */
#[Module_Declaration(
    id: {$q($id)},
    group: {$q($id)},
    order: {$order},
    tier: {$tier_expr},
    flavors: {$flavors_expr},
    requires: new Requires(plugin: [{$q($plugin)}], name: {$q($plugin_name)}, slug: {$q($slug)}),
    test_plugins: [{$q($plugin)}],
)]
#[Owns_Data()]
#[Feature({$q($plugin_name . ' support')})]
final class Module
{
}

PHP);

// Value object.
$files["{$dir}/Value/Item.php"] = $php("{$ns}\\Value", '', <<<PHP
/**
 * One {$plugin_name} item, as the module's tools and ops return it.
 */
final class Item
{
    public function __construct(
        public readonly int \$id,
        public readonly string \$title,
    ) {
    }

    public function with_title(string \$title): self
    {
        return new self(\$this->id, \$title);
    }

    /** @return array{id: int, title: string} */
    public function to_array(): array
    {
        return ['id' => \$this->id, 'title' => \$this->title];
    }
}

PHP);

// Adapter interface and implementation.
$write_sig = $writes ? "\n\n    public function update_title(int \$id, string \$title): ?Item;" : '';
$files["{$dir}/Adapter/{$name}_Adapter.php"] = $php("{$ns}\\Adapter", "use {$ns}\\Value\\Item;\n", <<<PHP
/**
 * The module boundary: the only way its tools and ops reach {$plugin_name}.
 */
interface {$name}_Adapter
{
    /** @return list<Item> */
    public function items(int \$limit): array;

    public function item(int \$id): ?Item;{$write_sig}
}

PHP);
$write_impl = $writes ? <<<PHP


    public function update_title(int \$id, string \$title): ?Item
    {
        // Call {$plugin_name}'s own API here.
        \$item = \$this->item(\$id);
        return null === \$item ? null : \$item->with_title(\$title);
    }
PHP : '';
$files["{$dir}/Adapter/Wp_{$name}_Adapter.php"] = $php("{$ns}\\Adapter", "use WPMCP\\Api\\V1\\Attribute\\Provides;\nuse {$ns}\\Value\\Item;\n", <<<PHP
/**
 * The real adapter. Host plugin functions, classes, tables and options are
 * touched here and nowhere else in the module (rule S1).
 */
#[Provides({$name}_Adapter::class)]
final class Wp_{$name}_Adapter implements {$name}_Adapter
{
    /** @return list<Item> */
    public function items(int \$limit): array
    {
        // Call {$plugin_name}'s own API here.
        return [];
    }

    public function item(int \$id): ?Item
    {
        return null;
    }{$write_impl}
}

PHP);

if ('integration' === $type) {
    $gate_use = $writes ? "use WPMCP\\Api\\V1\\Attribute\\Opt_In_Gate;\n" : '';
    $gate_attr = $writes ? "\n#[Opt_In_Gate(filter: self::OPT_IN_FILTER, destructive: false, labels: 'opt_in_labels')]" : '';
    $write_ops = $writes ? <<<PHP

            '{$op_prefix}-update-item' => [
                'mode' => 'write',
                'description' => 'Rename one {$plugin_name} item',
                'handler' => 'update_item',
                'capability' => 'manage_options',
                'enabled_by_default' => false,
                'opt_in_filter' => self::OPT_IN_FILTER,
                'input_schema' => ['type' => 'object', 'properties' => ['item_id' => ['type' => 'integer'], 'title' => ['type' => 'string']], 'required' => ['item_id', 'title']],
            ],
PHP : '';
    $write_methods = $writes ? <<<PHP


    /**
     * @param array<string, mixed> \$args
     * @return array<string, mixed>
     */
    public function update_item(array \$args): array
    {
        \$item = \$this->items->update_title((int) (\$args['item_id'] ?? 0), (string) (\$args['title'] ?? ''));
        return null === \$item ? ['updated' => false] : ['updated' => true, 'item' => \$item->to_array()];
    }

    /** @return array<string, string> */
    public static function opt_in_labels(): array
    {
        return [self::OPT_IN_FILTER => __({$q($plugin_name . ': writes')}, 'wpmcp')];
    }
PHP : '';
    $const = $writes ? "    public const OPT_IN_FILTER = {$q($filter)};\n\n" : '';
    $not_objects = "\n    not_objects: ['{$op_prefix}-get-item:item_id' => 'a {$plugin_name} item in its own storage'" . ($writes ? ", '{$op_prefix}-update-item:item_id' => 'a {$plugin_name} item in its own storage'" : '') . "],";
    $files["{$dir}/Ops/{$name}_Ops.php"] = $php("{$ns}\\Ops", "use WPMCP\\Api\\V1\\Attribute\\Operations;\n{$gate_use}use WPMCP\\Api\\V1\\Contract\\Operation_Provider;\nuse WPMCP\\Api\\V1\\Value\\Requires;\nuse WPMCP\\Api\\V1\\Value\\Tier;\nuse {$ns}\\Adapter\\{$name}_Adapter;\n", <<<PHP
/**
 * {$plugin_name} operations on the {$dispatcher} pair. operations() is pure
 * metadata (the generator reads it at build time); each 'handler' names an
 * instance method, resolved with the adapter injected.
 */
#[Operations(
    dispatcher: {$q($dispatcher)},
    host: {$q($plugin_name)},
    summary: {$q($plugin_name . ' items')},
    order: {$order},
    tier: {$tier_expr},
    requires: new Requires(plugin: [{$q($plugin)}]),{$not_objects}
)]{$gate_attr}
final class {$name}_Ops implements Operation_Provider
{
{$const}    public function __construct(private readonly {$name}_Adapter \$items)
    {
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        return [
            '{$op_prefix}-list-items' => [
                'mode' => 'read',
                'description' => 'List {$plugin_name} items',
                'handler' => 'list_items',
                'input_schema' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]]],
            ],
            '{$op_prefix}-get-item' => [
                'mode' => 'read',
                'description' => 'Read one {$plugin_name} item',
                'handler' => 'get_item',
                'input_schema' => ['type' => 'object', 'properties' => ['item_id' => ['type' => 'integer']], 'required' => ['item_id']],
            ],{$write_ops}
        ];
    }

    /**
     * @param array<string, mixed> \$args
     * @return array<string, mixed>
     */
    public function list_items(array \$args): array
    {
        \$limit = max(1, min(100, (int) (\$args['limit'] ?? 20)));
        return ['items' => array_map(static fn (\$item): array => \$item->to_array(), \$this->items->items(\$limit))];
    }

    /**
     * @param array<string, mixed> \$args
     * @return array<string, mixed>
     */
    public function get_item(array \$args): array
    {
        \$item = \$this->items->item((int) (\$args['item_id'] ?? 0));
        return ['item' => \$item?->to_array()];
    }{$write_methods}
}

PHP);
} else {
    $ability = $op_prefix;
    $files["{$dir}/Tools/List_Items.php"] = $php("{$ns}\\Tools", "use WPMCP\\Api\\V1\\Attribute\\Ability;\nuse WPMCP\\Api\\V1\\Contract\\Ability_Handler;\nuse WPMCP\\Api\\V1\\Value\\Op;\nuse {$ns}\\Adapter\\{$name}_Adapter;\n", <<<PHP
/**
 * Lists {$plugin_name} items.
 */
#[Ability(
    name: 'wpmcp/{$ability}-list-items',
    description: 'List {$plugin_name} items (id, title)',
    input: self::INPUT,
    capability: 'manage_options',
    domain: {$q($id)},
    operation: Op::Read,
    position: 10,
)]
final class List_Items implements Ability_Handler
{
    public const INPUT = ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]]];

    public function __construct(private readonly {$name}_Adapter \$items)
    {
    }

    /** @param array<string, mixed> \$input */
    public function handle(array \$input): mixed
    {
        \$limit = max(1, min(100, (int) (\$input['limit'] ?? 20)));
        return ['items' => array_map(static fn (\$item): array => \$item->to_array(), \$this->items->items(\$limit))];
    }
}

PHP);
}

if ($writes) {
    $files["{$dir}/Snapshots/Items_Table.php"] = $php("{$ns}\\Snapshots", "use WPMCP\\Api\\V1\\Attribute\\Table_Kind;\n", <<<PHP
/**
 * The plugin table rows a write snapshots before it changes them, for
 * Plugin_Table_Rows_Snapshot. Adjust table, key and order to the real schema.
 */
#[Table_Kind(kind: '{$op_prefix}_items', table: '{$op_prefix}_items', key: ['id'], order: ['id'], capability: 'manage_options')]
final class Items_Table
{
}

PHP);
}

$files["{$dir}/README.md"] = <<<MD
# {$plugin_name} module

Module id `{$id}`, tier `{$tier}`. Generated by `composer make:module`; edit freely.

## What it covers

Describe what the module reads and writes in {$plugin_name}.

## Operations

<!-- wpmcp:ops {$id} -->
Generated from the registry.
<!-- /wpmcp:ops -->

MD;

// Tests.
$fake_write = $writes ? <<<PHP


    public function update_title(int \$id, string \$title): ?Item
    {
        if (! isset(\$this->items[ \$id ])) {
            return null;
        }
        return \$this->items[ \$id ] = \$this->items[ \$id ]->with_title(\$title);
    }
PHP : '';
$files["{$tests_dir}/Fake/In_Memory_{$name}_Adapter.php"] = $php("{$tests_ns}\\Fake", "use {$ns}\\Adapter\\{$name}_Adapter;\nuse {$ns}\\Value\\Item;\n", <<<PHP
/**
 * In-memory {$plugin_name} for unit tests: no WordPress, no plugin.
 */
final class In_Memory_{$name}_Adapter implements {$name}_Adapter
{
    /** @var array<int, Item> */
    public array \$items = [];

    public function __construct(Item ...\$items)
    {
        foreach (\$items as \$item) {
            \$this->items[ \$item->id ] = \$item;
        }
    }

    /** @return list<Item> */
    public function items(int \$limit): array
    {
        return array_slice(array_values(\$this->items), 0, \$limit);
    }

    public function item(int \$id): ?Item
    {
        return \$this->items[ \$id ] ?? null;
    }{$fake_write}
}

PHP, false);

if ('integration' === $type) {
    $unit_write = $writes ? <<<PHP


    public function test_update_item_renames_through_the_adapter(): void
    {
        \$fake = new In_Memory_{$name}_Adapter(new Item(1, 'One'));

        self::assertSame(['updated' => true, 'item' => ['id' => 1, 'title' => 'Uno']], (new {$name}_Ops(\$fake))->update_item(['item_id' => 1, 'title' => 'Uno']));
        self::assertSame('Uno', \$fake->items[1]->title);
        self::assertSame(['updated' => false], (new {$name}_Ops(\$fake))->update_item(['item_id' => 9, 'title' => 'x']));
    }
PHP : '';
    $files["{$tests_dir}/Unit/{$name}_Ops_Test.php"] = $php("{$tests_ns}\\Unit", "use PHPUnit\\Framework\\TestCase;\nuse {$ns}\\Ops\\{$name}_Ops;\nuse {$ns}\\Value\\Item;\nuse {$tests_ns}\\Fake\\In_Memory_{$name}_Adapter;\n", <<<PHP
final class {$name}_Ops_Test extends TestCase
{
    public function test_list_items_returns_adapter_rows(): void
    {
        \$ops = new {$name}_Ops(new In_Memory_{$name}_Adapter(new Item(1, 'One'), new Item(2, 'Two')));

        self::assertSame(['items' => [['id' => 1, 'title' => 'One']]], \$ops->list_items(['limit' => 1]));
    }

    public function test_get_item_returns_null_for_an_unknown_id(): void
    {
        self::assertSame(['item' => null], (new {$name}_Ops(new In_Memory_{$name}_Adapter()))->get_item(['item_id' => 7]));
    }

    public function test_every_op_names_an_instance_handler(): void
    {
        foreach ({$name}_Ops::operations() as \$op => \$def) {
            self::assertTrue(method_exists({$name}_Ops::class, (string) \$def['handler']), \$op);
        }
    }{$unit_write}
}

PHP, false);
} else {
    $files["{$tests_dir}/Unit/List_Items_Test.php"] = $php("{$tests_ns}\\Unit", "use PHPUnit\\Framework\\TestCase;\nuse {$ns}\\Tools\\List_Items;\nuse {$ns}\\Value\\Item;\nuse {$tests_ns}\\Fake\\In_Memory_{$name}_Adapter;\n", <<<PHP
final class List_Items_Test extends TestCase
{
    public function test_lists_adapter_rows(): void
    {
        \$tool = new List_Items(new In_Memory_{$name}_Adapter(new Item(1, 'One')));

        self::assertSame(['items' => [['id' => 1, 'title' => 'One']]], \$tool->handle([]));
    }
}

PHP, false);
}

$files["{$tests_dir}/Conformance_Test.php"] = $php($tests_ns, "use {$ns}\\Adapter\\{$name}_Adapter;\nuse {$ns}\\Module;\nuse {$tests_ns}\\Fake\\In_Memory_{$name}_Adapter;\n", <<<PHP
final class Conformance_Test extends \\WPMCP\\Tests\\Kernel\\Support\\Module_Conformance_Test
{
    protected static function module(): string
    {
        return Module::class;
    }

    protected static function fakes(): array
    {
        return [{$name}_Adapter::class => new In_Memory_{$name}_Adapter()];
    }
}

PHP, false);

$files["{$tests_dir}/Integration/{$name}_Round_Trip_Test.php"] = $php("{$tests_ns}\\Integration", '', <<<PHP
/**
 * @group integration
 *
 * Snapshot then rollback round trip for each write, against WordPress and a
 * database. Fill in fixtures() when the first write op lands.
 */
final class {$name}_Round_Trip_Test extends \\WP_UnitTestCase
{
    public function test_round_trip(): void
    {
        if (! function_exists('add_action')) {
            self::markTestSkipped('Needs WordPress: runs in the integration lane.');
        }
        self::markTestIncomplete('Add fixtures for each write op of this module.');
    }
}

PHP, false);

$files["{$tests_dir}/Live/{$name}_Live_Test.php"] = $php("{$tests_ns}\\Live", '', <<<PHP
/**
 * @group live
 *
 * Runs against the real {$plugin_name}; skipped unless it is installed.
 */
final class {$name}_Live_Test extends \\WP_UnitTestCase
{
    public function test_against_the_real_plugin(): void
    {
        if (! function_exists('is_plugin_active') || ! is_plugin_active({$q($plugin)})) {
            self::markTestSkipped({$q($plugin_name . ' is not installed.')});
        }
        self::assertTrue(true);
    }
}

PHP, false);

foreach ($files as $file => $content) {
    if (! is_dir(dirname($file))) {
        mkdir(dirname($file), 0777, true);
    }
    file_put_contents($file, $content);
    fwrite(STDOUT, 'created ' . (str_starts_with($file, $repo . '/') ? substr($file, strlen($repo) + 1) : $file) . "\n");
}

if (isset($opt['no-run'])) {
    exit(0);
}

// Build the module's lock, then run its fast lane. A module outside the
// composer autoload map (an add-on or a test fixture root) is autoloaded
// from WPMCP_MODULE_AUTOLOAD by tools/registry/autoload-env.php.
$php_bin = escapeshellarg(PHP_BINARY);
$env = 'WPMCP_MODULE_AUTOLOAD=' . escapeshellarg(trim("{$ns}={$dir};{$tests_ns}={$tests_dir};" . (string) getenv('WPMCP_MODULE_AUTOLOAD'), ';'));
$build = sprintf('%s %s -d auto_prepend_file=%s %s/bin/build-registry.php --root=%s --out=%s --artifacts=%s --quiet', $env, $php_bin, escapeshellarg($repo . '/tools/registry/autoload-env.php'), escapeshellarg($repo), escapeshellarg($dir), escapeshellarg(sys_get_temp_dir() . '/wpmcp-make-module-' . getmypid() . '/registry'), escapeshellarg(sys_get_temp_dir() . '/wpmcp-make-module-' . getmypid() . '/artifacts'));
passthru($build, $code);
$tmp = sys_get_temp_dir() . '/wpmcp-make-module-' . getmypid();
if (is_dir($tmp)) {
    passthru('rm -rf ' . escapeshellarg($tmp));
}
if (0 !== $code) {
    $fail('registry build failed for the new module', 1);
}
fwrite(STDOUT, "wrote {$dir}/surface.lock.php\n");

$test = sprintf('%s %s %s/vendor/bin/phpunit --no-configuration --bootstrap %s %s', $env, $php_bin, escapeshellarg($repo), escapeshellarg($repo . '/tools/registry/module-test-bootstrap.php'), escapeshellarg($tests_dir));
passthru($test, $code);
exit(0 === $code ? 0 : 1);
