<?php

namespace BlackBrickSoftware\GravityFormsCiviCRMFormProcessor;

class Tooltips
{
    /**
     * Add additional tooltips
     */
    public static function add_gfform_tooltips($gfform_tooltips)
    {
        $additional_tooltips = [
            'civicrm_notification_send_to_email' => '<strong>' . __('Send To Email Address', 'gravityforms-civicrm-form-processor') . '</strong>' . __('Enter the email address you would like the notification email sent to.', 'gravityforms-civicrm-form-processor'),
            'civicrm_notification_from_name'    => '<strong>' . __('From Name', 'gravityforms-civicrm-form-processor') . '</strong>' . __('Enter the name you would like the notification email sent from.', 'gravityforms-civicrm-form-processor'),
            'civicrm_notification_from_email'   => '<strong>' . __('From Email Address', 'gravityforms-civicrm-form-processor') . '</strong>' . __('Enter an authorized email address you would like the notification email sent from. To avoid deliverability issues, always use your site domain in the from email.', 'gravityforms-civicrm-form-processor'),
            'civicrm_notification_bcc'   => '<strong>' . __('Blind Carbon Copy Addresses', 'gravityforms-civicrm-form-processor') . '</strong>' . __('Enter a comma separated list of email addresses you would like to receive a BCC of the notification email.', 'gravityforms-civicrm-form-processor'),
            'civicrm_notification_subject'   => '<strong>' . __('Subject', 'gravityforms-civicrm-form-processor') . '</strong>' . __('Enter a subject for the notification email.', 'gravityforms-civicrm-form-processor'),
        ];

        foreach ($additional_tooltips as $key => $tooltip) {
            if (!array_key_exists($key, $gfform_tooltips)) {
                $gfform_tooltips[$key] = $tooltip;
            }
        }

        return $gfform_tooltips;
    }
}
