// FIX 1: Declare calendar and eventEditorModal globally so ALL functions can access them
var calendar;
var eventEditorModal;

document.addEventListener('DOMContentLoaded', function() {
    console.log('calendar.js loaded');
    var calendarEl = document.getElementById('calendar');
    var showCompletedCheckbox = document.getElementById('showCompletedCheckbox');
    var calendarViewSelect = document.getElementById('calendarViewSelect');
    
    // Assign global variables (removed the 'var' keyword from these two lines)
    eventEditorModal = document.getElementById('eventEditorModal');
    eventEditorForm = document.getElementById('eventEditorForm');
    
    var closeEventEditor = document.getElementById('closeEventEditor');
    var deleteEventButton = document.getElementById('deleteEventButton');
    var deleteSeriesButton = document.getElementById('deleteSeriesButton');
    var editorEventId = document.getElementById('editorEventId');
    var editorSeriesKey = document.getElementById('editorSeriesKey');
    var editorCalendarGroup = document.getElementById('editorCalendarGroup');
    var editorTitle = document.getElementById('editorTitle');
    var editorStart = document.getElementById('editorStart');
    var editorEnd = document.getElementById('editorEnd');
    var editorColor = document.getElementById('editorColor');
    var saveEventButton = document.getElementById('saveEventButton');
    var saveSeriesButton = document.getElementById('saveSeriesButton');

    var modalDropdown = document.getElementById('editorCalendarGroup');
    if (modalDropdown) {
        modalDropdown.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    }

    // Initialize calendar globally
    calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'timeGridWeek',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay'
        },
        views: {
            timeGridWeek: {
                titleFormat: { year: 'numeric', month: 'short', day: 'numeric' },
                slotMinTime: '00:00:00',
                slotMaxTime: '24:00:00',
                slotDuration: '00:30:00',
                slotLabelInterval: '01:00:00'
            },
            timeGridDay: {
                slotMinTime: '00:00:00',
                slotMaxTime: '24:00:00',
                slotDuration: '00:30:00',
                slotLabelInterval: '01:00:00'
            }
        },

        displayEventTime: true,
        displayEventEnd: false,

        eventTimeFormat: {
            hour: 'numeric',
            minute: '2-digit',
        },
        nowIndicator: true,
        editable: true,
        selectable: true,
        displayEventTime: true,
        eventDisplay: 'block',
        dayMaxEvents: true,
        slotDuration: '00:30:00',
        events: function(info, successCallback, failureCallback) {
            var url = 'get_calendar_events.php';
            if (showCompletedCheckbox.checked) {
                url += '?show_completed=true';
            }

            fetch(url)
                .then(response => response.json())
                .then(data => {
                    successCallback(data);
                })
                .catch(error => {
                    failureCallback(error);
                });
        },
        eventDidMount: function(info) {
            var event = info.event;
            var el = info.el;
            var isTask = event.extendedProps.type === 'task' || event.type === 'task';

            if (isTask) {
                var checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.className = 'event-complete-checkbox';
                checkbox.checked = event.extendedProps.status === 'Completed';

                checkbox.addEventListener('click', function(e) {
                    e.stopPropagation();
                });

                checkbox.addEventListener('change', function(e) {
                    var newStatus = checkbox.checked ? 'Completed' : 'Pending';
                    updateTaskStatus(event.id, newStatus, 'task');
                    event.setProp('color', newStatus === 'Completed' ? '#9be6a3' : '#cfe8ff');
                    event.setExtendedProp('status', newStatus);
                });

                var titleEl = el.querySelector('.fc-event-title') || el.querySelector('.fc-title') || el;
                titleEl.prepend(checkbox);
            }
        },
        eventClick: function(info) {
            if (info.event.extendedProps.type === 'task') {
                return;
            }
            openEventEditor(info.event);
        },
        eventDrop: function(info) {
            updateEventDate(info.event);
        },
        eventResize: function(info) {
            updateEventDate(info.event);
        },
        select: function(info) {
            if (info.view.type !== 'dayGridMonth') {
                // no-op
            }
        }
    });

    calendar.render();
    window.calendar = calendar;
    window.eventEditorModal = eventEditorModal;

    if (calendarViewSelect) {
        calendarViewSelect.addEventListener('change', function() {
            calendar.changeView(calendarViewSelect.value);
        });
    }

    showCompletedCheckbox.addEventListener('change', function() {
        calendar.refetchEvents();
    });

    // Clean close mapping
    closeEventEditor.addEventListener('click', function() {
        eventEditorModal.style.display = 'none';
        document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
        document.body.classList.remove('modal-open');
    });

    deleteEventButton.addEventListener('click', function() {
        deleteEvent(editorEventId.value, false);
    });

    deleteSeriesButton.addEventListener('click', function() {
        deleteEvent(editorEventId.value, true);
    });

    // Intercept form submission completely
    if (eventEditorForm) {
        eventEditorForm.addEventListener('submit', function(e) {
            e.preventDefault();
        });
    }

    // Bind Button Click Controls Safely
    if (saveEventButton) {
        saveEventButton.onclick = function(e) {
            e.preventDefault();
            saveEventEditor(false);
        };
    }

    if (saveSeriesButton) {
        saveSeriesButton.onclick = function(e) {
            e.preventDefault();
            saveEventEditor(true);
        };
    }

    function openEventEditor(event) {
        editorEventId.value = event.id;
        editorSeriesKey.value = event.extendedProps.seriesKey || '';

        if (saveSeriesButton) {
            if (event.extendedProps.seriesKey) {
                saveSeriesButton.style.display = 'inline-block';
            } else {
                saveSeriesButton.style.display = 'none';
            }
        }

        if (editorCalendarGroup) {
            editorCalendarGroup.value = event.extendedProps.calendarGroupId || '';
        }
        editorTitle.value = event.title;
        editorStart.value = formatForInput(event.start);
        editorEnd.value = formatForInput(event.end || event.start);

        editorColor.value = event.extendedProps.customColor || 'inherit';

        if (window.syncColorPreview) {
            window.syncColorPreview();
        }
        eventEditorModal.style.display = 'block';
    }

    function saveEventEditor(updateSeries) {
        try {
            var payload = {
                action: 'update',
                id: document.getElementById('editorEventId') ? document.getElementById('editorEventId').value : 0,
                title: document.getElementById('editorTitle') ? document.getElementById('editorTitle').value.trim() : '',
                start_at: document.getElementById('editorStart') ? document.getElementById('editorStart').value : null,
                end_at: document.getElementById('editorEnd') ? document.getElementById('editorEnd').value : null,
                color: document.getElementById('editorColor') ? document.getElementById('editorColor').value : 'inherit', 
                series_key: (document.getElementById('editorSeriesKey') && document.getElementById('editorSeriesKey').value !== '') ? document.getElementById('editorSeriesKey').value : null,
                calendar_group_id: (document.getElementById('editorCalendarGroup') && document.getElementById('editorCalendarGroup').value !== '') ? document.getElementById('editorCalendarGroup').value : null,
                update_series: updateSeries ? 1 : 0
            };

            if (!payload.id || !payload.title) {
                alert("Event title cannot be empty.");
                return;
            }

            fetch('update_event.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(response => {
                if (!response.ok) throw new Error('HTTP network error');
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    // Modal cleanup closure wrapper
                    if (eventEditorModal) {
                        eventEditorModal.style.display = 'none';
                        eventEditorModal.classList.remove('show');
                    }
                    
                    document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
                    document.body.classList.remove('modal-open');
                    document.body.style.overflow = '';
                    document.body.style.paddingRight = '';
                
                    if (eventEditorForm) eventEditorForm.reset();
                    if (document.activeElement) document.activeElement.blur();
                
                    if (typeof calendar !== 'undefined') {
                        calendar.removeAllEvents();
                        calendar.refetchEvents();
                    }
                } else {
                    alert(data.message || 'Failed to update event');
                }
            })
            .catch(error => {
                console.error('Fetch Error:', error);
                alert('A background server error occurred while saving.');
            });

        } catch (err) {
            console.error('Initialization failure inside saveEventEditor:', err);
        }
    }

    function formatForInput(date) {
        if (!date) return '';
        var d = new Date(date);
        if (isNaN(d.getTime())) return '';
        var local = new Date(d.getTime() - d.getTimezoneOffset() * 60000);
        return local.toISOString().slice(0, 16);
    }
});

