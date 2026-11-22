#!/usr/bin/env bash
# Tests for scripts/lib/pro-leak-gate.sh against small synthetic zips.
# Runs in a few seconds, needs only bash, zip and unzip, and removes its
# scratch directory on exit (including failure and Ctrl-C).
#
#   bash tests/build/pro-leak-gate-test.sh
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
# shellcheck source=scripts/lib/pro-leak-gate.sh
. "$ROOT/scripts/lib/pro-leak-gate.sh"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
trap 'exit 130' INT TERM

pass=0
failures=0
expect() {
	local want=$1 label=$2 got
	shift 2
	"$@" 2>"$WORK/err"
	got=$?
	if [ "$got" = "$want" ]; then
		pass=$((pass + 1))
	else
		failures=$((failures + 1))
		echo "FAIL: $label (expected $want, got $got)" >&2
		sed 's/^/    /' "$WORK/err" >&2
	fi
}

cat > "$WORK/manifest.txt" <<'TXT'
# Pro-only paths, original public locations.

src/Tools/Cloud/
  src/Tools/Media/Optimize_Media.php
./src/Pro
TXT

# make_tree <name> <root> <relative paths...>: a tree under $WORK/<name>/<root>
# and its zip at $WORK/<name>.zip.
make_tree() {
	local name=$1 root=$2 rel
	shift 2
	rm -rf "${WORK:?}/$name" "$WORK/$name.zip"
	mkdir -p "$WORK/$name/$root"
	for rel in "$@"; do
		mkdir -p "$(dirname "$WORK/$name/$root/$rel")"
		case "$rel" in
			src/Freemius/wpmcp-fs.php) printf "<?php\nfs_dynamic_init(array (\n  'is_premium' => %s,\n));\n" "${FS_PREMIUM:-false}" > "$WORK/$name/$root/$rel" ;;
			.wpmcp-pro) printf '%s\n' "${MARKER_TEXT:-$WPMCP_PREMIUM_MARKER_TEXT}" > "$WORK/$name/$root/$rel" ;;
			*) echo x > "$WORK/$name/$root/$rel" ;;
		esac
	done
	(cd "$WORK/$name" && zip -rq "$WORK/$name.zip" "$root")
}

FREE_BASE=(wpmcp.php src/Plugin.php src/Tools/Media/Upload.php src/Freemius/wpmcp-fs.php vendor/autoload.php)
M="$WORK/manifest.txt"

# Manifest parsing.
expect 0 "manifest parses" eval 'pro_manifest_paths "$M" >/dev/null'
got="$(pro_manifest_paths "$M" | tr '\n' ' ')"
[ "$got" = "src/Tools/Cloud/ src/Tools/Media/Optimize_Media.php src/Pro " ] \
	&& pass=$((pass + 1)) || { failures=$((failures + 1)); echo "FAIL: manifest entries: $got" >&2; }
printf 'src/ok.php\n../etc/passwd\n' > "$WORK/bad1.txt"
expect 2 "manifest rejects .." eval 'pro_manifest_paths "$WORK/bad1.txt" >/dev/null'
printf '/src/abs.php\n' > "$WORK/bad2.txt"
expect 2 "manifest rejects absolute" pro_manifest_paths "$WORK/bad2.txt"
expect 2 "missing manifest" pro_manifest_paths "$WORK/none.txt"
expect 2 "missing manifest, strict" pro_manifest_ready "$WORK/none.txt" 1
expect 1 "missing manifest, lenient" pro_manifest_ready "$WORK/none.txt" 0
expect 0 "present manifest" pro_manifest_ready "$M" 1

# Free zip checks.
make_tree clean wpmcp "${FREE_BASE[@]}"
expect 0 "clean free zip" assert_no_pro_paths "$WORK/clean.zip" wpmcp "$M"
expect 0 "clean free zip, full free check" assert_free_zip "$WORK/clean.zip" wpmcp "$M"
expect 0 "clean stage" assert_stage_no_pro_paths "$WORK/clean/wpmcp" "$M"

make_tree dirleak wpmcp "${FREE_BASE[@]}" src/Tools/Cloud/Sync.php
expect 1 "directory entry leak" assert_no_pro_paths "$WORK/dirleak.zip" wpmcp "$M"
expect 1 "directory entry leak, stage" assert_stage_no_pro_paths "$WORK/dirleak/wpmcp" "$M"

