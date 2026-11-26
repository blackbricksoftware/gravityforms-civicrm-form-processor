<?php
// What src/Redact.php masks in the copies of a webhook this plugin prints (the failure email, the log), and what it
// leaves alone. tests/run.sh runs it with WP-CLI in the sandbox site (wp eval-file), so it tests the class as the zip
// ships it, with WordPress's own WP_Error; `php tests/redact.php` runs it on its own, against src/, without the
// WP_Error check. Prints PASS or FAIL per check, as run.sh does, and exits 1 when any failed.
//
// Every key here is made up, and each starts with "gfcfp-test-": no masked copy may hold that anywhere.

use BlackBrickSoftware\GravityFormsCiviCRMFormProcessor\Redact;

if ( ! class_exists( Redact::class ) ) {
	require dirname( __DIR__ ) . '/src/Redact.php';
}

$fails = 0;
$check = static function ( string $what, $got, $expected ) use ( &$fails ): void {
	if ( $got === $expected ) {
		echo "PASS $what\n";
		return;
	}
	$fails++;
	echo "FAIL $what\n     expected: ", str_replace( "\n", "\n     ", var_export( $expected, true ) ), "\n     got:      ", str_replace( "\n", "\n     ", var_export( $got, true ) ), "\n";
};
$all = array(); // Every masked copy, for the final check.

$api  = 'gfcfp-test-api-key-0123456789-WXYZ'; // An API key: masked, but for its last 4 characters.
$site = 'gfcfp-test-site-key-0123456789';     // The site key: masked whole.

// A webhook as the Webhooks add-on stores it. Its headers and body fields are "generic map" rows: a custom name is in
// custom_key (key "gf_custom"), a name from the add-on's list in key; a typed value in custom_value (value
// "gf_custom"), or a form field's id in value.
$row  = static fn( string $name, string $value ): array => array( 'key' => 'gf_custom', 'custom_key' => $name, 'value' => 'gf_custom', 'custom_value' => $value );
$feed = array(
	'id'         => '7',
	'form_id'    => '3',
	'is_active'  => '1',
	'feed_order' => '0',
	'meta'       => array(
		'feedName'                           => 'To CiviCRM',
		'requestURL'                         => "https://civicrm.example.org/civicrm/ajax/rest?entity=FormProcessor&action=volunteer&api_key=$api&key=$site&json=" . rawurlencode( json_encode( array( 'sequential' => 1, 'api_key' => $api ) ) ) . '#top',
		'requestMethod'                      => 'POST',
		'requestFormat'                      => 'form',
		'requestHeaders'                     => array(
			array( 'key' => 'Authorization', 'custom_key' => '', 'value' => 'gf_custom', 'custom_value' => "Bearer $api" ),
			$row( 'X-Civi-Key', $site ),
			$row( 'X-Civi-Auth', "Bearer $api" ),
			$row( 'Cookie', 'SESS=gfcfp-test-cookie' ),
			$row( 'X-Api-Token', 'gfcfp-test-token-0123456789-QRST' ),
			$row( 'X-Requested-With', 'XMLHttpRequest' ),
		),
		'requestBodyType'                    => 'select_fields',
		'fieldValues'                        => array(
			array( 'key' => 'gf_custom', 'custom_key' => 'json.first_name', 'value' => '1', 'custom_value' => '' ),
			$row( 'api_key', $api ),
			$row( 'json.api_key', $api ),
			$row( 'KEY', $site ),
			$row( 'params[password]', 'gfcfp-test-password' ),
			array( 'key' => 'gf_custom', 'custom_key' => 'api_key', 'value' => '4', 'custom_value' => '' ),
			$row( 'json.note', 'see https://example.org/?token=gfcfp-test-token' ),
		),
		'CiviCRMWebhookFailureNotification'   => '1',
		'CiviCRMWebhookFailureNotificationTo' => 'alerts@example.org',
	),
	'addon_slug' => 'gravityformswebhooks',
);
$expected                                 = $feed;
$expected['meta']['requestURL']           = 'https://civicrm.example.org/civicrm/ajax/rest?entity=FormProcessor&action=volunteer&api_key=****WXYZ&key=****&json=' . rawurlencode( '{"sequential":1,"api_key":"****WXYZ"}' ) . '#top';
$masked_headers                           = array( 'Bearer ****WXYZ', '****', 'Bearer ****WXYZ', '****', '****', 'XMLHttpRequest' );
$masked_fields                            = array( '', '****WXYZ', '****WXYZ', '****', '****', '', 'see https://example.org/?token=****' );
foreach ( $masked_headers as $i => $value ) {
	$expected['meta']['requestHeaders'][ $i ]['custom_value'] = $value;
}
foreach ( $masked_fields as $i => $value ) {
	$expected['meta']['fieldValues'][ $i ]['custom_value'] = $value;
}
$all[] = $got = Redact::feed( $feed );
$check( 'a webhook: the secrets in its URL, headers and body fields masked; names, field ids and the rest kept', $got, $expected );
$check( '  masking it again changes nothing', Redact::feed( $got ), $got );
$check( '  something that is not a webhook is left alone', Redact::feed( 'no feed' ), 'no feed' );

