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
    const railButtons = [...page.querySelectorAll('[data-constellation-star-jump]')];

    if (!panel || !panelBody || stars.length === 0) return;

    const starById = new Map(
        stars.map((button) => [button.dataset.constellationStarOpen, button]),
    );

    const selectStar = (starId, focusPanel = false) => {
        const button = starById.get(starId);
        if (!button) return;

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

        railButtons.forEach((railButton) => {
            const selected = railButton.dataset.constellationStarJump === starId;
            railButton.classList.toggle('is-selected', selected);
            railButton.setAttribute('aria-pressed', selected ? 'true' : 'false');

            if (selected) {
                railButton.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest',
                    inline: 'center',
                });
            }
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
        button.addEventListener('click', () => {
            selectStar(button.dataset.constellationStarOpen, true);
        });
    });

    railButtons.forEach((button) => {
        button.addEventListener('click', () => {
            selectStar(button.dataset.constellationStarJump, false);
        });
    });

    const selected = stars.find((button) => button.dataset.constellationStarSelected === '1')
        || stars.find((button) => button.dataset.starCurrent === '1')
        || stars[0];

    if (selected) {
        selectStar(selected.dataset.constellationStarOpen);
    }
}

function mountPlanSwipe(page) {
    const workspace = page.querySelector('[data-constellation-selected-workspace]');
    const zone = page.querySelector('[data-constellation-swipe-zone]');
    if (!workspace || !zone) return;

    let startX = null;
    let startY = null;
    let ignored = false;

    const reset = () => {
        startX = null;
        startY = null;
        ignored = false;
    };

    zone.addEventListener('touchstart', (event) => {
        const touch = event.touches?.[0];
        if (!touch) return;

        ignored = Boolean(event.target.closest('a,button,input,select,textarea,label'));
        startX = touch.clientX;
        startY = touch.clientY;
    }, { passive: true });

    zone.addEventListener('touchend', (event) => {
        if (ignored || startX === null || startY === null) {
            reset();
            return;
        }

        const touch = event.changedTouches?.[0];
        if (!touch) {
            reset();
            return;
        }

        const deltaX = touch.clientX - startX;
        const deltaY = touch.clientY - startY;
        reset();

        if (Math.abs(deltaX) < 72 || Math.abs(deltaY) > 48) return;

        const target = deltaX < 0
            ? page.querySelector('[data-constellation-next-plan]')
            : page.querySelector('[data-constellation-previous-plan]');

        target?.click();
    }, { passive: true });
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
        mountPlanSwipe(page);
        centerUniverse(page);
    });
}