make_tree fileleak wpmcp "${FREE_BASE[@]}" src/Tools/Media/Optimize_Media.php
expect 1 "file entry leak" assert_no_pro_paths "$WORK/fileleak.zip" wpmcp "$M"

make_tree noslash wpmcp "${FREE_BASE[@]}" src/Pro/Gate.php
expect 1 "directory listed without a slash" assert_no_pro_paths "$WORK/noslash.zip" wpmcp "$M"

make_tree sibling wpmcp "${FREE_BASE[@]}" src/Tools/CloudX/Other.php src/Tools/Media/Optimize_Media.php.bak src/Profile.php
expect 0 "similar names are not leaks" assert_no_pro_paths "$WORK/sibling.zip" wpmcp "$M"

make_tree prodir wpmcp "${FREE_BASE[@]}" pro/bootstrap.php
expect 1 "pro/ in a free zip" assert_no_pro_paths "$WORK/prodir.zip" wpmcp "$M"
expect 1 "pro/ in a free stage" assert_stage_no_pro_paths "$WORK/prodir/wpmcp" "$M"

make_tree marker wpmcp "${FREE_BASE[@]}" .wpmcp-pro
expect 1 "marker in a free zip" assert_no_pro_paths "$WORK/marker.zip" wpmcp "$M"

FS_PREMIUM=true make_tree fstrue wpmcp "${FREE_BASE[@]}"
expect 1 "free zip inlining is_premium true" assert_free_zip "$WORK/fstrue.zip" wpmcp "$M"
expect 2 "unreadable zip" assert_no_pro_paths "$WORK/none.zip" wpmcp "$M"

# Premium zip checks.
PREMIUM_BASE=(wpmcp.php src/Plugin.php src/Freemius/wpmcp-fs.php vendor/autoload.php .wpmcp-pro pro/bootstrap.php pro/src/Tools/Cloud/Sync.php)
FS_PREMIUM=true make_tree prem wpmcp-pro "${PREMIUM_BASE[@]}"
expect 0 "valid premium zip" assert_premium_zip "$WORK/prem.zip" "$M"

FS_PREMIUM=false make_tree premfs wpmcp-pro "${PREMIUM_BASE[@]}"
expect 1 "premium zip inlining is_premium false" assert_premium_zip "$WORK/premfs.zip" "$M"

FS_PREMIUM=true make_tree premroot wpmcp "${PREMIUM_BASE[@]}"
expect 1 "premium zip under wpmcp/" assert_premium_zip "$WORK/premroot.zip" "$M"

FS_PREMIUM=true make_tree premnomark wpmcp-pro wpmcp.php src/Freemius/wpmcp-fs.php pro/bootstrap.php
expect 1 "premium zip without the marker" assert_premium_zip "$WORK/premnomark.zip" "$M"

FS_PREMIUM=true MARKER_TEXT='something else' make_tree premtext wpmcp-pro "${PREMIUM_BASE[@]}"
expect 1 "premium marker with the wrong text" assert_premium_zip "$WORK/premtext.zip" "$M"

FS_PREMIUM=true make_tree premnoboot wpmcp-pro wpmcp.php src/Freemius/wpmcp-fs.php .wpmcp-pro pro/src/A.php
expect 1 "premium zip without pro/bootstrap.php" assert_premium_zip "$WORK/premnoboot.zip" "$M"

FS_PREMIUM=true make_tree premtests wpmcp-pro "${PREMIUM_BASE[@]}" pro/tests/ATest.php
expect 1 "premium zip with pro/tests" assert_premium_zip "$WORK/premtests.zip" "$M"

FS_PREMIUM=true make_tree premgit wpmcp-pro "${PREMIUM_BASE[@]}" pro/.github/workflows/ci.yml
expect 1 "premium zip with a dotfile under pro/" assert_premium_zip "$WORK/premgit.zip" "$M"

FS_PREMIUM=true make_tree prempublic wpmcp-pro "${PREMIUM_BASE[@]}" src/Tools/Cloud/Sync.php
expect 1 "premium zip with a Pro path at its public location" assert_premium_zip "$WORK/prempublic.zip" "$M"

expect 1 "a premium zip is not a free zip" assert_free_zip "$WORK/prem.zip" wpmcp-pro "$M"

echo "pro-leak-gate: $pass passed, $failures failed"
[ "$failures" = 0 ]
