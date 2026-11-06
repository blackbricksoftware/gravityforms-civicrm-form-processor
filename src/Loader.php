<?php

namespace BlackBrickSoftware\GravityFormsCiviCRMFormProcessor;

use GFCommon;

abstract class Loader
{

    /** Includes the Gravity Forms classes this plugin uses directly, once Gravity Forms has loaded (on init). */
    public static function register(): void
    {
        add_action('init', [self::class, 'include_gravity_forms_classes']);
    }

    public static function include_gravity_forms_classes()
    {
        // Used directly
        if (!class_exists('GFNotification')) {
            require_once GFCommon::get_base_path() . '/notification.php';
        }
    }
}