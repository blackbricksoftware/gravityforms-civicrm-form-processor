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
 * Run it with WP-CLI. The script and the folder of copies can be anywhere, outside the site's folder too:
 *
 *   wp --path=<site> eval-file recover-entries.php <folder>                   what it would do; changes nothing
 *   wp --path=<site> eval-file recover-entries.php <folder> apply             puts the entries back
 *   wp --path=<site> eval-file recover-entries.php <folder> apply 1681-1710   only entries with these ids
 *
 * <folder> holds .txt files of text copied from the failure emails (the "$entry" part on its own, or the whole email;
 * with its line breaks or without, as some mail apps show it: "Array ( [id] => 1681 [form_id] => 4 ... )"), or the
 * emails' HTML. A file may hold several entries, and an entry may be in several files.
 *
 * - It never changes an entry that exists: an id that is taken is reported and left alone, so it is safe to run again.
 * - Each copy is checked before it is used, and one that fails is reported and not used: it must be whole (its closing
 *   ")" there, nothing cut off), have each key once, have single-line fields on one line, and stored lists must read.
 *   Copies of one entry in several files must agree (the email's HTML is preferred to copied text).
 * - Each entry is put back in one database transaction, then read back and compared with the copy. If anything differs
 *   or goes wrong, none of it is kept.
 * - It prints only ids, form ids, dates, field ids and counts, never what was submitted: the entries hold personal data.
 *
 * What a copy can't bring back: the entry's notes, uploaded files (deleted with the entry), payment records, and the
 * types of stored values (print_r() writes them all as text). A paragraph field's line breaks come back as a browser
 * sends them ("\r\n"). From a copy without line breaks, paragraph fields come back as one line, and runs of spaces in
 * any value as one space; the dry run names those fields. Values of fields no longer on the form can't be saved, and
 * are listed.
 *
 * How it puts an entry back under its own id: Gravity Forms always gives a new entry the next id (GFAPI::add_entry()),
 * so the entry's row is inserted with its id, and Gravity Forms' own GFAPI::update_entry() then writes its details and
 * field values. The gap left by deleted entries can be filled because MySQL only moves its next id forward.
 *
 * @var array $args The arguments after the file name, from WP-CLI's eval-file.
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WP_CLI' ) ) {
	exit( "Run this with WP-CLI: wp eval-file recover-entries.php <folder> [apply] [first-last]\n" );
}
if ( ! class_exists( 'GFAPI' ) ) {
	WP_CLI::error( 'Gravity Forms is not active on this site.' );
}

// The folder, then "apply" and an id range, in either order. Anything else is a mistake: a mistyped range must not
// quietly become "all of them".
$usage  = 'wp eval-file recover-entries.php <folder> [apply] [first-last]';
$folder = (string) ( $args[0] ?? '' );
$apply  = false;
$range  = null;
foreach ( array_slice( $args, 1 ) as $arg ) {
	if ( 'apply' === $arg ) {
		$apply = true;
	} elseif ( preg_match( '/^(\d+)(?:-|\x{2013}|\x{2014})(\d+)$/u', $arg, $m ) && 0 < (int) $m[1] && (int) $m[1] <= (int) $m[2] ) {
		$range = array( (int) $m[1], (int) $m[2] );
	} else {
		WP_CLI::error( "Not understood: \"$arg\". Use: $usage (a range is the first and last id with a hyphen: 1681-1710)" );
	}
}
if ( '' === $folder ) {
	WP_CLI::error( "Name the folder that holds the copies: $usage" );
}
if ( ! is_dir( $folder ) ) {
	WP_CLI::error( "There is no folder $folder" . ( '/' === $folder[0] ? '' : ' in ' . getcwd() ) . ". Use: $usage" );
}

// An entry's own columns; every other key is a field's input (a number, "4.3") or an add-on's entry meta.
$columns = array( 'id', 'status', 'form_id', 'ip', 'source_url', 'currency', 'post_id', 'date_created', 'date_updated', 'is_starred', 'is_read', 'user_agent', 'payment_status', 'payment_date', 'payment_amount', 'payment_method', 'transaction_id', 'is_fulfilled', 'created_by', 'transaction_type', 'source_id' );

/**
 * A file's text, as UTF-8 with "\n" line ends; or why it can't be read.
 *
 * @return array{0: ?string, 1: ?string}
 */
