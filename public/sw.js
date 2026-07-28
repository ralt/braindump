// Minimal service worker: exists only to make the app installable.
// The empty fetch handler satisfies browsers that require one for install
// eligibility while leaving every request on the normal network path —
// no caching, so Mercure SSE streams and deploys behave exactly as before.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => {});
