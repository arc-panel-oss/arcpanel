<?php
/*
 * Cross-user lists of REST API v2 (E43 B2): the objects of one kind of every
 * user, or of a reseller and its customers, read in one process. Run by the
 * v-list-sys-<kind> commands (func/sys-list.sh) as root; see
 * docs/features/specs/2026-10-16-004-e43-api-v2-fleet-additions-design.md §5.
 *
 * Usage: php sys-list.php KIND USERS_DIR HOMEDIR OWNER UPDATED_SINCE
 *
 * KIND is web, db, cron, node or backup. OWNER is empty for every user.
 * UPDATED_SINCE is empty or a Unix time: a user is changed when the file of
 * the kind was modified at or after it, or, when the file is missing, when
 * the user's directory was (removing the last node app removes its file).
 * Prints {"AS_OF", "USERS", "CHANGED_USERS", "OBJECTS"}: the objects of each
 * changed user with the keys of the per-user command (v-list-web-domains,
 * v-list-databases, v-list-cron-jobs, v-list-user-backups, and the records
 * of v-list-node-apps without the live state of pm2), sorted by name.
 */

$asOf = time();
[, $kind, $usersDir, $homeDir, $owner, $since] = array_pad($argv, 6, "");

/** kind => [file, name key, the keys of the per-user command] */
$kinds = [
	"web" => [
		"web.conf",
		"DOMAIN",
		[
			"IP",
			"IP6",
			"DOCUMENT_ROOT",
			"U_DISK",
			"U_BANDWIDTH",
			"TPL",
			"ALIAS",
			"STATS",
			"STATS_USER",
			"SSL",
			"SSL_HOME",
			"LETSENCRYPT",
			"FTP_USER",
			"FTP_PATH",
			"AUTH_USER",
			"BACKEND",
			"PROXY",
			"PROXY_EXT",
			"SUSPENDED",
			"TIME",
			"DATE",
		],
	],
	"db" => [
		"db.conf",
		"DB",
		["DATABASE", "DBUSER", "HOST", "TYPE", "CHARSET", "U_DISK", "SUSPENDED", "TIME", "DATE"],
	],
	"cron" => [
		"cron.conf",
		"JOB",
		["MIN", "HOUR", "DAY", "MONTH", "WDAY", "CMD", "JOB", "SUSPENDED", "TIME", "DATE"],
	],
	"node" => [
		"nodeapps.conf",
		"DOMAIN",
		[
			"DOMAIN",
			"NAME",
			"START",
			"CWD",
			"NODE_VERSION",
			"PORT",
			"INSTANCES",
			"MAX_MEMORY",
			"TEMPLATE",
			"PREV_TPL",
			"STATE",
			"SUSPENDED",
			"TIME",
			"DATE",
		],
	],
	"backup" => [
		"backup.conf",
		"BACKUP",
		["TYPE", "SIZE", "WEB", "DNS", "MAIL", "DB", "CRON", "UDIR", "RUNTIME", "TIME", "DATE"],
	],
];
if (!isset($kinds[$kind]) || !is_dir($usersDir) || ($since !== "" && !ctype_digit($since))) {
	fwrite(STDERR, "usage: sys-list.php KIND USERS_DIR HOMEDIR OWNER UPDATED_SINCE\n");
	exit(1);
}
[$file, $nameKey, $keys] = $kinds[$kind];

/** The KEY='value' pairs of one line of a flat file. */
function sys_list_pairs(string $line): array {
	preg_match_all("/(?:^|\\s)([A-Za-z][A-Za-z0-9_]*)='([^']*)'/", $line, $matches, PREG_SET_ORDER);
	$pairs = [];
	foreach ($matches as $match) {
		$pairs[$match[1]] = $match[2];
	}
	return $pairs;
}

/** One key of a small flat file (user.conf, tenancy.conf), empty when missing. */
function sys_list_value(string $path, string $key): string {
	$text = is_file($path) ? (string) file_get_contents($path) : "";
	return preg_match("/^{$key}='([^']*)'/m", $text, $match) === 1 ? $match[1] : "";
}

// The users in scope, as tenancy_all_users and tenancy_customers find them
$all = [];
foreach (scandir($usersDir) ?: [] as $name) {
	if ($name[0] !== "." && is_file("$usersDir/$name/user.conf")) {
		$all[] = $name;
	}
}
if ($owner === "") {
	$users = $all;
} else {
	$users = [$owner];
	if (sys_list_value("$usersDir/$owner/user.conf", "ROLE") === "reseller") {
		foreach ($all as $name) {
			if (
				$name !== $owner &&
				sys_list_value("$usersDir/$name/user.conf", "ROLE") === "user" &&
				sys_list_value("$usersDir/$name/tenancy.conf", "OWNER") === $owner
			) {
				$users[] = $name;
			}
		}
	}
}
sort($users, SORT_STRING);

$changed = [];
$objects = [];
foreach ($users as $user) {
	$path = "$usersDir/$user/$file";
	clearstatcache();
	$mtime = is_file($path) ? (int) filemtime($path) : (int) filemtime("$usersDir/$user");
	if ($since !== "" && $mtime < (int) $since) {
		continue;
	}
	$changed[] = $user;
	$rows = [];
	$lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
	foreach ($lines ?: [] as $line) {
		$pairs = sys_list_pairs($line);
		$name = $pairs[$nameKey] ?? "";
		if ($name === "") {
			continue;
		}
		if ($kind === "web") {
			$custom = $pairs["CUSTOM_DOCROOT"] ?? "";
			$pairs["DOCUMENT_ROOT"] =
				$custom !== "" ? $custom : "$homeDir/$user/web/$name/public_html/";
		} elseif ($kind === "db") {
			$pairs["DATABASE"] = $name;
		} elseif ($kind === "cron") {
			$pairs["CMD"] = str_replace("%quote%", "'", $pairs["CMD"] ?? "");
		}
		$row = [];
		foreach ($keys as $key) {
			$row[$key] = $pairs[$key] ?? "";
		}
		$rows[$name] = $row;
	}
	if ($kind === "cron") {
		uksort($rows, fn($a, $b) => (int) $a <=> (int) $b);
	} else {
		ksort($rows, SORT_STRING);
	}
	$objects[$user] = (object) $rows;
}

echo json_encode(
	[
		"AS_OF" => (string) $asOf,
		"USERS" => $users,
		"CHANGED_USERS" => $changed,
		"OBJECTS" => (object) $objects,
	],
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
),
	"\n";