// One value at a time.
$check( 'an API key that is short keeps no characters', Redact::mask( 'api_key', 'gfcfp-test-1' ), '****' );
$check( 'Basic authorization is masked whole (its end is the end of the password)', Redact::mask( 'Authorization', 'Basic ' . base64_encode( 'user:gfcfp-test-pass-0123456789' ) ), 'Basic ****' );
$check( 'last characters that are not letters or digits are not shown', Redact::mask( 'api_key', 'gfcfp-test-0123456789+/==' ), '****' );
$check( 'an empty value stays empty, so a missing key shows', Redact::mask( 'X-Civi-Key', '' ), '' );
$check( 'a value masked already is left as it is', Redact::mask( 'api_key', '****WXYZ' ), '****WXYZ' );
$check( 'a list is masked item by item', Redact::mask( 'set-cookie', array( 'a=gfcfp-test-a', 'b=gfcfp-test-b' ) ), array( '****', '****' ) );
$check( 'header names: secret ones', array_map( array( Redact::class, 'isSecretHeader' ), array( 'Authorization', 'x-civi-key', 'X-CIVI-AUTH', 'Cookie', 'X-Api-Token', 'X-My-Secret', 'Proxy-Authorization' ) ), array_fill( 0, 7, true ) );
$check( 'header names: the rest', array_map( array( Redact::class, 'isSecretHeader' ), array( 'X-Requested-With', 'Content-Type', 'Accept' ) ), array_fill( 0, 3, false ) );

// A request's body, as this plugin logs it, and CiviCRM's answer.
$request = array(
	'entity'  => 'Contact',
	'Api_Key' => $api,
	'key'     => $site,
	'_authx'  => "Bearer $api",
	'json'    => json_encode( array( 'first_name' => 'Ada', 'site_key' => $site, 'contact' => array( 'password' => 'gfcfp-test-pw', 'monkey' => 'banana', 'keyword' => 'kept' ) ) ),
	'params'  => array( 'token' => 'gfcfp-test-t', 'nested' => array( 'secret' => array( 'gfcfp-test-a', 'gfcfp-test-b' ) ) ),
	'monkey'  => 'kept',
	'keys'    => 'kept',
	'url'     => "https://civicrm.example.org/rest?api_key=$api",
	3         => 'a field id, kept',
);
$all[] = $got = Redact::data( $request );
$check( 'request data: secret parameters masked at any depth, inside json and URLs too, whatever their case', $got, array(
	'entity'  => 'Contact',
	'Api_Key' => '****WXYZ',
	'key'     => '****',
	'_authx'  => 'Bearer ****',
	'json'    => '{"first_name":"Ada","site_key":"****","contact":{"password":"****","monkey":"banana","keyword":"kept"}}',
	'params'  => array( 'token' => '****', 'nested' => array( 'secret' => array( '****', '****' ) ) ),
	'monkey'  => 'kept',
	'keys'    => 'kept',
	'url'     => 'https://civicrm.example.org/rest?api_key=****WXYZ',
	3         => 'a field id, kept',
) );
$check( '  json with nothing secret in it is left as it was written', Redact::data( array( 'json' => '{"first_name": "Ada"}' ) ), array( 'json' => '{"first_name": "Ada"}' ) );
$check( '  CiviCRM\'s answer, an error, is left alone', Redact::data( array( 'is_error' => 1, 'error_message' => 'Mandatory key(s) missing: contact_type' ) ), array( 'is_error' => 1, 'error_message' => 'Mandatory key(s) missing: contact_type' ) );

