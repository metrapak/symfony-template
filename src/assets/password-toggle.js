/*
 * Show / hide password (TASK-001 Frontend Tasks — progressive enhancement).
 *
 * The button ships with `hidden` in the markup and is revealed here, so a visitor without
 * JavaScript sees a normal password field rather than a control that does nothing. The same
 * reasoning as `copy-link.js`: the form is fully usable without this file.
 *
 * It is a `<button type="button">`, not a checkbox styled as one, because it performs an action
 * on another control rather than carrying state that gets submitted. `aria-pressed` is what
 * announces whether the password is currently visible; the visible label ("Show" / "Hide") says
 * the same thing for everybody else, so the state is never colour or icon alone.
 *
 * Nothing here reads or stores the value. A password toggle that logged, cached or copied the
 * field would turn a convenience into a credential leak (NFR-003).
 */

function enhance(button) {
    const field = document.getElementById(button.getAttribute('aria-controls'));
    const label = button.querySelector('[data-password-toggle-label]');

    if (!field || !label) {
        return;
    }

    button.hidden = false;

    button.addEventListener('click', () => {
        const revealing = field.type === 'password';

        field.type = revealing ? 'text' : 'password';
        label.textContent = revealing ? 'Hide' : 'Show';
        button.setAttribute('aria-pressed', revealing ? 'true' : 'false');

        /*
         * Focus goes back to the field, at the end of the value. Leaving it on the button would
         * make a keyboard user tab back into the field they were already typing in, and browsers
         * reset the caret to position 0 when `type` changes.
         */
        const caret = field.value.length;

        field.focus();
        field.setSelectionRange(caret, caret);
    });
}

document.querySelectorAll('[data-password-toggle]').forEach(enhance);
