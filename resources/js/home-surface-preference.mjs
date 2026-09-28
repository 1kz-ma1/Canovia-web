export const HOME_SURFACE_STORAGE_KEY = 'pacekeeper.ui.home_surface';
export const HOME_SURFACE_COOKIE = 'canovia_home_surface';

export function normalizeHomeSurface(value) {
    return value === 'map' ? 'map' : 'classic';
}

export function cookieHomeSurface(documentRef = globalThis.document) {
    const cookie = documentRef?.cookie || '';
    const prefix = `${HOME_SURFACE_COOKIE}=`;

    for (const part of cookie.split(';')) {
        const trimmed = part.trim();
        if (!trimmed.startsWith(prefix)) continue;
        return normalizeHomeSurface(decodeURIComponent(trimmed.slice(prefix.length)));
    }

    return 'classic';
}

export function resolveHomeSurface({
    storageRef = globalThis.localStorage,
    documentRef = globalThis.document,
} = {}) {
    try {
        const stored = storageRef?.getItem?.(HOME_SURFACE_STORAGE_KEY);
        if (stored === 'classic' || stored === 'map') return stored;
    } catch (_) {}

    return cookieHomeSurface(documentRef);
}

export function persistHomeSurface(value, {
    storageRef = globalThis.localStorage,
    documentRef = globalThis.document,
    locationRef = globalThis.location,
} = {}) {
    const normalized = normalizeHomeSurface(value);

    try {
        storageRef?.setItem?.(HOME_SURFACE_STORAGE_KEY, normalized);
    } catch (_) {}

    if (documentRef) {
        const secure = locationRef?.protocol === 'https:' ? '; Secure' : '';
        documentRef.cookie = `${HOME_SURFACE_COOKIE}=${encodeURIComponent(normalized)}; Path=/; Max-Age=31536000; SameSite=Lax${secure}`;
    }

    return normalized;
}

export function homeSurfaceUrl(value, {
    classicUrl = '/',
    mapUrl = '/map',
} = {}) {
    return normalizeHomeSurface(value) === 'map' ? mapUrl : classicUrl;
}
