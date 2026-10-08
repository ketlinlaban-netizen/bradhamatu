import { useEffect, useState } from "react";
import { Download, Router as RouterIcon, Users, Activity, TrendingUp } from "lucide-react";
import { routerService } from "../services/RouterService";
import type { DashboardSummary, Router, Incident } from "../types";
import { formatBytes, formatNumber, timeAgo } from "../utils/format";
import { LoadingSpinner } from "../components/ui";

export function ReportsPage() {
  const [summary, setSummary] = useState<DashboardSummary | null>(null);
  const [routers, setRouters] = useState<Router[]>([]);
  const [incidents, setIncidents] = useState<Incident[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.all([
      routerService.getDashboardSummary(),
      routerService.getRouters(),
      routerService.getIncidents(),
    ]).then(([s, r, i]) => {
      setSummary(s);
      setRouters(r);
      setIncidents(i);
      setLoading(false);
    });
  }, []);

  if (loading) return <LoadingSpinner label="Generating reports..." />;

  const reportCards = [
    { title: "Network Summary", desc: "Overview of all routers, clients, and traffic", icon: TrendingUp, data: `Routers: ${summary?.totalRouters} | Online: ${summary?.online} | Offline: ${summary?.offline} | Clients: ${formatNumber(summary?.totalClients ?? 0)}` },
    { title: "Router Inventory", desc: "Full list of all routers with details", icon: RouterIcon, data: `${routers.length} routers across ${new Set(routers.map((r) => r.location)).size} locations` },
    { title: "Incident Log", desc: "All active and recent incidents", icon: Activity, data: `${incidents.length} incidents (${incidents.filter((i) => !i.resolvedAt).length} active)` },
    { title: "Client Census", desc: "Total connected clients network-wide", icon: Users, data: `${formatNumber(summary?.totalClients ?? 0)} clients connected` },
  ];

  const handleExport = () => {
    const lines: string[] = [];
    lines.push("COMMUNITY WIFI BRADHA MATU — NETWORK REPORT");
    lines.push(`Generated: ${new Date().toISOString()}`);
    lines.push("");
    lines.push("=== SUMMARY ===");
    lines.push(`Total Routers: ${summary?.totalRouters}`);
    lines.push(`Online: ${summary?.online}`);
    lines.push(`Offline: ${summary?.offline}`);
    lines.push(`Degraded: ${summary?.degraded}`);
    lines.push(`Total Clients: ${summary?.totalClients}`);
    lines.push(`Total Traffic: ${formatBytes(summary?.totalTrafficBytes ?? 0)}`);
    lines.push(`Uptime: ${summary?.uptimePercent}%`);
    lines.push("");
    lines.push("=== ROUTERS ===");
    lines.push("Name,Location,IP,Status,Model,RouterOS,LastSeen");
    for (const r of routers) {
      lines.push(`${r.name},${r.location},${r.ipAddress},${r.status},${r.model},${r.routerosVersion},${r.lastSeenAt}`);
    }
    lines.push("");
    lines.push("=== INCIDENTS ===");
    lines.push("Router,Type,Severity,Message,Started");
    for (const i of incidents) {
      lines.push(`${i.routerName},${i.type},${i.severity},${i.message},${i.startedAt}`);
    }
    const blob = new Blob([lines.join("\n")], { type: "text/plain" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `bradha-matu-report-${Date.now()}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  };

  return (
    <div className="space-y-6">
      <div className="flex items-start justify-between flex-wrap gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-100">Reports</h1>
          <p className="text-sm text-slate-400 mt-1">Read-only network reports and exportable summaries.</p>
        </div>
        <button onClick={handleExport} className="btn-primary flex items-center gap-2 text-sm">
          <Download className="w-4 h-4" />
          Export CSV Report
        </button>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {reportCards.map((card) => {
          const Icon = card.icon;
          return (
            <div key={card.title} className="card p-5">
              <div className="flex items-start gap-4">
                <div className="w-10 h-10 rounded-lg bg-noc-accent/10 flex items-center justify-center flex-shrink-0">
                  <Icon className="w-5 h-5 text-noc-accent" />
                </div>
                <div>
                  <h3 className="text-sm font-semibold text-slate-100">{card.title}</h3>
                  <p className="text-xs text-slate-400 mt-0.5">{card.desc}</p>
                  <p className="text-sm text-slate-300 mt-2 font-mono">{card.data}</p>
                </div>
              </div>
            </div>
          );
        })}
      </div>

      <div className="card overflow-hidden">
        <div className="px-5 py-4 border-b border-noc-border">
          <h2 className="text-lg font-semibold text-slate-100">Full Router Inventory Report</h2>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-xs text-slate-500 uppercase tracking-wider border-b border-noc-border">
                <th className="px-5 py-3 font-medium">Router</th>
                <th className="px-5 py-3 font-medium">Location</th>
                <th className="px-5 py-3 font-medium">IP</th>
                <th className="px-5 py-3 font-medium">Model</th>
                <th className="px-5 py-3 font-medium">RouterOS</th>
                <th className="px-5 py-3 font-medium">Status</th>
                <th className="px-5 py-3 font-medium">Last Seen</th>
              </tr>
            </thead>
            <tbody>
              {routers.map((r) => (
                <tr key={r.id} className="border-b border-noc-border/50 last:border-0">
                  <td className="px-5 py-3 font-medium text-slate-200">{r.name}</td>
                  <td className="px-5 py-3 text-slate-400">{r.location}</td>
                  <td className="px-5 py-3 font-mono text-xs text-slate-400">{r.ipAddress}</td>
                  <td className="px-5 py-3 text-slate-400">{r.model}</td>
                  <td className="px-5 py-3 text-slate-400">{r.routerosVersion}</td>
                  <td className="px-5 py-3">
                    <span className={`text-xs font-medium ${
                      r.status === "online" ? "text-green-400" :
                      r.status === "offline" ? "text-red-400" :
                      r.status === "degraded" ? "text-amber-400" : "text-slate-400"
                    }`}>
                      {r.status}
                    </span>
                  </td>
                  <td className="px-5 py-3 text-slate-500 text-xs">{timeAgo(r.lastSeenAt)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