$text_of = static function ( string $raw ): array {
	if ( str_starts_with( $raw, "\xFF\xFE" ) || str_starts_with( $raw, "\xFE\xFF" ) ) {
		if ( ! function_exists( 'mb_convert_encoding' ) ) {
			return array( null, 'it is UTF-16 text: save it as UTF-8' );
		}
		$from = "\xFF" === $raw[0] ? 'UTF-16LE' : 'UTF-16BE';
		$raw  = (string) mb_convert_encoding( substr( $raw, 2 ), 'UTF-8', $from );
	}
	if ( str_starts_with( $raw, "\xEF\xBB\xBF" ) ) {
		$raw = substr( $raw, 3 );
	}
	if ( 1 !== preg_match( '//u', $raw ) ) {
		return array( null, 'it is not UTF-8 text: save it as UTF-8' );
	}
	return array( str_replace( array( "\r\n", "\r" ), "\n", $raw ), null );
};

/**
 * Some mail apps copy print_r()'s indentation as non-breaking spaces. Then they turned the values' spaces into them
 * too, so all of them become spaces again. Otherwise they are left alone: they are part of what was submitted.
 */
$plain_spaces = static function ( string $text ): string {
	return preg_match( '/^[ \x{00A0}]*\x{00A0}[ \x{00A0}]*[\[()]|\]\x{00A0}=>/mu', $text ) ? str_replace( "\u{00A0}", ' ', $text ) : $text;
};

/**
 * Parses print_r() lines of one array, those between its "(" and ")", whose keys are $indent spaces in. A value runs
 * to the next key line, so a value over several lines comes back whole (its lines joined by "\n"); an "Array" value is
 * a nested array, parsed the same way. $shift is how far the whole copy is indented: a value's other lines lose it too.
 *
 * @return array|string The array, or why it can't be read.
 */
$parse = static function ( array $lines, int $indent, int $shift ) use ( &$parse ) {
	$key_line = '/^ {' . $indent . '}\[([^\]\n]*)\] =>(?: (.*))?$/';
	$out      = array();
	$count    = count( $lines );
	for ( $n = 0; $n < $count; ) {
		if ( ! preg_match( $key_line, $lines[ $n ], $m ) ) {
			return 0 === $n ? 'it does not start with a key' : 'a line after [' . array_key_last( $out ) . '] is not a key';
		}
		$key   = $m[1];
		$first = $m[2] ?? '';
		if ( array_key_exists( $key, $out ) ) {
			return "[$key] is in it twice (two copies run together, or a value with a line that looks like a key)";
		}
		if ( 'Array' === $first && isset( $lines[ $n + 1 ] ) && preg_match( '/^ {' . ( $indent + 4 ) . '}\( *$/', $lines[ $n + 1 ] ) ) {
			$close = '/^ {' . ( $indent + 4 ) . '}\) *$/';
			for ( $end = $n + 2; $end < $count && ! preg_match( $close, $lines[ $end ] ); $end++ ) {
				// To its ")".
			}
			if ( $end >= $count ) {
				return "[$key] is cut off";
			}
			$value = $parse( array_slice( $lines, $n + 2, $end - $n - 2 ), $indent + 8, $shift );
			if ( is_string( $value ) ) {
				return "in [$key], $value";
			}
			$out[ $key ] = $value;
			$n           = $end + 1;
			// print_r() leaves a blank line after a nested array.
			if ( $n < $count && '' === trim( $lines[ $n ] ) ) {
				$n++;
			}
			continue;
		}
		$value = array( $first );
		for ( $n++; $n < $count && ! preg_match( $key_line, $lines[ $n ] ); $n++ ) {
			$line    = $lines[ $n ];
			$value[] = ( $shift > 0 && str_starts_with( $line, str_repeat( ' ', $shift ) ) ) ? substr( $line, $shift ) : $line;
		}
		$out[ $key ] = implode( "\n", $value );
	}
	return $out;
};

/**
 * One copy of an entry, from its print_r() lines: its "Array" line, its "(" line, and the rest of its part of the file.
 * The close is the LAST line that is ")" at the "(" line's indent: a value can hold a line that is just ")", and the
 * close comes after all of them. A key line after it means the real close is missing: the copy was cut off.
 *
 * @return array|string The entry, or why it can't be used.
 */
$entry_from = static function ( array $lines ) use ( $parse ) {
	preg_match( '/^( *)\( *$/', $lines[1], $m );
	$indent = strlen( $m[1] );
	$close  = null;
	foreach ( $lines as $n => $line ) {
		if ( $n > 1 && preg_match( '/^ {' . $indent . '}\) *$/', $line ) ) {
			$close = $n;
		}
	}
	if ( null === $close || preg_grep( '/^ {' . ( $indent + 4 ) . '}\[[^\]\n]*\] =>/', array_slice( $lines, $close + 1 ) ) ) {
		return 'it is cut off (its closing ")" is missing): copy the whole entry, opening "View entire message" first if the email has it';
	}
	return $parse( array_slice( $lines, 2, $close - 2 ), $indent + 4, $indent );
};

