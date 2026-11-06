<?php

/**
 * Plugin Name: Gravity Forms CiviCRM Form Processor
 * Plugin URI: https://github.com/blackbricksoftware/gravityforms-civicrm-form-processor
 * Description: Add functionality to make Gravity Forms to CiviCRM integrations easier.
 * Author: Black Brick Software LLC
 * Author URI: https://blackbricksoftware.com
 * Version: 2.1.0
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

  // Everything else extends Gravity Forms and its Webhooks add-on, and does nothing without them.
  if (!class_exists('GFForms')) {
    return;
  }

  // Include GravityForms classes, Not all are always available
  add_action('init', [Loader::class, 'include_gravity_forms_classes']);

  // Add additional Form Settings
  add_filter('gform_form_settings_fields', [Forms::class, 'register_webhook_settings'], 10, 2);

  // Set the Retries attempts
  add_filter('gform_max_async_feed_attempts', [Forms::class, 'set_webhook_retry_attempts'], 10, 5);

  // Maybe delete entry after feed processed
  add_action('gform_post_process_feed', [Entries::class, 'maybe_delete_entry'], 10, 4);

  // More available tool tips
  add_filter('gform_tooltips', [Tooltips::class, 'add_gfform_tooltips'], 10, 2);

  // Add settings
  add_filter('gform_gravityformswebhooks_feed_settings_fields', [Webhooks::class, 'body_fields_settings'], 10, 2);

  // Modify outgoing webhook format
  add_filter('gform_webhooks_request_data', [Webhooks::class, 'maybe_undot_request_keys'], 10, 4);

  // Set a per-webhook request timeout (overrides WordPress's 5s default for this webhook only)
  add_filter('gform_webhooks_request_args', [Webhooks::class, 'set_request_timeout'], 10, 4);

  // (if enabled) Send a notification email of failed webhook or delete entry
  add_action('gform_webhooks_post_request', [Webhooks::class, 'after_webhook_actions'], 10, 4);
});
