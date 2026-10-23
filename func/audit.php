<?php
/*
 * The system-wide audit log (E23): one JSON event per line in daily files
 * data/audit/YYYY-MM-DD.jsonl (UTC), chained with SHA-256 across lines and
 * days, with an anchor file per day. Run as root by the v-*-sys-audit
 * commands (func/audit.sh); see
 * docs/features/specs/2026-10-23-002-e23-audit-log-design.md §3, §4 and §6.
 *
 * Usage:
 *   php audit.php append DIR FILE
 *       validate the event in FILE, set ts, chain and append it; prints the line
 *   php audit.php system DIR ACTION NAME
 *       record an event of channel system (audit.start, audit.purge); prints the line
 *   php audit.php list DIR FROM TO FILTERS FORMAT LIMIT OFFSET TREE
 *       events between two days, newest first; TREE is the comma list of the
 *       users of OWNER's tree (empty without OWNER)
 *   php audit.php check DIR FROM TO KEEP_FROM FORMAT
 *       recompute the chain; KEEP_FROM is the first day the retention keeps
 *   php audit.php update DIR RETENTION_DAYS
 *       seal the anchors of past days, gzip files older than 7 days, purge
 *       files older than the retention; prints the number of files purged
 *
 * Exit codes: 0 ok, 1 usage, 2 invalid event or arguments, 3 broken chain,
 * 13 the log cannot be written.
 */

const AUDIT_ZERO = "0000000000000000000000000000000000000000000000000000000000000000";
const AUDIT_MAX_LINE = 8192;
const AUDIT_GZIP_AFTER_DAYS = 7;
const AUDIT_CHANNELS = ["web", "api_v2", "api_v1", "system"];
const AUDIT_RESULTS = ["ok", "denied", "invalid", "failed"];
const AUDIT_ROLES = ["admin", "reseller", "user", "dns-cluster", "system", ""];
const AUDIT_LINE = '/^(\{.*),"prev":"([0-9a-f]{64})","hash":"([0-9a-f]{64})"\}$/';
const AUDIT_JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

umask(0077);
[, $mode, $dir] = array_pad($argv, 3, "");

function audit_fail(string $message, int $code = 2): never {
	fwrite(STDERR, $message . "\n");
	exit($code);
}

/** A string without control characters and at most $max characters, or null. */
function audit_text(mixed $value, int $max, bool $nullable = true): ?string {
	if ($value === null && $nullable) {
		return null;
	}
	if (
		!is_string($value) ||
		mb_strlen($value) > $max ||
		preg_match('/[\x00-\x1f\x7f]/', $value) === 1
	) {
		throw new InvalidArgumentException("a value is not a string of at most $max characters");
	}
	return $value;
}

function audit_pattern(
	mixed $value,
	string $pattern,
	string $what,
	bool $nullable = true,
): ?string {
	if (($value === null || $value === "") && $nullable) {
		return null;
	}
	if (!is_string($value) || preg_match($pattern, $value) !== 1) {
		throw new InvalidArgumentException("invalid $what");
	}
	return $value;
}

