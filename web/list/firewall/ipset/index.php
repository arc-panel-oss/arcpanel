<?php
$TAB = "FIREWALL";

// Main include
include $_SERVER["DOCUMENT_ROOT"] . "/inc/main.php";

// ArcPanel: served by the panel app when ARCPANEL_APP_PAGES is on (inc/arcpanel-routes.php)
arc_redirect_if_migrated("/list/firewall/ipset/", $_GET);

// Check user
if ($_SESSION["userContext"] != "admin") {
	header("Location: /list/user/");
	exit();
}

// Data
exec(HESTIA_CMD . "v-list-firewall-ipset json", $output, $return_var);
$data = json_decode(implode("", $output), true);
ksort($data);

// Render page
render_page($user, $TAB, "list_firewall_ipset");

// Back uri
$_SESSION["back"] = $_SERVER["REQUEST_URI"];
