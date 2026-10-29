<?php
require_once 'config.php';

function parseIcsDateValue($value) {
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m)) {
        return [
            'date' => sprintf('%s-%s-%s', $m[1], $m[2], $m[3]),
            'all_day' => true,
        ];
    }

    if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z)?$/', $value, $m)) {
        $date = sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
        if (!empty($m[7])) {
            $date = gmdate('Y-m-d H:i:s', strtotime($date . ' UTC'));
        }
        return [
            'date' => $date,
            'all_day' => false,
        ];
    }

    if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})$/', $value, $m)) {
        $date = sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
        return [
            'date' => $date,
            'all_day' => false,
        ];
    }

    $parsed = strtotime($value);
    return [
        'date' => $parsed !== false ? date('Y-m-d H:i:s', $parsed) : date('Y-m-d H:i:s'),
        'all_day' => false,
    ];
}

function expandRecurringEvent($event) {
    if (empty($event['RRULE']) || empty($event['DTSTART'])) {
        return [$event];
    }

    $start = parseIcsDateValue($event['DTSTART']);
    $occurrences = [];

    if (!preg_match('/FREQ=WEEKLY/i', $event['RRULE'])) {
        return [$event];
    }

    preg_match('/BYDAY=([^;]+)/i', $event['RRULE'], $daysMatch);
    preg_match('/UNTIL=([0-9TZ]+)/i', $event['RRULE'], $untilMatch);

    if (empty($daysMatch[1])) {
        return [$event];
    }

    $days = explode(',', $daysMatch[1]);

    $until = !empty($untilMatch[1])
        ? parseIcsDateValue($untilMatch[1])['date']
        : null;

    $current = new DateTime($start['date']);

    while (true) {
        $dayCode = strtoupper($current->format('D'));

        $map = [
            'MON' => 'MO',
            'TUE' => 'TU',
            'WED' => 'WE',
            'THU' => 'TH',
            'FRI' => 'FR',
            'SAT' => 'SA',
            'SUN' => 'SU'
        ];

        if (in_array($map[$dayCode], $days)) {
            $copy = $event;

            $copy['DTSTART'] = $current->format('Ymd\THis');

            $occurrences[] = $copy;
        }

        if ($until && $current >= new DateTime($until)) {
            break;
        }

        $current->modify('+1 day');

        // Safety limit
        if (count($occurrences) > 500) {
            break;
        }
    }

    return $occurrences;
}


function parseIcsContent($content) {
    $lines = preg_split("/\r\n|\n|\r/", $content);

    $events = [];
    $current = null;

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '') {
            continue;
        }

        if ($line === 'BEGIN:VEVENT') {
            $current = [];
            continue;
        }

        if ($line === 'END:VEVENT') {
            if ($current && isset($current['DTSTART'])) {
                $events[] = $current;
            }

            $current = null;
            continue;
        }

        if ($current === null) {
            continue;
        }

        if (strpos($line, ':') !== false) {
            [$key, $value] = explode(':', $line, 2);

            // Remove parameters:
            // DTSTART;TZID=America/Denver:
            $key = strtoupper(explode(';', $key)[0]);

            $current[$key] = trim($value);
        }
    }


    $parsed = [];

    foreach ($events as $event) {

        foreach (expandRecurringEvent($event) as $instance) {

            $start = parseIcsDateValue($instance['DTSTART']);

            if (isset($instance['DURATION'])) {

                preg_match(
                    '/PT(?:(\d+)H)?(?:(\d+)M)?/',
                    $instance['DURATION'],
                    $duration
                );

                $minutes =
                    (($duration[1] ?? 0) * 60)
                    + ($duration[2] ?? 0);

                $end = new DateTime($start['date']);
                $end->modify("+{$minutes} minutes");

                $end = [
                    'date' => $end->format('Y-m-d H:i:s')
                ];

            } else {
                $end = isset($instance['DTEND'])
                    ? parseIcsDateValue($instance['DTEND'])
                    : $start;
            }


            $parsed[] = [
                'title' => $instance['SUMMARY'] ?? 'Untitled Event',
                'description' => $instance['DESCRIPTION'] ?? null,
                'location' => $instance['LOCATION'] ?? null,

                // unique per occurrence
                'uid' =>
                    ($instance['UID'] ?? uniqid())
                    . '-'
                    . $start['date'],

                // same calendar series
                'series_key' =>
                    $instance['UID'] ?? uniqid(),

                'start_at' => $start['date'],
                'end_at' => $end['date'],

                'all_day' => $start['all_day'],

                'status' => 'Pending'
            ];
        }
    }

    return $parsed;
}

// Note: createCalendarItem() lives in includes/db.php. Any file using
// importIcsEvents() must require_once includes/db.php (not just config.php),
// so the canonical implementation -- backed by the shared getDb() connection --
// is the one in scope. A second, divergent copy used to live here, opening its
// own separate PDO connection. That duplicate is what caused calendar-group
// assignment to behave inconsistently and imported events to appear "bundled"
// into the wrong calendar. Do not re-add a copy of it here.

