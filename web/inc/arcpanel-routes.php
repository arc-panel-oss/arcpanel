<?php
/**
 * ArcPanel: legacy pages that moved to the panel app (web-app/, served under
 * /app/). With ARCPANEL_APP_PAGES='yes' in hestia.conf the legacy pages send
 * browsers to the new pages with a 302 redirect (not 301, so switching back
 * with `v-change-sys-config-value ARCPANEL_APP_PAGES no` works at once) and
 * menus link to them. See docs/features/specs/2026-10-05-002-e1-s2-dashboard-web-domains-design.md §9.
 */

/** Whether the migrated pages are served by the panel app. */
function arc_app_pages(): bool {
	return ($_SESSION["ARCPANEL_APP_PAGES"] ?? "") === "yes";
}

/**
 * The URL a legacy menu link should use: the panel app page when the switch
 * is on and the page has moved, else the legacy path.
 */
function arc_route(string $legacyPath): string {
	$routes = [
		"/" => "/app/",
		"/list/web/" => "/app/web/domains",
		"/add/web/" => "/app/web/domains/create",
		// File manager (E3): /fm/ itself keeps FileGator until E8
		"/fm/" => "/app/files",
		// E7 batch 1 (docs/features/specs/2026-10-06-001-e7-batch1-pages-design.md §5.6)
		"/list/db/" => "/app/db",
		"/add/db/" => "/app/db/create",
		"/list/cron/" => "/app/cron",
		"/add/cron/" => "/app/cron/create",
		"/list/dns/" => "/app/dns",
		"/add/dns/" => "/app/dns/create",
		"/list/mail/" => "/app/mail",
		"/add/mail/" => "/app/mail/create",
		"/list/backup/" => "/app/backups",
		// E7 batch 2 (docs/features/specs/2026-10-06-002-e7-batch2-pages-design.md §11)
		"/list/firewall/" => "/app/server/firewall",
	];

	return arc_app_pages() && isset($routes[$legacyPath]) ? $routes[$legacyPath] : $legacyPath;
}

/**
 * Called at the top of a migrated legacy page (after inc/main.php): sends a
 * GET request to the matching panel app page. Form posts of an open legacy
 * page still work. $query is the request's query string ($_GET).
 */
