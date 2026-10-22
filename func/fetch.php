<?php
/*
 * Upload from URL (E3, docs/features/specs/2026-10-22-001-e3-upload-from-url-design.md):
 * the request file, the address checks and the file name of a fetch. Run
 * by func/fetch.sh as root with the panel's PHP CLI; no dependencies.
 *
 * Usage:
 *   php fetch.php validate REQUEST            checks the request file (§4.1); prints nothing
 *   php fetch.php get REQUEST KEY             prints the url or the name of the request
 *   php fetch.php display                     prints the URL without user information and query
 *   php fetch.php check PRIVATE [OWN...]      prints "SCHEME HOST PORT ADDRESSES" of an allowed URL
 *                                             (ADDRESSES comma-separated, IPv6 in brackets)
 *   php fetch.php config REQUEST              prints the curl config of the URL (url, headers, credentials)
 *   php fetch.php location                    prints the absolute URL of the redirect FETCH_LOCATION
 *   php fetch.php name HEADERS                prints the file name of a finished fetch
 *
 * URLs come in the environment (FETCH_URL, FETCH_LOCATION), never as
 * arguments: every process can read the command lines of the others, and
 * signed URLs carry tokens.
 *
 * Exit codes: 0 ok, 2 invalid (message on stderr), 3 the host does not
 * resolve, 4 the address is blocked.
 */

const REQUEST_MAX = 65536;
const URL_MAX = 8192;
const LINE_MAX = 8192;
const HEADERS_MAX = 50;
const REFUSED_HEADERS = ["host", "content-length", "transfer-encoding", "connection", "range"];

/** Ranges never fetched unless the request allows private addresses (§6.4) */
const BLOCKED = [
	"0.0.0.0/8",
	"10.0.0.0/8",
	"100.64.0.0/10",
	"127.0.0.0/8",
	"169.254.0.0/16",
	"172.16.0.0/12",
	"192.0.0.0/24",
	"192.0.2.0/24",
	"192.88.99.0/24",
	"192.168.0.0/16",
	"198.18.0.0/15",
	"198.51.100.0/24",
	"203.0.113.0/24",
	"224.0.0.0/4",
	"240.0.0.0/4",
	"::/128",
	"::1/128",
	"fc00::/7",
	"fe80::/10",
	"ff00::/8",
	"2001:db8::/32",
];

function env(string $name): string {
	return (string) getenv($name);
}

function fail(string $message, int $code = 2): never {
	fwrite(STDERR, $message . "\n");
	exit($code);
}

/**
 * The directives of a request file, validated.
 *
 * @return array{url: string, name: string, headers: list<array{0: string, 1: string}>, basic: ?string, bearer: ?string}
 */
function request(string $file): array {
	$text = @file_get_contents($file, false, null, 0, REQUEST_MAX + 1);
	if ($text === false) {
		fail("the request file cannot be read");
	}
	if (strlen($text) > REQUEST_MAX) {
		fail("the request is too large");
	}
	$request = ["url" => "", "name" => "", "headers" => [], "basic" => null, "bearer" => null];
	foreach (preg_split('/\n/', rtrim($text, "\n")) as $line) {
		if ($line === "") {
			continue;
		}
		if (strlen($line) > LINE_MAX || preg_match('/[\x00\r]/', $line)) {
			fail("a line of the request is not valid");
		}
		[$key, $value] = array_pad(explode(" ", $line, 2), 2, "");
		switch ($key) {
			case "url":
				if ($request["url"] !== "") {
					fail("the request has more than one url");
				}
				$request["url"] = url($value);
				break;
			case "name":
				if ($request["name"] !== "" || !valid_name($value)) {
					fail("invalid file name");
				}
				$request["name"] = $value;
				break;
			case "header":
				if (count($request["headers"]) >= HEADERS_MAX) {
					fail("more than " . HEADERS_MAX . " headers");
				}
				if (!preg_match('/^([!#$%&\'*+.^_`|~0-9A-Za-z-]+):[ \t]*(.*)$/', $value, $m)) {
					fail("invalid header");
				}
				if (in_array(strtolower($m[1]), REFUSED_HEADERS, true)) {
					fail("the header {$m[1]} cannot be set");
				}
				$request["headers"][] = [$m[1], rtrim($m[2], " \t")];
				break;
			case "basic":
			case "bearer":
				if ($request["basic"] !== null || $request["bearer"] !== null) {
					fail("more than one authentication");
				}
				if ($value === "" || ($key === "basic" && !str_contains($value, ":"))) {
					fail("invalid $key authentication");
				}
				$request[$key] = $value;
				break;
			default:
				fail("unknown directive in the request");
		}
	}
	if ($request["url"] === "") {
		fail("the request has no url");
	}

	return $request;
}

