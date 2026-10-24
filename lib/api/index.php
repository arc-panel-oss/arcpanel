<?php
use function ArcPanel\quoteshellarg\quoteshellarg;

try {
	require_once __DIR__ . "/../vendor/autoload.php";
} catch (Throwable $ex) {
	$errstr =
		"Unable to load required libraries. Please run v-add-sys-dependencies in command line. Error: " .
		$ex->getMessage();
	trigger_error($errstr);
	echo $errstr;
	exit(1);
}

define("ARCPANEL_DIR_BIN", "/usr/local/arcpanel/bin/");
define("ARCPANEL_CMD", "/usr/bin/sudo /usr/local/arcpanel/bin/");

include __DIR__ . "/helpers.php";

/**
 * Displays the error message, checks the proper code and saves a log if needed.
 *
 * @param int $exit_code
 * @param string $message
 * @param bool $add_log
 * @param string $user
 * @return void
 */
function api_error($exit_code, $message, $arc_return, bool $add_log = false, $user = "system") {
	$message = trim(is_array($message) ? implode("\n", $message) : $message);

	// Add log
	if ($add_log) {
		$v_real_user_ip = get_real_user_ip();
		arc_add_history_log("[$v_real_user_ip] $message", "API", "Error", $user);
	}

	// Print the message with http_code and exit_code
	$http_code = $exit_code >= 100 ? $exit_code : exit_code_to_http_code($exit_code);
	header("ArcPanel-Exit-Code: $exit_code");
	http_response_code($http_code);
	if ($arc_return == "code") {
		echo $exit_code;
	} else {
		echo !preg_match("/^Error:/", $message) ? "Error: $message" : $message;
	}
	exit();
}

// Failed authentications per client address before 429, and the window in
// seconds (docs/features/specs/2026-10-16-002 §7.2, as API v2 counts them)
const API_V1_LIMIT_FAILURES = 10;
const API_V1_LIMIT_WINDOW = 60;
const API_V1_LIMIT_DIR = "/usr/local/arcpanel/web-app/storage/framework/api-v1";

/** API v1 answers only with API_V1='yes' (E39 W4 D9). */
function api_v1_enabled(array $settings): bool {
	return ($settings["config"]["API_V1"] ?? "no") === "yes";
}

/** The request comes from the loopback address itself; forwarded headers never count (D10). */
function api_from_loopback(): bool {
	return in_array($_SERVER["REMOTE_ADDR"] ?? "", ["127.0.0.1", "::1"], true);
}

/** API_ALLOWED_IP: "allow-all", or a comma list in which 0.0.0.0 allows every address; an empty address never passes. */
function api_ip_allowed(array $settings, string $ip): bool {
	$allowed = (string) ($settings["config"]["API_ALLOWED_IP"] ?? "");
	if ($allowed === "allow-all") {
		return true;
	}
	if ($ip === "") {
		return false;
	}
	$list = array_map("trim", explode(",", $allowed));

	return in_array("0.0.0.0", $list, true) || in_array($ip, $list, true);
}

/**
 * arg1 … arg13 in order. A missing argument before a present one is refused,
 * so a gap cannot shift the other arguments into new positions.
 *
 * @return array<string, string>
 */
function api_command_args(array $request_data, string $arc_return): array {
	$args = [];
	$gap = false;
	for ($i = 1; $i <= 13; $i++) {
		if (!isset($request_data["arg{$i}"])) {
			$gap = true;
			continue;
		}
		if ($gap || !is_scalar($request_data["arg{$i}"])) {
			api_error(
				E_ARGS,
				"Invalid arguments: arg{$i} follows a missing argument or is not a value",
				$arc_return,
			);
		}
		$args["arg{$i}"] = trim((string) $request_data["arg{$i}"]);
	}

	return $args;
}

/**
 * Failed authentications of a client address in the current window; with
 * $record, counts one more first. Files are per address, under flock.
 */
