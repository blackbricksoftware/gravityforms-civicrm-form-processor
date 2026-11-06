<?php

namespace BlackBrickSoftware\GravityFormsCiviCRMFormProcessor;

use GFLogging;
use KLogger;

/**
 * Debug messages to Gravity Forms' log for the Webhooks add-on (Forms > Settings > Logging).
 *
 * Gravity Forms only loads its KLogger class once something has asked for a logger, and its own feed processing
 * always does before this plugin's hooks run. Not every path does: a webhook sent by WebhookResend, or by another
 * plugin calling the Webhooks add-on directly, reaches this plugin's filters first, where KLogger::DEBUG was a fatal
 * error. So the logger is loaded here, as Gravity Forms' add-ons do (GFAddOn::log_debug()).
 */
abstract class Log
{

    public static function debug(string $message): void
    {
        if (!class_exists('GFLogging')) {
            return;
        }
        GFLogging::include_logger();
        GFLogging::log_message('gravityformswebhooks', $message, KLogger::DEBUG);
    }
}
