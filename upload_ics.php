<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/ics.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['ics_file'])) {
    header('Location: dashboard.php');
    exit;
}

if ($_FILES['ics_file']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['upload_error'] = 'File upload failed.';
    header('Location: dashboard.php');
    exit;
}

$selectedColor = isset($_POST['ics_color']) && preg_match('/^#([A-Fa-f0-9]{6})$/', $_POST['ics_color'])
    ? $_POST['ics_color']
    : 'inherit';
$calendarGroupId = isset($_POST['ics_calendar_group']) && $_POST['ics_calendar_group'] !== ''
    ? (int)$_POST['ics_calendar_group']
    : null;

$tmpFile = $_FILES['ics_file']['tmp_name'];

if (!is_readable($tmpFile)) {
    $_SESSION['upload_error'] = 'Unable to read ICS file.';
    header('Location: dashboard.php');
    exit;
}

$uploaded = importIcsEvents(
    $_SESSION['user_id'],
    $tmpFile,
    $selectedColor,
    $calendarGroupId
);

if ($uploaded !== false && $uploaded > 0) {
    $_SESSION['upload_success'] = 'Imported ' . $uploaded . ' event(s) from the ICS file.';
} else {
    $_SESSION['upload_error'] = 'No events were imported.';
}

header('Location: dashboard.php');
exit;
