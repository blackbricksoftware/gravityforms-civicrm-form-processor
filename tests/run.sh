#!/usr/bin/env bash
# tests/run.sh - the plugin's updates, end to end, in a REAL throwaway WordPress.
#
# WHAT IT DOES: a WordPress (latest) on SQLite, downloaded once into .sandbox/ (git-ignored) and reused. The plugin is
# installed from a zip built by bin/build.sh (run here, which replaces dist/), or from GFCFP_TEST_ZIP when that is set
# (CI passes the zip it is about to publish). Gravity Forms is not installed (it isn't free), so this covers what works
# without it: the site loads, and updates. With GitHub and wordpress.org's update checks replaced by stand-ins
# (tests/sandbox.php), WordPress is offered a release and installs it: from the Plugins page's code path and from
# WP-Cron's auto-updater, and into the folder the plugin is in even when that isn't the one the zip names. Only the
# last section uses the network after the one-time download: when the newest real GitHub release carries a zip the
# updater recognises, the upgrade from it to this build is tested too, through the released plugin's own updater code,
# and the plugin's real request to GitHub must find it.
#
# Needs php (with pdo_sqlite), wp (the WP-CLI phar), curl, unzip, zip and perl, and composer to build the zip. PHP_BIN
# picks the php that runs WordPress and WP-CLI (default: php); CI runs it on 8.2. GFCFP_TEST_GITHUB_TOKEN, when set, is
# used for the test's own lookups of the newest release (CI passes the workflow's token), never by the plugin.
# GFCFP_TEST_GF_DIR, a folder holding gravityforms_*.zip and gravityformswebhooks_*.zip (a licensed copy), adds the
# checks of sending a webhook again from an entry's page; without it they are skipped (CI has no Gravity Forms).
#
# NOT pipefail, deliberately: checks here are `producer | grep -q`, and under pipefail grep's early exit hands the
# producer a SIGPIPE, so a MATCH reads as a failure. Without it a pipeline's status is its last command's, which is
# exactly what these checks mean.
set -eu

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
T="$ROOT/tests"
SB="$ROOT/.sandbox"
WP="$SB/site"
PLUGINS="$WP/wp-content/plugins"
WPPORT="${GFCFP_TEST_WP_PORT:-8090}"
BASE="http://127.0.0.1:$WPPORT"
PHP="${PHP_BIN:-php}"
SLUG=gravityforms-civicrm-form-processor
LEGACY=gravity_forms_civicrm_form_processor   # The folder release zips up to 2.0.0 installed into.
REPO=blackbricksoftware/gravityforms-civicrm-form-processor

NEEDS="$PHP wp curl unzip zip perl"
[ -n "${GFCFP_TEST_ZIP:-}" ] || NEEDS="$NEEDS composer"
for c in $NEEDS; do command -v "$c" >/dev/null || { echo "missing: $c"; exit 2; }; done
"$PHP" -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' || { echo "$PHP has no pdo_sqlite, which the sandbox WordPress needs"; exit 2; }
WPBIN="$(command -v wp)"
# WP-CLI on the chosen PHP. A function, so every `wp` below goes through it.
wp() { "$PHP" -d memory_limit=256M -d 'error_reporting=E_ALL&~E_DEPRECATED' "$WPBIN" "$@"; }
# That only works if `wp` is the WP-CLI phar. Run through php, a shell-script wrapper just prints itself and exits 0,
# and every check below would then fail for a reason nowhere in its output.
wp cli version 2>/dev/null | grep -q '^WP-CLI ' || { echo "$WPBIN is not the WP-CLI phar ($PHP cannot run it)"; exit 2; }
if lsof -ti "tcp:$WPPORT" >/dev/null 2>&1; then echo "port $WPPORT is in use; set GFCFP_TEST_WP_PORT"; exit 2; fi

wpq() { wp --path="$WP" "$@" 2>&1 | grep -v -i deprecat || true; }
# debug.log is emptied now and then; whatever warnings it held are kept here first, for the final check.
WARN="$SB/warnings.log"
rotate_log() { grep -iE "php (warning|fatal|parse)" "$WP/wp-content/debug.log" 2>/dev/null | grep -v -i deprecat >> "$WARN" || true; : > "$WP/wp-content/debug.log"; }
FAILS=0
pass() { echo "PASS $*"; }
fail() { echo "FAIL $*"; FAILS=$((FAILS + 1)); }
expect() { if [ "$2" = "$3" ]; then pass "$1"; else fail "$1"; echo "     expected: $3"; echo "     got:      $2"; fi; }

# 1. The sandbox, once. WordPress via curl, not `wp core download` (its PharData extraction fails on PHP 8.5).
mkdir -p "$SB"
if [ ! -f "$WP/wp-config.php" ]; then
  echo "== building the sandbox WordPress (one-time download)"
  mkdir -p "$SB/dl"
  curl -fsSL --retry 3 --max-time 300 -o "$SB/dl/wp.tar.gz" https://wordpress.org/latest.tar.gz
  tar xzf "$SB/dl/wp.tar.gz" -C "$SB/dl" && rm -rf "$WP" && mv "$SB/dl/wordpress" "$WP"
  curl -fsSL --retry 3 --max-time 300 -o "$SB/dl/sqlite.zip" https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip
  unzip -qo "$SB/dl/sqlite.zip" -d "$PLUGINS/"
  sed "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$PLUGINS/sqlite-database-integration#; s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
    "$PLUGINS/sqlite-database-integration/db.copy" > "$WP/wp-content/db.php"
  cat > "$WP/wp-config.php" <<'EOF'
<?php
define( 'DB_NAME', 'wp' ); define( 'DB_USER', 'u' ); define( 'DB_PASSWORD', 'p' ); define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8' ); define( 'DB_COLLATE', '' );
define( 'AUTH_KEY', 'sandbox' ); define( 'SECURE_AUTH_KEY', 'sandbox' ); define( 'LOGGED_IN_KEY', 'sandbox' ); define( 'NONCE_KEY', 'sandbox' );
define( 'AUTH_SALT', 'sandbox' ); define( 'SECURE_AUTH_SALT', 'sandbox' ); define( 'LOGGED_IN_SALT', 'sandbox' ); define( 'NONCE_SALT', 'sandbox' );
define( 'WP_DEBUG', ! file_exists( __DIR__ . '/wp-content/gfcfp-debug-off' ) ); define( 'WP_DEBUG_LOG', true ); define( 'WP_DEBUG_DISPLAY', false );
define( 'FS_METHOD', 'direct' );
$table_prefix = 'wp_';
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
EOF
  wpq core install --url="$BASE" --title=sandbox --admin_user=admin --admin_password=admin --admin_email=sandbox@example.com --skip-email
fi
# WP_DEBUG is on, except while wp-content/gfcfp-debug-off exists, as on a live site (a sandbox built before that was
# possible gets the switch here).
sed -i "s#define( 'WP_DEBUG', true );#define( 'WP_DEBUG', ! file_exists( __DIR__ . '/wp-content/gfcfp-debug-off' ) );#" "$WP/wp-config.php"
rm -f "$WP/wp-content/gfcfp-debug-off"
# The site's address follows this run's port (GFCFP_TEST_WP_PORT), not the first run's: WordPress redirects to its
# address, and the auto-updater's after-update check loads it.
wpq option update home "$BASE" > /dev/null; wpq option update siteurl "$BASE" > /dev/null
echo "== WordPress $(sed -n "s/^\$wp_version = '\(.*\)';/\1/p" "$WP/wp-includes/version.php"), PHP $("$PHP" -r 'echo PHP_VERSION;')"

# 2. The zip that ships, and the plugin installed from it; the sandbox's stand-ins beside it.
ZIP="${GFCFP_TEST_ZIP:-}"
if [ -z "$ZIP" ]; then
  ALLOW_DIRTY=1 "$ROOT/bin/build.sh" > "$SB/build.log" 2>&1 || { cat "$SB/build.log"; exit 1; }
  ZIP="$(sed -n 's/^== \(dist\/.*\.zip\)$/\1/p' "$SB/build.log")"
