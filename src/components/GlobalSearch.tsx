import { useEffect, useState, useRef } from "react";
import { useNavigate } from "react-router-dom";
import { Search, X } from "lucide-react";
import { routerService } from "../services/RouterService";
import type { Router } from "../types";
import { STATUS_CONFIG } from "../utils/format";

export function GlobalSearch() {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<Router[]>([]);
  const [loading, setLoading] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const dialogRef = useRef<HTMLDivElement>(null);
  const wasOpenRef = useRef(false);
  const navigate = useNavigate();

  // / keyboard shortcut
  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if (e.key === "/" && !open && document.activeElement?.tagName !== "INPUT") {
        e.preventDefault();
        setOpen(true);
      }
      if (e.key === "Escape") {
        setOpen(false);
        return;
      }
      if (open && e.key === "Tab" && dialogRef.current) {
        const focusable = dialogRef.current.querySelectorAll<HTMLElement>(
          'button:not([disabled]), input:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])',
        );
        if (focusable.length === 0) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) {
          e.preventDefault();
          last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault();
          first.focus();
        }
      }
    };
    window.addEventListener("keydown", handler);
    return () => window.removeEventListener("keydown", handler);
  }, [open]);

  useEffect(() => {
    if (open) {
      wasOpenRef.current = true;
      const timer = window.setTimeout(() => inputRef.current?.focus(), 50);
      return () => window.clearTimeout(timer);
    } else {
      setQuery("");
      setResults([]);
      if (wasOpenRef.current) {
        wasOpenRef.current = false;
        triggerRef.current?.focus();
      }
    }
  }, [open]);

  useEffect(() => {
    if (!open) return;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => {
      document.body.style.overflow = previousOverflow;
    };
  }, [open]);

  useEffect(() => {
    if (!query.trim()) {
      setResults([]);
      return;
    }
    setLoading(true);
    const timer = setTimeout(async () => {
      const res = await routerService.searchRouters(query);
      setResults(res);
      setLoading(false);
    }, 150);
    return () => clearTimeout(timer);
  }, [query]);

  const selectRouter = (id: string) => {
    setOpen(false);
    navigate(`/routers/${id}`);
  };

  if (!open) {
    return (
      <button
        ref={triggerRef}
        onClick={() => setOpen(true)}
        className="flex min-h-11 w-full max-w-md min-w-0 items-center gap-2 rounded-lg border border-noc-border bg-noc-panel px-3 py-2 text-left text-sm text-slate-400 transition-colors hover:border-noc-accent/40 hover:text-slate-200 sm:px-4"
        aria-label="Search routers"
      >
        <Search className="w-4 h-4" />
        <span className="min-w-0 flex-1 truncate">Search routers, IPs, MAC addresses...</span>
        <kbd className="hidden flex-shrink-0 rounded bg-noc-border px-1.5 py-0.5 font-mono text-xs sm:inline">/</kbd>
      </button>
    );
  }

  return (
    <div
      className="fixed inset-0 z-50 flex h-dvh items-start justify-center overflow-y-auto px-3 pb-4 pt-[max(1rem,env(safe-area-inset-top))] animate-fade-in sm:px-4 sm:pt-20"
      role="dialog"
      aria-modal="true"
      aria-labelledby="router-search-title"
      ref={dialogRef}
      onClick={() => setOpen(false)}
    >
      <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" aria-hidden="true" />
      <div
        className="relative flex max-h-[calc(100dvh-2rem)] w-full min-w-0 max-w-xl flex-col overflow-hidden rounded-xl border border-noc-border bg-noc-card shadow-2xl animate-slide-up"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center gap-3 px-4 py-3 border-b border-noc-border">
          <Search className="w-5 h-5 text-slate-400" />
          <span id="router-search-title" className="sr-only">Search routers</span>
          <input
            ref={inputRef}
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Search by name, IP, MAC, location, model, serial..."
            className="min-w-0 flex-1 bg-transparent text-base text-slate-100 placeholder-slate-500 focus:outline-none"
          />
          <button onClick={() => setOpen(false)} aria-label="Close search" className="inline-flex min-h-11 min-w-11 flex-shrink-0 items-center justify-center rounded-lg text-slate-400 hover:bg-noc-hover hover:text-white">
            <X className="w-5 h-5" />
          </button>
        </div>
        <div className="min-h-0 overflow-y-auto overscroll-contain">
          {loading && <div className="px-4 py-6 text-sm text-slate-400 text-center">Searching...</div>}
          {!loading && query && results.length === 0 && (
            <div className="px-4 py-6 text-sm text-slate-500 text-center">No routers found for "{query}"</div>
          )}
          {!loading && results.length > 0 && (
            <div className="py-2">
              {results.map((r) => {
                const cfg = STATUS_CONFIG[r.status];
                return (
                  <button
                    key={r.id}
                    onClick={() => selectRouter(r.id)}
                    className="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-noc-hover transition-colors text-left"
                  >
                    <span className={`status-dot ${cfg.dotClass}`} />
                    <div className="flex-1 min-w-0">
                      <div className="text-sm font-medium text-slate-100">{r.name}</div>
                      <div className="text-xs text-slate-500 font-mono">{r.ipAddress} · {r.location}</div>
                    </div>
                    <span className={`text-xs font-medium ${cfg.textClass}`}>{cfg.label}</span>
                  </button>
                );
              })}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