/** The ULID of now: 48 bits of milliseconds and 80 random bits, Crockford base32. */
function audit_ulid(): string {
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

/**
 * The event in the order of §3, from the panel's JSON: every key checked,
 * unknown keys dropped, ts set here, prev and hash added by the writer.
 */
function audit_event(array $in, string $ts): array {
	if (($in["v"] ?? null) !== 1) {
		throw new InvalidArgumentException("unknown schema version");
	}
	$actor = $in["actor"] ?? null;
	if (!is_array($actor)) {
		throw new InvalidArgumentException("actor missing");
	}
	$role = $actor["role"] ?? "";
	if (!in_array($role, AUDIT_ROLES, true)) {
		throw new InvalidArgumentException("invalid actor role");
	}
	$ip = $actor["ip"] ?? null;
	if ($ip !== null && $ip !== "" && filter_var($ip, FILTER_VALIDATE_IP) === false) {
		throw new InvalidArgumentException("invalid actor ip");
	}
	$object = $in["object"] ?? null;
	if ($object !== null) {
		if (!is_array($object)) {
			throw new InvalidArgumentException("invalid object");
		}
		$object = [
			"type" => audit_pattern(
				$object["type"] ?? null,
				'/^[a-z][a-z0-9_]{0,63}$/',
				"object type",
				false,
			),
			"user" => audit_text($object["user"] ?? null, 64),
			"name" => audit_text($object["name"] ?? null, 255),
		];
	}
	$channel = $in["channel"] ?? null;
	if (!in_array($channel, AUDIT_CHANNELS, true)) {
		throw new InvalidArgumentException("invalid channel");
	}
	$result = $in["result"] ?? null;
	if (!in_array($result, AUDIT_RESULTS, true)) {
		throw new InvalidArgumentException("invalid result");
	}
	$status = $in["status"] ?? null;
	if ($status !== null && (!is_int($status) || $status < 100 || $status > 599)) {
		throw new InvalidArgumentException("invalid status");
	}
	$fields = $in["fields"] ?? [];
	if (!is_array($fields) || !array_is_list($fields)) {
		throw new InvalidArgumentException("invalid fields");
	}
	foreach ($fields as $field) {
		audit_pattern($field, '/^[A-Za-z0-9_.\[\]-]{1,64}$/', "field name", false);
	}

	return [
		"v" => 1,
		"id" => audit_pattern($in["id"] ?? null, '/^[0-9A-HJKMNP-TV-Z]{26}$/', "id", false),
		"ts" => $ts,
		"request_id" => audit_pattern(
			$in["request_id"] ?? null,
			'/^[A-Za-z0-9._:-]{1,128}$/',
			"request id",
		),
		"channel" => $channel,
		"actor" => [
			"user" => audit_text($actor["user"] ?? "", 128, false),
			"role" => $role,
			"ip" => $ip === "" ? null : $ip,
			"key" => audit_pattern($actor["key"] ?? null, '/^[A-Za-z0-9]{20}$/', "access key id"),
			"ua" => audit_pattern($actor["ua"] ?? null, '/^[0-9a-f]{16}$/', "user agent hash"),
		],
		"effective_user" => audit_text($in["effective_user"] ?? null, 64),
		"action" => audit_pattern(
			$in["action"] ?? null,
			'/^[a-z0-9][a-z0-9._-]{0,127}$/',
			"action",
			false,
		),
		"object" => $object,
		"result" => $result,
		"status" => $status,
		"fields" => array_values($fields),
	];
}

/** The day YYYY-MM-DD, or an error. */
function audit_day(string $value, string $what): string {
	$date = DateTimeImmutable::createFromFormat("!Y-m-d", $value, new DateTimeZone("UTC"));
	if ($date === false || $date->format("Y-m-d") !== $value) {
		audit_fail("invalid $what :: $value");
	}
	return $value;
}

function audit_today(): string {
	return gmdate("Y-m-d");
}

function audit_shift(string $day, int $days): string {
	return (new DateTimeImmutable($day, new DateTimeZone("UTC")))
		->modify(sprintf("%+d days", $days))
		->format("Y-m-d");
}

/** @return array{LAST_HASH: string, COUNT: int, SEALED: string}|null */
function audit_anchor(string $dir, string $day): ?array {
	$text = @file_get_contents("$dir/$day.anchor");
	if ($text === false) {
		return null;
	}
	preg_match_all("/([A-Z_]+)='([^']*)'/", $text, $matches, PREG_SET_ORDER);
	$values = [];
	foreach ($matches as $match) {
		$values[$match[1]] = $match[2];
	}
	return [
		"LAST_HASH" => (string) ($values["LAST_HASH"] ?? ""),
		"COUNT" => (int) ($values["COUNT"] ?? 0),
		"SEALED" => (string) ($values["SEALED"] ?? "no"),
	];
}

function audit_write_anchor(
	string $dir,
	string $day,
	string $hash,
	int $count,
	string $sealed,
): void {
	$tmp = "$dir/.$day.anchor.tmp";
	if (
		file_put_contents($tmp, "LAST_HASH='$hash' COUNT='$count' SEALED='$sealed'\n") === false ||
		!rename($tmp, "$dir/$day.anchor")
	) {
		audit_fail("the anchor of $day cannot be written", 13);
	}
}

/** Days that have an anchor or a file, sorted. */
function audit_days(string $dir): array {
	$days = [];
	foreach (scandir($dir) ?: [] as $name) {
		if (preg_match('/^(\d{4}-\d{2}-\d{2})\.(anchor|jsonl|jsonl\.gz)$/', $name, $match) === 1) {
			$days[$match[1]] = true;
		}
	}
	$days = array_keys($days);
	sort($days, SORT_STRING);
	return $days;
}

/** The LAST_HASH of the latest anchor before $day, or 64 zeros. */
function audit_prev_before(string $dir, string $day): string {
	$prev = AUDIT_ZERO;
	foreach (audit_days($dir) as $other) {
		if ($other >= $day) {
			break;
		}
		$anchor = audit_anchor($dir, $other);
		if ($anchor !== null && preg_match('/^[0-9a-f]{64}$/', $anchor["LAST_HASH"]) === 1) {
			$prev = $anchor["LAST_HASH"];
		}
	}
	return $prev;
}

/** The file of a day, plain or gzipped, or null. */
function audit_file(string $dir, string $day): ?string {
	foreach (["$dir/$day.jsonl", "$dir/$day.jsonl.gz"] as $path) {
		if (is_file($path)) {
			return $path;
		}
	}
	return null;
}

/**
 * The lines of a day's file. The panel's PHP has no zlib, so gzipped days
 * are read through the system's gzip.
 */
function audit_lines(string $path): array {
	if (str_ends_with($path, ".gz")) {
		$pipe = popen("gzip -dc -- " . escapeshellarg($path), "r");
		$text = $pipe === false ? false : stream_get_contents($pipe);
		if ($pipe === false || pclose($pipe) !== 0) {
			$text = false;
		}
		$lines = $text === false ? false : explode("\n", $text);
	} else {
		$lines = @file($path);
	}
	if ($lines === false) {
		audit_fail("$path cannot be read", 13);
	}
	return array_values(
		array_filter(array_map(fn($line) => rtrim($line, "\n"), $lines), fn($line) => $line !== ""),
	);
}

/** Runs $fn with the writers' lock held. */
function audit_locked(string $dir, callable $fn): mixed {
	if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
		audit_fail("$dir cannot be created", 13);
	}
	$lock = @fopen("$dir/.lock", "c");
	if ($lock === false || !flock($lock, LOCK_EX)) {
		audit_fail("the lock of the audit log cannot be taken", 13);
	}
	try {
		return $fn();
	} finally {
		flock($lock, LOCK_UN);
		fclose($lock);
	}
}

