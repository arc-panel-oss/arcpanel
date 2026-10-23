<?php
use Divinity76\CloudflareIpValidator\CloudflareIpValidator;
use function ArcPanel\quoteshellarg\quoteshellarg;

# Return codes
const E_ARGS = 1;
const E_INVALID = 2;
const E_NOTEXIST = 3;
const E_EXISTS = 4;
const E_SUSPENDED = 5;
const E_UNSUSPENDED = 6;
const E_INUSE = 7;
const E_LIMIT = 8;
const E_PASSWORD = 9;
const E_FORBIDEN = 10;
const E_FORBIDDEN = 10;
const E_DISABLED = 11;
const E_PARSING = 12;
const E_DISK = 13;
const E_LA = 14;
const E_CONNECT = 15;
const E_FTP = 16;
const E_DB = 17;
const E_RRD = 18;
const E_UPDATE = 19;
const E_RESTART = 20;
const E_API_DISABLED = 21;

if (!function_exists("tohtml")) {
	function tohtml(string|int|float|bool|null $str): string {
		if ($str === null || $str === "") {
			return "";
		}
		if (is_int($str) || is_float($str)) {
			return (string) $str;
		}
		if (is_bool($str)) {
			return $str ? "1" : "0";
		}

		return htmlentities(
			$str,
			ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED | ENT_HTML5,
			"UTF-8",
			true,
		);
	}
}

/**
 * Looks for a code equivalent to "exit_code" to use in http_code.
 *
 * @param int $exit_code
 * @param int $default
 * @return int
 */
function exit_code_to_http_code(int $exit_code, int $default = 400): int {
	switch ($exit_code) {
		case 0:
			return 200;
		case E_ARGS:
			// return 500;
			return 400;
		case E_INVALID:
			return 422;
		// case E_NOTEXIST:
		// 	return 404;
		// case E_EXISTS:
		// 	return 302;
		case E_PASSWORD:
			return 401;
		case E_SUSPENDED:
		case E_UNSUSPENDED:
		case E_FORBIDEN:
		case E_FORBIDDEN:
		case E_API_DISABLED:
			return 401;
		// return 403;
		case E_DISABLED:
			return 400;
		// return 503;
	}

	return $default;
}

function check_local_ip($addr) {
	if (in_array($addr, [$_SERVER["SERVER_ADDR"], "127.0.0.1"], true)) {
		return true;
	} else {
		return false;
	}
}

function get_real_user_ip() {
	$ip = "";

	if (
		!empty($_SERVER["REMOTE_ADDR"]) &&
		filter_var($_SERVER["REMOTE_ADDR"], FILTER_VALIDATE_IP)
	) {
		$ip = $_SERVER["REMOTE_ADDR"];
	}

	if (
		!empty($_SERVER["HTTP_CF_CONNECTING_IP"]) &&
		filter_var($_SERVER["HTTP_CF_CONNECTING_IP"], FILTER_VALIDATE_IP) &&
		!empty($ip) &&
		CloudflareIpValidator::isCloudflareIp($ip)
	) {
		$ip = $_SERVER["HTTP_CF_CONNECTING_IP"];
	}

	// Handling IPv4-mapped IPv6 address
	if (strpos($ip, ":") === 0 && strpos($ip, ".") > 0) {
		$ip = substr($ip, strrpos($ip, ":") + 1); // Strip IPv4 Compatibility notation
	}
	return $ip;
}

/**
 * Create a history log using 'v-log-action' script.
 *
 * @param string $message The message for log.
 * @param string $category A category for log. Ex: Auth, Firewall, API...
 * @param string $level Info|Warning|Error.
 * @param string $user A username for save in the user history ou 'system' to save in ArcPanel history.
 * @return int The script result code.
 */
function arc_add_history_log($message, $category = "System", $level = "Info", $user = "system") {
	//$message = ucfirst($message);
	//$message = str_replace("'", "`", $message);
	$category = ucfirst(strtolower($category));
	$level = ucfirst(strtolower($level));

	$command_args =
		quoteshellarg($user) .
		" " .
		quoteshellarg($level) .
		" " .
		quoteshellarg($category) .
		" " .
		quoteshellarg($message);
	exec(ARCPANEL_CMD . "v-log-action " . $command_args, $output, $return_var);
	unset($output);

	return $return_var;
}

