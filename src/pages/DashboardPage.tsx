import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import {
  Router as RouterIcon,
  Wifi,
  WifiOff,
  AlertTriangle,
  Users,
  HardDrive,
  Activity,
  TrendingUp,
} from "lucide-react";
import { routerService } from "../services/RouterService";
import type { DashboardSummary, Router } from "../types";
import { formatBytes, formatNumber, timeAgo, statusBadge } from "../utils/format";
import { LoadingSpinner } from "../components/ui";

export function DashboardPage() {
  const [summary, setSummary] = useState<DashboardSummary | null>(null);
  const [routers, setRouters] = useState<Router[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.all([routerService.getDashboardSummary(), routerService.getRouters()]).then(
      ([s, r]) => {
        setSummary(s);
        setRouters(r);
        setLoading(false);
      }
    );
  }, []);

  if (loading) return <LoadingSpinner label="Loading network overview..." />;

  const stats = [
    { label: "Total Routers", value: summary ? formatNumber(summary.totalRouters) : "—", icon: RouterIcon, color: "text-blue-400" },
    { label: "Online", value: summary ? formatNumber(summary.online) : "—", icon: Wifi, color: "text-green-400" },
    { label: "Offline", value: summary ? formatNumber(summary.offline) : "—", icon: WifiOff, color: "text-red-400" },
    { label: "Degraded", value: summary ? formatNumber(summary.degraded) : "—", icon: AlertTriangle, color: "text-amber-400" },
    { label: "Total Clients", value: summary ? formatNumber(summary.totalClients) : "—", icon: Users, color: "text-cyan-400" },
    { label: "Total Traffic", value: summary ? formatBytes(summary.totalTrafficBytes) : "—", icon: HardDrive, color: "text-purple-400" },
    { label: "Uptime", value: summary ? `${summary.uptimePercent}%` : "—", icon: Activity, color: "text-emerald-400" },
  ];

  const sortedRouters = [...routers].sort((a, b) => {
    // offline first, then degraded, then online
    const order = { offline: 0, degraded: 1, online: 2, unknown: 3 };
    return order[a.status] - order[b.status];
  });

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-100">Dashboard</h1>
        <p className="text-sm text-slate-400 mt-1">Real-time network operations overview — read-only monitoring.</p>
      </div>

      {/* Stat cards */}
      <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-7 gap-4">
        {stats.map((stat) => {
          const Icon = stat.icon;
          return (
            <div key={stat.label} className="stat-card">
              <div className="flex items-center justify-between mb-2">
                <Icon className={`w-5 h-5 ${stat.color}`} />
              </div>
              <div className="text-2xl font-bold text-slate-100">{stat.value}</div>
              <div className="text-xs text-slate-400 mt-1">{stat.label}</div>
            </div>
          );
        })}
      </div>

      {/* Quick links */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <Link to="/routers?status=offline" className="card p-5 hover:border-red-500/40 transition-colors group">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-lg bg-red-500/10 flex items-center justify-center">
              <WifiOff className="w-5 h-5 text-red-400" />
            </div>
            <div>
              <div className="text-lg font-bold text-slate-100">{summary?.offline ?? 0}</div>
              <div className="text-xs text-slate-400">Routers Offline</div>
            </div>
          </div>
          <div className="text-xs text-slate-500 mt-2 group-hover:text-red-400 transition-colors">View offline center →</div>
        </Link>

        <Link to="/routers?status=degraded" className="card p-5 hover:border-amber-500/40 transition-colors group">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-lg bg-amber-500/10 flex items-center justify-center">
              <AlertTriangle className="w-5 h-5 text-amber-400" />
            </div>
            <div>
              <div className="text-lg font-bold text-slate-100">{summary?.degraded ?? 0}</div>
              <div className="text-xs text-slate-400">Degraded Routers</div>
            </div>
          </div>
          <div className="text-xs text-slate-500 mt-2 group-hover:text-amber-400 transition-colors">Investigate →</div>
        </Link>

        <Link to="/traffic" className="card p-5 hover:border-noc-accent/40 transition-colors group">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-lg bg-noc-accent/10 flex items-center justify-center">
              <TrendingUp className="w-5 h-5 text-noc-accent" />
            </div>
            <div>
              <div className="text-lg font-bold text-slate-100">{summary ? formatBytes(summary.totalTrafficBytes) : "—"}</div>
              <div className="text-xs text-slate-400">Total Traffic</div>
            </div>
          </div>
          <div className="text-xs text-slate-500 mt-2 group-hover:text-noc-accent transition-colors">View traffic →</div>
        </Link>
      </div>

      {/* Router health overview */}
      <div className="card overflow-hidden">
        <div className="px-5 py-4 border-b border-noc-border flex items-center justify-between">
          <h2 className="text-lg font-semibold text-slate-100">Router Health Overview</h2>
          <Link to="/routers" className="text-sm text-noc-accent hover:text-blue-400">View all →</Link>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-xs text-slate-500 uppercase tracking-wider border-b border-noc-border">
                <th className="px-5 py-3 font-medium">Router</th>
                <th className="px-5 py-3 font-medium">Location</th>
                <th className="px-5 py-3 font-medium">IP</th>
                <th className="px-5 py-3 font-medium">Status</th>
                <th className="px-5 py-3 font-medium text-right">Clients</th>
                <th className="px-5 py-3 font-medium">Uptime</th>
                <th className="px-5 py-3 font-medium">Last Seen</th>
              </tr>
            </thead>
            <tbody>
              {sortedRouters.slice(0, 12).map((router) => (
                <tr
                  key={router.id}
                  className="table-row-hover border-b border-noc-border/50 last:border-0"
                  onClick={() => (window.location.href = `/routers/${router.id}`)}
                >
                  <td className="px-5 py-3">
                    <Link to={`/routers/${router.id}`} className="font-medium text-slate-100 hover:text-noc-accent" onClick={(e) => e.stopPropagation()}>
                      {router.name}
                    </Link>
                  </td>
                  <td className="px-5 py-3 text-slate-400">{router.location}</td>
                  <td className="px-5 py-3 font-mono text-xs text-slate-400">{router.ipAddress}</td>
                  <td className="px-5 py-3">
                    <span className={statusBadge(router.status)}>
                      <span className={`status-dot ${
                        router.status === "online" ? "bg-green-500" :
                        router.status === "offline" ? "bg-red-500" :
                        router.status === "degraded" ? "bg-amber-500" : "bg-slate-500"
                      }`} />
                      {router.status.charAt(0).toUpperCase() + router.status.slice(1)}
                    </span>
                  </td>
                  <td className="px-5 py-3 text-right text-slate-400">
                    {router.status === "offline" ? "—" : "—"}
                  </td>
                  <td className="px-5 py-3 text-slate-400">
                    {router.status === "offline" ? "—" : "—"}
                  </td>
                  <td className="px-5 py-3 text-slate-400 text-xs">{timeAgo(router.lastSeenAt)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {sortedRouters.length > 12 && (
          <div className="px-5 py-3 border-t border-noc-border text-center">
            <Link to="/routers" className="text-sm text-noc-accent hover:text-blue-400">
              View all {sortedRouters.length} routers →
            </Link>
          </div>
        )}
      </div>
    </div>
  );
}
