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
    if (!universe || !stage) return;

    const selected = page.querySelector('[data-plan-constellation].is-selected');
    const station = page.querySelector('[data-constellation-station-open]');
    const target = selected || station;
    if (!target) return;

    requestAnimationFrame(() => {
        if (!page.isConnected || !target.isConnected) return;

        const left = Math.max(
            0,
            target.offsetLeft + (target.offsetWidth / 2) - (universe.clientWidth / 2),
        );
        const top = Math.max(
            0,
            target.offsetTop + (target.offsetHeight / 2) - (universe.clientHeight / 2),
        );

        universe.scrollTo({ left, top, behavior: 'auto' });
    });
}

export function mountConstellationRoadmap(root = document) {
    const pages = root.matches?.('[data-constellation-page]')
        ? [root]
        : [...root.querySelectorAll?.('[data-constellation-page]') || []];

    pages.forEach((page) => {
        if (mountedPages.has(page)) return;
        mountedPages.add(page);

        const taskDialog = page.querySelector('[data-constellation-star-dialog]');
        const taskDialogBody = page.querySelector('[data-constellation-star-dialog-body]');
        const taskDialogClose = page.querySelector('[data-constellation-star-close]');
        const stationDialog = page.querySelector('[data-constellation-station-dialog]');
        const stationOpen = page.querySelector('[data-constellation-station-open]');
        const stationClose = page.querySelector('[data-constellation-station-close]');

        page.querySelectorAll('[data-constellation-star-open]').forEach((button) => {
            button.addEventListener('click', () => {
                const starId = button.dataset.constellationStarOpen;
                const template = [...page.querySelectorAll('[data-constellation-star-template]')]
                    .find((candidate) => candidate.dataset.constellationStarTemplate === starId);

                if (!template || !taskDialog || !taskDialogBody) return;

                taskDialogBody.replaceChildren(template.content.cloneNode(true));
                openDialog(taskDialog);
            });
        });

        taskDialogClose?.addEventListener('click', () => closeDialog(taskDialog));
        taskDialog?.addEventListener('click', (event) => {
            if (event.target === taskDialog) closeDialog(taskDialog);
        });

        stationOpen?.addEventListener('click', () => openDialog(stationDialog));
        stationClose?.addEventListener('click', () => closeDialog(stationDialog));
        stationDialog?.addEventListener('click', (event) => {
            if (event.target === stationDialog) closeDialog(stationDialog);
        });

        centerUniverse(page);
    });
}
