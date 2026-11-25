# shellcheck shell=bash
#
# The premium zip goes to Freemius, which builds two packages from each
# upload: a premium one, delivered to licensed sites, and a free one, which
# Freemius offers as a download. Every path the plugin's main file header
# lists under @fs_premium_only is left out of the free package (the
# wordpress-sdk README, "two ways to exclude files from your free version").
# scripts/build-release.sh --premium lists the Pro tree and the premium marker
# there, so no free package carries private Pro code. The free zip and the
# wordpress.org builds never carry the tag: the source does not have it, and
# only the premium stage gets it. Sourced; defines variables and functions.
#
# Return codes: 0 done or clean, 1 a finding, 2 the input cannot be used.

# The paths, as the header lists them: relative to the plugin root, each with
# a leading slash, a directory with a trailing one.
WPMCP_PREMIUM_ONLY_PATHS='/pro/, /.wpmcp-pro'

# add_premium_only_tag <main-file>: appends " * @fs_premium_only <paths>" as
# the last line of the file's first docblock (the plugin header), after a
# blank " *" line. Idempotent: a header that already names the tag is left as
# it is. Returns 2 when the file has no docblock.
add_premium_only_tag() {
	local main=$1
	[ -r "$main" ] || { echo "premium-only tag: cannot read $main" >&2; return 2; }
	if grep -q '^ \* @fs_premium_only ' "$main"; then
		return 0
	fi
	grep -q '^/\*\*' "$main" || { echo "premium-only tag: $main has no header docblock" >&2; return 2; }
	WPMCP_PO_PATHS="$WPMCP_PREMIUM_ONLY_PATHS" perl -0pi -e 's{\A(.*?/\*\*.*?\n)( \*/)}{$1 . " *\n * \@fs_premium_only $ENV{WPMCP_PO_PATHS}\n" . $2}se' "$main" \
		|| { echo "premium-only tag: could not edit $main" >&2; return 2; }
	assert_premium_only_tag "$main"
}

# assert_premium_only_tag <main-file>: 0 when the header names exactly the
# Pro tree and the marker under @fs_premium_only, 1 otherwise.
assert_premium_only_tag() {
	local main=$1 line
	line=$(grep -m1 '^ \* @fs_premium_only ' "$main" || true)
	if [ "$line" = " * @fs_premium_only $WPMCP_PREMIUM_ONLY_PATHS" ]; then
		return 0
	fi
	echo "premium-only tag: $main does not list $WPMCP_PREMIUM_ONLY_PATHS under @fs_premium_only" >&2
	return 1
}
