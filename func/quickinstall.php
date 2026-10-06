<?php
/**
 * Shared bootstrap of the Quick Install commands (E7 batch 2 spec §10.1):
 * v-list-quick-install-apps, v-list-quick-install-app-options and
 * v-add-web-domain-app. The installer classes live in lib/quickinstall (moved
 * out of web/src by E8) and read the panel session; these helpers give them
 * what they expect when they run from the command line.
 */

declare(strict_types=1);

require_once __DIR__ . "/../lib/vendor/autoload.php";
require_once __DIR__ . "/../lib/quickinstall/init.php";

if (!defined("ARCPANEL_DIR_BIN")) {
	define("ARCPANEL_DIR_BIN", "/usr/local/arcpanel/bin/");
}
if (!defined("ARCPANEL_CMD")) {
	define("ARCPANEL_CMD", "/usr/bin/sudo /usr/local/arcpanel/bin/");
}

const QI_E_ARGS = 1;
const QI_E_INVALID = 2;
const QI_E_NOTEXIST = 3;
const QI_E_FORBIDEN = 8;

/** Installer folders: lib/quickinstall/app/WebApp/Installers/<App>/<App>Setup.php */
function qi_installers_dir(): string {
	return __DIR__ . "/../lib/quickinstall/app/WebApp/Installers";
}

/** Print "Error: …" as the shell commands do and exit with CODE. */
function qi_fail(int $code, string $message): never {
	echo "Error: " . $message . "\n";
	exit($code);
}

/**
 * The session keys the installer classes read: arcpanel.conf (WEB_SYSTEM,
 * WEB_BACKEND …) and the user the commands act for. Never the panel's own
 * session.
 */
function qi_session(string $user = ""): void {
	$_SESSION = [];
	exec(ARCPANEL_CMD . "v-list-sys-config json", $output, $code);
	$config = $code === 0 ? json_decode(implode("", $output), true) : null;
	foreach ((array) ($config["config"] ?? []) as $key => $value) {
		$_SESSION[$key] = $value;
	}
	$_SESSION["user"] = $user;
	$_SESSION["userContext"] = "user";
	$_SESSION["look"] = "";
}

/**
 * Failed sub-commands are reported by the installer classes with
 * trigger_error() and their whole command line, which may hold passwords:
 * only the command name is printed (spec §15).
 */
function qi_quiet_errors(): void {
	ini_set("display_errors", "0");
	ini_set("log_errors", "0");
	set_error_handler(function (int $level, string $message): bool {
		if ($level === E_USER_NOTICE || $level === E_USER_WARNING) {
			if (preg_match("#/bin/(v-[a-z0-9-]+)#", $message, $match) === 1) {
				echo "Command failed: " . $match[1] . "\n";
			}
			return true;
		}
		return false;
	});
}

function qi_valid_user(string $user): bool {
	return preg_match('/^[[:alnum:]][-.\w]{0,31}$/', $user) === 1;
}

function qi_valid_app(string $app): bool {
	return preg_match('/^[A-Za-z][A-Za-z0-9]{0,63}$/', $app) === 1 &&
		is_file(qi_installers_dir() . "/$app/{$app}Setup.php");
}

function qi_valid_domain(string $domain): bool {
	return preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/', $domain) === 1 &&
		!str_contains($domain, "..");
}

/** @return list<string> installer names, sorted */
function qi_apps(): array {
	$apps = [];
	foreach (glob(qi_installers_dir() . "/*/*Setup.php") ?: [] as $file) {
		$app = basename(dirname($file));
		if (basename($file) === "{$app}Setup.php" && qi_valid_app($app)) {
			$apps[] = $app;
		}
	}
	sort($apps);
	return $apps;
}

/**
 * The installer context of the commands. Installers post their setup form
 * to the domain resolved to its address; behind NAT, web.conf holds the
 * public address, which the server itself may not reach: the local address
 * that NAT maps to is used instead (spec §15, implementation notes).
 */
final class QiArcPanelApp extends \ArcPanel\System\ArcPanelApp {
	public function getWebDomain(string $domainName): \ArcPanel\System\WebDomain {
		$domain = parent::getWebDomain($domainName);
		$ip = $domain->ipAddress;
		if ($ip !== "" && !is_file("/usr/local/arcpanel/data/ips/$ip")) {
			foreach (glob("/usr/local/arcpanel/data/ips/*") ?: [] as $file) {
				if (
					preg_match(
						"/^NAT='" . preg_quote($ip, "/") . "'/m",
						(string) file_get_contents($file),
					) === 1
				) {
					$ip = basename($file);
					break;
				}
			}
		}

		return new \ArcPanel\System\WebDomain(
			$domain->domainName,
			$domain->domainPath,
			$ip,
			$domain->isSslEnabled,
		);
	}
}

function qi_installer(
	string $app,
	\ArcPanel\System\ArcPanelApp $arcpanel,
): \ArcPanel\WebApp\BaseSetup {
	$class = "\\ArcPanel\\WebApp\\Installers\\$app\\{$app}Setup";
	if (!class_exists($class)) {
		qi_fail(QI_E_NOTEXIST, "installer $app does not exist");
	}
	return new $class($arcpanel);
}

/**
 * The form of an installer as plain rows: KEY, TYPE, LABEL, VALUE,
 * PLACEHOLDER, OPTIONS (VALUE, LABEL) and REQUIRED.
 *
 * @param array<string, mixed> $form AppWizard::getOptions()
 * @return list<array<string, mixed>>
 */
function qi_fields(array $form): array {
	$fields = [];
	foreach ($form as $key => $spec) {
		$key = (string) $key;
		$spec = is_array($spec) ? $spec : ["type" => (string) $spec];
		$type = in_array($spec["type"] ?? "text", ["text", "password", "boolean", "select"], true)
			? (string) ($spec["type"] ?? "text")
			: "text";
		$value = $spec["value"] ?? "";
		$value = is_bool($value) ? ($value ? "true" : "") : (string) $value;
		$options = [];
		foreach ((array) ($spec["options"] ?? []) as $optionKey => $label) {
			// web/templates/pages/setup_webapp.php: the key unless it is a list index
			$options[] = [
				"VALUE" => is_int($optionKey) ? (string) $label : (string) $optionKey,
				"LABEL" => (string) $label,
			];
		}
		$fields[] = [
			"KEY" => $key,
			"TYPE" => $type,
			"LABEL" => (string) ($spec["label"] ?? ucwords(str_replace([".", "_"], " ", $key))),
			"VALUE" => $value,
			"PLACEHOLDER" => (string) ($spec["placeholder"] ?? ""),
			"OPTIONS" => $options,
			"REQUIRED" =>
				$value === "" &&
				$options === [] &&
				$type !== "boolean" &&
				!str_starts_with($key, "database_")
					? "yes"
					: "no",
		];
	}
	return $fields;
}

function qi_print_json(mixed $data): void {
	echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) .
		"\n";
}