fi
case "$ZIP" in /*) ;; *) ZIP="$ROOT/$ZIP" ;; esac
[ -f "$ZIP" ] || { echo "no zip at $ZIP"; exit 2; }
VERSION="$(unzip -p "$ZIP" "$SLUG/$SLUG.php" 2>/dev/null | sed -n 's/^ \* Version: *\([^ ]*\) *$/\1/p')"
[ -n "$VERSION" ] || { echo "$ZIP has no $SLUG/$SLUG.php with a Version header"; exit 1; }
echo "== testing $(basename "$ZIP") (version $VERSION)"

echo; echo "-- the zip"
LIST=$(unzip -Z1 "$ZIP")
if grep -v "^$SLUG/" <<< "$LIST" | grep -q .; then fail "everything is inside one $SLUG/ folder"; else pass "everything is inside one $SLUG/ folder"; fi
if grep -qx "$SLUG/vendor/autoload.php" <<< "$LIST" && grep -qx "$SLUG/vendor/blackbricksoftware/wp-artifact-updater/src/V1/GitHubReleases.php" <<< "$LIST" \
  && grep -qx "$SLUG/vendor/illuminate/collections/Arr.php" <<< "$LIST" && grep -qx "$SLUG/src/Updater.php" <<< "$LIST" && grep -qx "$SLUG/uninstall.php" <<< "$LIST"; then
  pass "it ships composer's autoloader, the updater library, illuminate/support and the plugin's code"; else fail "it ships composer's autoloader, the updater library, illuminate/support and the plugin's code"; fi
if grep -E "^$SLUG/(tests|bin|images|\.github|\.git|composer\.json|composer\.lock|\.sandbox|dist)(/|$)|/[Tt]ests/" <<< "$LIST" | grep -q .; then
  fail "nothing from development ships"; grep -E "^$SLUG/(tests|bin|images|\.github|\.git|composer\.json|composer\.lock|\.sandbox|dist)(/|$)|/[Tt]ests/" <<< "$LIST" | head -5; else pass "nothing from development ships (tests, CI, composer files, images)"; fi
HEADER=$(unzip -p "$ZIP" "$SLUG/$SLUG.php")
if [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] && grep -q "^ \* Update URI: https://github.com/$REPO\$" <<< "$HEADER" && grep -q '^ \* Requires PHP: 8.2$' <<< "$HEADER"; then
  pass "the header: version $VERSION (no v), Update URI github.com, Requires PHP 8.2"; else fail "the header: an x.y.z version, the Update URI and Requires PHP"; fi

# Start from no copy of the plugin at all: a run that stopped part-way can leave one in any folder (and the Gravity
# Forms stand-in on).
rm -rf "$WP/wp-content/gfcfp-gf-stub"
for d in gravityformswebhooks gravityforms gravityformscli; do [ -e "$PLUGINS/$d" ] && { wpq plugin deactivate "$d" > /dev/null; rm -rf "${PLUGINS:?}/$d"; }; done
for d in "$PLUGINS"/*civicrm* "$PLUGINS"/*CiviCRM*; do
  [ -e "$d" ] || continue
  wpq plugin deactivate "$(basename "$d")" > /dev/null; rm -rf "$d"
done
wpq plugin install "$ZIP" --force --activate | tail -1
expect "installed as wp-content/plugins/$SLUG/, and active" "$(wpq plugin get "$SLUG" --field=status)" "active"
mkdir -p "$WP/wp-content/mu-plugins"
cp "$T/sandbox.php" "$WP/wp-content/mu-plugins/gfcfp-sandbox.php"
rm -f "$WARN"
: > "$WP/wp-content/debug.log"
wpq plugin auto-updates disable --all > /dev/null

# Stand-ins for GitHub and wordpress.org, for the checks that must not depend on the network. HTTPLOG records every
# request the site makes, as "<host> auth|noauth <url>".
HTTPLOG="$SB/http.log"
mock() { local mode="$1"; shift; GFCFP_HTTP_MOCK="$mode" GFCFP_HTTP_MOCK_ZIP="${MOCKZIP:-$ZIP}" GFCFP_HTTP_LOG="$HTTPLOG" wpq "$@"; }
logged() { GFCFP_HTTP_LOG="$HTTPLOG" wpq "$@"; }
github_asks() { grep -c "^api.github.com noauth https://api.github.com/repos/$REPO/releases/latest$" "$HTTPLOG" || true; }
fake_old() { perl -pi -e 's/^ \* Version: .*$/ * Version: 0.0.1/' "$PLUGINS/$1/$SLUG.php"; }
# The library's cache says the newest release is 0.0.2, and WordPress's check is due.
seed() { mock release eval 'set_site_transient( gfcfp_test_cache_key(), array( "version" => "0.0.2", "url" => "https://github.com/'"$REPO"'/releases/download/v0.0.2/'"$SLUG"'-v0.0.2.zip" ), 3600 ); $s = get_site_transient( "update_plugins" ); if ( is_object( $s ) ) { $s->last_checked = 0; set_site_transient( "update_plugins", $s ); }' > /dev/null; }
cached() { wpq eval '$c = get_site_transient( gfcfp_test_cache_key() ); echo is_array( $c ) ? $c["version"] : "none";'; }

# 3. The site over real HTTP, with the stand-ins on. Stopped on any exit.
GFCFP_HTTP_MOCK=release GFCFP_HTTP_MOCK_ZIP="$ZIP" GFCFP_HTTP_LOG="$HTTPLOG" \
  "$PHP" -d memory_limit=256M -d upload_max_filesize=64M -d post_max_size=64M -S "127.0.0.1:$WPPORT" -t "$WP" > "$SB/wp-server.log" 2>&1 &
WPPID=$!
trap 'kill $WPPID 2>/dev/null || true' EXIT
# A signal (Ctrl-C, or a pipe into head closing early) exits through the EXIT trap too, so the server never lingers.
trap 'exit 1' INT TERM HUP PIPE
for _ in $(seq 50); do curl -s -o /dev/null "$BASE/wp-login.php" && break; sleep 0.2; done
curl -s -o /dev/null "$BASE/wp-login.php" || { echo "the sandbox's web server did not start (see $SB/wp-server.log)"; exit 2; }
JAR="$SB/jar.txt"
curl -s -c "$JAR" -b "wordpress_test_cookie=WP%20Cookie%20check" -o /dev/null \
  -d "log=admin&pwd=admin&wp-submit=Log+In&testcookie=1" "$BASE/wp-login.php"

echo; echo "-- without Gravity Forms"
# Until 2.1.0, every request was a fatal error here (Class "GFCommon" not found, on init).
for u in / /wp-admin/ /wp-admin/plugins.php /wp-admin/update-core.php; do
  code=$(curl -s -b "$JAR" -o "$SB/page.html" -w "%{http_code}" "$BASE$u")
  if [ "$code" = "200" ] && ! grep -q "critical error" "$SB/page.html"; then pass "200, no critical error: $u"; else fail "$u answered $code"; fi
done
if curl -s -b "$JAR" "$BASE/wp-admin/plugins.php" | grep -q "Version $VERSION"; then pass "Plugins page: version $VERSION"; else fail "Plugins page: version $VERSION"; fi

echo; echo "-- updates (GitHub replaced by a stand-in)"
: > "$HTTPLOG"
OUT=$(mock release eval 'gfcfp_test_check();')
expect "this build, with the newest release 9.9.9: offered" "$OUT" "ASKED=1 OFFERED=9.9.9 CURRENT=none"
expect "  asking GitHub once, at the address of the repository's latest release" "$(github_asks)" "1"
if grep -q ' auth ' "$HTTPLOG"; then fail "  and nothing carried a credential"; grep ' auth ' "$HTTPLOG" | head -3; else pass "  and nothing carried a credential"; fi
OUT=$(mock release eval 'require_once ABSPATH . "wp-admin/includes/plugin-install.php"; $i = plugins_api( "plugin_information", (object) array( "slug" => "'"$SLUG"'" ) ); echo is_wp_error( $i ) ? "error" : $i->name . " " . $i->version;')
if [ "$OUT" = "Gravity Forms CiviCRM Form Processor 9.9.9" ] && ! grep -q '^api.wordpress.org .*/plugins/info/' "$HTTPLOG"; then
  pass "\"View details\" is answered by the plugin, not wordpress.org"; else fail "\"View details\" is answered by the plugin, not wordpress.org ($OUT)"; fi
PAGE=$(curl -s -b "$JAR" "$BASE/wp-admin/plugins.php")
# By markup rather than wording, which WordPress may change: the update row for this plugin, and its auto-updates link.
if grep -q "action=enable-auto-update&amp;plugin=$SLUG%2F$SLUG.php" <<< "$PAGE" && grep -qE "<tr class=\"plugin-update-tr[^\"]*\"[^>]* data-plugin=\"$SLUG/$SLUG.php\"" <<< "$PAGE"; then
  pass "Plugins page: the update notice and the \"Enable auto-updates\" link"; else fail "Plugins page: the update notice and the \"Enable auto-updates\" link"; fi
expect "the newest release has no zip named like this one (as for 2.0.0 and earlier): nothing offered" "$(mock no-asset eval 'gfcfp_test_check();')" "ASKED=1 OFFERED=none CURRENT=none"
MOCKVERSION_OUT=$(GFCFP_HTTP_MOCK_VERSION="$VERSION" mock release eval 'gfcfp_test_check();')
expect "the newest release is this version: not offered, but known (which keeps \"Enable auto-updates\")" "$MOCKVERSION_OUT" "ASKED=1 OFFERED=none CURRENT=$VERSION"

