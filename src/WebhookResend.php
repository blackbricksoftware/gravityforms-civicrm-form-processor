<?php

namespace BlackBrickSoftware\GravityFormsCiviCRMFormProcessor;

use Exception;
use GF_Webhooks;
use GFAPI;
use GFCommon;
use Throwable;
use WP_Error;

/**
 * "Webhooks" on an entry's page: send any of the form's active webhooks again for that entry.
 *
 * Gravity Forms sends a webhook once, when the entry is submitted (retried in the background as many times as the
 * form's or webhook's "attempts" setting allows), and has no way to send it again by hand. So if CiviCRM was down, or
 * the webhook was turned off when the entry came in, the data never arrives. This box lists the form's active webhooks
 * with how each last went for this entry, and sends the chosen one straight away, showing the result.
 *
 * It sends the webhook the way Gravity Forms' background processor does (GF_Feed_Processor::task()), so everything
 * that applies to a webhook applies here too: this plugin's CiviCRM formatting, timeout and failure email, the entry's
 * feed status, the "Delete entries after Webhooks are completed successfully" setting, and the Webhooks add-on's own
 * entry note with the response code. The differences: it runs now, in the request, rather than queued; it sends even
 * when the webhook was already sent, or its condition doesn't match the entry (the admin chose it); it makes one
 * attempt; and it adds an entry note saying who sent it and how it went.
 *
 * For users who can edit entries (gravityforms_edit_entries), over admin-ajax with a nonce, as Gravity Forms' own
 * "Resend Notifications" box does: every box on the entry page sits inside the entry's own form, so it can't hold one.
 */
abstract class WebhookResend
{

    /** The Webhooks add-on's slug. */
    public const SLUG = 'gravityformswebhooks';

    /** The admin-ajax action, and the nonce's action. */
    public const ACTION = 'gravityforms_civicrm_form_processor_resend_webhook';

    /** The box's id on the entry page. */
    public const BOX = 'gravityforms_civicrm_form_processor_webhooks';

    /** Adds the box to entry pages, and the request that sends a webhook. */
    public static function register(): void
    {
        add_filter('gform_entry_detail_meta_boxes', [self::class, 'addMetaBox'], 10, 3);
        add_action('wp_ajax_' . self::ACTION, [self::class, 'ajax']);
    }

    /** The gform_entry_detail_meta_boxes filter. Only when the Webhooks add-on is active, and for users who can use it. */
    public static function addMetaBox(mixed $metaBoxes, mixed $entry = null, mixed $form = null): mixed
    {
        if (!is_array($metaBoxes) || !class_exists('GF_Webhooks') || !GFCommon::current_user_can_any('gravityforms_edit_entries')) {
            return $metaBoxes;
        }
        $metaBoxes[self::BOX] = [
            'title' => esc_html__('Webhooks', 'gravityforms-civicrm-form-processor'),
            'callback' => [self::class, 'renderMetaBox'],
            'context' => 'side',
        ];
        return $metaBoxes;
    }

