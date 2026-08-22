/*
 * Progressive enhancement for destructive actions (FR-024, FR-028, NFR-023).
 *
 * Every form this upgrades already works without JavaScript: it is a POST with a CSRF token,
 * and the server is the only thing that decides whether the action happens. What this adds is
 * a confirmation step, and it is deliberately built on the native <dialog> element rather than
 * on a framework:
 *
 *   - showModal() traps focus, closes on Escape and exposes the correct dialog semantics with
 *     no ARIA of our own to get wrong;
 *   - window.confirm() is explicitly ruled out by the requirements, and it blocks the whole
 *     renderer besides;
 *   - Stimulus is not installed in this project, and adding a bundle to obtain three
 *     confirmation dialogs is a large dependency for a small amount of markup.
 *
 * A browser without <dialog> (or with JavaScript off) submits directly. That is the correct
 * fallback: the confirmation is a courtesy to the operator, never the security boundary.
 */

function buildDialog(message, confirmLabel) {
    const dialog = document.createElement('dialog');

    const text = document.createElement('p');
    text.textContent = message;

    const actions = document.createElement('div');
    actions.className = 'dialog-actions';

    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.textContent = 'Cancel';
    cancel.value = 'cancel';

    const confirm = document.createElement('button');
    confirm.type = 'button';
    confirm.textContent = confirmLabel;
    confirm.value = 'confirm';

    actions.append(cancel, confirm);
    dialog.append(text, actions);

    return { dialog, cancel, confirm };
}

function enhance(form) {
    const message = form.dataset.confirm;

    if (!message || typeof HTMLDialogElement === 'undefined') {
        return;
    }

    const { dialog, cancel, confirm } = buildDialog(message, form.dataset.confirmLabel || 'Confirm');
    form.append(dialog);

    let confirmed = false;

    form.addEventListener('submit', (event) => {
        if (confirmed) {
            return;
        }

        event.preventDefault();
        dialog.showModal();
        // Cancel holds the initial focus so that Enter on a dialog nobody read does nothing.
        cancel.focus();
    });

    cancel.addEventListener('click', () => dialog.close());

    confirm.addEventListener('click', () => {
        confirmed = true;
        dialog.close();
        form.requestSubmit();
    });
}

document.querySelectorAll('form[data-confirm]').forEach(enhance);
