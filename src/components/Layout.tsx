import { useState, useEffect, useCallback } from "react";
import { Outlet, useNavigate } from "react-router-dom";
import { Menu, X, RefreshCw } from "lucide-react";
import { Sidebar } from "./Sidebar";
import { GlobalSearch } from "./GlobalSearch";
import { RealtimeNotifications, type RealtimeNotification } from "./RealtimeNotifications";
import { useAuth } from "../auth/AuthContext";
import { routerService } from "../services/RouterService";
import { apiGet } from "../services/api";
import type { Incident } from "../types";

export function Layout() {
  const { user, isLoading } = useAuth();
  const navigate = useNavigate();
  const [mobileSidebar, setMobileSidebar] = useState(false);
  const [provider, setProvider] = useState<"mock" | "mikrotik" | "rest" | "unavailable" | "checking">("checking");

  useEffect(() => {
    if (!isLoading && !user) {
      navigate("/login");
    }
  }, [user, isLoading, navigate]);

  const loadNotifications = useCallback(async (): Promise<RealtimeNotification[]> => {
    if (!user) return [];

    try {
      const incidents: Incident[] = await routerService.getIncidents();
      const activeIncidents = incidents.filter((incident) => !incident.resolvedAt);

      return activeIncidents.slice(0, 5).map((incident) => ({
        id: `incident-${incident.id}`,
        title: incident.routerName,
        summary: `${incident.type.replace("_", " ")} · ${incident.message}`,
        createdAt: incident.startedAt,
        kind: incident.severity === "critical" ? "critical" : incident.severity === "warning" ? "warning" : "info",
      }));
    } catch {
      return [];
    }
  }, [user]);

  useEffect(() => {
    void loadNotifications();
  }, [loadNotifications]);

  useEffect(() => {
    apiGet<{ provider: "mock" | "mikrotik" | "rest" }>("/health")
      .then((health) => setProvider(health.provider))
      .catch(() => setProvider("unavailable"));
  }, []);

  if (isLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-noc-bg">
        <div className="w-8 h-8 border-2 border-noc-border border-t-noc-accent rounded-full animate-spin" />
      </div>
    );
  }

  if (!user) return null;

  return (
    <div className="min-h-screen bg-noc-bg flex">
      {/* Desktop sidebar */}
      <div className="hidden lg:block fixed inset-y-0 left-0">
        <Sidebar />
      </div>

      {/* Mobile sidebar */}
      {mobileSidebar && (
        <div className="lg:hidden fixed inset-0 z-40 flex">
          <div className="absolute inset-0 bg-black/60" onClick={() => setMobileSidebar(false)} />
          <div className="relative">
            <Sidebar onNavigate={() => setMobileSidebar(false)} />
          </div>
        </div>
      )}

      {/* Main content */}
      <div className="flex-1 lg:ml-64 flex flex-col min-h-screen">
        {/* Top bar */}
        <header className="sticky top-0 z-30 glass border-b border-noc-border px-4 py-3 flex items-center gap-4">
          <button
            onClick={() => setMobileSidebar(true)}
            className="lg:hidden text-slate-400 hover:text-white"
          >
            <Menu className="w-5 h-5" />
          </button>

          <div className="flex-1 max-w-md">
            <GlobalSearch />
          </div>

          <div className="ml-auto flex items-center gap-3">
            <RealtimeNotifications
              title="Network alerts"
              emptyMessage="No active incidents. Network is stable."
              loadNotifications={loadNotifications}
            />
            <div className="hidden sm:flex items-center gap-2 text-xs text-slate-500">
              {provider === "checking" ? <RefreshCw className="h-3 w-3 animate-spin" /> : (
                <span className={`w-2 h-2 rounded-full ${
                  provider === "mock" ? "bg-amber-400" :
                  provider === "unavailable" ? "bg-slate-500" : "bg-cyan-400"
                }`} />
              )}
              <span className="font-mono">
                {provider === "mock" ? "DEMO" : provider === "unavailable" ? "SOURCE UNKNOWN" : provider === "checking" ? "CHECKING" : "LIVE PROVIDER"}
              </span>
            </div>
          </div>
        </header>

        {/* Page content */}
        <main className="flex-1 p-4 lg:p-6 animate-fade-in">
          {provider === "mock" && (
            <div role="status" className="mb-5 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
              Demo data is active. Router metrics are fictional and no physical router was contacted.
            </div>
          )}
          {(provider === "mikrotik" || provider === "rest") && (
            <div role="status" className="mb-5 rounded-lg border border-cyan-500/20 bg-cyan-500/5 px-4 py-3 text-sm text-cyan-100">
              {provider === "rest" ? "HTTPS REST" : "Binary API"} live provider is configured. This does not confirm each router is currently reachable; check per-router status and last-seen data.
            </div>
          )}
          {provider === "unavailable" && (
            <div role="alert" className="mb-5 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
              Could not verify the monitoring data source. Router metrics may be unavailable; use the retry action on the affected page.
            </div>
          )}
          <Outlet />
        </main>
      </div>

      {/* Close button for mobile when sidebar is via overlay — hidden by default */}
      {mobileSidebar && (
        <button
          onClick={() => setMobileSidebar(false)}
          className="lg:hidden fixed top-3 right-3 z-50 text-white"
        >
          <X className="w-5 h-5" />
        </button>
      )}
    </div>
  );
}
