<?php

namespace BlackBrickSoftware\GravityFormsCiviCRMFormProcessor;

use GFCommon;
use GF_Entry_List_Table;
use GFLogging;
use GFNotification;
use Illuminate\Support\Arr;
use KLogger;

// Not always included
if ( ! class_exists('GF_Entry_List_Table') ) {
    require_once GFCommon::get_base_path() . '/entry_list.php';
}
if ( ! class_exists( 'GFNotification' ) ) {
    require_once GFCommon::get_base_path() . '/notification.php';
}

class Webhooks
{
    /**
    * Add a setting to specify if JSON request body fields should be structured
    * https://docs.gravityforms.com/gform_addon_feed_settings_fields/
    */
    public function body_fields_settings($feed_settings_fields, $addon)
    {
        $feed_settings_fields[] = [
            'title'  => esc_html__('CiviCRM Settings (Must use Request Format FORM)', 'gravityforms-civicrm-form-processor'),
            'fields' => [
                // Add a toggle to turn on CiviCRM compatibility
                [
                    'name'          => 'CiviCRMAPIBodyFields',
                    'type'          => 'toggle',
                    'label'         => esc_html__('CiviCRM API Format', 'gravityforms-civicrm-form-processor'),
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
                        esc_html__('CiviCRM API Format', 'gravityforms-civicrm-form-processor'),
                        esc_html__('When CiviCRM API Format is enabled, the Request Body fields starting with "json." (API v3) or "params." (API v4) will be translated to urlencoded json under their respective key. This parameter is only valid when the FORM Request Format.', 'gravityforms-civicrm-form-processor')
                    ),
                ],
                // Enable notification settings for failed webhooks
                [
                    'name'           => 'CiviCRMWebhookFailureNotification',
                    'type'           => 'toggle',
                    'label'          => esc_html__('CiviCRM Webhook Failure Notification', 'gravityforms-civicrm-form-processor'),
                    'default_value'  => false,
                    'dependency'    => [
                        'live'   => true,
                        'fields' => [
                                [
                                    'field'  => 'requestFormat',
                                    'values' =>[ 'form' ],
                                ],
                                [
                                    'field'  => 'CiviCRMAPIBodyFields',
                                ],
                        ],
                    ],
                    'checkbox_label' => esc_html__('Enable Failure Notification', 'gravityforms-civicrm-form-processor'),
                    'instructions'   => esc_html__('Execute Webhook if', 'gravityforms-civicrm-form-processor'),
                    'tooltip'        => sprintf(
                        '<h6>%s</h6>%s',
                        esc_html__('Failure Notifications', 'gravityforms-civicrm-form-processor'),
                        esc_html__('When failure notifications are enabled, the the response from the webhooks will be intelligently analyzed for https status code failure and specific CiviCRM error messages.', 'gravityforms-civicrm-form-processor')
                    ),
                ],
                // To Email for notification settings for failed webhooks
                [
                    'name'                => 'CiviCRMWebhookFailureNotificationTo',
                    'label'               => esc_html__('To Email', 'gravityforms-civicrm-form-processor'),
                    'tooltip'             => gform_tooltip('civicrm_notification_send_to_email', null, true),
                    'type'                => 'text',
                    'required'			  => true,
                    'class'               => 'merge-tag-support mt-position-right mt-hide_all_fields',
                    'default_value'       => '{admin_email}',
                    'args'                => [ 'input_types' =>  [ 'email' ] ],
                    'validation_callback' => [$this, 'email_validation_callback'],
                    'dependency'          => [
                        'live'   => true,
                        'fields' => [
                            [
                                'field'  => 'requestFormat',
                                'values' =>[ 'form' ],
                            ],
                            [
                                'field'  => 'CiviCRMAPIBodyFields',
                            ],
                            [
                                'field'  => 'CiviCRMWebhookFailureNotification',
                            ],
                        ],
                    ],
                ],
                // From Name for notification settings for failed webhooks
                [
                    'name'    => 'CiviCRMWebhookFailureNotificationFromName',
                    'label'   => esc_html__('From Name', 'gravityforms-civicrm-form-processor'),
                    'tooltip' => gform_tooltip('civicrm_notification_from_name', null, true),
                    'type'    => 'text',
                    'class'   => 'merge-tag-support mt-position-right mt-hide_all_fields',
                    'dependency'          => [
                        'live'   => true,
                        'fields' => [
                            [
                                'field'  => 'requestFormat',
                                'values' =>[ 'form' ],
                            ],
                            [
                                'field'  => 'CiviCRMAPIBodyFields',
                            ],
                            [
                                'field'  => 'CiviCRMWebhookFailureNotification',
                            ],
                        ],
                    ],
                ],
                // From Email for notification settings for failed webhooks
                [
                    'name'                => 'CiviCRMWebhookFailureNotificationFrom',
                    'label'               => esc_html__('From Email', 'gravityforms-civicrm-form-processor'),
                    'tooltip'             => gform_tooltip('civicrm_notification_from_email', null, true),
                    'type'                => 'text',
                    'class'               => 'merge-tag-support mt-position-right mt-hide_all_fields',
                    'required'			  => true,
                    'default_value'       => '{admin_email}',
                    'args'                => [ 'input_types' =>  [ 'email' ] ],
                    'validation_callback' => [$this, 'email_validation_callback'],
                    'dependency'          => [
                        'live'   => true,
                        'fields' => [
                            [
                                'field'  => 'requestFormat',
                                'values' =>[ 'form' ],
                            ],
                            [
                                'field'  => 'CiviCRMAPIBodyFields',
                            ],
                            [
                                'field'  => 'CiviCRMWebhookFailureNotification',
                            ],
                        ],
                    ],
                ],
                // To Email for notification settings for failed webhooks
                [
                    'name'                => 'CiviCRMWebhookFailureNotificationBCC',
                    'label'               => esc_html__('BCC', 'gravityforms-civicrm-form-processor'),
                    'tooltip'             => gform_tooltip('civicrm_notification_bcc', null, true),
                    'type'                => 'text',
                    'class'               => 'merge-tag-support mt-position-right mt-hide_all_fields',
                    'args'                => [ 'input_types' =>  [ 'email' ] ],
                    'validation_callback' => [$this, 'email_validation_callback'],
                    'dependency'          => [
                        'live'   => true,
                        'fields' => [
                            [
                                'field'  => 'requestFormat',
                                'values' =>[ 'form' ],
                            ],
                            [
                                'field'  => 'CiviCRMAPIBodyFields',
                            ],
                            [
                                'field'  => 'CiviCRMWebhookFailureNotification',
                            ],
                        ],
                    ],
                ],
                // Subject for notification settings for failed webhooks
                [
                    'name'     						=> 'CiviCRMWebhookFailureNotificationSubject',
                    'label'    						=> esc_html__('Subject', 'gravityforms-civicrm-form-processor'),
                    'type'    						=> 'text',
                    'class'    						=> 'merge-tag-support mt-position-right mt-hide_all_fields',
                    'required' => true,
                    'default_value'       => 'CiviCRM Webhook Failure',
                    'dependency'          => [
                        'live'   => true,
                        'fields' => [
                            [
                                'field'  => 'requestFormat',
                                'values' =>[ 'form' ],
                            ],
                            [
                                'field'  => 'CiviCRMAPIBodyFields',
                            ],
                            [
                                'field'  => 'CiviCRMWebhookFailureNotification',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return $feed_settings_fields;
    }

    /**
     * Function to use in validation callbacks to validate value is an email
     */
    public function email_validation_callback($field, $value) {
        if (! empty($value) && ! GFNotification::is_valid_notification_email($value)) {
            $field->set_error(__("Please enter a valid email address or merge tag in the field.", 'gravityforms-civicrm-form-processor'));
        }
    }

    /**
     * If Structured body fields is enabled, array undot their keys
     * https://docs.gravityforms.com/gform_webhooks_request_data/
     */
    public function maybe_undot_request_keys($request_data, $feed, $entry, $form)
    {

        // Log all incoming data
        GFLogging::log_message('gravityformswebhooks', 'CiviCRM Form Processor (Request Data): ' . print_r($request_data, true), KLogger::DEBUG);
        GFLogging::log_message('gravityformswebhooks', 'CiviCRM Form Processor (Feed): ' . print_r($feed, true), KLogger::DEBUG);
        GFLogging::log_message('gravityformswebhooks', 'CiviCRM Form Processor (Entry): ' . print_r($entry, true), KLogger::DEBUG);
        GFLogging::log_message('gravityformswebhooks', 'CiviCRM Form Processor (Form): ' . print_r($form, true), KLogger::DEBUG);
        GFLogging::log_message('gravityformswebhooks', 'Seperator: ' . print_r('------------------------------------------------------------', true), KLogger::DEBUG);

        // Nothing?
        GFLogging::log_message('gravityformswebhooks', 'CiviCRM Form Processor (Original Request Data):' . print_r($request_data, true), KLogger::DEBUG);
        if (empty($request_data)) {
            return $request_data;
        }

        // Not sending Form data
        if (rgars($feed, 'meta/requestFormat') !== 'form') {
            return $request_data;
        }

        // docs seem to indicate these do not play nice
        if (strpos(rgars($feed, 'meta/requestURL'), 'automate.io') !== false) {
            return $request_data;
        }

        // setting not enabled
        GFLogging::log_message('gravityformswebhooks', 'CiviCRM Form Processor (Enabled?):' .  rgars($feed, 'meta/CiviCRMAPIBodyFields'), KLogger::DEBUG);
        if (!rgars($feed, 'meta/CiviCRMAPIBodyFields')) {
            return $request_data;
        }

        $multivalues = [];
        foreach($form['fields'] as $field) {
            if ($field->type == 'checkbox') {
                $multivalues[$field->id] = '';
            }
        }

        foreach($feed['fieldValues'] as $fv) {
            if (array_key_exists($fv['value'], $multivalues)) {
                $multivalues[$fv['value']] = $fv['custom_key'];
            }
        }

        GFLogging::log_message('gravityformswebhooks', 'CiviCRM Form Processor (Multivalues): ' . print_r($multivalues, true), KLogger::DEBUG);

        $undotted_request_data = Arr::undot($request_data);

        foreach (['json', 'params'] as $prefix) {
            // Remove the dotted params
            foreach ($request_data as $data_name => $data_value) {
                if (strpos($data_name, "$prefix.") === 0) {
                    if (in_array($data_name, $multivalues)) {
                        $data_value = explode(', ', $data_value);
                    }
                    unset($request_data[$data_name]);
                }
            }
            // Add undotted params
            if (array_key_exists($prefix, $undotted_request_data)) {
                $request_data[$prefix] = json_encode($undotted_request_data[$prefix]);
            }
        }

        GFLogging::log_message('gravityformswebhooks', 'CiviCRM Form Processor (Undotted Request Data):' . print_r($undotted_request_data, true), KLogger::DEBUG);
        GFLogging::log_message('gravityformswebhooks', 'CiviCRM Form Processor (New Request Data):' . print_r($request_data, true), KLogger::DEBUG);

        return $request_data;
    }

    /**
     * If enabled, mail contents and response of failed webhook
     * https://docs.gravityforms.com/gform_webhooks_post_request/
     */
    public function failed_webhook_notification ( $response, $feed, $entry, $form ) {

        $notification = rgars( $feed, 'meta/CiviCRMWebhookFailureNotification', false );

        // Not enabled
        if ( !$notification ) {
            return;
        }

        /**
         * Some form DNS Error
         */
        if ( is_wp_error( $response ) ) {
            $this->log_variables( 'Post Webhook Failed: is_wp_error( $response )', $response, $feed, $entry, $form );
            $this->send_failed_webhook_email( 'Network Error', 'Sending data to CiviCRM failed with a possible network error.', $response, $feed, $entry, $form);
            return;
        }

        /**
         * Some form of HTTP Error
         */
        if ( $response['response']['code'] !== 200 ) { // I think CiviCRM always returns 200 for success
            $this->log_variables( 'Post Webhook Failed: $response[response][code] !== 200', $response, $feed, $entry, $form );
            $this->send_failed_webhook_email( 'Invalid HTTP Status Code', 'Sending data to CiviCRM failed with an invalid return code.', $response, $feed, $entry, $form);
            return;
        }

        /**
         * Response not JSON
         */
        try {
            $responseData = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->log_variables( 'Post Webhook: $response[body] is not JSON', $response, $feed, $entry, $form );
            $this->send_failed_webhook_email( 'Response Was Not JSON', 'Sending data to CiviCRM failed with a return payload that was not valid JSON. Verify the GET or POST parameters contain a \'json\' key.', $response, $feed, $entry, $form);
            return;
        }

        /**
         * CiviCRM API (v3 or v4) or Form Processor Error Error
         */
        if ( $responseData['is_error'] || array_key_exists('error_code', $responseData) ) {
            $this->log_variables( 'Post Webhook Failed: $responseData[is_error] || array_key_exists(error_code, $responseData)', $response, $feed, $entry, $form, $responseData );
            $this->send_failed_webhook_email( 'CiviCRM API Returned an Error', 'The CiviCRM API returned an error message. Verify all required field are submitted and contain valid data.', $response, $feed, $entry, $form, $responseData );
            return;
        }

        // No Error Detected (probably)
    }

    public function log_variables ( $msg, ...$vars ) {

        GFLogging::log_message( 'gravityformswebhooks', $msg );

        foreach ($vars as $var) {
            GFLogging::log_message( 'gravityformswebhooks', print_r($var, true) );
        }
    }

    public function send_failed_webhook_email ( $header, $errorMsg, $response, $feed, $entry, $form, $responseData = null ) {

        $entryListTable = new GF_Entry_List_Table([
            'form' => $form,
        ]);
        $entryId = $entry['id'];
        $entryUrl = $entryListTable->get_detail_url( $entry );

        $toRaw = rgars( $feed, 'meta/CiviCRMWebhookFailureNotificationTo' );
        $fromNameRaw = rgars( $feed, 'meta/CiviCRMWebhookFailureNotificationFromName' );
        $fromRaw = rgars( $feed, 'meta/CiviCRMWebhookFailureNotificationFrom' );
        $bccRaw = rgars( $feed, 'meta/CiviCRMWebhookFailureNotificationBCC' );
        $subjectRaw = rgars( $feed, 'meta/CiviCRMWebhookFailureNotificationSubject' );

        $to = GFCommon::replace_variables( $toRaw, $form, $entry, false, false, false, 'text' );
        $fromName = GFCommon::replace_variables( $fromNameRaw, $form, $entry, false, false, false, 'text' );
        $from = GFCommon::replace_variables( $fromRaw, $form, $entry, false, false, false, 'text' );
        $bcc = GFCommon::replace_variables( $bccRaw, $form, $entry, false, false, false, 'text' );
        $subject = GFCommon::replace_variables( $subjectRaw, $form, $entry, false, false, false, 'text' );
        
        $emailInfoBefore = <<<EMAIL
        Post Webhook Failed
        === Email Vars Before and After Replacement ===
        To: $toRaw / $to
        From Name: $fromNameRaw / $fromName
        From: $fromRaw / $from
        BCC: $bccRaw / $bcc
        Subject: $subjectRaw / $subject
        === / Email Vars Before and After Replacement ===\n\n
        EMAIL;
        GFLogging::log_message( 'gravityformswebhooks', $emailInfoBefore );

        $debugInfo = $this->get_notification_debug_html( $response, $feed, $entry, $form, $responseData );
        $message = <<<MSG
        <p><strong>{$header}</strong></p>
        <p>{$errorMsg}</p>
        <p>The data is still accessible at entry <a href="{$entryUrl}" target="_blank">#{$entryId}</a> and in notification emails.</p>
        $debugInfo
        MSG;

        GFCommon::send_email( $from, $to, $bcc, '', $subject, $message, $fromName, 'html', '', $entry );
    }

    
    public function get_notification_debug_html( $response, $feed, $entry, $form, $responseData = null ) {
        
        $responseDump = $this->dump_and_encode( $response );
        $feedDump = $this->dump_and_encode( $feed );
        $entryDump = $this->dump_and_encode( $entry );
        $formDump = $this->dump_and_encode( $form );

        $debugInfo = <<<DEBUG
        === Debug Information ===
        <p><strong>\$response</strong></p>
        <code style="white-space: pre-wrap;">
            {$responseDump}
        </code>
        <p><strong>\$feed</strong></p>
        <code style="white-space: pre-wrap;">
            {$feedDump}
        </code>
        <p><strong>\$entry</strong></p>
        <code style="white-space: pre-wrap;">
            {$entryDump}
        </code>
        <p><strong>\$form</strong></p>
        <code style="white-space: pre-wrap;">
            {$formDump}
        </code>
        === / Debug Information ===\n\n
        DEBUG;

        if ( $responseData !== null ) {
            $responseDataDump = $this->dump_and_encode( $responseData );
            $responseInfo = <<<DEBUG
            === Response Data ===
            <p><strong>\$responseData</strong></p>
            <code style="white-space: pre-wrap;">
                {$responseDataDump}
            </code>
            === / Response Data ===\n\n
            DEBUG;
            $debugInfo = $responseInfo . $debugInfo;
        }

        return $debugInfo;
    }

    public function dump_and_encode($rawText)
    {
        return htmlspecialchars( print_r( $rawText, true ) );
    }
}
