<?php

namespace BlackBrickSoftware\GravityFormsCiviCRMFormProcessor;

use GFAPI;
use GFFeedAddOn;
use GFLogging;
use KLogger;

abstract class Entries
{
    public static function maybe_delete_entry(
        array $feed,
        array $entry,
        array $form,
        GFFeedAddOn $addon,
    ): void {

        GFLogging::log_message('gravityformswebhooks', 'CiviCRM Form Processor Entry Deletion Starting', KLogger::DEBUG);

        $enabled = $form['CiviCRMDeleteEntries'] ?? false;
        GFLogging::log_message('gravityformswebhooks', 'CiviCRM Form Processor Entry Deletion Active? ' . (int)$enabled, KLogger::DEBUG);
        if (!$enabled) {
            return;
        }

        $entryId = $entry['id'] ?? null;
        $formId = $form['id'] ?? null;
        if ($entryId === null || $formId === null) {
            return;
        }

        GFLogging::log_message('gravityformswebhooks', "CiviCRM Form Processor Entry Deletion Ids (Form: $formId, Entry: $entryId)", KLogger::DEBUG);

        $feeds = GFAPI::get_feeds(null, $formId, 'gravityformswebhooks');

        // No feeds probably, let's not continue
        if (is_wp_error($feeds) || count($feeds) === 0) {
            return;
        }

        $feedIds = array_map(fn($feed) => $feed['id'], $feeds);
        GFLogging::log_message('gravityformswebhooks', "CiviCRM Form Processor Entry Deletion Feeds (Feed Ids: " . implode(', ', $feedIds). ")", KLogger::DEBUG);

        $success = true;
        foreach ($feeds as $feed) {
            
            $feedId = $feed['id'] ?? null;
            GFLogging::log_message('gravityformswebhooks', "CiviCRM Form Processor Entry Deletion Checking Feed (Feed Id: $feedId)", KLogger::DEBUG);

            // This should not be a thing
            if ($feedId === null) {
                $success = false;
                break;
            }

            // Query the field statuses
            $metaKey = "feed_{$feedId}_status";
            $feedStatuses = gform_get_meta($entryId, $metaKey);
            GFLogging::log_message('gravityformswebhooks', "CiviCRM Form Processor Entry Deletion Feed Status: " . print_r($feedStatuses, true), KLogger::DEBUG);
            if (empty($feedStatuses) || !is_array($feedStatuses)) {
                $success = false;
                break;
            }

            // Latest is the last element
            $feedStatus = end($feedStatuses);

            // Check for success
            $status = $feedStatus['status'] ?? null;
            if ($status !== 'success') {
                $success = false;
                break;
            }
        }

        if ($success) {
            GFLogging::log_message('gravityformswebhooks', "CiviCRM Form Processor Entry Deletion Deleting Entry (Entry Id: $entryId)", KLogger::DEBUG);
            GFAPI::delete_entry($entryId);
        }
    }
}