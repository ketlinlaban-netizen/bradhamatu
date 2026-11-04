import { defineConfig, loadEnv } from "vite";
import react from "@vitejs/plugin-react";
import { readFileSync, writeFileSync } from "node:fs";
import { resolve } from "node:path";

function versionServiceWorker() {
  return {
    name: "version-service-worker",
    apply: "build" as const,
    closeBundle() {
      const outputDirectory = resolve(process.cwd(), "dist");
      const html = readFileSync(resolve(outputDirectory, "index.html"), "utf8");
      const entryScript = html.match(/src="\/assets\/(index-[^"]+\.js)"/)?.[1];
      if (!entryScript) {
        throw new Error("Could not determine the built frontend entry script for the service worker.");
      }

      const workerPath = resolve(outputDirectory, "sw.js");
      const worker = readFileSync(workerPath, "utf8");
      const buildId = entryScript.slice("index-".length, -".js".length);
      const versionedWorker = worker.replace(
        /const CACHE_NAME = "bradha-matu-shell-[^"]+";/,
        `const CACHE_NAME = "bradha-matu-shell-${buildId}";`,
      );
      if (versionedWorker === worker) {
        throw new Error("Could not update the service worker cache version.");
      }
      writeFileSync(workerPath, versionedWorker);
    },
  };
}

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), "");

  return {
    plugins: [react(), versionServiceWorker()],
    server: {
      host: "0.0.0.0",
      port: 5173,
      proxy: {
        "/api": env.VITE_API_PROXY_TARGET ?? "http://127.0.0.1:8000",
      },
    },
  };
});
