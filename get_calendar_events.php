<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/functions.php';

requireLogin();

$user_id = $_SESSION['user_id'];
$include_completed = isset($_GET['show_completed']) && $_GET['show_completed'] == 'true';

$events = [];

function formatEventDate($value, $allDay = false) {
    if ($value === null || $value === '') {
        return null;
    }

    $date = new DateTime($value);
    if ($allDay) {
        return $date->format('Y-m-d');
    }

    return $date->format('Y-m-d\TH:i:s');
}

$query = "SELECT t.id, t.task_name AS title, t.due_date AS start_at, t.due_date AS end_at, 'task' AS type, t.status, t.calendar_group_id, t.series_key, t.recurrence_rule, cg.visible, cg.color AS group_color FROM tasks t LEFT JOIN calendar_groups cg ON t.calendar_group_id = cg.id WHERE t.user_id = :user_id AND t.due_date IS NOT NULL";
if (!$include_completed) {
    $query .= " AND t.status = 'Pending'";
}
$query .= " AND (t.calendar_group_id IS NULL OR cg.visible = 1)";

$stmt = getDb()->prepare($query);
$stmt->execute(['user_id' => $user_id]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $task) {
    $events[] = [
        'id' => $task['id'],
        'title' => $task['title'],
        'start' => $task['start_at'],
        'end' => $task['end_at'] ?? $task['start_at'],
        'allDay' => true,
        'color' => $task['group_color'] ?? '#cfe8ff',
        'textColor' => $task['group_color'] ? 'white' : '#0f172a',
        'status' => $task['status'],
        'type' => 'task',
        'groupId' => !empty($task['series_key']) ? 'task-series-' . $task['series_key'] : 'task-' . $task['id'],
        'extendedProps' => [
            'type' => 'task',
            'status' => $task['status'],
            'calendarGroupId' => $task['calendar_group_id'],
            'seriesKey' => $task['series_key'],
            'recurrenceRule' => $task['recurrence_rule']
        ]
    ];
}

$query = "SELECT ci.id, ci.type, ci.title, ci.start_at, ci.end_at, ci.all_day, ci.status, ci.description, ci.location, ci.color, ci.series_key, ci.recurrence_rule, ci.calendar_group_id, cg.visible, cg.color AS group_color FROM calendar_items ci LEFT JOIN calendar_groups cg ON ci.calendar_group_id = cg.id WHERE ci.user_id = :user_id AND ci.start_at IS NOT NULL";
if (!$include_completed) {
    $query .= " AND ci.status = 'Pending'";
}
$query .= " AND (ci.calendar_group_id IS NULL OR cg.visible = 1)";

$stmt = getDb()->prepare($query);
$stmt->execute(['user_id' => $user_id]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
    $start = $item['start_at'];
    $end = $item['end_at'] ?? $item['start_at'];
    $allDay = (bool)$item['all_day'];

    // If color field is empty or explicitly says 'inherit', use the calendar group color
    $displayColor = ($item['color'] === 'inherit' || empty($item['color'])) 
        ? ($item['group_color'] ?? '#5c636f') 
        : $item['color'];

    $events[] = [
        'id' => $item['id'],
        'title' => $item['title'],
        'start' => $start,
        'end' => $end,
        'allDay' => $allDay,
        'color' => $displayColor, // Gives FullCalendar a solid color to render on grid
        'textColor' => 'white',
        'status' => $item['status'],
        'type' => $item['type'],
        'groupId' => !empty($item['series_key']) ? 'series-' . $item['series_key'] : 'event-' . $item['id'],
        'extendedProps' => [
            'description' => $item['description'],
            'location' => $item['location'],
            'seriesKey' => $item['series_key'],
            'recurrenceRule' => $item['recurrence_rule'],
            'type' => $item['type'],
            'status' => $item['status'],
            'calendarGroupId' => $item['calendar_group_id'],
            // Keeps track of the true state so modal knows to tick 'Inherit'
            'customColor' => (!empty($item['color'])) ? $item['color'] : 'inherit'
        ]
    ];
}

echo json_encode($events);