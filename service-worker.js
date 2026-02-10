const CACHE_NAME = 'cukrinukas-v1';
const OFFLINE_URL = '/offline.php';

// Failai, kuriuos visada norime turėti (Core)
const ASSETS_TO_CACHE = [
    OFFLINE_URL,
    '/index.php',
    '/products.php',
    // Pridėkite savo CSS/JS failus, jei turite išorinius
    // '/assets/style.css',
    'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap'
];

// Instaliavimas: Išsaugome statinius failus
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                return cache.addAll(ASSETS_TO_CACHE);
            })
    );
});

// Aktyvavimas: Išvalome senas talpyklas
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keyList) => {
            return Promise.all(keyList.map((key) => {
                if (key !== CACHE_NAME) {
                    return caches.delete(key);
                }
            }));
        })
    );
});

// Užklausų valdymas: Network First, falling back to Cache
// Pirmiausia bandome gauti naujausius duomenis iš interneto.
// Jei nepavyksta (nėra interneto), imame iš cache.
// Jei nėra cache, rodome offline puslapį.
self.addEventListener('fetch', (event) => {
    // Tikriname tik GET užklausas
    if (event.request.method !== 'GET') return;

    event.respondWith(
        fetch(event.request)
            .then((response) => {
                // Jei gavome sėkmingą atsakymą, išsaugome kopiją į cache ateičiai
                const responseClone = response.clone();
                caches.open(CACHE_NAME).then((cache) => {
                    cache.put(event.request, responseClone);
                });
                return response;
            })
            .catch(() => {
                // Jei nėra interneto, bandome imti iš cache
                return caches.match(event.request)
                    .then((cachedResponse) => {
                        if (cachedResponse) {
                            return cachedResponse;
                        }
                        // Jei puslapis nerastas cache ir tai yra navigacija (HTML), rodome offline puslapį
                        if (event.request.mode === 'navigate') {
                            return caches.match(OFFLINE_URL);
                        }
                    });
            })
    );
});
