// Dedicated root on a separately accepted origin. Never caches personal data.
const CACHE='canovia-local-preview-shell-20261023-1';
const FILES=['./','./index.html','./style.css','./manifest.webmanifest','./icon-192.png','./icon-512.png',
 './src/app.mjs','./src/config.mjs','./src/pwa.mjs','./src/model.mjs','./src/repository.mjs','./src/planning.mjs',
 './src/execution.mjs','./src/execution-ui.mjs','./src/validation-pack.mjs','./src/study.mjs','./src/study-ui.mjs','./src/backup.mjs','./src/backup-ui.mjs'];
const allowed=new Set(FILES.map(p=>new URL(p,self.registration.scope).href));
self.addEventListener('install',event=>event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(FILES))));
// Do not skipWaiting: an old page must not silently switch code under its data.
self.addEventListener('activate',event=>event.waitUntil((async()=>{
 for(const key of await caches.keys())if(key.startsWith('canovia-local-preview-shell-')&&key!==CACHE)await caches.delete(key);
 await self.clients.claim();
})()));
self.addEventListener('fetch',event=>{
 if(event.request.method!=='GET'||!allowed.has(event.request.url))return;
 event.respondWith(caches.open(CACHE).then(async cache=>(await cache.match(event.request))||fetch(event.request)));
});
