/*
 * Password requirement hint (TASK-001 Frontend Tasks — progressive enhancement).
 *
 * The rules are already on the page as static help text linked to the field with
 * `aria-describedby`, so somebody without JavaScript reads exactly the same requirements. This
 * file only marks which of them the current value already satisfies.
 *
 * **The server stays authoritative.** These two checks mirror `PasswordRequirements` (min 8
 * characters, at least one capital), and that constraint also runs `NotCompromisedPassword`,
 * which needs a network call and is deliberately not reproduced here. So a value this hint marks
 * as complete can still be rejected on submit — which is why the hint says "so far" rather than
 * "valid", and why nothing here disables the submit button. A form that cannot be submitted
 * because client-side rules disagreed with the server is a form nobody can recover.
 *
 * The value is never stored, logged or sent anywhere (NFR-003).
 */

const RULES = {
    length: (value) => value.length >= 8,
    capital: (value) => /[A-Z]/.test(value),
};

function enhance(list) {
    const field = document.getElementById(list.dataset.passwordHintFor);

    if (!field) {
        return;
    }

    const items = Array.from(list.querySelectorAll('[data-password-rule]'));

    if (items.length === 0) {
        return;
    }

    const update = () => {
        items.forEach((item) => {
            const rule = RULES[item.dataset.passwordRule];

            if (!rule) {
                return;
            }

            const met = rule(field.value);

            item.dataset.met = met ? 'true' : 'false';

            /*
             * The state is a word, not only a tick and a colour: a green check conveys nothing
             * to a screen reader and nothing to a reader who cannot separate it from decoration
             * (WCAG 1.4.1). `aria-live` is on the list, so the change is announced politely
             * rather than interrupting typing.
             */
            const state = item.querySelector('[data-password-rule-state]');

            if (state) {
                state.textContent = met ? 'met' : 'not met yet';
            }
        });
    };

    // `input` rather than `keyup`: paste, autofill and speech input all fire it.
    field.addEventListener('input', update);
    update();
}

document.querySelectorAll('[data-password-hint-for]').forEach(enhance);