# Installing it: the installed copy pretends to be 0.0.1, and the zip the stand-in serves is the one under test.
fake_old "$SLUG"
mock release eval 'gfcfp_test_check();' > /dev/null
: > "$HTTPLOG"
mock release plugin update "$SLUG" > /dev/null
expect "the update installs (Update now, or WP-CLI)" "$(wpq plugin get "$SLUG" --field=version) $(wpq plugin get "$SLUG" --field=status)" "$VERSION active"
if grep -q "^github.com noauth https://github.com/$REPO/releases/download/v9.9.9/$SLUG-v9.9.9.zip$" "$HTTPLOG" && grep -q '^release-assets.githubusercontent.com noauth' "$HTTPLOG" && ! grep -q ' auth ' "$HTTPLOG"; then
  pass "  downloaded from the release, following GitHub's redirect, with no credential"; else fail "  downloaded from the release, following GitHub's redirect, with no credential"; cat "$HTTPLOG"; fi
expect "  into $SLUG/ itself, not a second copy" "$(ls "$PLUGINS" | grep -c -i 'civicrm')" "1"

echo; echo "-- updates into the folder the plugin is in (release zips up to 2.0.0 installed into $LEGACY/)"
legacy_install() {
  wpq plugin deactivate "$SLUG" > /dev/null; rm -rf "${PLUGINS:?}/$SLUG" "${PLUGINS:?}/$LEGACY" "$SB/unzipped"
  unzip -q "$ZIP" -d "$SB/unzipped" && mv "$SB/unzipped/$SLUG" "$PLUGINS/$LEGACY"
  wpq plugin activate "$LEGACY" > /dev/null
  fake_old "$LEGACY"
}
legacy_install
expect "set up: the plugin as 0.0.1 in $LEGACY/, active" "$(wpq plugin get "$LEGACY" --field=version) $(wpq plugin get "$LEGACY" --field=status)" "0.0.1 active"
mock release eval 'gfcfp_test_check();' > /dev/null
mock release plugin update "$LEGACY" > /dev/null
expect "Update now (WP-CLI's path): installed into $LEGACY/, still active" "$(wpq plugin get "$LEGACY" --field=version) $(wpq plugin get "$LEGACY" --field=status)" "$VERSION active"
expect "  and no $SLUG/ beside it" "$(ls "$PLUGINS" | grep -i 'civicrm' | tr '\n' ' ')" "$LEGACY "

legacy_install
wpq plugin auto-updates enable "$LEGACY" > /dev/null
OUT=$(mock release eval 'gfcfp_test_auto_update();')
if grep -q '^ASKED=1 OFFERED=9.9.9' <<< "$OUT" && grep -q '^AUTO=yes' <<< "$OUT" && grep -q '^RESULT=true' <<< "$OUT"; then
  pass "WP-Cron's auto-updater: installs it, with no rollback"; else fail "WP-Cron's auto-updater: installs it, with no rollback"; echo "$OUT" | grep -E '^(ASKED|AUTO|RESULT)=' | sed 's/^/     /'; fi
expect "  into $LEGACY/, still active, and nothing beside it" "$(wpq plugin get "$LEGACY" --field=version) $(wpq plugin get "$LEGACY" --field=status) $(ls "$PLUGINS" | grep -i 'civicrm' | tr '\n' ' ')" "$VERSION active $LEGACY "
wpq plugin auto-updates disable "$LEGACY" > /dev/null

# A zip with no folder inside, as release zips up to 2.0.0 were: refused, rather than installed under the zip's name.
legacy_install
rm -rf "$SB/flat" && mkdir -p "$SB/flat" && unzip -q "$ZIP" -d "$SB/flat" && (cd "$SB/flat/$SLUG" && zip -qr "$SB/flat.zip" .)
mock release eval 'gfcfp_test_check();' > /dev/null
OUT=$(MOCKZIP="$SB/flat.zip" mock release plugin update "$LEGACY")
if grep -q 'its zip has no folder inside it' <<< "$OUT" && [ "$(wpq plugin get "$LEGACY" --field=version) $(wpq plugin get "$LEGACY" --field=status)" = "0.0.1 active" ] \
  && [ "$(ls "$PLUGINS" | grep -i 'civicrm' | tr '\n' ' ')" = "$LEGACY " ]; then
  pass "an update zip with no folder inside: refused, saying why, and the plugin is untouched"; else fail "an update zip with no folder inside: refused, saying why, and the plugin is untouched"; echo "$OUT" | tail -3; fi
rm -rf "$SB/flat" "$SB/flat.zip" "$SB/unzipped"

echo; echo "-- another plugin ships an older copy of the updater library, and its copy wins"
# The plugin registers the library's InstallFolder and CheckAgain itself because of this: only one copy of each class
# loads, and the Updater of a copy older than 1.5.0 doesn't register them. 1.4.0 is the newest such copy; 1.2.0 also
# predates forceRecheck(), which "Check again" needs. Each is downloaded once (the library is public).
for OLDVER in 1.4.0 1.2.0; do
  OLDLIB="$SB/dl/wp-artifact-updater-$OLDVER"
  if [ ! -f "$OLDLIB/src/V1/Updater.php" ]; then
    mkdir -p "$OLDLIB" && curl -fsSL --retry 3 --max-time 60 "https://codeload.github.com/blackbricksoftware/wp-artifact-updater/tar.gz/refs/tags/v$OLDVER" \
      | tar xz -C "$OLDLIB" --strip-components=1 2>/dev/null || rm -rf "$OLDLIB"
  fi
  if [ ! -f "$OLDLIB/src/V1/Updater.php" ]; then echo "SKIP an older copy of the library (v$OLDVER could not be downloaded)"; continue; fi
  old() { GFCFP_TEST_OLD_LIBRARY="$OLDLIB" mock release "$@"; }
  legacy_install
  HAS=$([ "$OLDVER" = 1.2.0 ] && echo lacks || echo has)
  expect "$OLDVER: set up: the library's Updater comes from that copy ($HAS forceRecheck())" \
    "$(old eval '$c = new ReflectionClass( "BlackBrickSoftware\\WpArtifactUpdater\\V1\\Updater" ); echo strpos( $c->getFileName(), "wp-artifact-updater-'"$OLDVER"'/" ) !== false ? "'"$OLDVER"'" : $c->getFileName(), " ", method_exists( $c->getName(), "forceRecheck" ) ? "has" : "lacks";')" "$OLDVER $HAS"
  old eval 'gfcfp_test_check();' > /dev/null
  old plugin update "$LEGACY" > /dev/null
  expect "$OLDVER:   an update still installs into $LEGACY/, still active, nothing beside it" "$(wpq plugin get "$LEGACY" --field=version) $(wpq plugin get "$LEGACY" --field=status) $(ls "$PLUGINS" | grep -i 'civicrm' | tr '\n' ' ')" "$VERSION active $LEGACY "
  seed; : > "$HTTPLOG"
  OUT=$(GFCFP_TEST_OLD_LIBRARY="$OLDLIB" mock release eval '$_GET["force-check"] = "1"; do_action( "load-update-core.php" ); echo "loaded";' --user=admin)
  if [ "$OLDVER" = 1.2.0 ]; then
    expect "$OLDVER:   \"Check again\" is no fatal error (and can't forget the cache)" "$OUT $(github_asks) $(cached)" "loaded 0 0.0.2"
  else
    expect "$OLDVER:   \"Check again\" still asks GitHub" "$OUT $(github_asks) $(cached)" "loaded 1 9.9.9"
  fi
done
wpq plugin deactivate "$LEGACY" > /dev/null; rm -rf "${PLUGINS:?}/$LEGACY"
wpq plugin install "$ZIP" --force --activate > /dev/null

echo; echo "-- Dashboard > Updates > Check again"
# The library's cache says the newest release is 0.0.2, and WordPress's check is due. A plain visit to the Updates
# screen answers from that cache; "Check again" (force-check=1) must ask GitHub, and find 9.9.9.
seed; : > "$HTTPLOG"
curl -s -b "$JAR" -o /dev/null "$BASE/wp-admin/update-core.php"
expect "a plain visit to Dashboard > Updates answers from the cache" "$(github_asks) $(cached)" "0 0.0.2"
seed; : > "$HTTPLOG"
curl -s -b "$JAR" -o /dev/null "$BASE/wp-admin/update-core.php?force-check=1"
expect "\"Check again\" asks GitHub, and finds 9.9.9" "$(github_asks) $(cached)" "1 9.9.9"
# The capability check, for a user who reaches the Updates screen's hook without being able to update plugins. (A
# visitor who isn't logged in never gets that far, so can't test it.) The same call as an administrator shows it can.
wpq user create gfcfp_editor editor@example.com --role=editor > /dev/null
again_as() { mock release eval '$_GET["force-check"] = "1"; do_action( "load-update-core.php" );' --user="$1" > /dev/null; }
seed; : > "$HTTPLOG"; again_as gfcfp_editor
expect "  but not for a user who can't update plugins" "$(github_asks) $(cached)" "0 0.0.2"
seed; : > "$HTTPLOG"; again_as admin
expect "  (the same call as an administrator does)" "$(github_asks) $(cached)" "1 9.9.9"
wpq user delete gfcfp_editor --yes > /dev/null

