# shellcheck shell=bash
#
# The Pro leak gate, shared by every release build (scripts/build-release.sh
# in both modes, scripts/build-wporg-release.sh, scripts/build-woo-release.sh)
# and by scripts/release-assets.sh. Source it; it defines variables and
# functions only.
#
# The Pro code lives in the private submodule at pro/. pro-manifest.txt at the
# repository root lists every Pro-only path at its ORIGINAL public location,
# one repo-relative path per line (a directory ends in "/"); blank lines and
# lines starting with "#" are ignored. After the move each listed path lives
# at pro/<path>, so none of them may appear at its public location in any
# build that is not the premium build, and the premium build carries them
# only under pro/.
#
# Return codes, for every assert_* function: 0 clean, 1 a finding (each
# offending entry printed on stderr), 2 the gate could not do its job (a zip
# or manifest that cannot be read, a malformed manifest entry). A gate that
# cannot read its input must never report clean.
#
# Every zip listing is captured into a variable first and searched from
# there, never piped into grep -q (see scripts/lib/zip-gate.sh for why).

# The marker file the premium build carries at its root, and its one line.
WPMCP_PREMIUM_MARKER='.wpmcp-pro'
WPMCP_PREMIUM_MARKER_TEXT='WP MCP premium build'

# The root folders of the two self-hosted zips.
WPMCP_FREE_ROOT='wpmcp'
WPMCP_PREMIUM_ROOT='wpmcp-pro'

# pro_manifest_file <repo-root>: the manifest path. WPMCP_PRO_MANIFEST
# overrides it (used by the gate's own tests to point at a fixture).
pro_manifest_file() {
	echo "${WPMCP_PRO_MANIFEST:-$1/pro-manifest.txt}"
}

# pro_manifest_ready <manifest> <strict>: 0 when the manifest is readable.
# When it is missing, strict 1 prints an error and returns 2 (the release
# builds), strict 0 prints a warning and returns 1 so the caller can skip the
# manifest half of the gate (the --check-only modes, so `composer
# check:flavors` keeps working on a branch that predates the manifest).
pro_manifest_ready() {
	if [ -r "$1" ]; then
		# Before the split runs (bin/pro-split.php move), the Pro code still
		# sits at its public paths and Plugin.php carries no edition slot
		# calls; the manifest describes a tree that does not exist yet.
		local plugin
		plugin="$(dirname "$1")/src/Plugin.php"
		if [ -z "${WPMCP_PRO_MANIFEST:-}" ] && [ -f "$plugin" ] && ! grep -q 'edition()->run(' "$plugin"; then
			if [ "${2:-1}" = 1 ]; then
				echo "pro leak gate: the tree is not split yet (run php bin/pro-split.php move); refusing to build" >&2
				return 2
			fi
			echo "pro leak gate: warning: the tree is not split yet; skipping the manifest check" >&2
			return 1
		fi
		return 0
	fi
	if [ "${2:-1}" = 1 ]; then
		echo "pro leak gate: no Pro manifest at $1; the gate cannot run without it" >&2
		return 2
	fi
	echo "pro leak gate: warning: no Pro manifest at $1; skipping the manifest check" >&2
	return 1
}

