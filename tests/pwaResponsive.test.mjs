import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const manifest = JSON.parse(await readFile(new URL("../public/manifest.webmanifest", import.meta.url), "utf8"));
const serviceWorker = await readFile(new URL("../public/sw.js", import.meta.url), "utf8");
const css = await readFile(new URL("../src/index.css", import.meta.url), "utf8");
const shell = await readFile(new URL("../src/components/Layout.tsx", import.meta.url), "utf8");
const pwa = await readFile(new URL("../src/components/PwaExperience.tsx", import.meta.url), "utf8");
const routerList = await readFile(new URL("../src/pages/RoutersPage.tsx", import.meta.url), "utf8");
const routerDetail = await readFile(new URL("../src/pages/RouterDetailPage.tsx", import.meta.url), "utf8");
const search = await readFile(new URL("../src/components/GlobalSearch.tsx", import.meta.url), "utf8");

test("PWA manifest has app identity, standalone scope, and real any/maskable PNG icons", async () => {
  assert.equal(manifest.name, "Bratha Matu Network Operations Console");
  assert.equal(manifest.short_name, "Bratha Matu");
  assert.equal(manifest.start_url, "/");
  assert.equal(manifest.scope, "/");
  assert.equal(manifest.display, "standalone");
  for (const icon of manifest.icons) {
    assert.equal(icon.type, "image/png");
    const image = await readFile(new URL(`../public${icon.src}`, import.meta.url));
    assert.equal(image.subarray(1, 4).toString(), "PNG");
    assert.equal(image.readUInt32BE(16), Number(icon.sizes.split("x")[0]));
    assert.equal(image.readUInt32BE(20), Number(icon.sizes.split("x")[1]));
  }
  assert.ok(manifest.icons.some((icon) => icon.sizes === "192x192" && icon.purpose === "any"));
  assert.ok(manifest.icons.some((icon) => icon.sizes === "512x512" && icon.purpose === "any"));
  assert.ok(manifest.icons.some((icon) => icon.purpose === "maskable"));
});

test("service worker caches only the public app shell and immutable static assets, never API data", () => {
  assert.match(serviceWorker, /url\.pathname\.startsWith\("\/api\/"\)/);
  assert.match(serviceWorker, /url\.pathname\.startsWith\("\/sanctum\/"\)/);
  assert.match(serviceWorker, /const isHashedBuildAsset/);
  assert.match(serviceWorker, /const isPublicAppIcon/);
  assert.doesNotMatch(serviceWorker, /caches\.(?:match|put)\([^)]*\/api/);
  assert.match(serviceWorker, /type === "SKIP_WAITING"/);
  const installHandler = serviceWorker.split('self.addEventListener("install"')[1].split('self.addEventListener("message"')[0];
  assert.doesNotMatch(installHandler, /skipWaiting/);
});

test("responsive shell has a bounded main region and accessible, scroll-locked mobile drawer", () => {
  assert.match(css, /\.table-scroll/);
  assert.match(css, /overscroll-behavior-x:\s*contain/);
  assert.match(css, /max-width:\s*640px/);
  assert.doesNotMatch(css, /overflow-x:\s*hidden/);
  assert.match(shell, /min-w-0 w-full flex-1/);
  assert.match(shell, /aria-modal="true"/);
  assert.match(shell, /document\.body\.style\.overflow = "hidden"/);
  assert.match(shell, /event\.key === "Escape"/);
  assert.match(routerList, /flex max-w-full flex-wrap gap-1\.5/);
  assert.match(routerDetail, /className="tab-scroll border-b/);
  assert.match(search, /role="dialog"/);
  assert.match(search, /h-dvh/);
  assert.match(pwa, /beforeinstallprompt/);
  assert.match(pwa, /appinstalled/);
  assert.match(pwa, /display-mode: standalone/);
  assert.match(pwa, /credentials: "omit"/);
  assert.match(pwa, /cache: "no-store"/);
});
