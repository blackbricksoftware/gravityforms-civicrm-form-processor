<?php

namespace BlackBrickSoftware\GravityFormsCiviCRMFormProcessor;

use WP_Error;

/**
 * Copies of a webhook's settings, request and answer with their secrets masked, for the failure email and the log.
 *
 * A webhook to CiviCRM carries its credentials: the API key in an "Authorization: Bearer" or X-Civi-Auth header, the
 * site key in X-Civi-Key, or either one as a parameter of the URL or of the body (api_key, key). Up to 2.3.1 the
 * failure email printed the webhook's settings whole, so each failure sent them by email in clear text, and the debug
 * log held them too. Everything this plugin prints about a webhook now goes through here first.
 *
 * Names stay, so the email still shows which headers and parameters the webhook sends. Values are masked: "****", or
 * for an API key "****" and its last 4 characters, to tell which key it is ("Authorization: Bearer ****abcd"). The site
 * key, passwords, cookies and anything else secret are masked whole. Empty values stay empty, so a missing key shows.
 */
abstract class Redact
{

    /** What a secret is replaced with. */
    public const MASK = '****';

    /**
     * Parameters whose values are secret, in a URL, a body, or the JSON in a json or params parameter. Compared in lower
     * case, by the last part of a dotted or bracketed name, so the body field json.api_key counts as api_key.
     */
    public const PARAMS = ['key', 'api_key', '_authx', 'site_key', 'password', 'pass', 'secret', 'token'];

    /** Headers whose values are secret. So is any header whose name contains key, token, secret, auth, pass or cookie. */
    public const HEADERS = ['authorization', 'proxy-authorization', 'x-civi-auth', 'x-civi-key', 'cookie', 'set-cookie'];

    /** The parameters CiviCRM's REST API reads JSON from: json (API v3) and params (API v4). */
    public const JSON_PARAMS = ['json', 'params'];

    /**
     * Where an API key goes, the secrets that keep their last 4 characters when long enough to be a key. A Basic
     * Authorization header doesn't (its end is the end of the password), nor does the site key.
     */
    private const HINTED = ['authorization', 'x-civi-auth', 'api_key'];

    /** A webhook (a Gravity Forms feed) with the secrets in its URL, headers and body fields masked. */
    public static function feed(mixed $feed): mixed
    {
        if (!is_array($feed) || !is_array($feed['meta'] ?? null)) {
            return $feed;
        }
        foreach ($feed['meta'] as $name => $value) {
            $feed['meta'][$name] = match (true) {
                $name === 'requestURL' && is_string($value) => self::url($value),
                $name === 'requestHeaders' && is_array($value) => self::map($value, true),
                $name === 'fieldValues' && is_array($value) => self::map($value, false),
                is_string($value) => self::text($value),
                default => $value,
            };
        }
        return $feed;
    }

    /**
     * A URL with the values of secret parameters in its query string masked, the JSON in json= or params= included,
     * and any password before its host. Everything else is left as it was written.
     */
    public static function url(string $url): string
    {
        $url = preg_replace('#^([a-z][a-z0-9+.-]*://[^/?\#@:]*:)[^/?\#@]+@#i', '$1' . self::MASK . '@', $url) ?? $url;
        $start = strpos($url, '?');
        if ($start === false) {
            return $url;
        }
        $end = strpos($url, '#', $start);
        $end = $end === false ? strlen($url) : $end;
        return substr($url, 0, $start + 1) . self::query(substr($url, $start + 1, $end - $start - 1)) . substr($url, $end);
    }

    /** A query string (a=1&b=2): each secret parameter's value masked, inside json= and params= too. */
    public static function query(string $query): string
    {
        $pairs = explode('&', $query);
        foreach ($pairs as $i => $pair) {
            $eq = strpos($pair, '=');
            if ($eq === false) {
                continue;
            }
            $name = urldecode(substr($pair, 0, $eq));
            $value = urldecode(substr($pair, $eq + 1));
            if (self::isSecretParam($name)) {
                $pairs[$i] = substr($pair, 0, $eq + 1) . self::mask($name, $value);
            } elseif (self::isJsonParam($name) && ($json = self::json($value)) !== null) {
                $pairs[$i] = substr($pair, 0, $eq + 1) . rawurlencode($json);
            }
        }
        return implode('&', $pairs);
    }

