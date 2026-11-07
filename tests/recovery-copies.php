<?php
// Makes the copies tests/run.sh gives bin/recover-entries.php, from the failure emails the sandbox kept: an email's
// HTML; text copied from it (the HTML as a mail app shows it: tags gone, entities decoded); a copy cut off part-way,
// with another email after it; an "$entry" part copied on its own, indented; two copies of one entry that differ; and
// "$entry" parts that lost their line breaks, as some mail apps show the email ("Array ( [id] => 1681 ..."), one of
// them cut off.
//
//   php tests/recovery-copies.php <mail.jsonl> <folder> <entry 1> ... <entry 8>

list( , $mail, $dir, $r1, $r2, $r3, $r4, $r5, $r6, $r7, $r8 ) = $argv;

// Each email by the id of the entry in its "$entry" part.
$emails = array();
foreach ( file( $mail ) as $line ) {
	$message = json_decode( $line, true )['message'] ?? '';
	if ( preg_match( '#\$entry</strong></p>\s*<code[^>]*>\s*Array\s*\(\s*\[id\] =&gt; (\d+)#', $message, $m ) ) {
		$emails[ $m[1] ] = $message;
	}
}
foreach ( array( $r1, $r2, $r3, $r4, $r5, $r6, $r7, $r8 ) as $id ) {
	if ( ! isset( $emails[ $id ] ) ) {
		fwrite( STDERR, "no failure email for entry $id\n" );
		exit( 1 );
	}
}
$copied = static fn( string $html ): string => html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

file_put_contents( "$dir/email.html", $emails[ $r1 ] );
file_put_contents( "$dir/copied.txt", $copied( $emails[ $r1 ] ) );

// Entry 2's email clipped in the middle of its entry, as Gmail clips a long one, and entry 3's after it.
$text = $copied( $emails[ $r2 ] );
$at   = strpos( $text, "\n    [3] => ", strpos( $text, '$entry' ) );
file_put_contents( "$dir/r2-cut-r3.txt", substr( $text, 0, $at ) . "\n\n[Message clipped]  View entire message\n\n" . $copied( $emails[ $r3 ] ) );

// An entry's "$entry" part on its own, as copied text.
$entry_part = static function ( string $html ) use ( $copied ): string {
	$text  = $copied( $html );
	$start = strpos( $text, "\n", strpos( $text, '$entry' ) ) + 1;
	return trim( substr( $text, $start, strpos( $text, '$form', $start ) - $start ), "\n" );
};
// Entry 4's, every line indented 4 more spaces.
file_put_contents( "$dir/entry-only.txt", preg_replace( '/^/m', '    ', $entry_part( $emails[ $r4 ] ) ) . "\n" );
// Entries 7's and 8's without line breaks: every run of spaces and line breaks one space. Entry 8's is cut off.
$flat = static fn( string $html ): string => trim( preg_replace( '/[ \t\r\n]+/', ' ', $entry_part( $html ) ) );
file_put_contents( "$dir/r7-flat.txt", $flat( $emails[ $r7 ] ) );
file_put_contents( "$dir/r8-flat-cut.txt", substr( $flat( $emails[ $r8 ] ), 0, -40 ) );

file_put_contents( "$dir/r5.txt", $copied( $emails[ $r5 ] ) );

// Entry 6 in two files that disagree on its email address.
file_put_contents( "$dir/r6-a.txt", $copied( $emails[ $r6 ] ) );
file_put_contents( "$dir/r6-b.txt", str_replace( 'r6@example.com', 'r6-changed@example.com', $copied( $emails[ $r6 ] ) ) );