/**
 * One copy of an entry from a copy that lost its line breaks, "Array ( [id] => 1681 [form_id] => 4 ... )", as some mail
 * apps show the email (every run of spaces and line breaks is one space). Each "[key] => " starts a value, which runs to
 * the next one. Its values' line breaks and runs of spaces are lost for good. A stored array in it can't be read
 * reliably, so a copy holding one isn't used.
 *
 * @return array|string The entry, or why it can't be used.
 */
$entry_from_flat = static function ( string $part ) {
	$part = trim( $part );
	if ( ! str_starts_with( $part, 'Array ( ' ) ) {
		return 'it does not start with "Array ("';
	}
	if ( ! str_ends_with( $part, ' )' ) ) {
		return 'it is cut off (it does not end with ")"): copy the whole entry, opening "View entire message" first if the email has it';
	}
	$inner = substr( $part, 8, -2 );
	preg_match_all( '/(?<=^| )\[([A-Za-z0-9_.]+)\] =>(?= |$)/', $inner, $keys, PREG_OFFSET_CAPTURE | PREG_SET_ORDER );
	if ( ! $keys || 0 !== $keys[0][0][1] ) {
		return 'it does not start with a key';
	}
	$out = array();
	foreach ( $keys as $i => $key ) {
		$name = $key[1][0];
		if ( array_key_exists( $name, $out ) ) {
			return "[$name] is in it twice (two copies run together, or a value that holds \"[$name] =>\")";
		}
		$from  = $key[0][1] + strlen( $key[0][0] );
		$value = trim( substr( $inner, $from, ( isset( $keys[ $i + 1 ] ) ? $keys[ $i + 1 ][0][1] : strlen( $inner ) ) - $from ), ' ' );
		if ( str_starts_with( $value, 'Array ( ' ) ) {
			return "[$name] holds a stored array, which a copy without its line breaks can't be read reliably";
		}
		$out[ $name ] = $value;
	}
	return $out;
};

/**
 * Every copy of an entry in a file's text: each is ['entry' => array] or ['problem' => why it can't be used], with its
 * 'id' if that can be read, and its 'rank': 2 from the email's HTML, 1 from copied text, 0 from copied text that lost
 * its line breaks ('flat').
 */
