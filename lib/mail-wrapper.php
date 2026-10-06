#!/usr/local/arcpanel/php/bin/php
<?php
/**
 * Mail wrapper of the commands ($SENDMAIL in func/main.sh):
 *
 *   mail-wrapper.php -s SUBJECT EMAIL [NOTIFY] < body
 *
 * Sends the body as an HTML mail (line breaks kept) from the White Label
 * sender, through the server SMTP relay when USE_SERVER_SMTP is on. Rewritten
 * for ArcPanel 2.0 (E8 spec §6.3) with the behaviour of the 1.x wrapper,
 * which loaded the whole legacy web bootstrap; this one needs neither that
 * nor the panel app, so notifications still go out when the panel is broken.
 */

use PHPMailer\PHPMailer\PHPMailer;

if (empty($argv[1])) {
	echo "ERROR: not enough arguments\n";
	echo "USAGE: mail-wrapper.php -s SUBJECT EMAIL [NOTIFY]\n";
	exit(3);
}

if (!empty($argv[4]) && $argv[4] == "no") {
	exit();
}

require_once __DIR__ . "/vendor/autoload.php";

/** The server configuration, as the 1.x bootstrap read it. */
function mail_wrapper_config(): array {
	exec("/usr/bin/sudo /usr/local/arcpanel/bin/v-list-sys-config json", $output, $code);
	$data = $code === 0 ? json_decode(implode("", $output), true) : null;

	return is_array($data["config"] ?? null) ? $data["config"] : [];
}

/** get_hostname() of 1.x: the host name, resolved forward and back when possible. */
function mail_wrapper_hostname(): string {
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

$config = mail_wrapper_config();

$hostname = mail_wrapper_hostname();
$from = !empty($config["FROM_EMAIL"]) ? $config["FROM_EMAIL"] : "noreply@" . $hostname;
$from_name = !empty($config["FROM_NAME"]) ? $config["FROM_NAME"] : $config["APP_NAME"] ?? "";
$to = trim((string) ($argv[3] ?? ""));
$subject = trim((string) ($argv[2] ?? ""));
$mailtext = (string) file_get_contents("php://stdin");

if ($to === "" || $subject === "") {
	exit();
}

$mail = new PHPMailer();

if (($config["USE_SERVER_SMTP"] ?? "") === "true") {
	if (
		!empty($config["SERVER_SMTP_ADDR"]) &&
		filter_var($config["SERVER_SMTP_ADDR"], FILTER_VALIDATE_EMAIL)
	) {
		$from = $config["SERVER_SMTP_ADDR"];
	}
	$mail->IsSMTP();
	$mail->Mailer = "smtp";
	$mail->SMTPDebug = 0;
	$mail->SMTPAuth = true;
	$mail->SMTPSecure = $config["SERVER_SMTP_SECURITY"] ?? "";
	$mail->Port = (int) ($config["SERVER_SMTP_PORT"] ?? 587);
	$mail->Host = $config["SERVER_SMTP_HOST"] ?? "";
	$mail->Username = $config["SERVER_SMTP_USER"] ?? "";
	$mail->Password = $config["SERVER_SMTP_PASSWD"] ?? "";
}

$mail->IsHTML(true);
$mail->ClearReplyTos();
$mail->AddAddress($to);
$mail->SetFrom($from, $from_name);
$mail->CharSet = "utf-8";
$mail->Subject = $subject;
$mail->MsgHTML(nl2br($mailtext));

// The exit code stays 0 as in 1.x (callers pipe into it); PHPMailer's error
// text names the relay but never the password.
if (!$mail->Send()) {
	fwrite(STDERR, "ERROR: mail not sent: " . $mail->ErrorInfo . "\n");
}