    /** The box: the form's active webhooks, each with how it last went for this entry, and a Send button. */
    public static function renderMetaBox(mixed $args, mixed $metabox = null): void
    {
        $form = is_array($args) ? ($args['form'] ?? null) : null;
        $entry = is_array($args) ? ($args['entry'] ?? null) : null;
        if (!is_array($form) || !is_array($entry) || empty($entry['id'])) {
            return;
        }
        $feeds = self::activeFeeds((int) $form['id']);
        if (!$feeds) {
            echo '<p class="description">' . esc_html__('This form has no active webhooks to send.', 'gravityforms-civicrm-form-processor') . '</p>';
            echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=gf_edit_forms&view=settings&subview=' . self::SLUG . '&id=' . (int) $form['id'])) . '">' . esc_html__('Configure Webhooks', 'gravityforms-civicrm-form-processor') . '</a>';
            return;
        }
        $addon = GF_Webhooks::get_instance();
        $id = esc_attr(self::BOX);
        ?>
        <div id="<?php echo $id; ?>">
            <p class="description"><?php esc_html_e('Send a webhook again for this entry, now. The result is shown here and added to the entry\'s notes.', 'gravityforms-civicrm-form-processor'); ?></p>
            <p>
                <label class="screen-reader-text" for="<?php echo $id; ?>_feed"><?php esc_html_e('Webhook', 'gravityforms-civicrm-form-processor'); ?></label>
                <select id="<?php echo $id; ?>_feed" style="width:100%; max-width:100%;">
                    <?php foreach ($feeds as $feed) : ?>
                        <?php
                        $label = GFAPI::get_feed_name($feed) ?: sprintf(__('Webhook #%d', 'gravityforms-civicrm-form-processor'), (int) $feed['id']);
                        $label .= ' (' . self::statusLabel((int) $entry['id'], (int) $feed['id']) . ')';
                        if (!$addon->is_feed_condition_met($feed, $form, $entry)) {
                            $label .= ' ' . __('[condition not met]', 'gravityforms-civicrm-form-processor');
                        }
                        ?>
                        <option value="<?php echo (int) $feed['id']; ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p>
                <button type="button" class="button" id="<?php echo $id; ?>_send"><?php esc_html_e('Send', 'gravityforms-civicrm-form-processor'); ?></button>
                <span class="spinner" style="float:none;"></span>
            </p>
            <div id="<?php echo $id; ?>_result" role="status"></div>
        </div>
        <script>
        (function () {
            var box = document.getElementById(<?php echo wp_json_encode(self::BOX); ?>);
            var button = box.querySelector('button'), select = box.querySelector('select'), spinner = box.querySelector('.spinner'), result = box.querySelector('[role="status"]');
            button.addEventListener('click', function () {
                var data = new FormData();
                data.append('action', <?php echo wp_json_encode(self::ACTION); ?>);
                data.append('nonce', <?php echo wp_json_encode(wp_create_nonce(self::ACTION)); ?>);
                data.append('entry_id', <?php echo wp_json_encode((string) (int) $entry['id']); ?>);
                data.append('feed_id', select.value);
                button.disabled = true;
                spinner.classList.add('is-active');
                result.innerHTML = '';
                fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (json) {
                        var d = json && json.data ? json.data : {};
                        var p = document.createElement('p');
                        p.textContent = d.message || 'No answer.';
                        result.className = 'notice inline ' + (json && json.success ? 'notice-success' : 'notice-error');
                        result.appendChild(p);
                        if (d.status) { select.options[select.selectedIndex].textContent = d.status; }
                    })
                    .catch(function () {
                        result.className = 'notice inline notice-error';
                        result.textContent = 'The request failed. Reload the page and check the entry\'s notes.';
                    })
                    .finally(function () { button.disabled = false; spinner.classList.remove('is-active'); });
            });
        })();
        </script>
        <?php
    }

    /** The admin-ajax request from the box's Send button. Answers JSON: success, and data.message (and data.status). */
    public static function ajax(): void
    {
        check_ajax_referer(self::ACTION, 'nonce');
        if (!class_exists('GFCommon') || !GFCommon::current_user_can_any('gravityforms_edit_entries')) {
            wp_send_json_error(['message' => __('You are not allowed to send webhooks for entries.', 'gravityforms-civicrm-form-processor')], 403);
        }
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
        $entryId = absint($_POST['entry_id'] ?? 0);
        $feedId = absint($_POST['feed_id'] ?? 0);
        // phpcs:enable
        $result = self::send($entryId, $feedId);
        $result['ok'] ? wp_send_json_success($result) : wp_send_json_error($result);
    }

    /**
     * Sends one webhook for one entry, now, as Gravity Forms' background processor would (GF_Feed_Processor::task()).
     *
     * @return array{ok: bool, message: string, status?: string, deleted?: bool}
     */
    public static function send(int $entryId, int $feedId): array
    {
        if (!class_exists('GF_Webhooks') || !class_exists('GFAPI')) {
            return self::fail(__('The Gravity Forms Webhooks add-on is not active.', 'gravityforms-civicrm-form-processor'));
        }
        $entry = GFAPI::get_entry($entryId);
        if (is_wp_error($entry)) {
            return self::fail(sprintf(__('Entry #%d was not found.', 'gravityforms-civicrm-form-processor'), $entryId));
        }
        $formId = (int) $entry['form_id'];
        $feed = GFAPI::get_feed($feedId);
        if (is_wp_error($feed) || !is_array($feed) || ($feed['addon_slug'] ?? '') !== self::SLUG || (int) ($feed['form_id'] ?? 0) !== $formId) {
            return self::fail(__('That webhook does not belong to this entry\'s form.', 'gravityforms-civicrm-form-processor'));
        }
        if (empty($feed['is_active'])) {
            return self::fail(__('That webhook is turned off. Turn it on in the form\'s Webhooks settings first.', 'gravityforms-civicrm-form-processor'));
        }
        $form = GFAPI::get_form($formId);
        if (!is_array($form)) {
            return self::fail(sprintf(__('Form #%d was not found.', 'gravityforms-civicrm-form-processor'), $formId));
        }

        $addon = GF_Webhooks::get_instance();
        $name = GFAPI::get_feed_name($feed) ?: sprintf(__('Webhook #%d', 'gravityforms-civicrm-form-processor'), $feedId);
        $user = wp_get_current_user();
        $who = $user->exists() ? $user->display_name : __('an administrator', 'gravityforms-civicrm-form-processor');

        // The response, for saying what went wrong: this plugin's failure check (Webhooks::after_webhook_actions())
        // reports it as an exception with a technical message.
        $response = null;
        $capture = static function ($r) use (&$response): void {
            $response = $r;
        };
        add_action('gform_webhooks_post_request', $capture, 5);
        $addon->log_debug(sprintf('%s(): Sending feed (#%d - %s) again for entry #%d, by hand, for %s.', __METHOD__, $feedId, $name, $entryId, $who));
        try {
            $result = $addon->process_feed($feed, $entry, $form);
        } catch (Throwable $e) {
            $result = $e instanceof Exception ? $e : new Exception($e->getMessage(), (int) $e->getCode());
        } finally {
            remove_action('gform_webhooks_post_request', $capture, 5);
        }
        $addon->save_entry_feed_status($result, $entryId, $feedId, $formId);

        if ($result instanceof Exception || is_wp_error($result)) {
            $why = self::describeFailure($result, $response);
            $note = sprintf(__('Webhook "%1$s" sent again by %2$s, and it failed: %3$s', 'gravityforms-civicrm-form-processor'), $name, $who, $why);
            GFAPI::add_note($entryId, (int) $user->ID, $who, $note, self::SLUG, 'error');
            return self::fail(sprintf(__('"%1$s" failed: %2$s', 'gravityforms-civicrm-form-processor'), $name, $why)) + ['status' => self::optionLabel($name, $entryId, $feedId)];
        }

        // As the background processor does: an add-on may return the entry it changed.
        if (is_array($result) && (int) ($result['id'] ?? 0) === $entryId) {
            if ($entry !== $result) {
                GFAPI::update_entry($result);
            }
            $entry = $result;
        }
        $note = sprintf(__('Webhook "%1$s" sent again by %2$s.', 'gravityforms-civicrm-form-processor'), $name, $who);
        GFAPI::add_note($entryId, (int) $user->ID, $who, $note, self::SLUG, 'success');

        // This is where the "Delete entries after Webhooks are completed successfully" setting acts (Entries).
        $addon->post_process_feed($feed, $entry, $form);
        if (is_wp_error(GFAPI::get_entry($entryId))) {
            return [
                'ok' => true,
                'deleted' => true,
                'message' => sprintf(__('"%s" was sent. The entry has now been deleted, as this form\'s "Delete entries after Webhooks are completed successfully" setting asks.', 'gravityforms-civicrm-form-processor'), $name),
            ];
        }
        $addon->fulfill_entry($entryId, $formId);
        GFAPI::update_processed_feeds_meta($entryId, self::SLUG, $feedId, $formId);

        return ['ok' => true, 'message' => sprintf(__('"%s" was sent.', 'gravityforms-civicrm-form-processor'), $name), 'status' => self::optionLabel($name, $entryId, $feedId)];
    }

    /**
     * The form's active webhooks.
     *
     * @return list<array<string, mixed>>
     */
    public static function activeFeeds(int $formId): array
    {
        $feeds = GFAPI::get_feeds(null, $formId, self::SLUG, true);
        return is_array($feeds) ? array_values(array_filter($feeds, static fn($f) => is_array($f) && !empty($f['is_active']))) : [];
    }

    /** How a webhook last went for an entry, from the feed status Gravity Forms keeps: "sent 6 Nov 2026 14:02", etc. */
    public static function statusLabel(int $entryId, int $feedId): string
    {
        $statuses = gform_get_meta($entryId, "feed_{$feedId}_status");
        $last = is_array($statuses) && $statuses ? end($statuses) : null;
        if (!is_array($last) || empty($last['status'])) {
            return __('not sent yet', 'gravityforms-civicrm-form-processor');
        }
        $when = empty($last['timestamp']) ? '' : ' ' . wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $last['timestamp']);
        return ($last['status'] === 'success' ? __('sent', 'gravityforms-civicrm-form-processor') : __('failed', 'gravityforms-civicrm-form-processor')) . $when;
    }

    /** The select option's text after a send. */
    private static function optionLabel(string $name, int $entryId, int $feedId): string
    {
        return $name . ' (' . self::statusLabel($entryId, $feedId) . ')';
    }

    /** What went wrong, in a sentence: the response when there was one, else the error. */
    private static function describeFailure(Exception|WP_Error $error, mixed $response): string
    {
        if (is_wp_error($response)) {
            return sprintf(__('the request did not get an answer (%s).', 'gravityforms-civicrm-form-processor'), $response->get_error_message());
        }
        if (is_array($response) && isset($response['response']['code'])) {
            $code = (int) $response['response']['code'];
            $body = json_decode((string) ($response['body'] ?? ''), true);
            $civi = is_array($body) ? ($body['error_message'] ?? $body['error_code'] ?? null) : null;
            if ($code !== 200) {
                return sprintf(__('the server answered with HTTP %d.', 'gravityforms-civicrm-form-processor'), $code);
            }
            if (!is_array($body)) {
                return __('the answer was not JSON, so CiviCRM did not handle it (check the webhook\'s URL and request format).', 'gravityforms-civicrm-form-processor');
            }
            if (is_string($civi) || is_int($civi)) {
                return sprintf(__('CiviCRM reported an error: %s', 'gravityforms-civicrm-form-processor'), (string) $civi);
            }
        }
        return $error instanceof Exception ? $error->getMessage() : $error->get_error_message();
    }

    /** @return array{ok: false, message: string} */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
