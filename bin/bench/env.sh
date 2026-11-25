#!/usr/bin/env bash
#
# The benchmark environment of bin/bench/run.php (module architecture,
# section 3.6): a throwaway WordPress site served by PHP's built-in server
# with its own MariaDB, measured, then removed.
#
#   bin/bench/env.sh run [options] [-- run.php options]
#   bin/bench/env.sh --prune
#
# What `run` does, in order, with everything under one directory
# ($WPMCP_BENCH_ROOT, default $TMPDIR/wpmcp-bench, one run-<pid> per run):
#   1. Checks free space (under 20 GB it runs --prune first, under 10 GB it
#      stops) and claims the newest ready install of bin/test-local.sh
#      (~/.cache/wpmcp-tests/wp-*-plugins-*, its $dir/.users/<pid> claim, so
#      that cache's prune leaves it alone), then clones its WordPress with
#      the canonical plugins of bin/install-test-plugins.sh (WooCommerce,
#      Elementor, ACF, Yoast SEO, Polylang). An APFS clone (cp -c) costs no
#      space; elsewhere it is a plain copy. The claim is released at once.
#   2. Stages each plugin checkout the way scripts/build-release.sh does
#      (src, assets, languages, composer install --no-dev
#      --optimize-autoloader, the inlined Freemius config), so the site
#      loads what a release zip loads and none of the dev autoloads.
#   3. Starts a private MariaDB (its own datadir and socket, no TCP), installs
#      WordPress with WP-CLI, activates the canonical plugins and WPMCP,
#      creates a throwaway admin with a random password and an application
#      password (kept in a mode 600 file in the run directory, never
#      printed), and copies bin/bench/probe.php in as a must-use plugin.
#   4. Serves the site with `php -S` on a free 127.0.0.1 port: one worker,
#      requests one at a time, opcache on with validate_timestamps=0 unless
#      --opcache=off.
#   5. Runs bin/bench/run.php once per plugin checkout, against the same
#      database, writing <out-dir>/<label>.csv, then bin/bench/compare.php
#      into <out-dir>/compare.md.
#   6. On exit, also on failure, Ctrl-C or TERM: stops the server and
#      MariaDB and removes the run directory. Nothing is left behind.
#
# Options:
#   --php=BIN            the PHP that serves the site (default: Homebrew php@8.1,
#                        else `php`); run.php itself runs on the CLI php
#   --opcache=on|off     default on (validate_timestamps=0)
#   --plugin=LABEL:DIR   a checkout to measure, repeatable (default
#                        baseline:<this checkout>); with two, the second
#                        is compared with the first
#   --out-dir=DIR        where the CSVs and compare.md go (default: the
#                        current directory)
#   --keep               leave the site up after the runs until Ctrl-C, to
#                        look at it (the cleanup still runs on exit)
#   Anything after -- goes to run.php (--runs=30, --warmup=3, --delta,
#   --scenarios=...).
#
# --prune stops the servers and MariaDB of runs whose owner process is gone
# and removes their directories; it is what to run after a crash or a
# kill -9. Nothing outside $WPMCP_BENCH_ROOT is created, apart from the
# composer cache composer keeps anyway.
#
# Needs: PHP with curl, mysqli and opcache; MariaDB (Homebrew mariadb@10.11,
# as bin/test-local.sh); WP-CLI; composer; one install of bin/test-local.sh
# in its cache (any targeted run makes one).
#
# REPO-ONLY, like the rest of bin/.
set -euo pipefail

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
BENCH_ROOT="${WPMCP_BENCH_ROOT:-${TMPDIR:-/tmp}}"
BENCH_ROOT="${BENCH_ROOT%/}"
[ "$(basename "$BENCH_ROOT")" = "wpmcp-bench" ] || BENCH_ROOT="$BENCH_ROOT/wpmcp-bench"
TEST_CACHE="${WPMCP_TEST_CACHE:-$HOME/.cache/wpmcp-tests}"

