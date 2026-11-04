import { createContext, useContext, useEffect, useMemo, useRef, useState } from "react";
import { Download, RefreshCw, WifiOff, X } from "lucide-react";
import type { ReactNode } from "react";

type InstallChoice = { outcome: "accepted" | "dismissed"; platform: string };

interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>;
  userChoice: Promise<InstallChoice>;
}

type PwaContextValue = {
  installPrompt: BeforeInstallPromptEvent | null;
  androidInstallHelp: boolean;
  installed: boolean;
  offline: boolean;
  updateReady: boolean;
  install: () => Promise<InstallChoice["outcome"] | null>;
  applyUpdate: () => void;
};

const PwaContext = createContext<PwaContextValue | null>(null);
const DISMISSED_INSTALL_KEY = "bradha-matu-install-dismissed-until";
const INSTALL_DISMISSAL_MS = 7 * 24 * 60 * 60 * 1000;

function isStandalone(): boolean {
  return window.matchMedia("(display-mode: standalone)").matches
    || (navigator as Navigator & { standalone?: boolean }).standalone === true;
}

function installWasDismissed(): boolean {
  try {
    return Number(localStorage.getItem(DISMISSED_INSTALL_KEY) ?? 0) > Date.now();
  } catch {
    return false;
  }
}

function dismissInstall(): void {
  try {
    localStorage.setItem(DISMISSED_INSTALL_KEY, String(Date.now() + INSTALL_DISMISSAL_MS));
  } catch {
    // Installation remains available for this page session even if storage is unavailable.
  }
}

export function PwaExperience({ children }: { children: ReactNode }) {
  const [installPrompt, setInstallPrompt] = useState<BeforeInstallPromptEvent | null>(null);
  const [installed, setInstalled] = useState(isStandalone);
  const [offline, setOffline] = useState(!navigator.onLine);
  const [updateReady, setUpdateReady] = useState(false);
  const reloadOnControllerChange = useRef(false);
  const [registration, setRegistration] = useState<ServiceWorkerRegistration | null>(null);
  const androidInstallHelp = /Android/i.test(navigator.userAgent);

  useEffect(() => {
    const handlePrompt = (event: Event) => {
      if (isStandalone() || !/Android/i.test(navigator.userAgent)) return;
      event.preventDefault();
      setInstallPrompt(event as BeforeInstallPromptEvent);
    };
    const handleInstalled = () => {
      setInstalled(true);
      setInstallPrompt(null);
    };
    const displayMode = window.matchMedia("(display-mode: standalone)");
    const handleDisplayMode = () => setInstalled(isStandalone());
    window.addEventListener("beforeinstallprompt", handlePrompt);
    window.addEventListener("appinstalled", handleInstalled);
    displayMode.addEventListener("change", handleDisplayMode);
    return () => {
      window.removeEventListener("beforeinstallprompt", handlePrompt);
      window.removeEventListener("appinstalled", handleInstalled);
      displayMode.removeEventListener("change", handleDisplayMode);
    };
  }, []);

  useEffect(() => {
    let active = true;
    const checkConnection = async () => {
      if (!navigator.onLine) {
        if (active) setOffline(true);
        return;
      }
      const controller = new AbortController();
      const timeout = window.setTimeout(() => controller.abort(), 5000);
      try {
        const response = await fetch("/api/health", {
          method: "GET",
          cache: "no-store",
          credentials: "omit",
          signal: controller.signal,
        });
        if (active) setOffline(!response.ok);
      } catch {
        if (active) setOffline(true);
      } finally {
        window.clearTimeout(timeout);
      }
    };
    const handleOnline = () => void checkConnection();
    const handleOffline = () => setOffline(true);
    void checkConnection();
    const interval = window.setInterval(() => void checkConnection(), 30_000);
    window.addEventListener("online", handleOnline);
    window.addEventListener("offline", handleOffline);
    return () => {
      active = false;
      window.clearInterval(interval);
      window.removeEventListener("online", handleOnline);
      window.removeEventListener("offline", handleOffline);
    };
  }, []);

  useEffect(() => {
    if (!import.meta.env.PROD || !("serviceWorker" in navigator)) return;
    let active = true;
    const onControllerChange = () => {
      if (reloadOnControllerChange.current) window.location.reload();
    };
    navigator.serviceWorker.addEventListener("controllerchange", onControllerChange);
    navigator.serviceWorker.register("/sw.js", { updateViaCache: "none" })
      .then((nextRegistration) => {
        if (!active) return;
        setRegistration(nextRegistration);
        if (nextRegistration.waiting && navigator.serviceWorker.controller) {
          setUpdateReady(true);
        }
        nextRegistration.addEventListener("updatefound", () => {
          const worker = nextRegistration.installing;
          if (!worker) return;
          worker.addEventListener("statechange", () => {
            if (worker.state === "installed" && navigator.serviceWorker.controller) {
              setUpdateReady(true);
            }
          });
        });
      })
      .catch((error: unknown) => {
        console.error("Service worker registration failed:", error);
      });
    return () => {
      active = false;
      navigator.serviceWorker.removeEventListener("controllerchange", onControllerChange);
    };
  }, []);

  const install = async (): Promise<InstallChoice["outcome"] | null> => {
    if (!installPrompt) return null;
    try {
      await installPrompt.prompt();
      const choice = await installPrompt.userChoice;
      setInstallPrompt(null);
      if (choice.outcome === "accepted") {
        setInstalled(true);
      } else {
        dismissInstall();
      }
      return choice.outcome;
    } catch (error) {
      console.error("The browser installation prompt failed:", error);
      setInstallPrompt(null);
      return null;
    }
  };

  const applyUpdate = () => {
    const waitingWorker = registration?.waiting;
    if (waitingWorker) {
      reloadOnControllerChange.current = true;
      waitingWorker.postMessage({ type: "SKIP_WAITING" });
    }
  };

  const value = useMemo<PwaContextValue>(() => ({
    installPrompt,
    androidInstallHelp,
    installed,
    offline,
    updateReady,
    install,
    applyUpdate,
  }), [installPrompt, androidInstallHelp, installed, offline, updateReady, registration]);

  return (
    <PwaContext.Provider value={value}>
      {children}
    </PwaContext.Provider>
  );
}