function importIcsEvents($user_id, $uploadedFile, $color = '#0d6efd', $calendar_group_id = null) {
    if (!is_uploaded_file($uploadedFile)) {
        return false;
    }

    $content = file_get_contents($uploadedFile);
    if ($content === false) {
        return false;
    }

    $events = parseIcsContent($content);

    $imported = 0;
    foreach ($events as $event) {
        $created = createCalendarItem(
            $user_id,
            'event',
            $event['title'],
            $event['start_at'],
            $event['end_at'],
            $event['all_day'],
            $event['status'],
            $event['description'],
            $event['location'],
            $color,
            $event['series_key'],
            $calendar_group_id
        );

        if ($created) {
            $imported++;
        }
    }

    return $imported;
}

// ---------------------------------------------------------------------
// Export: build an .ics file for a single calendar (calendar_group).
// ---------------------------------------------------------------------

// Per RFC 5545, commas/semicolons/backslashes must be escaped and
// newlines turned into the literal two-character sequence "\n".
function icsEscapeText($value) {
    $value = (string)$value;
    $value = str_replace(['\\', "\r\n", "\n", "\r", ',', ';'], ['\\\\', '\\n', '\\n', '\\n', '\\,', '\\;'], $value);
    return $value;
}

// Lines longer than 75 octets should be folded with a leading space on
// the continuation line, per RFC 5545 section 3.1.
function icsFoldLine($line) {
    if (strlen($line) <= 75) {
        return $line;
    }

    $folded = '';
    while (strlen($line) > 75) {
        $folded .= substr($line, 0, 75) . "\r\n ";
        $line = substr($line, 75);
    }
    $folded .= $line;

    return $folded;
}

function icsFormatDateTime($value, $allDay) {
    $date = new DateTime($value);

    if ($allDay) {
        return $date->format('Ymd');
    }

    return $date->format('Ymd\THis');
}

// Builds the full text of an .ics file for one calendar group's events
// and tasks. $group_id may be an int or null (the "Default / No
// Calendar" bucket). Every stored row is written out as its own
// VEVENT/VTODO -- recurring series are already materialized as
// individual rows sharing a series_key, so exporting them this way
// guarantees the file matches exactly what's on the calendar, with no
// risk of an RRULE diverging from rows that were edited individually.
function exportCalendarGroupToIcs($user_id, $group_id, $calendarName) {
    $items = fetchCalendarItemsByGroup($user_id, $group_id);
    $tasks = fetchTasksByGroup($user_id, $group_id);

    $lines = [];
    $lines[] = 'BEGIN:VCALENDAR';
    $lines[] = 'VERSION:2.0';
    $lines[] = 'PRODID:-//Personal Planner//EN';
    $lines[] = 'CALSCALE:GREGORIAN';
    $lines[] = icsFoldLine('X-WR-CALNAME:' . icsEscapeText($calendarName));

    $stamp = gmdate('Ymd\THis\Z');

    foreach ($items as $item) {
        $allDay = (bool)$item['all_day'];

        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:event-' . $item['id'] . '-' . $user_id . '@personal-planner';
        $lines[] = 'DTSTAMP:' . $stamp;

        if ($allDay) {
            $lines[] = 'DTSTART;VALUE=DATE:' . icsFormatDateTime($item['start_at'], true);
            if (!empty($item['end_at'])) {
                // ICS all-day DTEND is exclusive; bump by one day so a
                // single-day event round-trips as the same single day.
                $end = new DateTime($item['end_at']);
                $end->modify('+1 day');
                $lines[] = 'DTEND;VALUE=DATE:' . $end->format('Ymd');
            }
        } else {
            $lines[] = 'DTSTART:' . icsFormatDateTime($item['start_at'], false);
            if (!empty($item['end_at'])) {
                $lines[] = 'DTEND:' . icsFormatDateTime($item['end_at'], false);
            }
        }

        $lines[] = icsFoldLine('SUMMARY:' . icsEscapeText($item['title']));

        if (!empty($item['description'])) {
            $lines[] = icsFoldLine('DESCRIPTION:' . icsEscapeText($item['description']));
        }
        if (!empty($item['location'])) {
            $lines[] = icsFoldLine('LOCATION:' . icsEscapeText($item['location']));
        }

        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'END:VEVENT';
    }

    foreach ($tasks as $task) {
        $lines[] = 'BEGIN:VTODO';
        $lines[] = 'UID:task-' . $task['id'] . '-' . $user_id . '@personal-planner';
        $lines[] = 'DTSTAMP:' . $stamp;

        if (!empty($task['due_date'])) {
            $lines[] = 'DUE:' . icsFormatDateTime($task['due_date'], false);
        }

        $lines[] = icsFoldLine('SUMMARY:' . icsEscapeText($task['task_name']));
        $lines[] = 'STATUS:' . ($task['status'] === 'Completed' ? 'COMPLETED' : 'NEEDS-ACTION');
        $lines[] = 'END:VTODO';
    }

    $lines[] = 'END:VCALENDAR';

    return implode("\r\n", $lines) . "\r\n";
}