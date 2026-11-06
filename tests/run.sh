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
define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', true ); define( 'WP_DEBUG_DISPLAY', false );
define( 'FS_METHOD', 'direct' );
$table_prefix = 'wp_';
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
EOF
  wpq core install --url="$BASE" --title=sandbox --admin_user=admin --admin_password=admin --admin_email=sandbox@example.com --skip-email
fi
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
  expect "  activated from the result page: both copies active, side by side" "$(copies)$(wpq plugin get "$OLDDIR" --field=status) $(wpq plugin get "$SLUG" --field=status) $(wpq plugin get "$SLUG" --field=version)" "$OLDDIR $SLUG active active $VERSION"
  # What the admin sees, since the Plugins page doesn't show folders: the plugin listed twice, old and new versions.
  PAGE=$(curl -s -b "$JAR" "$BASE/wp-admin/plugins.php")
  if [ "$(grep -o 'data-plugin="[^"]*gravityforms-civicrm-form-processor.php"' <<< "$PAGE" | sort -u | wc -l)" = 2 ] && grep -q 'Version v2.0.0' <<< "$PAGE" && grep -q "Version $VERSION" <<< "$PAGE"; then
    pass "  the Plugins page lists it twice, as version v2.0.0 and version $VERSION"; else fail "  the Plugins page lists it twice, as version v2.0.0 and version $VERSION"; fi
  expect "  and the site works with both active" "$(pages_ok)" "ok"
  expect "  the new copy's code is what runs (its updater, its folder)" "$(mock release eval 'echo defined( "GRAVITYFORMS_CIVICRM_FORM_PROCESSOR" ) ? basename( dirname( GRAVITYFORMS_CIVICRM_FORM_PROCESSOR ) ) : "undefined"; echo " "; gfcfp_test_check();')" "$SLUG ASKED=1 OFFERED=9.9.9 CURRENT=none"
  wpq plugin deactivate "$OLDDIR" > /dev/null; wpq plugin delete "$OLDDIR" > /dev/null
  expect "deactivating and deleting the old copy leaves the new one, active, and the site working" "$(copies)$(wpq plugin get "$SLUG" --field=status) $(pages_ok)" "$SLUG active ok"
  rm -rf "$WP/wp-content/gfcfp-gf-stub"
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
