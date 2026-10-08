import { NavLink, useLocation } from "react-router-dom";
import {
  LayoutDashboard,
  Router as RouterIcon,
  Users,
  Activity,
  HeartPulse,
  AlertTriangle,
  FileText,
  Settings,
  LogOut,
  Wifi,
  CircleOff,
  Zap,
  ShieldCheck,
  Cable,
} from "lucide-react";
import { useAuth } from "../auth/AuthContext";

interface NavItem {
  to: string;
  label: string;
  icon: typeof LayoutDashboard;
  end?: boolean;
}

const navSections: Array<{ label?: string; items: NavItem[] }> = [
  {
    items: [
      { to: "/", label: "Dashboard", icon: LayoutDashboard, end: true },
    ],
  },
  {
    label: "Routers",
    items: [
      { to: "/routers", label: "All Routers", icon: RouterIcon },
      { to: "/routers?status=online", label: "Online", icon: Zap },
      { to: "/routers?status=offline", label: "Offline", icon: CircleOff },
      { to: "/routers?status=degraded", label: "Degraded", icon: AlertTriangle },
      { to: "/offline", label: "Offline Center", icon: CircleOff },
    ],
  },
  {
    items: [
      { to: "/clients", label: "Clients", icon: Users },
      { to: "/traffic", label: "Traffic", icon: Activity },
      { to: "/network-health", label: "Network Health", icon: HeartPulse },
      { to: "/incidents", label: "Incidents", icon: AlertTriangle },
      { to: "/reports", label: "Reports", icon: FileText },
      { to: "/mikrotik-config", label: "MikroTik Config", icon: Cable },
      { to: "/system", label: "System", icon: Settings },
    ],
  },
];

export function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
  const { user, logout } = useAuth();
  const location = useLocation();

  return (
    <aside className="h-full w-64 bg-noc-panel border-r border-noc-border flex flex-col">
      {/* Brand */}
      <div className="px-5 py-5 border-b border-noc-border">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-lg bg-gradient-to-br from-noc-accent to-noc-accent2 flex items-center justify-center">
            <Wifi className="w-5 h-5 text-white" />
          </div>
          <div>
            <div className="text-sm font-bold text-slate-100 leading-tight">COMMUNITY WIFI</div>
            <div className="text-xs text-slate-400 leading-tight">BRADHA MATU</div>
          </div>
        </div>
        <div className="mt-3 text-[10px] font-mono text-slate-500 uppercase tracking-wider">
          Network Operations Console
        </div>
      </div>

      {/* Nav */}
      <nav className="flex-1 overflow-y-auto py-4 px-3">
        {navSections.map((section, sIdx) => (
          <div key={sIdx} className="mb-4">
            {section.label && (
              <div className="px-3 mb-1.5 text-[10px] font-semibold text-slate-500 uppercase tracking-wider">
                {section.label}
              </div>
            )}
            {section.items.map((item) => {
              const Icon = item.icon;
              const isActive = location.pathname + location.search === item.to ||
                (item.end === true && location.pathname === item.to);
              return (
                <NavLink
                  key={item.to}
                  to={item.to}
                  end={item.end}
                  onClick={onNavigate}
                  className={`flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors mb-0.5 ${
                    isActive
                      ? "bg-noc-accent/10 text-noc-accent font-medium"
                      : "text-slate-400 hover:text-slate-100 hover:bg-noc-hover"
                  }`}
                >
                  <Icon className="w-4 h-4" />
                  {item.label}
                </NavLink>
              );
            })}
          </div>
        ))}
      </nav>

      {/* Read-only indicator */}
      <div className="px-3 pb-3">
        <div className="flex items-center gap-2 px-3 py-2 rounded-lg bg-green-500/5 border border-green-500/10">
          <ShieldCheck className="w-4 h-4 text-green-500" />
          <div className="text-[11px] leading-tight">
            <div className="text-green-400 font-medium">READ-ONLY MODE</div>
            <div className="text-slate-500">No mutations permitted</div>
          </div>
        </div>
      </div>

      {/* User */}
      <div className="px-3 py-3 border-t border-noc-border">
        <div className="flex items-center gap-3 mb-2">
          <div className="w-8 h-8 rounded-full bg-noc-accent/20 flex items-center justify-center text-xs font-semibold text-noc-accent">
            {user?.name?.charAt(0).toUpperCase() ?? "A"}
          </div>
          <div className="flex-1 min-w-0">
            <div className="text-xs font-medium text-slate-200 truncate">{user?.name}</div>
            <div className="text-[10px] text-slate-500 truncate">{user?.email}</div>
          </div>
        </div>
        <div className="flex items-center justify-between">
          <span className="text-[10px] px-2 py-0.5 rounded font-mono bg-noc-border text-slate-400 uppercase">
            {user?.role.replace("_", " ")}
          </span>
          <button
            onClick={logout}
            className="flex items-center gap-1.5 text-xs text-slate-400 hover:text-red-400 transition-colors"
          >
            <LogOut className="w-3.5 h-3.5" />
            Logout
          </button>
        </div>
      </div>
    </aside>
  );
}
