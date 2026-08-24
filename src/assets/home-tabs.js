/*
 * Front-page section tabs (templates/main/homepage.html.twig) — progressive enhancement.
 *
 * The page ships every section expanded, each under its own heading, so a visitor without
 * JavaScript reads a stacked index of all their destinations. That is a complete page, not a
 * degraded one. What this file adds is the tab strip: the same sections, one at a time, so a
 * signed-in trainer or admin is not scrolling past four groups to reach the fifth.
 *
 * Built here rather than in Twig for the same reason `password-toggle.js` reveals its own button:
 * a tablist rendered in markup would hide four panels behind controls that only work once this
 * file has run, and a failed asset load would then cost the visitor most of the page.
 *
 * Follows the ARIA authoring practices' *manual* activation pattern — arrow keys move focus
 * between tabs and Enter/Space selects. Automatic activation would swap the panel under a
 * keyboard user simply passing through, which on a list of links is disorienting.
 *
 * With one panel there is nothing to switch between, so the strip is not built at all: a single
 * tab is a control that cannot do anything. That is the signed-out case today.
 */

const container = document.querySelector('[data-home-tabs]');
const panels = container ? Array.from(container.querySelectorAll('[data-home-panel]')) : [];

function select(tabs, index) {
    tabs.forEach((tab, i) => {
        const current = i === index;

        tab.setAttribute('aria-selected', current ? 'true' : 'false');
        /*
         * Roving tabindex: exactly one tab is in the document's tab order, so Tab leaves the
         * strip for the panel instead of walking through every tab first.
         */
        tab.tabIndex = current ? 0 : -1;
        panels[i].hidden = !current;
    });
}

function enhance() {
    const list = document.createElement('div');

    list.className = 'pp-landing-tabs';
    list.setAttribute('role', 'tablist');
    list.setAttribute('aria-label', 'Sections');

    const tabs = panels.map((panel, index) => {
        const tab = document.createElement('button');

        tab.type = 'button';
        tab.className = 'pp-landing-tab';
        tab.id = `home-tab-${index}`;
        tab.setAttribute('role', 'tab');
        tab.setAttribute('aria-controls', panel.id || (panel.id = `home-panel-${index}`));
        tab.textContent = panel.dataset.homePanelLabel || `Section ${index + 1}`;

        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('aria-labelledby', tab.id);
        /*
         * A tab panel that contains links must be focusable itself, or a keyboard user moving off
         * the strip with Tab lands on the first link with no announcement of what they are in.
         */
        panel.tabIndex = 0;

        /*
         * The heading is now said by the tab. Hiding it visually rather than removing it keeps
         * the panel reachable by heading navigation, which is how many screen-reader users move
         * around a page of links.
         */
        const heading = panel.querySelector('[data-home-panel-heading]');

        if (heading) {
            heading.classList.add('visually-hidden');
        }

        tab.addEventListener('click', () => select(tabs, index));
        tab.addEventListener('keydown', (event) => {
            const step = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
            let target = null;

            if (step) {
                target = (index + step + tabs.length) % tabs.length;
            } else if (event.key === 'Home') {
                target = 0;
            } else if (event.key === 'End') {
                target = tabs.length - 1;
            }

            if (target === null) {
                return;
            }

            event.preventDefault();
            tabs[target].focus();
        });

        return tab;
    });

    tabs.forEach((tab) => list.append(tab));
    container.prepend(list);
    select(tabs, 0);
}

if (panels.length > 1) {
    enhance();
}
