/*
 * Service worker de PROCOM Tools : application installable et consultation hors ligne.
 *
 * - Fichiers statiques (/assets, /pwa, polices, logo) : cache d'abord. Les fichiers d'/assets portent leur empreinte dans leur nom,
 *   un fichier modifié a donc une autre adresse.
 * - Pages : le réseau d'abord, la dernière copie vue si le réseau manque, puis la page « hors ligne ». Seules les pages
 *   consultées (ou préchargées par l'application une fois connecté) sont disponibles hors ligne.
 * - Jamais mis en cache : connexion, déconnexion, documents du personnel, administration, exports, flux iCalendar, API et
 *   toute réponse qui suit une redirection ou qui porte un message flash. Les copies de pages sont effacées à la déconnexion.
 *
 * Changer VERSION invalide les caches.
 */
const VERSION = 'v2';
const STATIC = `procom-static-${VERSION}`;
const PAGES = `procom-pages-${VERSION}`;
const OFFLINE = '/offline.html';
const MAX_PAGES = 60;
const NETWORK_TIMEOUT = 5000;

const NEVER = [
    '/login', '/logout', '/2fa', '/admin', '/liens/', '/espocrm', '/manifest',
    '/personnel/documents/', '/profil/documents/', '/calendrier/ical/', '/correcteur-media/api', '/_profiler', '/_wdt', '/sw.js',
];
const EXPORT = /\.(xlsx|pdf|ics|csv|zip)$/i;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC)
            .then((cache) => cache.addAll([OFFLINE, '/pwa/icon-192.png', '/pwa/icon-512.png', '/pwa/apple-touch-icon.png']))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key.startsWith('procom-') && ![STATIC, PAGES].includes(key)).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('message', (event) => {
    const data = event.data || {};
    if ('clear-pages' === data.type) {
        event.waitUntil(caches.delete(PAGES));
    } else if ('precache' === data.type && Array.isArray(data.urls)) {
        event.waitUntil(precache(data.urls));
    }
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if ('GET' !== request.method) return;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    if (url.pathname.startsWith('/assets/') || url.pathname.startsWith('/pwa/')) {
        event.respondWith(cacheFirst(request));
        return;
    }
    if (url.pathname.startsWith('/uploads/')) {
        event.respondWith(staleWhileRevalidate(request));
        return;
    }
    if (isNever(url)) return;

    const wantsHtml = 'navigate' === request.mode || (request.headers.get('accept') || '').includes('text/html');
    if (wantsHtml) {
        event.respondWith(pageFirstNetwork(request));
    }
});

function isNever(url) {
    return NEVER.some((prefix) => url.pathname.startsWith(prefix)) || EXPORT.test(url.pathname);
}

async function cacheFirst(request) {
    const cache = await caches.open(STATIC);
    const hit = await cache.match(request);
    if (hit) return hit;
    const response = await fetch(request);
    if (response.ok) cache.put(request, response.clone());
    return response;
}

async function staleWhileRevalidate(request) {
    const cache = await caches.open(STATIC);
    const hit = await cache.match(request);
    const refresh = fetch(request).then((response) => {
        if (response.ok) cache.put(request, response.clone());
        return response;
    }).catch(() => hit);

    return hit || refresh;
}

/** Réseau d'abord (avec délai), sinon la dernière copie de la page, sinon la page hors ligne. */
async function pageFirstNetwork(request) {
    try {
        const response = await fetchWithTimeout(request, NETWORK_TIMEOUT);
        await remember(request, response);
        return response;
    } catch (error) {
        const cache = await caches.open(PAGES);
        const hit = await cache.match(request.url);
        if (hit) return hit;
        if ('navigate' === request.mode || (request.headers.get('accept') || '').includes('text/html')) {
            const offline = await (await caches.open(STATIC)).match(OFFLINE);
            if (offline) return offline;
        }
        throw error;
    }
}

function fetchWithTimeout(request, ms) {
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('timeout')), ms);
        fetch(request).then((response) => { clearTimeout(timer); resolve(response); }, (error) => { clearTimeout(timer); reject(error); });
    });
}

/** Garde une copie des pages réussies, sans redirection ni message flash, sous leur adresse d'origine. */
async function remember(request, response) {
    if (!response.ok || response.redirected || !(response.headers.get('content-type') || '').includes('text/html')) return;
    if (isNever(new URL(request.url))) return;
    const copy = response.clone();
    // Un message flash (« Profil mis à jour ») n'a pas à ressortir plus tard d'une copie.
    if ((await copy.clone().text()).includes('class="alert ')) return;
    const cache = await caches.open(PAGES);
    await cache.put(request.url, copy);
    const keys = await cache.keys();
    if (keys.length > MAX_PAGES) {
        await Promise.all(keys.slice(0, keys.length - MAX_PAGES).map((key) => cache.delete(key)));
    }
}

/** Charge à l'avance les pages du menu pour qu'elles soient disponibles hors ligne. */
async function precache(urls) {
    const cache = await caches.open(PAGES);
    for (const raw of urls) {
        const url = new URL(raw, self.location.origin);
        if (url.origin !== self.location.origin || isNever(url)) continue;
        try {
            const request = new Request(url.href, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
            const response = await fetch(request);
            if (response.ok && !response.redirected && (response.headers.get('content-type') || '').includes('text/html')
                && !(await response.clone().text()).includes('class="alert ')) {
                await cache.put(url.href, response);
            }
        } catch (error) {
            return; // plus de réseau : inutile d'insister
        }
    }
}
