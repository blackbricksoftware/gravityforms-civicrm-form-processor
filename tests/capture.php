<?php
/**
 * SANDBOX ONLY: a stand-in for CiviCRM's REST endpoint, run by tests/run.sh as `php -S 127.0.0.1:<port> capture.php`
 * from a scratch folder (CAPTURE_DIR). Each request is appended to CAPTURE_DIR/captured.jsonl as {method, uri, body}.
 * The answer is CAPTURE_DIR/answer.json, {"code": 200, "body": "..."}, when it exists, else 200 with CiviCRM's usual
 * success: {"is_error":0,"values":[]}.
 */
$dir = getenv('CAPTURE_DIR') ?: __DIR__;
file_put_contents(
	$dir . '/captured.jsonl',
	json_encode(array(
		'method' => $_SERVER['REQUEST_METHOD'] ?? '',
		'uri'    => $_SERVER['REQUEST_URI'] ?? '',
		'body'   => file_get_contents('php://input'),
	)) . "\n",
	FILE_APPEND
);
$answer = is_file($dir . '/answer.json') ? json_decode((string) file_get_contents($dir . '/answer.json'), true) : null;
http_response_code((int) ($answer['code'] ?? 200));
header('Content-Type: application/json');
echo $answer['body'] ?? '{"is_error":0,"values":[]}';
