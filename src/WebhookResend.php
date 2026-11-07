<?php

namespace BlackBrickSoftware\GravityFormsCiviCRMFormProcessor;

use Exception;
use GF_Webhooks;
use GFAPI;
use GFCommon;
use Throwable;
use WP_Error;

/**
 * Sending webhooks again: from an entry's page, or for many entries from the Entries list.
 *
 * Gravity Forms sends a webhook once, when the entry is submitted (retried in the background as many times as the
 * form's or webhook's "attempts" setting allows), and has no way to send it again by hand. So if CiviCRM was down, or
 * the webhook was turned off when the entry came in, the data never arrives. Two ways to send it again:
 *
 * - The "Webhooks" box on an entry's page lists the form's active webhooks, each with how it last went for this entry.
 *   Tick one or more and press Send: they go out straight away, one after another, and the box shows each result.
 * - "Send webhooks again", a bulk action on the Entries list, for the selected entries (CiviCRM was down for an
 *   afternoon, say). It queues each entry's webhooks exactly as a submission does (GFAPI::maybe_process_feeds()): its
 *   active webhooks whose conditions match it, sent in the background by Gravity Forms with the usual retries. Sending
 *   many entries' webhooks inline would wait out CiviCRM once per webhook in a single request, and time out.
 *
 * About the box: it sends each webhook the way Gravity Forms' background processor does (GF_Feed_Processor::task()),
 * so everything that applies to a webhook applies here too: the form filter gform_form_pre_process_async_task, this
 * plugin's CiviCRM formatting, timeout and failure email, the entry's feed status, the "Delete entries after Webhooks
 * are completed successfully" setting, and the Webhooks add-on's own entry note with the response code. The
 * differences: it runs now, in the request, rather than queued; it sends even when the webhook was already sent, or
 * its condition doesn't match the entry (the admin chose it); it makes one attempt; and it adds an entry note saying
 * who sent it and how it went.
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

    /**
     * The box's id on the entry page. Gravity Forms gives it to the frame it puts around the box (the title bar with its
     * arrows, and the box's content), so what is inside the box uses ids of its own: BOX_SEND, and BOX . '_<feed id>'.
     */
    public const BOX = 'gravityforms_civicrm_form_processor_webhooks';

    /** The id of the box's content: its checkboxes, Send button and results. */
    public const BOX_SEND = 'gravityforms_civicrm_form_processor_webhooks_send';

    /** The Entries list's bulk action. */
    public const BULK_ACTION = 'gravityforms_civicrm_form_processor_resend_webhooks';

    /** Adds the box to entry pages, the request that sends webhooks from it, and the Entries list's bulk action. */
    public static function register(): void
    {
        add_filter('gform_entry_detail_meta_boxes', [self::class, 'addMetaBox'], 10, 3);
        add_action('wp_ajax_' . self::ACTION, [self::class, 'ajax']);
        add_filter('gform_entry_list_bulk_actions', [self::class, 'addBulkAction'], 10, 2);
        add_action('gform_entry_list_action', [self::class, 'bulkAction'], 10, 3);
        add_action('admin_print_footer_scripts', [self::class, 'confirmBulkAction']);
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

    /** The box: the form's active webhooks, each with how it last went for this entry, to tick, and a Send button. */
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
        <div id="<?php echo esc_attr(self::BOX_SEND); ?>">
            <p class="description"><?php esc_html_e('Send webhooks again for this entry, now. The results are shown here and added to the entry\'s notes.', 'gravityforms-civicrm-form-processor'); ?></p>
            <?php foreach ($feeds as $feed) : ?>
                <?php
                $feedId = (int) $feed['id'];
                $name = GFAPI::get_feed_name($feed) ?: sprintf(__('Webhook #%d', 'gravityforms-civicrm-form-processor'), $feedId);
                $note = self::statusLabel((int) $entry['id'], $feedId);
                if (!$addon->is_feed_condition_met($feed, $form, $entry)) {
                    $note .= ', ' . __('condition not met', 'gravityforms-civicrm-form-processor');
                }
                ?>
                <p style="margin:0 0 8px;">
                    <input type="checkbox" value="<?php echo $feedId; ?>" id="<?php echo $id . '_' . $feedId; ?>" />
                    <label for="<?php echo $id . '_' . $feedId; ?>"><?php echo esc_html($name); ?> <span class="description" data-status="<?php echo $feedId; ?>">(<?php echo esc_html($note); ?>)</span></label>
                </p>
            <?php endforeach; ?>
            <p>
                <button type="button" class="button"><?php esc_html_e('Send', 'gravityforms-civicrm-form-processor'); ?></button>
                <span class="spinner" style="float:none;"></span>
            </p>
            <div role="status"></div>
        </div>
        <script>
        (function () {
            var box = document.getElementById(<?php echo wp_json_encode(self::BOX_SEND); ?>);
            var button = box.querySelector('button'), spinner = box.querySelector('.spinner'), result = box.querySelector('[role="status"]');
            function show(ok, lines) {
                result.className = 'notice inline ' + (ok ? 'notice-success' : 'notice-error');
                result.innerHTML = '';
                lines.forEach(function (text) { var p = document.createElement('p'); p.textContent = text; result.appendChild(p); });
            }
            button.addEventListener('click', function () {
                var ticked = Array.prototype.slice.call(box.querySelectorAll('input[type="checkbox"]:checked'));
                if (!ticked.length) { show(false, [<?php echo wp_json_encode(__('Tick at least one webhook to send.', 'gravityforms-civicrm-form-processor')); ?>]); return; }
                var lines = [], ok = true;
                button.disabled = true;
                spinner.classList.add('is-active');
                result.innerHTML = '';
                // One request per webhook, one after another, so each waits for at most one answer from CiviCRM.
                var chain = Promise.resolve(false);
                ticked.forEach(function (input) {
                    chain = chain.then(function (stop) {
                        if (stop) { return true; }
                        var data = new FormData();
                        data.append('action', <?php echo wp_json_encode(self::ACTION); ?>);
                        data.append('nonce', <?php echo wp_json_encode(wp_create_nonce(self::ACTION)); ?>);
                        data.append('entry_id', <?php echo wp_json_encode((string) (int) $entry['id']); ?>);
                        data.append('feed_ids[]', input.value);
                        return fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
                            .then(function (r) { return r.json(); })
                            .then(function (json) {
                                var d = json && json.data ? json.data : {}, r = (d.results || [])[0] || { message: d.message || 'No answer.', ok: false };
                                var status = box.querySelector('[data-status="' + input.value + '"]');
                                if (status && r.status) { status.textContent = '(' + r.status + ')'; }
                                lines.push(r.message);
                                ok = ok && !!(json && json.success);
                                show(ok, lines);
                                return !!r.deleted;
                            })
                            .catch(function () {
                                ok = false;
                                lines.push(<?php echo wp_json_encode(__('The request failed. Reload the page and check the entry\'s notes.', 'gravityforms-civicrm-form-processor')); ?>);
                                show(false, lines);
                                return false;
                            });
                    });
                });
                chain.finally(function () { button.disabled = false; spinner.classList.remove('is-active'); });
            });
        })();
        </script>
        <?php
    }

    /**
     * The admin-ajax request from the box's Send button: sends each ticked webhook, one after another. Answers JSON:
     * success (true when every one was sent), and data.results, one {feed_id, ok, message, status} per webhook.
     */
    public static function ajax(): void
    {
        check_ajax_referer(self::ACTION, 'nonce');
        if (!class_exists('GFCommon') || !GFCommon::current_user_can_any('gravityforms_edit_entries')) {
            wp_send_json_error(['message' => __('You are not allowed to send webhooks for entries.', 'gravityforms-civicrm-form-processor')], 403);
        }
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
        $entryId = absint($_POST['entry_id'] ?? 0);
        $feedIds = array_values(array_unique(array_filter(array_map('absint', (array) ($_POST['feed_ids'] ?? [])))));
        // phpcs:enable
        if (!$feedIds) {
            wp_send_json_error(['message' => __('Tick at least one webhook to send.', 'gravityforms-civicrm-form-processor')]);
        }
        $results = [];
        foreach ($feedIds as $feedId) {
            $result = self::send($entryId, $feedId);
            $results[] = ['feed_id' => $feedId] + $result;
            // "Delete entries after Webhooks are completed successfully" may have deleted the entry: nothing to send now.
            if (!empty($result['deleted'])) {
                break;
            }
        }
        $ok = !in_array(false, array_column($results, 'ok'), true);
        $ok ? wp_send_json_success(['results' => $results]) : wp_send_json_error(['results' => $results]);
    }

    /** The gform_entry_list_bulk_actions filter: "Send webhooks again", for users who can edit entries. */
    public static function addBulkAction(mixed $actions, mixed $formId = null): mixed
    {
        if (!is_array($actions) || !class_exists('GF_Webhooks') || !GFCommon::current_user_can_any('gravityforms_edit_entries')) {
            return $actions;
        }
        $actions[self::BULK_ACTION] = esc_html__('Send webhooks again', 'gravityforms-civicrm-form-processor');
        return $actions;
    }

    /**
     * The gform_entry_list_action action, which Gravity Forms runs after checking the list's nonce: queues the selected
     * entries' webhooks as a submission does, for its background processor to send. Prints the list's usual notice.
     */
    public static function bulkAction(mixed $action, mixed $entryIds = [], mixed $formId = 0): void
    {
        if ($action !== self::BULK_ACTION) {
            return;
        }
        $notice = static function (string $class, string $message): void {
            echo '<div id="message" class="alert ' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
        };
        if (!class_exists('GF_Webhooks') || !GFCommon::current_user_can_any('gravityforms_edit_entries')) {
            $notice('error', __('You are not allowed to send webhooks for entries.', 'gravityforms-civicrm-form-processor'));
            return;
        }
        $form = GFAPI::get_form((int) $formId);
        if (!is_array($form)) {
            $notice('error', sprintf(__('Form #%d was not found.', 'gravityforms-civicrm-form-processor'), (int) $formId));
            return;
        }
        if (!self::activeFeeds((int) $form['id'])) {
            $notice('error', __('This form has no active webhooks to send.', 'gravityforms-civicrm-form-processor'));
            return;
        }
        $user = wp_get_current_user();
        $who = $user->exists() ? $user->display_name : __('an administrator', 'gravityforms-civicrm-form-processor');
        $queued = 0;
        $skipped = 0;
        foreach ((array) $entryIds as $entryId) {
            $entry = GFAPI::get_entry(absint($entryId));
            if (is_wp_error($entry) || (int) $entry['form_id'] !== (int) $form['id']) {
                continue;
            }
            // As for one entry: spam and trashed entries are left alone.
            if (in_array($entry['status'] ?? 'active', ['spam', 'trash'], true)) {
                $skipped++;
                continue;
            }
            // As on submission: the entry's active webhooks whose conditions match it, queued for the background
            // processor. Clearing the "already processed" record first is what lets them run again.
            GFAPI::maybe_process_feeds($entry, $form, self::SLUG, true);
            GFAPI::add_note((int) $entry['id'], (int) $user->ID, $who, sprintf(__('Webhooks queued to be sent again by %s.', 'gravityforms-civicrm-form-processor'), $who), self::SLUG, 'success');
            $queued++;
        }
        $notice('success', sprintf(
            _n(
                'The webhooks of %d entry are queued to be sent again: its active webhooks whose conditions match it. Gravity Forms sends them in the background, with the usual retries; the entry\'s notes show how they went.',
                'The webhooks of %d entries are queued to be sent again: each one\'s active webhooks whose conditions match it. Gravity Forms sends them in the background, with the usual retries; each entry\'s notes show how they went.',
                $queued,
                'gravityforms-civicrm-form-processor'
            ),
            $queued
        ) . ($skipped ? ' ' . sprintf(_n('%d entry in spam or the trash was left alone.', '%d entries in spam or the trash were left alone.', $skipped, 'gravityforms-civicrm-form-processor'), $skipped) : ''));
    }

    /** On the Entries list: confirm before "Send webhooks again" is applied, since it sends data to CiviCRM. */
    public static function confirmBulkAction(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which page this is, nothing more.
        if (($_GET['page'] ?? '') !== 'gf_entries' || !empty($_GET['view']) || !class_exists('GF_Webhooks')) {
            return;
        }
        ?>
        <script>
        (function () {
            document.querySelectorAll('#doaction, #doaction2').forEach(function (button) {
                button.addEventListener('click', function (event) {
                    var select = button.parentNode.querySelector('select');
                    if (select && select.value === <?php echo wp_json_encode(self::BULK_ACTION); ?> && !window.confirm(<?php echo wp_json_encode(__('Send the selected entries\' webhooks again? Each entry\'s active webhooks whose conditions match it are queued, and sent to CiviCRM in the background.', 'gravityforms-civicrm-form-processor')); ?>)) {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                    }
                }, true);
            });
        })();
        </script>
        <?php
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
        // Gravity Forms never sends a spam entry's webhooks (it sends them when the entry is marked not spam), and a
        // trashed entry is on its way out.
        if (in_array($entry['status'] ?? 'active', ['spam', 'trash'], true)) {
            return self::fail($entry['status'] === 'spam'
                ? __('This entry is marked as spam. Mark it as not spam first, which also sends its webhooks.', 'gravityforms-civicrm-form-processor')
                : __('This entry is in the trash. Restore it first.', 'gravityforms-civicrm-form-processor'));
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
        // As the background processor does before sending (GF_Background_Process::filter_form()): sites and plugins
        // use this filter to adjust the form for feeds that run after the submission's own request.
        $form = gf_apply_filters(['gform_form_pre_process_async_task', $formId], $form, $entry);

        $addon = GF_Webhooks::get_instance();
        $name = GFAPI::get_feed_name($feed) ?: sprintf(__('Webhook #%d', 'gravityforms-civicrm-form-processor'), $feedId);
        // A payment add-on (PayPal, say) holds an entry's webhooks back until it is paid, then sends them all. Sending
        // one now would also mark the entry's webhooks done, and the rest would never go.
        if ($addon->maybe_delay_feed($entry, $form)) {
            return self::fail(sprintf(__('"%s" waits for this entry\'s payment: its webhooks are sent when the payment is received.', 'gravityforms-civicrm-form-processor'), $name));
        }
        // The filters a submission's webhooks go through (gform_addon_pre_process_feeds and the like): a site may
        // change a webhook there, or leave it out for some entries.
        $filtered = array_values(array_filter((array) $addon->pre_process_feeds([$feed], $entry, $form), static fn($f) => is_array($f) && (int) ($f['id'] ?? 0) === $feedId));
        if (!$filtered) {
            return self::fail(sprintf(__('"%s" was not sent: a filter on this site (gform_addon_pre_process_feeds or similar) leaves it out for this entry.', 'gravityforms-civicrm-form-processor'), $name));
        }
        $feed = $filtered[0];
        $user = wp_get_current_user();
        $who = $user->exists() ? $user->display_name : __('an administrator', 'gravityforms-civicrm-form-processor');

        $addon->log_debug(sprintf('%s(): Sending feed (#%d - %s) again for entry #%d, by hand, for %s.', __METHOD__, $feedId, $name, $entryId, $who));
        // As the entry's own user, as when it was submitted, so {user:...} merge tags in the webhook are the
        // submitter's, not the admin's who pressed Send.
        wp_set_current_user((int) ($entry['created_by'] ?? 0));
        try {
            $result = $addon->process_feed($feed, $entry, $form);
        } catch (Throwable $e) {
            $result = $e instanceof Exception ? $e : new Exception($e->getMessage(), (int) $e->getCode());
        } finally {
            wp_set_current_user($user->ID);
        }
        $addon->save_entry_feed_status($result, $entryId, $feedId, $formId);

        if ($result instanceof Exception || is_wp_error($result)) {
            $why = self::describeFailure($result);
            $addon->log_error(sprintf('%s(): Feed (#%d - %s) sent again for entry #%d failed: %s', __METHOD__, $feedId, $name, $entryId, $why));
            $note = sprintf(__('Webhook "%1$s" sent again by %2$s, and it failed: %3$s', 'gravityforms-civicrm-form-processor'), $name, $who, $why);
            GFAPI::add_note($entryId, (int) $user->ID, $who, $note, self::SLUG, 'error');
            return self::fail(sprintf(__('"%1$s" failed: %2$s', 'gravityforms-civicrm-form-processor'), $name, $why)) + ['status' => self::statusLabel($entryId, $feedId)];
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
        if (!in_array($feedId, (array) GFAPI::get_processed_feeds_meta($entryId, self::SLUG), true)) {
            GFAPI::update_processed_feeds_meta($entryId, self::SLUG, $feedId, $formId);
        }

        return ['ok' => true, 'message' => sprintf(__('"%s" was sent.', 'gravityforms-civicrm-form-processor'), $name), 'status' => self::statusLabel($entryId, $feedId)];
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

    /**
     * What went wrong, in a sentence. CiviCRM's answer is checked in Webhooks::check_civicrm_response(), whose WP_Error
     * says what was wrong with it; any other WP_Error means the request got no answer.
     */
    private static function describeFailure(Exception|WP_Error $error): string
    {
        if ($error instanceof Exception) {
            return $error->getMessage();
        }
        if (str_starts_with((string) $error->get_error_code(), Webhooks::FAILURE)) {
            return $error->get_error_message();
        }
        return sprintf(__('The request did not get an answer (%s).', 'gravityforms-civicrm-form-processor'), $error->get_error_message());
    }

    /** @return array{ok: false, message: string} */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
