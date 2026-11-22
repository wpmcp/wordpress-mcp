# WPMCP PHP coding standard

Every rule here applies to new and changed code. Legacy code is not
rewritten for it: the gate checks only the lines a change adds or modifies,
and a whole file once it is new.

```
composer standard        # full run: src/Kernel, src/Api, src/Modules, pro/modules, tools/phpstan, tools/standard
composer standard:diff   # new and changed lines under src/ and tools/ against origin/main
```

The pre-push hook runs `composer standard:diff`. Nothing merges to main
without `composer standard` green (CONTRIBUTING.md). The gate is local only;
GitHub Actions is not part of it.

| Gate | Tool | Rules |
|---|---|---|
| PHPStan at level max, phpstan-strict-rules, bleedingEdge, phpstan-wordpress, and the WPMCP module rules G2 to G5 (phpstan.neon.dist, tools/phpstan) | PHPStan 2.3.7 | 1, 5, 6, 8, 9, 11 |
| phpcs-standard.xml.dist: PSR-12 plus slevomat strict types, final classes, no public mutable properties, native type hints, early exit, no unused uses | slevomat/coding-standard | 2, 3, 4, 9, 10 |
| Central-list guard (tools/standard/central-list-guard.php) | git diff | 12 |
| Gate rule tests, registry check | phpunit, bin/build-registry.php | 12 |

