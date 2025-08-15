<?php

namespace BlackBrickSoftware\GravityFormsCiviCRMFormProcessor;

use GFCommon;

class Loader
{

    public static function include_gravity_forms_classes()
    {
        // Required for GF_Entry_List_Table::__construct
        if (!class_exists('GFEntryLocking')) {
            require_once GFCommon::get_base_path() . '/includes/locking/locking.php';
        }
        // Used directly
        if (!class_exists('GF_Entry_List_Table')) {
            require_once GFCommon::get_base_path() . '/entry_list.php';
        }
        // Used directly
        if (!class_exists('GFNotification')) {
            require_once GFCommon::get_base_path() . '/notification.php';
        }
    }
}