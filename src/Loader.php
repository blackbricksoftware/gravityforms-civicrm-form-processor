<?php

namespace BlackBrickSoftware\GravityFormsCiviCRMFormProcessor;

use GFCommon;

abstract class Loader
{

    public static function include_gravity_forms_classes()
    {
        // Used directly
        if (!class_exists('GFNotification')) {
            require_once GFCommon::get_base_path() . '/notification.php';
        }
    }
}