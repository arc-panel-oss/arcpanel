<?php

/*
 * ArcPanel: php.ini checks of the PHP Manager (E41 M3,
 * docs/features/specs/2026-10-12-001-e41-php-manager-design.md §6).
 *
 * Run by func/php.sh with the PHP binary of the version the file belongs to,
 * so that the checks use that version's parser, time zones and directives:
 *
 *   phpX.Y -n php-ini.php check FILE SAPI     syntax and semantics, JSON findings
 *   phpX.Y -n php-ini.php values FILE SAPI    effective values of the form directives
 *   phpX.Y -n php-ini.php merge FILE VALUES OUT   apply directive=value lines
 *   phpX.Y -c FILE php-ini.php known          directives PHP knows with FILE loaded
 *   phpX.Y -n php-ini.php unknown FILE KNOWN CURRENT   directives PHP does not know
 *
 * Written for every PHP version the catalog lists (5.6 and later): no
 * scalar type hints, no ?? and no short closures.
 */

error_reporting(E_ALL);
ini_set("display_errors", "stderr");

/* The directives of the form (§6.2) with PHP's built-in defaults */
function form_defaults($sapi) {
	return [
		"memory_limit" => "128M",
		"max_execution_time" => $sapi === "cli" ? "0" : "30",
		"max_input_time" => "-1",
		"max_input_vars" => "1000",
		"upload_max_filesize" => "2M",
		"post_max_size" => "8M",
		"max_file_uploads" => "20",
		"display_errors" => "1",
		"error_reporting" => "E_ALL",
		"log_errors" => "1",
		"date.timezone" => "",
		"default_socket_timeout" => "60",
		"allow_url_fopen" => "1",
		"short_open_tag" => "1",
		"cgi.fix_pathinfo" => "1",
		"session.gc_maxlifetime" => "1440",
		"opcache.enable" => "1",
		"opcache.memory_consumption" => "128",
		"opcache.max_accelerated_files" => "10000",
		"opcache.validate_timestamps" => "1",
		"opcache.revalidate_freq" => "2",
		"disable_functions" => "",
	];
}

/* Sizes: name => whether -1 (no limit) is allowed */
function size_directives() {
	return ["memory_limit" => true, "upload_max_filesize" => false, "post_max_size" => false];
}

/* Integers: name => array(minimum, maximum or null) */
function int_directives() {
	return [
		"max_execution_time" => [0, null],
		"max_input_time" => [-1, null],
		"max_input_vars" => [1, null],
		"max_file_uploads" => [0, null],
		"default_socket_timeout" => [-1, null],
		"session.gc_maxlifetime" => [0, null],
		"opcache.memory_consumption" => [8, null],
		"opcache.max_accelerated_files" => [200, 1000000],
		"opcache.revalidate_freq" => [0, null],
		"opcache.interned_strings_buffer" => [0, null],
		"realpath_cache_ttl" => [0, null],
	];
}

function bool_directives() {
	return [
		"display_errors",
		"display_startup_errors",
		"log_errors",
		"html_errors",
		"allow_url_fopen",
		"allow_url_include",
		"short_open_tag",
		"cgi.fix_pathinfo",
		"file_uploads",
		"expose_php",
		"opcache.enable",
		"opcache.enable_cli",
		"opcache.validate_timestamps",
		"session.use_strict_mode",
		"session.cookie_httponly",
		"session.cookie_secure",
		"zlib.output_compression",
		"ignore_user_abort",
	];
}

function is_bool_value($name, $value) {
	$v = strtolower($value);
	if (in_array($v, ["", "on", "off", "yes", "no", "true", "false", "none", "1", "0"], true)) {
		return true;
	}
	return $name === "display_errors" && in_array($v, ["stderr", "stdout"], true);
}

/* Bytes of a size value (8M); null when it is not a size */
function size_bytes($value) {
	if (!preg_match('/^(-?[0-9]+)([KMGkmg]?)$/', $value, $m)) {
		return null;
	}
	$n = (float) $m[1];
	switch (strtoupper($m[2])) {
		case "G":
			$n *= 1024;
		// no break
		case "M":
			$n *= 1024;
		// no break
		case "K":
			$n *= 1024;
	}
	return $n;
}

