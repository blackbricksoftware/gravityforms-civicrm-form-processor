<?php
// Only ever loaded by WordPress, as a mu-plugin.
defined( 'ABSPATH' ) || exit;

/**
 * SANDBOX ONLY. tests/run.sh copies this into the throwaway site's mu-plugins; nothing here runs on a real site, and
 * each part is off unless the process sets its environment variable.
 *
 *   GFCFP_TEST_OLD_LIBRARY=<dir> loads wp-artifact-updater from <dir>/src ahead of the plugin's own copy, the way
 *                               another plugin's older copy wins on a real site (composer puts each plugin's autoloader
 *                               in front, so the plugin loaded last supplies the classes every copy has).
 *   wp-content/gfcfp-gf-stub/   while it exists, a Gravity Forms stand-in (see below).
 *   GFCFP_HTTP_LOG=<file>       every outgoing HTTP request is appended to <file> as "<host> auth|noauth <url>", so
 *                               run.sh can see which hosts were contacted and whether anything carried a credential.
 *   wordpress.org's update checks always answer "nothing new", without the network: WordPress asks wordpress.org
 *   before it asks about this plugin, and stops if that fails, so a slow or unreachable wordpress.org would otherwise
 *   fail checks that have nothing to do with it (and log a PHP warning).
 *   GFCFP_HTTP_MOCK=<mode>      a stand-in for GitHub, so updates are tested without the network:
 *       release                 the newest release is v<GFCFP_HTTP_MOCK_VERSION> (default 9.9.9), with
 *                               gravityforms-civicrm-form-processor-v<version>.zip attached, which serves the file
 *                               GFCFP_HTTP_MOCK_ZIP. Its download redirects to file storage, as GitHub's does.
 *       no-asset                the newest release has only a zip named the way releases up to 2.0.0 named theirs.
 *                               Any other GitHub address fails. Every other request goes out as usual (WordPress's
 *                               own loopback requests, say).
 *
 * The gfcfp_test_*() functions are shorthands for run.sh's `wp eval` calls.
 */

if ( getenv( 'GFCFP_TEST_OLD_LIBRARY' ) ) {
	add_action(
		'plugins_loaded',
		function () {
			$src = rtrim( getenv( 'GFCFP_TEST_OLD_LIBRARY' ), '/' ) . '/src/';
			spl_autoload_register(
				function ( $class ) use ( $src ) {
					$prefix = 'BlackBrickSoftware\\WpArtifactUpdater\\';
					if ( 0 === strpos( $class, $prefix ) ) {
						$file = $src . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
						if ( is_file( $file ) ) {
							require $file;
						}
					}
				},
				true,
				true
			);
		},
		1
	);
}

// While the folder wp-content/gfcfp-gf-stub exists, the parts of Gravity Forms that plugin versions up to 2.0.0 touch
// on every request (they fatal without it), so an old copy can run here as it does on a real site. run.sh creates it,
// with a notification.php, around the migration checks only.
if ( is_dir( WP_CONTENT_DIR . '/gfcfp-gf-stub' ) ) {
	if ( ! class_exists( 'GFForms' ) ) {
		class GFForms {}
	}
	if ( ! class_exists( 'GFCommon' ) ) {
		class GFCommon {
			public static function get_base_path() {
				return WP_CONTENT_DIR . '/gfcfp-gf-stub';
			}
		}
	}
}

// Webhooks to the test's stand-in for CiviCRM (tests/capture.php, on 127.0.0.1 and its own port): WordPress's "safe"
// requests only allow ports 80, 443 and 8080, or the site's own.
add_filter(
	'http_allowed_safe_ports',
	function ( $ports, $host, $url ) {
		$port = (int) wp_parse_url( $url, PHP_URL_PORT );
		return '127.0.0.1' === $host && $port ? array_merge( (array) $ports, array( $port ) ) : $ports;
	},
	10,
	3
);

// No email leaves the sandbox: each one is appended to wp-content/gfcfp-mail.jsonl (to, subject, message) instead, so
// run.sh can check this plugin's webhook failure emails.
add_filter(
	'pre_wp_mail',
	function ( $return, $atts ) {
		file_put_contents( WP_CONTENT_DIR . '/gfcfp-mail.jsonl', wp_json_encode( array( 'to' => $atts['to'], 'subject' => $atts['subject'], 'message' => $atts['message'] ) ) . "\n", FILE_APPEND );
		return true;
	},
	10,
	2
);

