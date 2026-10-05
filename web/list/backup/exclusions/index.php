<?php
error_reporting(null);
$TAB = "BACKUP";

// Main include
include $_SERVER["DOCUMENT_ROOT"] . "/inc/main.php";

// ArcPanel: served by the panel app when ARCPANEL_APP_PAGES is on (inc/arcpanel-routes.php)
arc_redirect_if_migrated("/list/backup/exclusions/", $_GET);

// Data
exec(HESTIA_CMD . "v-list-user-backup-exclusions $user json", $output, $return_var);
$data = json_decode(implode("", $output), true);
unset($output);
// Render page
render_page($user, $TAB, "list_backup_exclusions");

// Back uri
$_SESSION["back"] = $_SERVER["REQUEST_URI"];
