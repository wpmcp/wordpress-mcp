#!/usr/bin/env bash
# Zip-listing gates shared by the release builds (issue #167). Sourced, never
# executed: it defines two functions and nothing else.
#
#   zip_excludes <zip> <basename>...
#   zip_includes <zip> <path>...
#
# Returns 0 when no entry of the zip ends in any of the basenames, 1 (with
# every offending entry on stderr) when one does, and 2 when the listing
# itself cannot be read, so a zip that does not exist or is corrupt is not
# reported clean.
#
# The listing is read into memory once and grepped from there. The obvious
# `unzip -l "$zip" | grep -q "/$name\$"` is wrong under `set -o pipefail`:
# grep -q exits at its first match, unzip then takes SIGPIPE writing the rest
# of the listing, the pipeline's status becomes 141 and an `if` on it reads
# the hit as a miss. Whether that happens depends on where the entry sits in
# the listing, and src/ sorts before the thousands of vendor/ entries, so the
# case the check exists for is the one it would get wrong.
zip_excludes() {
  local zip="$1"
  shift
  local listing
  listing="$(unzip -Z1 -- "$zip")" || return 2

  local name hits status=0
  for name in "$@"; do
    # grep reads the here-string to EOF; nothing can close the pipe early.
    hits="$(grep -E -- "(^|/)${name}\$" <<<"$listing" || true)"
    if [ -n "$hits" ]; then
      echo "$hits" >&2
      status=1
    fi
  done
  return "$status"
}

# zip_includes <zip> <path>...
#
# Returns 0 when the zip holds every path (an exact entry, such as
# wpmcp/build/registry/boot.php), 1 (with each missing path on stderr) when
# one is missing, and 2 when the listing cannot be read. The release builds
# use it for the compiled module registry: a zip without it boots with no
# modules.
zip_includes() {
  local zip="$1"
  shift
  local listing
  listing="$(unzip -Z1 -- "$zip")" || return 2

  local path status=0
  for path in "$@"; do
    if ! grep -Fxq -- "$path" <<<"$listing"; then
      echo "missing: $path" >&2
      status=1
    fi
  done
  return "$status"
}