/** Chain and append one event; returns the line. */
function audit_append(string $dir, array $in): string {
	return audit_locked($dir, function () use ($dir, $in): string {
		$now = DateTimeImmutable::createFromFormat(
			"U.u",
			sprintf("%.6F", microtime(true)),
			new DateTimeZone("UTC"),
		);
		$day = $now->format("Y-m-d");
		try {
			$event = audit_event($in, $now->format("Y-m-d\TH:i:s.v\Z"));
		} catch (InvalidArgumentException $e) {
			audit_fail($e->getMessage());
		}
		$anchor = audit_anchor($dir, $day);
		$prev =
			$anchor !== null && preg_match('/^[0-9a-f]{64}$/', $anchor["LAST_HASH"]) === 1
				? $anchor["LAST_HASH"]
				: audit_prev_before($dir, $day);
		$count = $anchor["COUNT"] ?? 0;

		// A line is at most 8 KB: the field list is cut and ends with "…"
		$body = json_encode($event, AUDIT_JSON);
		$cut = false;
		while (strlen($body) + 149 > AUDIT_MAX_LINE && $event["fields"] !== []) {
			array_pop($event["fields"]);
			$cut = true;
			$body = json_encode([...$event, "fields" => [...$event["fields"], "…"]], AUDIT_JSON);
		}
		if ($cut) {
			$event["fields"][] = "…";
			$body = json_encode($event, AUDIT_JSON);
		}
		if (strlen($body) + 149 > AUDIT_MAX_LINE) {
			audit_fail("the event is longer than 8 KB");
		}

		$hash = hash("sha256", $prev . "\n" . $body);
		$line = substr($body, 0, -1) . ',"prev":"' . $prev . '","hash":"' . $hash . '"}';
		if (file_put_contents("$dir/$day.jsonl", $line . "\n", FILE_APPEND) === false) {
			audit_fail("$dir/$day.jsonl cannot be written", 13);
		}
		audit_write_anchor($dir, $day, $hash, $count + 1, "no");
		return $line;
	});
}

