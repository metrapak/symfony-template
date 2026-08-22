/*
 * Progressive enhancement for the availability grid (FR-080, FR-082, NFR-081).
 *
 * The grid works with this file absent: every cell is a native checkbox inside a real table, so
 * clicking, tabbing, space-toggling and submitting all happen without JavaScript. Nothing here
 * is required for the form to be usable — which is why every control it adds starts out `hidden`
 * in the markup and is revealed only once this has run.
 *
 * What it adds:
 *
 *  - drag across cells to select or clear a run of them, which is the difference between ticking
 *    three hours and ticking twenty-one;
 *  - "copy Monday to all weekdays", for the coach whose week is the same five days;
 *  - "clear all";
 *  - a running count in the page's `aria-live` region, so a keyboard or screen-reader user hears
 *    what changed rather than having to re-read the grid.
 *
 * The count is deliberately a count and not a written-out summary ("Mon 5-8pm, Wed 6-9pm"). That
 * sentence is `AvailabilitySummarizer`'s, and it is the summary trainers read; a second
 * implementation of it here would be a second answer to the same question, and the one on screen
 * while editing would be the one nobody had tested.
 *
 * Marking a day "not available" clears and disables that day's cells, because the server rejects a
 * day that claims both (`DayAvailabilityInput::validateConsistency`). Doing it here means a
 * JavaScript user never meets that error; leaving the server rule in place means a user without
 * JavaScript still cannot save a contradiction.
 */

function cellsOf(grid, dayKey) {
    return Array.from(grid.querySelectorAll(`td.cell[data-day="${dayKey}"] input[data-availability-cell]`));
}

function allCells(grid) {
    return Array.from(grid.querySelectorAll('input[data-availability-cell]'));
}

function unavailableBoxes(grid) {
    return Array.from(grid.querySelectorAll('input[data-availability-unavailable]'));
}

function announce(form, grid) {
    const summary = form.querySelector('[data-availability-summary]');

    if (!summary) {
        return;
    }

    const selected = allCells(grid).filter((cell) => cell.checked && !cell.disabled);
    const days = new Set(selected.map((cell) => cell.closest('td').dataset.day));
    const closedDays = unavailableBoxes(grid).filter((box) => box.checked).length;

    if (selected.length === 0 && closedDays === 0) {
        summary.textContent = 'Nothing selected yet.';

        return;
    }

    const parts = [];

    if (selected.length > 0) {
        parts.push(`${selected.length} ${selected.length === 1 ? 'slot' : 'slots'} selected across ${days.size} ${days.size === 1 ? 'day' : 'days'}`);
    }

    if (closedDays > 0) {
        parts.push(`${closedDays} ${closedDays === 1 ? 'day' : 'days'} marked not available`);
    }

    summary.textContent = `${parts.join(', ')}.`;
}

function applyUnavailable(grid, box) {
    const dayKey = box.dataset.availabilityUnavailable;

    cellsOf(grid, dayKey).forEach((cell) => {
        cell.disabled = box.checked;

        if (box.checked) {
            cell.checked = false;
        }
    });
}

function enableDragSelect(grid, onChange) {
    let painting = false;
    let paintTo = true;

    const paint = (cell) => {
        if (!cell || cell.disabled || cell.checked === paintTo) {
            return;
        }

        cell.checked = paintTo;
        onChange();
    };

    grid.addEventListener('pointerdown', (event) => {
        const cell = event.target.closest('input[data-availability-cell]');

        if (!cell || cell.disabled) {
            return;
        }

        // The browser is about to toggle this cell itself, so the run continues in whichever
        // direction that click is going.
        painting = true;
        paintTo = !cell.checked;

        // Pointer capture keeps the events coming while the pointer leaves the cell it started
        // in, which is what makes a drag across a row work on touch as well as with a mouse.
        if (event.pointerId !== undefined && grid.setPointerCapture) {
            try {
                grid.setPointerCapture(event.pointerId);
            } catch {
                // Some pointer types refuse capture; dragging then works within the table only.
            }
        }
    });

    grid.addEventListener('pointermove', (event) => {
        if (!painting) {
            return;
        }

        // `event.target` is the element under the pointer only while it is inside the grid; for a
        // captured pointer it stays the grid, so the cell is resolved from coordinates.
        const element = document.elementFromPoint(event.clientX, event.clientY);
        paint(element ? element.closest('input[data-availability-cell]') : null);
    });

    const stop = () => {
        painting = false;
    };

    grid.addEventListener('pointerup', stop);
    grid.addEventListener('pointercancel', stop);
    window.addEventListener('blur', stop);
}

function enhance(form) {
    const grid = form.querySelector('[data-availability-grid]');

    if (!grid) {
        return;
    }

    const onChange = () => announce(form, grid);

    grid.addEventListener('change', (event) => {
        const box = event.target.closest('input[data-availability-unavailable]');

        if (box) {
            applyUnavailable(grid, box);
        }

        onChange();
    });

    // A day loaded as "not available" starts with its cells disabled, so the rendered state and
    // the interactive state agree before anybody clicks.
    unavailableBoxes(grid).forEach((box) => applyUnavailable(grid, box));

    enableDragSelect(grid, onChange);

    const copyButton = form.querySelector('[data-availability-copy-weekdays]');

    if (copyButton) {
        copyButton.hidden = false;
        copyButton.addEventListener('click', () => {
            const monday = cellsOf(grid, 'monday').map((cell) => cell.checked);

            ['tuesday', 'wednesday', 'thursday', 'friday'].forEach((dayKey) => {
                const box = grid.querySelector(`input[data-availability-unavailable="${dayKey}"]`);

                if (box && box.checked) {
                    // Copying into a day somebody has closed would silently reopen it.
                    return;
                }

                cellsOf(grid, dayKey).forEach((cell, index) => {
                    cell.checked = monday[index] ?? false;
                });
            });

            onChange();
        });
    }

    const clearButton = form.querySelector('[data-availability-clear]');

    if (clearButton) {
        clearButton.hidden = false;
        clearButton.addEventListener('click', () => {
            allCells(grid).forEach((cell) => {
                cell.checked = false;
            });
            unavailableBoxes(grid).forEach((box) => {
                box.checked = false;
                applyUnavailable(grid, box);
            });
            onChange();
        });
    }

    onChange();
}

document.querySelectorAll('form[data-availability-form]').forEach(enhance);