function get_hostname() {
	$badValues = [
		false,
		null,
		0,
		"",
		"localhost",
		"127.0.0.1",
		"::1",
		"0000:0000:0000:0000:0000:0000:0000:0001",
	];
	$ret = gethostname();
	if (in_array($ret, $badValues, true)) {
		throw new Exception("gethostname() failed");
	}
	$ret2 = gethostbyname($ret);
	if (in_array($ret2, $badValues, true)) {
		return $ret;
	}
	$ret3 = gethostbyaddr($ret2);
	if (in_array($ret3, $badValues, true)) {
		return $ret2;
	}
	return $ret3;
}

function display_title($tab) {
	$array1 = ["{{page}}", "{{hostname}}", "{{ip}}", "{{appname}}"];
	$array2 = [$tab, get_hostname(), $_SERVER["REMOTE_ADDR"], $_SESSION["APP_NAME"]];
	return str_replace($array1, $array2, $_SESSION["TITLE"]);
}

/**
 * Record a command run through REST API v1 in the audit log of the server
 * (E23, docs/features/specs/2026-10-23-002-e23-audit-log-design.md §5.3):
 * the actor, the command as the action api_v1.<command> and the exit code
 * as the result. The event is written by v-add-sys-audit-event after the
 * answer is sent; read commands (v-list-*) are not recorded.
 *
 * @param string|null $object_user The user argument of the command, when the key names its position.
 */
function api_audit(
	string $command,
	int $exit_code,
	int $status,
	string $user,
	string $role,
	?string $key,
	?string $object_user,
): void {
	if (str_starts_with($command, "v-list-")) {
		return;
	}
	$result = match (true) {
		$exit_code === 0 => "ok",
		$exit_code === E_FORBIDDEN,
		$exit_code === E_PASSWORD,
		$exit_code === E_API_DISABLED
			=> "denied",
		in_array($exit_code, [E_ARGS, E_INVALID, E_NOTEXIST, E_EXISTS], true) => "invalid",
		default => "failed",
	};
	$request_id = (string) ($_SERVER["HTTP_X_REQUEST_ID"] ?? "");
	$agent = (string) ($_SERVER["HTTP_USER_AGENT"] ?? "");
	$ip = get_real_user_ip();
	$event = [
		"v" => 1,
		"id" => api_audit_ulid(),
		"request_id" =>
			preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $request_id) === 1
				? $request_id
				: bin2hex(random_bytes(16)),
		"channel" => "api_v1",
		"actor" => [
			"user" => $user,
			"role" => in_array($role, ["admin", "reseller", "user", "dns-cluster"], true)
				? $role
				: "",
			"ip" => $ip === "" ? null : $ip,
			"key" => $key,
			"ua" => $agent === "" ? null : substr(hash("sha256", $agent), 0, 16),
		],
		"effective_user" => $user,
		"action" => "api_v1." . strtolower($command),
		"object" =>
			$object_user === null || $object_user === ""
				? null
				: ["type" => "user", "user" => $object_user, "name" => $object_user],
		"result" => $result,
		"status" => $status,
		"fields" => [],
	];
	register_shutdown_function(function () use ($event, $command): void {
		if (function_exists("fastcgi_finish_request")) {
			fastcgi_finish_request();
		}
		$file = tempnam(sys_get_temp_dir(), "arc");
		if ($file === false) {
			error_log("ArcPanel audit log: no temporary file for the event of $command");
			return;
		}
		chmod($file, 0600);
		file_put_contents(
			$file,
			json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		);
		exec(ARCPANEL_CMD . "v-add-sys-audit-event " . quoteshellarg($file), $output, $code);
		@unlink($file);
		if ($code !== 0) {
			error_log(
				"ArcPanel audit log: the event of $command could not be written (exit $code)",
			);
		}
	});
}

/** A ULID: 48 bits of milliseconds and 80 random bits in Crockford base32. */
function api_audit_ulid(): string {
	$alphabet = "0123456789ABCDEFGHJKMNPQRSTVWXYZ";
	$time = (int) floor(microtime(true) * 1000);
	$out = "";
	for ($i = 0; $i < 10; $i++) {
		$out = $alphabet[$time % 32] . $out;
		$time = intdiv($time, 32);
	}
	$bits = "";
	foreach (str_split(random_bytes(10)) as $byte) {
		$bits .= str_pad(decbin(ord($byte)), 8, "0", STR_PAD_LEFT);
	}
	foreach (str_split($bits, 5) as $chunk) {
		$out .= $alphabet[bindec($chunk)];
	}
	return $out;
}