Baselines may only shrink. The `--diff` line filter
(tools/standard/diff-filter.php) is the legacy baseline. phpstan-baseline.neon
holds the level max findings src/Kernel and src/Api had when they landed at
level 8 (PR #1207).

## 1. PHP 8.1 syntax

The floor is PHP 8.1 (composer platform and `Requires PHP`). You may use
enums, readonly properties, `new` in initializers and first-class callables.
Do not use 8.2+ syntax: readonly classes, DNF types, `true` as a standalone
type, or constants in traits.

```php
// Good
public function __construct(public readonly Op $op) {}
// Bad (readonly class is PHP 8.2)
readonly final class Item {}
```

## 2. `declare(strict_types=1);` in every file

The file starts with `<?php`, then a blank line, then `declare(strict_types=1);`.

```php
// Good
<?php

declare(strict_types=1);
// Bad: no declare, or `declare(strict_types = 1)` on the open tag line
<?php declare(strict_types = 1);
```

## 3. Final by default

Every concrete class is `final`. Only a kernel base class meant for extension
may be `abstract`.

```php
// Good
final class List_Orders implements Ability_Handler {}
// Bad
class List_Orders implements Ability_Handler {}
```

## 4. Readonly properties and constructor promotion

A dependency or value is a promoted `private readonly` property, or a
`public readonly` one on a value object. There are no public mutable properties
and no setters. A `with*()` method returns a new instance.

```php
// Good
public function __construct(private readonly Orders_Adapter $orders) {}
public function with_title(string $title): self { return new self($this->id, $title); }
// Bad
public $orders;
public function set_title(string $title): void { $this->title = $title; }
```

## 5. Constructor injection only

A service gets its dependencies as typed constructor parameters, and the
compiled container supplies them. Inside logic, do not `new` a service, do
not use a service locator (`Plugin::instance()`, `$container->get()` in a
module), do not keep a singleton, and do not use static or global state.
`new` is fine for value objects, exceptions, and PHP or WordPress value
classes (`WP_Error`, `DateTimeImmutable`).

```php
// Good
public function __construct(private readonly Orders_Adapter $orders) {}
// Bad
$orders = new Wp_Orders_Adapter();
$orders = Plugin::instance()->orders();
private static ?self $instance = null;
```

**Static helpers are allowed; static state is not.** A static method on a
stateless final class (a pure function: its output depends only on its
arguments, and it reads or writes no static property, global or option) is
acceptable, and existing pure helpers are not to be rewritten for this
standard. What is forbidden is static *state*: static properties that change,
memoized statics, `global $x`, and singletons. In module code, gate G4
(NoStaticHelperCallRule) still reports static calls on other WPMCP classes,
because a module depends on its own injected services. Grandfather a pure
helper through `wpmcp.staticCallAllowlist` when a module needs one.

```php
// Good: pure, stateless
final class Slug { public static function of(string $title): string { return sanitize_title($title); } }
// Bad: static state
final class Cache { private static array $rows = []; public static function put(string $k, array $v): void { self::$rows[$k] = $v; } }
```

## 6. Interfaces at module seams

Anything another layer or the kernel consumes is typed by an interface:
handlers (`Ability_Handler`), providers (`Operation_Provider`), restorers
(`Snapshot_Restorer`), adapters (`<Id>_Adapter`) and edition seams
(`#[Provides(Interface::class)]`). Outside Adapter/, type against the adapter
interface, never the `Wp_*` implementation.

```php
// Good
public function __construct(private readonly Orders_Adapter $orders) {}
// Bad
public function __construct(private readonly Wp_Orders_Adapter $orders) {}
```

## 7. One responsibility per class

A class does one job: one ability handler, one adapter, one value. If you
need "and" to describe the class, split it. Shared helpers for one family
stay inside that module.

```php
// Good: List_Orders (one tool), Wp_Orders_Adapter (host access), Order (value)
// Bad: Orders_Manager that registers hooks, queries tables, formats output and renders admin pages
```

## 8. Enums and value objects instead of magic strings and arrays

When the set of values or the shape is fixed, use an enum or a readonly value
object. Use an `array` for open-ended data (JSON input, schemas), and give it
an array-shape docblock.

```php
// Good
public function __construct(public readonly Op $operation) {}
/** @return array{id: int, title: string} */
// Bad
public function __construct(public readonly string $operation) {} // 'read'|'update'|...
return ['id' => $id, 'ttl' => $title]; // shape nobody checks
```

## 9. Typed parameters, returns and properties

Every parameter, return and property has a native type. Use `mixed` only
where the value is genuinely open (an ability's JSON result, a filter
callback's value), and give the reason in the docblock. Put generics and
array shapes in docblocks. PHPStan at level max must pass.

```php
// Good
/** @param array<string, mixed> $input */
public function handle(array $input): array
// Bad
public function handle($input)
```

## 10. Early returns

Handle the invalid or empty case first and return, so the main path stays
unindented. Do not nest `if` blocks more than two levels deep.

```php
// Good
if ([] === $ids) {
    return [];
}
return $this->orders->find($ids);
// Bad
if ([] !== $ids) {
    if ($this->orders->ready()) {
        return $this->orders->find($ids);
    }
}
return [];
```

## 11. WordPress behind small adapters

Pure logic must be unit-testable without WordPress. Only Adapter/ calls the
host plugin or WordPress storage (options, meta, tables, `WP_Query`).
Tools/, Ops/ and Snapshots/ call the adapter interface, and their unit tests
use an in-memory fake (tests/modules/<Id>/Fake/). Gate G5 (LayerRule)
enforces this for host plugin symbols.

```php
// Good (Tools/)
$rows = $this->orders->recent($limit);
// Bad (Tools/)
$rows = wc_get_orders(['limit' => $limit]);
```

## 12. New integrations self-register

A new integration lives in its own module directory (`src/Modules/<Id>/`,
from `composer make:module`) and declares itself with attributes
(`#[Module]`, `#[Ability]`, `#[Operations]`, `#[Opt_In_Gate]`, `#[Owns_Data]`,
`#[Feature]`). The generated registry picks it up. It never edits a central
list: the Plugin_Data_Integration lists, Opt_In_Settings, Plugin_Refusal,
Data_Registry, scripts/flavors/wporg/strip.php and policy.php, Plugin.php, or
the readme counts. See "How to add an integration" below. The central-list guard fails a
change that adds a literal entry to a list that reads the registry
(Opt_In_Settings, Plugin_Refusal, Theme_Integration, Data_Registry) or any
line to strip.php or policy.php; grows Plugin.php or Plugin_Data_Integration
by more than one line; or adds a hand-maintained count to readme.txt or
README.md. A justified line carries `// central-list-ok: <reason>`.

```php
// Good: src/Modules/Acme_Forms/Ops/Acme_Forms_Ops.php
#[Operations(dispatcher: 'plugin-data', host: 'Acme Forms', summary: 'Acme Forms entries')]
#[Opt_In_Gate(filter: 'wpmcp_enable_acme_forms_write', destructive: true, labels: 'opt_in_labels')]
final class Acme_Forms_Ops implements Operation_Provider {}
// Bad: new rows for Acme Forms in Opt_In_Settings, Plugin_Data_Integration and strip.php
```

## 13. The golden baseline moves with the surface

tests/golden/GoldenCaptureTest.php compares the plugin's surface (abilities,
tools/list, dispatcher catalogs, opt-in groups, rollback refreshers, hooks and
the admin menu, for the full, woocommerce and wporg flavors) with
tests/support/golden/baseline/. A change that intends to alter that surface
(a new or changed ability, operation, description, schema, hint, opt-in group
or hook) regenerates the baseline in the same PR and commits the diff:

```
WPMCP_GOLDEN_REGENERATE=1 bash bin/test-local.sh -- --group golden tests/golden
```

Review that diff before committing: it shows only the intended changes. A
change that does not intend to alter the surface runs the group without
regenerating, and it stays green:

```
bash bin/test-local.sh -- --group golden tests/golden
```

## How to add an integration

An integration is a module: a directory under `src/Modules/<Id>/` with
attributes, compiled by `bin/build-registry.php` into `build/registry/`. The
release builds write that registry for each flavor and ship it, and the
plugin reads it at runtime (`Plugin::instance()->kernel()`, D11). The legacy
central lists keep their own rows and then take in the registry's, so you
never edit them. A key that is in both (a filter, a plugin name, a table, an
operation) throws a `LogicException`: move the row, do not copy it.

1. `composer make:module -- <Id> --plugin=<dir/main.php> --plugin-name="<Name>" --slug=<wporg-slug>`
   scaffolds the module, its adapter, value, ops and tests.
2. Declare everything on the module's own classes, using this table:

| What you used to add by hand | Declare instead | Where it lands |
|---|---|---|
| Opt_In_Settings `all_groups()` row and `labels()` row | `#[Opt_In_Gate(filter: 'wpmcp_enable_<id>_write', destructive: true, labels: 'opt_in_labels')]` on the Ops class, plus a static `opt_in_labels(): array` returning filter => label | `opt_in_gates` in boot.php; `Opt_In_Settings::all_groups()` and `labels()` |
| Plugin_Refusal `PLUGINS` and `ALT_FILES` rows | `requires: new Requires(plugin: ['<dir>/<main>.php', '<pro-dir>/<main>.php'], name: '<Name>', slug: '<wporg-slug>')` (or `url:` for a paid plugin) on `#[Module]` or `#[Operations]` | `refusal-plugins.php`; `Plugin_Refusal::make()` and `install_url()` |
| `Some_Pack::operations(),` in Theme_Integration | `#[Operations(dispatcher: 'theme', host: '<Name>', summary: '...')]` on a class implementing `Operation_Provider` (add `variant: '<framework>'` for a theme framework pack) | `ops/theme.php`; the theme pair's operations |
| Data_Registry `TABLES` (and options or user meta outside the `wpmcp_` prefixes) | `#[Owns_Data(tables: [...], options: [...], user_meta: [...])]` on the Module class | `data.php`; `Data_Registry::tables()`, `module_options()`, `module_user_meta()`, the uninstaller |
| A strip.php edit or a policy.php path for the wp.org or WooCommerce build | `#[Module(flavors: [...], exclude_flavors: [...], tier: ...)]` | the release build drops the module folder from flavors that do not ship it (scripts/lib/stage-registry.sh) |

3. Run `composer registry:build` and commit the module's `surface.lock.php`,
   then `composer standard:diff`, `composer lint`, `composer check:flavors`
   and the module's tests.

Moving an existing pack follows the same table: add the declaration and
delete the literal row in the same change (the guard counts only additions).
The plugin-data pair (Plugin_Data_Integration) does not read the registry
yet. Until it does, a pack there still adds its one registration line.
