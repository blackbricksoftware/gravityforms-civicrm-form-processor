<?php
/**
 * Puts back Gravity Forms entries that were deleted, from the copy of each one in this plugin's webhook failure email.
 *
 * Up to 2.2.0, on sites running Gravity Forms 3.0.2 or later with WP_DEBUG off, a webhook that CiviCRM answered with a
 * failure (a 403, say) was recorded as sent, and with "Delete entries after Webhook are completed successfully" on, the
 * entry was deleted. The failure email went out first, and it holds the whole entry: the "$entry" part is PHP's
 * print_r() of it. This script reads those, and puts each entry back under its ORIGINAL id, with its date, fields and
 * other details, so it is the same entry #1681 it was. It sends nothing: once the cause of the failure is fixed, select
 * the entries on the Entries list and use "Send webhooks again".
 *
 * Run it with WP-CLI on the site, from the plugin's folder or with the path to this file:
 *
 *   wp eval-file bin/recover-entries.php <folder>                   what it would do; changes nothing
 *   wp eval-file bin/recover-entries.php <folder> apply             puts the entries back
 *   wp eval-file bin/recover-entries.php <folder> apply 1681-1710   only entries with these ids
 *
 * <folder> holds .txt files of text copied from the failure emails: the "$entry" part on its own, or the whole email.
 * A file may hold several entries. (The email's HTML works too.)
 *
 * It never changes an entry that exists: an id that is already taken is reported and left alone, so it is safe to run
 * again. It prints only ids, form ids, dates and how many fields each entry has, never what was submitted, since the
 * entries hold personal data. Each entry put back gets a note saying where it came from.
 *
 * How it puts an entry back under its own id: Gravity Forms always gives a new entry the next id (GFAPI::add_entry()),
 * so the entry's row is inserted with its id, and Gravity Forms' own GFAPI::update_entry() then writes its details and
 * field values. The gap left by deleted entries can be filled because MySQL only moves its next id forward.
 *
 * @var array $args The arguments after the file name, from WP-CLI's eval-file.
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WP_CLI' ) ) {
	exit( "Run this with WP-CLI: wp eval-file bin/recover-entries.php <folder> [apply] [first-last]\n" );
}
if ( ! class_exists( 'GFAPI' ) ) {
	WP_CLI::error( 'Gravity Forms is not active on this site.' );
}

$folder = $args[0] ?? '';
$apply  = in_array( 'apply', $args, true );
$range  = null;
foreach ( $args as $arg ) {
	if ( preg_match( '/^(\d+)-(\d+)$/', $arg, $m ) ) {
		$range = array( (int) $m[1], (int) $m[2] );
	}
}
if ( '' === $folder || ! is_dir( $folder ) ) {
	WP_CLI::error( 'Name the folder that holds the emails: wp eval-file bin/recover-entries.php <folder> [apply] [first-last]' );
}

/**
 * The text of a file as a person would read it: HTML's tags and entities removed, and the spaces email clients swap in
 * made plain.
 */
