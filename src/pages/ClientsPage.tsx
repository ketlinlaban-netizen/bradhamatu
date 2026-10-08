import { useEffect, useState, useMemo } from "react";
import { Link } from "react-router-dom";
import { Search } from "lucide-react";
import { routerService } from "../services/RouterService";
import type { Router, RouterClient } from "../types";
import { formatBytes, timeAgo } from "../utils/format";
import { LoadingSpinner, EmptyState } from "../components/ui";

interface ClientWithRouter extends RouterClient {
  routerName: string;
  routerStatus: string;
}

export function ClientsPage() {
  const [clients, setClients] = useState<ClientWithRouter[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");

  useEffect(() => {
    routerService.getRouters().then(async (routers: Router[]) => {
      const all: ClientWithRouter[] = [];
      for (const router of routers) {
        if (router.status === "offline") continue;
        const rClients = await routerService.getClients(router.id);
        for (const c of rClients) {
          all.push({ ...c, routerName: router.name, routerStatus: router.status });
        }
      }
      setClients(all);
      setLoading(false);
    });
  }, []);

  const filtered = useMemo(() => {
    if (!search.trim()) return clients;
    const q = search.toLowerCase();
    return clients.filter(
      (c) =>
        c.hostname.toLowerCase().includes(q) ||
        c.ipAddress.includes(q) ||
        c.macAddress.toLowerCase().includes(q) ||
        c.routerName.toLowerCase().includes(q) ||
        c.interfaceName.toLowerCase().includes(q)
    );
  }, [clients, search]);

  if (loading) return <LoadingSpinner label="Loading all clients..." />;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-100">Connected Clients</h1>
        <p className="text-sm text-slate-400 mt-1">{filtered.length} clients across all online routers</p>
      </div>

      <div className="relative max-w-sm">
        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" />
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Search by client, IP, MAC, router..."
          className="input-field pl-9"
        />
      </div>

      {filtered.length === 0 ? (
        <div className="card"><EmptyState message="No clients found." /></div>
      ) : (
        <div className="card overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-xs text-slate-500 uppercase tracking-wider border-b border-noc-border">
                  <th className="px-5 py-3 font-medium">Client</th>
                  <th className="px-5 py-3 font-medium">IP</th>
                  <th className="px-5 py-3 font-medium">MAC</th>
                  <th className="px-5 py-3 font-medium">Router</th>
                  <th className="px-5 py-3 font-medium">Interface</th>
                  <th className="px-5 py-3 font-medium text-right">Signal</th>
                  <th className="px-5 py-3 font-medium text-right">RX</th>
                  <th className="px-5 py-3 font-medium text-right">TX</th>
                  <th className="px-5 py-3 font-medium">Last Seen</th>
                </tr>
              </thead>
              <tbody>
                {filtered.slice(0, 200).map((client) => (
                  <tr key={client.id} className="border-b border-noc-border/50 last:border-0 hover:bg-noc-hover/50">
                    <td className="px-5 py-3 font-medium text-slate-200">{client.hostname}</td>
                    <td className="px-5 py-3 font-mono text-xs text-slate-400">{client.ipAddress}</td>
                    <td className="px-5 py-3 font-mono text-xs text-slate-500">{client.macAddress}</td>
                    <td className="px-5 py-3">
                      <Link to={`/routers/${client.routerId}`} className="text-noc-accent hover:text-blue-400 text-xs">
                        {client.routerName}
                      </Link>
                    </td>
                    <td className="px-5 py-3 text-slate-400 text-xs">{client.interfaceName}</td>
                    <td className="px-5 py-3 text-right font-mono text-xs">
                      <span className={client.signal > -60 ? "text-green-400" : client.signal > -75 ? "text-amber-400" : "text-red-400"}>
                        {client.signal} dBm
                      </span>
                    </td>
                    <td className="px-5 py-3 text-right text-slate-400 font-mono text-xs">{formatBytes(client.rxBytes)}</td>
                    <td className="px-5 py-3 text-right text-slate-400 font-mono text-xs">{formatBytes(client.txBytes)}</td>
                    <td className="px-5 py-3 text-slate-500 text-xs">{timeAgo(client.lastSeenAt)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {filtered.length > 200 && (
            <div className="px-5 py-3 border-t border-noc-border text-xs text-slate-500 text-center">
              Showing first 200 of {filtered.length} clients. Use search to narrow results.
            </div>
          )}
        </div>
      )}
    </div>
  );
}
