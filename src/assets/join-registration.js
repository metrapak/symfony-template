/*
 * Reveals the child-only field on the registration form when it applies (FR-042, NFR-043).
 *
 * Only the player's own name is hidden, and only while "I am the player" is selected: an adult
 * registering themselves already gave their name above, but date of birth and gender are asked
 * for on both branches and must stay visible.
 *
 * The server does not trust any of it. `PlayerRegistrationFormType` picks its validation group
 * from the submitted flag, so the child rules apply whenever the flag says child - whether or
 * not the field was ever visible, and whether or not this file ran.
 */

function enhance(form) {
    const childOnly = form.querySelector('[data-child-only]');
    const radios = form.querySelectorAll('input[type="radio"][value]');

    if (!childOnly || radios.length === 0) {
        return;
    }

    const legend = form.querySelector('[data-player-legend]');

    const apply = () => {
        const registeringChild = Array.from(radios).some((radio) => radio.checked && radio.value === 'child');

        childOnly.hidden = !registeringChild;

        if (legend) {
            legend.textContent = registeringChild ? "Your child's details" : 'Player details';
        }
    };

    radios.forEach((radio) => radio.addEventListener('change', apply));
    apply();
}

document.querySelectorAll('form[data-join-registration]').forEach(enhance);
