<?php

/**
 * Plugin Name: Gravity Forms CiviCRM Form Processor
 * Plugin URI: https://github.com/blackbricksoftware/gravityforms-civicrm-form-processor
 * Description: Add functionality to make Gravity Forms to CiviCRM integrations easier.
 * Author: Black Brick Software LLC
 * Author URI: https://blackbricksoftware.com
 * Version: v1.0.0
 * Text Domain: gravityforms-civicrm-form-processor
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

/**
 * Composer dependencies
 */
require_once __DIR__ . '/libs/autoload.php';

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

// (if enabled) Send a notification email of failed webhook or delete entry
add_action('gform_webhooks_post_request', [Webhooks::class, 'after_webhook_actions'], 10, 4);
