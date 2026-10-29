<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/functions.php';

requireLogin();

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['event_title']) && isset($_POST['event_start'])) {
    $event_end = $_POST['event_end'] !== '' ? $_POST['event_end'] : $_POST['event_start'];
    $all_day = isset($_POST['event_all_day']) ? 1 : 0;

    // Allow 'inherit' or fall back to default color if standard hex regex fails
    $event_color = isset($_POST['event_color']) && ($_POST['event_color'] === 'inherit' || preg_match('/^#([A-Fa-f0-9]{6})$/', $_POST['event_color']))
        ? $_POST['event_color']
        : '#7c838f';

    $eventCalendarGroup = isset($_POST['event_calendar_group']) && $_POST['event_calendar_group'] !== ''
        ? (int)$_POST['event_calendar_group']
        : null;

    // If no custom color was picked and no group exists, fall back to default blue
    if ($event_color === 'inherit' && $eventCalendarGroup === null) {
        $event_color = '#7c838f';
    }

    $repeatEnabled = isset($_POST['event_repeat']) && $_POST['event_repeat'] === '1';

    if ($repeatEnabled) {
        $freq = $_POST['event_repeat_freq'] ?? 'weekly';
        $interval = isset($_POST['event_repeat_interval']) ? max(1, (int)$_POST['event_repeat_interval']) : 1;
        $endType = ($_POST['event_repeat_end_type'] ?? 'count') === 'until' ? 'until' : 'count';
        $endValue = $endType === 'until'
            ? ($_POST['event_repeat_until'] ?? '')
            : ($_POST['event_repeat_count'] ?? '');

        $seriesKey = generateSeriesKey();
        $recurrenceLabel = describeRecurrenceRule($freq, $interval, $endType, $endValue);

        $startDt = new DateTime($_POST['event_start']);
        $endDt = new DateTime($event_end);
        $durationSeconds = $endDt->getTimestamp() - $startDt->getTimestamp();

        $occurrenceStarts = expandRecurrenceDates($startDt, $freq, $interval, $endType, $endValue);

        foreach ($occurrenceStarts as $occStart) {
            $occEnd = (clone $occStart)->modify('+' . $durationSeconds . ' seconds');

            createCalendarItem(
                $user_id,
                'event',
                $_POST['event_title'],
                $occStart->format('Y-m-d H:i:s'),
                $occEnd->format('Y-m-d H:i:s'),
                (bool)$all_day,
                'Pending',
                $_POST['event_description'] ?? null,
                $_POST['event_location'] ?? null,
                $event_color,
                $seriesKey,
                $eventCalendarGroup,
                $recurrenceLabel
            );
        }
    } else {
        createCalendarItem(
            $user_id,
            'event',
            $_POST['event_title'],
            $_POST['event_start'],
            $event_end,
            (bool)$all_day,
            'Pending',
            $_POST['event_description'] ?? null,
            $_POST['event_location'] ?? null,
            $event_color, // Saves literal 'inherit' string safely
            null,
            $eventCalendarGroup
        );
    }

    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['newCalendarName']) && trim($_POST['newCalendarName']) !== '') {
    $groupName = trim($_POST['newCalendarName']);
    $groupColor = isset($_POST['newCalendarColor']) && preg_match('/^#([A-Fa-f0-9]{6})$/', $_POST['newCalendarColor'])
        ? $_POST['newCalendarColor']
        : '#7c838f';
    createCalendarGroup($user_id, $groupName, $groupColor);
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['task_name']) && isset($_POST['due_date'])) {
    $taskCalendarGroup = isset($_POST['task_calendar_group']) && $_POST['task_calendar_group'] !== ''
        ? (int)$_POST['task_calendar_group']
        : null;

    $repeatEnabled = isset($_POST['task_repeat']) && $_POST['task_repeat'] === '1';

    if ($repeatEnabled) {
        $freq = $_POST['task_repeat_freq'] ?? 'weekly';
        $interval = isset($_POST['task_repeat_interval']) ? max(1, (int)$_POST['task_repeat_interval']) : 1;
        $endType = ($_POST['task_repeat_end_type'] ?? 'count') === 'until' ? 'until' : 'count';
        $endValue = $endType === 'until'
            ? ($_POST['task_repeat_until'] ?? '')
            : ($_POST['task_repeat_count'] ?? '');

        $seriesKey = generateSeriesKey();
        $recurrenceLabel = describeRecurrenceRule($freq, $interval, $endType, $endValue);

        $dueDt = new DateTime($_POST['due_date']);
        $occurrenceStarts = expandRecurrenceDates($dueDt, $freq, $interval, $endType, $endValue);

        foreach ($occurrenceStarts as $occDue) {
            createTask(
                $user_id,
                $_POST['task_name'],
                $occDue->format('Y-m-d H:i:s'),
                $taskCalendarGroup,
                $seriesKey,
                $recurrenceLabel
            );
        }
    } else {
        createTask($user_id, $_POST['task_name'], $_POST['due_date'], $taskCalendarGroup);
    }

    header('Location: dashboard.php');
    exit;
}
?>