/** A URL of the request or of a redirect: http(s), a host, no user information */
function url(string $url): string {
	if ($url === "" || strlen($url) > URL_MAX || preg_match('/[\s\x00-\x1f\x7f]/', $url)) {
		fail("invalid url");
	}
	$parts = parse_url($url);
	if (
		$parts === false ||
		!in_array(strtolower($parts["scheme"] ?? ""), ["http", "https"], true) ||
		($parts["host"] ?? "") === ""
	) {
		fail("only http and https URLs are supported");
	}
	if (isset($parts["user"]) || isset($parts["pass"])) {
		fail("put the user name and password in the authentication, not in the URL");
	}
	if (isset($parts["port"]) && ($parts["port"] < 1 || $parts["port"] > 65535)) {
		fail("invalid port");
	}

	return $url;
}

function valid_name(string $name): bool {
	return $name !== "" &&
		$name !== "." &&
		$name !== ".." &&
		strlen($name) <= 255 &&
		!preg_match('/[\/\x00-\x1f\x7f]/', $name);
}

function display(string $url): string {
	$parts = parse_url($url);
	if ($parts === false) {
		return "";
	}
	$host = $parts["host"] ?? "";
	$out = strtolower($parts["scheme"] ?? "http") . "://" . $host;
	if (isset($parts["port"])) {
		$out .= ":" . $parts["port"];
	}
	$out .= $parts["path"] ?? "/";
	if (isset($parts["query"])) {
		$out .= "?…";
	}

	return $out;
}