/*
 * The directive lines of a file: array(name, value, line), in order. Values
 * lose their quotes and trailing comments as PHP's parser does.
 */
function scan_lines($file) {
	$out = [];
	$lines = preg_split('/\r\n|\n|\r/', (string) file_get_contents($file));
	foreach ($lines as $i => $line) {
		if ($i === 0) {
			$line = preg_replace('/^\xEF\xBB\xBF/', "", $line);
		}
		$t = trim($line);
		if ($t === "" || $t[0] === ";" || $t[0] === "#" || $t[0] === "[") {
			continue;
		}
		if (!preg_match('/^([^=]+?)\s*=\s*(.*)$/', $t, $m)) {
			continue;
		}
		$value = $m[2];
		if ($value !== "" && ($value[0] === '"' || $value[0] === "'")) {
			$q = $value[0];
			$end = strpos($value, $q, 1);
			$value = $end === false ? substr($value, 1) : substr($value, 1, $end - 1);
		} else {
			$semi = strpos($value, ";");
			if ($semi !== false) {
				$value = substr($value, 0, $semi);
			}
			$value = trim($value);
		}
		$out[] = [trim($m[1]), $value, $i + 1];
	}
	return $out;
}

function finding($line, $level, $directive, $message) {
	return ["line" => $line, "level" => $level, "directive" => $directive, "message" => $message];
}

