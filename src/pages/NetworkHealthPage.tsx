import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Cpu, MemoryStick, Thermometer, Activity, RefreshCw } from "lucide-react";
import { routerService } from "../services/RouterService";
import type { Router, RouterStatusInfo } from "../types";
import { formatUptime } from "../utils/format";

export function NetworkHealthPage() {
  const [routers, setRouters] = useState<Router[]>([]);
  const [statuses, setStatuses] = useState<Record<string, RouterStatusInfo>>({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const loadHealth = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const all = await routerService.getRouters();
      setRouters(all);
      const results = await Promise.allSettled(
        all.filter((router) => router.status !== "offline").map(async (router) => {
          const status = await routerService.getStatus(router.id);
          return [router.id, status] as const;
        }),
      );
      const nextStatuses: Record<string, RouterStatusInfo> = {};
      let failedCount = 0;
      for (const result of results) {
        if (result.status === "fulfilled" && result.value[1]) {
          nextStatuses[result.value[0]] = result.value[1];
        } else if (result.status === "rejected") {
          failedCount += 1;
        }
      }
      setStatuses(nextStatuses);
      if (failedCount > 0) {
        setError(`Health data could not be loaded for ${failedCount} router${failedCount === 1 ? "" : "s"}. Other available router data is shown.`);
      }
    } catch (loadError) {
      setError(loadError instanceof Error ? loadError.message : "Could not load network health.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadHealth();
  }, [loadHealth]);

  const onlineRouters = routers.filter((r) => r.status !== "offline");
  const average = (values: Array<number | null | undefined>): number | null => {
    const available = values.filter((value): value is number => value !== null && value !== undefined);
    return available.length > 0
      ? available.reduce((sum, value) => sum + value, 0) / available.length
      : null;
  };
  const avgCpu = average(onlineRouters.map((router) => statuses[router.id]?.cpuUsage));
  const avgMem = average(onlineRouters.map((router) => statuses[router.id]?.memoryUsage));
  const avgTemp = average(onlineRouters.map((router) => statuses[router.id]?.temperature));
  const avgLatency = average(onlineRouters.map((router) => statuses[router.id]?.connectionLatencyMs));

  const healthMetrics = [
    { label: "Avg CPU", value: avgCpu === null ? "—" : `${avgCpu.toFixed(1)}%`, icon: Cpu, color: "text-blue-400", barColor: "bg-blue-500", percent: avgCpu },
    { label: "Avg Memory", value: avgMem === null ? "—" : `${avgMem.toFixed(1)}%`, icon: MemoryStick, color: "text-cyan-400", barColor: "bg-cyan-500", percent: avgMem },
    { label: "Avg Temperature", value: avgTemp === null ? "—" : `${avgTemp.toFixed(1)}°C`, icon: Thermometer, color: "text-amber-400", barColor: "bg-amber-500", percent: avgTemp === null ? null : (avgTemp / 70) * 100 },
    { label: "Avg Latency", value: avgLatency === null ? "—" : `${avgLatency.toFixed(1)} ms`, icon: Activity, color: "text-green-400", barColor: "bg-green-500", percent: avgLatency === null ? null : (avgLatency / 10) * 100 },
  ];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-100">Network Health</h1>
        <p className="text-sm text-slate-400 mt-1">Aggregate health metrics across all online routers.</p>
        {loading && <p className="mt-1 text-xs text-slate-500">Loading saved router health…</p>}
      </div>
      {error && (
        <div role="alert" className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
          <span>{error}</span>
          <button type="button" className="btn-secondary flex items-center gap-2 !px-3 !py-1.5" onClick={() => void loadHealth()} disabled={loading}>
            <RefreshCw className={`h-3.5 w-3.5 ${loading ? "animate-spin" : ""}`} />Retry
          </button>
        </div>
      )}

      {/* Health metrics */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {healthMetrics.map((m) => {
          const Icon = m.icon;
          return (
            <div key={m.label} className="card p-5">
              <div className="flex items-center gap-2 mb-3">
                <Icon className={`w-4 h-4 ${m.color}`} />
                <span className="text-xs text-slate-400">{m.label}</span>
              </div>
              <div className="text-xl font-bold text-slate-100 mb-2">{m.value}</div>
              <div className="h-1.5 bg-noc-border rounded-full overflow-hidden">
                {m.percent !== null && <div className={`h-full ${m.barColor} rounded-full transition-all duration-500`} style={{ width: `${Math.min(m.percent, 100)}%` }} />}
              </div>
            </div>
          );
        })}
      </div>

      {/* Per-router health */}
      <div className="card overflow-hidden">
        <div className="px-5 py-4 border-b border-noc-border">
          <h2 className="text-lg font-semibold text-slate-100">Per-Router Health</h2>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-xs text-slate-500 uppercase tracking-wider border-b border-noc-border">
                <th className="px-5 py-3 font-medium">Router</th>
                <th className="px-5 py-3 font-medium text-right">CPU</th>
                <th className="px-5 py-3 font-medium text-right">Memory</th>
                <th className="px-5 py-3 font-medium text-right">Storage</th>
                <th className="px-5 py-3 font-medium text-right">Temp</th>
                <th className="px-5 py-3 font-medium text-right">Latency</th>
                <th className="px-5 py-3 font-medium">Uptime</th>
              </tr>
            </thead>
            <tbody>
              {routers.length === 0 && !loading ? (
                <tr><td colSpan={7} className="px-5 py-8 text-center text-sm text-slate-500">{error ? "Router health could not be loaded." : "No routers are configured."}</td></tr>
              ) : routers.map((router) => {
                const s = statuses[router.id];
                const isOffline = router.status === "offline";
                return (
                  <tr key={router.id} className="border-b border-noc-border/50 last:border-0 hover:bg-noc-hover/50">
                    <td className="px-5 py-3">
                      <Link to={`/admin/routers/${router.id}`} className="font-medium text-slate-100 hover:text-noc-accent">{router.name}</Link>
                      <div className="text-xs text-slate-500">{router.location}</div>
                    </td>
                    <td className="px-5 py-3 text-right font-mono text-xs">
                      <span className={isOffline ? "text-slate-600" : (s?.cpuUsage ?? 0) > 80 ? "text-red-400" : (s?.cpuUsage ?? 0) > 60 ? "text-amber-400" : "text-slate-300"}>
                        {isOffline || s?.cpuUsage == null ? "—" : `${s.cpuUsage}%`}
                      </span>
                    </td>
                    <td className="px-5 py-3 text-right font-mono text-xs">
                      <span className={isOffline ? "text-slate-600" : (s?.memoryUsage ?? 0) > 80 ? "text-red-400" : (s?.memoryUsage ?? 0) > 60 ? "text-amber-400" : "text-slate-300"}>
                        {isOffline || s?.memoryUsage == null ? "—" : `${s.memoryUsage}%`}
                      </span>
                    </td>
                    <td className="px-5 py-3 text-right font-mono text-xs text-slate-400">
                      {isOffline || s?.storageUsage == null ? "—" : `${s.storageUsage}%`}
                    </td>
                    <td className="px-5 py-3 text-right font-mono text-xs">
                      <span className={isOffline ? "text-slate-600" : (s?.temperature ?? 0) > 60 ? "text-red-400" : "text-slate-300"}>
                        {isOffline || s?.temperature == null ? "—" : `${s.temperature}°C`}
                      </span>
                    </td>
                    <td className="px-5 py-3 text-right font-mono text-xs text-slate-400">
                      {isOffline || s?.connectionLatencyMs == null ? "—" : `${s.connectionLatencyMs}ms`}
                    </td>
                    <td className="px-5 py-3 text-slate-400 text-xs">
                      {isOffline || s?.uptimeSeconds == null ? "—" : formatUptime(s.uptimeSeconds)}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