$readable = function ( string $raw ): string {
	$text = str_replace( array( "\r\n", "\r" ), "\n", $raw );
	if ( preg_match( '/<(code|p|br|div|html)\b/i', $text ) || false !== strpos( $text, '&gt;' ) ) {
		$text = preg_replace( '#<br\s*/?>#i', "\n", $text );
		$text = html_entity_decode( wp_strip_all_tags( $text, false ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
	return str_replace( array( "\xC2\xA0", "\t" ), array( ' ', '    ' ), $text );
};

/**
 * Parses print_r() output of an array, starting at the line after "Array" (the "(" line). print_r indents each
 * level by 8 spaces: the "(" and ")" of a level at $indent, its keys at $indent + 4. A value runs until the next key or
 * the closing ")" at that level, so values over several lines (a paragraph field) come back whole.
 *
 * @return array|null
 */
$parse = function ( array $lines, int &$i, int $indent ) use ( &$parse ) {
	$key_line = '/^ {' . ( $indent + 4 ) . '}\[(.*?)\] => ?(.*)$/';
	$close    = '/^ {' . $indent . '}\)\s*$/';
	$out      = array();
	$i++;
	while ( $i < count( $lines ) ) {
		$line = $lines[ $i ];
		if ( preg_match( $close, $line ) ) {
			return $out;
		}
		if ( ! preg_match( $key_line, $line, $m ) ) {
			return null;
		}
		$key = $m[1];
		if ( 'Array' === rtrim( $m[2] ) && isset( $lines[ $i + 1 ] ) && preg_match( '/^ {' . ( $indent + 8 ) . '}\(\s*$/', $lines[ $i + 1 ] ) ) {
			$i++;
			$value = $parse( $lines, $i, $indent + 8 );
			if ( null === $value ) {
				return null;
			}
			$out[ $key ] = $value;
			$i++;
			// print_r leaves a blank line after a nested array.
			if ( isset( $lines[ $i ] ) && '' === trim( $lines[ $i ] ) && ! preg_match( $close, $lines[ $i ] ) ) {
				$i++;
			}
			continue;
		}
		$value = array( $m[2] );
		$i++;
		while ( $i < count( $lines ) && ! preg_match( $key_line, $lines[ $i ] ) && ! preg_match( $close, $lines[ $i ] ) ) {
			$value[] = $lines[ $i ];
			$i++;
		}
		$out[ $key ] = implode( "\n", $value );
	}
	return null;
};

/** Every entry in a text: each print_r() array whose keys are an entry's (id, form_id, date_created). */
$entries_in = function ( string $text ) use ( $parse ): array {
	$lines = explode( "\n", $text );
	$found = array();
	foreach ( $lines as $n => $line ) {
		if ( 'Array' !== trim( $line ) || ! isset( $lines[ $n + 1 ] ) || ! preg_match( '/^( *)\(\s*$/', $lines[ $n + 1 ], $m ) ) {
			continue;
		}
		$i     = $n + 1;
		$array = $parse( $lines, $i, strlen( $m[1] ) );
		if ( is_array( $array ) && isset( $array['id'], $array['form_id'], $array['date_created'] ) && ! isset( $array['fields'], $array['meta'] ) ) {
			$found[] = $array;
		}
	}
	return $found;
};

// Every entry in every file, one per id. The same entry can be in several emails (one per failed webhook or attempt).
$byId  = array();
$files = array_values( array_filter( glob( rtrim( $folder, '/' ) . '/*' ), 'is_file' ) );
if ( ! $files ) {
	WP_CLI::error( "No files in $folder." );
}
foreach ( $files as $file ) {
	$found = $entries_in( $readable( (string) file_get_contents( $file ) ) );
	if ( ! $found ) {
		WP_CLI::warning( basename( $file ) . ': no entry found in it (look for the "$entry" part of the failure email).' );
		continue;
	}
	foreach ( $found as $entry ) {
		$id = (int) $entry['id'];
		if ( isset( $byId[ $id ] ) && $byId[ $id ]['entry'] != $entry ) {
			$byId[ $id ]['differs'] = true;
		}
		$byId[ $id ] = $byId[ $id ] ?? array( 'entry' => $entry, 'file' => basename( $file ), 'differs' => false );
	}
}
ksort( $byId );

global $wpdb;
$table   = GFFormsModel::get_entry_table_name();
$counts  = array( 'restored' => 0, 'would' => 0, 'exists' => 0, 'skipped' => 0, 'failed' => 0 );
$who     = wp_get_current_user()->exists() ? wp_get_current_user()->display_name : 'WP-CLI';
WP_CLI::log( ( $apply ? 'Putting back' : 'Dry run (add "apply" to put them back):' ) . ' ' . count( $byId ) . ' entr' . ( 1 === count( $byId ) ? 'y' : 'ies' ) . ' found in ' . count( $files ) . ' file(s)' . ( $range ? ", ids {$range[0]}-{$range[1]} only" : '' ) . '.' );

foreach ( $byId as $id => $found ) {
	$entry  = $found['entry'];
	$formId = (int) $entry['form_id'];
	$fields = count( array_filter( array_keys( $entry ), static fn( $k ) => is_numeric( $k ) && '' !== (string) $entry[ $k ] ) );
	$about  = sprintf( '#%d (form %d, %s, %d field value%s, from %s%s)', $id, $formId, $entry['date_created'], $fields, 1 === $fields ? '' : 's', $found['file'], $found['differs'] ? '; other copies differ, this one is used' : '' );

	if ( $range && ( $id < $range[0] || $id > $range[1] ) ) {
		$counts['skipped']++;
		WP_CLI::log( "  skipped  $about: outside {$range[0]}-{$range[1]}" );
		continue;
	}
	if ( ! is_array( GFAPI::get_form( $formId ) ) ) {
		$counts['failed']++;
		WP_CLI::log( "  FAILED   $about: there is no form $formId on this site" );
		continue;
	}
	if ( ! is_wp_error( GFAPI::get_entry( $id ) ) || $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE id = %d", $id ) ) ) {
		$counts['exists']++;
		WP_CLI::log( "  exists   $about: an entry with this id is already there, left alone" );
		continue;
	}
	if ( ! $apply ) {
		$counts['would']++;
		WP_CLI::log( "  would    $about" );
		continue;
	}

	// The row, under its own id; Gravity Forms then writes everything else, the field values included.
	$inserted = $wpdb->insert( $table, array( 'id' => $id, 'form_id' => $formId, 'date_created' => $entry['date_created'], 'status' => 'active' ), array( '%d', '%d', '%s', '%s' ) );
	if ( ! $inserted ) {
		$counts['failed']++;
		WP_CLI::log( "  FAILED   $about: the database refused the entry ({$wpdb->last_error})" );
		continue;
	}
	$entry['status'] = 'active';
	$result          = GFAPI::update_entry( $entry, $id );
	if ( is_wp_error( $result ) ) {
		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		$counts['failed']++;
		WP_CLI::log( "  FAILED   $about: Gravity Forms refused its details (" . $result->get_error_message() . '); nothing was kept' );
		continue;
	}
	GFAPI::add_note( $id, 0, 'Gravity Forms CiviCRM Form Processor', sprintf( 'Put back on %s by %s, from the copy in the webhook failure email (%s): the entry had been deleted after a failed webhook was recorded as sent. Its webhooks have not been sent again.', wp_date( 'Y-m-d H:i' ), $who, $found['file'] ) );
	$counts['restored']++;
	WP_CLI::log( "  restored $about" );
}

WP_CLI::success( sprintf(
	'%s %d; already there %d; outside the range %d; failed %d.',
	$apply ? 'Put back' : 'Would put back',
	$apply ? $counts['restored'] : $counts['would'],
	$counts['exists'],
	$counts['skipped'],
	$counts['failed']
) );
