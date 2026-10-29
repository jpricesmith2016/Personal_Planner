<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/ics.php';

requireLogin();

$user_id = $_SESSION['user_id'];

// group_id of "0" (or missing) means the Default / No Calendar bucket.
$rawGroupId = $_GET['group_id'] ?? '';
$group_id = ($rawGroupId === '' || $rawGroupId === '0') ? null : (int)$rawGroupId;

$calendarName = 'Default';

if ($group_id !== null) {
    $group = fetchCalendarGroup($user_id, $group_id);
    if (!$group) {
        http_response_code(404);
        echo 'Calendar not found.';
        exit;
    }
    $calendarName = $group['name'];
}

$ics = exportCalendarGroupToIcs($user_id, $group_id, $calendarName);

$safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $calendarName);
$filename = ($safeName !== '' ? $safeName : 'calendar') . '.ics';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($ics));

echo $ics;
exit;