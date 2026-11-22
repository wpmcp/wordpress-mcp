#!/usr/bin/env bash
#
# The free-tree lane: the public tree must boot and work with pro/ absent,
# which is what the free zip ships and what a checkout without the private
# submodule has.
#
#   bin/free-tree-smoke.sh                 the free-tree group (boot, free abilities, free tools)
#   bin/free-tree-smoke.sh -- <phpunit>    other PHPUnit arguments, e.g. -- --testsuite free
#   bin/free-tree-smoke.sh --keep          keep the copy (prints its path)
#   bin/free-tree-smoke.sh --prune         remove copies a killed run left behind, run nothing
#
# It copies the checkout without pro/ (and without .git, vendor and build
# output) into a temporary directory, builds its autoloader, runs
# `php bin/pro-split.php check` on it (no public file may name a Pro class),
# then runs PHPUnit there through bin/test-local.sh, which gives the copy its
# own database and drops it when it exits. The copy is removed on exit,
# failure and Ctrl-C alike.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PREFIX="wpmcp-free-tree"
BASE="${TMPDIR:-/tmp}"
BASE="${BASE%/}"

keep=false
phpunit_args=()
while [ $# -gt 0 ]; do
	case "$1" in
		--keep) keep=true ;;
		--prune)
			# Only copies whose run is gone: the pid is the suffix.
			for dir in "$BASE/$PREFIX".*; do
				[ -d "$dir" ] || continue
				pid="${dir##*.}"
				if ! kill -0 "$pid" 2>/dev/null; then
					rm -rf "$dir"
					echo "removed $dir"
				fi
			done
			exit 0 ;;
		--) shift; phpunit_args=("$@"); break ;;
		*) echo "unknown argument $1" >&2; exit 2 ;;
	esac
	shift
done
[ ${#phpunit_args[@]} -gt 0 ] || phpunit_args=(--group free-tree)

COPY="$BASE/$PREFIX.$$"
cleanup() {
	if [ "$keep" = true ]; then
		echo "free-tree copy kept at $COPY"
		return
	fi
	rm -rf "$COPY"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

mkdir -p "$COPY"
rsync -a \
	--exclude '/pro/' --exclude '/.git' --exclude '/vendor/' --exclude '/build/' \
	--exclude '/dist/' --exclude '/coverage/' --exclude '/node_modules/' --exclude '/tools/standard/vendor/' \
	"$ROOT/" "$COPY/"
[ ! -e "$COPY/pro" ] || { echo "free-tree: pro/ leaked into the copy" >&2; exit 1; }

# The checkout's vendor/ holds the same locked packages; copying it avoids a
# network install. The autoloader is rebuilt for the copy's own tree.
cp -R "$ROOT/vendor" "$COPY/vendor"
composer dump-autoload --working-dir="$COPY" --quiet --no-interaction

echo "free-tree: php bin/pro-split.php check"
php "$COPY/bin/pro-split.php" check --root="$COPY"

echo "free-tree: phpunit ${phpunit_args[*]}"
bash "$COPY/bin/test-local.sh" -- "${phpunit_args[@]}"
