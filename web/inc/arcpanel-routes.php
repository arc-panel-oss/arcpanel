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
	if (($_SESSION["userContext"] ?? "") === "admin" && empty($_SESSION["look"]) && !empty($query["user"])) {
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
			$url = "/app/web/domains/" . $segment($owner) . "/" . $segment($domain) . "/logs/" . $type;
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
		default:
			return;
	}

	header("Location: " . $url, true, 302);
	exit();
}