function api_limit_count(string $ip, bool $record): int {
	if (!is_dir(API_V1_LIMIT_DIR)) {
		@mkdir(API_V1_LIMIT_DIR, 0700, true);
	}
	$fp = @fopen(API_V1_LIMIT_DIR . "/" . hash("sha256", $ip), "c+");
	if ($fp === false) {
		return 0;
	}
	flock($fp, LOCK_EX);
	$data = json_decode((string) stream_get_contents($fp), true);
	$now = time();
	$start = (int) ($data["start"] ?? 0);
	$count = (int) ($data["count"] ?? 0);
	if ($now - $start >= API_V1_LIMIT_WINDOW) {
		$start = $now;
		$count = 0;
	}
	if ($record) {
		$count++;
		ftruncate($fp, 0);
		rewind($fp);
		fwrite($fp, json_encode(["start" => $start, "count" => $count]));
	}
	flock($fp, LOCK_UN);
	fclose($fp);

	return $count;
}

/** 429 before any authentication work once the address used up its failures. */
function api_limit_check(string $ip, string $arc_return): void {
	if (api_limit_count($ip, false) >= API_V1_LIMIT_FAILURES) {
		header("Retry-After: " . API_V1_LIMIT_WINDOW);
		api_error(429, "Too many failed authentications, try again later", $arc_return);
	}
}

/** One answer for every authentication failure, counted by the limiter. */
function api_auth_failed(string $ip, string $message, string $arc_return): void {
	api_limit_count($ip, true);
	api_error(E_PASSWORD, $message, $arc_return);
}

/**
 * Legacy connection format using hash or user and password.
 *
 * @param array{user: string?, pass: string?, hash?: string, cmd: string, arg1?: string, arg2?: string, arg3?: string, arg4?: string, arg5?: string, arg6?: string, arg7?: string, arg8?: string, arg9?: string, arg10?: string, arg11?: string, arg12?: string, arg13?: string, returncode?: string} $request_data
 * @return void
 * @return void
 */
