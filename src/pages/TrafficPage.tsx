import { useEffect, useState, useMemo, useCallback } from "react";
import { Link } from "react-router-dom";
import { ArrowDownToLine, ArrowUpFromLine, Search, RefreshCw } from "lucide-react";
import { AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from "recharts";
import { routerService } from "../services/RouterService";
import type { Router, RouterTraffic } from "../types";
import { formatBytes } from "../utils/format";

export function TrafficPage() {
  const [routers, setRouters] = useState<Router[]>([]);
  const [trafficMap, setTrafficMap] = useState<Record<string, RouterTraffic>>({});
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const [error, setError] = useState("");

  const loadTraffic = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const all = await routerService.getRouters();
      setRouters(all);
      const results = await Promise.allSettled(
        all.filter((router) => router.status !== "offline").map(async (router) => [
          router.id,
          await routerService.getTraffic(router.id),
        ] as const),
      );
      const nextTraffic: Record<string, RouterTraffic> = {};
      let failedCount = 0;
      for (const result of results) {
        if (result.status === "rejected") {
          failedCount += 1;
        } else if (result.value[1]) {
          nextTraffic[result.value[0]] = result.value[1];
        }
      }
      setTrafficMap(nextTraffic);
      if (failedCount > 0) {
        setError(`Traffic data could not be loaded for ${failedCount} router${failedCount === 1 ? "" : "s"}. Totals include only successful responses.`);
      }
    } catch (loadError) {
      setError(loadError instanceof Error ? loadError.message : "Could not load network traffic.");
      setRouters([]);
      setTrafficMap({});
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadTraffic();
  }, [loadTraffic]);

  const onlineRouters = useMemo(() => {
    let result = routers.filter((r) => r.status !== "offline");
    if (search.trim()) {
      const q = search.toLowerCase();
      result = result.filter((r) => r.name.toLowerCase().includes(q) || r.ipAddress.includes(q) || r.location.toLowerCase().includes(q));
    }
    return result;
  }, [routers, search]);

  const totalRx = Object.values(trafficMap).reduce((sum, t) => sum + t.totalRxBytes, 0);
  const totalTx = Object.values(trafficMap).reduce((sum, t) => sum + t.totalTxBytes, 0);
  const currentRx = Object.values(trafficMap).reduce((sum, t) => sum + t.currentRxMbps, 0);
  const currentTx = Object.values(trafficMap).reduce((sum, t) => sum + t.currentTxMbps, 0);

  // Aggregate traffic history
  const aggregateHistory = onlineRouters.length > 0 && trafficMap[onlineRouters[0]?.id]
    ? trafficMap[onlineRouters[0].id].history.map((point, i) => {
        let rx = 0, tx = 0;
        for (const r of onlineRouters) {
          const t = trafficMap[r.id];
          if (t && t.history[i]) {
            rx += t.history[i].rxMbps;
            tx += t.history[i].txMbps;
          }
        }
        return { time: point.time, rxMbps: rx, txMbps: tx };
      })
    : [];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-100">Traffic Monitoring</h1>
        <p className="text-sm text-slate-400 mt-1">Network-wide traffic overview across all online routers.</p>
        {loading && <p className="mt-1 text-xs text-slate-500">Loading saved router inventory…</p>}
      </div>
      {(error || !loading) && (
        <div className={`flex flex-wrap items-center justify-between gap-3 rounded-lg border px-4 py-3 text-sm ${error ? "border-amber-500/30 bg-amber-500/10 text-amber-200" : "border-noc-border bg-noc-panel text-slate-400"}`}>
          <span>{error || "Traffic totals reflect available router responses only; offline routers are excluded."}</span>
          <button type="button" className="btn-secondary flex items-center gap-2 !px-3 !py-1.5" onClick={() => void loadTraffic()} disabled={loading}>
            <RefreshCw className={`h-3.5 w-3.5 ${loading ? "animate-spin" : ""}`} />Refresh
          </button>
        </div>
      )}

      {/* Summary */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div className="stat-card">
          <div className="flex items-center gap-2 mb-2">
            <ArrowDownToLine className="w-4 h-4 text-green-400" />
            <span className="text-xs text-slate-400">Total RX</span>
          </div>
          <div className="text-xl font-bold text-slate-100">{loading && !Object.keys(trafficMap).length ? "—" : formatBytes(totalRx)}</div>
        </div>
        <div className="stat-card">
          <div className="flex items-center gap-2 mb-2">
            <ArrowUpFromLine className="w-4 h-4 text-cyan-400" />
            <span className="text-xs text-slate-400">Total TX</span>
          </div>
          <div className="text-xl font-bold text-slate-100">{loading && !Object.keys(trafficMap).length ? "—" : formatBytes(totalTx)}</div>
        </div>
        <div className="stat-card">
          <div className="flex items-center gap-2 mb-2">
            <ArrowDownToLine className="w-4 h-4 text-green-400" />
            <span className="text-xs text-slate-400">Current RX</span>
          </div>
          <div className="text-xl font-bold text-slate-100">{loading && !Object.keys(trafficMap).length ? "—" : currentRx} <span className="text-sm text-slate-400">Mbps</span></div>
        </div>
        <div className="stat-card">
          <div className="flex items-center gap-2 mb-2">
            <ArrowUpFromLine className="w-4 h-4 text-cyan-400" />
            <span className="text-xs text-slate-400">Current TX</span>
          </div>
          <div className="text-xl font-bold text-slate-100">{loading && !Object.keys(trafficMap).length ? "—" : currentTx} <span className="text-sm text-slate-400">Mbps</span></div>
        </div>
      </div>

      {/* Aggregate chart */}
      {aggregateHistory.length > 0 && (
        <div className="card p-5">
          <h3 className="text-sm font-semibold text-slate-200 mb-4">Aggregate Network Traffic</h3>
          <ResponsiveContainer width="100%" height={300}>
            <AreaChart data={aggregateHistory}>
              <defs>
                <linearGradient id="aggRx" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stopColor="#22c55e" stopOpacity={0.4} />
                  <stop offset="100%" stopColor="#22c55e" stopOpacity={0} />
                </linearGradient>
                <linearGradient id="aggTx" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stopColor="#06b6d4" stopOpacity={0.4} />
                  <stop offset="100%" stopColor="#06b6d4" stopOpacity={0} />
                </linearGradient>
              </defs>
              <CartesianGrid strokeDasharray="3 3" stroke="#1e293b" />
              <XAxis dataKey="time" stroke="#64748b" fontSize={11} />
              <YAxis stroke="#64748b" fontSize={11} unit=" Mbps" />
              <Tooltip
                contentStyle={{ background: "#151c2e", border: "1px solid #1e293b", borderRadius: "8px", fontSize: "12px" }}
                labelStyle={{ color: "#94a3b8" }}
              />
              <Area type="monotone" dataKey="rxMbps" stroke="#22c55e" strokeWidth={2} fill="url(#aggRx)" name="RX (Mbps)" />
              <Area type="monotone" dataKey="txMbps" stroke="#06b6d4" strokeWidth={2} fill="url(#aggTx)" name="TX (Mbps)" />
            </AreaChart>
          </ResponsiveContainer>
        </div>
      )}

      {/* Per-router traffic */}
      <div className="flex items-center gap-3">
        <div className="relative flex-1 max-w-sm">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" />
          <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Filter routers..." className="input-field pl-9" />
        </div>
      </div>

      <div className="card overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-xs text-slate-500 uppercase tracking-wider border-b border-noc-border">
                <th className="px-5 py-3 font-medium">Router</th>
                <th className="px-5 py-3 font-medium">Location</th>
                <th className="px-5 py-3 font-medium text-right">Current RX</th>
                <th className="px-5 py-3 font-medium text-right">Current TX</th>
                <th className="px-5 py-3 font-medium text-right">Total RX</th>
                <th className="px-5 py-3 font-medium text-right">Total TX</th>
              </tr>
            </thead>
            <tbody>
              {!onlineRouters.some((router) => trafficMap[router.id]) && (
                <tr><td colSpan={6} className="px-5 py-8 text-center text-sm text-slate-500">{loading ? "Loading traffic data…" : error ? "No traffic data is available." : "No online routers with traffic data match this filter."}</td></tr>
              )}
              {onlineRouters.map((router) => {
                const t = trafficMap[router.id];
                if (!t) return null;
                return (
                  <tr key={router.id} className="border-b border-noc-border/50 last:border-0 hover:bg-noc-hover/50">
                    <td className="px-5 py-3">
                      <Link to={`/admin/routers/${router.id}`} className="font-medium text-slate-100 hover:text-noc-accent">{router.name}</Link>
                    </td>
                    <td className="px-5 py-3 text-slate-400">{router.location}</td>
                    <td className="px-5 py-3 text-right text-green-400 font-mono text-xs">{t.currentRxMbps} Mbps</td>
                    <td className="px-5 py-3 text-right text-cyan-400 font-mono text-xs">{t.currentTxMbps} Mbps</td>
                    <td className="px-5 py-3 text-right text-slate-400 font-mono text-xs">{formatBytes(t.totalRxBytes)}</td>
                    <td className="px-5 py-3 text-right text-slate-400 font-mono text-xs">{formatBytes(t.totalTxBytes)}</td>
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
