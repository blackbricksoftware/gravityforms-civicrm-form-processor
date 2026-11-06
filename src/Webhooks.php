<?php

namespace BlackBrickSoftware\GravityFormsCiviCRMFormProcessor;

use GFAPI;
use GFCommon;
use GFNotification;
use Exception;
use Illuminate\Support\Arr;
use JsonException;
use WP_Error;

abstract class Webhooks
{

    /** The request argument that marks a request as one of the Webhooks add-on's, for check_civicrm_response(). */
    public const CHECK = 'gravityforms_civicrm_form_processor_check';

    /** The start of the WP_Error codes check_civicrm_response() gives a failed answer. */
    public const FAILURE = 'gravityforms_civicrm_form_processor_';

    /**
     * Each webhook's CiviCRM settings, and what happens around its request: the CiviCRM API format of the data, the
     * webhook's own timeout (instead of WordPress's 5 seconds), the check of CiviCRM's answer, and the failure email.
     */
    public static function register(): void
    {
        add_filter('gform_gravityformswebhooks_feed_settings_fields', [self::class, 'body_fields_settings'], 10, 2);
        add_filter('gform_webhooks_request_data', [self::class, 'maybe_undot_request_keys'], 10, 4);
        add_filter('gform_webhooks_request_args', [self::class, 'set_request_timeout'], 10, 4);
        add_filter('gform_webhooks_request_args', [self::class, 'mark_request'], 20, 4);
        add_filter('http_response', [self::class, 'check_civicrm_response'], 10, 3);
        add_action('gform_webhooks_post_request', [self::class, 'after_webhook_actions'], 10, 4);
    }
    /**
    * Add a setting to specify if JSON request body fields should be structured
    * https://docs.gravityforms.com/gform_addon_feed_settings_fields/
    */
    public static function body_fields_settings($feed_settings_fields, $addon): array
    {
        // gform_tooltip() lives in Gravity Forms' tooltips.php, which it only loads on its admin screens. These settings
        // are also built elsewhere: GFAPI::add_feed() and update_feed() build them to know which fields to encrypt,
        // from WP-CLI, the REST API or an import, where calling it was a fatal error. Gravity Forms loads the file the
        // same way when it needs it.
        if (!function_exists('gform_tooltip') && class_exists('GFCommon')) {
            require_once GFCommon::get_base_path() . '/tooltips.php';
        }

        $feed_settings_fields['civicrm_webhook_fields'] = [
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
                                'values' => [ 'form' ],
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
                                    'values' => [ 'form' ],
                                ],
                                [
                                    'field'  => 'CiviCRMAPIBodyFields',
                                ],
                        ],
                    ],
                    'tooltip'        => sprintf(
                        '<h6>%s</h6>%s',
                        esc_html__('Failure Notifications', 'gravityforms-civicrm-form-processor'),
                        esc_html__('When failure notifications are enabled, the response from the webhooks will be intelligently analyzed for https status code failure and specific CiviCRM error messages.', 'gravityforms-civicrm-form-processor')
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
                    'validation_callback' => [static::class, 'email_validation_callback'],
                    'dependency'          => [
                        'live'   => true,
                        'fields' => [
                            [
                                'field'  => 'requestFormat',
                                'values' => [ 'form' ],
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
                                'values' => [ 'form' ],
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
                    'validation_callback' => [static::class, 'email_validation_callback'],
                    'dependency'          => [
                        'live'   => true,
                        'fields' => [
                            [
                                'field'  => 'requestFormat',
                                'values' => [ 'form' ],
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
                    'validation_callback' => [static::class, 'email_validation_callback'],
                    'dependency'          => [
                        'live'   => true,
                        'fields' => [
                            [
                                'field'  => 'requestFormat',
                                'values' => [ 'form' ],
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
                                'values' => [ 'form' ],
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

        // Per-webhook request resilience settings, isolated to the firing feed's request
        $feed_settings_fields['civicrm_webhook_request_settings'] = [
            'title'  => esc_html__('CiviCRM Webhook Request Settings', 'gravityforms-civicrm-form-processor'),
            'fields' => [
                [
                    'name'                => 'CiviCRMWebhookTimeout',
                    'type'                => 'text',
                    'label'               => esc_html__('Request Timeout (seconds)', 'gravityforms-civicrm-form-processor'),
                    'default_value'       => 30,
                    'validation_callback' => [static::class, 'positive_int_validation_callback'],
                    'tooltip'             => sprintf(
                        '<h6>%s</h6>%s',
                        esc_html__('Request Timeout', 'gravityforms-civicrm-form-processor'),
                        esc_html__('Maximum number of seconds to wait for this webhook\'s HTTP request before it times out. Overrides the WordPress default of 5 seconds for this webhook only. Defaults to 30 seconds; increase it if CiviCRM is slow to respond and you are seeing cURL error 28 (Operation timed out).', 'gravityforms-civicrm-form-processor')
                    ),
                ],
                [
                    'name'                => 'CiviCRMWebhookMaxAttempts',
                    'type'                => 'text',
                    'label'               => esc_html__('Max Attempts (override form setting)', 'gravityforms-civicrm-form-processor'),
                    'default_value'       => '',
                    'validation_callback' => [static::class, 'positive_int_validation_callback'],
                    'tooltip'             => sprintf(
                        '<h6>%s</h6>%s',
                        esc_html__('Webhook Max Attempts (per feed)', 'gravityforms-civicrm-form-processor'),
                        esc_html__('Number of times to attempt this specific webhook before giving up. Overrides the form-level "Number of times to try submitting a Webhook" setting for this feed only. Leave blank to use the form-level setting.', 'gravityforms-civicrm-form-processor')
                    ),
                ],
            ],
        ];

        return $feed_settings_fields;
    }

    /**
     * Function to use in validation callbacks to validate value is an email
     */
    public static function email_validation_callback($field, $value): void
    {
        if (!empty($value) && ! GFNotification::is_valid_notification_email($value)) {
            $field->set_error(__("Please enter a valid email address or merge tag in the field.", 'gravityforms-civicrm-form-processor'));
        }
    }

    /**
     * Validate a positive whole number, or blank. Shared by the per-feed numeric
     * settings (request timeout, max attempts) where blank means "use the default".
     */
    public static function positive_int_validation_callback($field, $value): void
    {
        if ($value === '' || $value === null) {
            return;
        }
        if (!ctype_digit((string) $value) || (int) $value < 1) {
            $field->set_error(__('Please enter a positive whole number, or leave it blank.', 'gravityforms-civicrm-form-processor'));
        }
    }

    /**
     * Set a per-webhook HTTP request timeout, isolated to the firing feed's request.
     * Overrides the WordPress default (5s) for this webhook only, rather than globally.
     * https://docs.gravityforms.com/gform_webhooks_request_args/
     */
    public static function set_request_timeout($request_args, $feed, $entry, $form): array
    {
        $timeout = rgars($feed, 'meta/CiviCRMWebhookTimeout');

        // Not configured for this feed: leave the WordPress default in place.
        if (!is_numeric($timeout) || (int) $timeout < 1) {
            return $request_args;
        }

        $request_args['timeout'] = (int) $timeout;

        Log::debug(sprintf('CiviCRM Form Processor: webhook request timeout set to %d seconds for feed %s', (int) $timeout, rgar($feed, 'id')));

        return $request_args;
    }

    /**
     * If Structured body fields is enabled, array undot their keys
     * https://docs.gravityforms.com/gform_webhooks_request_data/
     */
    public static function maybe_undot_request_keys($request_data, $feed, $entry, $form): array
    {

        // Nothing?
        Log::debug('CiviCRM Form Processor (Original Request Data):' . print_r($request_data, true));
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
        Log::debug('CiviCRM Form Processor (Enabled?):' .  rgars($feed, 'meta/CiviCRMAPIBodyFields'));
        if (!rgars($feed, 'meta/CiviCRMAPIBodyFields')) {
            return $request_data;
        }

        // identify gf field ids of checkboxes (multi-value)
        $multivalues = [];
        foreach ($form['fields'] as $field) {
            if ($field->type == 'checkbox') {
                $multivalues[$field->id] = '';
            }
        }

        // map gf field ids to their civicrm field counterpart
        foreach ($feed['meta']['fieldValues'] as $fv) {
            if (array_key_exists($fv['value'], $multivalues)) {
                $multivalues[$fv['value']] = $fv['custom_key'];
            }
        }

        // explode multi-values into arrays for CiviCRM consumption (and trim whitespace in values)
        foreach ($request_data as $data_name => $data_value) {
            if (in_array($data_name, $multivalues)) {
                $exploded_values = array_map('trim', explode(',', $data_value));
                // if only one value is submitted, it shouldn't be submitted as array with single element
                if (count($exploded_values) == 1) {
                    $exploded_values = array_pop($exploded_values);
                }
                $request_data[$data_name] = $exploded_values;
            }
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

        Log::debug('CiviCRM Form Processor (Undotted Request Data):' . print_r($undotted_request_data, true));
        Log::debug('CiviCRM Form Processor (New Request Data):' . print_r($request_data, true));

        return $request_data;
    }

    /**
     * If enabled, mail contents and response of failed webhook
     * https://docs.gravityforms.com/gform_webhooks_post_request/
     */
    /** The gform_webhooks_request_args filter: marks the request as a webhook's, so its answer is checked. */
    public static function mark_request($request_args, $feed, $entry, $form): array
    {
        $request_args = is_array($request_args) ? $request_args : [];
        $request_args[self::CHECK] = (int) rgar($feed, 'id');
        return $request_args;
    }

    /**
     * WordPress's http_response filter, for a webhook's request (mark_request()): an answer that is not CiviCRM's
     * success becomes a WP_Error, with the answer in its data.
     *
     * This is how a failure reaches Gravity Forms. The Webhooks add-on treats a WP_Error from the request as a failed
     * webhook: it records the failure, the background processor tries again (as many times as the attempts settings
     * allow), "Delete entries after Webhooks" leaves the entry alone, and a webhook sent again from the entry's page
     * reports it. Until 2.3.0 the failure was an exception thrown from gform_webhooks_post_request, and since Gravity
     * Forms 3.0.2 its gf_do_action() catches exceptions from hooks and only re-throws them when WP_DEBUG is on. So on a
     * live site every failure CiviCRM answered was recorded as a success, never retried, and with "Delete entries"
     * on, the entry was deleted; only the failure email went out.
     */
    public static function check_civicrm_response(mixed $response, mixed $args = [], mixed $url = ''): mixed
    {
        if (!is_array($args) || empty($args[self::CHECK]) || !is_array($response)) {
            return $response;
        }
        return self::civicrm_failure($response) ?? $response;
    }

    /**
     * What is wrong with CiviCRM's answer to a webhook, or null when it is a success: an HTTP status other than 200,
     * an answer that isn't a JSON object, or a CiviCRM API (v3 or v4) or Form Processor error.
     */
    public static function civicrm_failure(array $response): ?WP_Error
    {
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) { // CiviCRM answers 200 when it succeeds
            return self::failure('http_status', sprintf(__('The server answered with HTTP %d.', 'gravityforms-civicrm-form-processor'), $code),
                'Post Webhook Failed: $response[response][code] !== 200',
                'Invalid HTTP Status Code: Sending data to CiviCRM failed with an invalid return code.', $response);
        }
        $notJson = __('The answer was not JSON, so CiviCRM did not handle it (check the webhook\'s URL and request format).', 'gravityforms-civicrm-form-processor');
        $notJsonEmail = 'Response Was Not JSON: Sending data to CiviCRM failed with a return payload that was not valid JSON. Verify the GET or POST parameters contain a \'json\' key.';
        try {
            $data = json_decode((string) wp_remote_retrieve_body($response), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return self::failure('not_json', $notJson, 'Post Webhook: $response[body] is not JSON', $notJsonEmail, $response);
        }
        // JSON, but not an object (0, null, "ok"): not an answer from CiviCRM's API either.
        if (!is_array($data)) {
            return self::failure('not_json', $notJson, 'Post Webhook: $response[body] is not a JSON object', $notJsonEmail, $response, $data);
        }
        if (!empty($data['is_error']) || array_key_exists('error_code', $data)) {
            $civi = $data['error_message'] ?? $data['error_code'] ?? '';
            return self::failure('api_error', sprintf(__('CiviCRM reported an error: %s', 'gravityforms-civicrm-form-processor'), is_scalar($civi) ? (string) $civi : wp_json_encode($civi)),
                'Post Webhook Failed: $responseData[is_error] || array_key_exists(error_code, $responseData)',
                'CiviCRM API Returned an Error: The CiviCRM API returned an error message. Verify all required field are submitted and contain valid data.', $response, $data);
        }
        return null;
    }

    /** A failed answer, as check_civicrm_response() returns it. */
    private static function failure(string $kind, string $message, string $header, string $email, array $response, mixed $data = null): WP_Error
    {
        return new WP_Error(self::FAILURE . $kind, $message, ['response' => $response, 'response_data' => $data, 'header' => $header, 'email' => $email]);
    }

    /**
     * After a webhook's request (gform_webhooks_post_request): when it failed, log it and send the failure email, if
     * the webhook has one. A failure arrives as a WP_Error: from WordPress when the request got no answer, or from
     * check_civicrm_response() when CiviCRM's answer was a failure. Nothing is thrown (see check_civicrm_response()).
     */
    public static function after_webhook_actions($response, $feed, $entry, $form): void
    {
        if (!is_wp_error($response)) {
            return;
        }
        $data = $response->get_error_data();
        if (str_starts_with((string) $response->get_error_code(), self::FAILURE) && is_array($data)) {
            static::log_variables($data['header'], $data['response'], $feed, $entry, $form, $data['response_data']);
            static::send_failed_webhook_email($data['header'], $data['email'], $data['response'], $feed, $entry, $form, $data['response_data']);
            return;
        }
        // No answer at all: DNS, a refused connection, a timeout.
        $msg = 'Post Webhook Failed: is_wp_error( $response )';
        static::log_variables($msg, $response, $feed, $entry, $form);
        static::send_failed_webhook_email($msg, 'Network Error: Sending data to CiviCRM failed with a possible network error.', $response, $feed, $entry, $form);
    }

    protected static function log_variables($msg, ...$vars): void
    {

        Log::debug($msg);

        foreach ($vars as $var) {
            Log::debug(print_r($var, true));
        }
    }

    protected static function send_failed_webhook_email($header, $errorMsg, $response, $feed, $entry, $form, $responseData = null): void
    {
        $notification = rgars($feed, 'meta/CiviCRMWebhookFailureNotification', false);
        if (!$notification) {
            return;
        }
        
        $formId = $form['id'];
        $entryId = $entry['id'];
        $entryUrl = get_bloginfo('wpurl') . '/wp-admin/admin.php?page=gf_entries&view=entry&id=' . $formId . '&lid=' . $entryId;

        $toRaw = rgars($feed, 'meta/CiviCRMWebhookFailureNotificationTo');
        $fromNameRaw = rgars($feed, 'meta/CiviCRMWebhookFailureNotificationFromName');
        $fromRaw = rgars($feed, 'meta/CiviCRMWebhookFailureNotificationFrom');
        $bccRaw = rgars($feed, 'meta/CiviCRMWebhookFailureNotificationBCC');
        $subjectRaw = rgars($feed, 'meta/CiviCRMWebhookFailureNotificationSubject');

        $to = GFCommon::replace_variables($toRaw, $form, $entry, false, false, false, 'text');
        $fromName = GFCommon::replace_variables($fromNameRaw, $form, $entry, false, false, false, 'text');
        $from = GFCommon::replace_variables($fromRaw, $form, $entry, false, false, false, 'text');
        $bcc = GFCommon::replace_variables($bccRaw, $form, $entry, false, false, false, 'text');
        $subject = GFCommon::replace_variables($subjectRaw, $form, $entry, false, false, false, 'text');

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
        Log::debug($emailInfoBefore);

        $debugInfo = static::get_notification_debug_html($response, $feed, $entry, $form, $responseData);
        $message = <<<MSG
        <p><strong>{$header}</strong></p>
        <p>{$errorMsg}</p>
        <p>The data is still accessible at entry <a href="{$entryUrl}" target="_blank">#{$entryId}</a> and in notification emails.</p>
        $debugInfo
        MSG;

        GFCommon::send_email($from, $to, $bcc, '', $subject, $message, $fromName, 'html', '', $entry, [
            'id' => uniqid(),
            'name' => 'CiviCRM Webhook Failure Notification',
        ]);
    }

    protected static function get_notification_debug_html($response, $feed, $entry, $form, $responseData = null): string
    {

        $responseDump = static::dump_and_encode($response);
        $feedDump = static::dump_and_encode($feed);
        $entryDump = static::dump_and_encode($entry);
        $formDump = static::dump_and_encode($form);

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

        if ($responseData !== null) {
            $responseDataDump = static::dump_and_encode($responseData);
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

    protected static function dump_and_encode($rawText): string
    {
        return htmlspecialchars(print_r($rawText, true));
    }
}
