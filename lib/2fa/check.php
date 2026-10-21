<?php
// Checks a TOTP code for v-check-user-2fa and v-change-user-2fa. Input on
// stdin, one value per line: the secret, the code and the time step of the
// last accepted code (0 for none), so the secret never appears in a process
// list. Prints "ok STEP" when the code is valid for a step newer than the
// last one (E39 W2 spec D4).
use RobThree\Auth\Providers\Qr\IQRCodeProvider;
use RobThree\Auth\TwoFactorAuth;
require_once __DIR__ . "/../vendor/autoload.php";

if (PHP_SAPI !== "cli") {
	exit(1);
}

// The library wants a QR code provider; this one never draws, so no secret
// can reach a QR code service (E39 W5 N18). The panel draws the QR code.
final class NoQrCodeProvider implements IQRCodeProvider {
	public function getQRCodeImage(string $qrText, int $size): string {
		throw new LogicException("QR codes are drawn by the panel");
	}

	public function getMimeType(): string {
		return "image/svg+xml";
	}
}

$input = explode("\n", (string) stream_get_contents(STDIN));
$secret = trim($input[0] ?? "");
$token = trim($input[1] ?? "");
$last = trim($input[2] ?? "0");

if ($secret === "" || preg_match('/^[0-9]{6}$/', $token) !== 1 || !ctype_digit($last)) {
	echo "ERROR: invalid input";
	exit(1);
}

$tfa = new TwoFactorAuth(new NoQrCodeProvider(), "ArcPanel");

$timeslice = 0;
if ($tfa->verifyCode($secret, $token, 1, null, $timeslice) && $timeslice > (int) $last) {
	echo "ok " . $timeslice;
}