$copies_in = static function ( string $text ) use ( $entry_from, $entry_from_flat, $plain_spaces ): array {
	$html  = false !== strpos( $text, '] =&gt; ' );
	$flat  = ! $html && false !== strpos( $text, '] => ' ) && ! preg_match( '/^ +\[[^\]\n]*\] =>/m', $text );
	$parts = array();
	if ( $flat ) {
		// Copied text without its line breaks (or wrapped anywhere): made one line, and cut into entries at each
		// "$entry" heading (whole emails) or else at each "Array ( [id] => ".
		$text = trim( (string) preg_replace( '/[ \t\n]+/', ' ', $text ) );
		if ( preg_match_all( '/(?:^| )\$entry (.*?)(?= \$(?:response|responseData|feed|entry|form) | ===|$)/', $text, $found ) ) {
			$parts = $found[1];
		} else {
			$parts = array_filter( preg_split( '/ (?=Array \( \[id\] => )/', $text ), static fn( $p ) => str_starts_with( $p, 'Array ( ' ) );
		}
		$copies = array();
		foreach ( $parts as $part ) {
			$id    = preg_match( '/(?:^| )\[id\] => (\d+)(?: |$)/', $part, $m ) ? (int) $m[1] : null;
			$entry = $entry_from_flat( $part );
			if ( is_array( $entry ) && ( ! isset( $entry['id'], $entry['form_id'], $entry['date_created'] ) || isset( $entry['fields'] ) ) ) {
				continue; // Some other array, copied without its heading.
			}
			$copies[] = is_string( $entry ) ? array( 'problem' => $entry, 'id' => $id, 'rank' => 0 ) : array( 'entry' => $entry, 'id' => (int) $entry['id'], 'rank' => 0 );
		}
		return $copies;
	}
	if ( $html ) {
		// The email's HTML: the "$entry" heading, then print_r()'s output in a <code> element. htmlspecialchars() escaped
		// every "<" in it, so the part ends at the next "</code>", and only it is decoded.
		preg_match_all( '#<strong[^>]*>\s*\$entry\s*</strong>.*?<code[^>]*>(.*?)</code>#s', $text, $found );
		foreach ( $found[1] as $part ) {
			$parts[] = explode( "\n", $plain_spaces( html_entity_decode( $part, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		}
	} else {
		// Copied text. Nothing in it is decoded or stripped: it is what was submitted, "<" and "&" included.
		$lines  = explode( "\n", $plain_spaces( $text ) );
		$labels = array();
		foreach ( $lines as $n => $line ) {
			if ( preg_match( '/^\s*(?:\$(response|responseData|feed|entry|form)|===.*===)\s*$/', $line, $m ) ) {
				$labels[ $n ] = $m[1] ?? 'end';
			}
		}
		if ( in_array( 'entry', $labels, true ) ) {
			// Whole emails: each "$entry" heading's part runs to the email's next heading.
			$heads = array_keys( $labels );
			foreach ( $heads as $i => $n ) {
				if ( 'entry' === $labels[ $n ] ) {
					$parts[] = array_slice( $lines, $n + 1, ( $heads[ $i + 1 ] ?? count( $lines ) ) - $n - 1 );
				}
			}
		} else {
			// "$entry" parts copied on their own: each starts at an "Array" line followed by "(".
			$starts = array();
			foreach ( $lines as $n => $line ) {
				if ( 'Array' === trim( $line ) && isset( $lines[ $n + 1 ] ) && preg_match( '/^ *\( *$/', $lines[ $n + 1 ] ) ) {
					$starts[] = $n;
				}
			}
			foreach ( $starts as $i => $n ) {
				$parts[] = array_slice( $lines, $n, ( $starts[ $i + 1 ] ?? count( $lines ) ) - $n );
			}
		}
	}
	$copies = array();
	foreach ( $parts as $part ) {
		$id    = preg_match( '/^ *\[id\] => (\d+) *$/m', implode( "\n", $part ), $m ) ? (int) $m[1] : null;
		$start = null;
		foreach ( $part as $n => $line ) {
			if ( 'Array' === trim( $line ) && isset( $part[ $n + 1 ] ) && preg_match( '/^ *\( *$/', $part[ $n + 1 ] ) ) {
				$start = $n;
				break;
			}
		}
		$entry = null === $start ? 'its "$entry" part holds no entry' : $entry_from( array_slice( $part, $start ) );
		if ( is_array( $entry ) && ( ! isset( $entry['id'], $entry['form_id'], $entry['date_created'] ) || isset( $entry['fields'] ) ) ) {
			continue; // Some other array (a form, say), copied without its heading.
		}
		$rank     = $html ? 2 : 1;
		$copies[] = is_string( $entry ) ? array( 'problem' => $entry, 'id' => $id, 'rank' => $rank ) : array( 'entry' => $entry, 'id' => (int) $entry['id'], 'rank' => $rank );
	}
	return $copies;
};

/**
 * Checks a copy against its form, and readies it: a plain paragraph field's line breaks become "\r\n", as a browser
 * sends them. Returns [the entry, problems (it can't be used), notes (for whoever runs this), keys not to compare
 * after saving (Gravity Forms can't save them), and the number of field values it will save]. $flat: the copy lost its
 * line breaks, so it must have every key its form gives an entry (one missing means it was cut off, or the form has
 * fields it didn't have then), and its paragraph fields can only come back as one line.
 */
$check = static function ( array $entry, array $form, bool $flat ) use ( $columns ): array {
	$problems = array();
	$notes    = array();
	if ( ! ctype_digit( (string) $entry['id'] ) || (int) $entry['id'] < 1 ) {
		$problems[] = 'its id is not a number';
	}
	if ( ! preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $entry['date_created'] ) ) {
		$problems[] = 'its date_created is not a date';
	}
	// The form's inputs, by id ("4.3" for part of a name): Gravity Forms puts one key in an entry for each, even when
	// empty, so a copy of a whole entry has them all, unless the form has changed since.
	$inputs = array();
	foreach ( $form['fields'] as $field ) {
		$field_inputs = $field->get_entry_inputs();
		foreach ( is_array( $field_inputs ) ? wp_list_pluck( $field_inputs, 'id' ) : array( $field->id ) as $input_id ) {
			$inputs[ (string) $input_id ] = $field;
		}
	}
	$meta      = array_keys( (array) GFFormsModel::get_entry_meta( $form['id'] ) );
	$wrapped   = array();
	$unread    = array();
	$gone      = array();
	$files     = array();
	$paragraph = array();
	$values    = 0;
	foreach ( $entry as $key => $value ) {
		$key = (string) $key;
		if ( in_array( $key, $columns, true ) ) {
			if ( is_array( $value ) || str_contains( $value, "\n" ) ) {
				$wrapped[] = $key;
			}
			continue;
		}
		if ( ! is_numeric( $key ) ) {
			if ( ! in_array( $key, $meta, true ) && '' !== $value ) {
				$gone[] = $key;
			}
		} elseif ( ! isset( $inputs[ $key ] ) ) {
			if ( '' !== $value ) {
				$gone[] = $key;
			}
			continue;
		} elseif ( '' !== $value ) {
			$field = $inputs[ $key ];
			$type  = $field->get_input_type();
			$values++;
			if ( is_array( $value ) ) {
				$wrapped[] = $key;
				continue;
			}
			if ( str_contains( $value, "\n" ) ) {
				if ( ! in_array( $type, array( 'textarea', 'post_content', 'post_excerpt' ), true ) ) {
					$wrapped[] = $key;
				} elseif ( ! $field->useRichTextEditor ) {
					$entry[ $key ] = $value = str_replace( "\n", "\r\n", $value );
				}
			}
			if ( $flat && in_array( $type, array( 'textarea', 'post_content', 'post_excerpt' ), true ) ) {
				$paragraph[] = $key;
			}
			if ( in_array( $type, array( 'fileupload', 'signature', 'post_image' ), true ) ) {
				$files[] = $key;
			}
		}
		// A stored list (a List field, say) that no longer reads was changed by the copy: one character is enough.
		if ( is_string( $value ) && 'b:0;' !== $value && is_serialized( $value ) && false === @unserialize( $value, array( 'allowed_classes' => false ) ) ) { // phpcs:ignore
			$unread[] = $key;
		}
	}
	if ( $wrapped ) {
		$problems[] = 'line breaks in values that are one line (' . implode( ', ', $wrapped ) . '): the copy was wrapped or run together with other text';
	}
	if ( $unread ) {
		$problems[] = 'stored values that no longer read (' . implode( ', ', $unread ) . '): the copy changed them';
	}
	if ( $gone ) {
		$notes[] = 'not on the form now, so they can\'t be put back: ' . implode( ', ', $gone );
	}
	$missing = array_diff( array_keys( array_filter( $inputs, static fn( $f ) => ! $f->displayOnly ) ), array_map( 'strval', array_keys( $entry ) ) );
	if ( $missing && $flat ) {
		$problems[] = 'it has no key for ' . implode( ', ', $missing ) . ': it was cut off, or the form has had fields added since';
	} elseif ( $missing ) {
		$notes[] = 'the form has fields this copy has no key for (added since?): ' . implode( ', ', $missing );
	}
	if ( $paragraph ) {
		$notes[] = 'paragraph fields (' . implode( ', ', $paragraph ) . '): the copy has no line breaks, so they come back as one line';
	}
	if ( $files ) {
		$notes[] = 'file fields (' . implode( ', ', $files ) . '): their files were deleted with the entry, so these links lead nowhere';
	}
	$policy = rgars( $form, 'personalData/retention/policy', 'retain' );
	$days   = (int) rgars( $form, 'personalData/retention/retain_entries_days' );
	if ( in_array( $policy, array( 'trash', 'delete' ), true ) && $days > 0 && strtotime( $entry['date_created'] . ' UTC' ) < time() - DAY_IN_SECONDS * $days ) {
		$notes[] = "older than the $days days the form's personal data setting keeps entries: Gravity Forms will $policy it on its next daily run";
	}
	return array( $entry, $problems, $notes, $gone, $values );
};

/** Whether two values are the same as print_r() would write them: null, false and '' alike, numbers as text. */
$same = static function ( $a, $b ) use ( &$same ): bool {
	if ( is_array( $a ) || is_array( $b ) ) {
		if ( ! is_array( $a ) || ! is_array( $b ) || count( $a ) !== count( $b ) ) {
			return false;
		}
		foreach ( $a as $key => $value ) {
			if ( ! array_key_exists( $key, $b ) || ! $same( $value, $b[ $key ] ) ) {
				return false;
			}
		}
		return true;
	}
	return (string) $a === (string) $b;
};

/** A database error without the values it may quote: names and numbers are kept, anything else in quotes is not. */
$redact = static fn( string $error ): string => (string) preg_replace_callback( "/'([^']*)'/", static fn( $m ) => preg_match( '/^[A-Za-z0-9_.]{1,64}$/', $m[1] ) ? $m[0] : "'…'", $error );

// Every copy in every file, by entry id.
$byId  = array();
$files = array_values( array_filter( (array) glob( rtrim( $folder, '/' ) . '/*' ), 'is_file' ) );
if ( ! $files ) {
	// Say what is there instead: the copies may have landed in a folder inside it.
	$inside = array_values( array_diff( (array) scandir( $folder ), array( '.', '..' ) ) );
	$names  = array_map( static fn( string $name ): string => is_dir( "$folder/$name" ) ? "$name/" : $name, array_slice( $inside, 0, 5 ) );
	WP_CLI::error( 'No files in ' . ( realpath( $folder ) ?: $folder ) . ( $inside ? ', only ' . implode( ', ', $names ) . ( count( $inside ) > 5 ? ', …' : '' ) . '. The copies go in it directly, as .txt files.' : ': it is empty.' ) );
}
foreach ( $files as $file ) {
	list( $text, $why ) = $text_of( (string) file_get_contents( $file ) );
	if ( null === $text ) {
		WP_CLI::warning( basename( $file ) . ": $why." );
		continue;
	}
	$copies = $copies_in( $text );
	if ( ! $copies ) {
		WP_CLI::warning( basename( $file ) . ': no entry found in it (look for the "$entry" part of the failure email).' );
	}
	foreach ( $copies as $copy ) {
		$copy['file'] = basename( $file );
		if ( null === $copy['id'] ) {
			WP_CLI::warning( "{$copy['file']}: an entry in it can't be used, and its id can't be read: {$copy['problem']}." );
			continue;
		}
		$byId[ $copy['id'] ][] = $copy;
	}
}
ksort( $byId );

global $wpdb;
$entry_table = GFFormsModel::get_entry_table_name();
$meta_table  = GFFormsModel::get_entry_meta_table_name();
$notes_table = GFFormsModel::get_entry_notes_table_name();
$counts      = array( 'restored' => 0, 'would' => 0, 'exists' => 0, 'skipped' => 0, 'failed' => 0, 'missing' => 0 );
$who         = wp_get_current_user()->exists() ? wp_get_current_user()->display_name : 'WP-CLI';
$forms       = array();
WP_CLI::log( sprintf( 'Site: %s (database %s, tables %s*)', home_url(), defined( 'DB_NAME' ) ? DB_NAME : '?', $wpdb->prefix ) );
WP_CLI::log( ( $apply ? 'Putting back' : 'Dry run (add "apply" to put them back):' ) . ' ' . count( $byId ) . ' entr' . ( 1 === count( $byId ) ? 'y' : 'ies' ) . ' found in ' . count( $files ) . ' file(s)' . ( $range ? ", ids {$range[0]}-{$range[1]} only" : '' ) . '.' );

// An entry being put back, for the shutdown function: if PHP stops part-way (a fatal error), it is taken out again.
$restoring = 0;
$remove    = static function ( int $id ) use ( $wpdb, $entry_table, $meta_table, $notes_table ): void {
	$wpdb->query( 'ROLLBACK' );
	// Without transactions (a MyISAM table), the rows are deleted instead. Only ever an entry this run inserted.
	$wpdb->delete( $meta_table, array( 'entry_id' => $id ), array( '%d' ) );
	$wpdb->delete( $notes_table, array( 'entry_id' => $id ), array( '%d' ) );
	$wpdb->delete( $entry_table, array( 'id' => $id ), array( '%d' ) );
};
register_shutdown_function(
	static function () use ( &$restoring, $remove ): void {
		if ( $restoring ) {
			$remove( $restoring );
			fwrite( STDERR, "Stopped while putting back #$restoring: it was taken out again, so nothing of it is kept. Run again once the error above is dealt with.\n" );
		}
	}
);
if ( $apply ) {
	// Database errors can quote values: this script reports them itself, without the values.
	$wpdb->suppress_errors( true );
}

foreach ( $byId as $id => $copies ) {
	$first  = current( array_filter( $copies, static fn( $c ) => isset( $c['entry'] ) ) );
	$formId = $first ? (int) $first['entry']['form_id'] : 0;
	$from   = implode( ', ', array_unique( array_column( $copies, 'file' ) ) );
	$about  = sprintf( '#%d (%s, from %s)', $id, $first ? "form $formId, {$first['entry']['date_created']}" : 'form ?', $from );

	if ( $range && ( $id < $range[0] || $id > $range[1] ) ) {
		$counts['skipped']++;
		WP_CLI::log( "  skipped  $about: outside {$range[0]}-{$range[1]}" );
		continue;
	}
	if ( ! $first ) {
		$counts['failed']++;
		WP_CLI::log( "  FAILED   $about: " . implode( '; ', array_unique( array_column( $copies, 'problem' ) ) ) );
		continue;
	}

	// Each copy checked against its form. The email's HTML is preferred to copied text, copied text to text that lost
	// its line breaks, and the copies used must agree.
	$usable = array();
	$notes  = array();
	foreach ( $copies as $copy ) {
		if ( isset( $copy['problem'] ) ) {
			$notes[] = "the copy in {$copy['file']} can't be used: {$copy['problem']}";
			continue;
		}
		$copyForm = (int) $copy['entry']['form_id'];
		$forms[ $copyForm ] ??= GFAPI::get_form( $copyForm );
		if ( ! is_array( $forms[ $copyForm ] ) ) {
			$notes[] = "the copy in {$copy['file']} is for form $copyForm, which isn't on this site";
			continue;
		}
		list( $entry, $problems, $copyNotes, $gone, $values ) = $check( $copy['entry'], $forms[ $copyForm ], 0 === $copy['rank'] );
		if ( $problems ) {
			$notes[] = "the copy in {$copy['file']} can't be used: " . implode( '; ', $problems );
			continue;
		}
		$usable[] = $copy + array( 'ready' => $entry, 'notes' => $copyNotes, 'gone' => $gone, 'values' => $values );
	}
	$best = $usable ? max( array_column( $usable, 'rank' ) ) : 0;
	$pool = array_values( array_filter( $usable, static fn( $c ) => $c['rank'] === $best ) );
	if ( ! $pool ) {
		$counts['failed']++;
		WP_CLI::log( "  FAILED   $about: no copy can be used" );
		foreach ( $notes as $note ) {
			WP_CLI::log( "           $note" );
		}
		continue;
	}
	// What a copy says about the entry, leaving out what changes while it waits for its webhooks (read, starred).
	$content = static fn( array $c ): array => array_diff_key( $c['ready'], array_flip( array( 'date_updated', 'is_read', 'is_starred' ) ) );
	foreach ( $pool as $copy ) {
		if ( ! $same( $content( $copy ), $content( $pool[0] ) ) ) {
			$counts['failed']++;
			WP_CLI::log( "  FAILED   $about: its copies differ (" . implode( ', ', array_column( $pool, 'file' ) ) . '): leave only the right one in the folder' );
			continue 2;
		}
	}
	$use = $pool[0];
	// Copied text that disagrees with the email's HTML is worth a look. (Text without line breaks always differs.)
	foreach ( $usable as $copy ) {
		if ( 1 === $copy['rank'] && 2 === $best && ! $same( $content( $copy ), $content( $use ) ) ) {
			$notes[] = "the copied text in {$copy['file']} differs from the email's HTML, which is used";
		}
	}
	$entry  = $use['ready'];
	$notes  = array_merge( $notes, $use['notes'] );
	$about  = sprintf( '#%d (form %d, %s, %d field value%s, from %s)', $id, $formId, $entry['date_created'], $use['values'], 1 === $use['values'] ? '' : 's', $from );
	$detail = static function () use ( $notes ): void {
		foreach ( $notes as $note ) {
			WP_CLI::log( "           $note" );
		}
	};

	if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $entry_table WHERE id = %d", $id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts['exists']++;
		$empty = ! $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $meta_table WHERE entry_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		WP_CLI::log( "  exists   $about: an entry with this id is already there, left alone" . ( $empty ? '. It has NO field values: if a run stopped part-way, check it, delete it, and run again' : '' ) );
		continue;
	}
	if ( ! $apply ) {
		$counts['would']++;
		WP_CLI::log( "  would    $about" );
		$detail();
		continue;
	}

	// All of it or none of it: the row under its own id, then Gravity Forms writes the rest, then it is read back.
	$entry['status'] = in_array( $entry['status'] ?? '', array( 'active', 'spam', 'trash' ), true ) ? $entry['status'] : 'active';
	$wpdb->query( 'START TRANSACTION' );
	$inserted = $wpdb->insert( $entry_table, array( 'id' => $id, 'form_id' => $formId, 'date_created' => $entry['date_created'], 'ip' => (string) ( $entry['ip'] ?? '' ), 'status' => $entry['status'] ), array( '%d', '%d', '%s', '%s', '%s' ) );
	if ( ! $inserted ) {
		$why = $redact( (string) $wpdb->last_error );
		$wpdb->query( 'ROLLBACK' );
		$counts['failed']++;
		WP_CLI::log( "  FAILED   $about: the database refused the entry ($why)" );
		$detail();
		continue;
	}
	$restoring = $id;
	// As no one: Gravity Forms makes the user running this the entry's creator when the copy has none (print_r() writes
	// "no one" as ""), which would make an anonymous entry the admin's.
	$user = get_current_user_id();
	wp_set_current_user( 0 );
	try {
		$result = GFAPI::update_entry( $entry, $id );
	} catch ( Throwable $e ) {
		$result = new WP_Error( 'thrown', get_class( $e ) . ' thrown' );
	} finally {
		wp_set_current_user( $user );
	}
	$wrong = array();
	if ( ! is_wp_error( $result ) ) {
		// What Gravity Forms saved, against the copy. It turns empty or 0 values of these columns into NULL, sets its
		// own date_updated, fills in the site's currency when there is none, and tidies the user agent.
		$saved = GFAPI::get_entry( $id );
		foreach ( $entry as $key => $value ) {
			$key = (string) $key;
			if ( 'date_updated' === $key || in_array( $key, $use['gone'], true ) || ( 'currency' === $key && '' === $value ) ) {
				continue;
			}
			if ( in_array( $key, array( 'post_id', 'payment_status', 'payment_date', 'payment_amount', 'transaction_id', 'is_fulfilled', 'created_by', 'transaction_type', 'source_id' ), true ) && empty( $value ) ) {
				$value = '';
			}
			if ( 'user_agent' === $key ) {
				$value = sanitize_text_field( $value );
			}
			if ( is_wp_error( $saved ) || ! $same( $value, $saved[ $key ] ?? '' ) ) {
				$wrong[] = $key;
			}
		}
	}
	$note = is_wp_error( $result ) || $wrong ? null : GFAPI::add_note( $id, 0, 'Gravity Forms CiviCRM Form Processor', sprintf( 'Put back on %s by %s, from the copy in the webhook failure email (%s): the entry had been deleted after a failed webhook was recorded as sent. Its webhooks have not been sent again.', wp_date( 'Y-m-d H:i' ), $who, $use['file'] ) );
	if ( is_wp_error( $result ) || $wrong || ! $note || is_wp_error( $note ) ) {
		$remove( $id );
		$restoring = 0;
		$counts['failed']++;
		$why = is_wp_error( $result ) ? 'Gravity Forms refused it (' . $result->get_error_message() . ')' : ( $wrong ? 'Gravity Forms did not save these as the copy has them: ' . implode( ', ', $wrong ) : 'its note could not be added' );
		WP_CLI::log( "  FAILED   $about: $why; nothing of it was kept" );
		$detail();
		continue;
	}
	$wpdb->query( 'COMMIT' );
	$restoring = 0;
	$counts['restored']++;
	WP_CLI::log( "  restored $about" );
	$detail();
}
if ( $apply ) {
	$wpdb->suppress_errors( false );
}

// The ids in the range that have no copy here and aren't on the site: an email not copied yet, or a copy whose id
// couldn't be read (warned about above).
if ( $range ) {
	$known = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $entry_table WHERE id BETWEEN %d AND %d", $range[0], $range[1] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$known = array_unique( array_merge( $known, array_keys( $byId ) ) );
	sort( $known );
	$gaps = array();
	$next = $range[0];
	foreach ( array_merge( array_filter( $known, static fn( $id ) => $id >= $range[0] && $id <= $range[1] ), array( $range[1] + 1 ) ) as $id ) {
		if ( $id > $next ) {
			$gaps[]             = $id - 1 > $next ? "#$next-" . ( $id - 1 ) : "#$next";
			$counts['missing'] += $id - $next;
		}
		$next = max( $next, $id + 1 );
	}
	if ( $gaps ) {
		WP_CLI::log( '  missing  ' . implode( ', ', $gaps ) . ': no copy in the folder, and not on the site' );
	}
}

WP_CLI::success(
	sprintf(
		'%s %d; already there %d; outside the range %d; failed %d%s.',
		$apply ? 'Put back' : 'Would put back',
		$apply ? $counts['restored'] : $counts['would'],
		$counts['exists'],
		$counts['skipped'],
		$counts['failed'],
		$range ? "; missing {$counts['missing']}" : ''
	)
);
if ( $apply && $counts['restored'] ) {
	WP_CLI::log( 'Nothing was sent. Before "Send webhooks again", see the README: it sends every active webhook of each entry\'s form, and "Delete entries after Webhooks" deletes entries again once theirs succeed.' );
}
