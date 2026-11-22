#!/usr/bin/env bash
# Writes vendor/composer/autoload_classmap.php for a staged tree without
# installing any dependency, for the --check-only mode of the two release
# builds. The class-reference gate only reads WPMCP classes out of that map,
# and they all come from the psr-4 "WPMCP\\" rule, so a manifest holding just
# that rule yields the same answer as the full composer run.
#
# The rule covers src/ always, and pro/src/ and pro/modules/ only when the
# stage carries them (the premium build). A wp.org or free stage never has a
# pro/ directory, so its map stays src/ only.
stage_classmap() {
  local stage="$1" dirs='"src/"' modules='"src/Modules/"'
  [ -d "$stage/pro/src" ] && dirs="$dirs,\"pro/src/\""
  [ -d "$stage/pro/modules" ] && modules="$modules,\"pro/modules/\""
  printf '{"name":"wpmcp/stage","autoload":{"psr-4":{"WPMCP\\\\Modules\\\\":[%s],"WPMCP\\\\":[%s]}}}\n' "$modules" "$dirs" > "$stage/composer.json"
  rm -f "$stage/composer.lock"
  composer dump-autoload --working-dir="$stage" --optimize --classmap-authoritative --quiet --no-interaction
}