say() { printf 'bench: %s\n' "$*" >&2; }
die() { say "$*"; exit 1; }

alive() { [ -n "${1:-}" ] && kill -0 "$1" 2>/dev/null; }

# Stops what a run directory started, if it is still running and still ours.
stop_run_dir() {
	local dir=$1 pid
	for f in server.pid mariadbd.pid; do
		[ -f "$dir/$f" ] || continue
		pid=$(cat "$dir/$f" 2>/dev/null || true)
		if alive "$pid" && ps -o command= -p "$pid" 2>/dev/null | grep -qF "$dir"; then
			kill "$pid" 2>/dev/null || true
			for _ in $(seq 1 50); do alive "$pid" || break; sleep 0.1; done
			alive "$pid" && kill -9 "$pid" 2>/dev/null || true
		fi
	done
	# Anything else started for this run (the run.php client) names the
	# run directory on its command line.
	pkill -f -- "$dir/" 2>/dev/null || true
}

prune() {
	[ -d "$BENCH_ROOT" ] || { say "nothing to prune"; return 0; }
	local d owner n=0
	for d in "$BENCH_ROOT"/run-*; do
		[ -d "$d" ] || continue
		owner=$(cat "$d/owner.pid" 2>/dev/null || true)
		if alive "$owner"; then
			say "kept $d (run $owner is alive)"
			continue
		fi
		stop_run_dir "$d"
		rm -rf "$d"
		n=$((n + 1))
		say "removed $d"
	done
	rmdir "$BENCH_ROOT" 2>/dev/null || true
	say "pruned $n run(s)"
}

free_gb() { df -g "$1" 2>/dev/null | awk 'NR==2 {print $4}'; }

find_php() {
	local p
	for p in /opt/homebrew/opt/php@8.1/bin/php /usr/local/opt/php@8.1/bin/php; do
		[ -x "$p" ] && { echo "$p"; return; }
	done
	command -v php
}

find_mariadb() {
	local f prefix
	if [ -n "${WPMCP_MARIADB_BIN:-}" ]; then echo "$WPMCP_MARIADB_BIN"; return; fi
	if command -v brew >/dev/null 2>&1; then
		for f in mariadb@10.11 mariadb@11.4 mariadb@10.6 mariadb; do
			prefix=$(brew --prefix "$f" 2>/dev/null) || continue
			[ -x "$prefix/bin/mariadbd" ] && { echo "$prefix/bin"; return; }
		done
	fi
	command -v mariadbd >/dev/null 2>&1 && { dirname "$(command -v mariadbd)"; return; }
	die "MariaDB not found (brew install mariadb@10.11, or set WPMCP_MARIADB_BIN)"
}

newest_install() {
	local d best=""
	for d in "$TEST_CACHE"/wp-*-plugins-*/; do
		d=${d%/}
		case "$d" in *-live-*) continue ;; esac
		[ -f "$d/.ready" ] && [ -d "$d/wordpress/wp-content/plugins/woocommerce" ] || continue
		if [ -z "$best" ] || [ "$d/.ready" -nt "$best/.ready" ]; then best=$d; fi
	done
	echo "$best"
}

stage_plugin() {
	local src=$1 dest=$2
	mkdir -p "$dest"
	cp "$src/wpmcp.php" "$src/readme.txt" "$src/LICENSE" "$src/composer.json" "$src/composer.lock" "$dest/"
	cp -R "$src/src" "$dest/src"
	cp -R "$src/assets" "$dest/assets"
	rm -rf "$dest/assets/readme"
	mkdir -p "$dest/languages"
	find "$src/languages" -maxdepth 1 \( -name '*.po' -o -name '*.mo' -o -name '*.l10n.php' \) -exec cp {} "$dest/languages/" \;
	composer install --working-dir="$dest" --no-dev --optimize-autoloader --quiet --no-interaction
	rm -f "$dest/composer.json" "$dest/composer.lock"
	if [ -f "$src/scripts/lib/inline-fs-config.php" ]; then
		php "$src/scripts/lib/inline-fs-config.php" "$src" "$dest" >/dev/null
	fi
}