<?php include 'templates/header.php'; ?>

<?php
$calendarGroups = fetchCalendarGroups($user_id);
?>

<style>
    .page-layout {
        display: flex;
        flex-direction: column;
        gap: 18px;
        width: 100%;
    }
    .calendar-top-row {
        display: flex;
        gap: 18px;
        align-items: flex-start;
        width: 100%;
    }
    .manage-panel {
        width: 320px;
        flex: 0 0 320px;
        background: #f8f9fa;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 16px;
    }
    .manage-panel h3 {
        margin-top: 0;
        margin-bottom: 12px;
    }
    .calendar-group-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        padding: 8px 10px;
        border-radius: 6px;
        background: #fff;
        margin-bottom: 8px;
    }
    .calendar-group-item button {
        padding: 6px 10px;
    }
    .export-group-link {
        font-size: 13px;
        color: #0d6efd;
        text-decoration: none;
        margin-right: 4px;
    }
    .export-group-link:hover {
        text-decoration: underline;
    }
    .calendar-panel {
        flex: 1;
        min-width: 0;
    }
    .forms-row {
        display: flex;
        gap: 24px;
        align-items: flex-start;
        flex-wrap: wrap;
    }
    .forms-column {
        flex: 1;
        min-width: 320px;
    }
    .form-control {
        width: 100%;
        max-width: 100%;
        padding: 10px 12px;
        border: 1px solid #d0d7de;
        border-radius: 6px;
        font-size: 14px;
        background: #fff;
        box-sizing: border-box;
    }
    .field-group {
        margin-bottom: 12px;
    }
    .recurrence-fieldset {
        background: #f8f9fa;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 10px 12px;
    }
    .recurrence-options {
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px solid #e5e7eb;
    }
    .recurrence-options .field-group {
        margin-bottom: 10px;
    }
    .color-row {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 8px;
    }
    .color-preview {
        width: 46px;
        height: 24px;
        border-radius: 6px;
        border: 1px solid #d0d7de;
        display: inline-block;
        background: #0d6efd;
    }
    .color-palette {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    .color-swatch {
        width: 28px;
        height: 28px;
        border-radius: 999px;
        border: 2px solid #fff;
        box-shadow: 0 0 0 1px #d0d7de;
        cursor: pointer;
        transition: transform 0.15s ease;
        padding: 0;
        overflow: hidden;
    }
    .color-swatch:hover {
        transform: translateY(-1px);
    }
    .color-swatch.active {
        box-shadow: 0 0 0 2px #0d6efd, 0 0 0 4px #e7f1ff;
    }
    /* Dynamic look for the inherited auto color button option */
    .color-swatch.swatch-inherit {
        background: linear-gradient(135deg, #e5e7eb 50%, #9ca3af 50%);
        position: relative;
    }
</style>

<div class="page-layout">
    <div class="calendar-top-row">
        <aside class="manage-panel">
            <h3>Manage Calendars</h3>
            <div id="calendarGroupsList">
                <?php foreach ($calendarGroups as $group): ?>
                    <div class="calendar-group-item" data-group-id="<?= (int)$group['id'] ?>">
                        <span>
                            <span class="color-preview" style="width:16px; height:16px; vertical-align:middle; margin-right:6px; background:<?= htmlspecialchars($group['color']) ?>;"></span>
                            <strong><?= htmlspecialchars($group['name']) ?></strong>
                        </span>
                        <span>
                            <a class="export-group-link" href="export_ics.php?group_id=<?= (int)$group['id'] ?>">Export</a>
                            <button type="action" class="toggle-group-btn" data-group-id="<?= (int)$group['id'] ?>" data-visible="<?= (int)$group['visible'] ?>"><?= (int)$group['visible'] ? 'Hide' : 'Show' ?></button>
                            <button type="action" class="delete-group-btn" data-group-id="<?= (int)$group['id'] ?>">Delete</button>
                        </span>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($calendarGroups)): ?>
                    <p style="color:#6b7280; font-size:13px;">No calendars yet. Create one below.</p>
                <?php endif; ?>
                <div class="calendar-group-item" data-group-id="0">
                    <span>
                        <span class="color-preview" style="width:16px; height:16px; vertical-align:middle; margin-right:6px; background:#7c838f;"></span>
                        <strong>Default / No Calendar</strong>
                    </span>
                    <span>
                        <a class="export-group-link" href="export_ics.php?group_id=0">Export</a>
                    </span>
                </div>
            </div>
            <hr>
            <form id="createCalendarGroupForm" method="POST">
                <div class="field-group">
                    <label for="newCalendarName">New Calendar Name</label>
                    <input class="form-control" type="text" id="newCalendarName" name="newCalendarName" required>
                </div>
                <div class="field-group">
                    <div class="color-row">
                        <label for="newCalendarColor">Color</label>
                        <span class="color-preview" id="newCalendarPreview"></span>
                    </div>
                    <div class="color-palette" data-hidden="newCalendarColor" data-preview="newCalendarPreview">
                        <button type="button" class="color-swatch active" data-color="#0d6efd" style="background:#0d6efd;" title="Blue"></button>
                        <button type="button" class="color-swatch" data-color="#198754" style="background:#198754;" title="Green"></button>
                        <button type="button" class="color-swatch" data-color="#dc3545" style="background:#dc3545;" title="Red"></button>
                        <button type="button" class="color-swatch" data-color="#fd7e14" style="background:#fd7e14;" title="Orange"></button>
                        <button type="button" class="color-swatch" data-color="#6f42c1" style="background:#6f42c1;" title="Purple"></button>
                        <button type="button" class="color-swatch" data-color="#20c997" style="background:#20c997;" title="Teal"></button>
                    </div>
                    <input type="hidden" id="newCalendarColor" name="newCalendarColor" value="#0d6efd">
                </div>
                <button type="submit">Create Calendar</button>
            </form>
        </aside>

        <section class="calendar-panel">
            <div id="calendar"></div>
        </section>
    </div>

    <div>
        <label for="showCompletedCheckbox">
            <input type="checkbox" id="showCompletedCheckbox">
            Show Completed Tasks
        </label>
    </div>

    <div class="forms-row">
        <div class="forms-column">
            <div>
                <h2>Add Task</h2>
                <form method="POST">
                    <div class="field-group">
                        <label for="task_calendar_group">Calendar</label>
                        <select class="form-control" name="task_calendar_group" id="task_calendar_group">
                            <option value="">Default / No Calendar</option>
                            <?php foreach ($calendarGroups as $group): ?>
                                <option value="<?= (int)$group['id'] ?>"><?= htmlspecialchars($group['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field-group">
                        <label for="task_name">Task Name:</label>
                        <input class="form-control" type="text" name="task_name" id="task_name" required>
                    </div>

                    <div class="field-group">
                        <label for="due_date">Due Date:</label>
                        <input class="form-control" type="datetime-local" name="due_date" id="due_date" required>
                    </div>

                    <div class="field-group recurrence-fieldset">
                        <label>
                            <input type="checkbox" class="repeat-toggle" data-target="task" name="task_repeat" id="task_repeat" value="1">
                            Repeat this task
                        </label>
                        <div class="recurrence-options" id="task_recurrence_options" style="display:none;">
                            <div class="field-group">
                                <label for="task_repeat_freq">Frequency</label>
                                <select class="form-control" name="task_repeat_freq" id="task_repeat_freq">
                                    <option value="daily">Daily</option>
                                    <option value="weekly" selected>Weekly</option>
                                    <option value="monthly">Monthly</option>
                                    <option value="yearly">Yearly</option>
                                </select>
                            </div>
                            <div class="field-group">
                                <label for="task_repeat_interval">Repeat every</label>
                                <input class="form-control" type="number" min="1" value="1" name="task_repeat_interval" id="task_repeat_interval">
                            </div>
                            <div class="field-group">
                                <label>Ends</label><br>
                                <label><input type="radio" name="task_repeat_end_type" value="count" checked> After
                                    <input type="number" min="1" value="10" name="task_repeat_count" id="task_repeat_count" style="width:70px;"> occurrences
                                </label><br>
                                <label><input type="radio" name="task_repeat_end_type" value="until"> On date
                                    <input type="date" name="task_repeat_until" id="task_repeat_until">
                                </label>
                            </div>
                        </div>
                    </div>

                    <button type="submit">Add Task</button>
                </form>
            </div>
        </div>

        <div class="forms-column">
            <div>
                <h2>Import Calendar (.ics)</h2>
                <form id="icsForm" action="upload_ics.php" method="POST" enctype="multipart/form-data">
                    <div class="field-group">
                        <label for="ics_calendar_group">Calendar</label>
                        <select class="form-control" name="ics_calendar_group" id="ics_calendar_group">
                            <option value="">Default / No Calendar</option>
                            <?php foreach ($calendarGroups as $group): ?>
                                <option value="<?= (int)$group['id'] ?>"><?= htmlspecialchars($group['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field-group">
                        <div class="color-row">
                            <label for="ics_color">Choose color:</label>
                            <span class="color-preview" id="icsPreview"></span>
                        </div>
                        <div class="color-palette" data-hidden="ics_color" data-preview="icsPreview">
                            <button type="button" class="color-swatch swatch-inherit active" data-color="inherit" title="Use Calendar Color"></button>
                            <button type="button" class="color-swatch" data-color="#0d6efd" style="background:#0d6efd;" title="Blue"></button>
                            <button type="button" class="color-swatch" data-color="#198754" style="background:#198754;" title="Green"></button>
                            <button type="button" class="color-swatch" data-color="#dc3545" style="background:#dc3545;" title="Red"></button>
                            <button type="button" class="color-swatch" data-color="#fd7e14" style="background:#fd7e14;" title="Orange"></button>
                            <button type="button" class="color-swatch" data-color="#6f42c1" style="background:#6f42c1;" title="Purple"></button>
                            <button type="button" class="color-swatch" data-color="#20c997" style="background:#20c997;" title="Teal"></button>
                        </div>
                        <input type="hidden" name="ics_color" id="ics_color" value="inherit">
                    </div>

                    <div class="field-group">
                        <input class="form-control" type="file" name="ics_file" accept=".ics" required>
                    </div>
                    <button type="submit" id="submitBtn">Upload ICS</button>
                </form>
                <dialog id="waitPopup">
                    <p>Please wait while we process your ICS...</p>
                </dialog>
            </div>
        </div>

        <div class="forms-column">
            <div>
                <h2>Add Calendar Event</h2>
                <form method="POST">
                    <div class="field-group">
                        <label for="event_calendar_group">Calendar</label>
                        <select class="form-control" name="event_calendar_group" id="event_calendar_group">
                            <option value="">Default / No Calendar</option>
                            <?php foreach ($calendarGroups as $group): ?>
                                <option value="<?= (int)$group['id'] ?>"><?= htmlspecialchars($group['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field-group">
                        <div class="color-row">
                            <label for="event_color">Choose color:</label>
                            <span class="color-preview" id="eventPreview"></span>
                        </div>
                        <div class="color-palette" data-hidden="event_color" data-preview="eventPreview">
                            <button type="button" class="color-swatch swatch-inherit active" data-color="inherit" title="Use Calendar Color"></button>
                            <button type="button" class="color-swatch" data-color="#0d6efd" style="background:#0d6efd;" title="Blue"></button>
                            <button type="button" class="color-swatch" data-color="#198754" style="background:#198754;" title="Green"></button>
                            <button type="button" class="color-swatch" data-color="#dc3545" style="background:#dc3545;" title="Red"></button>
                            <button type="button" class="color-swatch" data-color="#fd7e14" style="background:#fd7e14;" title="Orange"></button>
                            <button type="button" class="color-swatch" data-color="#6f42c1" style="background:#6f42c1;" title="Purple"></button>
                            <button type="button" class="color-swatch" data-color="#20c997" style="background:#20c997;" title="Teal"></button>
                        </div>
                        <input type="hidden" name="event_color" id="event_color" value="inherit">
                    </div>

                    <div class="field-group">
                        <label for="event_title">Title:</label>
                        <input class="form-control" type="text" name="event_title" id="event_title" required>
                    </div>

                    <div class="field-group">
                        <label for="event_start">Start:</label>
                        <input class="form-control" type="datetime-local" name="event_start" id="event_start" required>
                    </div>

                    <div class="field-group">
                        <label for="event_end">End:</label>
                        <input class="form-control" type="datetime-local" name="event_end" id="event_end">
                    </div>

                    <div class="field-group">
                        <label for="event_description">Description:</label>
                        <input class="form-control" type="text" name="event_description" id="event_description">
                    </div>

                    <div class="field-group">
                        <label for="event_location">Location:</label>
                        <input class="form-control" type="text" name="event_location" id="event_location">
                    </div>

                    <div class="field-group">
                        <label>
                            <input type="checkbox" name="event_all_day" value="1"> All day event
                        </label>
                    </div>

                    <div class="field-group recurrence-fieldset">
                        <label>
                            <input type="checkbox" class="repeat-toggle" data-target="event" name="event_repeat" id="event_repeat" value="1">
                            Repeat this event
                        </label>
                        <div class="recurrence-options" id="event_recurrence_options" style="display:none;">
                            <div class="field-group">
                                <label for="event_repeat_freq">Frequency</label>
                                <select class="form-control" name="event_repeat_freq" id="event_repeat_freq">
                                    <option value="daily">Daily</option>
                                    <option value="weekly" selected>Weekly</option>
                                    <option value="monthly">Monthly</option>
                                    <option value="yearly">Yearly</option>
                                </select>
                            </div>
                            <div class="field-group">
                                <label for="event_repeat_interval">Repeat every</label>
                                <input class="form-control" type="number" min="1" value="1" name="event_repeat_interval" id="event_repeat_interval">
                            </div>
                            <div class="field-group">
                                <label>Ends</label><br>
                                <label><input type="radio" name="event_repeat_end_type" value="count" checked> After
                                    <input type="number" min="1" value="10" name="event_repeat_count" id="event_repeat_count" style="width:70px;"> occurrences
                                </label><br>
                                <label><input type="radio" name="event_repeat_end_type" value="until"> On date
                                    <input type="date" name="event_repeat_until" id="event_repeat_until">
                                </label>
                            </div>
                        </div>
                    </div>

                    <button type="submit">Add Event</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div id="eventEditorModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.45); z-index:9999;">
    <div style="max-width:420px; margin:80px auto; background:#fff; padding:18px; border-radius:8px;">
        <h3>Edit Event</h3>
        <form id="eventEditorForm">
            <input type="hidden" id="editorEventId">
            <input type="hidden" id="editorSeriesKey">
            <div class="field-group">
                <label for="editorCalendarGroup">Calendar</label>
                <select class="form-control" id="editorCalendarGroup">
                    <option value="">Default / No Calendar</option>
                    <?php foreach ($calendarGroups as $group): ?>
                        <option value="<?= (int)$group['id'] ?>"><?= htmlspecialchars($group['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field-group">
                <label>Title</label>
                <input class="form-control" type="text" id="editorTitle">
            </div>
            <div class="field-group">
                <label>Start</label>
                <input class="form-control" type="datetime-local" id="editorStart">
            </div>
            <div class="field-group">
                <label>End</label>
                <input class="form-control" type="datetime-local" id="editorEnd">
            </div>
            <div class="field-group">
                <div class="color-row">
                    <label>Color</label>
                    <span class="color-preview" id="editorPreview"></span>
                </div>
                <div class="color-palette" data-hidden="editorColor" data-preview="editorPreview">
                    <button type="button" class="color-swatch swatch-inherit" data-color="inherit" title="Use Calendar Color"></button>
                    <button type="button" class="color-swatch" data-color="#0d6efd" style="background:#0d6efd;" title="Blue"></button>
                    <button type="button" class="color-swatch" data-color="#198754" style="background:#198754;" title="Green"></button>
                    <button type="button" class="color-swatch" data-color="#dc3545" style="background:#dc3545;" title="Red"></button>
                    <button type="button" class="color-swatch" data-color="#fd7e14" style="background:#fd7e14;" title="Orange"></button>
                    <button type="button" class="color-swatch" data-color="#6f42c1" style="background:#6f42c1;" title="Purple"></button>
                    <button type="button" class="color-swatch" data-color="#20c997" style="background:#20c997;" title="Teal"></button>
                </div>
                <input type="hidden" id="editorColor" value="inherit">
            </div>
            <div style="display:flex; gap:8px;">
                <button type="submit" id="saveEventButton" class="btn btn-primary">Save</button>
                <button type="button" id="saveSeriesButton" class="btn btn-success">Save Series</button>
                <button type="button" id="deleteEventButton">Delete</button>
                <button type="button" id="deleteSeriesButton">Delete Series</button>
                <button type="button" id="closeEventEditor" class="btn btn-secondary">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('icsForm').addEventListener('submit', function() {
    const popup = document.getElementById('waitPopup');
    const btn = document.getElementById('submitBtn');
    
    popup.showModal();
    btn.disabled = true;
    btn.innerText = "Uploading...";
});
</script>

<script>
    function syncColorPreview() {
        document.querySelectorAll('.color-palette').forEach(function(palette) {
            var hidden = document.getElementById(palette.dataset.hidden);
            var preview = document.getElementById(palette.dataset.preview);
            if (!hidden || !preview) {
                return;
            }

            // Inside your syncColorPreview() function in dashboard.php
            var selectedColor = hidden.value || 'inherit'; // If backend renders empty/null, default to 'inherit'

            palette.querySelectorAll('.color-swatch').forEach(function(button) {
                var isActive = button.dataset.color === selectedColor;
                button.classList.toggle('active', isActive);
            });

            // Adjust the preview box style dynamically depending on the selected selection
            if (selectedColor === 'inherit' || selectedColor === '') {
                preview.style.background = 'linear-gradient(135deg, #e5e7eb 50%, #9ca3af 50%)';
            } else {
                preview.style.background = selectedColor;
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.color-palette').forEach(function(palette) {
            var hidden = document.getElementById(palette.dataset.hidden);
            if (!hidden) {
                return;
            }

            palette.querySelectorAll('.color-swatch').forEach(function(button) {
                button.addEventListener('click', function() {
                    hidden.value = button.dataset.color;
                    syncColorPreview();
                });
            });
        });

        syncColorPreview();
        window.syncColorPreview = syncColorPreview;
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.repeat-toggle').forEach(function(checkbox) {
            var target = checkbox.dataset.target;
            var options = document.getElementById(target + '_recurrence_options');
            if (!options) {
                return;
            }

            checkbox.addEventListener('change', function() {
                options.style.display = checkbox.checked ? 'block' : 'none';
            });
        });
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var groupsList = document.getElementById('calendarGroupsList');
        var saveSeriesButton = document.getElementById('saveSeriesButton');

        groupsList.addEventListener('click', function(e) {
            var toggleBtn = e.target.closest('.toggle-group-btn');
            var deleteBtn = e.target.closest('.delete-group-btn');

            if (toggleBtn) {
                var groupId = toggleBtn.dataset.groupId;
                fetch('manage_calendar_group.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'toggle', group_id: groupId })
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.success) {
                        toggleBtn.dataset.visible = data.visible;
                        toggleBtn.textContent = data.visible ? 'Hide' : 'Show';
                        if (window.calendar) {
                            window.calendar.refetchEvents();
                        }
                    } else {
                        alert(data.message || 'Failed to update calendar');
                    }
                });
                return;
            }

            if (deleteBtn) {
                if (!confirm('Delete this calendar? Events and tasks in it will be kept but unassigned from any calendar.')) {
                    return;
                }
                var groupId = deleteBtn.dataset.groupId;
                fetch('manage_calendar_group.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', group_id: groupId })
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert(data.message || 'Failed to delete calendar');
                    }
                });
            }
        });
    });
</script>

<link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.10.1/main.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.10.1/main.js"></script>
<script src="assets/js/calendar.js?v=6"></script>

<?php include 'templates/footer.php'; ?>