# pro_manifest_paths <manifest>: prints each listed path, trimmed, comments
# and blank lines dropped, a leading "./" removed. Returns 2 when the file
# cannot be read or an entry is absolute, climbs out with "..", or is empty
# after trimming a lone "/".
pro_manifest_paths() {
	local manifest=$1
	[ -r "$manifest" ] || { echo "pro leak gate: cannot read the manifest $manifest" >&2; return 2; }
	awk '
		{ sub(/\r$/, ""); sub(/^[ \t]+/, ""); sub(/[ \t]+$/, "") }
		$0 == "" || substr($0, 1, 1) == "#" { next }
		{
			p = $0
			sub(/^\.\//, "", p)
			if (p == "" || p == "/" || substr(p, 1, 1) == "/" || p ~ /(^|\/)\.\.(\/|$)/ || p ~ /[ \t]/) {
				printf "pro leak gate: malformed manifest entry on line %d: \"%s\"\n", NR, $0 > "/dev/stderr"
				bad = 1
				next
			}
			print p
		}
		END { exit bad ? 2 : 0 }
	' "$manifest"
}

# _plg_match <root-prefix> <paths> <entries>: prints every entry that is a
# manifest path under <root-prefix>, the root's pro/ tree, or the marker.
# <root-prefix> is "wpmcp/" for a zip listing and "" for a staged tree.
# A path ending in "/" matches as a prefix; any other path matches exactly
# or as a directory prefix (path + "/"). The path list goes through the
# environment, not awk -v, which cannot carry newlines. A non-zero status
# means awk itself failed; callers turn that into return 2, never a pass.
_plg_match() {
	PLG_ROOT="$1" PLG_PATHS="$2" PLG_MARKER="$WPMCP_PREMIUM_MARKER" awk '
		BEGIN {
			root = ENVIRON["PLG_ROOT"]; marker = ENVIRON["PLG_MARKER"]
			n = split(ENVIRON["PLG_PATHS"], list, "\n")
			for (i = 1; i <= n; i++) { if (list[i] != "") { want[++m] = root list[i] } }
			want[++m] = root "pro/"
		}
		{
			e = $0
			if (e == root marker || e == root "pro") { print e; next }
			for (i = 1; i <= m; i++) {
				w = want[i]
				if (substr(w, length(w)) == "/") {
					if (substr(e, 1, length(w)) == w || e "/" == w) { print e; next }
				} else if (e == w || substr(e, 1, length(w) + 1) == w "/") {
					print e; next
				}
			}
		}
	' <<<"$3"
}

# assert_no_pro_paths <zip> <root-folder> <manifest>
# Fails when the zip carries any manifest path as <root>/<path> (file, or
# directory prefix), anything under <root>/pro/, or <root>/.wpmcp-pro.
assert_no_pro_paths() {
	local zip=$1 root=${2%/} manifest=$3 paths listing hits
	paths=$(pro_manifest_paths "$manifest") || return 2
	listing=$(unzip -Z1 -- "$zip") || { echo "pro leak gate: cannot list $zip" >&2; return 2; }
	hits=$(_plg_match "$root/" "$paths" "$listing") || { echo "pro leak gate: matching failed for $zip" >&2; return 2; }
	if [ -n "$hits" ]; then
		echo "pro leak gate: $zip carries Pro-only paths:" >&2
		echo "$hits" | sed 's/^/  /' >&2
		return 1
	fi
	return 0
}

# assert_stage_no_pro_paths <stage-dir> <manifest>
# The same question asked of a staged tree before it is zipped (and by the
# --check-only modes, which never zip).
assert_stage_no_pro_paths() {
	local stage=${1%/} manifest=$2 paths listing hits
	paths=$(pro_manifest_paths "$manifest") || return 2
	[ -d "$stage" ] || { echo "pro leak gate: no staged tree at $stage" >&2; return 2; }
	# Directories are listed with a trailing "/", the way a zip lists them.
	listing=$(cd "$stage" && { find . -mindepth 1 -type d | sed 's|$|/|'; find . -mindepth 1 ! -type d; } | sed 's|^\./||') \
		|| { echo "pro leak gate: cannot walk $stage" >&2; return 2; }
	hits=$(_plg_match "" "$paths" "$listing") || { echo "pro leak gate: matching failed for $stage" >&2; return 2; }
	if [ -n "$hits" ]; then
		echo "pro leak gate: the stage $stage carries Pro-only paths:" >&2
		echo "$hits" | sed 's/^/  /' >&2
		return 1
	fi
	return 0
}

# assert_fs_is_premium <zip> <root-folder> <true|false>
# The licensing config inlined into <root>/src/Freemius/wpmcp-fs.php by
# scripts/lib/inline-fs-config.php must declare 'is_premium' => <value>.
assert_fs_is_premium() {
	local zip=$1 root=${2%/} want=$3 fs
	fs=$(unzip -p -- "$zip" "$root/src/Freemius/wpmcp-fs.php" 2>/dev/null) \
		|| { echo "pro leak gate: $zip has no $root/src/Freemius/wpmcp-fs.php" >&2; return 2; }
	[ -n "$fs" ] || { echo "pro leak gate: $root/src/Freemius/wpmcp-fs.php in $zip is empty" >&2; return 2; }
	if ! grep -Eq "'is_premium' => $want," <<<"$fs"; then
		echo "pro leak gate: $zip does not inline 'is_premium' => $want" >&2
		grep -E "'is_premium'" <<<"$fs" | sed 's/^/  found: /' >&2
		return 1
	fi
	return 0
}

# assert_free_zip <zip> <root-folder> <manifest>
# The free self-hosted zip, and any zip that is not the premium build: no
# Pro path, no pro/, no marker, and the inlined config says is_premium false.
assert_free_zip() {
	local zip=$1 root=${2%/} manifest=$3
	assert_no_pro_paths "$zip" "$root" "$manifest" || return $?
	assert_fs_is_premium "$zip" "$root" false
}

# assert_premium_zip <zip> [<manifest>]
# The premium zip: root folder wpmcp-pro/ and nothing else at the top level,
# the marker with its exact text, pro/src/Pro/bootstrap.php, no pro/tests and no
# dotfile anywhere under pro/, the inlined config saying is_premium true, and,
# when a manifest is given, no manifest path left at its public location.
assert_premium_zip() {
	local zip=$1 manifest=${2:-} root=$WPMCP_PREMIUM_ROOT listing tops marker bad paths hits
	listing=$(unzip -Z1 -- "$zip") || { echo "pro leak gate: cannot list $zip" >&2; return 2; }
	tops=$(awk -F/ '{ print $1 }' <<<"$listing" | sort -u)
	if [ "$tops" != "$root" ]; then
		echo "pro leak gate: $zip must hold exactly one root folder, $root/; found:" >&2
		echo "$tops" | sed 's/^/  /' >&2
		return 1
	fi
	grep -Fxq -- "$root/$WPMCP_PREMIUM_MARKER" <<<"$listing" \
		|| { echo "pro leak gate: $zip has no $root/$WPMCP_PREMIUM_MARKER marker" >&2; return 1; }
	marker=$(unzip -p -- "$zip" "$root/$WPMCP_PREMIUM_MARKER" | tr -d '\r')
	[ "$marker" = "$WPMCP_PREMIUM_MARKER_TEXT" ] \
		|| { echo "pro leak gate: $root/$WPMCP_PREMIUM_MARKER in $zip does not read \"$WPMCP_PREMIUM_MARKER_TEXT\"" >&2; return 1; }
	grep -Fxq -- "$root/pro/src/Pro/bootstrap.php" <<<"$listing" \
		|| { echo "pro leak gate: $zip has no $root/pro/src/Pro/bootstrap.php" >&2; return 1; }
	# pro/tests, or any path under pro/ with a component that starts with a
	# dot (.git, .github, .gitignore, editor files).
	bad=$(grep -E "^$root/pro/(tests(/|\$)|(.*/)?\.)" <<<"$listing" || true)
	if [ -n "$bad" ]; then
		echo "pro leak gate: $zip carries development files:" >&2
		echo "$bad" | sed 's/^/  /' >&2
		return 1
	fi
	if [ -n "$manifest" ]; then
		paths=$(pro_manifest_paths "$manifest") || return 2
		# Only the public locations are checked here: the premium build
		# legitimately carries pro/ and the marker.
		hits=$(_plg_match "$root/" "$paths" "$listing") || { echo "pro leak gate: matching failed for $zip" >&2; return 2; }
		hits=$(grep -Ev "^$root/(pro(/|\$)|\.wpmcp-pro\$)" <<<"$hits" || true)
		if [ -n "$hits" ]; then
			echo "pro leak gate: $zip carries Pro paths at their public locations, not under pro/:" >&2
			echo "$hits" | sed 's/^/  /' >&2
			return 1
		fi
	fi
	assert_fs_is_premium "$zip" "$root" true
}
