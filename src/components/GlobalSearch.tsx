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
      }
    };
    window.addEventListener("keydown", handler);
    return () => window.removeEventListener("keydown", handler);
  }, [open]);

  useEffect(() => {
    if (open) {
      setTimeout(() => inputRef.current?.focus(), 50);
    } else {
      setQuery("");
      setResults([]);
    }
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
        onClick={() => setOpen(true)}
        className="flex items-center gap-2 px-4 py-2 bg-noc-panel border border-noc-border rounded-lg text-slate-400 hover:text-slate-200 hover:border-noc-accent/40 transition-colors w-full max-w-md text-sm"
      >
        <Search className="w-4 h-4" />
        <span>Search routers, IPs, MAC addresses...</span>
        <kbd className="ml-auto text-xs bg-noc-border px-1.5 py-0.5 rounded font-mono">/</kbd>
      </button>
    );
  }

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center pt-20 px-4 animate-fade-in" onClick={() => setOpen(false)}>
      <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" />
      <div
        className="relative w-full max-w-xl bg-noc-card border border-noc-border rounded-xl shadow-2xl overflow-hidden animate-slide-up"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center gap-3 px-4 py-3 border-b border-noc-border">
          <Search className="w-5 h-5 text-slate-400" />
          <input
            ref={inputRef}
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Search by name, IP, MAC, location, model, serial..."
            className="flex-1 bg-transparent text-slate-100 placeholder-slate-500 focus:outline-none text-sm"
          />
          <button onClick={() => setOpen(false)} className="text-slate-400 hover:text-white">
            <X className="w-5 h-5" />
          </button>
        </div>
        <div className="max-h-96 overflow-y-auto">
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
