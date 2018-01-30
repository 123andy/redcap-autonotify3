<?php
/**
 *  This is a cron task that will try to find all autonotify3 triggers and check logic once a day
 */

error_log(E_ALL);

define('NOAUTH',true);
include_once "../../redcap_connect.php";
include_once "common.php";

global $log_file;
$log_file = "/var/log/redcap/autonotify3_cron.log";

$plugin_name = "AutoNotify3";

logIt("STARTING");


// Step 1:  Find all AutoNotify Projects that have a datediff in the filter logic
$sql = "
    SELECT
        distinct l.project_id
       -- , l.sql_log, l.ts
    FROM
        redcap_log_event l
    WHERE
        AND l.description = 'AutoNotify3 Config'
        AND l.sql_log like '%datediff%'
";
$q = db_query($sql);

logIt("Found " . db_num_rows($q) . " from $sql");
//global $Proj;

while ($row = db_fetch_assoc($q)) {
    $project_id = $row['project_id'];

    $_GET['pid'] = $project_id;
//    $Proj = new Project($project_id);

    $an = new AutoNotify($project_id);
    $an->loadConfig();
    logIt( "Instantiated AN from project $project_id" );

    $thisProject = new Project($project_id);
    $pk = $thisProject->table_pk;
    $event_id = $thisProject->firstEventId;
    // TODO - add iteration across arms...

    // Set defaults
    $an->instrument = $thisProject->firstForm;
    $an->redcap_event_name = $thisProject->firstEventName;
    $an->event_id = $event_id;
    $instrument_complete_field = $thisProject->firstForm . "_complete";

    // Get all Record IDs in the projects
    $records = REDCap::getData($project_id, 'array', NULL, array($pk,$instrument_complete_field), $event_id);
    foreach ($records as $record => $data) {
        // Set autonotify
        $an->record = $record;
        $an->instrument_complete = $data[$event_id][$instrument_complete_field];
        logIt("$record: [$project_id] Cron Result => " . $an->execute(true));
    }
}
logIt("DONE");
