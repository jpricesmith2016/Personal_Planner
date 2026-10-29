<?php
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit;
    }
}

// Hard ceiling on how many occurrences a single recurring series can
// expand to. Protects against runaway input (e.g. a daily series with no
// end date) blowing up the database with an unbounded insert loop.
const RECURRENCE_MAX_OCCURRENCES = 365;

/**
 * Build a short, human-readable label for a recurrence rule, e.g.
 * "Weekly, every 2 weeks, until 2026-09-01" or "Daily, 10 times".
 * Returns null if the rule is empty/invalid so callers can store NULL.
 */
function describeRecurrenceRule($freq, $interval, $endType, $endValue) {
    $freq = strtolower(trim((string)$freq));
    if (!in_array($freq, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
        return null;
    }

    $interval = max(1, (int)$interval);

    $labels = [
        'daily' => ['day', 'days'],
        'weekly' => ['week', 'weeks'],
        'monthly' => ['month', 'months'],
        'yearly' => ['year', 'years'],
    ];
    $unit = $interval === 1 ? $labels[$freq][0] : $labels[$freq][1];

    $summary = ucfirst($freq) . ", every {$interval} {$unit}";

    if ($endType === 'until' && !empty($endValue)) {
        $summary .= ", until {$endValue}";
    } elseif ($endType === 'count' && (int)$endValue > 0) {
        $summary .= ", " . (int)$endValue . " times";
    }

    return $summary;
}

/**
 * Expand a recurrence definition into a list of DateTime start offsets,
 * relative to the series' own first occurrence ($firstStart).
 *
 * $freq: 'daily' | 'weekly' | 'monthly' | 'yearly'
 * $interval: repeat every N units (minimum 1)
 * $endType: 'until' (stop after a given date) | 'count' (stop after N occurrences)
 * $endValue: a 'Y-m-d' date string for 'until', or an integer for 'count'
 *
 * Always includes the first occurrence itself as the first entry.
 * Caps at RECURRENCE_MAX_OCCURRENCES regardless of end condition.
 */
function expandRecurrenceDates(DateTime $firstStart, $freq, $interval, $endType, $endValue) {
    $freq = strtolower(trim((string)$freq));
    $interval = max(1, (int)$interval);

    $modifyStep = [
        'daily' => "+{$interval} day",
        'weekly' => "+{$interval} week",
        'monthly' => "+{$interval} month",
        'yearly' => "+{$interval} year",
    ];

    if (!isset($modifyStep[$freq])) {
        return [clone $firstStart];
    }

    $until = null;
    if ($endType === 'until' && !empty($endValue)) {
        $until = new DateTime($endValue);
        // Treat the until date as inclusive through the end of that day.
        $until->setTime(23, 59, 59);
    }

    $count = null;
    if ($endType === 'count' && (int)$endValue > 0) {
        $count = (int)$endValue;
    }

    $dates = [clone $firstStart];
    $current = clone $firstStart;

    while (count($dates) < RECURRENCE_MAX_OCCURRENCES) {
        if ($count !== null && count($dates) >= $count) {
            break;
        }

        $current = clone $current;
        $current->modify($modifyStep[$freq]);

        if ($until !== null && $current > $until) {
            break;
        }

        $dates[] = $current;

        // If neither an "until" nor a "count" was given, don't expand
        // forever -- fall back to the same cap used everywhere else.
        if ($until === null && $count === null && count($dates) >= RECURRENCE_MAX_OCCURRENCES) {
            break;
        }
    }

    return $dates;
}