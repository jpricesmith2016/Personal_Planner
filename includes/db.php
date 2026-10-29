<?php
require_once 'config.php';

function fetchUserByUsername($username) {
    $db = getDb();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function createUser($username, $password) {
    $db = getDb();
    $stmt = $db->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
    return $stmt->execute([
        $username,
        password_hash($password, PASSWORD_BCRYPT)
    ]);
}

function generateSeriesKey() {
    return bin2hex(random_bytes(16));
}

function createTask($user_id, $task_name, $due_date, $calendar_group_id = null, $series_key = null, $recurrence_rule = null) {
    $db = getDb();
    $stmt = $db->prepare(
        "INSERT INTO tasks (user_id, task_name, due_date, calendar_group_id, series_key, recurrence_rule) VALUES (?, ?, ?, ?, ?, ?)"
    );
    return $stmt->execute([$user_id, $task_name, $due_date, $calendar_group_id, $series_key, $recurrence_rule]);
}

function fetchTasks($user_id) {
    $db = getDb();
    $stmt = $db->prepare("SELECT * FROM tasks WHERE user_id = ? ORDER BY due_date ASC");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function markTaskComplete($task_id, $user_id) {
    $db = getDb();
    $stmt = $db->prepare("UPDATE tasks SET status='Completed' WHERE id=? AND user_id=?");
    return $stmt->execute([$task_id, $user_id]);
}

function deleteTask($task_id, $user_id) {
    $db = getDb();
    $stmt = $db->prepare("DELETE FROM tasks WHERE id=? AND user_id=?");
    return $stmt->execute([$task_id, $user_id]);
}

function createCalendarGroup($user_id, $name, $color = '#0d6efd') {
    $db = getDb();
    $stmt = $db->prepare(
        "INSERT INTO calendar_groups (user_id, name, color) VALUES (?, ?, ?)"
    );
    $stmt->execute([$user_id, $name, $color]);
    return (int)$db->lastInsertId();
}

function fetchCalendarGroups($user_id) {
    $db = getDb();
    $stmt = $db->prepare(
        "SELECT id, user_id, name, color, visible FROM calendar_groups WHERE user_id = ? ORDER BY name ASC"
    );
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchCalendarGroup($user_id, $group_id) {
    $db = getDb();
    $stmt = $db->prepare(
        "SELECT id, user_id, name, color, visible FROM calendar_groups WHERE id = ? AND user_id = ?"
    );
    $stmt->execute([$group_id, $user_id]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    return $group ?: null;
}

function updateCalendarGroupVisibility($user_id, $group_id, $visible) {
    $db = getDb();
    $stmt = $db->prepare(
        "UPDATE calendar_groups SET visible = ? WHERE id = ? AND user_id = ?"
    );
    return $stmt->execute([$visible ? 1 : 0, $group_id, $user_id]);
}

function deleteCalendarGroup($user_id, $group_id) {
    $db = getDb();
    $stmt = $db->prepare(
        "DELETE FROM calendar_groups WHERE id = ? AND user_id = ?"
    );
    return $stmt->execute([$group_id, $user_id]);
}

function createCalendarItem(
    $user_id,
    $type,
    $title,
    $start_at,
    $end_at,
    $all_day = false,
    $status = 'Pending',
    $description = null,
    $location = null,
    $color = 'inherit',
    $series_key = null,
    $calendar_group_id = null,
    $recurrence_rule = null
) {
    $db = getDb();

    if ($color === 'inherit' || empty($color) || trim($color) === '') {
        $color = 'inherit';
    } else {
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = 'inherit';
        }
    }

    $stmt = $db->prepare(
        "INSERT INTO calendar_items (user_id, calendar_group_id, type, title, start_at, end_at, all_day, status, description, location, color, series_key, recurrence_rule) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    return $stmt->execute([
        $user_id,
        $calendar_group_id,
        $type,
        $title,
        $start_at,
        $end_at,
        $all_day ? 1 : 0,
        $status,
        $description,
        $location,
        $color,
        $series_key,
        $recurrence_rule,
    ]);
}

function fetchCalendarItems($user_id) {
    $db = getDb();
    $stmt = $db->prepare(
        "SELECT * FROM calendar_items WHERE user_id = ? ORDER BY start_at ASC"
    );
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// $group_id may be an int (events/tasks assigned to that calendar) or null
// (the "Default / No Calendar" bucket -- items with no calendar_group_id).
function fetchCalendarItemsByGroup($user_id, $group_id) {
    $db = getDb();
    if ($group_id === null) {
        $stmt = $db->prepare(
            "SELECT * FROM calendar_items WHERE user_id = ? AND calendar_group_id IS NULL ORDER BY start_at ASC"
        );
        $stmt->execute([$user_id]);
    } else {
        $stmt = $db->prepare(
            "SELECT * FROM calendar_items WHERE user_id = ? AND calendar_group_id = ? ORDER BY start_at ASC"
        );
        $stmt->execute([$user_id, $group_id]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchTasksByGroup($user_id, $group_id) {
    $db = getDb();
    if ($group_id === null) {
        $stmt = $db->prepare(
            "SELECT * FROM tasks WHERE user_id = ? AND calendar_group_id IS NULL ORDER BY due_date ASC"
        );
        $stmt->execute([$user_id]);
    } else {
        $stmt = $db->prepare(
            "SELECT * FROM tasks WHERE user_id = ? AND calendar_group_id = ? ORDER BY due_date ASC"
        );
        $stmt->execute([$user_id, $group_id]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}