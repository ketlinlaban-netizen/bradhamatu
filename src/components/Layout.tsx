import { useState, useEffect } from "react";
import { Outlet, useNavigate } from "react-router-dom";
import { Menu, X, Bell } from "lucide-react";
import { Sidebar } from "./Sidebar";
import { GlobalSearch } from "./GlobalSearch";
import { useAuth } from "../auth/AuthContext";
import { routerService } from "../services/RouterService";
import type { Incident } from "../types";

export function Layout() {
  const { user, isLoading } = useAuth();
  const navigate = useNavigate();
  const [mobileSidebar, setMobileSidebar] = useState(false);
  const [incidentCount, setIncidentCount] = useState(0);

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
            <button
              onClick={() => navigate("/incidents")}
              className="relative text-slate-400 hover:text-white p-2 rounded-lg hover:bg-noc-hover transition-colors"
              title="Incidents"
            >
              <Bell className="w-5 h-5" />
              {incidentCount > 0 && (
                <span className="absolute -top-0.5 -right-0.5 w-4 h-4 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center">
                  {incidentCount}
                </span>
              )}
            </button>
            <div className="hidden sm:flex items-center gap-2 text-xs text-slate-500">
              <span className="w-2 h-2 rounded-full bg-green-500 animate-pulse-slow" />
              <span className="font-mono">LIVE</span>
            </div>
          </div>
        </header>

        {/* Page content */}
        <main className="flex-1 p-4 lg:p-6 animate-fade-in">
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
