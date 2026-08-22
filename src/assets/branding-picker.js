/*
 * Progressive enhancement for the branding colour control (FR-072, NFR-064).
 *
 * The hex text field is the form's only real input, and it works with this file absent. All this
 * does is keep the native colour picker beside it in step, in both directions, and remove the
 * picker entirely if the browser does not support it — a picker that renders as a text box the
 * user cannot understand is worse than none.
 *
 * The live preview updates the same `--brand` custom property the server sets, so what a trainer
 * sees while dragging is what their members will see after saving. The foreground is *not*
 * recomputed here: the server derives it from WCAG relative luminance (NFR-065), and a second
 * implementation in JavaScript would be a second answer to a question that must have one. So the
 * preview swaps the background live and leaves the text colour until the save round-trips.
 */

function supportsColorInput(input) {
    // A browser without support degrades <input type="color"> to a text field, which reports
    // its type as "text" — the standard capability check for this element.
    return input.type === 'color';
}

function normalise(value) {
    const candidate = value.trim().replace(/^#/, '').toLowerCase();

    if (/^[0-9a-f]{3}$/.test(candidate)) {
        return `#${candidate[0]}${candidate[0]}${candidate[1]}${candidate[1]}${candidate[2]}${candidate[2]}`;
    }

    return /^[0-9a-f]{6}$/.test(candidate) ? `#${candidate}` : null;
}

function enhance(hexField, picker) {
    if (!supportsColorInput(picker)) {
        picker.remove();

        return;
    }

    picker.hidden = false;

    picker.addEventListener('input', () => {
        hexField.value = picker.value;
        document.documentElement.style.setProperty('--brand', picker.value);
    });

    hexField.addEventListener('input', () => {
        const colour = normalise(hexField.value);

        // Only a value the server would also accept reaches the preview. Half-typed input leaves
        // both the picker and the preview where they were, rather than flickering through every
        // colour a prefix happens to spell.
        if (colour) {
            picker.value = colour;
            document.documentElement.style.setProperty('--brand', colour);
        }
    });
}

const hexField = document.querySelector('[data-brand-hex]');
const picker = document.querySelector('[data-brand-picker]');

if (hexField && picker) {
    enhance(hexField, picker);
}
