<?php

/**
 * Runs when the plugin is deleted from the Plugins page. It removes the updater's cached answer about the newest
 * GitHub release, which is all the plugin keeps of its own. The form and webhook settings it adds are stored by Gravity
 * Forms with each form and feed, and are left alone.
 *
 * The name is written out here rather than taken from the plugin's classes, because WordPress runs this file on its
 * own, without loading the plugin.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
  exit;
}

// The updater library caches the newest release under this key: the plugin's basename and the update host, hashed.
// WP_UNINSTALL_PLUGIN holds the basename, such as gravityforms-civicrm-form-processor/gravityforms-civicrm-form-processor.php.
delete_site_transient('wpu_release_' . md5(WP_UNINSTALL_PLUGIN . '|github.com'));
