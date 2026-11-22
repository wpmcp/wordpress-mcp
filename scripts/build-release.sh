#!/usr/bin/env bash
# Build the self-hosted distribution zips.
#
# The free zip is the public tree only: the free plugin, the licensing SDK,
# the in-plugin upgrade banner and an Update URI header so WordPress never
# offers a same-slug wordpress.org package as its update. It is the headline
# GitHub download (wpmcp-<version>.zip and the stable wpmcp.zip, see
# scripts/release-assets.sh). The premium zip adds the private pro/ tree and
# is delivered by the licensing SDK only; licence checks stay inside Pro.
#
# The WordPress.org cut is a different artifact with the paid tier removed
# rather than gated: scripts/build-wporg-release.sh, which writes
# dist/wpmcp-directory-<version>.zip and must never carry Update URI.
#
# Up to 1.2.0 the free zip was named wpmcp-pro-<version>.zip and the
# directory cut took wpmcp-<version>.zip.
#
# Two modes, one code path:
#
#   scripts/build-release.sh             the FREE zip, dist/wpmcp-<version>.zip,
#                                        root folder wpmcp/. Public tree only:
#                                        never pro/, never the premium marker.
#   scripts/build-release.sh --premium   the PREMIUM zip,
#                                        dist/wpmcp-pro-<version>.zip, root
#                                        folder wpmcp-pro/. The free stage plus
#                                        the private pro/ submodule (runtime
#                                        files only) and the root marker
#                                        .wpmcp-pro. Never a GitHub
#                                        release asset (scripts/release-assets.sh
#                                        refuses it).
#
# pro-manifest.txt lists the Pro-only paths at their original public
# locations; scripts/lib/pro-leak-gate.sh fails any zip that is not the
# premium build if one of them, pro/ or the marker is inside it.
# WPMCP_PRO_MANIFEST points the gate at another manifest file.
set -euo pipefail

PREMIUM=0
case "${1:-}" in
    "") ;;
    --premium) PREMIUM=1 ;;
    *) echo "usage: scripts/build-release.sh [--premium]" >&2; exit 2 ;;
esac

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(sed -n "s/^define( 'WPMCP_VERSION', '\([0-9.]*\)' );/\1/p" "$ROOT/wpmcp.php")"
[ -n "$VERSION" ] || { echo "could not read WPMCP_VERSION from wpmcp.php" >&2; exit 1; }

# shellcheck source=scripts/lib/pro-leak-gate.sh
. "$ROOT/scripts/lib/pro-leak-gate.sh"
MANIFEST="$(pro_manifest_file "$ROOT")"
[ -r "$MANIFEST" ] || { echo "gate: no Pro manifest at $MANIFEST; the leak gate cannot run without it" >&2; exit 1; }
pro_manifest_paths "$MANIFEST" > /dev/null || { echo "gate: $MANIFEST is malformed" >&2; exit 1; }

if [ "$PREMIUM" = 1 ]; then
    FOLDER="$WPMCP_PREMIUM_ROOT"
    ZIP_NAME="wpmcp-pro-$VERSION.zip"
    # Never build a premium zip without Pro: an uninitialised submodule is an
    # empty directory, so the entry point and the class tree are checked, not
    # just the directory.
    if [ ! -f "$ROOT/pro/bootstrap.php" ] || [ ! -d "$ROOT/pro/src" ]; then
        echo "premium build: pro/bootstrap.php or pro/src/ is missing from this checkout." >&2
        echo "The Pro code is the private submodule at pro/; fetch it with: git submodule update --init pro" >&2
        exit 1
    fi
else
    FOLDER="$WPMCP_FREE_ROOT"
    ZIP_NAME="wpmcp-$VERSION.zip"
fi

STAGE_PARENT="$(mktemp -d)"
STAGE="$STAGE_PARENT/$FOLDER"
trap 'rm -rf "$STAGE_PARENT"' EXIT
mkdir -p "$STAGE"

cp "$ROOT/wpmcp.php" "$ROOT/readme.txt" "$ROOT/LICENSE" "$ROOT/composer.json" "$ROOT/composer.lock" "$STAGE/"
cp -R "$ROOT/src" "$STAGE/src"

