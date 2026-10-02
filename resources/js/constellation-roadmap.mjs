const mountedPages = new WeakSet();

function openDialog(dialog) {
    if (!dialog) return;
    if (typeof dialog.showModal === 'function') {
        if (!dialog.open) dialog.showModal();
        return;
    }
    dialog.setAttribute('open', '');
}

function closeDialog(dialog) {
    if (!dialog) return;
    if (typeof dialog.close === 'function' && dialog.open) {
        dialog.close();
        return;
    }
    dialog.removeAttribute('open');
}

function centerUniverse(page) {
    const universe = page.querySelector('[data-constellation-universe]');
    const stage = page.querySelector('[data-constellation-stage]');
    if (!universe || !stage || !universe.matches('[data-constellation-overview]')) return;

    const station = page.querySelector('[data-constellation-station-open]');
    if (!station) return;

    requestAnimationFrame(() => {
        if (!page.isConnected || !station.isConnected) return;

        const left = Math.max(
            0,
            station.offsetLeft + (station.offsetWidth / 2) - (universe.clientWidth / 2),
        );
        const top = Math.max(
            0,
            station.offsetTop + (station.offsetHeight / 2) - (universe.clientHeight / 2),
        );

        universe.scrollTo({ left, top, behavior: 'auto' });
    });
}

function mountSelectedStarPanel(page) {
    const panel = page.querySelector('[data-constellation-star-panel]');
    const panelBody = page.querySelector('[data-constellation-star-panel-body]');
    const stars = [...page.querySelectorAll('[data-constellation-star-open]')];

    if (!panel || !panelBody || stars.length === 0) return;

    const selectStar = (button, focusPanel = false) => {
        const starId = button?.dataset.constellationStarOpen;
        if (!starId) return;

        const template = [...page.querySelectorAll('[data-constellation-star-template]')]
            .find((candidate) => candidate.dataset.constellationStarTemplate === starId);
        if (!template) return;

        panelBody.replaceChildren(template.content.cloneNode(true));
        panel.dataset.selectedStarId = starId;

        stars.forEach((starButton) => {
            const selected = starButton === button;
            starButton.classList.toggle('is-selected', selected);
            starButton.dataset.constellationStarSelected = selected ? '1' : '0';
            starButton.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });

        if (focusPanel) {
            window.requestAnimationFrame(() => {
                panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });
        }
    };

    stars.forEach((button) => {
        button.setAttribute(
            'aria-pressed',
            button.dataset.constellationStarSelected === '1' ? 'true' : 'false',
        );
        button.addEventListener('click', () => selectStar(button, true));
    });

    const selected = stars.find((button) => button.dataset.constellationStarSelected === '1')
        || stars.find((button) => button.dataset.starCurrent === '1')
        || stars[0];

    if (selected && !panel.dataset.selectedStarId) {
        selectStar(selected);
    }
}

export function mountConstellationRoadmap(root = document) {
    const pages = root.matches?.('[data-constellation-page]')
        ? [root]
        : [...root.querySelectorAll?.('[data-constellation-page]') || []];

    pages.forEach((page) => {
        if (mountedPages.has(page)) return;
        mountedPages.add(page);

        const stationDialog = page.querySelector('[data-constellation-station-dialog]');
        const stationOpen = page.querySelector('[data-constellation-station-open]');
        const stationClose = page.querySelector('[data-constellation-station-close]');

        stationOpen?.addEventListener('click', () => openDialog(stationDialog));
        stationClose?.addEventListener('click', () => closeDialog(stationDialog));
        stationDialog?.addEventListener('click', (event) => {
            if (event.target === stationDialog) closeDialog(stationDialog);
        });

        mountSelectedStarPanel(page);
        centerUniverse(page);
    });
}