// WordPress's answer to a webhook's request. Its http_response object holds the URL as sent, with the keys in it.
$sent     = new class( "https://civicrm.example.org/civicrm/ajax/rest?api_key=$api&key=$site" ) {
	public function __construct( private string $url ) {}
	public function get_response_object(): object {
		return (object) array( 'url' => $this->url, 'raw' => "HTTP/1.1 403 Forbidden\r\nSet-Cookie: SESS=gfcfp-test-sess" );
	}
};
$response = array(
	'headers'       => new ArrayIterator( array( 'content-type' => 'text/html', 'set-cookie' => array( 'SESS=gfcfp-test-sess; path=/', 'x=gfcfp-test-x' ) ) ),
	'body'          => '<html><body>403 Forbidden</body></html>',
	'response'      => array( 'code' => 403, 'message' => 'Forbidden' ),
	'cookies'       => array( (object) array( 'name' => 'SESS', 'value' => 'gfcfp-test-sess' ) ),
	'filename'      => null,
	'http_response' => $sent,
);
$all[] = $got = Redact::response( $response );
$check( 'an HTTP answer: status and body kept, cookies masked, the URL it went to masked, WordPress\'s object left out', $got, array(
	'headers'  => array( 'content-type' => 'text/html', 'set-cookie' => array( '****', '****' ) ),
	'body'     => '<html><body>403 Forbidden</body></html>',
	'response' => array( 'code' => 403, 'message' => 'Forbidden' ),
	'cookies'  => array( 'SESS=****' ),
	'filename' => null,
	'url'      => 'https://civicrm.example.org/civicrm/ajax/rest?api_key=****WXYZ&key=****',
) );
$check( '  masking it again changes nothing (a failure\'s data holds it masked, and the email masks it again)', Redact::response( $got ), $got );

// The entry's feed statuses, as Gravity Forms keeps them and Entries logs them: a failed one holds the failure's data,
// before 2.3.2 with WordPress's whole answer in it.
$statuses = array(
	array( 'timestamp' => 1, 'status' => 'failed', 'code' => 'gravityforms_civicrm_form_processor_http_status', 'message' => 'The server answered with HTTP 403.', 'data' => array( 'response' => $response, 'response_data' => null, 'header' => 'h', 'email' => 'e' ) ),
	array( 'timestamp' => 2, 'status' => 'success', 'code' => '', 'message' => '', 'data' => '' ),
);
$expected                            = $statuses;
$expected[0]['data']['response']     = Redact::response( $response );
$all[] = $got = Redact::data( $statuses );
$check( 'feed statuses: a failure\'s copy of the answer masked as above, the rest kept', $got, $expected );

// Text: the Webhooks add-on's log of a request (Webhooks::redact_log_message()), and error messages.
$args   = static fn( $body, array $headers ): array => array( 'body' => $body, 'method' => 'POST', 'headers' => $headers, 'sslverify' => true, 'timeout' => 30, 'gravityforms_civicrm_form_processor_check' => 7 );
$prefix = 'GF_Webhooks::process_feed(): Sending webhook request to https://civicrm.example.org/civicrm/ajax/rest?entity=Contact';
$line   = "$prefix&api_key=$api&key=$site; " . print_r( $args( array( 'json' => json_encode( array( 'first_name' => 'Ada', 'api_key' => $api ) ), 'key' => $site ), array( 'Authorization' => "Bearer $api", 'X-Civi-Key' => $site, 'X-Requested-With' => 'XMLHttpRequest' ) ), true );
$all[]  = $got = Redact::text( $line );
$check( 'the Webhooks add-on\'s log of a form request: URL, headers and body masked', $got,
	"$prefix&api_key=****WXYZ&key=****; " . print_r( $args( array( 'json' => '{"first_name":"Ada","api_key":"****WXYZ"}', 'key' => '****' ), array( 'Authorization' => 'Bearer ****WXYZ', 'X-Civi-Key' => '****', 'X-Requested-With' => 'XMLHttpRequest' ) ), true ) );
