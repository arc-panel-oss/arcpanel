#!/usr/local/arcpanel/php/bin/php
<?php
declare(strict_types=1);

function deny(string $error, int $code = 1): never {
	echo json_encode(
	["ok" => false, "error" => $error],
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
),
		PHP_EOL;
	exit($code);
}

if (!isset($argv[1]) || !is_string($argv[1])) {
	deny("missing session id");
}

$sessionId = $argv[1];
if ($sessionId === "" || preg_match('/^[A-Za-z0-9,-]+$/', $sessionId) !== 1) {
	deny("invalid session id");
}

$arcpanel = getenv("ARCPANEL");
if (!is_string($arcpanel) || $arcpanel === "") {
	deny("missing ARCPANEL env");
}

session_name("ARCPANELSID");
session_save_path($arcpanel . "/data/sessions");
session_id($sessionId);

if (!@session_start()) {
	deny("session start failed");
}

$user = $_SESSION["user"] ?? "";
$look = $_SESSION["look"] ?? "";

if (!is_string($user) || $user === "") {
	deny("unauthenticated");
}

if (!is_string($look)) {
	$look = "";
}

echo json_encode(
	["ok" => true, "user" => $user, "look" => $look],
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
),
	PHP_EOL;

