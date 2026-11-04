import { useState, useEffect, useRef } from "react";
import { Outlet, useNavigate } from "react-router-dom";
import { Menu, X, Bell } from "lucide-react";
import { Sidebar } from "./Sidebar";
import { GlobalSearch } from "./GlobalSearch";
import { PwaNotices } from "./PwaExperience";
import { useAuth } from "../auth/AuthContext";
import { routerService } from "../services/RouterService";
import type { Incident } from "../types";
import { usePwaExperience } from "./PwaExperience";

export function Layout() {
  const { user, isLoading } = useAuth();
  const navigate = useNavigate();
  const { offline } = usePwaExperience();
  const [mobileSidebar, setMobileSidebar] = useState(false);
  const [incidentCount, setIncidentCount] = useState(0);
  const menuButtonRef = useRef<HTMLButtonElement>(null);
  const drawerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!isLoading && !user) {
      navigate("/login");
    }
  }, [user, isLoading, navigate]);

  useEffect(() => {
    if (user) {
      routerService.getIncidents().then((incidents: Incident[]) => {
        setIncidentCount(incidents.filter((i) => !i.resolvedAt).length);
      });
    }
  }, [user]);

  useEffect(() => {
    if (!mobileSidebar) return;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        setMobileSidebar(false);
        return;
      }
      if (event.key !== "Tab" || !drawerRef.current) return;
      const focusable = drawerRef.current.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
      );
      if (focusable.length === 0) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };
    document.addEventListener("keydown", handleKeyDown);
    drawerRef.current?.querySelector<HTMLElement>('a[href], button:not([tabindex="-1"])')?.focus();
    return () => {
      document.body.style.overflow = previousOverflow;
      document.removeEventListener("keydown", handleKeyDown);
      menuButtonRef.current?.focus();
    };
  }, [mobileSidebar]);

  if (isLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-noc-bg">
        <div className="w-8 h-8 border-2 border-noc-border border-t-noc-accent rounded-full animate-spin" />
      </div>
    );
  }

  if (!user) return null;

  return (
    <div className="min-h-screen w-full min-w-0 bg-noc-bg flex">
      {/* Desktop sidebar */}
      <div className="hidden lg:block fixed inset-y-0 left-0">
        <Sidebar />
      </div>

      {/* Mobile sidebar */}
      {mobileSidebar && (
        <div ref={drawerRef} className="lg:hidden fixed inset-0 z-40 flex" role="dialog" aria-modal="true" aria-label="Main navigation">
          <button className="absolute inset-0 bg-black/60" onClick={() => setMobileSidebar(false)} aria-label="Close navigation menu" tabIndex={-1} />
          <div className="relative z-10 h-full max-h-[100dvh] overflow-y-auto">
            <Sidebar onNavigate={() => setMobileSidebar(false)} />
          </div>
          <button
            onClick={() => setMobileSidebar(false)}
            className="absolute right-3 top-3 z-20 inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg bg-noc-panel text-white"
            aria-label="Close navigation menu"
          >
            <X className="h-5 w-5" />
          </button>
        </div>
      )}

      {/* Main content */}
      <div className="flex min-h-screen min-w-0 w-full flex-1 flex-col lg:ml-64">
        {/* Top bar */}
        <header className="sticky top-0 z-30 flex min-w-0 items-center gap-2 border-b border-noc-border px-3 py-2.5 glass sm:gap-4 sm:px-4 sm:py-3">
          <button
            ref={menuButtonRef}
            onClick={() => setMobileSidebar(true)}
            className="inline-flex min-h-11 min-w-11 flex-shrink-0 items-center justify-center rounded-lg text-slate-400 hover:bg-noc-hover hover:text-white lg:hidden"
            aria-label="Open navigation menu"
            aria-expanded={mobileSidebar}
          >
            <Menu className="w-5 h-5" />
          </button>

          <div className="min-w-0 flex-1 max-w-md">
            <GlobalSearch />
          </div>

          <div className="ml-auto flex flex-shrink-0 items-center gap-1 sm:gap-3">
            <button
              onClick={() => navigate("/incidents")}
              className="relative inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg p-2 text-slate-400 transition-colors hover:bg-noc-hover hover:text-white"
              title="Incidents"
              aria-label={`Incidents${incidentCount > 0 ? `, ${incidentCount} unresolved` : ""}`}
            >
              <Bell className="w-5 h-5" />
              {incidentCount > 0 && (
                <span className="absolute -top-0.5 -right-0.5 w-4 h-4 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center">
                  {incidentCount}
                </span>
              )}
            </button>
            <div className="hidden sm:flex items-center gap-2 text-xs text-slate-500">
              <span className={`h-2 w-2 rounded-full ${offline ? "bg-amber-400" : "bg-green-500 animate-pulse-slow"}`} />
              <span className={`font-mono ${offline ? "text-amber-300" : ""}`}>{offline ? "API OFFLINE" : "API ONLINE"}</span>
            </div>
          </div>
        </header>

        {/* Page content */}
        <PwaNotices />
        <main className="min-w-0 w-full flex-1 p-3 animate-fade-in sm:p-4 lg:p-6">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
