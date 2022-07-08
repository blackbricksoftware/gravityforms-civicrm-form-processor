<?php
/**
 * Plugin Name: Gravity Forms CiviCRM Form Processor
 * Plugin URI: https://github.com/blackbricksoftware/gravityforms-civicrm-form-processor
 * Description: Add functionality to make Gravity Forms to CiviCRM integrations easier.
 * Author: Black Brick Software LLC
 * Author URI: https://blackbricksoftware.com
 * Version: 1.0.0
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

require_once __DIR__ . '/libs/autoload.php';

use GFLogging;
use Illuminate\Support\Arr;
use KLogger;

/**
 * Add a setting to specify if JSON request body fields should be structured
 * https://docs.gravityforms.com/gform_addon_feed_settings_fields/
 */
function body_fields_settings( $feed_settings_fields, $addon ) {
	$feed_settings_fields = $addon->add_field_after( 'requestFormat', array(
		[
			'name'          => 'CiviCRMAPIBodyFields',
			'type'          => 'toggle',
			'label'         => esc_html__( 'CiviCRM API Format', 'gravityforms-civicrm-form-processor' ),
			'default_value' => false,
			'dependency'    => [
				'live'   => true,
				'fields' => [
					[
						'field'  => 'requestFormat',
						'values' =>[ 'form' ],
					],
				],
			],
			'tooltip'        => sprintf(
				'<h6>%s</h6>%s',
				esc_html__( 'CiviCRM API Format', 'gravityforms-civicrm-form-processor' ),
				esc_html__( 'When CiviCRM API Format is enabled, the Request Body fields starting with "json." (API v3) or "params." (API v4) will be translated to urlencoded json under their respective key.', 'gravityforms-civicrm-form-processor' )
			),			
		],
	), $feed_settings_fields );
	return $feed_settings_fields;
}
add_filter( 'gform_gravityformswebhooks_feed_settings_fields', 'BlackBrickSoftware\GravityFormsCiviCRMFormProcessor\body_fields_settings', 10, 2 );

/**
 * If Structured body fields is enabled, array undot their keys
 * https://docs.gravityforms.com/gform_webhooks_request_data/
 */
function maybe_undot_request_keys( $request_data, $feed ) {


	// Nothing?
	GFLogging::log_message( 'gravityformswebhooks', 'CiviCRM Form Processor (Original Request Data):' . print_r($request_data, true), KLogger::DEBUG );
	if (empty($request_data)) {
		return $request_data;
	}

	// Not sending Form data
	if (rgars( $feed, 'meta/requestFormat' ) !== 'form') {
		return $request_data;
	}

	// docs seem to indicate these do not play nice
	if (strpos( rgars( $feed, 'meta/requestURL' ), 'automate.io' ) !== false) {
		return $request_data;
	}

	// setting not enabled
	GFLogging::log_message( 'gravityformswebhooks', 'CiviCRM Form Processor (Enabled?):' .  rgars( $feed, 'meta/CiviCRMAPIBodyFields' ) , KLogger::DEBUG );
	if (!rgars( $feed, 'meta/CiviCRMAPIBodyFields' )) {
		return $request_data;
	}

	$undotted_request_data = Arr::undot($request_data);

	foreach (['json', 'params'] as $prefix) {
		if (array_key_exists($prefix, $undotted_request_data)) {
			$request_data[$prefix] = json_encode($undotted_request_data[$prefix]);
		}
	}

	GFLogging::log_message( 'gravityformswebhooks', 'CiviCRM Form Processor (Undotted Request Data):' . print_r( $undotted_request_data, true), KLogger::DEBUG );
	GFLogging::log_message( 'gravityformswebhooks', 'CiviCRM Form Processor (New Request Data):' . print_r( $request_data, true), KLogger::DEBUG );

	return $request_data;
}
add_filter( 'gform_webhooks_request_data', 'BlackBrickSoftware\GravityFormsCiviCRMFormProcessor\maybe_undot_request_keys', 10, 2 );