export function usePwaExperience(): PwaContextValue {
  const context = useContext(PwaContext);
  if (!context) throw new Error("usePwaExperience must be used within PwaExperience.");
  return context;
}

export function PwaNotices() {
  const { installPrompt, androidInstallHelp, installed, offline, updateReady, install, applyUpdate } = usePwaExperience();
  const [installHelpOpen, setInstallHelpOpen] = useState(false);
  const [installDismissed, setInstallDismissed] = useState(installWasDismissed);
  const canOfferInstall = !installed && !installDismissed
    && (installPrompt !== null || (androidInstallHelp && !installHelpOpen));

  if (!offline && !updateReady && !canOfferInstall && !installHelpOpen) return null;

  return (
    <section aria-label="App status and installation" className="space-y-2">
      {offline && (
        <div role="status" className="flex min-w-0 items-start gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-200">
          <WifiOff className="mt-0.5 h-4 w-4 flex-shrink-0" />
          <span className="min-w-0 break-words">Connection to the console API is unavailable. Saved telemetry is not being presented as live.</span>
        </div>
      )}
      {updateReady && (
        <div role="status" className="flex min-w-0 flex-wrap items-center gap-2 rounded-lg border border-cyan-500/30 bg-cyan-500/10 px-3 py-2 text-xs text-cyan-100">
          <span className="min-w-0 flex-1">An app update is ready to install.</span>
          <button type="button" onClick={applyUpdate} className="btn-secondary inline-flex min-h-11 items-center gap-2 text-xs">
            <RefreshCw className="h-4 w-4" /> Refresh app
          </button>
        </div>
      )}
      {canOfferInstall && (
        <div role="region" aria-label="Install Bratha Matu app" className="flex min-w-0 flex-wrap items-center gap-2 rounded-lg border border-noc-accent/30 bg-noc-panel px-3 py-2 text-xs text-slate-200">
          <Download className="h-4 w-4 flex-shrink-0 text-noc-accent" />
          <span className="min-w-0 flex-1">Install Bratha Matu for a standalone app experience.</span>
          {installPrompt ? (
            <button
              type="button"
              onClick={() => {
                void install().then((choice) => {
                  if (choice === "dismissed") setInstallDismissed(true);
                });
              }}
              className="btn-primary min-h-11 text-xs"
            >
              Install app
            </button>
          ) : (
            <button type="button" onClick={() => setInstallHelpOpen(true)} className="btn-secondary min-h-11 text-xs">Install help</button>
          )}
          <button
            type="button"
            onClick={() => {
              dismissInstall();
              setInstallDismissed(true);
              setInstallHelpOpen(false);
            }}
            aria-label="Dismiss installation banner for seven days"
            className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg text-slate-400 hover:bg-noc-hover hover:text-white"
          >
            <X className="h-4 w-4" />
          </button>
        </div>
      )}
      {installHelpOpen && !installed && (
        <div role="dialog" aria-modal="false" aria-labelledby="pwa-install-help-title" className="rounded-lg border border-noc-border bg-noc-panel p-3 text-xs text-slate-300">
          <div className="flex items-start justify-between gap-3">
            <div>
              <h2 id="pwa-install-help-title" className="font-semibold text-slate-100">Install from your browser</h2>
              <p className="mt-1">This browser did not provide a native install prompt. Open its menu and choose “Install app” or “Add to Home screen” if that option is available.</p>
            </div>
            <button type="button" onClick={() => setInstallHelpOpen(false)} aria-label="Close install help" className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg hover:bg-noc-hover">
              <X className="h-4 w-4" />
            </button>
          </div>
        </div>
      )}
    </section>
  );
}

export function PwaInstallHelpCard() {
  const { androidInstallHelp, installed } = usePwaExperience();
  if (!androidInstallHelp) return null;

  return (
    <section className="card min-w-0 p-4 sm:p-5" aria-labelledby="pwa-settings-title">
      <h2 id="pwa-settings-title" className="text-base font-semibold text-slate-100">Mobile app</h2>
      <p className="mt-1 text-sm text-slate-400">
        {installed
          ? "Bratha Matu is running in standalone app mode on this device."
          : "To install later, open your browser menu and choose “Install app” or “Add to Home screen” if available. Chrome may show an Install app prompt when this site is eligible."}
      </p>
    </section>
  );
}
