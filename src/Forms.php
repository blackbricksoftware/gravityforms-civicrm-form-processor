<?php

namespace BlackBrickSoftware\GravityFormsCiviCRMFormProcessor;

abstract class Forms
{
    public static function register_webhook_settings(array $fields, array $form): array
    {
        $fields['civicrm_webhook_fields'] = [
            'title'  => esc_html__('Additional Webhooks settings', 'gravityforms-civicrm-form-processor'),
            'description' => esc_html__('These settings are provided by the Gravity Forms CiviCRM Form Processor.', 'gravityforms-civicrm-form-processor'),
            'fields' => [
                // Delete Entries after successful submission (TBD)
                [
                    'name'           => 'CiviCRMDeleteEntries',
                    'type'           => 'toggle',
                    'label'          => esc_html__('Delete entries after Webhook are completed successfully', 'gravityforms-civicrm-form-processor'),
                    'default_value'  => false,
                     'tooltip'        => sprintf(
                         '<h6>%s</h6>%s',
                         esc_html__('Delete Entries', 'gravityforms-civicrm-form-processor'),
                         esc_html__('When entry deletion is enabled, entries will be deleted after all Webhooks complete successfully.', 'gravityforms-civicrm-form-processor')
                     ),
                ],
                [
                    'name'           => 'CiviCRMWebhookMaxAttempts',
                    'type'           => 'text',
                    'label'          => esc_html__('Number of times to try submitting a Webhook', 'gravityforms-civicrm-form-processor'),
                    'default_value'  => 1,
                     'tooltip'        => sprintf(
                         '<h6>%s</h6>%s',
                         esc_html__('Webhook Max Attempts', 'gravityforms-civicrm-form-processor'),
                         esc_html__('This setting will set the number of times to attempt a Webhook. Default to 1.', 'gravityforms-civicrm-form-processor')
                     ),
                     // TODO: validation
                ],
            ],
        ];

        return $fields;
    }

    public static function set_webhook_retry_attempts(
        int $max_attempts,
        array $form,
        array $entry,
        string $addon_slug,
        array $feed,
    ): int {

        if ($addon_slug !== 'gravityformswebhooks') {
            return $max_attempts;
        }

        $max_attempts = (int)($form['CiviCRMWebhookMaxAttempts'] ?? $max_attempts);
        if ($max_attempts < 1) {
            $max_attempts = 1;
        }

        return $max_attempts;
    }
}