function arc_redirect_if_migrated(string $legacyPath, array $query = []): void {
	if (!arc_app_pages() || !in_array($_SERVER["REQUEST_METHOD"] ?? "GET", ["GET", "HEAD"], true)) {
		return;
	}

	// The owner of the object: an administrator may name it (?user=), as
	// web/edit/web allows; everyone else acts as the effective user.
	if (!empty($_SESSION["look"])) {
		$owner = $_SESSION["look"];
	} else {
		$owner = $_SESSION["user"] ?? "";
	}
	if (
		($_SESSION["userContext"] ?? "") === "admin" &&
		empty($_SESSION["look"]) &&
		!empty($query["user"])
	) {
		$owner = (string) $query["user"];
	}
	$domain = isset($query["domain"]) ? (string) $query["domain"] : "";
	$segment = fn(string $value): string => rawurlencode($value);

	switch ($legacyPath) {
		case "/list/web/":
			$url = "/app/web/domains";
			break;
		case "/add/web/":
			$url = "/app/web/domains/create";
			break;
		case "/edit/web/":
			if ($domain === "" || $owner === "") {
				return;
			}
			$url = "/app/web/domains/" . $segment($owner) . "/" . $segment($domain) . "/edit";
			break;
		case "/list/web-log/":
			if ($domain === "" || $owner === "") {
				return;
			}
			$type = ($query["type"] ?? "") === "error" ? "error" : "access";
			$url =
				"/app/web/domains/" . $segment($owner) . "/" . $segment($domain) . "/logs/" . $type;
			break;
		case "/list/db/":
			$url = "/app/db";
			break;
		case "/add/db/":
			$url = "/app/db/create";
			break;
		case "/edit/db/":
			$database = isset($query["database"]) ? (string) $query["database"] : "";
			if ($database === "" || $owner === "") {
				return;
			}
			$url = "/app/db/" . $segment($owner) . "/" . $segment($database) . "/edit";
			break;
		case "/list/cron/":
			$url = "/app/cron";
			break;
		case "/add/cron/":
			$url = "/app/cron/create";
			break;
		case "/edit/cron/":
			$job = isset($query["job"]) ? (string) $query["job"] : "";
			if (preg_match('/^\d{1,9}$/', $job) !== 1 || $owner === "") {
				return;
			}
			$url = "/app/cron/" . $segment($owner) . "/" . $job . "/edit";
			break;
		case "/list/dns/":
			// ?domain= lists the zone's records, ?domain=&action= shows its DNSSEC key
			if ($domain === "" || $owner === "") {
				$url = "/app/dns";
			} else {
				$zone = "/app/dns/" . $segment($owner) . "/" . $segment($domain);
				$url = $zone . (!empty($query["action"]) ? "/dnssec" : "/records");
			}
			break;
		case "/add/dns/":
			// ?domain= adds a record: the records page with the record drawer open
			if ($domain === "" || $owner === "") {
				$url = "/app/dns/create";
			} else {
				$url = "/app/dns/" . $segment($owner) . "/" . $segment($domain) . "/records?add=1";
			}
			break;
		case "/edit/dns/":
			if ($domain === "" || $owner === "") {
				return;
			}
			$zone = "/app/dns/" . $segment($owner) . "/" . $segment($domain);
			$record = isset($query["record_id"]) ? (string) $query["record_id"] : "";
			if ($record === "") {
				$url = $zone . "/edit";
			} elseif (preg_match('/^\d{1,9}$/', $record) === 1) {
				$url = $zone . "/records?edit=" . $record;
			} else {
				return;
			}
			break;
		case "/list/mail/":
			// ?domain= lists the domain's accounts, ?domain=&dns=1 shows its DNS records
			if ($domain === "" || $owner === "") {
				$url = "/app/mail";
			} else {
				$mail = "/app/mail/" . $segment($owner) . "/" . $segment($domain);
				$url = $mail . (!empty($query["dns"]) ? "/dns" : "/accounts");
			}
			break;
		case "/add/mail/":
			// ?domain= adds an account to the domain
			if ($domain === "" || $owner === "") {
				$url = "/app/mail/create";
			} else {
				$url =
					"/app/mail/" . $segment($owner) . "/" . $segment($domain) . "/accounts/create";
			}
			break;
		case "/edit/mail/":
			if ($domain === "" || $owner === "") {
				return;
			}
			$mail = "/app/mail/" . $segment($owner) . "/" . $segment($domain);
			$account = isset($query["account"]) ? (string) $query["account"] : "";
			if ($account === "") {
				$url = $mail . "/edit";
			} elseif (preg_match('/^[\w.-]{1,64}$/', $account) === 1) {
				$url = $mail . "/accounts/" . $segment($account) . "/edit";
			} else {
				return;
			}
			break;
		case "/list/backup/":
			if ($owner === "") {
				return;
			}
			$url = "/app/backups/" . $segment($owner);
			$backup = isset($query["backup"]) ? (string) $query["backup"] : "";
			if ($backup !== "") {
				if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,250}$/', $backup) !== 1) {
					return;
				}
				$url .= "/archives/" . $segment($backup);
			}
			break;
		case "/list/backup/incremental/":
			if ($owner === "") {
				return;
			}
			$snapshot = isset($query["snapshot"]) ? (string) $query["snapshot"] : "";
			if ($snapshot === "") {
				$url = "/app/backups/" . $segment($owner) . "/snapshots";
			} elseif (preg_match('/^[0-9a-f]{8,64}$/', $snapshot) !== 1) {
				return;
			} elseif (!empty($query["browse"])) {
				$url = "/app/files/" . $segment($owner) . "?snapshot=" . $snapshot;
			} else {
				$url = "/app/backups/" . $segment($owner) . "/snapshots/" . $snapshot;
			}
			break;
		case "/list/backup/exclusions/":
		case "/edit/backup/exclusions/":
			if ($owner === "") {
				return;
			}
			$url = "/app/backups/" . $segment($owner) . "/exclusions";
			break;
		// E7 batch 2: firewall (administrators; the panel app checks the role)
		case "/list/firewall/":
			$url = "/app/server/firewall";
			break;
		case "/add/firewall/":
			$url = "/app/server/firewall/create";
			break;
		case "/edit/firewall/":
			$rule = isset($query["rule"]) ? (string) $query["rule"] : "";
			if (preg_match('/^\d{1,9}$/', $rule) !== 1) {
				return;
			}
			$url = "/app/server/firewall/" . $rule . "/edit";
			break;
		case "/list/firewall/ipset/":
			$url = "/app/server/firewall/ipsets";
			break;
		case "/add/firewall/ipset/":
			$url = "/app/server/firewall/ipsets/create";
			break;
		case "/list/firewall/banlist/":
			$url = "/app/server/firewall/bans";
			break;
		case "/add/firewall/banlist/":
			$url = "/app/server/firewall/bans/create";
			break;
		default:
			return;
	}

	header("Location: " . $url, true, 302);
	exit();
}