// Records each time Gravity Forms' pre-feed form filter runs, as "<form id> <entry id>" in wp-content/gfcfp-probe.log,
// so run.sh can check that a webhook sent again gets it, as one sent in the background does.
add_filter(
	'gform_form_pre_process_async_task',
	function ( $form, $entry ) {
		file_put_contents( WP_CONTENT_DIR . '/gfcfp-probe.log', ( $form['id'] ?? '?' ) . ' ' . ( $entry['id'] ?? '?' ) . "\n", FILE_APPEND );
		return $form;
	},
	10,
	2
);

// A site's own feed filter: webhooks named "Skip: ..." are left out, as gform_addon_pre_process_feeds and its
// relatives let a site do. And a payment add-on's delay: an entry whose first field is "Unpaid" waits for its payment,
// as gform_is_delayed_pre_process_feed lets PayPal and the like do. run.sh checks a webhook sent again respects both.
add_filter(
	'gform_gravityformswebhooks_pre_process_feeds',
	function ( $feeds ) {
		return is_array( $feeds ) ? array_values( array_filter( $feeds, function ( $feed ) { return 0 !== strpos( (string) ( $feed['meta']['feedName'] ?? '' ), 'Skip:' ); } ) ) : $feeds;
	}
);
add_filter(
	'gform_is_delayed_pre_process_feed',
	function ( $delayed, $form, $entry ) {
		return 'Unpaid' === ( $entry['1'] ?? '' ) ? true : $delayed;
	},
	10,
	3
);

// Code on the site that throws while an entry is updated: bin/recover-entries.php must keep nothing of an entry it
// was putting back then. Only for an entry whose first field is "Throws on update".
add_filter(
	'gform_entry_pre_update',
	function ( $entry ) {
		if ( 'Throws on update' === ( $entry['1'] ?? '' ) ) {
			throw new RuntimeException( 'gfcfp sandbox: throws on update' );
		}
		return $entry;
	}
);

// Page loads don't start WP-Cron, whose own update check could land in the middle of a check run.sh is counting.
if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		$log  = getenv( 'GFCFP_HTTP_LOG' );
		$mode = getenv( 'GFCFP_HTTP_MOCK' );
		$host = strval( wp_parse_url( $url, PHP_URL_HOST ) );
		$auth = empty( $args['headers']['Authorization'] ) && empty( $args['headers']['authorization'] ) ? 'noauth' : 'auth';
		if ( $log ) {
			file_put_contents( $log, "$host $auth $url\n", FILE_APPEND );
		}

		// A response as WP_Http gives it. With stream set, the body goes into the file the caller named.
		$answer = function ( $code, $body = '', $headers = array() ) use ( $args ) {
			if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
				file_put_contents( $args['filename'], $body );
				$body = '';
			}
			return array( 'headers' => $headers, 'body' => $body, 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => $args['filename'] ?? null );
		};

		$path = strval( wp_parse_url( $url, PHP_URL_PATH ) );
		if ( 'api.wordpress.org' === $host && 0 === strpos( $path, '/plugins/update-check/' ) ) {
			return $answer( 200, wp_json_encode( array( 'plugins' => array(), 'translations' => array(), 'no_update' => array() ) ) );
		}
		if ( 'api.wordpress.org' === $host && 0 === strpos( $path, '/themes/update-check/' ) ) {
			return $answer( 200, wp_json_encode( array( 'themes' => array(), 'no_update' => array(), 'translations' => array() ) ) );
		}
		if ( 'api.wordpress.org' === $host && 0 === strpos( $path, '/core/version-check/' ) ) {
			return $answer( 200, wp_json_encode( array( 'offers' => array(), 'translations' => array() ) ) );
		}
		// Gravity Forms' own calls home (version and licence checks), when it is installed for the webhook checks, except
		// while run.sh downloads it with a licence key (GFCFP_ALLOW_GF_API).
		if ( ! getenv( 'GFCFP_ALLOW_GF_API' ) && preg_match( '/(^|\.)(gravityapi|gravityforms|gravityhelp|rocketgenius)\.com$/', $host ) ) {
			return new WP_Error( 'http_request_failed', 'sandbox: no calls to Gravity Forms\' servers' );
		}
		if ( ! $mode ) {
			return $pre;
		}

		$repo    = 'blackbricksoftware/gravityforms-civicrm-form-processor';
		$version = getenv( 'GFCFP_HTTP_MOCK_VERSION' ) ?: '9.9.9';
		$name    = 'no-asset' === $mode ? 'gravity_forms_civicrm_form_processor.zip' : "gravityforms-civicrm-form-processor-v$version.zip";
		$public  = "https://github.com/$repo/releases/download/v$version/$name";
		$store   = 'https://release-assets.githubusercontent.com/signed/' . $name . '?sig=sandbox';
		if ( "https://api.github.com/repos/$repo/releases/latest" === $url ) {
			return $answer( 200, wp_json_encode( array(
				'tag_name' => "v$version",
				'assets'   => array( array( 'name' => $name, 'url' => "https://api.github.com/repos/$repo/releases/assets/101", 'browser_download_url' => $public ) ),
			) ) );
		}
		if ( $public === $url ) {
			// GitHub answers with a redirect to its file storage. This filter answers before WordPress's HTTP layer,
			// which would follow it itself whenever the caller allows redirects, so do what it does: log the hop, with
			// the same headers, and serve the file. Only a caller that allows no redirects sees the 302.
			if ( ! empty( $args['redirection'] ) ) {
				if ( $log ) {
					file_put_contents( $log, "release-assets.githubusercontent.com $auth $store\n", FILE_APPEND );
				}
				return $answer( 200, file_get_contents( getenv( 'GFCFP_HTTP_MOCK_ZIP' ) ) );
			}
			return $answer( 302, '', array( 'location' => $store ) );
		}
		if ( in_array( $host, array( 'api.github.com', 'github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com' ), true ) ) {
			return new WP_Error( 'http_request_failed', 'sandbox: no stand-in for ' . $url );
		}
		return $pre;
	},
	5,
	3
);

