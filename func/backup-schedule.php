<?php
/*
 * Schedules of backup jobs (E48,
 * docs/features/specs/2026-10-22-002-e48-backup-destinations-and-schedules-design.md §5.1).
 * Run by func/backup-job.sh as root with the panel's PHP CLI; no dependencies.
 *
 * A schedule is one of
 *   hourly:MM            every hour at minute MM
 *   daily:HH:MM          every day
 *   weekly:D:HH:MM       every week, D 1-7 (Monday 1)
 *   monthly:DD:HH:MM     every month, DD 1-28
 *   cron:M H DOM MON DOW five cron fields: numbers, *, lists, ranges and
 *                        steps, no names; DOW 0-7 (Sunday 0 or 7); when
 *                        both DOM and DOW are restricted either matches
 *
 * Usage:
 *   php backup-schedule.php valid SCHEDULE           exit 0 when valid
 *   php backup-schedule.php next SCHEDULE FROM [N]   the next N (default 1) times after the epoch
 *                                                    FROM, one "EPOCH<TAB>YYYY-MM-DD HH:MM" per line
 *   php backup-schedule.php interval SCHEDULE        the shortest gap between two runs in seconds,
 *                                                    over the next 400 runs
 *
 * Times are in the time zone of ARC_TZ (default UTC). Exit codes: 0 ok,
 * 2 invalid schedule or arguments.
 */

const SEARCH_LIMIT_DAYS = 1500;

function fail(string $message): never {
	fwrite(STDERR, $message . "\n");
	exit(2);
}

/** The values of one cron field, or null when it is not valid. */
function cron_field(string $field, int $min, int $max): ?array {
	$values = [];
	foreach (explode(",", $field) as $part) {
		if (!preg_match('/^(\*|(\d{1,2})(?:-(\d{1,2}))?)(?:\/(\d{1,2}))?$/', $part, $m)) {
			return null;
		}
		$step = isset($m[4]) && $m[4] !== "" ? (int) $m[4] : 1;
		if ($step < 1) {
			return null;
		}
		if ($m[1] === "*") {
			[$from, $to] = [$min, $max];
		} else {
			$from = (int) $m[2];
			$to = isset($m[3]) && $m[3] !== "" ? (int) $m[3] : ($step > 1 ? $max : $from);
		}
		if ($from < $min || $to > $max || $from > $to) {
			return null;
		}
		for ($value = $from; $value <= $to; $value += $step) {
			$values[$value] = true;
		}
	}

	return $values;
}

/**
 * The schedule as cron fields: [minutes, hours, days, months, weekdays,
 * dayRestricted, weekdayRestricted], or null when it is not valid.
 */
function parse(string $schedule): ?array {
	$two = "(\d{1,2})";
	if (preg_match("/^hourly:$two$/", $schedule, $m)) {
		$cron = "{$m[1]} * * * *";
	} elseif (preg_match("/^daily:$two:$two$/", $schedule, $m)) {
		$cron = "{$m[2]} {$m[1]} * * *";
	} elseif (preg_match("/^weekly:([1-7]):$two:$two$/", $schedule, $m)) {
		$cron = "{$m[3]} {$m[2]} * * " . (int) $m[1] % 7;
	} elseif (preg_match("/^monthly:$two:$two:$two$/", $schedule, $m)) {
		if ((int) $m[1] < 1 || (int) $m[1] > 28) {
			return null;
		}
		$cron = "{$m[3]} {$m[2]} {$m[1]} * *";
	} elseif (preg_match('/^cron:(\S+) (\S+) (\S+) (\S+) (\S+)$/', $schedule)) {
		$cron = substr($schedule, 5);
	} else {
		return null;
	}
	$parts = explode(" ", $cron);
	$fields = [
		cron_field($parts[0], 0, 59),
		cron_field($parts[1], 0, 23),
		cron_field($parts[2], 1, 31),
		cron_field($parts[3], 1, 12),
		cron_field($parts[4], 0, 7),
	];
	if (in_array(null, $fields, true)) {
		return null;
	}
	if (isset($fields[4][7])) {
		$fields[4][0] = true;
		unset($fields[4][7]);
	}
	$fields[] = $parts[2] !== "*";
	$fields[] = $parts[4] !== "*";

	return $fields;
}

function day_matches(array $f, DateTimeImmutable $t): bool {
	$dom = isset($f[2][(int) $t->format("j")]);
	$dow = isset($f[4][(int) $t->format("w")]);
	if ($f[5] && $f[6]) {
		return $dom || $dow;
	}

	return $dom && $dow;
}

/** The first matching minute after $from (epoch seconds). */
function next_run(array $f, int $from, DateTimeZone $tz): ?int {
	$t = (new DateTimeImmutable("@" . ($from - ($from % 60) + 60)))->setTimezone($tz);
	$end = $from + SEARCH_LIMIT_DAYS * 86400;
	while ($t->getTimestamp() <= $end) {
		if (!isset($f[3][(int) $t->format("n")])) {
			$t = $t->modify("first day of next month")->setTime(0, 0);
			continue;
		}
		if (!day_matches($f, $t)) {
			$t = $t->modify("+1 day")->setTime(0, 0);
			continue;
		}
		if (!isset($f[1][(int) $t->format("G")])) {
			$t = $t->setTime((int) $t->format("G"), 0)->modify("+1 hour");
			continue;
		}
		if (!isset($f[0][(int) $t->format("i")])) {
			$t = $t->modify("+1 minute");
			continue;
		}

		return $t->getTimestamp();
	}

	return null;
}

$args = array_slice($argv, 1);
$command = $args[0] ?? "";
$schedule = $args[1] ?? "";
$fields = parse($schedule);
if ($command === "valid") {
	// A schedule that never runs (31 February) is not valid either
	exit($fields === null || next_run($fields, time(), new DateTimeZone("UTC")) === null ? 2 : 0);
}
if ($fields === null) {
	fail("invalid schedule");
}
try {
	$tz = new DateTimeZone(getenv("ARC_TZ") ?: "UTC");
} catch (Exception) {
	$tz = new DateTimeZone("UTC");
}

if ($command === "next") {
	$from = $args[2] ?? "";
	$count = (int) ($args[3] ?? 1);
	if (!preg_match('/^\d{1,12}$/', $from) || $count < 1 || $count > 100) {
		fail("invalid arguments");
	}
	$at = (int) $from;
	for ($i = 0; $i < $count; $i++) {
		$at = next_run($fields, $at, $tz);
		if ($at === null) {
			break;
		}
		echo $at,
			"\t",
			(new DateTimeImmutable("@" . $at))->setTimezone($tz)->format("Y-m-d H:i"),
			"\n";
	}
	exit(0);
}

if ($command === "interval") {
	$at = next_run($fields, time(), $tz);
	$shortest = null;
	for ($i = 0; $i < 400 && $at !== null; $i++) {
		$next = next_run($fields, $at, $tz);
		if ($next === null) {
			break;
		}
		$shortest = $shortest === null ? $next - $at : min($shortest, $next - $at);
		$at = $next;
	}
	echo $shortest ?? 0, "\n";
	exit(0);
}

fail("unknown command");
