<?php
$TAB = "CRON";

// Main include
include $_SERVER["DOCUMENT_ROOT"] . "/inc/main.php";

// ArcPanel: served by the panel app when ARCPANEL_APP_PAGES is on (inc/arcpanel-routes.php)
arc_redirect_if_migrated("/list/cron/", $_GET);

// Data
exec(HESTIA_CMD . "v-list-cron-jobs $user json", $output, $return_var);
$data = json_decode(implode("", $output), true);
if ($_SESSION["userSortOrder"] == "name") {
	ksort($data);
} else {
	$data = array_reverse($data, true);
}
unset($output);

// Render page
render_page($user, $TAB, "list_cron");

// Back uri
$_SESSION["back"] = $_SERVER["REQUEST_URI"];