function api_legacy(array $request_data) {
	$arc_return = ($request_data["returncode"] ?? "no") === "yes" ? "code" : "data";
	exec(ARCPANEL_CMD . "v-list-sys-config json", $output, $return_var);
	$settings = json_decode(implode("", $output), true);
	unset($output);

	if (!api_v1_enabled($settings)) {
		api_error(E_DISABLED, "API v1 is disabled", $arc_return);
	}

	if (($settings["config"]["API"] ?? "no") !== "yes") {
		api_error(E_DISABLED, "Error: API Disabled", $arc_return);
	}

	$v_real_user_ip = get_real_user_ip();
	if (!api_ip_allowed($settings, $v_real_user_ip)) {
		api_error(E_FORBIDDEN, "Error: IP is not allowed to connect with API", $arc_return);
	}
	api_limit_check($v_real_user_ip, $arc_return);

	//This exists, so native JSON can be used without the repeating the code twice, so future code changes are easier and don't need to be replicated twice
	// Authentication: the administrator's password or a hash key of the
	// administrator, so ROOT_USER acts in the audit log (E23)
	$audit_user = (string) ($settings["config"]["ROOT_USER"] ?? "admin");
	$audit_key = null;
	if (empty($request_data["hash"])) {
		exec(ARCPANEL_CMD . "v-list-sys-config json", $output, $return_var);
		$data = json_decode(implode("", $output), true);
		$root_user = $data["config"]["ROOT_USER"];

		if (($request_data["user"] ?? null) !== $root_user) {
			api_auth_failed($v_real_user_ip, "Error: authentication failed", $arc_return);
		}
		$password = $request_data["password"] ?? null;
		if (!is_string($password) || $password === "") {
			api_auth_failed($v_real_user_ip, "Error: authentication failed", $arc_return);
		}
		$v_ip = quoteshellarg(get_real_user_ip());
		$user = quoteshellarg($root_user);
		unset($output);
		exec(
			ARCPANEL_CMD . "v-get-user-salt " . $user . " " . $v_ip . " json",
			$output,
			$return_var,
		);
		$pam = json_decode(implode("", $output), true);
		$salt = $pam[$root_user]["SALT"];
		$method = $pam[$root_user]["METHOD"];

		if ($method == "md5") {
			$hash = crypt($password, '$1$' . $salt . '$');
		}
		if ($method == "sha-512") {
			$hash = crypt($password, '$6$rounds=5000$' . $salt . '$');
			$hash = str_replace('$rounds=5000', "", $hash);
		}
		if ($method == "yescrypt") {
			$fp = tmpfile();
			$v_password = stream_get_meta_data($fp)["uri"];
			fwrite($fp, $password . "\n");
			unset($output);
			exec(
				ARCPANEL_CMD .
					"v-check-user-password " .
					quoteshellarg($root_user) .
					" " .
					quoteshellarg($v_password) .
					" " .
					$v_ip .
					" yes",
				$output,
				$return_var,
			);
			$hash = $output[0];
			fclose($fp);
			unset($output, $fp, $v_password);
		}
		if ($method == "des") {
			$hash = crypt($password, $salt);
		}

		// Send hash via tmp file
		$v_hash = exec("mktemp -p /tmp");
		$fp = fopen($v_hash, "w");
		fwrite($fp, $hash . "\n");
		fclose($fp);

		// Check user hash
		exec(
			ARCPANEL_CMD . "v-check-user-hash " . $user . " " . $v_hash . " " . $v_ip,
			$output,
			$return_var,
		);
		unset($output);

		// Remove tmp file
		unlink($v_hash);

		// Check API answer
		if ($return_var > 0) {
			api_auth_failed($v_real_user_ip, "Error: authentication failed", $arc_return);
		}
	} else {
		$key = "/usr/local/arcpanel/data/keys/" . basename((string) $request_data["hash"]);
		$audit_key =
			preg_match('/^[A-Za-z0-9]{20}$/', basename((string) $request_data["hash"])) === 1
				? basename((string) $request_data["hash"])
				: null;
		$v_ip = quoteshellarg($v_real_user_ip);
		exec(
			ARCPANEL_CMD . "v-check-api-key " . quoteshellarg($key) . " " . $v_ip,
			$output,
			$return_var,
		);
		unset($output);
		// Check API answer
		if ($return_var > 0) {
			api_auth_failed($v_real_user_ip, "Error: authentication failed", $arc_return);
		}
	}

	$arc_cmd = trim($request_data["cmd"] ?? "");
	$arc_cmd_args = api_command_args($request_data, $arc_return);

	if (empty($arc_cmd)) {
		api_error(E_INVALID, "Command not provided", $arc_return);
	} elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $arc_cmd)) {
		api_error(E_INVALID, "$arc_cmd command invalid", $arc_return);
	}

	// Check command
	if ($arc_cmd == "v-make-tmp-file") {
		// Used in DNS Cluster
		$fp = fopen("/tmp/" . basename(escapeshellcmd($arc_cmd_args["arg2"])), "w");
		fwrite($fp, $arc_cmd_args["arg1"] . "\n");
		fclose($fp);
		$return_var = 0;
	} else {
		// Prepare command
		$cmdquery = ARCPANEL_CMD . escapeshellcmd($arc_cmd);

		// Prepare arguments
		foreach ($arc_cmd_args as $cmd_arg) {
			$cmdquery .= " " . quoteshellarg($cmd_arg);
		}

		// Run cmd query
		exec($cmdquery, $output, $cmd_exit_code);
	}
	api_audit($arc_cmd, (int) ($cmd_exit_code ?? 0), 200, $audit_user, "admin", $audit_key, null);

	if (!empty($arc_return) && $arc_return == "code") {
		echo $cmd_exit_code;
	} else {
		if ($return_var == 0 && empty($output)) {
			echo "OK";
		} else {
			echo implode("\n", $output) . "\n";
		}
	}

	exit();
}

/**
 * Connection using access key.
 *
 * @param array{access_key: string, secret_key: string, cmd: string, arg1?: string, arg2?: string, arg3?: string, arg4?: string, arg5?: string, arg6?: string, arg7?: string, arg8?: string, arg9?: string, arg10?: string, arg11?: string, arg12?: string, arg13?: string, returncode?: string} $request_data
 * @return void
 */
