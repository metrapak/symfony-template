/*
 * Progressive enhancement for the purchase approval screens (FR-094, FR-096, NFR-094).
 *
 * Two things, and neither is load-bearing:
 *
 *   1. A live countdown beside each expiry. The deadline itself is already on the page as a real
 *      <time datetime> with a readable date — see approval/_countdown.html.twig — because a
 *      countdown that only exists while JavaScript runs would make NFR-094's "countdown not the
 *      only expiry indicator" false. This adds "1 day, 4 hours left" and refreshes it once a
 *      minute.
 *   2. A confirmation on Deny. Built on the native <dialog>, for the reasons confirm-dialog.js
 *      gives: showModal() traps focus, closes on Escape and carries the right semantics with no
 *      ARIA of ours to get wrong; window.confirm() is ruled out; Stimulus is not installed.
 *
 * This file confirms a *button* where confirm-dialog.js confirms a *form*, and that difference is
 * the requirement: one form carries both Approve and Deny (the Deny button posts elsewhere with
 * formaction), so a form-level confirmation would ask the parent to confirm approving as well.
 *
 * Without JavaScript, Deny submits directly and the countdown never appears. Both are correct
 * fallbacks: the confirmation is a courtesy, and the deadline is already legible.
 */

const MINUTE = 60 * 1000;

function remainingText(deadline, now) {
    const ms = deadline.getTime() - now.getTime();

    if (ms <= 0) {
        return '(expired)';
    }

    const totalMinutes = Math.floor(ms / MINUTE);
    const days = Math.floor(totalMinutes / (60 * 24));
    const hours = Math.floor((totalMinutes % (60 * 24)) / 60);
    const minutes = totalMinutes % 60;

    const parts = [];

    if (days > 0) {
        parts.push(`${days} day${days === 1 ? '' : 's'}`);
    }

    if (days > 0 || hours > 0) {
        parts.push(`${hours} hour${hours === 1 ? '' : 's'}`);
    }

    if (days === 0) {
        parts.push(`${minutes} minute${minutes === 1 ? '' : 's'}`);
    }

    return `(${parts.join(', ')} left)`;
}

function startCountdown(time) {
    const deadline = new Date(time.getAttribute('datetime'));

    if (Number.isNaN(deadline.getTime())) {
        return;
    }

    // The target sits beside the date rather than replacing it, so the absolute deadline stays on
    // the page. It is not a live region: a value that announced itself every minute would
    // interrupt a screen-reader user reading the request it belongs to.
    const target = time.parentElement?.querySelector('[data-countdown-target]');

    if (!target) {
        return;
    }

    const tick = () => {
        target.textContent = remainingText(deadline, new Date());
    };

    tick();
    window.setInterval(tick, MINUTE);
}

function buildDialog(message, confirmLabel) {
    const dialog = document.createElement('dialog');

    const text = document.createElement('p');
    text.textContent = message;

    const actions = document.createElement('div');
    actions.className = 'dialog-actions';

    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.textContent = 'Cancel';

    const confirm = document.createElement('button');
    confirm.type = 'button';
    confirm.textContent = confirmLabel;

    actions.append(cancel, confirm);
    dialog.append(text, actions);

    return { dialog, cancel, confirm };
}

function enhanceButton(button) {
    const message = button.dataset.confirmAction;
    const form = button.form;

    if (!message || !form || typeof HTMLDialogElement === 'undefined') {
        return;
    }

    const { dialog, cancel, confirm } = buildDialog(message, button.dataset.confirmLabel || 'Confirm');
    form.append(dialog);

    let confirmed = false;

    button.addEventListener('click', (event) => {
        if (confirmed) {
            return;
        }

        event.preventDefault();
        dialog.showModal();
        // Cancel holds the initial focus, so Enter on a dialog nobody read does nothing.
        cancel.focus();
    });

    cancel.addEventListener('click', () => dialog.close());

    confirm.addEventListener('click', () => {
        confirmed = true;
        dialog.close();
        // requestSubmit(button) rather than form.submit(): it carries the button's own
        // formaction, which is what sends the deny to the deny route.
        form.requestSubmit(button);
    });
}

document.querySelectorAll('time[data-countdown]').forEach(startCountdown);
document.querySelectorAll('button[data-confirm-action]').forEach(enhanceButton);