/** The words KEY=value of FILTERS. */
function audit_filters(string $text): array {
	$allowed = [
		"ACTOR" => '/^.{1,128}$/u',
		"USER" => '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/',
		"ACTION" => '/^[a-z0-9][a-z0-9._-]{0,127}$/',
		"RESULT" => '/^(ok|denied|invalid|failed)$/',
		"CHANNEL" => '/^(web|api_v2|api_v1|system)$/',
		"OWNER" => '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/',
	];
	$filters = [];
	foreach (preg_split("/\s+/", trim($text), -1, PREG_SPLIT_NO_EMPTY) as $word) {
		[$key, $value] = array_pad(explode("=", $word, 2), 2, "");
		if (!isset($allowed[$key]) || preg_match($allowed[$key], $value) !== 1) {
			audit_fail("invalid filter :: $word");
		}
		$filters[$key] = $value;
	}
	return $filters;
}

function audit_matches(array $event, array $filters, array $tree): bool {
	$users = array_values(
		array_filter(
			[
				$event["actor"]["user"] ?? null,
				$event["effective_user"] ?? null,
				$event["object"]["user"] ?? null,
			],
			fn($user) => is_string($user) && $user !== "",
		),
	);
	if (isset($filters["ACTOR"]) && ($event["actor"]["user"] ?? null) !== $filters["ACTOR"]) {
		return false;
	}
	if (isset($filters["USER"]) && !in_array($filters["USER"], $users, true)) {
		return false;
	}
	if (
		isset($filters["ACTION"]) &&
		!str_starts_with((string) ($event["action"] ?? ""), $filters["ACTION"])
	) {
		return false;
	}
	if (isset($filters["RESULT"]) && ($event["result"] ?? null) !== $filters["RESULT"]) {
		return false;
	}
	if (isset($filters["CHANNEL"]) && ($event["channel"] ?? null) !== $filters["CHANNEL"]) {
		return false;
	}
	if (isset($filters["OWNER"]) && array_intersect($users, $tree) === []) {
		return false;
	}
	return true;
}

/** The columns of the csv, plain and shell formats. */
function audit_row(array $event): array {
	return [
		$event["ts"] ?? "",
		$event["id"] ?? "",
		$event["channel"] ?? "",
		$event["actor"]["user"] ?? "",
		$event["actor"]["role"] ?? "",
		$event["actor"]["ip"] ?? "",
		$event["actor"]["key"] ?? "",
		$event["effective_user"] ?? "",
		$event["action"] ?? "",
		$event["object"]["type"] ?? "",
		$event["object"]["user"] ?? "",
		$event["object"]["name"] ?? "",
		$event["result"] ?? "",
		(string) ($event["status"] ?? ""),
		implode(" ", $event["fields"] ?? []),
		$event["request_id"] ?? "",
		$event["hash"] ?? "",
	];
}

const AUDIT_COLUMNS = [
	"TIME",
	"ID",
	"CHANNEL",
	"ACTOR",
	"ROLE",
	"IP",
	"KEY",
	"EFFECTIVE_USER",
	"ACTION",
	"OBJECT_TYPE",
	"OBJECT_USER",
	"OBJECT_NAME",
	"RESULT",
	"STATUS",
	"FIELDS",
	"REQUEST_ID",
	"HASH",
];

