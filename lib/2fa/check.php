<?php
// Checks a TOTP code for v-check-user-2fa. Input on stdin, one value per line:
// the secret, the code and the time step of the last accepted code (0 for
// none), so the secret never appears in a process list. Prints "ok STEP" when
// the code is valid for a step newer than the last one (E39 W2 spec D4).
use RobThree\Auth\TwoFactorAuth;
use RobThree\Auth\Providers\Qr\QRServerProvider;
require_once __DIR__ . "/../vendor/autoload.php";

if (PHP_SAPI !== "cli") {
	exit(1);
}

$input = explode("\n", (string) stream_get_contents(STDIN));
$secret = trim($input[0] ?? "");
$token = trim($input[1] ?? "");
$last = trim($input[2] ?? "0");

if ($secret === "" || preg_match('/^[0-9]{6}$/', $token) !== 1 || !ctype_digit($last)) {
	echo "ERROR: invalid input";
	exit(1);
}

$tfa = new TwoFactorAuth(new QRServerProvider(), "ArcPanel");

$timeslice = 0;
if ($tfa->verifyCode($secret, $token, 1, null, $timeslice) && $timeslice > (int) $last) {
	echo "ok " . $timeslice;
}