function api_connection(array $request_data) {
	$arc_return = ($request_data["returncode"] ?? "no") === "yes" ? "code" : "data";
	$v_real_user_ip = get_real_user_ip();

	exec(ARCPANEL_CMD . "v-list-sys-config json", $output, $return_var);
	$settings = json_decode(implode("", $output), true);
	unset($output, $return_var);
	$root_user = (string) $settings["config"]["ROOT_USER"];

	// With API v1 off, only the phpMyAdmin single sign-on key from the
	// loopback address passes (E39 W4 D10); its sets are checked below
	$sso_only = !api_v1_enabled($settings);
	if ($sso_only && !api_from_loopback()) {
		api_error(E_DISABLED, "API v1 is disabled", $arc_return);
	}

	// Get the status of api
	$api_status =
		!empty($settings["config"]["API_SYSTEM"]) && is_numeric($settings["config"]["API_SYSTEM"])
			? (int) $settings["config"]["API_SYSTEM"]
			: 0;
	if ($api_status === 0) {
		// Check if API is disabled for all users
		api_error(E_DISABLED, "API has been disabled", $arc_return);
	}

	// Check if API access is enabled for the user
	if (!api_ip_allowed($settings, $v_real_user_ip)) {
		api_error(E_FORBIDDEN, "IP is not allowed to connect with API", $arc_return);
	}

	// Get POST Params
	$arc_access_key_id = trim((string) ($request_data["access_key"] ?? ""));
	$arc_secret_access_key = trim((string) ($request_data["secret_key"] ?? ""));
	$arc_cmd = trim((string) ($request_data["cmd"] ?? ""));
	$arc_cmd_args = api_command_args($request_data, $arc_return);

	if (empty($arc_cmd)) {
		api_error(E_INVALID, "Command not provided", $arc_return);
	} elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $arc_cmd)) {
		api_error(E_INVALID, "$arc_cmd command invalid", $arc_return);
	}

	api_limit_check($v_real_user_ip, $arc_return);
	if (empty($arc_access_key_id) || empty($arc_secret_access_key)) {
		api_auth_failed($v_real_user_ip, "Access key authentication failed", $arc_return);
	}

	// Authenticates the key and checks permission to run the script
	exec(
		ARCPANEL_CMD .
			"v-check-access-key " .
			quoteshellarg($arc_access_key_id) .
			" " .
			quoteshellarg($arc_secret_access_key) .
			" " .
			quoteshellarg($arc_cmd) .
			" " .
			quoteshellarg($v_real_user_ip) .
			" json",
		$output,
		$return_var,
	);
	if ($return_var > 0) {
		// A key with a valid secret but without the command keeps its
		// message; an unknown key, a wrong secret or a suspended owner all
		// get the same answer (L12)
		if ($return_var === E_FORBIDDEN) {
			api_error(E_FORBIDDEN, $output, $arc_return);
		}
		api_auth_failed($v_real_user_ip, "Access key authentication failed", $arc_return);
	}
	$key_data = json_decode(implode("", $output), true) ?? [];
	unset($output, $return_var);

	$key_user = (string) ($key_data["USER"] ?? "");
	$user_arg_position =
		isset($key_data["USER_ARG_POSITION"]) && is_numeric($key_data["USER_ARG_POSITION"])
			? (int) $key_data["USER_ARG_POSITION"]
			: -1;

	if ($sso_only && ($key_data["PERMISSIONS"] ?? "") !== "phpmyadmin-sso") {
		api_error(E_DISABLED, "API v1 is disabled", $arc_return);
	}

	# Check if API access is enabled for nonadmin users
	if ($key_user !== $root_user && $api_status < 2) {
		api_error(E_API_DISABLED, "API has been disabled", $arc_return);
	}

	// Checks if the value entered in the "user" argument matches the user of the key
	if (
		$key_user !== $root_user &&
		$user_arg_position > 0 &&
		($arc_cmd_args["arg{$user_arg_position}"] ?? null) !== $key_user
	) {
		api_error(
			E_FORBIDDEN,
			"Key $arc_access_key_id - the \"user\" argument doesn't match the key's user",
			$arc_return,
		);
	}

	// Prepare command
	$cmdquery = ARCPANEL_CMD . escapeshellcmd($arc_cmd);

	// Prepare arguments
	foreach ($arc_cmd_args as $cmd_arg) {
		$cmdquery .= " " . quoteshellarg($cmd_arg);
	}

	# v-make-temp files is manodory other wise some functions will break
	if ($arc_cmd == "v-make-tmp-file") {
		$fp = fopen("/tmp/" . basename($arc_cmd_args["arg2"]), "w");
		fwrite($fp, $arc_cmd_args["arg1"] . "\n");
		fclose($fp);
		$cmd_exit_code = 0;
	} else {
		// Run cmd query
		exec($cmdquery, $output, $cmd_exit_code);
		$cmd_output = trim(implode("\n", $output));
		unset($output);
	}

	header("ArcPanel-Exit-Code: $cmd_exit_code");
	if (!str_starts_with($arc_cmd, "v-list-")) {
		$audit_role = "admin";
		if ($key_user !== $root_user) {
			exec(
				ARCPANEL_CMD . "v-list-user " . quoteshellarg($key_user) . " json",
				$audit_output,
				$audit_code,
			);
			$audit_record = json_decode(implode("", $audit_output), true);
			$audit_role = (string) ($audit_record[$key_user]["ROLE"] ?? "");
			unset($audit_output, $audit_code, $audit_record);
		}
		$audit_status =
			$arc_return == "code"
				? 200
				: ($cmd_exit_code > 0
					? exit_code_to_http_code($cmd_exit_code)
					: (empty($cmd_output)
						? 204
						: 200));
		api_audit(
			$arc_cmd,
			(int) $cmd_exit_code,
			$audit_status,
			$key_user,
			$audit_role,
			$arc_access_key_id,
			$user_arg_position > 0
				? (string) ($arc_cmd_args["arg{$user_arg_position}"] ?? "")
				: null,
		);
	}

	if ($arc_return == "code") {
		echo $cmd_exit_code;
	} else {
		if ($cmd_exit_code > 0) {
			http_response_code(exit_code_to_http_code($cmd_exit_code));
		} else {
			http_response_code(!empty($cmd_output) ? 200 : 204);

			if (!empty($cmd_output) && json_decode($cmd_output, true)) {
				header("Content-Type: application/json; charset=utf-8");
			}
		}

		echo $cmd_output;
	}

	exit();
}

