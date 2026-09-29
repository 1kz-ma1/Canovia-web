/** Fit rendered mobile boxes without changing graph coordinates or zoom state. */
export function fitMobileNodeBoxes(positions, boxes, viewport, centerId = null) {
    const width = Math.max(1, viewport.width);
    const height = Math.max(1, viewport.height);
    const gap = 8;
    const placed = [];
    const result = new Map(positions);
    const ordered = [...boxes].sort((a, b) => Number(b.id === centerId) - Number(a.id === centerId));

    for (const box of ordered) {
        const point = positions.get(box.id);
        if (!point || !box.width || !box.height) continue;
        const halfW = box.width / 2;
        const halfH = box.height / 2;
        const minX = Math.min(width / 2, halfW + gap);
        const maxX = Math.max(minX, width - halfW - gap);
        const minY = Math.min(height / 2, halfH + 48);
        const maxY = Math.max(minY, height - halfH - 56);
        const clampX = x => Math.max(minX, Math.min(maxX, x));
        const clampY = y => Math.max(minY, Math.min(maxY, y));
        const original = { x: clampX(point.x * width / 100), y: clampY(point.y * height / 100) };
        const xs = [original.x, minX, maxX];
        const ys = [original.y, minY, maxY];
        for (const other of placed) {
            xs.push(clampX(other.x - other.halfW - halfW - gap), clampX(other.x + other.halfW + halfW + gap));
            ys.push(clampY(other.y - other.halfH - halfH - gap), clampY(other.y + other.halfH + halfH + gap));
        }
        const candidates = xs.flatMap(x => ys.map(y => ({ x, y })));
        candidates.sort((a, b) => ((a.x - original.x) ** 2 + (a.y - original.y) ** 2)
            - ((b.x - original.x) ** 2 + (b.y - original.y) ** 2));
        const chosen = candidates.find(candidate => placed.every(other =>
            Math.abs(candidate.x - other.x) >= halfW + other.halfW + gap - .01
            || Math.abs(candidate.y - other.y) >= halfH + other.halfH + gap - .01
        ));
        if (!chosen) return fitCompactGrid(positions, ordered, { width, height });
        placed.push({ ...chosen, halfW, halfH });
        result.set(box.id, { x: chosen.x / width * 100, y: chosen.y / height * 100 });
    }
    return result;
}

// A crowded small screen may have no free slot around a fixed center. Reflow
// only the rendered boxes in that case; semantic identities and edges survive.
function fitCompactGrid(positions, boxes, { width, height }) {
    const result = new Map(positions);
    const measured = boxes.filter(box => positions.has(box.id) && box.width > 0 && box.height > 0);
    const cellWidth = Math.max(...measured.map(box => box.width)) + 8;
    const cellHeight = Math.max(...measured.map(box => box.height)) + 8;
    const columns = Math.max(1, Math.floor((width - 8) / cellWidth));
    const rows = Math.ceil(measured.length / columns);
    const gridHeight = rows * cellHeight - 8;
    // Keep every item reachable by existing pan controls if the viewport cannot
    // physically hold all boxes at readable size. Do not shrink text or targets.
    const top = Math.max(48, (height - gridHeight) / 2);
    const cells = Array.from({ length: rows * columns }, (_, i) => ({
        x: (width - columns * cellWidth + 8) / 2 + (i % columns) * cellWidth + (cellWidth - 8) / 2,
        y: top + Math.floor(i / columns) * cellHeight + (cellHeight - 8) / 2,
    }));
    for (const box of measured) {
        const point = positions.get(box.id);
        cells.sort((a, b) => ((a.x - point.x * width / 100) ** 2 + (a.y - point.y * height / 100) ** 2)
            - ((b.x - point.x * width / 100) ** 2 + (b.y - point.y * height / 100) ** 2));
        const cell = cells.shift();
        result.set(box.id, { x: cell.x / width * 100, y: cell.y / height * 100 });
    }
    return result;
}