$check( '  masking it again changes nothing', Redact::text( $got ), $got );
$line  = "$prefix; " . print_r( $args( json_encode( array( '1' => 'Ada', 'api_key' => $api, 'json' => json_encode( array( 'site_key' => $site ) ) ) ), array( 'X-Civi-Auth' => "Bearer $api", 'Content-Type' => 'application/json' ) ), true );
$all[] = $got = Redact::text( $line );
$check( '  and of a JSON request, JSON inside JSON too', $got,
	"$prefix; " . print_r( $args( '{"1":"Ada","api_key":"****WXYZ","json":"{\"site_key\":\"****\"}"}', array( 'X-Civi-Auth' => 'Bearer ****WXYZ', 'Content-Type' => 'application/json' ) ), true ) );
$texts = array(
	"Failed to authenticate with Bearer $api"                     => 'Failed to authenticate with Bearer ****WXYZ',
	"entity=Contact&json.api_key=$api&key=$site&first_name=Ada"   => 'entity=Contact&json.api_key=****WXYZ&key=****&first_name=Ada',
	'https://admin:gfcfp-test-pw@civicrm.example.org/rest'        => 'https://admin:****@civicrm.example.org/rest',
	"Could not reach https://civicrm.example.org/?key=$site."     => 'Could not reach https://civicrm.example.org/?key=****.',
	"rejected: bearer $api"                                       => 'rejected: bearer ****WXYZ',
	"params[key]=$site&params%5Bapi_key%5D=$api&params[id]=3"     => 'params[key]=****&params%5Bapi_key%5D=****WXYZ&params[id]=3',
	"X-Civi-Key: $site\r\nauthorization: Bearer $api\r\nAccept: */*" => "X-Civi-Key: ****\r\nauthorization: Bearer ****WXYZ\r\nAccept: */*",
);
foreach ( $texts as $text => $masked ) {
	$all[] = $got = Redact::text( $text );
	$check( 'text: "' . str_replace( array( "\r", "\n" ), array( '\r', '\n' ), $masked ) . '"', $got, $masked );
}
$kept = array(
	'CiviCRM reported an error: Mandatory key(s) missing: contact_type',
	'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received',
	'The server answered with HTTP 403.',
	'Bearer tokens are checked by AuthX; the monkey=banana and keyword=x parameters are not secret',
);
$check( 'text with nothing secret in it is left alone', array_map( array( Redact::class, 'text' ), $kept ), $kept );
// Headers as WordPress also takes them, one string, and a header sent twice (an array under its name).
$line  = "$prefix; " . print_r( $args( null, array( 'X-Civi-Key' => array( $site, array( 'gfcfp-test-nested' ) ), 'Accept' => '*/*' ) ), true ) . print_r( array( 'headers' => "Authorization: Bearer $api\r\nX-Civi-Key: $site\r\nAccept: */*" ), true );
$all[] = $got = Redact::text( $line );
$check( '  and of headers written as one string, or an array under a secret name', $got,
	"$prefix; " . print_r( $args( null, array( 'X-Civi-Key' => array( '****', array( '****' ) ), 'Accept' => '*/*' ) ), true ) . print_r( array( 'headers' => "Authorization: Bearer ****WXYZ\r\nX-Civi-Key: ****\r\nAccept: */*" ), true ) );

// WordPress's WP_Error, when a request got no answer: with WordPress loaded (run.sh), not on its own.
if ( class_exists( 'WP_Error' ) ) {
	$error = new WP_Error( 'http_request_failed', "cURL error 7: Failed to connect to https://civicrm.example.org/rest?api_key=$api&key=$site" );
	$error->add_data( array( 'api_key' => $api, 'status' => 0 ) );
	$all[] = $got = Redact::response( $error );
	$check( 'a WP_Error: its code kept, its message and data masked', array( get_class( $got ), $got->get_error_code(), $got->get_error_message(), $got->get_error_data() ),
		array( 'WP_Error', 'http_request_failed', 'cURL error 7: Failed to connect to https://civicrm.example.org/rest?api_key=****WXYZ&key=****', array( 'api_key' => '****WXYZ', 'status' => 0 ) ) );
} else {
	echo "SKIP a WP_Error (WordPress isn't loaded; tests/run.sh runs this with it)\n";
}

$check( 'no masked copy holds any of the keys', substr_count( print_r( $all, true ), 'gfcfp-test-' ), 0 );

if ( $fails ) {
	exit( 1 );
}