# The compiled module registry for this flavor, shipped as build/registry
# (D11); modules the flavor does not ship leave with their own folder.
# shellcheck source=scripts/lib/stage-registry.sh
. "$ROOT/scripts/lib/stage-registry.sh"
stage_registry "$STAGE" full || { echo "ERROR: the full module registry did not build" >&2; exit 1; }
# No uninstall file here (issue #468): WordPress would run it instead of the
# licensing SDK's uninstall hook. This build clears its cron events through
# that SDK's after_uninstall action (src/Freemius/Bootstrap.php) instead.
# src/Freemius/Bootstrap.php pins the connect-screen icon to assets/, so the
# self-hosted zip has to carry that directory (#164).
cp -R "$ROOT/assets" "$STAGE/assets"
# assets/readme/ holds the GitHub README images (demo GIF, screenshots); the
# plugin never loads them, so they stay out of the zip.
rm -rf "$STAGE/assets/readme"

# Ship the translation directory the Domain Path header points at (issue #184).
mkdir -p "$STAGE/languages"
find "$ROOT/languages" -maxdepth 1 \( -name '*.po' -o -name '*.mo' -o -name '*.l10n.php' \) -exec cp {} "$STAGE/languages/" \;

# Gate: the public tree staged so far is the same for both modes and must not
# carry a Pro path at its public location, pro/ or the marker.
assert_stage_no_pro_paths "$STAGE" "$MANIFEST" || { echo "gate: Pro-only paths are still in the public tree (move them to pro/)" >&2; exit 1; }

if [ "$PREMIUM" = 1 ]; then
    # Runtime files only: the entry point and the class, module, build, skill
    # and asset trees when present. Never pro/tests, pro/.git, pro/.github or
    # any other dotfile.
    mkdir -p "$STAGE/pro"
    cp "$ROOT/pro/bootstrap.php" "$STAGE/pro/bootstrap.php"
    for dir in src modules build skills assets; do
        if [ -d "$ROOT/pro/$dir" ]; then cp -R "$ROOT/pro/$dir" "$STAGE/pro/$dir"; fi
    done
    find "$STAGE/pro" -mindepth 1 -name '.*' -prune -exec rm -rf {} +
    printf '%s\n' "$WPMCP_PREMIUM_MARKER_TEXT" > "$STAGE/$WPMCP_PREMIUM_MARKER"
fi

composer install --working-dir="$STAGE" --no-dev --optimize-autoloader --quiet --no-interaction
rm -f "$STAGE/composer.json" "$STAGE/composer.lock"

# Freemius refuses a zip whose fs_dynamic_init() argument is not a literal
# array, so the staged copy gets the config inlined (scripts/lib/inline-fs-config.php).
if [ "$PREMIUM" = 1 ]; then
    php "$ROOT/scripts/lib/inline-fs-config.php" "$ROOT" "$STAGE" --premium

    # Gate: every class under pro/src and pro/modules must be in the optimized
    # classmap, or the Pro tier fatals on first use. The repository
    # composer.json maps WPMCP\\ to src/ and pro/src/; this checks the result
    # rather than the rule.
    php -r '
$stage = $argv[1];
$map = require $stage . "/vendor/composer/autoload_classmap.php";
$mapped = [];
foreach ($map as $file) { $mapped[realpath($file)] = true; }
$missing = [];
foreach (["pro/src", "pro/modules"] as $dir) {
    if (! is_dir("$stage/$dir")) { continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$stage/$dir", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ("php" !== $f->getExtension()) { continue; }
        $declares = false;
        $tokens = token_get_all((string) file_get_contents($f->getPathname()));
        foreach ($tokens as $i => $t) {
            if (is_array($t) && in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $prev = $tokens[$i - 1] ?? null;
                $prev2 = $tokens[$i - 2] ?? null;
                // Skip Foo::class and new class (anonymous).
                if ((is_array($prev) && T_DOUBLE_COLON === $prev[0]) || (is_array($prev2) && T_NEW === $prev2[0])) { continue; }
                $declares = true;
                break;
            }
        }
        if ($declares && ! isset($mapped[realpath($f->getPathname())])) { $missing[] = substr($f->getPathname(), strlen($stage) + 1); }
    }
}
if ($missing) { fwrite(STDERR, implode("\n", $missing) . "\n"); exit(1); }
' "$STAGE" || { echo "gate: the Pro classes above are not in the staged classmap; composer.json must autoload pro/src/" >&2; exit 1; }
else
    php "$ROOT/scripts/lib/inline-fs-config.php" "$ROOT" "$STAGE"
