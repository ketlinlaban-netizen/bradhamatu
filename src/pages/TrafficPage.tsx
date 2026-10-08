import { useEffect, useState, useMemo } from "react";
import { Link } from "react-router-dom";
import { ArrowDownToLine, ArrowUpFromLine, Search } from "lucide-react";
import { AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from "recharts";
import { routerService } from "../services/RouterService";
import type { Router, RouterTraffic } from "../types";
import { formatBytes } from "../utils/format";
import { LoadingSpinner } from "../components/ui";

export function TrafficPage() {
  const [routers, setRouters] = useState<Router[]>([]);
  const [trafficMap, setTrafficMap] = useState<Record<string, RouterTraffic>>({});
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");

  useEffect(() => {
    routerService.getRouters().then(async (all) => {
      setRouters(all);
      const map: Record<string, RouterTraffic> = {};
      for (const r of all) {
        const t = await routerService.getTraffic(r.id);
        if (t) map[r.id] = t;
      }
      setTrafficMap(map);
      setLoading(false);
    });
  }, []);

  const onlineRouters = useMemo(() => {
    let result = routers.filter((r) => r.status !== "offline");
    if (search.trim()) {
      const q = search.toLowerCase();
      result = result.filter((r) => r.name.toLowerCase().includes(q) || r.ipAddress.includes(q) || r.location.toLowerCase().includes(q));
    }
    return result;
  }, [routers, search]);

  if (loading) return <LoadingSpinner label="Loading traffic data..." />;

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
      </div>

      {/* Summary */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div className="stat-card">
          <div className="flex items-center gap-2 mb-2">
            <ArrowDownToLine className="w-4 h-4 text-green-400" />
            <span className="text-xs text-slate-400">Total RX</span>
          </div>
          <div className="text-xl font-bold text-slate-100">{formatBytes(totalRx)}</div>
        </div>
        <div className="stat-card">
          <div className="flex items-center gap-2 mb-2">
            <ArrowUpFromLine className="w-4 h-4 text-cyan-400" />
            <span className="text-xs text-slate-400">Total TX</span>
          </div>
          <div className="text-xl font-bold text-slate-100">{formatBytes(totalTx)}</div>
        </div>
        <div className="stat-card">
          <div className="flex items-center gap-2 mb-2">
            <ArrowDownToLine className="w-4 h-4 text-green-400" />
            <span className="text-xs text-slate-400">Current RX</span>
          </div>
          <div className="text-xl font-bold text-slate-100">{currentRx} <span className="text-sm text-slate-400">Mbps</span></div>
        </div>
        <div className="stat-card">
          <div className="flex items-center gap-2 mb-2">
            <ArrowUpFromLine className="w-4 h-4 text-cyan-400" />
            <span className="text-xs text-slate-400">Current TX</span>
          </div>
          <div className="text-xl font-bold text-slate-100">{currentTx} <span className="text-sm text-slate-400">Mbps</span></div>
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
              {onlineRouters.map((router) => {
                const t = trafficMap[router.id];
                if (!t) return null;
                return (
                  <tr key={router.id} className="border-b border-noc-border/50 last:border-0 hover:bg-noc-hover/50">
                    <td className="px-5 py-3">
                      <Link to={`/routers/${router.id}`} className="font-medium text-slate-100 hover:text-noc-accent">{router.name}</Link>
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