// Get request data
if (isset($_POST["access_key"]) || isset($_POST["user"]) || isset($_POST["hash"])) {
	$request_data = $_POST;
} elseif (($json_data = json_decode(file_get_contents("php://input"), true)) != null) {
	$request_data = $json_data;
} else {
	api_error(
		405,
		"Error: data received is null or invalid, check https://arcpanel.org/docs/rest-api",
		"",
	);
}

// Try to get access key in the hash
if (
	!isset($request_data["access_key"]) &&
	isset($request_data["hash"]) &&
	substr_count($request_data["hash"], ":") == 1
) {
	$hash_parts = explode(":", $request_data["hash"]);
	if (strlen($hash_parts[0]) == 20 && strlen($hash_parts[1]) == 40) {
		$request_data["access_key"] = $hash_parts[0];
		$request_data["secret_key"] = $hash_parts[1];
		unset($request_data["hash"]);
	}
}

// Check data format
if (isset($request_data["access_key"]) && isset($request_data["secret_key"])) {
	api_connection($request_data);
} elseif (isset($request_data["user"]) || isset($request_data["hash"])) {
	api_legacy($request_data);
} else {
	api_error(
		405,
		"Error: data received is null or invalid, check https://arcpanel.org/docs/rest-api",
		"",
	);
}
