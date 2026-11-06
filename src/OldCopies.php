<?php

namespace BlackBrickSoftware\GravityFormsCiviCRMFormProcessor;

/**
 * When this copy of the plugin is activated, deactivates any other copy of it that is active in another folder.
 *
 * Release zips up to 2.0.0 had no folder inside them, so a site that uploaded one has the plugin in a folder named
 * after the zip (gravity_forms_civicrm_form_processor/, or since WordPress 7.1.4 that name plus random characters).
 * Those versions can't update themselves, so moving such a site onto the updater means uploading a newer zip, which
 * WordPress installs beside the old copy rather than over it. Without this, the admin then has to deactivate the old
 * copy by hand. With it: upload, activate, and the old copy is deactivated, with a notice offering to delete it.
 *
 * A copy is "another copy of this plugin" when its main file has this one's name, gravityforms-civicrm-form-processor.php,
 * in a different folder. It is deactivated in the scope this one was activated in: for the site, or network-wide.
 * Nothing is deleted without the admin: the notice links to WordPress's own delete screen. Deleting an old copy loses
 * nothing, since the plugin's settings are stored by Gravity Forms with each form and webhook.
 *
 * Both copies being active for a moment is harmless (the newer one's classes load, as tests/run.sh checks).
 */
abstract class OldCopies
{

    /** Site option listing the copies this one deactivated, for the notice: plugin file => version. */
    public const OPTION = 'gravityforms_civicrm_form_processor_replaced';

    /**
     * The activation hook. Runs in the request that activates this copy, after plugins_loaded, so it can't rely on
     * anything the plugins_loaded callback sets up. WordPress re-reads the active plugins after activation hooks run
     * (activate_plugin()), so deactivating another plugin here sticks.
     */
    public static function deactivateOthers(mixed $networkWide = false): void
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $networkWide = (bool) $networkWide;
        $mine = plugin_basename(GRAVITYFORMS_CIVICRM_FORM_PROCESSOR);
        $replaced = [];
        foreach (get_plugins() as $file => $data) {
            if ($file === $mine || basename($file) !== basename($mine) || dirname($file) === '.') {
                continue;
            }
            $active = $networkWide
                ? is_multisite() && is_plugin_active_for_network($file)
                : in_array($file, (array) get_option('active_plugins', []), true);
            if ($active) {
                deactivate_plugins($file, false, $networkWide);
                $replaced[$file] = is_string($data['Version'] ?? null) ? $data['Version'] : '';
            }
        }
        if ($replaced) {
            update_option(self::OPTION, $replaced + self::replaced(), false);
        }
    }

    /** Shows the notice about deactivated copies, in wp-admin. */
    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'renderNotice']);
        add_action('network_admin_notices', [self::class, 'renderNotice']);
    }

    /**
     * The notice: which copy was deactivated, and a link to delete it. A copy that has since been deleted is dropped
     * from the list, and the option goes with the last one.
     */
    public static function renderNotice(): void
    {
        $replaced = self::replaced();
        if (!$replaced || !current_user_can('activate_plugins')) {
            return;
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $installed = get_plugins();
        $still = array_intersect_key($replaced, $installed);
        if ($still !== $replaced) {
            $still ? update_option(self::OPTION, $still, false) : delete_option(self::OPTION);
        }
        foreach ($still as $file => $version) {
            $folder = dirname($file);
            $message = sprintf(
                /* translators: 1: folder name, 2: version */
                esc_html__('Gravity Forms CiviCRM Form Processor: this copy replaces the older one in wp-content/plugins/%1$s/ (version %2$s), so that one has been deactivated. It can be deleted; nothing is lost, since the settings are stored by Gravity Forms.', 'gravityforms-civicrm-form-processor'),
                esc_html($folder),
                esc_html($version !== '' ? $version : '?')
            );
            $link = '';
            if (current_user_can('delete_plugins')) {
                $url = wp_nonce_url(self_admin_url('plugins.php?action=delete-selected&checked[]=' . rawurlencode($file) . '&plugin_status=all'), 'bulk-plugins');
                $link = ' <a href="' . esc_url($url) . '">' . esc_html__('Delete the old copy', 'gravityforms-civicrm-form-processor') . '</a>';
            }
            echo '<div class="notice notice-info"><p>' . $message . $link . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
        }
    }

    /** @return array<string, string> plugin file => version */
    private static function replaced(): array
    {
        $value = get_option(self::OPTION, []);
        return is_array($value) ? array_filter($value, 'is_string') : [];
    }
}
