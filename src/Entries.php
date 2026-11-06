<?php

namespace BlackBrickSoftware\GravityFormsCiviCRMFormProcessor;

use GFAPI;
use GFFeedAddOn;

abstract class Entries
{

    /** After each webhook is processed: delete the entry, if the form asks and all its active webhooks succeeded. */
    public static function register(): void
    {
        add_action('gform_post_process_feed', [self::class, 'maybe_delete_entry'], 10, 4);
    }
    public static function maybe_delete_entry(
        array $feed,
        array $entry,
        array $form,
        GFFeedAddOn $addon,
    ): void {

        Log::debug('CiviCRM Form Processor Entry Deletion Starting');

        $enabled = $form['CiviCRMDeleteEntries'] ?? false;
        Log::debug('CiviCRM Form Processor Entry Deletion Active? ' . (int)$enabled);
        if (!$enabled) {
            return;
        }

        $entryId = $entry['id'] ?? null;
        $formId = $form['id'] ?? null;
        if ($entryId === null || $formId === null) {
            return;
        }

        Log::debug("CiviCRM Form Processor Entry Deletion Ids (Form: $formId, Entry: $entryId)");

        $feeds = GFAPI::get_feeds(null, $formId, 'gravityformswebhooks');

        // No feeds probably, let's not continue
        if (is_wp_error($feeds) || count($feeds) === 0) {
            return;
        }

        $feedIds = array_map(fn($feed) => $feed['id'], $feeds);
        Log::debug("CiviCRM Form Processor Entry Deletion Feeds (Feed Ids: " . implode(', ', $feedIds). ")");

        $success = true;
        $activeFeedCount = 0;
        foreach ($feeds as $feed) {

            // Skip inactive feeds: they never run, so they have no status and must not block deletion
            if (!rgar($feed, 'is_active')) {
                continue;
            }
            $activeFeedCount++;

            $feedId = $feed['id'] ?? null;
            Log::debug("CiviCRM Form Processor Entry Deletion Checking Feed (Feed Id: $feedId)");

            // This should not be a thing
            if ($feedId === null) {
                $success = false;
                break;
            }

            // Query the field statuses
            $metaKey = "feed_{$feedId}_status";
            $feedStatuses = gform_get_meta($entryId, $metaKey);
            Log::debug("CiviCRM Form Processor Entry Deletion Feed Status: " . print_r($feedStatuses, true));
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

        // Only delete when at least one active feed ran and every active feed succeeded
        if ($success && $activeFeedCount > 0) {
            Log::debug("CiviCRM Form Processor Entry Deletion Deleting Entry (Entry Id: $entryId)");
            GFAPI::delete_entry($entryId);
        }
    }
}