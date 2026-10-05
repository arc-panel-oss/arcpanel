<?php
use function Hestiacp\quoteshellarg\quoteshellarg;
$TAB = "BACKUP";

// Main include
include $_SERVER["DOCUMENT_ROOT"] . "/inc/main.php";

// ArcPanel: served by the panel app when ARCPANEL_APP_PAGES is on (inc/arcpanel-routes.php)
arc_redirect_if_migrated("/list/backup/", $_GET);

// Data & Render page
if (empty($_GET["backup"])) {
	exec(HESTIA_CMD . "v-list-user-backups $user json", $output, $return_var);
	$data = json_decode(implode("", $output), true);
	if ($_SESSION["userSortOrder"] == "name") {
		ksort($data);
	} else {
		$data = array_reverse($data, true);
	}
	unset($output);
	render_page($user, $TAB, "list_backup");
} else {
	exec(
		HESTIA_CMD . "v-list-user-backup $user " . quoteshellarg($_GET["backup"]) . " json",
		$output,
		$return_var,
	);
	$data = json_decode(implode("", $output), true);
	$data = array_reverse($data, true);
	unset($output);

	render_page($user, $TAB, "list_backup_detail");
}

// Back uri
$_SESSION["back"] = $_SERVER["REQUEST_URI"];
