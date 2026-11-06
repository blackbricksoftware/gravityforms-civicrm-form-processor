<?php

namespace BlackBrickSoftware\GravityFormsCiviCRMFormProcessor;

use BlackBrickSoftware\WpArtifactUpdater\V1\CheckAgain;
use BlackBrickSoftware\WpArtifactUpdater\V1\GitHubReleases;
use BlackBrickSoftware\WpArtifactUpdater\V1\InstallFolder;
use BlackBrickSoftware\WpArtifactUpdater\V1\Updater as LibraryUpdater;

/**
 * Updates for this plugin, from the GitHub releases of its public repository.
 *
 * WordPress can only update plugins it finds on wordpress.org, and this one isn't there. Instead, each release is a
 * GitHub release of blackbricksoftware/gravityforms-civicrm-form-processor with the built zip attached as
 * gravityforms-civicrm-form-processor-vX.Y.Z.zip, and the blackbricksoftware/wp-artifact-updater library (in vendor/,
 * which the release zip includes) tells WordPress about it. The update then shows on the Plugins page like any other
 * plugin's, and the plugin's "Enable auto-updates" link works.
 *
 * The repository is public, so no token is needed: the library asks api.github.com for the latest release and
 * downloads the zip from github.com without credentials (the requests carry WordPress's usual user agent, which names
 * the site). Its answer is cached for 6 hours (15 minutes after a failure).
 *
 * Updates are offered, never installed on their own: nothing here turns on auto-updates. An administrator can turn
 * them on for this plugin on the Plugins page. Sites where a deploy owns the plugins (composer, git) switch updates off
 * with WordPress's own DISALLOW_FILE_MODS, which removes the notice, "Update now" and auto-updates for every plugin.
 *
 * Two extras from the library, registered here explicitly because another plugin on the site may ship an older copy
 * of the library whose Updater knows nothing of them:
 * - InstallFolder: an update installs into the folder the plugin is already in. Release zips up to 2.0.0 had no folder
 *   inside them, so sites that uploaded one have the plugin in a folder named after the zip,
 *   gravity_forms_civicrm_form_processor/ (since WordPress 7.1.4, plus random characters), while newer zips, git and
 *   composer use gravityforms-civicrm-form-processor/. Without it, WordPress would install an update into the
 *   zip's folder and the plugin would end up deactivated.
 * - CheckAgain: Dashboard > Updates > "Check again" asks GitHub, rather than reading the 6-hour cache.
 *
 * The plugin header's Update URI (github.com) is what sends WordPress's update check for this plugin here. It also stops
 * wordpress.org from ever offering a different plugin that happens to use the same folder name.
 *
 * The repository's name is fixed here. Renaming or moving it on GitHub would stop updates on every site running this
 * version (GitHub's redirect for a renamed repository is not something to rely on).
 */
abstract class Updater
{

    /** The GitHub repository whose releases hold the plugin's zips. */
    public const REPO = 'blackbricksoftware/gravityforms-civicrm-form-processor';

    /** The release file to install. The version in its name is the version offered. */
    public const ARTIFACT = '/^gravityforms-civicrm-form-processor-v?(\d+\.\d+\.\d+)\.zip$/';

    /** How long the answer to "what is the newest release?" is kept before GitHub is asked again: 6 hours. */
    public const TTL = 21600;

    /**
     * Tells WordPress where this plugin's updates come from. Runs on plugins_loaded. Cheap: the library makes no
     * request until WordPress checks for updates.
     */
    public static function register(): void
    {
        // The library comes in the release zip's vendor/ folder (or, on a composer-managed site, the project's). If
        // it's missing, there are no updates, and nothing else is affected.
        if (!class_exists(LibraryUpdater::class) || !class_exists(InstallFolder::class)) {
            return;
        }
        $updater = LibraryUpdater::register([
            'plugin_file' => GRAVITYFORMS_CIVICRM_FORM_PROCESSOR,
            // Public, so no credential.
            'source' => new GitHubReleases(self::REPO),
            'artifact' => self::ARTIFACT,
            'ttl' => self::TTL,
        ]);
        InstallFolder::register(GRAVITYFORMS_CIVICRM_FORM_PROCESSOR);
        CheckAgain::register($updater);
    }
}
