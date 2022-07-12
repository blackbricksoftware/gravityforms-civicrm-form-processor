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
    public function maybe_undot_request_keys($request_data, $feed)
    {

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

        $undotted_request_data = Arr::undot($request_data);

        foreach (['json', 'params'] as $prefix) {
            // Remove the dotted params
            foreach ($request_data as $data_name => $data_value) {
                if (strpos($data_name, "$prefix.") === 0) {
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

        $to = rgars( $feed, 'meta/CiviCRMWebhookFailureNotificationTo' );
        $fromName = rgars( $feed, 'meta/CiviCRMWebhookFailureNotificationFromName' );
        $from = rgars( $feed, 'meta/CiviCRMWebhookFailureNotificationFrom' );
        $bcc = rgars( $feed, 'meta/CiviCRMWebhookFailureNotificationBCC' );
        $subject = rgars( $feed, 'meta/CiviCRMWebhookFailureNotificationSubject' );

        // GFCommon::send_email(
        //     $from,
        //     $to,
        //     $bcc,
        //     $reply_to,
        //     $subject,
        //     $message,
        //     $from_name = '',
        //     $message_format = 'html',
        //     $attachments = '',
        //     $entry = false,
        //     $notification = false,
        //     $cc = null
        // )

        // $response
        // 'headers' (string[]) Array of response headers keyed by their name.
        // 'body' (string) Response body.
        // 'response' (array) Data about the HTTP response.
        // 'code' (int|false) HTTP response code.
        // 'message' (string|false) HTTP response message.
        // 'cookies' (WP_HTTP_Cookie[]) Array of response cookies.
        // 'http_response' (WP_HTTP_Requests_Response|null) Raw HTTP response object.

        GFLogging::log_message('gravityformswebhooks', "Post Webhook Settings: 
            notification: $notification
            to: $to
            fromName: $fromName
            from: $from
            bcc: $bcc
            subject: $subject
        ", KLogger::DEBUG);

        $entryListTable = new GF_Entry_List_Table([
            'form' => $form,
        ]);
        $entryId = $entry['id'];
        $entryUrl = $entryListTable->get_detail_url( $entry );

        /**
         * Some form DNS Error
         */
        if ( is_wp_error( $response ) ) {
            $debugInfo = $this->get_notification_debug_information( $response, $feed, $entry, $form );
            $message = <<<MSG
            <p><strong>Network Error</strong></p>
            <p>Sending data to CiviCRM failed with a possible network error. The data is still accessible at entry <a href="{$entryUrl}" target="_blank">#{$entryId}</a> and in notification emails.</p>
            $debugInfo
            MSG;
            GFLogging::log_message('gravityformswebhooks', "Post Webhook: is_wp_error( \$response )\n$debugInfo");
            GFCommon::send_email( $from, $to, $bcc, '', $subject, $message, $fromName, 'html', '', $entry );
            return;
        }

        /**
         * Some form of HTTP Error
         */
        if ( $response['response']['code'] !== 200 ) { // I think CiviCRM always returns 200 for success
            $debugInfo = $this->get_notification_debug_information( $response, $feed, $entry, $form );
            $message = <<<MSG
            <p><strong>Invalid HTTP Status Code</strong></p>
            <p>Sending data to CiviCRM failed with an invalid return code. The data is still accessible at entry <a href="{$entryUrl}" target="_blank">#{$entryId}</a> and in notification emails.</p>
            $debugInfo
            MSG;
            GFLogging::log_message('gravityformswebhooks', "Post Webhook: \$response['response']['code'] !== 200\n$debugInfo");
            GFCommon::send_email( $from, $to, $bcc, '', $subject, $message, $fromName, 'html', '', $entry );
            return;
        }

        /**
         * Response not JSON
         */
        try {
            $responseData = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $debugInfo = $this->get_notification_debug_information( $response, $feed, $entry, $form );
            $message = <<<MSG
            <p><strong>Response Was Not JSON</strong></p>
            <p>Sending data to CiviCRM failed with a return payload that was not valid JSON. Verify the GET or POST parameters contain a 'json' key. The data is still accessible at entry <a href="{$entryUrl}" target="_blank">#{$entryId}</a> and in notification emails.</p>
            $debugInfo
            MSG;
            GFLogging::log_message('gravityformswebhooks', "Post Webhook: \$response['body'] is not JSON \n$debugInfo");
            GFCommon::send_email( $from, $to, $bcc, '', $subject, $message, $fromName, 'html', '', $entry );
            return;
        }

        /**
         * CiviCRM API (v3 or v4) Error
         */
        if ( $responseData['is_error'] || array_key_exists('error_code', $responseData) ) {
            $debugInfo = $this->get_notification_debug_information( $response, $feed, $entry, $form );
            $responseDataDump = print_r($responseData, true);
            $message = <<<MSG
            <p><strong>CiviCRM API Returned an Error</strong></p>
            <p>The CiviCRM API returned an error message. Verify all required field are submitted and contain valid information. The data is still accessible at entry <a href="{$entryUrl}" target="_blank">#{$entryId}</a> and in notification emails.</p>
            === Response Data ===
            <p><strong>\$responseData</strong></p>
            <code>
                {$responseDataDump}
            </code>
            === / Response Data ===
            $debugInfo
            MSG;
            GFLogging::log_message('gravityformswebhooks', "Post Webhook: \$responseData['is_error'] || array_key_exists('error_code', \$responseData) \n$debugInfo");
            GFCommon::send_email( $from, $to, $bcc, '', $subject, $message, $fromName, 'html', '', $entry );
            return;
        }

        /**
         * CiviCRM Form Processor Error
         */
        // {"input":{"first_name":"first","last_name":"last"},"action":[{"action":"Create Guest Contact","output":{"contact_id":56831}}]}

        // No Error Detected
    }

    public function get_notification_debug_information( $response, $feed, $entry, $form ) {
        
        $responseDump = print_r($response, true);
        $feedDump = print_r($feed, true);
        $entryDump = print_r($entry, true);
        $formDump = print_r($form, true);

        return <<<DEBUG
        === Debug Information ===
        <p><strong>\$response</strong></p>
        <code>
            {$responseDump}
        </code>
        <p><strong>\$feed</strong></p>
        <code>
            {$feedDump}
        </code>
        <p><strong>\$entry</strong></p>
        <code>
            {$entryDump}
        </code>
        <p><strong>\$form</strong></p>
        <code>
            {$formDump}
        </code>
        === / Debug Information ===
        DEBUG;
    }
}
