import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { WifiOff, Clock, Users, Cpu, ArrowDownToLine, ArrowUpFromLine } from "lucide-react";
import { routerService } from "../services/RouterService";
import type { Router, RouterStatusInfo, RouterTraffic } from "../types";
import { formatUptime, timeAgo, formatBytes } from "../utils/format";
import { LoadingSpinner, EmptyState } from "../components/ui";

interface OfflineRouterData {
  router: Router;
  lastStatus: RouterStatusInfo | null;
  lastClients: number;
  lastTraffic: RouterTraffic | null;
}

export function OfflineRoutersPage() {
  const [data, setData] = useState<OfflineRouterData[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    routerService.getRouters().then(async (routers) => {
      const offline = routers.filter((r) => r.status === "offline");
      const enriched: OfflineRouterData[] = [];
      for (const router of offline) {
        const [status, clients, traffic] = await Promise.all([
          routerService.getStatus(router.id),
          routerService.getClients(router.id),
          routerService.getTraffic(router.id),
        ]);
        enriched.push({
          router,
          lastStatus: status,
          lastClients: clients.length,
          lastTraffic: traffic,
        });
      }
      setData(enriched);
      setLoading(false);
    });
  }, []);

  if (loading) return <LoadingSpinner label="Loading offline routers..." />;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-100">Offline Routers</h1>
        <p className="text-sm text-slate-400 mt-1">
          {data.length} {data.length === 1 ? "router" : "routers"} offline — showing last known information.
        </p>
      </div>

      {data.length === 0 ? (
        <div className="card">
          <EmptyState message="All routers are online. No offline routers to display." />
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {data.map(({ router, lastStatus, lastClients, lastTraffic }) => (
            <div key={router.id} className="card p-5 border-red-500/20 hover:border-red-500/40 transition-colors">
              {/* Header */}
              <div className="flex items-start justify-between mb-4">
                <div>
                  <Link to={`/routers/${router.id}`} className="text-lg font-bold text-slate-100 hover:text-noc-accent">
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
                  <span className="text-slate-200 font-medium">{lastClients}</span>
                </div>
                <div className="flex items-center gap-2">
                  <Clock className="w-4 h-4 text-slate-500" />
                  <span className="text-slate-400">Uptime:</span>
                  <span className="text-slate-200 font-medium">{lastStatus ? formatUptime(lastStatus.uptimeSeconds) : "—"}</span>
                </div>
                <div className="flex items-center gap-2">
                  <Cpu className="w-4 h-4 text-slate-500" />
                  <span className="text-slate-400">CPU:</span>
                  <span className="text-slate-200 font-medium">{lastStatus?.cpuUsage ?? 0}%</span>
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