echo; echo "-- deleting the plugin"
mock release eval 'gfcfp_test_check();' > /dev/null
wpq plugin uninstall "$SLUG" --deactivate > /dev/null
expect "deleting it removes the updater's cached release" "$(wpq eval 'echo false === get_site_transient( "wpu_release_" . md5( "'"$SLUG/$SLUG.php"'|github.com" ) ) ? "removed" : "kept";')" "removed"
wpq plugin install "$ZIP" --force --activate > /dev/null

echo; echo "-- upgrading a site from 2.0.0: upload the new zip, activate it, delete the old copy (README, Updates)"
# Through the real upload screen. 2.0.0's zip had no folder inside, so WordPress named its folder after the zip:
# gravity_forms_civicrm_form_processor/, or since WordPress 7.1.4 that plus 16 random characters (core now gives every
# unpacked package an unpredictable directory, and a zip with no folder is installed under it). The new zip's folder
# differs either way, so WordPress installs it beside the old copy rather than offering to replace it. 2.0.0 fatals
# without Gravity Forms, hence the stand-in.
OLDZIP="$SB/dl/gravity_forms_civicrm_form_processor-2.0.0.zip"
[ -s "$OLDZIP" ] || curl -fsSL --retry 3 --max-time 120 -o "$OLDZIP" "https://github.com/$REPO/releases/download/v2.0.0/gravity_forms_civicrm_form_processor.zip" || rm -f "$OLDZIP"
if [ ! -s "$OLDZIP" ]; then
  echo "SKIP the upgrade from 2.0.0 (its zip could not be downloaded)"