// STANDALONE GLOBAL BACKEND ACTIONS
function updateTaskStatus(taskId, status, type) {
    fetch('update_task_status.php', {
        method: 'POST',
        body: JSON.stringify({ id: taskId, status: status, type: type }),
        headers: { 'Content-Type': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) console.error('Failed to update status', data);
    });
}

function updateEventDate(event) {
    fetch('update_event.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            id: event.id,
            title: event.title,
            start_at: event.startStr,
            end_at: event.endStr,
            color: event.extendedProps.customColor || 'inherit',
            series_key: event.extendedProps.seriesKey || null,
            calendar_group_id: event.extendedProps.calendarGroupId || ''
        })
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) console.error('Failed to update event details', data);
    });
}

function deleteEvent(eventId, deleteSeries) {
    fetch('update_event.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            id: eventId,
            action: 'delete',
            delete_series: deleteSeries ? 1 : 0
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Safe modal close
            if (eventEditorModal) {
                eventEditorModal.style.display = 'none';
                eventEditorModal.classList.remove('show');
            }

            // Remove blackouts completely
            document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';

            if (document.getElementById('eventEditorForm')) {
                document.getElementById('eventEditorForm').reset();
            }

            // FIX 2: Since 'calendar' variable is defined globally now, this won't crash!
            if (typeof calendar !== 'undefined' && calendar !== null) {
                calendar.removeAllEvents();
                calendar.refetchEvents();
            } else {
                window.location.reload();
            }
        } else {
            alert(data.message || 'Failed to delete event');
        }
    })
    .catch(err => console.error('Delete error encountered:', err));
}