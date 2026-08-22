/*
 * Copy-to-clipboard for the trainer's player links (FR-040, NFR-043).
 *
 * The button ships hidden and is revealed here, only when the clipboard API is actually
 * available. That ordering matters: the URL is already on the page as selectable text, so a
 * visitor without JavaScript loses nothing, while a button that silently fails would be worse
 * than no button at all.
 *
 * Success is announced through an existing aria-live region rather than by moving focus, so a
 * screen-reader user hears "Link copied" without losing their place.
 */

function announce(message) {
    const status = document.getElementById('copy-status');

    if (status) {
        status.textContent = message;
    }
}

function enhance(button) {
    const source = document.getElementById(button.dataset.copyTarget);

    if (!source) {
        return;
    }

    button.hidden = false;
    button.setAttribute('aria-label', button.dataset.copyLabel || 'Copy link');

    button.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(source.textContent.trim());
            announce('Link copied to your clipboard.');
        } catch {
            // Permission refused, or the page is not in a secure context. Say so instead of
            // pretending it worked; the text is still there to select by hand.
            announce('Could not copy automatically. Select the link and copy it yourself.');
        }
    });
}

if (navigator.clipboard && window.isSecureContext) {
    document.querySelectorAll('button.copy-link').forEach(enhance);
}
