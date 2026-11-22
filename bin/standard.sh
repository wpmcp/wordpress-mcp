#!/usr/bin/env bash
#
# The coding standard gate (docs/CODING-STANDARD.md), local only:
#
#   composer standard                          # full run
#   composer standard -- --diff=origin/main    # new and changed code only
#   bash bin/standard.sh --install             # fetch the toolchain, then exit
#
# Full run: PHPStan (phpstan.neon.dist: level max, strict rules, bleedingEdge,
# the WPMCP module rules) and phpcs (phpcs-standard.xml.dist) on the v2 roots
# and tools/phpstan, the gate rule tests, the central-list guard and the
# registry check.
#
# --diff=<ref>: the same tools on the PHP files under src/ and tools/ that
# changed against the merge base with <ref> (working tree and untracked files
# included), keeping only findings on added or modified lines
# (tools/standard/diff-filter.php). That line filter is the legacy baseline.
#
# Toolchain: vendor/bin/phpstan when the root vendor has it, else the pinned
# phar cached in ${WPMCP_STANDARD_CACHE:-~/.cache/wpmcp-standard} (sha256
# checked; prune with `bash bin/standard.sh --prune`), plus
# tools/standard/vendor (PHPStan extensions, slevomat), installed on first
# use. Exit 0 when every gate passes, 1 when any fails, 2 on bad usage.

set -uo pipefail
cd "$(dirname "$0")/.." || exit 2

PHPSTAN_VERSION=2.3.7
PHPSTAN_SHA256=87fa9aa451bb9b61d7c65d40ec7d14d5bab67e3ad74ce75a09b3140d6f22631a
CACHE="${WPMCP_STANDARD_CACHE:-$HOME/.cache/wpmcp-standard}"

diff_ref=""
install_only=false
for arg in "$@"; do
	case "$arg" in
		--diff=*) diff_ref="${arg#--diff=}" ;;
		--diff) diff_ref="origin/main" ;;
		--install) install_only=true ;;
		--prune)
			# Keep only the pinned phar; drop older versions and partial downloads.
			find "$CACHE" -maxdepth 1 -type f ! -name "phpstan-${PHPSTAN_VERSION}.phar" -delete 2>/dev/null
			echo "standard: pruned $CACHE"
			exit 0
			;;
		*) echo "standard: unknown argument $arg" >&2; exit 2 ;;
	esac
done

say() { printf '[standard] %s\n' "$*" >&2; }

# --- toolchain --------------------------------------------------------------
if [ ! -f tools/standard/vendor/autoload.php ]; then
	say "installing tools/standard (PHPStan extensions, slevomat)..."
	composer install --working-dir=tools/standard --no-interaction --no-progress --prefer-dist -q || exit 2
fi
if [ -x vendor/bin/phpstan ]; then
	PHPSTAN=(vendor/bin/phpstan)
	unset WPMCP_PHPSTAN_PHAR
else
	phar="${WPMCP_PHPSTAN_PHAR:-$CACHE/phpstan-${PHPSTAN_VERSION}.phar}"
	if [ ! -f "$phar" ]; then
		mkdir -p "$CACHE"
		say "downloading PHPStan ${PHPSTAN_VERSION} phar..."
		tmp="$CACHE/.phpstan-${PHPSTAN_VERSION}.part.$$"
		trap 'rm -f "$tmp"' EXIT INT TERM
		curl -fsSL --retry 3 -o "$tmp" "https://github.com/phpstan/phpstan/releases/download/${PHPSTAN_VERSION}/phpstan.phar" || exit 2
		mv "$tmp" "$phar"
	fi
	if [ -z "${WPMCP_PHPSTAN_PHAR:-}" ] && [ "$(shasum -a 256 "$phar" | cut -d' ' -f1)" != "$PHPSTAN_SHA256" ]; then
		say "checksum mismatch for $phar; delete it and rerun"
		exit 2
	fi
	export WPMCP_PHPSTAN_PHAR="$phar"
	PHPSTAN=(php "$phar")
fi
PHPCS=(tools/standard/vendor/bin/phpcs --standard=phpcs-standard.xml.dist --runtime-set installed_paths "$PWD/tools/standard/vendor/slevomat/coding-standard")
$install_only && { say "toolchain ready"; exit 0; }

failed=0
gate() { # name, then the command
	local name="$1"; shift
	if "$@"; then say "ok    $name"; else say "FAIL  $name"; failed=1; fi
}

guard_ref="${diff_ref:-origin/main}"
if [ -z "$diff_ref" ]; then
	full_paths=()
	for dir in src/Kernel src/Api src/Modules pro/modules tools/phpstan tools/standard; do
		[ -d "$dir" ] && full_paths+=("$dir")
	done
	gate "phpstan (level max)" "${PHPSTAN[@]}" analyse -c phpstan.neon.dist -a tools/phpstan/autoload.php --no-progress --memory-limit=1G
	gate "phpcs (phpcs-standard.xml.dist)" "${PHPCS[@]}" -q "${full_paths[@]}"
	gate "gate rule tests" vendor/bin/phpunit --no-configuration --bootstrap tools/phpstan/test-bootstrap.php tests/kernel/PHPStan
	gate "registry check (every flavor)" php bin/build-registry.php --check --all-flavors --quiet
else
	base="$(git merge-base "$diff_ref" HEAD)" || { say "no merge base with $diff_ref"; exit 2; }
	changed=()
	while IFS= read -r file; do
		[ -f "$file" ] && changed+=("$file")
	done < <({ git diff --name-only --diff-filter=AMR "$base" -- 'src/*.php' 'tools/*.php'; git ls-files --others --exclude-standard -- 'src/*.php' 'tools/*.php'; } | sort -u)
	say "${#changed[@]} changed PHP file(s) under src/ and tools/ against $diff_ref ($base)"
	if [ "${#changed[@]}" -gt 0 ]; then
		phpstan_diff() {
			# The tool exits non-zero on any finding; only the filter's verdict counts.
			{ "${PHPSTAN[@]}" analyse -c phpstan.neon.dist -a tools/phpstan/autoload.php --no-progress --memory-limit=1G --error-format=json "${changed[@]}" 2>/dev/null || true; } \
				| php tools/standard/diff-filter.php phpstan "$base"
		}
		phpcs_diff() {
			{ "${PHPCS[@]}" -q --report=json "${changed[@]}" || true; } | php tools/standard/diff-filter.php phpcs "$base"
		}
		gate "phpstan (level max, changed lines)" phpstan_diff
		gate "phpcs (changed lines)" phpcs_diff
	fi
	if git diff --name-only "$base" -- tools/phpstan tests/kernel/PHPStan | grep -q . || git ls-files --others --exclude-standard -- tools/phpstan tests/kernel/PHPStan | grep -q .; then
		gate "gate rule tests" vendor/bin/phpunit --no-configuration --bootstrap tools/phpstan/test-bootstrap.php tests/kernel/PHPStan
	fi
	if git diff --name-only "$base" -- src/Modules pro/modules src/Api tools/registry | grep -q .; then
		gate "registry check (every flavor)" php bin/build-registry.php --check --all-flavors --quiet
	fi
fi
gate "central-list guard" php tools/standard/central-list-guard.php "$guard_ref"

[ "$failed" = 0 ] && say "standard: all gates passed" || say "standard: FAILED (docs/CODING-STANDARD.md)"
exit "$failed"
