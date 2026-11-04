# Mobile responsiveness and installable PWA

## Responsive behavior

The existing application shell remains in place. On phones, the sidebar opens
as a keyboard-operable modal drawer: Escape and the close/backdrop controls
dismiss it, focus stays within the drawer, and background page scrolling is
locked until it closes. Page containers and cards are shrinkable, while wide
tables use the bounded `.table-scroll` component so horizontal movement stays
inside the table card.

Inputs use a 16px mobile font size to avoid browser auto-zoom. Existing page
routes and live Laravel data remain unchanged. No router write path was added.

## PWA behavior and privacy

`public/manifest.webmanifest` describes the standalone app and references real
192px, 512px, and maskable 512px PNGs derived from the existing Bratha Matu
Wi-Fi mark. The existing custom service worker is retained and versioned. It
caches only the public app shell, branded icons, manifest, and content-hashed
build assets. Each production build derives the worker cache version from the
hashed frontend entry bundle, so a changed frontend release also triggers the
existing update-ready flow. Navigations are network-first with the public root
shell as an offline fallback. `/api/` and `/sanctum/` requests bypass the
service worker; authenticated API responses, telemetry, and client inventory
are not stored in its Cache Storage.

The app checks the public `/api/health` endpoint with an unauthenticated,
no-store request. If the browser is offline or the API is unreachable, the
shell displays an API-unavailable notice and does not label retained
telemetry as live. This connectivity notice is not a telemetry-freshness
claim; router freshness remains governed by the existing telemetry state.

On Android, the app listens for the native `beforeinstallprompt` and
`appinstalled` events and calls the native prompt only after the user chooses
Install. If the browser does not offer a prompt, the app provides conditional
manual browser-menu instructions; it does not claim to launch a native prompt.
Dismissal is stored only as a seven-day, non-sensitive preference. On a new
release, an update-ready notice lets the user activate the waiting worker and
refresh. The worker does not activate an update over the user's current
session without that action.

## Validation

Run:

```sh
npm run typecheck
node --test tests/*.test.mjs
npm run build
```

Use browser responsive emulation at widths 320, 360, 375, 390, 412, 430, 768,
1024, 1280, and 1440 pixels. Check the login, dashboard, routers, router
detail, offline center, clients, traffic, network health, incidents, reports,
MikroTik config, and system routes. Confirm document width does not exceed the
viewport; table overflow is contained by `.table-scroll`.

On an Android Chromium browser, test native install only if the browser emits
`beforeinstallprompt`. Otherwise use the browser menu's Install app/Add to
Home screen option if provided. Confirm standalone launch, dismiss behavior,
offline notice, reconnection, and an update prompt after a subsequent release.
Physical Android installation must be reported separately from desktop
browser emulation.

## Production verification (2026-10-23)

The frontend was deployed to both cPanel frontend roots with the current
hashed assets. The final entry files were backed up at
`/home/pandatechsco/tmp/bradha-mobile-final-20261023T005231Z/backup/`; an
earlier complete PWA shell/manifest/worker backup is at
`/home/pandatechsco/tmp/bradha-mobile-pwa-20261023T004021Z/backup/`.

Final asset hashes:

| File | SHA-256 |
|---|---|
| `index.html` | `b9a7f64815749f10476be0e04ffffc0b89279b05fdb2bad07776d243ee68ce1a` |
| `assets/index-CzUYMJ6B.js` | `b54f5e323c21674d09c354b46f1b3b755961410648aa77bc8c10e504abecd600` |
| `assets/index-BukxOfEa.css` | `9c50ad58c4dc8ab68f3ed756d04b33c7923fc3d84982c124a54a90412ec4224c` |
| `manifest.webmanifest` | `d050562e3c7bbeb3ed4fffefbd4969257d15e49a1d93b0fe0d69276758ae4b53` |
| `sw.js` | `56736f08ebdb4a21a02f2767b4326353491a7428bc2f7a156802ed3570f44436` |

The production browser returned the final title and HTTPS assets. The PWA
manifest, worker, and all three PNG icons were HTTP 200. The active service
worker cache contained only the public shell, manifest/icons, and hashed
frontend bundles; no `/api/` or `/sanctum/` request was cached. A waiting
worker showed the update notice and the user-triggered refresh activated the
new worker. A subsequent frontend-only build, with no manual cache-version
edit, also produced an update-ready notice; activating it installed
`bradha-matu-shell-CzUYMJ6B`. The previous production worker is backed up at
`/home/pandatechsco/tmp/bradha-pwa-worker-20261023T011000Z/backup/sw.js`.

Browser viewport emulation checked the 12 application routes (login,
dashboard, routers, one router detail, offline center, clients, traffic,
network health, incidents, reports, MikroTik config, and system) at all ten
requested widths from 320px through 1440px: **120 route/width checks, zero
document horizontal-overflow failures, and zero cards outside the viewport**.
Wide tables and router tabs remained scrollable inside their own containers.
There were also 20 no-overflow checks across 844×390 landscape and 390×844
portrait with the root font size increased to 150%. The 320×800 search dialog
filled its overlay, remained within the viewport, locked background scroll,
restored focus, and closed on Escape. The mobile drawer also locked scroll,
closed on Escape, and returned focus.

The browser verified client search/filter behavior, an offline/API-unavailable
notice followed by recovery, manual install help, and simulated
`beforeinstallprompt` acceptance/dismissal. These install events were
browser-emulated; this environment is not a physical Android device, so native
installation, standalone launch, Android back navigation, and Android font
scaling still require real-device acceptance testing. At the final browser
check, the API was reachable and client inventory showed 6 saved, 2 online,
4 offline, and 0 stale; no client records were changed by the responsive test.
