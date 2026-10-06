<?php

$TAB = "TERMINAL";

// Main include
include $_SERVER["DOCUMENT_ROOT"] . "/inc/main.php";

// ArcPanel: served by the panel app when ARCPANEL_APP_PAGES is on (inc/arcpanel-routes.php)
arc_redirect_if_migrated("/list/terminal/", $_GET);

if ($_SESSION["login_shell"] == "nologin") {
	header("Location: /list/user/");
	exit();
}

// Render page
render_page($user, $TAB, "list_terminal");