function audit_list(string $dir, array $args): void {
	[$from, $to, $filterText, $format, $limit, $offset, $treeText] = array_pad($args, 7, "");
	audit_day($from, "start day");
	audit_day($to, "end day");
	if ($from > $to) {
		audit_fail("the start day is after the end day");
	}
	if (!in_array($format, ["json", "csv", "plain", "shell"], true)) {
		audit_fail("invalid format :: $format");
	}
	if (
		!ctype_digit($limit) ||
		(int) $limit < 1 ||
		(int) $limit > 100000 ||
		!ctype_digit($offset)
	) {
		audit_fail("invalid limit or offset");
	}
	$filters = audit_filters($filterText);
	$tree = $treeText === "" ? [] : explode(",", $treeText);
	$limit = (int) $limit;
	$offset = (int) $offset;

	$total = 0;
	$events = [];
	for ($day = $to; $day >= $from; $day = audit_shift($day, -1)) {
		$path = is_dir($dir) ? audit_file($dir, $day) : null;
		if ($path === null) {
			continue;
		}
		foreach (array_reverse(audit_lines($path)) as $line) {
			$event = json_decode($line, true);
			if (!is_array($event) || !audit_matches($event, $filters, $tree)) {
				continue;
			}
			if ($total >= $offset && count($events) < $limit) {
				$events[] = $event;
			}
			$total++;
		}
	}

	if ($format === "json") {
		// SIZE: the bytes of data/audit/, which the server settings show
		$size = 0;
		foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $name) {
			if (is_file("$dir/$name")) {
				$size += (int) filesize("$dir/$name");
			}
		}
		echo json_encode(
		[
			"TOTAL" => $total,
			"LIMIT" => $limit,
			"OFFSET" => $offset,
			"SIZE" => $size,
			"EVENTS" => $events,
		],
		AUDIT_JSON | JSON_PRETTY_PRINT,
	),
			"\n";
		return;
	}
	$out = fopen("php://stdout", "w");
	if ($format === "csv") {
		fputcsv($out, AUDIT_COLUMNS, ",", '"', "");
		foreach ($events as $event) {
			fputcsv($out, audit_row($event), ",", '"', "");
		}
		return;
	}
	$columns = $format === "shell" ? [0, 3, 7, 8, 11, 12, 5] : array_keys(AUDIT_COLUMNS);
	$print = function (array $row) use ($out, $columns, $format): void {
		$cells = array_map(
			fn($i) => str_replace(
				["\t", "\n"],
				" ",
				$row[$i] === "" && $format === "shell" ? "-" : $row[$i],
			),
			$columns,
		);
		fwrite($out, implode("\t", $cells) . "\n");
	};
	if ($format === "shell") {
		$print(AUDIT_COLUMNS);
		$print(array_map(fn($name) => str_repeat("-", strlen($name)), AUDIT_COLUMNS));
	}
	foreach ($events as $event) {
		$print(audit_row($event));
	}
}

function audit_check(string $dir, array $args): int {
	[$from, $to, $keepFrom, $format] = array_pad($args, 4, "");
	$days = is_dir($dir) ? audit_days($dir) : [];
	$today = audit_today();
	$to = $to === "" ? $today : audit_day($to, "end day");
	if ($from === "") {
		// From the first day whose file is kept
		$from = $to;
		foreach ($days as $day) {
			if (audit_file($dir, $day) !== null) {
				$from = $day;
				break;
			}
		}
	}
	audit_day($from, "start day");
	audit_day($keepFrom, "first kept day");
	if (!in_array($format, ["json", "plain", "shell"], true)) {
		audit_fail("invalid format :: $format");
	}

	$expected = is_dir($dir) ? audit_prev_before($dir, $from) : AUDIT_ZERO;
	$count = 0;
	$checked = 0;
	$broken = null;
	foreach ($days as $day) {
		if ($day < $from || $day > $to) {
			continue;
		}
		$checked++;
		$anchor = audit_anchor($dir, $day);
		$path = audit_file($dir, $day);
		if ($path === null) {
			if ($anchor !== null && $day < $keepFrom) {
				// Purged by the retention; the chain goes on from its anchor
				$expected = $anchor["LAST_HASH"];
				continue;
			}
			$broken = [$day, 0, "the file of the day is missing"];
			break;
		}
		$n = 0;
		foreach (audit_lines($path) as $line) {
			$n++;
			if (preg_match(AUDIT_LINE, $line, $match) !== 1) {
				$broken = [$day, $n, "the line is not a chained event"];
				break 2;
			}
			if ($match[2] !== $expected) {
				$broken = [
					$day,
					$n,
					"prev does not match the line before (a line was removed, inserted or moved)",
				];
				break 2;
			}
			if (hash("sha256", $match[2] . "\n" . $match[1] . "}") !== $match[3]) {
				$broken = [$day, $n, "the hash does not match the line (the line was changed)"];
				break 2;
			}
			$expected = $match[3];
			$count++;
		}
		if ($anchor === null) {
			$broken = [$day, $n, "the anchor of the day is missing"];
			break;
		}
		if ($anchor["COUNT"] !== $n || $anchor["LAST_HASH"] !== $expected) {
			$broken = [
				$day,
				$n + 1,
				"the anchor does not match the file (lines were removed at the end)",
			];
			break;
		}
	}

	if ($format === "json") {
		$answer = [
			"OK" => $broken === null,
			"FROM" => $from,
			"TO" => $to,
			"DAYS" => $checked,
			"COUNT" => $count,
		];
		if ($broken !== null) {
			$answer += ["DAY" => $broken[0], "LINE" => $broken[1], "REASON" => $broken[2]];
		}
		echo json_encode($answer, AUDIT_JSON | JSON_PRETTY_PRINT), "\n";
	} elseif ($broken === null) {
		echo "OK $count events from $from to $to\n";
	} else {
		echo "BROKEN $broken[0] line $broken[1]: $broken[2]\n";
	}
	return $broken === null ? 0 : 3;
}