else
  # Upload a zip on Plugins > Add New > Upload Plugin, as the given file name; prints the result page.
  upload() {
    local nonce
    nonce=$(curl -s -b "$JAR" "$BASE/wp-admin/plugin-install.php?tab=upload" | sed -n '/wp-upload-form/,/<\/form>/p' | grep -o 'name="_wpnonce" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"//')
    curl -s -b "$JAR" -F "_wpnonce=$nonce" -F "_wp_http_referer=/wp-admin/plugin-install.php?tab=upload" \
      -F "pluginzip=@$1;filename=$2;type=application/zip" -F "install-plugin-submit=Install Now" "$BASE/wp-admin/update.php?action=upload-plugin"
  }
  # Follow the result page's "Activate Plugin" button.
  activate_from() { local link; link=$(grep -o 'href="[^"]*action=activate&amp;plugin=[^"]*"' <<< "$1" | head -1 | sed 's/^href="//; s/"$//; s/&amp;/\&/g'); [ -n "$link" ] && curl -s -b "$JAR" -o /dev/null "$BASE/wp-admin/$link"; }
  pages_ok() { local u code; for u in / /wp-admin/ /wp-admin/plugins.php; do code=$(curl -s -b "$JAR" -o "$SB/page.html" -w "%{http_code}" "$BASE$u"); if [ "$code" != 200 ] || grep -q "critical error" "$SB/page.html"; then echo "$u answered $code"; return; fi; done; echo ok; }
  copies() { ls "$PLUGINS" | grep -i civicrm | tr '\n' ' '; }
  for d in "$PLUGINS"/*civicrm*; do [ -e "$d" ] && { wpq plugin deactivate "$(basename "$d")" > /dev/null; rm -rf "$d"; }; done
  mkdir -p "$WP/wp-content/gfcfp-gf-stub"
  echo '<?php class GFNotification { public static function is_valid_notification_email( $email ) { return true; } }' > "$WP/wp-content/gfcfp-gf-stub/notification.php"
  rotate_log

  PAGE=$(upload "$OLDZIP" gravity_forms_civicrm_form_processor.zip); activate_from "$PAGE"
  OLDDIR=$(ls "$PLUGINS" | grep "^$LEGACY" | head -1)
  if [[ "$OLDDIR" =~ ^${LEGACY}(-[A-Za-z0-9]{16})?$ ]] && [ "$(copies)" = "$OLDDIR " ] && [ "$(wpq plugin get "$OLDDIR" --field=version) $(wpq plugin get "$OLDDIR" --field=status)" = "v2.0.0 active" ]; then
    pass "set up: 2.0.0 uploaded the way sites did, active, in $OLDDIR/"; else fail "set up: 2.0.0 uploaded the way sites did ($(copies))"; fi
  PAGE=$(upload "$ZIP" "$(basename "$ZIP")")
  if grep -q 'Plugin installed successfully' <<< "$PAGE" && ! grep -q 'already installed' <<< "$PAGE"; then
    pass "uploading the new zip installs it as a second copy (no \"Replace current with uploaded\")"; else fail "uploading the new zip installs it as a second copy"; fi
  activate_from "$PAGE"
  expect "  activating it from the result page deactivates the old copy (OldCopies)" "$(copies)$(wpq plugin get "$OLDDIR" --field=status) $(wpq plugin get "$SLUG" --field=status) $(wpq plugin get "$SLUG" --field=version)" "$OLDDIR $SLUG inactive active $VERSION"
  # What the admin sees, since the Plugins page doesn't show folders: the plugin listed twice, and a notice naming the
  # old copy's folder and version, with WordPress's own delete link for it.
  PAGE=$(curl -s -b "$JAR" "$BASE/wp-admin/plugins.php")
  if [ "$(grep -o 'data-plugin="[^"]*gravityforms-civicrm-form-processor.php"' <<< "$PAGE" | sort -u | wc -l)" = 2 ] && grep -q "replaces the older one in wp-content/plugins/$OLDDIR/ (version v2.0.0)" <<< "$PAGE" \
    && grep -qE "action=delete-selected(&amp;|&#038;)checked%5B0?%5D=$OLDDIR%2Fgravityforms-civicrm-form-processor\.php" <<< "$PAGE"; then
    pass "  the Plugins page says so, with a link to delete the old copy"; else fail "  the Plugins page says so, with a link to delete the old copy"; grep -o 'notice notice-info.\{0,400\}' <<< "$PAGE" | head -2; fi
  expect "  and the site works" "$(pages_ok)" "ok"
  expect "  the new copy's code is what runs (its updater, its folder)" "$(mock release eval 'echo defined( "GRAVITYFORMS_CIVICRM_FORM_PROCESSOR" ) ? basename( dirname( GRAVITYFORMS_CIVICRM_FORM_PROCESSOR ) ) : "undefined"; echo " "; gfcfp_test_check();')" "$SLUG ASKED=1 OFFERED=9.9.9 CURRENT=none"
  # The link, followed: WordPress's confirmation screen, then its delete.
  LINK=$(grep -o 'href="[^"]*action=delete-selected[^"]*"' <<< "$PAGE" | head -1 | sed 's/^href="//; s/"$//; s/&amp;/\&/g; s/&#038;/\&/g')
  CONFIRM=$(curl -s -b "$JAR" "$LINK")
  NONCE=$(grep -o 'name="_wpnonce" value="[^"]*"' <<< "$CONFIRM" | head -1 | sed 's/.*value="//; s/"//')
  curl -s -b "$JAR" -o /dev/null --data-urlencode "action=delete-selected" --data-urlencode "verify-delete=1" --data-urlencode "checked[]=$OLDDIR/gravityforms-civicrm-form-processor.php" \
    --data-urlencode "_wpnonce=$NONCE" --data-urlencode "plugin_status=all" "$BASE/wp-admin/plugins.php"
  PAGE=$(curl -s -b "$JAR" "$BASE/wp-admin/plugins.php")
  if [ "$(copies)" = "$SLUG " ] && [ "$(wpq plugin get "$SLUG" --field=status)" = active ] && ! grep -q 'replaces the older one' <<< "$PAGE" && [ "$(wpq option get gravityforms_civicrm_form_processor_replaced 2>&1 | grep -c Error)" = 1 ]; then
    pass "deleting the old copy from the notice's link leaves the new one active, and the notice goes"; else fail "deleting the old copy from the notice's link ($(copies))"; fi
  expect "  and the site works" "$(pages_ok)" "ok"
  rm -rf "$WP/wp-content/gfcfp-gf-stub"
fi

echo; echo "-- sending a webhook again from an entry's page (with Gravity Forms and its Webhooks add-on)"
# Gravity Forms isn't free. It comes from GFCFP_TEST_GF_DIR, a folder holding gravityforms_*.zip and
# gravityformswebhooks_*.zip (a licensed copy), or else it is downloaded with GFCFP_TEST_GF_KEY, a Gravity Forms licence
# key (CI passes the repository's secret), through Gravity Forms' own CLI add-on, so it is always the newest. Without
# either, these checks are skipped, unless GFCFP_TEST_GF_REQUIRED=1: CI sets that on pushes and tags, so nothing is
# released without them. The webhooks go to tests/capture.php, a stand-in for CiviCRM's REST endpoint on its own port,
# which answers as CiviCRM does unless told otherwise.
GFZIP=$(ls "${GFCFP_TEST_GF_DIR:-/nonexistent}"/gravityforms_[0-9]*.zip 2>/dev/null | sort -V | tail -1)
WHZIP=$(ls "${GFCFP_TEST_GF_DIR:-/nonexistent}"/gravityformswebhooks_[0-9]*.zip 2>/dev/null | sort -V | tail -1)
# Locally, the key can sit in the git-ignored file gravity_license_key at the repository's root instead.
[ -n "${GFCFP_TEST_GF_KEY:-}" ] || GFCFP_TEST_GF_KEY=$(cat "$ROOT/gravity_license_key" 2>/dev/null || true)
GF_FROM=""
if [ -n "$GFZIP" ] && [ -n "$WHZIP" ]; then
  wpq plugin install "$GFZIP" --force --activate > /dev/null; wpq plugin install "$WHZIP" --force --activate > /dev/null
  GF_FROM="from $(basename "$GFZIP"), $(basename "$WHZIP")"
elif [ -n "${GFCFP_TEST_GF_KEY:-}" ]; then
  # Nothing these commands print is shown: Gravity Forms' download addresses carry a hash of the key, and a public
  # repository's CI logs are public. (The workflow also masks that hash.) The sandbox lets these, and only these, reach
  # Gravity Forms' servers.
  wpq plugin install gravityformscli --force --activate > /dev/null
  for slug in gravityforms gravityformswebhooks; do
    GFCFP_ALLOW_GF_API=1 wp --path="$WP" gf install "$slug" --key="$GFCFP_TEST_GF_KEY" --force --activate > /dev/null 2>&1 || true
  done
  GF_FROM="downloaded with the licence key"
fi
GF_READY=""
if [ -n "$GF_FROM" ] && [ "$(wpq plugin get gravityforms --field=status)" = active ] && [ "$(wpq plugin get gravityformswebhooks --field=status)" = active ]; then
  GF_READY=1
fi
if [ "$GF_READY" != 1 ]; then
  if [ -n "$GF_FROM" ]; then
    fail "Gravity Forms and its Webhooks add-on could not be installed ($GF_FROM; for a key, check it is current and covers the Webhooks add-on)"
  elif [ "${GFCFP_TEST_GF_REQUIRED:-}" = 1 ]; then
    fail "the checks with Gravity Forms are required here (GFCFP_TEST_GF_REQUIRED=1), but neither GFCFP_TEST_GF_DIR nor GFCFP_TEST_GF_KEY was given"
  else
    echo "SKIP sending a webhook again (set GFCFP_TEST_GF_DIR to a folder with gravityforms_*.zip and gravityformswebhooks_*.zip, or GFCFP_TEST_GF_KEY to a licence key)"
  fi
else
  CAP="$SB/capture"; CAPPORT="${GFCFP_TEST_CAPTURE_PORT:-8091}"
  mkdir -p "$CAP" && rm -f "${CAP:?}/answer.json" "${CAP:?}/captured.jsonl" && cp "$T/capture.php" "$CAP/"
  (cd "$CAP" && CAPTURE_DIR="$CAP" exec "$PHP" -S "127.0.0.1:$CAPPORT" capture.php > "$SB/capture-server.log" 2>&1) &
  CAPPID=$!
  trap 'kill $WPPID $CAPPID 2>/dev/null || true' EXIT
  echo "     (Gravity Forms $(wpq plugin get gravityforms --field=version) and Webhooks $(wpq plugin get gravityformswebhooks --field=version), $GF_FROM)"
  # The Webhooks add-on's background processor starts empty and unpaused: a run that stopped part-way can leave its
  # batches, or its pause after a failed task, in the database (wp_gf_webhooks_feed_processor_*).
  wpq eval 'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE \"wp_gf_webhooks_feed_processor%\"" );' > /dev/null
  # A form with three webhooks to the stand-in, one of them turned off; a second form with its own; and an entry, saved
  # without running any feeds (GFAPI::add_entry), as if the webhooks had failed or been off when it came in.
  IDS=$(wpq eval '
    $url = "http://127.0.0.1:'"$CAPPORT"'/civicrm/ajax/rest";
    $meta = function ( $name ) use ( $url ) { return array( "feedName" => $name, "requestURL" => $url, "requestMethod" => "POST", "requestFormat" => "json", "requestHeaders" => array(), "requestBodyType" => "all_fields", "feed_condition_conditional_logic" => false ); };
    $form = GFAPI::add_form( array( "title" => "CiviCRM test " . wp_generate_password( 6, false ), "fields" => array( array( "type" => "text", "id" => 1, "label" => "First name" ), array( "type" => "email", "id" => 2, "label" => "Email" ) ) ) );
    $on = GFAPI::add_feed( $form, $meta( "To CiviCRM" ), "gravityformswebhooks" );
    $off = GFAPI::add_feed( $form, $meta( "Turned off" ), "gravityformswebhooks" ); GFAPI::update_feed_property( $off, "is_active", 0 );
    $other_form = GFAPI::add_form( array( "title" => "Other form " . wp_generate_password( 6, false ), "fields" => array( array( "type" => "text", "id" => 1, "label" => "Name" ) ) ) );
    $other = GFAPI::add_feed( $other_form, $meta( "Other form webhook" ), "gravityformswebhooks" );
    $entry = GFAPI::add_entry( array( "form_id" => $form, "1" => "Ada", "2" => "ada@example.com" ) );
    $also = GFAPI::add_feed( $form, array( "requestURL" => $url . "?who={user:user_login}" ) + $meta( "Also to CiviCRM" ), "gravityformswebhooks" );
    echo "$form $on $off $other $entry $also";' | tail -1)
  read -r FORM FEED OFFFEED OTHERFEED ENTRY ALSOFEED <<< "$IDS"
  # Gravity Forms loads its KLogger class only once something asks for a logger. This plugin's hooks can run first (a
  # webhook sent by another plugin, say), and until 2.2.0 their KLogger::DEBUG was then a fatal error.
  expect "this plugin logs fine before Gravity Forms has loaded its logger" \
    "$(wpq eval 'echo class_exists( "KLogger", false ) ? "loaded" : "not loaded", " "; \BlackBrickSoftware\GravityFormsCiviCRMFormProcessor\Log::debug( "probe" ); echo "logged";' | tail -1)" "not loaded logged"
  # Until 2.2.0 this was a fatal error: GFAPI::add_feed() builds the webhook settings, which called gform_tooltip().
  if [[ "$IDS" =~ ^[0-9]+\ [0-9]+\ [0-9]+\ [0-9]+\ [0-9]+\ [0-9]+$ ]]; then pass "webhooks can be created outside wp-admin (GFAPI::add_feed from WP-CLI)"; else fail "webhooks can be created outside wp-admin ($IDS)"; fi
  ENTRYPAGE="$BASE/wp-admin/admin.php?page=gf_entries&view=entry&id=$FORM&lid=$ENTRY"
  PAGE=$(curl -s -b "$JAR" "$ENTRYPAGE")
  BOXID=gravityforms_civicrm_form_processor_webhooks
  if grep -q "id=\"$BOXID\"" <<< "$PAGE" && grep -q "<input type=\"checkbox\" value=\"$FEED\" id=\"${BOXID}_$FEED\" />" <<< "$PAGE" \
    && grep -q "To CiviCRM <span class=\"description\" data-status=\"$FEED\">(not sent yet)</span>" <<< "$PAGE" && grep -q "value=\"$ALSOFEED\"" <<< "$PAGE" && ! grep -q "value=\"$OFFFEED\"" <<< "$PAGE"; then
    pass "the entry's page has a Webhooks box with a checkbox for each active webhook, not the one turned off"; else fail "the entry's page has a Webhooks box with a checkbox for each active webhook ($IDS)"; fi
  NONCE=$(grep -o "data.append('nonce', \"[^\"]*\")" <<< "$PAGE" | head -1 | sed "s/.*, \"//; s/\")//")
  # The box's request, as its Send button makes it, for the ticked webhooks (a comma-separated list of ids).
  resend() { local ticked=() id; for id in ${2//,/ }; do ticked+=(--data-urlencode "feed_ids[]=$id"); done
    curl -s -b "${4:-$JAR}" --data-urlencode "action=gravityforms_civicrm_form_processor_resend_webhook" --data-urlencode "nonce=${3:-$NONCE}" \
    --data-urlencode "entry_id=$1" "${ticked[@]}" "$BASE/wp-admin/admin-ajax.php"; }
  # "ok: <each webhook's result, joined by | >", or "error: ..." when any failed.
  json() { "$PHP" -r '$j = json_decode( stream_get_contents( STDIN ), true ); if ( ! is_array( $j ) ) { echo "not json: ", substr( (string) $j, 0, 80 ); exit; } $r = $j["data"]["results"] ?? null; echo $j["success"] ? "ok" : "error", ": ", is_array( $r ) ? implode( " | ", array_column( $r, "message" ) ) : ( $j["data"]["message"] ?? "" );'; }
  feed_status() { wpq eval '$s = gform_get_meta( '"$1"', "feed_'"$2"'_status" ); $l = is_array( $s ) ? end( $s ) : null; echo is_array( $l ) ? $l["status"] : "none", " ", in_array( '"$2"', GFAPI::get_processed_feeds_meta( '"$1"', "gravityformswebhooks" ) ) ? "processed" : "unprocessed";'; }
  notes() { wpq eval 'foreach ( GFAPI::get_notes( array( "entry_id" => '"$1"' ) ) as $n ) { echo $n->value, "\n"; }'; }
  sent() { wc -l < "$CAP/captured.jsonl" 2>/dev/null | tr -d ' ' || echo 0; }

  OUT=$(resend "$ENTRY" "$FEED" | json)
  expect "Send: the webhook goes out now, and the box says so" "$OUT" 'ok: "To CiviCRM" was sent.'
  if [ "$(sent)" = 1 ] && grep -q '"uri":"\\/civicrm\\/ajax\\/rest"' "$CAP/captured.jsonl" && grep -q 'ada@example.com' "$CAP/captured.jsonl"; then
    pass "  the stand-in CiviCRM got one request with the entry's data"; else fail "  the stand-in CiviCRM got one request with the entry's data"; head -3 "$CAP/captured.jsonl" 2>/dev/null; fi
  expect "  the entry's feed status says it succeeded, and Gravity Forms counts it as processed" "$(feed_status "$ENTRY" "$FEED")" "success processed"
  if notes "$ENTRY" | grep -q 'Webhook "To CiviCRM" sent again by admin.' && notes "$ENTRY" | grep -q 'Webhook sent. Response code: 200.'; then
    pass "  and the entry's notes say who sent it (and the Webhooks add-on's own note, with the response code)"; else fail "  the entry's notes"; notes "$ENTRY"; fi
  if curl -s -b "$JAR" "$ENTRYPAGE" | grep -q "data-status=\"$FEED\">(sent "; then pass "  and the box now shows when it was sent"; else fail "  and the box now shows when it was sent"; fi
  if grep -qx "$FORM $ENTRY" "$WP/wp-content/gfcfp-probe.log" 2>/dev/null; then
    pass "  Gravity Forms' pre-feed form filter ran for it, as for a webhook sent in the background"; else fail "  Gravity Forms' pre-feed form filter ran for it (gform_form_pre_process_async_task)"; fi

  # From here the webhook's failure email is on ("CiviCRM Webhook Failure Notification"). The sandbox keeps each email
  # in wp-content/gfcfp-mail.jsonl instead of sending it.
  MAIL="$WP/wp-content/gfcfp-mail.jsonl"
  wpq eval '$f = GFAPI::get_feed( '"$FEED"' ); $m = $f["meta"]; $m["CiviCRMWebhookFailureNotification"] = "1"; $m["CiviCRMWebhookFailureNotificationTo"] = "alerts@example.com"; $m["CiviCRMWebhookFailureNotificationFrom"] = "wordpress@example.com"; $m["CiviCRMWebhookFailureNotificationFromName"] = "Sandbox"; $m["CiviCRMWebhookFailureNotificationSubject"] = "CiviCRM webhook failed"; GFAPI::update_feed( '"$FEED"', $m );' > /dev/null
  : > "$MAIL"
  # The newest email: "<to> | <subject> | <the reason this plugin gives>".
  last_mail() { tail -1 "$MAIL" 2>/dev/null | "$PHP" -r '$m = json_decode( stream_get_contents( STDIN ), true ); if ( ! is_array( $m ) ) { echo "none"; exit; } preg_match( "/<p>(Network Error|Invalid HTTP Status Code|Response Was Not JSON|CiviCRM API Returned an Error)[^<]*<\/p>/", $m["message"], $r ); echo ( is_array( $m["to"] ) ? implode( ",", $m["to"] ) : $m["to"] ), " | ", $m["subject"], " | ", $r[1] ?? "?";'; }
  mails() { grep -c . "$MAIL" 2>/dev/null || true; }

  echo '{"code":500,"body":"Internal Server Error"}' > "$CAP/answer.json"
  expect "CiviCRM answers 500: the box says it failed, and why" "$(resend "$ENTRY" "$FEED" | json)" 'error: "To CiviCRM" failed: The server answered with HTTP 500.'
  expect "  the feed status says failed" "$(feed_status "$ENTRY" "$FEED" | cut -d' ' -f1)" "failed"
  if notes "$ENTRY" | grep -q 'Webhook "To CiviCRM" sent again by admin, and it failed: The server answered with HTTP 500.'; then pass "  and the entry's notes say so"; else fail "  and the entry's notes say so"; fi
  expect "  and the failure email goes out, as for any failed webhook" "$(mails) $(last_mail)" "1 alerts@example.com | CiviCRM webhook failed | Invalid HTTP Status Code"
  echo '{"code":200,"body":"{\"is_error\":1,\"error_message\":\"Mandatory key(s) missing: contact_type\"}"}' > "$CAP/answer.json"
  expect "CiviCRM reports an API error: the box gives CiviCRM's message" "$(resend "$ENTRY" "$FEED" | json)" 'error: "To CiviCRM" failed: CiviCRM reported an error: Mandatory key(s) missing: contact_type'
  expect "  and the failure email says so" "$(mails) $(last_mail)" "2 alerts@example.com | CiviCRM webhook failed | CiviCRM API Returned an Error"
  echo '{"code":200,"body":"<html>not json</html>"}' > "$CAP/answer.json"
  expect "CiviCRM's answer isn't JSON: the box says so" "$(resend "$ENTRY" "$FEED" | json)" "error: \"To CiviCRM\" failed: The answer was not JSON, so CiviCRM did not handle it (check the webhook's URL and request format)."
  expect "  and the failure email says so" "$(mails) $(last_mail)" "3 alerts@example.com | CiviCRM webhook failed | Response Was Not JSON"
  # JSON, but not an object: until 2.3.0 a TypeError, with no failure email.
  echo '{"code":200,"body":"0"}' > "$CAP/answer.json"
  expect "CiviCRM's answer is JSON but not an object (0): a failure too, with its email" "$(resend "$ENTRY" "$FEED" | json) $(mails) $(last_mail)" "error: \"To CiviCRM\" failed: The answer was not JSON, so CiviCRM did not handle it (check the webhook's URL and request format). 4 alerts@example.com | CiviCRM webhook failed | Response Was Not JSON"
  rm -f "${CAP:?}/answer.json"
  expect "a send that succeeds sends no failure email" "$(resend "$ENTRY" "$FEED" | json) $(mails)" 'ok: "To CiviCRM" was sent. 4'

  : > "$CAP/captured.jsonl"
  expect "two webhooks ticked: both go out, one after the other, each with its result" "$(resend "$ENTRY" "$FEED,$ALSOFEED" | json) $(sent)" 'ok: "To CiviCRM" was sent. | "Also to CiviCRM" was sent. 2'
  expect "  one of them turned off: that one is refused, the other still goes out" "$(resend "$ENTRY" "$OFFFEED,$ALSOFEED" | json) $(sent)" "error: That webhook is turned off. Turn it on in the form's Webhooks settings first. | \"Also to CiviCRM\" was sent. 3"
  expect "none ticked: nothing sent" "$(resend "$ENTRY" "" | json) $(sent)" "error: Tick at least one webhook to send. 3"
  # {user:...} merge tags are the entry's own user, as when it was submitted, not the admin who pressed Send. This
  # entry was saved by nobody, so "Also to CiviCRM"'s ?who={user:user_login} is empty.
  if grep -q '"uri":"\\/civicrm\\/ajax\\/rest?who="' "$CAP/captured.jsonl" && ! grep -q 'who=admin' "$CAP/captured.jsonl"; then
    pass "  sent as the entry's own user: its {user:...} merge tags aren't the admin's"; else fail "  sent as the entry's own user ({user:...} merge tags)"; grep -o '"uri":"[^"]*"' "$CAP/captured.jsonl"; fi

  : > "$CAP/captured.jsonl"
  expect "a webhook that is turned off: refused, nothing sent" "$(resend "$ENTRY" "$OFFFEED" | json) $(sent)" "error: That webhook is turned off. Turn it on in the form's Webhooks settings first. 0"
  expect "another form's webhook: refused, nothing sent" "$(resend "$ENTRY" "$OTHERFEED" | json) $(sent)" "error: That webhook does not belong to this entry's form. 0"
  expect "a bad nonce: refused by WordPress, nothing sent" "$(resend "$ENTRY" "$FEED" bad-nonce) $(sent)" "-1 0"
  # A logged-in user who can't edit entries (an editor), with a valid nonce for their own session: refused by the
  # capability check, which a bad nonce never reaches. The nonce is made for that session's token, from its cookie.
  wpq user create gfcfp_editor2 editor2@example.com --role=editor --user_pass=editor2-pass > /dev/null
  EJAR="$SB/editor-jar.txt"
  curl -s -c "$EJAR" -b "wordpress_test_cookie=WP%20Cookie%20check" -o /dev/null -d "log=gfcfp_editor2&pwd=editor2-pass&wp-submit=Log+In&testcookie=1" "$BASE/wp-login.php"
  LIC=$(awk '$6 ~ /^wordpress_logged_in_/ { print $7; exit }' "$EJAR")
  ENONCE=$(LIC="$LIC" wpq eval '$_COOKIE[ LOGGED_IN_COOKIE ] = rawurldecode( getenv( "LIC" ) ); wp_set_current_user( get_user_by( "login", "gfcfp_editor2" )->ID ); echo wp_create_nonce( "gravityforms_civicrm_form_processor_resend_webhook" );' | tail -1)
  expect "a user who can't edit entries (an editor, with a valid nonce): refused, nothing sent" "$(resend "$ENTRY" "$FEED" "$ENONCE" "$EJAR" | json) $(sent)" "error: You are not allowed to send webhooks for entries. 0"
  wpq user delete gfcfp_editor2 --yes > /dev/null
  # As Gravity Forms does: no webhooks for spam, none while a payment add-on is still waiting for the payment, and none
  # that the site's own feed filters leave out (the sandbox leaves out webhooks named "Skip: ...").
  EXTRA=$(wpq eval '$s = GFAPI::add_entry( array( "form_id" => '"$FORM"', "1" => "Spammy", "2" => "spam@example.com" ) ); GFAPI::update_entry_property( $s, "status", "spam" ); $u = GFAPI::add_entry( array( "form_id" => '"$FORM"', "1" => "Unpaid", "2" => "unpaid@example.com" ) ); $f = GFAPI::get_feed( '"$FEED"' ); $k = GFAPI::add_feed( '"$FORM"', array( "feedName" => "Skip: by a filter" ) + $f["meta"], "gravityformswebhooks" ); echo "$s $u $k";' | tail -1)
  read -r SPAM UNPAID SKIPFEED <<< "$EXTRA"
  expect "a spam entry: refused, nothing sent" "$(resend "$SPAM" "$FEED" | json) $(sent)" "error: This entry is marked as spam. Mark it as not spam first, which also sends its webhooks. 0"
  expect "an entry waiting for its payment: refused, nothing sent" "$(resend "$UNPAID" "$FEED" | json) $(sent)" "error: \"To CiviCRM\" waits for this entry's payment: its webhooks are sent when the payment is received. 0"
  expect "a webhook the site's feed filters leave out: not sent" "$(resend "$ENTRY" "$SKIPFEED" | json) $(sent)" "error: \"Skip: by a filter\" was not sent: a filter on this site (gform_addon_pre_process_feeds or similar) leaves it out for this entry. 0"
  wpq eval 'GFAPI::update_feed_property( '"$SKIPFEED"', "is_active", 0 );' > /dev/null

  # "Send webhooks again", the Entries list's bulk action, for two entries: queued as on submission, then sent by
  # Gravity Forms' background processor (a request the site makes to itself after the bulk action's request ends).
  BULK=$(wpq eval 'echo GFAPI::add_entry( array( "form_id" => '"$FORM"', "1" => "Bulk", "2" => "bulk-one@example.com" ) ), " ", GFAPI::add_entry( array( "form_id" => '"$FORM"', "1" => "Bulk", "2" => "bulk-two@example.com" ) );' | tail -1)
  read -r BULK1 BULK2 <<< "$BULK"
  LISTURL="$BASE/wp-admin/admin.php?page=gf_entries&id=$FORM"
  LIST=$(curl -s -b "$JAR" "$LISTURL")
  if grep -q '<option value="gravityforms_civicrm_form_processor_resend_webhooks">Send webhooks again</option>' <<< "$LIST" && grep -q 'window.confirm' <<< "$LIST"; then
    pass "the Entries list has a \"Send webhooks again\" bulk action, which asks first"; else fail "the Entries list has a \"Send webhooks again\" bulk action"; fi
  LNONCE=$(grep -o 'name="gforms_entry_list" value="[^"]*"' <<< "$LIST" | head -1 | sed 's/.*value="//; s/"//')
  : > "$CAP/captured.jsonl"
  OUT=$(curl -s -b "$JAR" --data-urlencode "gforms_entry_list=$LNONCE" --data-urlencode "action=gravityforms_civicrm_form_processor_resend_webhooks" --data-urlencode "action2=-1" \
    --data-urlencode "entry[]=$BULK1" --data-urlencode "entry[]=$BULK2" --data-urlencode "all_entries=" "$LISTURL")
  if grep -q 'The webhooks of 2 entries are queued to be sent again' <<< "$OUT"; then pass "  applied to two entries: the list says they are queued"; else fail "  applied to two entries: the list says they are queued"; fi
  for _ in $(seq 60); do [ "$(sent)" -ge 4 ] && break; sleep 0.5; done
  if [ "$(sent)" -lt 4 ]; then
    echo "     (the background request hadn't run; running Gravity Forms' processor directly)"
    wpq eval 'gf_feed_processor( GF_Webhooks::get_instance() )->handle_cron_healthcheck();' > /dev/null
    for _ in $(seq 30); do [ "$(sent)" -ge 4 ] && break; sleep 0.5; done
  fi
  if [ "$(sent)" = 4 ] && [ "$(grep -c 'bulk-one@example.com' "$CAP/captured.jsonl")" = 2 ] && [ "$(grep -c 'bulk-two@example.com' "$CAP/captured.jsonl")" = 2 ]; then
    pass "  Gravity Forms sent both entries' two active webhooks in the background"; else fail "  Gravity Forms sent both entries' two active webhooks in the background ($(sent) requests)"; fi
  expect "  each feed status says it succeeded" "$(feed_status "$BULK1" "$FEED"), $(feed_status "$BULK1" "$ALSOFEED"), $(feed_status "$BULK2" "$FEED"), $(feed_status "$BULK2" "$ALSOFEED")" "success processed, success processed, success processed, success processed"
  if notes "$BULK1" | grep -q 'Webhooks queued to be sent again by admin.' && notes "$BULK2" | grep -q 'Webhooks queued to be sent again by admin.'; then pass "  and each entry's notes say who queued them"; else fail "  and each entry's notes say who queued them"; fi

  # The form's "Delete entries after Webhooks are completed successfully" setting applies to a resend too: once both of
  # the form's active webhooks have succeeded, the entry goes, and the box says so.
  ENTRY2=$(wpq eval '$f = GFAPI::get_form( '"$FORM"' ); $f["CiviCRMDeleteEntries"] = true; GFAPI::update_form( $f ); echo GFAPI::add_entry( array( "form_id" => '"$FORM"', "1" => "Grace", "2" => "grace@example.com" ) );' | tail -1)
  OUT=$(resend "$ENTRY2" "$FEED,$ALSOFEED" | json)
  if [ "${OUT#ok: }" != "$OUT" ] && grep -q '"To CiviCRM" was sent. | "Also to CiviCRM" was sent. The entry has now been deleted' <<< "$OUT" && [ "$(wpq eval 'echo is_wp_error( GFAPI::get_entry( '"$ENTRY2"' ) ) ? "gone" : "kept";')" = gone ]; then
    pass "with \"Delete entries after Webhooks\" on: both sent, then the entry deleted, and the box says so"; else fail "with \"Delete entries after Webhooks\" on ($OUT)"; fi

  # As on a live site, with WP_DEBUG off, and "Delete entries after Webhooks" still on. Until 2.3.0 a failure CiviCRM
  # answered (a 403, say) was an exception thrown from a hook, which Gravity Forms 3.0.2 and later swallow unless
  # WP_DEBUG is on: the webhook was recorded as sent, never retried, and the entry deleted. Sent again by hand, and
  # sent in the background as a submission's are, a 403 must now be a failure, and the entry must stay.
  touch "$WP/wp-content/gfcfp-debug-off"
  echo '{"code":403,"body":"Forbidden"}' > "$CAP/answer.json"
  PROD=$(wpq eval 'echo GFAPI::add_entry( array( "form_id" => '"$FORM"', "1" => "Prod", "2" => "prod-one@example.com" ) ), " ", GFAPI::add_entry( array( "form_id" => '"$FORM"', "1" => "Prod", "2" => "prod-two@example.com" ) );' | tail -1)
  read -r PROD1 PROD2 <<< "$PROD"
  kept() { wpq eval 'echo is_wp_error( GFAPI::get_entry( '"$1"' ) ) ? "deleted" : "kept";'; }
  expect "WP_DEBUG off, CiviCRM answers 403, sent again by hand: a failure, and the entry stays" "$(resend "$PROD1" "$FEED" | json) $(feed_status "$PROD1" "$FEED") $(kept "$PROD1")" "error: \"To CiviCRM\" failed: The server answered with HTTP 403. failed unprocessed kept"
  : > "$CAP/captured.jsonl"; : > "$MAIL"
  wpq eval '$e = GFAPI::get_entry( '"$PROD2"' ); GFAPI::maybe_process_feeds( $e, GFAPI::get_form( '"$FORM"' ), "gravityformswebhooks" );' > /dev/null
  for _ in $(seq 40); do [ "$(sent)" -ge 2 ] && break; sleep 0.5; done
  [ "$(sent)" -ge 2 ] || { wpq eval 'gf_feed_processor( GF_Webhooks::get_instance() )->handle_cron_healthcheck();' > /dev/null; for _ in $(seq 30); do [ "$(sent)" -ge 2 ] && break; sleep 0.5; done; }
  # Gravity Forms keeps a failed task for another attempt and pauses its processor (GF_Background_Process::handle()),
  # so the entry's second webhook waits for the next run: its status is none yet, and the batch is still queued.
  queued() { wpq eval 'global $wpdb; echo $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE \"wp_gf_webhooks_feed_processor_batch_%\"" ) > 0 ? "queued for retry" : "not queued";'; }
  expect "WP_DEBUG off, CiviCRM answers 403, sent in the background as on submission: a failure, kept for retry, the entry stays" \
    "$(feed_status "$PROD2" "$FEED"), $(feed_status "$PROD2" "$ALSOFEED"), $(kept "$PROD2"), $(queued)" "failed unprocessed, none unprocessed, kept, queued for retry"
  expect "  and the failure email went out" "$(mails) $(last_mail)" "1 alerts@example.com | CiviCRM webhook failed | Invalid HTTP Status Code"
  if notes "$PROD2" | grep -q 'Webhook was not successfully executed. The server answered with HTTP 403.'; then pass "  and the entry's notes say why"; else fail "  and the entry's notes say why"; notes "$PROD2"; fi
  rm -f "${CAP:?}/answer.json" "$WP/wp-content/gfcfp-debug-off"
  wpq eval 'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE \"wp_gf_webhooks_feed_processor%\"" );' > /dev/null

  wpq plugin deactivate gravityformswebhooks > /dev/null
  code=$(curl -s -b "$JAR" -o "$SB/page.html" -w "%{http_code}" "$ENTRYPAGE")
  if [ "$code" = 200 ] && ! grep -q 'gravityforms_civicrm_form_processor_webhooks' "$SB/page.html" && ! grep -q 'critical error' "$SB/page.html"; then
    pass "without the Webhooks add-on: no box, and the entry's page still works"; else fail "without the Webhooks add-on ($code)"; fi
  wpq plugin deactivate gravityforms gravityformscli > /dev/null
  rm -rf "${PLUGINS:?}/gravityforms" "${PLUGINS:?}/gravityformswebhooks" "${PLUGINS:?}/gravityformscli"
  # The CLI add-on saves the licence key in Gravity Forms' settings; it doesn't stay in the sandbox.
  wpq option delete rg_gforms_key > /dev/null
  kill "$CAPPID" 2>/dev/null || true
  trap 'kill $WPPID 2>/dev/null || true' EXIT
fi

echo; echo "-- the newest real GitHub release"
AUTH=(); [ -n "${GFCFP_TEST_GITHUB_TOKEN:-}" ] && AUTH=(-H "Authorization: Bearer $GFCFP_TEST_GITHUB_TOKEN")
REL=$(curl -fsS --max-time 20 "${AUTH[@]}" -H 'Accept: application/vnd.github+json' "https://api.github.com/repos/$REPO/releases/latest" 2>/dev/null || true)
LATEST=$("$PHP" -r '$r = json_decode( stream_get_contents( STDIN ), true ); echo ltrim( strval( $r["tag_name"] ?? "" ), "vV" );' <<< "$REL")
REL_ASSET=$("$PHP" -r '$r = json_decode( stream_get_contents( STDIN ), true ); foreach ( $r["assets"] ?? array() as $a ) { if ( preg_match( "/^gravityforms-civicrm-form-processor-v?\d+\.\d+\.\d+\.zip$/", $a["name"] ) ) { echo $a["browser_download_url"]; break; } }' <<< "$REL")
if [ -z "$LATEST" ]; then
  echo "SKIP GitHub could not be reached, so the real release could not be looked up"
elif [ -z "$REL_ASSET" ]; then
  echo "SKIP the newest release (v$LATEST) has no $SLUG-vX.Y.Z.zip, so no site can update from it yet (releases made by this workflow have one)"
else
  echo "     (the newest GitHub release is v$LATEST)"
  : > "$HTTPLOG"
  OUT=$(logged eval 'gfcfp_test_check();')
  if grep -q "^ASKED=1 \(OFFERED=$LATEST CURRENT=none\|OFFERED=none CURRENT=$LATEST\)$" <<< "$OUT"; then
    pass "the plugin's own request to GitHub finds release $LATEST"
  elif [ "$(curl -s --max-time 10 https://api.github.com/rate_limit | "$PHP" -r 'echo json_decode( stream_get_contents( STDIN ), true )["rate"]["remaining"] ?? "?";')" = "0" ]; then
    echo "SKIP the plugin's own request to GitHub (this address has used up GitHub's anonymous rate limit)"
  else
    fail "the plugin's own request to GitHub finds release $LATEST ($OUT)"
  fi

  # The upgrade a site makes: from the newest release to this build, by WP-Cron's auto-updater, using the RELEASED
  # plugin's updater code. The stand-in offers this build as 9.9.9.
  REL_ZIP="$SB/release-$LATEST.zip"
  if ! curl -fsSL --retry 3 --max-time 120 -o "$REL_ZIP" "$REL_ASSET"; then
    echo "SKIP the upgrade from release $LATEST (its zip could not be downloaded)"
  else
  wpq plugin install "$REL_ZIP" --force --activate | tail -1
  # Without this, a release zip WordPress refused would leave this build installed, and the checks below would pass
  # while testing this build's updater instead of the released one's.
  expect "set up: release $LATEST installed, active" "$(wpq plugin get "$SLUG" --field=version) $(wpq plugin get "$SLUG" --field=status)" "$LATEST active"
  wpq plugin auto-updates enable "$SLUG" > /dev/null
  OUT=$(mock release eval 'gfcfp_test_auto_update();')
  echo "$OUT" | grep -E '^(ASKED|AUTO|RESULT)=' | sed 's/^/     /'
  if grep -q '^ASKED=1 OFFERED=9.9.9' <<< "$OUT" && grep -q '^AUTO=yes' <<< "$OUT" && grep -q '^RESULT=true' <<< "$OUT"; then
    pass "upgrade from release $LATEST: WP-Cron's auto-updater installs this build, with no rollback"; else fail "upgrade from release $LATEST: WP-Cron's auto-updater installs this build, with no rollback"; fi
  expect "  and it is this build ($VERSION), still active" "$(wpq plugin get "$SLUG" --field=version) $(wpq plugin get "$SLUG" --field=status)" "$VERSION active"
  wpq plugin auto-updates disable "$SLUG" > /dev/null
  fi
  wpq plugin install "$ZIP" --force --activate > /dev/null
fi

# 4. Nothing reached PHP's error log as a warning or a fatal, at any point in the run.
echo
rotate_log
if grep -q . "$WARN" 2>/dev/null; then fail "PHP warnings or fatals in debug.log"; head -5 "$WARN"; else pass "no PHP warnings or fatals"; fi

echo
if [ "$FAILS" -eq 0 ]; then echo "tests/run.sh: ALL PASS"; else echo "tests/run.sh: FAILED ($FAILS)"; exit 1; fi
