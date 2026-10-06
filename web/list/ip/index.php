<?php

$TAB = "IP";

// Main include
include $_SERVER["DOCUMENT_ROOT"] . "/inc/main.php";

// ArcPanel: served by the panel app when ARCPANEL_APP_PAGES is on (inc/arcpanel-routes.php)
arc_redirect_if_migrated("/list/ip/", $_GET);

// Check user
if ($_SESSION["userContext"] != "admin") {
	header("Location: /list/user/");
	exit();
}

// Data
exec(HESTIA_CMD . "v-list-sys-ips json", $output, $return_var);
$data = json_decode(implode("", $output), true);
if ($_SESSION["userSortOrder"] == "name") {
	ksort($data);
} else {
	$data = array_reverse($data, true);
}
unset($output);

// Render page
render_page($user, $TAB, "list_ip");

// Back uri
$_SESSION["back"] = $_SERVER["REQUEST_URI"];
