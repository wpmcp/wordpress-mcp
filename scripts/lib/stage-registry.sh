# shellcheck shell=bash
# Sourced by the release builds (D11). stage_registry <stage> <flavor>:
#   1. registry:check for every flavor, so a stale lock or an invalid
#      declaration never reaches a zip;
#   2. writes <stage>/build/registry for <flavor> (no artifacts, no
#      surface.lock writes, so the checkout stays untouched), the compiled
#      registry the plugin reads at runtime (src/Kernel/Kernel_Boot.php);
#   3. drops the module folders <flavor> does not ship and checks that every
#      module it does ship is staged (scripts/lib/stage-registry.php).
# Run it after the flavor's strip and before the class-reference gates.
stage_registry() {
	local stage="$1" flavor="$2"
	php "$ROOT/bin/build-registry.php" --check --all-flavors --quiet || return 1
	php "$ROOT/bin/build-registry.php" --flavor="$flavor" --out="$stage/build/registry" --artifacts= --no-locks --quiet || return 1
	php "$ROOT/scripts/lib/stage-registry.php" "$stage" || return 1
}