/** The plugin's basename, whichever folder it is in, or '' when it isn't loaded. */
function gfcfp_test_basename(): string {
	return defined( 'GRAVITYFORMS_CIVICRM_FORM_PROCESSOR' ) ? plugin_basename( GRAVITYFORMS_CIVICRM_FORM_PROCESSOR ) : '';
}

/** Where the updater library caches the newest release for the plugin. */
function gfcfp_test_cache_key(): string {
	return 'wpu_release_' . md5( gfcfp_test_basename() . '|github.com' );
}

/**
 * Forgets both caches, has WordPress check for updates, and prints what it found: ASKED is how many times WordPress
 * asked about this plugin (so "nothing went to GitHub" can be told apart from "the check never got that far"), OFFERED
 * the update it offers, CURRENT the newest release when that is not newer.
 */
function gfcfp_test_check(): void {
	$asked = 0;
	add_filter(
		'update_plugins_github.com',
		function ( $update, $data, $file ) use ( &$asked ) {
			$asked += $file === gfcfp_test_basename() ? 1 : 0;
			return $update;
		},
		1,
		3
	);
	delete_site_transient( gfcfp_test_cache_key() );
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	$state = get_site_transient( 'update_plugins' );
	$file  = gfcfp_test_basename();
	echo 'ASKED=', $asked, ' OFFERED=', $state->response[ $file ]->new_version ?? 'none', ' CURRENT=', $state->no_update[ $file ]->new_version ?? 'none', "\n";
}

/**
 * Checks for updates, then has WordPress's own auto-updater install the one offered, the way WP-Cron does on a live
 * site, and prints AUTO (whether it would) and RESULT. After installing, the auto-updater loads the site with a request
 * of its own and rolls the update back if the plugin then fails to load.
 */
function gfcfp_test_auto_update(): void {
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
	add_filter( 'automatic_updates_is_vcs_checkout', '__return_false' );   // The sandbox sits inside this git repository.
	add_filter( 'wp_doing_cron', '__return_true' );   // As in WP-Cron: outside it, the upgrader deactivates the plugin first.
	gfcfp_test_check();
	$item    = get_site_transient( 'update_plugins' )->response[ gfcfp_test_basename() ] ?? null;
	$updater = new WP_Automatic_Updater();
	echo 'AUTO=', $item && $updater->should_update( 'plugin', $item, WP_PLUGIN_DIR ) ? 'yes' : 'no', "\n";
	$result = $item ? $updater->update( 'plugin', $item ) : null;
	echo 'RESULT=', is_wp_error( $result ) ? implode( ' | ', $result->get_error_messages() ) : var_export( $result, true ), "\n";
}
