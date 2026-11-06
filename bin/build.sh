#!/usr/bin/env bash
# Builds the release zip: dist/gravityforms-civicrm-form-processor-v<version>.zip
#
#   bin/build.sh            build the version the plugin header names
#   bin/build.sh v1.2.3     the same, but fail unless that version is 1.2.3 (CI passes the git tag)
#
# GitHub Actions runs this on pushes to master, pull requests and v*.*.* tags, and on a tag publishes the zip to that
# tag's GitHub release (.github/workflows/build.yml). Sites update from that zip (src/Updater.php). Run locally on a clean checkout, it
# produces the same zip.
#
# Needs bash, php 8.2, composer and zip.
#   PHP_BIN       the php that lints the shipped files (default: php8.2 if installed, else php). It must be 8.2, the
#                 oldest version the plugin supports, because a newer parser accepts syntax 8.2 cannot run.
#                 LINT_ANY_PHP=1 accepts another version, for a build that will not be released.
#   ALLOW_DIRTY=1 build even though files that ship have uncommitted changes, or there are untracked files among them
#                 (tests/run.sh sets it, to test work in progress). Without it, such a build stops, because whatever
#                 sits in the working tree would end up in a zip labelled with a release version.
set -euo pipefail

SLUG=gravityforms-civicrm-form-processor
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PHP_BIN="${PHP_BIN:-$(command -v php8.2 || command -v php || echo php)}"
cd "$ROOT"

# What ships, besides vendor/ (step 5). Everything else in the repository (tests, CI, composer files, the README's
# images) stays out of the zip.
SHIP=("$SLUG.php" uninstall.php README.md CHANGELOG.md src)

die() { echo "build: $*" >&2; exit 1; }

# 1. One version everywhere. WordPress reads the installed version from the plugin header. If the header and the
#    release disagree, every site installs the release, still reports the old version, and is offered the same update
#    forever. So the header, the newest CHANGELOG.md entry and (in CI) the git tag must all name the same version, or
#    there is no zip. The header takes no "v": WordPress compares it as written, and PHP sorts "v2.1.0" below "2.1.0",
#    so a site would be offered the version it already runs, forever. (Tags keep their v.)
VERSION=$(sed -n 's/^ \* Version: *\([^ ]*\) *$/\1/p' "$SLUG.php")
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "the $SLUG.php header needs an x.y.z Version, with no v (found '$VERSION')"
same() { [ "$2" = "$VERSION" ] || die "$1 says '$2', but the plugin header says $VERSION"; }
same "the newest CHANGELOG.md entry" "$(sed -n 's/^# v\([0-9][^ ]*\) .*/\1/p' CHANGELOG.md | head -1)"
if [ -n "${1:-}" ] && [ "$1" != "v$VERSION" ]; then
  die "asked to build $1, but the plugin header says $VERSION"
fi
echo "== $SLUG $VERSION"
# composer reads the root package's version from git, and the staged copy (step 5) has no .git. Tell it instead.
export COMPOSER_ROOT_VERSION="$VERSION"

# 2. What ships is what is committed: no uncommitted changes, untracked files or ignored files (editor backups, a stray
#    debug script) among the shipped paths, or in composer.json and composer.lock, which decide what goes in vendor/.
#    CI builds from a fresh checkout, so this only ever stops a local build.
if git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  dirty=$(git -C "$ROOT" status --porcelain --untracked-files=all --ignored -- "${SHIP[@]}" composer.json composer.lock | grep -v '\.DS_Store$' || true)
  if [ -n "$dirty" ]; then
    if [ "${ALLOW_DIRTY:-}" = "1" ]; then
      echo "== WARNING: building uncommitted work (ALLOW_DIRTY=1); do not release this zip"
    else
      echo "$dirty" >&2
      die "the files above would ship but are not committed (commit or remove them, or set ALLOW_DIRTY=1 for a test build)"
    fi
  fi
fi

# 3. composer.json is valid, and composer.lock exists and matches it. (composer validate skips the lock check when there
#    is no lock, and without one the build would install whatever versions resolve today.)
[ -f composer.lock ] || die "composer.lock is missing: run composer update and commit it"
composer validate --strict --no-check-publish --no-interaction >/dev/null || die "composer validate failed (run it to see why)"

# 4. Stage into dist/gravityforms-civicrm-form-processor/. The zip must hold that one folder: WordPress installs a new
#    plugin into the folder its zip names (with no folder, it names one after the zip file, which is how release zips
#    up to 2.0.0 ended up in gravity_forms_civicrm_form_processor/). Updates are renamed to whatever folder a site
#    already uses (Updater::keepFolder()), but that needs a folder to rename.
rm -rf dist
mkdir -p "dist/$SLUG"
for path in "${SHIP[@]}"; do
  [ -e "$path" ] || die "missing $path"
  cp -R "$path" "dist/$SLUG/"
done
find dist -name '.DS_Store' -delete

# 5. Runtime dependencies (illuminate/support and the updater library), installed from composer.lock into the staged
#    copy rather than the working tree, so a developer's own vendor/, with whatever dev tools it holds, is never what
#    ships. composer.json and composer.lock are only needed for that, so they don't ship either.
cp composer.json composer.lock "dist/$SLUG/"
composer install --working-dir="dist/$SLUG" --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
rm "dist/$SLUG/composer.json" "dist/$SLUG/composer.lock"
[ -f "dist/$SLUG/vendor/autoload.php" ] || die "composer did not produce vendor/autoload.php"
[ -f "dist/$SLUG/vendor/blackbricksoftware/wp-artifact-updater/src/V1/Updater.php" ] || die "the updater library is not in vendor/"
# vendor/ is web-reachable on the site, so no dependency's test suite may ship in it.
# (-quit rather than piping into grep -q, which under pipefail can turn a long match list into a pass.)
found=$(find "dist/$SLUG/vendor" -type d \( -name tests -o -name Tests \) -print -quit)
[ -z "$found" ] || die "a dependency's tests/ directory would ship: $found"

# 6. Every shipped PHP file parses on PHP 8.2.
command -v "$PHP_BIN" >/dev/null || die "no php found (set PHP_BIN)"
lint_version=$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')
if [ "$lint_version" != "8.2" ] && [ "${LINT_ANY_PHP:-}" != "1" ]; then
  die "$PHP_BIN is PHP $lint_version; lint needs 8.2, the oldest version the plugin supports (set PHP_BIN, or LINT_ANY_PHP=1 for a build that will not be released)"
fi
echo "== lint: $("$PHP_BIN" -r 'echo "PHP ", PHP_VERSION;')"
# vendor/ holds over a thousand files and PHP 8.2 lints one per run, so several run at once.
if ! lint=$(find "dist/$SLUG" -name '*.php' -print0 | xargs -0 -n1 -P "$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 2)" "$PHP_BIN" -l 2>&1); then
  grep -v '^No syntax errors' <<< "$lint" >&2
  die "lint failed"
fi

# 7. Zip the folder, not its contents, so the archive has the single top-level directory WordPress needs.
ZIP="$SLUG-v$VERSION.zip"
(cd dist && zip -qrX "$ZIP" "$SLUG")
unzip -l "dist/$ZIP" | tail -1
echo "== dist/$ZIP"
SHA256=$(sha256sum "dist/$ZIP" | cut -d' ' -f1)
echo "== sha256 $SHA256"
if [ -n "${GITHUB_OUTPUT:-}" ]; then
  { echo "version=$VERSION"; echo "zip=dist/$ZIP"; echo "sha256=$SHA256"; } >> "$GITHUB_OUTPUT"
fi