/* Step 1 and 2 of §6.3, and the extension paths of step 3 */
function check_file($file, $sapi) {
	$findings = [];

	// 1. Syntax, with the version's own parser
	$result = @parse_ini_file($file, true, INI_SCANNER_NORMAL);
	if ($result === false) {
		$error = error_get_last();
		$message = $error ? $error["message"] : "The file is not valid INI syntax";
		$line = 0;
		if (preg_match("/on line ([0-9]+)/", $message, $m)) {
			$line = (int) $m[1];
		}
		$message = preg_replace('/ in .* on line [0-9]+$/', "", $message);
		$findings[] = finding($line, "error", "", $message);
		return $findings;
	}

	// 2. Semantics of known directives
	$sizes = size_directives();
	$ints = int_directives();
	$bools = bool_directives();
	$effective = form_defaults($sapi);
	$where = [];
	$zones = array_flip(timezone_identifiers_list(DateTimeZone::ALL_WITH_BC));
	$constants = [
		"E_ALL",
		"E_ERROR",
		"E_WARNING",
		"E_PARSE",
		"E_NOTICE",
		"E_CORE_ERROR",
		"E_CORE_WARNING",
		"E_COMPILE_ERROR",
		"E_COMPILE_WARNING",
		"E_USER_ERROR",
		"E_USER_WARNING",
		"E_USER_NOTICE",
		"E_STRICT",
		"E_RECOVERABLE_ERROR",
		"E_DEPRECATED",
		"E_USER_DEPRECATED",
	];
	$extensionDir = rtrim(PHP_EXTENSION_DIR, "/");

	foreach (scan_lines($file) as $entry) {
		[$name, $value, $line] = $entry;
		$effective[$name] = $value;
		$where[$name] = $line;

		if (isset($sizes[$name])) {
			if (!preg_match('/^-?[0-9]+[KMGkmg]?$/', $value)) {
				$findings[] = finding($line, "error", $name, "$name must be a size such as 128M");
			} elseif ($value[0] === "-" && !($sizes[$name] && $value === "-1")) {
				$findings[] = finding(
					$line,
					"error",
					$name,
					"$name cannot be negative" . ($sizes[$name] ? " (use -1 for no limit)" : ""),
				);
			}
		} elseif (isset($ints[$name])) {
			[$min, $max] = $ints[$name];
			if (!preg_match('/^-?[0-9]+$/', $value)) {
				$findings[] = finding($line, "error", $name, "$name must be a whole number");
			} elseif ((int) $value < $min || ($max !== null && (int) $value > $max)) {
				$range = $max === null ? "at least $min" : "between $min and $max";
				$findings[] = finding($line, "error", $name, "$name must be $range");
			}
		} elseif (in_array($name, $bools, true)) {
			if (!is_bool_value($name, $value)) {
				$findings[] = finding($line, "error", $name, "$name must be On or Off");
			}
		} elseif ($name === "date.timezone") {
			if ($value !== "" && !isset($zones[$value])) {
				$findings[] = finding($line, "error", $name, "Unknown time zone $value");
			}
		} elseif ($name === "error_reporting") {
			$ok = preg_match('/^[\sA-Z_0-9&|~^()!-]*$/', $value) === 1;
			if ($ok && preg_match_all("/E_[A-Z_]+/", $value, $m)) {
				foreach ($m[0] as $constant) {
					if (!in_array($constant, $constants, true)) {
						$ok = false;
					}
				}
			}
			if (!$ok) {
				$findings[] = finding(
					$line,
					"error",
					$name,
					"error_reporting must combine E_* constants, for example E_ALL & ~E_DEPRECATED",
				);
			}
		} elseif ($name === "disable_functions" || $name === "disable_classes") {
			if (
				!preg_match(
					'/^\s*([A-Za-z_][A-Za-z0-9_\\\\]*\s*(,\s*[A-Za-z_][A-Za-z0-9_\\\\]*\s*)*)?,?\s*$/',
					$value,
				)
			) {
				$findings[] = finding($line, "error", $name, "$name must be a comma list of names");
			}
		} elseif ($name === "extension" || $name === "zend_extension") {
			if (strpos($value, "/") !== false) {
				$real = realpath($value);
				if ($real === false || strpos($real, $extensionDir . "/") !== 0) {
					$findings[] = finding(
						$line,
						"error",
						$name,
						"$name must name a file in $extensionDir",
					);
				}
			}
		} elseif ($name === "extension_dir") {
			if (rtrim($value, "/") !== $extensionDir) {
				$findings[] = finding(
					$line,
					"error",
					$name,
					"extension_dir must stay $extensionDir",
				);
			}
		}
	}

	// Relations between the upload limits
	$post = size_bytes($effective["post_max_size"]);
	$upload = size_bytes($effective["upload_max_filesize"]);
	$memory = size_bytes($effective["memory_limit"]);
	if ($post !== null && $upload !== null && $post > 0 && $post < $upload) {
		$line = isset($where["post_max_size"])
			? $where["post_max_size"]
			: (isset($where["upload_max_filesize"])
				? $where["upload_max_filesize"]
				: 0);
		$findings[] = finding(
			$line,
			"error",
			"post_max_size",
			"post_max_size must be at least upload_max_filesize",
		);
	}
	if ($post !== null && $memory !== null && $post > 0 && $memory >= 0 && $memory < $post) {
		$line = isset($where["memory_limit"])
			? $where["memory_limit"]
			: (isset($where["post_max_size"])
				? $where["post_max_size"]
				: 0);
		$findings[] = finding(
			$line,
			"warning",
			"memory_limit",
			"memory_limit is lower than post_max_size",
		);
	}

	return $findings;
}

/*
 * Unknown directives: a warning, PHP ignores them (§6.3 step 2). Directives
 * the current file already has are not reported again: the stock php.ini sets
 * directives of extensions that are not installed.
 */
function check_unknown($file, $known, $current) {
	$findings = [];
	$known = array_flip($known);
	if ($current !== "" && is_readable($current)) {
		foreach (scan_lines($current) as $entry) {
			$known[$entry[0]] = true;
		}
	}
	foreach (["extension", "zend_extension"] as $name) {
		$known[$name] = true;
	}
	foreach (scan_lines($file) as $entry) {
		[$name, , $line] = $entry;
		if (!isset($known[$name])) {
			$findings[] = finding(
				$line,
				"warning",
				$name,
				"PHP does not know $name and ignores it",
			);
		}
	}
	return $findings;
}

