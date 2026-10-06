<?php
$TAB = "UPDATES";

// Main include
include $_SERVER["DOCUMENT_ROOT"] . "/inc/main.php";

// ArcPanel: served by the panel app when ARCPANEL_APP_PAGES is on (inc/arcpanel-routes.php)
arc_redirect_if_migrated("/list/updates/", $_GET);

// Check user
if ($_SESSION["userContext"] != "admin") {
	header("Location: /list/user/");
	exit();
}

// Data
exec(HESTIA_CMD . "v-list-sys-hestia-updates json", $output, $return_var);
$data = json_decode(implode("", $output), true);
unset($output);
exec(HESTIA_CMD . "v-list-sys-hestia-autoupdate plain", $output, $return_var);
$autoupdate = $output["0"];
unset($output);

// Render page
render_page($user, $TAB, "list_updates");

// Back uri
$_SESSION["back"] = $_SERVER["REQUEST_URI"];
