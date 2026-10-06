<?php
$TAB = "SERVER";

// Main include
include $_SERVER["DOCUMENT_ROOT"] . "/inc/main.php";

// ArcPanel: served by the panel app when ARCPANEL_APP_PAGES is on (inc/arcpanel-routes.php)
arc_redirect_if_migrated("/edit/server/web-terminal/", $_GET);

// Check user
header("Location: /list/server/");
exit();