/* The form directives with their values in the file, or PHP's defaults */
function effective_values($file, $sapi) {
	$values = [];
	foreach (form_defaults($sapi) as $name => $default) {
		$values[$name] = ["VALUE" => $default, "SOURCE" => "default", "LINE" => 0];
	}
	foreach (scan_lines($file) as $entry) {
		[$name, $value, $line] = $entry;
		if (isset($values[$name])) {
			$values[$name] = ["VALUE" => $value, "SOURCE" => "file", "LINE" => $line];
		}
	}
	return $values;
}

/* A value written into php.ini: plain when it is safe, quoted otherwise */
function ini_value($name, $value) {
	if ($name === "error_reporting" || preg_match('/^[A-Za-z0-9_.,:\/+@ -]*$/', $value)) {
		return $value;
	}
	return '"' . $value . '"';
}

/* Apply directive=value lines to the file, keeping comments (§6.1) */
function merge_values($file, $valuesFile, $out, $sapi) {
	$allowed = form_defaults($sapi);
	$changes = [];
	foreach (preg_split('/\r\n|\n|\r/', (string) file_get_contents($valuesFile)) as $line) {
		if (trim($line) === "") {
			continue;
		}
		if (!preg_match('/^([a-z0-9_.]+)=(.*)$/', $line, $m)) {
			fwrite(STDERR, "invalid line :: $line\n");
			exit(2);
		}
		if (!array_key_exists($m[1], $allowed)) {
			fwrite(STDERR, "not a form directive :: {$m[1]}\n");
			exit(2);
		}
		if (preg_match('/["$\\\\\x00-\x1f]/', $m[2])) {
			fwrite(STDERR, "invalid characters in the value of {$m[1]}\n");
			exit(2);
		}
		$changes[$m[1]] = trim($m[2]);
	}

	$lines = preg_split('/\r\n|\n|\r/', (string) file_get_contents($file));
	if (end($lines) === "") {
		array_pop($lines);
	}
	foreach ($changes as $name => $value) {
		$text = $name . " = " . ini_value($name, $value);
		$pattern = "/^\s*" . preg_quote($name, "/") . "\s*=/";
		$found = null;
		foreach ($lines as $i => $line) {
			if (preg_match($pattern, $line)) {
				$found = $i;
			}
		}
		if ($found !== null) {
			$lines[$found] = $text;
			continue;
		}
		// Not set yet: at the end of the [PHP] section
		$php = null;
		$next = null;
		foreach ($lines as $i => $line) {
			if (preg_match('/^\s*\[(.+)\]\s*$/', $line, $m)) {
				if ($php === null && strtoupper(trim($m[1])) === "PHP") {
					$php = $i;
				} elseif ($php !== null && $next === null) {
					$next = $i;
				}
			}
		}
		if ($php === null) {
			array_unshift($lines, "[PHP]", $text);
		} elseif ($next === null) {
			$lines[] = $text;
		} else {
			array_splice($lines, $next, 0, [$text, ""]);
		}
	}
	file_put_contents($out, implode("\n", $lines) . "\n");
}

$mode = isset($argv[1]) ? $argv[1] : "";
switch ($mode) {
	case "check":
		echo json_encode(check_file($argv[2], $argv[3])), "\n";
		break;
	case "known":
		echo json_encode(array_keys(ini_get_all(null, false))), "\n";
		break;
	case "unknown":
		$known = json_decode((string) file_get_contents($argv[3]), true);
		echo json_encode(
		check_unknown($argv[2], is_array($known) ? $known : [], isset($argv[4]) ? $argv[4] : ""),
	),
			"\n";
		break;
	case "values":
		echo json_encode(effective_values($argv[2], $argv[3])), "\n";
		break;
	case "merge":
		merge_values($argv[2], $argv[3], $argv[4], $argv[5]);
		break;
	default:
		fwrite(STDERR, "usage: php-ini.php check|known|unknown|values|merge ...\n");
		exit(1);
}
