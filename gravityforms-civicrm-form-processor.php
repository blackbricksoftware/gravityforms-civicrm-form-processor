<?php

/**
 * Plugin Name: Gravity Forms CiviCRM Form Processor
 * Plugin URI: https://github.com/blackbricksoftware/gravityforms-civicrm-form-processor
 * Description: Add functionality to make Gravity Forms to CiviCRM integrations easier.
 * Author: Black Brick Software LLC
 * Author URI: https://blackbricksoftware.com
 * Version: 2.3.0
 * Requires PHP: 8.2
 * Text Domain: gravityforms-civicrm-form-processor
 * Update URI: https://github.com/blackbricksoftware/gravityforms-civicrm-form-processor
 *
 * Gravity Forms CiviCRM Form Processor is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Gravity Forms CiviCRM Form Processor is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

namespace BlackBrickSoftware\GravityFormsCiviCRMFormProcessor;

defined('ABSPATH') || die();

// Composer's autoloader, which the release zip includes. A site that installs the plugin with composer loads the
// project's own autoloader instead, so this one may not be here.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
  require_once __DIR__ . '/vendor/autoload.php';
}

// This file. The updater reads the plugin's folder and version from it.
define('GRAVITYFORMS_CIVICRM_FORM_PROCESSOR', __FILE__);

/**
 * Everything the plugin hooks into WordPress, in one place, on plugins_loaded rather than when this file is included.
 *
 * By plugins_loaded every active plugin has been loaded, so whether Gravity Forms is there can be checked whatever
 * order the plugins load in. Every hook below fires later than this (init, or Gravity Forms' own hooks).
 */
add_action('plugins_loaded', function () {

  // No autoloader found the plugin's classes (a GitHub "Source code" zip has no vendor/, say): do nothing, rather than
  // stop every page with an error.
  if (!class_exists(Updater::class)) {
    return;
  }

  // Updates from the plugin's GitHub releases (src/Updater.php). BEFORE the Gravity Forms check on purpose: an update
  // that fixes something must still be installable while Gravity Forms is missing or deactivated. Offered, never
  // forced; WordPress's DISALLOW_FILE_MODS switches them off along with every other plugin's.
  Updater::register();

  // The notice after activating this copy deactivated an older copy in another folder (see the activation hook below).
  if (is_admin()) {
    OldCopies::register();
  }

  // Everything else extends Gravity Forms and its Webhooks add-on, and does nothing without them.
  if (!class_exists('GFForms')) {
    return;
  }

  // The Gravity Forms classes this plugin uses directly, which aren't always loaded (on init).
  Loader::register();

  // Form settings: delete entries after their webhooks succeed, and how many times to try a webhook.
  Forms::register();

  // Deleting an entry after its webhooks, when the form asks.
  Entries::register();

  // Help text for the settings.
  Tooltips::register();

  // Each webhook's CiviCRM settings and request: the CiviCRM API format, timeout, failure check and email.
  Webhooks::register();

  // The "Webhooks" box on an entry's page: send any of the form's active webhooks again for that entry.
  WebhookResend::register();
});

/**
 * Activation: deactivates any older copy of this plugin active in another folder (src/OldCopies.php), so moving a site
 * from a 2.0.0-era install is upload, activate. Registered here rather than on plugins_loaded, because it needs this
 * file's path, and because WordPress loads this file to activate the plugin after plugins_loaded has already run.
 */
register_activation_hook(__FILE__, static function (mixed $networkWide = false): void {
  if (class_exists(OldCopies::class)) {
    OldCopies::deactivateOthers($networkWide);
  }
});