    /**
     * Data: a webhook's request body, or CiviCRM's answer. Each secret parameter's value is masked, at any depth and
     * inside the JSON of a json or params parameter, other text as text() does, and a WordPress HTTP answer within it
     * as response() does.
     */
    public static function data(mixed $data, string $name = ''): mixed
    {
        if ($name !== '' && self::isSecretParam($name)) {
            return self::mask($name, $data);
        }
        if ($data instanceof WP_Error || self::isHttpResponse($data)) {
            return self::response($data);
        }
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = self::data($value, (string) $key);
            }
            return $data;
        }
        if (is_string($data)) {
            return (self::isJsonParam($name) ? self::json($data) : null) ?? self::text($data);
        }
        return $data;
    }

    /** Headers, name => value (a list for a repeated header), with the secret ones masked. */
    public static function headers(mixed $headers): mixed
    {
        if (!is_iterable($headers)) {
            return $headers;
        }
        $masked = [];
        foreach ($headers as $name => $value) {
            $masked[$name] = self::isSecretHeader((string) $name) ? self::mask((string) $name, $value) : $value;
        }
        return $masked;
    }

    /**
     * WordPress's answer to a webhook's request (wp_remote_request()), for printing: its status, headers (cookies
     * masked), body, and the URL it went to, masked. WordPress's own object for it (http_response) is left out: it holds
     * the URL as sent, with any key in its query string, and the raw headers. A WP_Error (no answer) keeps its codes,
     * with its messages and data masked.
     */
    public static function response(mixed $response): mixed
    {
        if ($response instanceof WP_Error) {
            $masked = new WP_Error();
            foreach ($response->get_error_codes() as $code) {
                foreach ($response->get_error_messages($code) as $message) {
                    $masked->add($code, self::text((string) $message));
                }
                foreach ($response->get_all_error_data($code) as $data) {
                    $masked->add_data(self::data($data), $code);
                }
            }
            return $masked;
        }
        if (!self::isHttpResponse($response)) {
            return self::data($response);
        }
        $masked = [];
        foreach ($response as $key => $value) {
            if ($key === 'http_response') {
                $sent = is_object($value) && method_exists($value, 'get_response_object') ? $value->get_response_object() : null;
                if (is_object($sent) && isset($sent->url) && is_string($sent->url)) {
                    $masked['url'] = self::url($sent->url);
                }
                continue;
            }
            $masked[$key] = match ($key) {
                'headers' => self::headers($value),
                // "name=****"; a copy masked already is left as it is.
                'cookies' => is_array($value) ? array_map(static fn($cookie) => is_object($cookie) && isset($cookie->name) ? $cookie->name . '=' . self::MASK
                    : (is_string($cookie) && preg_match('/^(?:[^=]*=)?\*{4}$/', $cookie) ? $cookie : self::MASK), $value) : $value,
                'body' => is_string($value) ? self::text($value) : self::data($value),
                default => self::data($value),
            };
        }
        return $masked;
    }

    /**
     * Text that may hold secrets: a log line, an error message, an answer's body. Masks the values of secret parameters
     * in URLs and in name=value pairs, of secret names in print_r() lines ("[X-Civi-Key] => ...", and every value in
     * such a name's array), in JSON ("api_key": "...") and in header lines ("X-Civi-Key: ...", as in headers written
     * as one string), and the token after "Bearer".
     */
    public static function text(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        // URLs, less the punctuation after them ("... to https://example.org/?key=1; Array").
        $text = preg_replace_callback('#\bhttps?://[^\s"\'<>]+#i', static function (array $m): string {
            $url = rtrim($m[0], ';,.)');
            return self::url($url) . substr($m[0], strlen($url));
        }, $text) ?? $text;
        $text = self::printR($text);
        // JSON, and JSON inside a JSON string (\"api_key\":\"...\").
        $names = implode('|', array_map(static fn(string $name): string => preg_quote($name, '/'), array_merge(self::PARAMS, self::HEADERS)));
        $text = preg_replace_callback('/"(' . $names . ')"(\s*:\s*)"((?:[^"\\\\]|\\\\.)*)"/i', static fn(array $m): string => '"' . $m[1] . '"' . $m[2] . '"' . self::mask($m[1], $m[3]) . '"', $text) ?? $text;
        $text = preg_replace_callback('/\\\\"(' . $names . ')\\\\"(\s*:\s*)\\\\"(.*?)\\\\"/i', static fn(array $m): string => '\\"' . $m[1] . '\\"' . $m[2] . '\\"' . self::mask($m[1], $m[3]) . '\\"', $text) ?? $text;
        // Header lines, at the start of a line or of a print_r() value: headers written as one string
        // ("Authorization: Bearer ...\r\nX-Civi-Key: ..."). "Name::" (a PHP method) is not one.
        $text = preg_replace_callback('/^([ \t]*(?:\[[^\]\r\n]*\] => )?)([A-Za-z][A-Za-z0-9_-]*)(:(?!:)[ \t]*)(\S[^\r\n]*?)([ \t]*\r?)$/m', static function (array $m): string {
            return self::isSecretHeader($m[2]) ? $m[1] . $m[2] . $m[3] . self::mask($m[2], $m[4]) . $m[5] : $m[0];
        }, $text) ?? $text;
        // name=value pairs outside a URL (a form body written out), dotted and bracketed names included
        // (json.api_key=..., params[key]=..., params%5Bkey%5D=...).
        $params = implode('|', array_map(static fn(string $name): string => preg_quote($name, '/'), self::PARAMS));
        $text = preg_replace_callback('/(?<![\w-])((?:[\w-]+(?:\.|\[|%5B))*(?:' . $params . ')(?:\]|%5D)?)=([^&\s"\'<>;]+)/i', static fn(array $m): string => $m[1] . '=' . self::mask(urldecode($m[1]), urldecode($m[2])), $text) ?? $text;
        // A bearer token anywhere else, whatever its case.
        return preg_replace_callback('/\bBearer\s+([A-Za-z0-9._~+\/=-]{8,})/i', static fn(array $m): string => self::mask('authorization', $m[0]), $text) ?? $text;
    }

    /**
     * print_r() output in text: the value of a secret name's line ("[X-Civi-Key] => ..."), and when that value is an
     * array or object (a header sent twice, say), every value in it, down to its closing ")".
     */
    private static function printR(string $text): string
    {
        if (!str_contains($text, '] => ')) {
            return $text;
        }
        $lines = preg_split('/(?<=\n)/', $text);
        $secret = null; // The secret name whose array the lines are in,
        $close = null;  // and the line that closes that array, once its "(" line is seen.
        foreach ($lines as $i => $line) {
            $body = rtrim($line, "\r\n");
            $end = substr($line, strlen($body));
            if ($secret !== null && $close === null) {
                if (preg_match('/^([ \t]*)\($/', $body, $m)) {
                    $close = $m[1] . ')';
                    continue;
                }
                $secret = null; // Not print_r()'s array after all.
            }
            if ($secret !== null) {
                if ($body === $close) {
                    $secret = $close = null;
                } elseif (preg_match('/^([ \t]*\[[^\]\r\n]+\] => )(.*)$/', $body, $m)) {
                    $lines[$i] = $m[1] . (self::isPrintRContainer($m[2]) ? $m[2] : self::mask($secret, $m[2])) . $end;
                } elseif (!preg_match('/^[ \t]*[()]?[ \t]*$/', $body)) {
                    $lines[$i] = preg_replace('/^([ \t]*).*$/', '$1', $body) . self::MASK . $end; // A value's next line.
                }
                continue;
            }
            if (!preg_match('/^([ \t]*\[)([^\]\r\n]+)(\] => )(.*)$/', $body, $m) || !(self::isSecretParam($m[2]) || self::isSecretHeader($m[2]))) {
                continue;
            }
            if (self::isPrintRContainer($m[4])) {
                $secret = $m[2];
                continue;
            }
            $lines[$i] = $m[1] . $m[2] . $m[3] . self::mask($m[2], $m[4]) . $end;
        }
        return implode('', $lines);
    }

    /** Whether a print_r() value opens an array or object ("Array", "Some\Class Object"). */
    private static function isPrintRContainer(string $value): bool
    {
        return $value === 'Array' || preg_match('/^[\w\\\\]+ Object$/', $value) === 1;
    }

    /**
     * A secret value, masked: "****". An Authorization scheme stays in front ("Bearer ****"). Where an API key goes
     * (HINTED), a value of 16 characters or more keeps its last 4, when they are letters, digits, "-" or "_". Empty
     * values, and values masked already, are left as they are; lists are masked item by item.
     */
    public static function mask(string $name, mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(static fn($item) => self::mask($name, $item), $value);
        }
        if ($value === null || $value === '' || $value === false) {
            return $value;
        }
        if (!is_scalar($value)) {
            return self::MASK;
        }
        $value = (string) $value;
        $scheme = '';
        if (preg_match('/^(Bearer|Basic|Digest|Token)\s+(\S.*)$/is', $value, $m)) {
            [$scheme, $value] = [$m[1] . ' ', $m[2]];
        }
        if (str_starts_with($value, self::MASK)) {
            return $scheme . $value;
        }
        $last = substr($value, -4);
        $hint = in_array(self::leaf($name), self::HINTED, true) && strcasecmp($scheme, 'Basic ') !== 0
            && strlen($value) >= 16 && preg_match('/^[A-Za-z0-9_-]{4}$/', $last);
        return $scheme . self::MASK . ($hint ? $last : '');
    }

    /** Whether a parameter's value is secret (PARAMS), by the last part of its name: json.api_key, json[api_key]. */
    public static function isSecretParam(string $name): bool
    {
        return in_array(self::leaf($name), self::PARAMS, true);
    }

    /** Whether a header's value is secret: one of HEADERS, or a name with key, token, secret, auth, pass or cookie in it. */
    public static function isSecretHeader(string $name): bool
    {
        $name = strtolower(trim($name));
        return in_array($name, self::HEADERS, true) || preg_match('/key|token|secret|auth|pass|cookie/', $name) === 1;
    }

    /** Whether a parameter holds JSON for CiviCRM (JSON_PARAMS). */
    private static function isJsonParam(string $name): bool
    {
        return in_array(strtolower(trim($name)), self::JSON_PARAMS, true);
    }

    /** Whether this is an answer from wp_remote_request(). */
    private static function isHttpResponse(mixed $value): bool
    {
        return is_array($value) && array_key_exists('response', $value) && array_key_exists('headers', $value) && array_key_exists('body', $value);
    }

    /** The last part of a name, in lower case: "api_key" for json.api_key or json[api_key]. */
    private static function leaf(string $name): string
    {
        $parts = preg_split('/[.\[\]]+/', strtolower(trim($name)), -1, PREG_SPLIT_NO_EMPTY);
        return $parts ? (string) end($parts) : '';
    }

    /** JSON with its secret parameters masked, or null when it isn't a JSON object or array, or has none. */
    private static function json(string $json): ?string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        $masked = self::data($data);
        return $masked === $data ? null : (string) json_encode($masked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The rows of a "generic map" setting, as the Webhooks add-on stores its headers and body fields: each row's name
     * is its key, or its custom_key when key is "gf_custom"; its value is its custom_value (or a form field's id, in
     * value, which is not secret). A secret row's custom_value is masked; the names stay.
     */
    private static function map(array $rows, bool $headers): array
    {
        foreach ($rows as $i => $row) {
            if (!is_array($row) || !array_key_exists('custom_value', $row)) {
                continue;
            }
            $name = (string) (($row['key'] ?? '') === 'gf_custom' ? ($row['custom_key'] ?? '') : ($row['key'] ?? ''));
            if (self::isSecretParam($name) || ($headers && self::isSecretHeader($name))) {
                $rows[$i]['custom_value'] = self::mask($name, $row['custom_value']);
            } elseif (is_string($row['custom_value'])) {
                $rows[$i]['custom_value'] = self::text($row['custom_value']);
            }
        }
        return $rows;
    }
}
