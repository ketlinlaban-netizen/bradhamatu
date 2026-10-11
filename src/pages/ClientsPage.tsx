import { useCallback, useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { RefreshCw, Search, Wifi, WifiOff } from "lucide-react";
import { routerService } from "../services/RouterService";
import type { RouterClient } from "../types";
import { formatBytes, timeAgo } from "../utils/format";
import { EmptyState } from "../components/ui";
import { ClientAliasControl } from "../components/ClientAliasControl";

type ClientStatusFilter = "all" | "online" | "offline" | "unknown";

function clientStatus(client: RouterClient): NonNullable<RouterClient["status"]> {
  const raw = client.status;

  if (raw === "online" || raw === "offline" || raw === "unknown") {
    return raw;
  }

  if (client.offlineReason) {
    return "offline";
  }

  return "unknown";
}

export function ClientsPage() {
  const [clients, setClients] = useState<RouterClient[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState<ClientStatusFilter>("all");
  const [error, setError] = useState<string | null>(null);

  const loadClients = useCallback(async (initial = false) => {
    try {
      if (initial) {
        setClients(await routerService.getAllClients());
        setLoading(false);
      } else {
        setRefreshing(true);
      }
      setClients(await routerService.refreshAllClients());
      setError(null);
    } catch (loadError) {
      setError(loadError instanceof Error ? loadError.message : "Could not load the client inventory.");
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => {
    void loadClients(true);
    const timer = window.setInterval(() => void loadClients(), 60_000);
    return () => window.clearInterval(timer);
  }, [loadClients]);

  const counts = useMemo(() => ({
    all: clients.length,
    online: clients.filter((client) => clientStatus(client) === "online").length,
    offline: clients.filter((client) => clientStatus(client) === "offline").length,
    unknown: clients.filter((client) => clientStatus(client) === "unknown").length,
  }), [clients]);

  const filtered = useMemo(() => {
    const query = search.trim().toLowerCase();
    return clients.filter((client) => {
      const matchesStatus = statusFilter === "all" || clientStatus(client) === statusFilter;
      const matchesSearch = !query || [
        client.alias ?? "",
        client.displayName ?? "",
        client.hostname,
        client.ipAddress,
        client.macAddress,
        client.routerName ?? "",
        client.interfaceName,
        client.offlineReason ?? "",
      ].some((value) => value.toLowerCase().includes(query));
      return matchesStatus && matchesSearch;
    });
  }, [clients, search, statusFilter]);

  const updateClient = (updated: RouterClient) => {
    setClients((current) => current.map((client) => client.id === updated.id ? updated : client));
  };

  const filters: { id: ClientStatusFilter; label: string }[] = [
    { id: "all", label: "All clients" },
    { id: "online", label: "Online" },
    { id: "offline", label: "Offline" },
    { id: "unknown", label: "Unknown" },
  ];

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-100">Client Inventory</h1>
          <p className="text-sm text-slate-400 mt-1">
            Clients are retained after disconnects. Status refreshes every minute and when you refresh manually.
          </p>
        </div>
        <button
          type="button"
          onClick={() => void loadClients()}
          disabled={refreshing}
          className="btn-secondary flex items-center gap-2"
        >
          <RefreshCw className={`h-4 w-4 ${refreshing ? "animate-spin" : ""}`} />
          Refresh clients
        </button>
      </div>

      {error && (
        <div role="alert" className="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
          Client inventory refresh failed: {error}. Previously saved clients are still shown.
        </div>
      )}
      {loading && <div className="text-xs text-slate-500">Loading saved client inventory…</div>}

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        {filters.map((filter) => (
          <button
            key={filter.id}
            type="button"
            onClick={() => setStatusFilter(filter.id)}
            className={`card p-4 text-left transition-colors ${statusFilter === filter.id ? "border-cyan-500/50" : "hover:border-slate-600"}`}
          >
            <div className="text-xs uppercase tracking-wider text-slate-500">{filter.label}</div>
            <div className="mt-1 text-2xl font-semibold text-slate-100">{counts[filter.id]}</div>
          </button>
        ))}
      </div>

      <div className="relative max-w-lg">
        <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-500" />
        <input
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          placeholder="Search name, IP, MAC, router, interface, or offline reason..."
          className="input-field pl-9"
        />
      </div>

      {filtered.length === 0 ? (
        <div className="card">
          <EmptyState message={clients.length === 0 ? "No clients have been discovered yet. Refresh after a router is available." : "No clients match this search and status filter."} />
        </div>
      ) : (
        <div className="card overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-noc-border text-left text-xs uppercase tracking-wider text-slate-500">
                  <th className="px-5 py-3 font-medium">Status</th>
                  <th className="px-5 py-3 font-medium">Client</th>
                  <th className="px-5 py-3 font-medium">IP</th>
                  <th className="px-5 py-3 font-medium">MAC</th>
                  <th className="px-5 py-3 font-medium">Router</th>
                  <th className="px-5 py-3 font-medium">Interface</th>
                  <th className="px-5 py-3 font-medium text-right">Signal</th>
                  <th className="px-5 py-3 font-medium text-right">RX / TX</th>
                  <th className="px-5 py-3 font-medium">Last seen / reason</th>
                  <th className="px-5 py-3 font-medium">Device name</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((client) => {
                  const status = clientStatus(client);
                  return (
                    <tr key={client.id} className="border-b border-noc-border/50 last:border-0 hover:bg-noc-hover/50">
                      <td className="px-5 py-3">
                        <span className={`inline-flex items-center gap-1.5 text-xs font-medium ${
                          status === "online" ? "text-green-400" : status === "offline" ? "text-red-400" : "text-amber-400"
                        }`}>
                          {status === "online" ? <Wifi className="h-3.5 w-3.5" /> : <WifiOff className="h-3.5 w-3.5" />}
                          {status}
                        </span>
                      </td>
                      <td className="px-5 py-3 font-medium text-slate-200">{client.displayName || client.alias || client.hostname || "Unknown Device"}</td>
                      <td className="px-5 py-3 font-mono text-xs text-slate-400">{client.ipAddress || "—"}</td>
                      <td className="px-5 py-3 font-mono text-xs text-slate-500">{client.macAddress}</td>
                      <td className="px-5 py-3">
                        <Link to={`/admin/routers/${client.routerId}`} className="text-xs text-noc-accent hover:text-blue-400">
                          {client.routerName || "Router"}
                        </Link>
                      </td>
                      <td className="px-5 py-3 text-xs text-slate-400">{client.interfaceName || "—"}</td>
                      <td className="px-5 py-3 text-right font-mono text-xs text-slate-400">
                        {client.signal ? `${client.signal} dBm` : "—"}
                      </td>
                      <td className="whitespace-nowrap px-5 py-3 text-right font-mono text-xs text-slate-400">
                        {formatBytes(client.rxBytes)} / {formatBytes(client.txBytes)}
                      </td>
                      <td className="px-5 py-3 text-xs text-slate-400">
                        <div>{timeAgo(client.lastSeenAt)}</div>
                        {client.offlineReason && (
                          <div className="mt-1 max-w-sm text-amber-300" title={client.offlineReason}>
                            {client.offlineReason}
                          </div>
                        )}
                      </td>
                      <td className="px-5 py-3">
                        <ClientAliasControl client={client} onSaved={updateClient} />
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          <div className="border-t border-noc-border px-5 py-3 text-center text-xs text-slate-500">
            Showing {filtered.length} of {clients.length} saved clients.
          </div>
        </div>
      )}
    </div>
  );
}
