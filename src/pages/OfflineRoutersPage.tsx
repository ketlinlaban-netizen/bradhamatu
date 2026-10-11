import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { WifiOff, Clock, Users, Cpu, ArrowDownToLine, ArrowUpFromLine, RefreshCw } from "lucide-react";
import { routerService } from "../services/RouterService";
import type { Router, RouterStatusInfo, RouterTraffic } from "../types";
import { formatUptime, timeAgo, formatBytes } from "../utils/format";
import { EmptyState } from "../components/ui";

interface OfflineRouterData {
  router: Router;
  lastStatus: RouterStatusInfo | null;
  lastClients: number | null;
  lastTraffic: RouterTraffic | null;
  failed?: boolean;
}

export function OfflineRoutersPage() {
  const [data, setData] = useState<OfflineRouterData[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const loadOfflineRouters = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const routers = await routerService.getRouters();
      const offline = routers.filter((r) => r.status === "offline");
      const results = await Promise.all(offline.map(async (router) => {
        const [statusResult, clientsResult, trafficResult] = await Promise.allSettled([
          routerService.getStatus(router.id),
          routerService.getClients(router.id),
          routerService.getTraffic(router.id),
        ]);
        return {
          router,
          lastStatus: statusResult.status === "fulfilled" ? statusResult.value : null,
          lastClients: clientsResult.status === "fulfilled" ? clientsResult.value.length : null,
          lastTraffic: trafficResult.status === "fulfilled" ? trafficResult.value : null,
          failed: [statusResult, clientsResult, trafficResult].some((result) => result.status === "rejected"),
        };
      }));
      setData(results);
      const failedCount = results.filter((item) => item.failed).length;
      if (failedCount > 0) setError(`Last-known details could not be retrieved for ${failedCount} offline router${failedCount === 1 ? "" : "s"}.`);
    } catch (loadError) {
      setError(loadError instanceof Error ? loadError.message : "Could not load offline router inventory.");
      setData([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadOfflineRouters();
  }, [loadOfflineRouters]);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-100">Offline Routers</h1>
        <div className="mt-1 flex flex-wrap items-center justify-between gap-3">
          <p className="text-sm text-slate-400">{data.length} {data.length === 1 ? "router" : "routers"} offline — showing available last-known information.</p>
          <button type="button" onClick={() => void loadOfflineRouters()} className="btn-secondary flex items-center gap-2 !px-3 !py-1.5 text-xs" disabled={loading}>
            <RefreshCw className={`h-3.5 w-3.5 ${loading ? "animate-spin" : ""}`} />Refresh
          </button>
        </div>
        {loading && <p className="mt-1 text-xs text-slate-500">Loading saved router inventory…</p>}
      </div>
      {error && <div role="alert" className="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">{error}</div>}

      {data.length === 0 ? (
        <div className="card">
          <EmptyState message={loading ? "Loading offline router data…" : "All routers are online. No offline routers to display."} />
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {data.map(({ router, lastStatus, lastClients, lastTraffic }) => (
            <div key={router.id} className="card p-5 border-red-500/20 hover:border-red-500/40 transition-colors">
              {/* Header */}
              <div className="flex items-start justify-between mb-4">
                <div>
                  <Link to={`/admin/routers/${router.id}`} className="text-lg font-bold text-slate-100 hover:text-noc-accent">
                    {router.name}
                  </Link>
                  <div className="text-sm text-slate-400">{router.site}</div>
                  <div className="text-xs font-mono text-slate-500 mt-0.5">{router.ipAddress}</div>
                </div>
                <span className="inline-flex items-center gap-2 px-2.5 py-1 rounded-full text-xs font-medium border bg-red-500/10 border-red-500/20 text-red-400">
                  <span className="status-dot bg-red-500" />
                  OFFLINE
                </span>
              </div>

              {/* Last seen */}
              <div className="flex items-center gap-2 text-sm text-slate-400 mb-4 pb-4 border-b border-noc-border">
                <Clock className="w-4 h-4 text-red-400" />
                <span>Last seen <span className="text-slate-200 font-medium">{timeAgo(router.lastSeenAt)}</span></span>
              </div>

              {/* Last known data */}
              <div className="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-3">Last Known State</div>
              <div className="grid grid-cols-2 gap-3 text-sm">
                <div className="flex items-center gap-2">
                  <Users className="w-4 h-4 text-slate-500" />
                  <span className="text-slate-400">Clients:</span>
                  <span className="text-slate-200 font-medium">{lastClients ?? "—"}</span>
                </div>
                <div className="flex items-center gap-2">
                  <Clock className="w-4 h-4 text-slate-500" />
                  <span className="text-slate-400">Uptime:</span>
                  <span className="text-slate-200 font-medium">{lastStatus?.uptimeSeconds == null ? "—" : formatUptime(lastStatus.uptimeSeconds)}</span>
                </div>
                <div className="flex items-center gap-2">
                  <Cpu className="w-4 h-4 text-slate-500" />
                  <span className="text-slate-400">CPU:</span>
                  <span className="text-slate-200 font-medium">{lastStatus?.cpuUsage ?? "—"}{lastStatus?.cpuUsage == null ? "" : "%"}</span>
                </div>
                <div className="flex items-center gap-2">
                  <ArrowDownToLine className="w-4 h-4 text-slate-500" />
                  <span className="text-slate-400">RX:</span>
                  <span className="text-slate-200 font-medium">{lastTraffic ? `${lastTraffic.currentRxMbps} Mbps` : "—"}</span>
                </div>
                <div className="flex items-center gap-2">
                  <ArrowUpFromLine className="w-4 h-4 text-slate-500" />
                  <span className="text-slate-400">TX:</span>
                  <span className="text-slate-200 font-medium">{lastTraffic ? `${lastTraffic.currentTxMbps} Mbps` : "—"}</span>
                </div>
                <div className="flex items-center gap-2">
                  <WifiOff className="w-4 h-4 text-slate-500" />
                  <span className="text-slate-400">Traffic:</span>
                  <span className="text-slate-200 font-medium">{lastTraffic ? formatBytes(lastTraffic.totalRxBytes + lastTraffic.totalTxBytes) : "—"}</span>
                </div>
              </div>

              <div className="mt-4 pt-4 border-t border-noc-border">
                <div className="text-xs text-slate-500">
                  <span className="text-amber-400 font-medium">⚠ This is last known data.</span> The router is currently offline and not responding.
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