function audit_update(string $dir, string $retention): int {
	if (!ctype_digit($retention) || (int) $retention < 7 || (int) $retention > 3650) {
		audit_fail("invalid retention :: $retention");
	}
	return audit_locked($dir, function () use ($dir, $retention): int {
		$today = audit_today();
		$gzipBefore = audit_shift($today, -AUDIT_GZIP_AFTER_DAYS);
		$keepFrom = audit_shift($today, -(int) $retention);
		$purged = 0;
		foreach (audit_days($dir) as $day) {
			if ($day >= $today) {
				continue;
			}
			$anchor = audit_anchor($dir, $day);
			if ($anchor !== null && $anchor["SEALED"] !== "yes") {
				audit_write_anchor($dir, $day, $anchor["LAST_HASH"], $anchor["COUNT"], "yes");
			}
			if ($day < $keepFrom) {
				foreach (["$dir/$day.jsonl", "$dir/$day.jsonl.gz"] as $path) {
					if (is_file($path) && unlink($path)) {
						$purged++;
					}
				}
				continue;
			}
			$plain = "$dir/$day.jsonl";
			if ($day < $gzipBefore && is_file($plain) && !is_file("$plain.gz")) {
				$code = 1;
				exec(
					"gzip -9 -c -- " .
						escapeshellarg($plain) .
						" > " .
						escapeshellarg("$plain.gz.tmp"),
					$output,
					$code,
				);
				if ($code !== 0 || !rename("$plain.gz.tmp", "$plain.gz")) {
					@unlink("$plain.gz.tmp");
					audit_fail("$plain cannot be compressed", 13);
				}
				unlink($plain);
			}
		}
		return $purged;
	});
}

switch ($mode) {
	case "append":
		$text = @file_get_contents($argv[3] ?? "");
		if ($text === false || strlen($text) > 65536) {
			audit_fail("the event file cannot be read");
		}
		try {
			$in = json_decode($text, true, 8, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			audit_fail("the event is not JSON");
		}
		if (!is_array($in)) {
			audit_fail("the event is not an object");
		}
		echo audit_append($dir, $in), "\n";
		exit(0);
	case "system":
		echo audit_append($dir, [
		"v" => 1,
		"id" => audit_ulid(),
		"request_id" => null,
		"channel" => "system",
		"actor" => [
			"user" => "root",
			"role" => "system",
			"ip" => null,
			"key" => null,
			"ua" => null,
		],
		"effective_user" => null,
		"action" => $argv[3] ?? "",
		"object" => ["type" => "audit_log", "user" => null, "name" => $argv[4] ?? ""],
		"result" => "ok",
		"status" => null,
		"fields" => [],
	]),
			"\n";
		exit(0);
	case "list":
		audit_list($dir, array_slice($argv, 3));
		exit(0);
	case "check":
		exit(audit_check($dir, array_slice($argv, 3)));
	case "update":
		echo audit_update($dir, $argv[3] ?? ""), "\n";
		exit(0);
	default:
		audit_fail("usage: audit.php append|system|list|check|update DIR …", 1);
}
