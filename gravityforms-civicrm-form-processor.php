<?php
/**
 * Plugin Name: Gravity Forms CiviCRM Form Processor
 * Plugin URI: https://github.com/blackbricksoftware/gravityforms-civicrm-form-processor
 * Description: Add functionality to make Gravity Forms to CiviCRM integrations easier.
 * Author: Black Brick Software LLC
 * Author URI: https://blackbricksoftware.com
 * Version: v1.0.0-beta1
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

defined('ABSPATH') || die();

require_once __DIR__ . '/libs/autoload.php';

use BlackBrickSoftware\GravityFormsCiviCRMFormProcessor\Webhooks;
use BlackBrickSoftware\GravityFormsCiviCRMFormProcessor\Tooltips;

$tooltips = new Tooltips;
$webhooks = new Webhooks;

// More available tool tips
add_filter( 'gform_tooltips', [ $tooltips, 'add_gfform_tooltips' ], 10, 2);

// Add settings
add_filter( 'gform_gravityformswebhooks_feed_settings_fields', [ $webhooks, 'body_fields_settings' ], 10, 2 );

// Modify outgoing webhook format
add_filter( 'gform_webhooks_request_data', [ $webhooks, 'maybe_undot_request_keys' ], 10, 2 );

// (if enabled) Send a notification email of failed webhook
add_action( 'gform_webhooks_post_request', [ $webhooks, 'failed_webhook_notification' ], 10, 4 );