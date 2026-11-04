import { useEffect, useState, useMemo } from "react";
import { Link } from "react-router-dom";
import { ArrowDownToLine, ArrowUpFromLine, Search } from "lucide-react";
import { AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from "recharts";
import { routerService } from "../services/RouterService";
import type { Router, RouterTraffic } from "../types";
import { formatBytes } from "../utils/format";

const TRAFFIC_REFRESH_INTERVAL_MS = 10_000;

export function TrafficPage() {
  const [routers, setRouters] = useState<Router[]>([]);
  const [trafficMap, setTrafficMap] = useState<Record<string, RouterTraffic>>({});
  const [loading, setLoading] = useState(true);
  const [refreshError, setRefreshError] = useState<string | null>(null);
  const [lastUpdated, setLastUpdated] = useState<Date | null>(null);
  const [search, setSearch] = useState("");

  useEffect(() => {
    let cancelled = false;
    let inFlight = false;

    const refresh = async () => {
      if (inFlight) return;
      inFlight = true;

      try {
        const all = await routerService.getRouters();
        if (cancelled) return;

        setRouters(all);
        setLoading(false);

        const online = all.filter((router) => router.status !== "offline");
        const results = await Promise.all(online.map(async (router) => ({
          routerId: router.id,
          traffic: await routerService.getTraffic(router.id),
        })));
        if (cancelled) return;

        const successful = results.filter(
          (result): result is { routerId: string; traffic: RouterTraffic } => result.traffic !== null,
        );
        const failedIds = new Set(results.filter((result) => result.traffic === null).map((result) => result.routerId));
        const updatedAt = new Date();

        setTrafficMap((previous) => {
          const onlineIds = new Set(online.map((router) => router.id));
          const next = Object.fromEntries(Object.entries(previous).filter(([routerId]) => onlineIds.has(routerId)));

          for (const { routerId, traffic } of successful) {
            const history = previous[routerId]?.history ?? [];
            const nextHistory = traffic.currentRxMbps === null || traffic.currentTxMbps === null
              ? history
              : [...history, {
                time: updatedAt.toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit", second: "2-digit" }),
                rxMbps: traffic.currentRxMbps,
                txMbps: traffic.currentTxMbps,
              }].slice(-60);
            next[routerId] = {
              ...traffic,
              stale: false,
              history: nextHistory,
            };
          }

          for (const routerId of failedIds) {
            if (next[routerId]) next[routerId] = { ...next[routerId], stale: true };
          }

          return next;
        });

        if (successful.length > 0) setLastUpdated(updatedAt);
        setRefreshError(
          failedIds.size > 0
            ? `Live traffic could not be refreshed for ${failedIds.size} online router${failedIds.size === 1 ? "" : "s"}. Showing the last successful sample where available.`
            : online.length > 0 && successful.length === 0
              ? "Live traffic is unavailable from the online routers."
              : null,
        );
      } catch {
        if (!cancelled) {
          setLoading(false);
          setRefreshError("Router status could not be refreshed. Traffic may be stale.");
          setTrafficMap((previous) => Object.fromEntries(
            Object.entries(previous).map(([routerId, traffic]) => [routerId, { ...traffic, stale: true }]),
          ));
        }
      } finally {
        inFlight = false;
      }
    };

    void refresh();
    const interval = window.setInterval(() => void refresh(), TRAFFIC_REFRESH_INTERVAL_MS);

    return () => {
      cancelled = true;
      window.clearInterval(interval);
    };
  }, []);

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
  const rxRates = Object.values(trafficMap).flatMap((traffic) => traffic.currentRxMbps === null ? [] : [traffic.currentRxMbps]);
  const txRates = Object.values(trafficMap).flatMap((traffic) => traffic.currentTxMbps === null ? [] : [traffic.currentTxMbps]);
  const currentRx = rxRates.reduce((sum, rate) => sum + rate, 0);
  const currentTx = txRates.reduce((sum, rate) => sum + rate, 0);
  const hasTrafficData = Object.keys(trafficMap).length > 0;
  const hasStaleTraffic = Object.values(trafficMap).some((traffic) => traffic.stale);

  const historyByTime = new Map<string, { time: string; rxMbps: number; txMbps: number }>();
  for (const traffic of Object.values(trafficMap)) {
    for (const point of traffic.history) {
      const aggregate = historyByTime.get(point.time) ?? { time: point.time, rxMbps: 0, txMbps: 0 };
      aggregate.rxMbps += point.rxMbps;
      aggregate.txMbps += point.txMbps;
      historyByTime.set(point.time, aggregate);
    }
  }
  const aggregateHistory = [...historyByTime.values()];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-100">Traffic Monitoring</h1>
        <p className="text-sm text-slate-400 mt-1">Network-wide traffic overview across all online routers.</p>
        {loading && <p className="mt-1 text-xs text-slate-500">Loading saved router inventory…</p>}
        {!loading && (
          <p role="status" className={`mt-1 text-xs ${refreshError || hasStaleTraffic ? "text-amber-400" : "text-slate-500"}`}>
            {refreshError
              ? refreshError
              : lastUpdated
                ? `${hasStaleTraffic ? "Stale / partial" : "Live"} · Last successful sample ${lastUpdated.toLocaleTimeString()} · refreshes every 10 seconds`
                : "Waiting for the first live traffic sample…"}
          </p>
        )}
      </div>

      {/* Summary */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div className="stat-card">
          <div className="flex items-center gap-2 mb-2">
            <ArrowDownToLine className="w-4 h-4 text-green-400" />
            <span className="text-xs text-slate-400">Total RX</span>
          </div>
          <div className="text-xl font-bold text-slate-100">{hasTrafficData ? formatBytes(totalRx) : "—"}</div>
        </div>
        <div className="stat-card">
          <div className="flex items-center gap-2 mb-2">
            <ArrowUpFromLine className="w-4 h-4 text-cyan-400" />
            <span className="text-xs text-slate-400">Total TX</span>
          </div>
          <div className="text-xl font-bold text-slate-100">{hasTrafficData ? formatBytes(totalTx) : "—"}</div>
        </div>
        <div className="stat-card">
          <div className="flex items-center gap-2 mb-2">
            <ArrowDownToLine className="w-4 h-4 text-green-400" />
            <span className="text-xs text-slate-400">Current RX</span>
          </div>
          <div className="text-xl font-bold text-slate-100">{rxRates.length > 0 ? currentRx.toFixed(2) : "Waiting for next report"} {rxRates.length > 0 && <span className="text-sm text-slate-400">Mbps</span>}</div>
        </div>
        <div className="stat-card">
          <div className="flex items-center gap-2 mb-2">
            <ArrowUpFromLine className="w-4 h-4 text-cyan-400" />
            <span className="text-xs text-slate-400">Current TX</span>
          </div>
          <div className="text-xl font-bold text-slate-100">{txRates.length > 0 ? currentTx.toFixed(2) : "Waiting for next report"} {txRates.length > 0 && <span className="text-sm text-slate-400">Mbps</span>}</div>
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
        <div className="table-scroll">
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
                return (
                  <tr key={router.id} className="border-b border-noc-border/50 last:border-0 hover:bg-noc-hover/50">
                    <td className="px-5 py-3">
                      <Link to={`/routers/${router.id}`} className="font-medium text-slate-100 hover:text-noc-accent">{router.name}</Link>
                      {t?.stale && <span className="ml-2 text-[10px] uppercase text-amber-400">Stale</span>}
                      {!t && <span className="ml-2 text-[10px] text-slate-500">No live sample</span>}
                    </td>
                    <td className="px-5 py-3 text-slate-400">{router.location}</td>
                    <td className="px-5 py-3 text-right text-green-400 font-mono text-xs">{t?.currentRxMbps == null ? "Waiting…" : `${t.currentRxMbps} Mbps`}</td>
                    <td className="px-5 py-3 text-right text-cyan-400 font-mono text-xs">{t?.currentTxMbps == null ? "Waiting…" : `${t.currentTxMbps} Mbps`}</td>
                    <td className="px-5 py-3 text-right text-slate-400 font-mono text-xs">{t ? formatBytes(t.totalRxBytes) : "—"}</td>
                    <td className="px-5 py-3 text-right text-slate-400 font-mono text-xs">{t ? formatBytes(t.totalTxBytes) : "—"}</td>
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