cmd_run() {
	local php_bin="" opcache=on out_dir="$PWD" keep=0
	local -a plugins=() run_args=()
	while [ $# -gt 0 ]; do
		case "$1" in
			--php=*) php_bin=${1#*=} ;;
			--opcache=*) opcache=${1#*=} ;;
			--plugin=*) plugins+=("${1#*=}") ;;
			--out-dir=*) out_dir=${1#*=} ;;
			--keep) keep=1 ;;
			--) shift; run_args=("$@"); break ;;
			*) die "unknown option $1 (see the header of $0)" ;;
		esac
		shift
	done
	[ ${#plugins[@]} -gt 0 ] || plugins=("baseline:$REPO")
	[ -n "$php_bin" ] || php_bin=$(find_php)
	case "$opcache" in on | off) ;; *) die "--opcache is on or off" ;; esac
	command -v wp >/dev/null 2>&1 || die "WP-CLI (wp) not found"
	command -v composer >/dev/null 2>&1 || die "composer not found"
	mkdir -p "$out_dir"
	out_dir=$(cd "$out_dir" && pwd)

	local gb
	gb=$(free_gb "${TMPDIR:-/tmp}")
	if [ -n "$gb" ] && [ "$gb" -lt 20 ]; then
		say "only ${gb} GB free: pruning first"
		prune
		gb=$(free_gb "${TMPDIR:-/tmp}")
		[ "$gb" -ge 10 ] || die "only ${gb} GB free, not starting"
	fi

	local install
	install=$(newest_install)
	[ -n "$install" ] || die "no ready install in $TEST_CACHE; run bin/test-local.sh -- --filter SomeTest once to build one"

	mkdir -p "$BENCH_ROOT"
	RUN_DIR="$BENCH_ROOT/run-$$"
	rm -rf "$RUN_DIR"
	mkdir -p "$RUN_DIR"
	echo $$ >"$RUN_DIR/owner.pid"
	trap 'cleanup' EXIT
	trap 'exit 130' INT
	trap 'exit 143' TERM

	say "cloning $(basename "$install") into $RUN_DIR"
	mkdir -p "$install/.users"
	touch "$install/.users/$$"
	cp -cR "$install/wordpress" "$RUN_DIR/wordpress" 2>/dev/null || cp -R "$install/wordpress" "$RUN_DIR/wordpress"
	rm -f "$install/.users/$$"
	local wp_dir="$RUN_DIR/wordpress"
	rm -f "$wp_dir/wp-config.php"
	mkdir -p "$wp_dir/wp-content/mu-plugins"
	cp "$REPO/bin/bench/probe.php" "$wp_dir/wp-content/mu-plugins/wpmcp-bench-probe.php"

	local spec label dir i=0
	for spec in "${plugins[@]}"; do
		label=${spec%%:*}
		dir=${spec#*:}
		[ -f "$dir/wpmcp.php" ] || die "$dir is not a WPMCP checkout"
		say "staging $label from $dir"
		stage_plugin "$dir" "$RUN_DIR/stage/$label/wpmcp"
		i=$((i + 1))
	done

	# MariaDB, private to this run.
	local bin sock="$RUN_DIR/db.sock"
	bin=$(find_mariadb)
	[ ${#sock} -lt 100 ] || die "socket path too long (${#sock} bytes): set WPMCP_BENCH_ROOT to a shorter directory"
	"$bin/mariadb-install-db" --no-defaults --basedir="$(dirname "$bin")" --datadir="$RUN_DIR/db" \
		--auth-root-authentication-method=normal --skip-test-db >"$RUN_DIR/db-install.log" 2>&1 \
		|| die "mariadb-install-db failed, see $RUN_DIR/db-install.log"
	"$bin/mariadbd" --no-defaults --basedir="$(dirname "$bin")" --datadir="$RUN_DIR/db" \
		--skip-networking --socket="$sock" --pid-file="$RUN_DIR/mariadbd.pid" \
		--log-error="$RUN_DIR/db-error.log" --innodb-buffer-pool-size=128M >/dev/null 2>&1 &
	for _ in $(seq 1 100); do
		"$bin/mariadb-admin" --no-defaults --socket="$sock" -uroot ping >/dev/null 2>&1 && break
		sleep 0.2
	done
	"$bin/mariadb" --no-defaults --socket="$sock" -uroot -e 'CREATE DATABASE wordpress' \
		|| die "MariaDB did not start, see $RUN_DIR/db-error.log"

	local port
	port=$("$php_bin" -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];')
	local url="http://127.0.0.1:$port"
	local admin_pass
	admin_pass=$("$php_bin" -r 'echo bin2hex(random_bytes(16));')

	# WP-CLI on the serving PHP, with room: activating WPMCP with the
	# canonical plugins registers every ability inside one CLI process.
	local wp_phar
	wp_phar=$(realpath "$(command -v wp)")
	local wp=("$php_bin" -d memory_limit=1G -d display_errors=stderr "$wp_phar" --path="$wp_dir" --quiet)
	"${wp[@]}" config create --dbname=wordpress --dbuser=root --dbpass= --dbhost="localhost:$sock" --skip-check \
		--extra-php <<PHP
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_DEBUG', false );
define( 'DISABLE_WP_CRON', true );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WPMCP_BENCH', true );
define( 'WPMCP_BENCH_LOG', '$RUN_DIR/probe.jsonl' );
PHP
	"${wp[@]}" core install --url="$url" --title="WPMCP bench" --admin_user=benchadmin \
		--admin_password="$admin_pass" --admin_email=bench@example.invalid --skip-email
	"${wp[@]}" rewrite structure '/%postname%/'
	"${wp[@]}" plugin activate woocommerce elementor advanced-custom-fields wordpress-seo polylang

	cat >"$RUN_DIR/router.php" <<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = $_SERVER['DOCUMENT_ROOT'] . $path;
if ('/' !== $path && file_exists($file) && ! is_dir($file)) {
    return false;
}
if (is_dir($file) && is_file(rtrim($file, '/') . '/index.php')) {
    $_SERVER['SCRIPT_NAME']     = rtrim($path, '/') . '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = rtrim($file, '/') . '/index.php';
    $_SERVER['PHP_SELF']        = $_SERVER['SCRIPT_NAME'];
    chdir(dirname($_SERVER['SCRIPT_FILENAME']));
    require $_SERVER['SCRIPT_FILENAME'];
    return;
}
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] . '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
require $_SERVER['SCRIPT_FILENAME'];
PHP

	local -a ini=(-d display_errors=0 -d log_errors=1 -d "error_log=$RUN_DIR/php-error.log" -d memory_limit=256M)
	if [ on = "$opcache" ]; then
		ini+=(-d opcache.enable=1 -d opcache.validate_timestamps=0 -d opcache.memory_consumption=256
			-d opcache.interned_strings_buffer=32 -d opcache.max_accelerated_files=50000 -d opcache.jit=off)
	else
		ini+=(-d opcache.enable=0)
	fi

	local app_pass first=1 csv
	for spec in "${plugins[@]}"; do
		label=${spec%%:*}
		rm -rf "$wp_dir/wp-content/plugins/wpmcp"
		cp -R "$RUN_DIR/stage/$label/wpmcp" "$wp_dir/wp-content/plugins/wpmcp"
		if [ 1 = "$first" ]; then
			"${wp[@]}" plugin activate wpmcp
			app_pass=$("${wp[@]}" user application-password create benchadmin bench --porcelain 2>/dev/null | tail -n 1)
			[ ${#app_pass} -eq 24 ] || die "could not create the application password"
			(umask 077 && printf 'url=%s\nuser=benchadmin\napp_password=%s\nlogin_password=%s\n' "$url" "$app_pass" "$admin_pass" >"$RUN_DIR/credentials")
			# Activation redirects a first wp-admin visit to the plugins' welcome screens.
			"${wp[@]}" transient delete _wc_activation_redirect >/dev/null 2>&1 || true
			"${wp[@]}" option delete elementor_activation_redirect >/dev/null 2>&1 || true
			"${wp[@]}" transient delete _wpseo_activation_redirect >/dev/null 2>&1 || true
			first=0
		fi

		# One server per checkout, so opcache never serves the previous one.
		stop_server
		(cd "$wp_dir" && exec "$php_bin" "${ini[@]}" -S "127.0.0.1:$port" -t "$wp_dir" "$RUN_DIR/router.php" \
			>>"$RUN_DIR/server.log" 2>&1) &
		echo $! >"$RUN_DIR/server.pid"
		disown $!
		for _ in $(seq 1 100); do
			curl -s -o /dev/null "$url/wp-login.php" && break
			sleep 0.1
		done
		say "measuring $label on $url ($("$php_bin" -r 'echo PHP_VERSION;'), opcache $opcache)"
		csv="$out_dir/$label.csv"
		WPMCP_BENCH_APP_PASSWORD="$app_pass" WPMCP_BENCH_LOGIN_PASSWORD="$admin_pass" \
			php "$REPO/bin/bench/run.php" --url="$url" --user=benchadmin --label="$label" \
			--probe-log="$RUN_DIR/probe.jsonl" --out="$csv" "${run_args[@]+"${run_args[@]}"}" &
		# In the background and waited for, so TERM and Ctrl-C reach the
		# trap at once instead of after the whole run.
		wait $!
	done

	local -a csvs=()
	for spec in "${plugins[@]}"; do csvs+=("$out_dir/${spec%%:*}.csv"); done
	php "$REPO/bin/bench/compare.php" "${csvs[@]}" >"$out_dir/compare.md"
	say "wrote ${csvs[*]} and $out_dir/compare.md"
	if [ -s "$RUN_DIR/php-error.log" ]; then
		cp "$RUN_DIR/php-error.log" "$out_dir/php-error.log"
		say "PHP logged errors, copied to $out_dir/php-error.log"
	fi

	if [ 1 = "$keep" ]; then
		say "site up at $url (credentials in $RUN_DIR/credentials); Ctrl-C removes it"
		while alive "$(cat "$RUN_DIR/server.pid" 2>/dev/null)"; do sleep 1 & wait $!; done
	fi
}

stop_server() {
	local pid
	pid=$(cat "$RUN_DIR/server.pid" 2>/dev/null || true)
	if alive "$pid"; then
		pkill -P "$pid" 2>/dev/null || true
		kill "$pid" 2>/dev/null || true
		for _ in $(seq 1 50); do alive "$pid" || break; sleep 0.1; done
	fi
	rm -f "$RUN_DIR/server.pid"
}

cleanup() {
	local code=$?
	trap - EXIT INT TERM
	if [ -n "${RUN_DIR:-}" ] && [ -d "$RUN_DIR" ]; then
		stop_server
		stop_run_dir "$RUN_DIR"
		rm -rf "$RUN_DIR"
		rmdir "$BENCH_ROOT" 2>/dev/null || true
		say "cleaned up $RUN_DIR"
	fi
	exit "$code"
}

case "${1:-}" in
	--prune | prune) prune ;;
	run) shift; cmd_run "$@" ;;
	*) sed -n '2,60p' "$0" | sed 's/^# \{0,1\}//'; exit 2 ;;
esac