fi

mkdir -p "$ROOT/dist"
ZIP="$ROOT/dist/$ZIP_NAME"
rm -f "$ZIP"
(cd "$STAGE_PARENT" && zip -rq "$ZIP" "$FOLDER" -x "*.DS_Store")

# shellcheck source=scripts/lib/zip-gate.sh
. "$ROOT/scripts/lib/zip-gate.sh"

# Gate: the compiled module registry ships (D11); without it the plugin boots
# with no modules, their integrations and opt-in switches gone.
zip_includes "$ZIP" "wpmcp/build/registry/boot.php" || { echo "gate: $ZIP has no build/registry" >&2; exit 1; }

# Gate: every assets/ path that src/ resolves against WPMCP_DIR must be in the
# zip, or the Freemius connect screen renders a 404 icon (#164).
ASSET_SOURCES=("$ROOT/src")
if [ "$PREMIUM" = 1 ]; then ASSET_SOURCES+=("$ROOT/pro/src"); fi
ZIP_LISTING="$(unzip -Z1 "$ZIP")"
for asset in $(grep -rhoE "WPMCP_DIR \. 'assets/[^']+'" "${ASSET_SOURCES[@]}" | sed -E "s/.*'(assets\/[^']+)'/\1/" | sort -u); do
    grep -Fxq "$FOLDER/$asset" <<<"$ZIP_LISTING" || { echo "gate: $asset is referenced from the source but missing from $ZIP" >&2; exit 1; }
done

# Gate: the Freemius deployment validator needs the literal array (see above).
FS_FILE="$(unzip -p "$ZIP" "$FOLDER/src/Freemius/wpmcp-fs.php")"
case "$FS_FILE" in
    *"Bootstrap::config()"*) echo "gate: wpmcp-fs.php still calls Bootstrap::config(); Freemius cannot deploy this zip" >&2; exit 1 ;;
esac
case "$FS_FILE" in
    *"'public_key'"*"'has_paid_plans'"*) ;;
    *) echo "gate: wpmcp-fs.php carries no inlined fs_dynamic_init config" >&2; exit 1 ;;
esac

# Gate: this is the build with the upgrade path. If the banner, the licensing
# SDK or the Update URI header goes missing, GitHub users get a plugin with no
# way to Pro (or one WordPress may "update" to the stripped directory cut).
# Shared with scripts/release-assets.sh (scripts/lib/release-flavors.sh).
# shellcheck source=scripts/lib/release-flavors.sh
. "$ROOT/scripts/lib/release-flavors.sh"
assert_self_hosted_zip "$ZIP" "$FOLDER/wpmcp.php" "$VERSION" || { echo "gate: $ZIP is not the self-hosted banner build" >&2; exit 1; }

# Gate: the Pro leak gate (scripts/lib/pro-leak-gate.sh). The free zip carries
# no manifest path, no pro/, no marker and inlines 'is_premium' => false; the
# premium zip is rooted at wpmcp-pro/, carries the marker and pro/bootstrap.php,
# no pro/tests or dotfiles under pro/, and inlines 'is_premium' => true.
if [ "$PREMIUM" = 1 ]; then
    assert_premium_zip "$ZIP" "$MANIFEST" || { echo "gate: $ZIP is not a valid premium build" >&2; exit 1; }
else
    assert_free_zip "$ZIP" "$FOLDER" "$MANIFEST" || { echo "gate: $ZIP leaks Pro code or is not the free build" >&2; exit 1; }
fi

echo "built $ZIP"
unzip -l "$ZIP" | tail -2
