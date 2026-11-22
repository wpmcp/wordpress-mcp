# Contributing

## Coding standard and the local merge gate

All new and changed PHP follows [docs/CODING-STANDARD.md](docs/CODING-STANDARD.md).
Nothing merges to main unless `composer standard` is green on the branch:

```
composer standard        # full run: v2 roots, gate rules, registry check, central-list guard
composer standard:diff   # new and changed lines against origin/main (the pre-push hook runs this)
```

The local merge gate is: `composer standard`, `composer lint`, `composer
check:flavors`, and the targeted tests for what changed. GitHub Actions is not
part of it.


## Release flavors: shared code must not name integration or Pro classes

The repository builds three artifacts from one tree: the full plugin, the
wp.org directory cut (`scripts/build-wporg-release.sh`) and the WooCommerce
vertical (`scripts/build-woo-release.sh`). The two directory builds strip the
Pro tier and the out-of-flavor integrations through `scripts/flavors/*/strip.php`
and `scripts/flavors/woocommerce/manifest.php`. A guard then fails the build
(CI jobs "compliance" and "plugin-check") when a file that flavor ships still
names a class the strip removed, because that name is a fatal at runtime.

The rule:

- Shared code, meaning `src/Plugin.php`, `src/Safety/*`, `src/Tools/Meta/*` and
  anything every flavor ships, must never name an integration or Pro class
  (`use`, `new`, `Foo::bar`, `Foo::class` or a `'WPMCP\\Foo::bar'` string callable).
- Integrations register themselves through a hook or filter. The shared code
  exposes the seam; the integration file hooks into it.
- Where the codebase already handles a case with a strip rule, add the matching
  rule to `scripts/flavors/wporg/strip.php` in the same commit.

Check it before you push. It takes seconds and does no zipping or Plugin Check:

```
composer check:flavors
```

The same check runs as `tests/free/Release/FlavorClassGuardTest.php` (so
`bin/test-local.sh -- --filter FlavorClassGuard` and the full gate cover it) and
as a step of the pre-push hook, which runs even when `WPMCP_SKIP_LOCAL_TESTS=1`
is set. It calls the build scripts' own `--check-only` path, so it cannot
disagree with CI.