/** Whether ADDRESS (binary) is in CIDR */
function in_cidr(string $address, string $cidr): bool {
	[$net, $bits] = explode("/", $cidr);
	$net = inet_pton($net);
	if ($net === false || strlen($net) !== strlen($address)) {
		return false;
	}
	$bits = (int) $bits;
	$bytes = intdiv($bits, 8);
	if (substr($address, 0, $bytes) !== substr($net, 0, $bytes)) {
		return false;
	}
	$rest = $bits % 8;
	if ($rest === 0) {
		return true;
	}
	$mask = (0xff << (8 - $rest)) & 0xff;

	return (ord($address[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
}

/** The IPv4 address an IPv6 address carries (mapped, NAT64, 6to4), or null */
function embedded_ipv4(string $address): ?string {
	if (strlen($address) !== 16) {
		return null;
	}
	if (in_cidr($address, "::ffff:0:0/96") || in_cidr($address, "64:ff9b::/96")) {
		return substr($address, 12, 4);
	}
	if (in_cidr($address, "2002::/16")) {
		return substr($address, 2, 4);
	}

	return null;
}

/** @param list<string> $own binary addresses of the server */
function blocked(string $address, array $own): bool {
	$v4 = embedded_ipv4($address);
	if ($v4 !== null) {
		return blocked($v4, $own);
	}
	foreach (BLOCKED as $cidr) {
		if (in_cidr($address, $cidr)) {
			return true;
		}
	}

	return in_array($address, $own, true);
}

/** @return list<string> printable addresses of HOST (an address itself, or getent ahosts) */
function resolve(string $host): array {
	if (inet_pton($host) !== false) {
		return [$host];
	}
	$out = [];
	exec("getent ahosts " . escapeshellarg($host) . " 2>/dev/null", $out);
	$addresses = [];
	foreach ($out as $line) {
		$address = strtok($line, " \t");
		if ($address !== false && inet_pton($address) !== false) {
			$addresses[$address] = true;
		}
	}

	return array_keys($addresses);
}

/** @param list<string> $own */
function check(string $url, bool $private, array $own): string {
	url($url);
	$parts = parse_url($url);
	$scheme = strtolower($parts["scheme"]);
	$host = trim(strtolower($parts["host"]), "[]");
	$port = $parts["port"] ?? ($scheme === "https" ? 443 : 80);
	$addresses = resolve($host);
	if ($addresses === []) {
		fail("$host could not be resolved", 3);
	}
	$ownBinary = [];
	foreach ($own as $address) {
		$binary = inet_pton(explode("/", $address)[0]);
		if ($binary !== false) {
			$ownBinary[] = $binary;
		}
	}
	if (!$private) {
		foreach ($addresses as $address) {
			if (blocked(inet_pton($address), $ownBinary)) {
				fail("$host is a private or local address", 4);
			}
		}
	}
	// curl --resolve takes every checked address (it tries them in turn) and
	// IPv6 addresses in brackets
	$pinned = implode(
		",",
		array_map(fn(string $a) => str_contains($a, ":") ? "[$a]" : $a, $addresses),
	);

	return "$scheme $host $port $pinned";
}

function quote(string $value): string {
	return '"' . str_replace(["\\", '"'], ["\\\\", '\\"'], $value) . '"';
}

/** The curl config of one hop: the url, and the headers and credentials while the host is the request's */
function config(array $request, string $url): string {
	$out = "url = " . quote($url) . "\n";
	$host = fn(string $u) => strtolower((string) parse_url($u, PHP_URL_HOST)) .
		":" .
		(parse_url($u, PHP_URL_PORT) ?? "") .
		":" .
		strtolower((string) parse_url($u, PHP_URL_SCHEME));
	if ($host($url) !== $host($request["url"])) {
		return $out;
	}
	foreach ($request["headers"] as [$name, $value]) {
		$out .= "header = " . quote("$name: $value") . "\n";
	}
	if ($request["basic"] !== null) {
		$out .= "user = " . quote($request["basic"]) . "\n";
	}
	if ($request["bearer"] !== null) {
		$out .= "oauth2-bearer = " . quote($request["bearer"]) . "\n";
	}

	return $out;
}

function location(string $base, string $location): string {
	$location = trim($location);
	if (preg_match("/^[a-z][a-z0-9+.-]*:/i", $location)) {
		return url($location);
	}
	$parts = parse_url($base);
	$origin =
		strtolower($parts["scheme"]) .
		"://" .
		$parts["host"] .
		(isset($parts["port"]) ? ":" . $parts["port"] : "");
	if (str_starts_with($location, "//")) {
		return url(strtolower($parts["scheme"]) . ":" . $location);
	}
	if (str_starts_with($location, "/")) {
		return url($origin . $location);
	}
	$path = $parts["path"] ?? "/";
	$dir = substr($path, 0, strrpos($path, "/") + 1);
	if (str_starts_with($location, "?")) {
		return url($origin . $path . $location);
	}

	return url($origin . $dir . $location);
}

/** A name from a header value or a URL segment, cleaned for the file system */
function clean(string $name): string {
	$name = basename(str_replace("\\", "/", $name));
	$name = preg_replace('/[\x00-\x1f\x7f\/]/', "_", $name);
	$name = trim($name);
	if (strlen($name) > 255) {
		$ext = strrchr($name, ".");
		$ext = $ext !== false && strlen($ext) <= 16 ? $ext : "";
		$name = mb_strcut($name, 0, 255 - strlen($ext), "UTF-8") . $ext;
	}

	return valid_name($name) ? $name : "";
}

/** The file name of §5 step 4 from the headers of the last hop and the final URL */
function name(string $headers, string $url): string {
	$text = (string) @file_get_contents($headers);
	$disposition = "";
	foreach (preg_split('/\r?\n/', $text) as $line) {
		if (preg_match('/^content-disposition:\s*(.*)$/i', $line, $m)) {
			$disposition = $m[1];
		}
	}
	if ($disposition !== "") {
		if (preg_match("/filename\\*\\s*=\\s*(?:UTF-8|utf-8)''([^;\\s]+)/", $disposition, $m)) {
			$name = clean(rawurldecode($m[1]));
			if ($name !== "" && mb_check_encoding($name, "UTF-8")) {
				return $name;
			}
		}
		if (
			preg_match('/filename\s*=\s*"((?:[^"\\\\]|\\\\.)*)"/i', $disposition, $m) ||
			preg_match("/filename\s*=\s*([^;\s]+)/i", $disposition, $m)
		) {
			$name = clean(stripcslashes($m[1]));
			if ($name !== "") {
				return $name;
			}
		}
	}
	$path = (string) parse_url($url, PHP_URL_PATH);
	$segment = rawurldecode(substr($path, strrpos($path, "/") + 1));
	$name = clean($segment);

	return $name !== "" ? $name : "download";
}

$command = $argv[1] ?? "";
switch ($command) {
	case "validate":
		request($argv[2] ?? "");
		break;
	case "get":
		echo request($argv[2] ?? "")[$argv[3] ?? "url"] ?? "";
		break;
	case "display":
		echo display(env("FETCH_URL"));
		break;
	case "check":
		echo check(env("FETCH_URL"), ($argv[2] ?? "no") === "yes", array_slice($argv, 3));
		break;
	case "config":
		echo config(request($argv[2] ?? ""), url(env("FETCH_URL")));
		break;
	case "location":
		echo location(env("FETCH_URL"), env("FETCH_LOCATION"));
		break;
	case "name":
		echo name($argv[2] ?? "", env("FETCH_URL"));
		break;
	default:
		fail("usage: fetch.php validate|get|display|check|config|location|name …");
}
