import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Link } from "react-router-dom";
import { RefreshCw, Search, Wifi, WifiOff } from "lucide-react";
import { routerService } from "../services/RouterService";
import type { RouterClient } from "../types";
import { formatBytes, formatSignal, timeAgo } from "../utils/format";
import { EmptyState } from "../components/ui";
import { ClientAliasControl } from "../components/ClientAliasControl";

type ClientStatusFilter = "all" | "online" | "offline" | "unknown" | "stale";

function clientStatus(client: RouterClient): NonNullable<RouterClient["status"]> {
  if (client.status === "online" || client.status === "offline" || client.status === "unknown") {
    return client.status;
  }

  return client.lastConfirmedStatus ?? (client.offlineReason ? "offline" : "online");
}

function elapsed(iso: string | null | undefined, now: number): string {
  if (!iso) return "never";
  const seconds = Math.max(0, Math.floor((now - new Date(iso).getTime()) / 1000));
  if (seconds < 60) return `${seconds}s`;
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`;
  return `${Math.floor(seconds / 86400)}d ${Math.floor((seconds % 86400) / 3600)}h`;
}

function useSecondClock(): number {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, []);
  return now;
}

export function ClientsPage() {
  const [clients, setClients] = useState<RouterClient[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState<ClientStatusFilter>("all");
  const [error, setError] = useState<string | null>(null);
  const refreshingRef = useRef(false);
  const now = useSecondClock();

  const loadClients = useCallback(async (initial = false) => {
    if (refreshingRef.current) return;
    refreshingRef.current = true;
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
      refreshingRef.current = false;
    }
  }, []);

  useEffect(() => {
    void loadClients(true);
    const timer = window.setInterval(() => void loadClients(), 5_000);
    return () => window.clearInterval(timer);
  }, [loadClients]);

  const counts = useMemo(() => ({
    all: clients.length,
    online: clients.filter((client) => clientStatus(client) === "online").length,
    offline: clients.filter((client) => clientStatus(client) === "offline").length,
    unknown: clients.filter((client) => clientStatus(client) === "unknown").length,
    stale: clients.filter((client) => client.monitoringStale).length,
  }), [clients]);

  const filtered = useMemo(() => {
    const query = search.trim().toLowerCase();
    return clients.filter((client) => {
      const matchesStatus = statusFilter === "all"
        || (statusFilter === "stale" ? client.monitoringStale === true : clientStatus(client) === statusFilter);
      const matchesSearch = !query || [
        client.alias ?? "",
        client.displayName ?? "",
        client.hostname,
        client.ipAddress,
        client.configuredIpAddress ?? "",
        client.routerObservedIpAddress ?? "",
        client.macAddress,
        client.routerName ?? "",
        client.interfaceName,
        client.monitoringIssue ?? "",
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
    { id: "unknown", label: "Unconfirmed" },
    { id: "stale", label: "Monitoring stale" },
  ];
  const staleTelemetryClient = clients.find(
    (client) => client.monitoringStale && client.routerTelemetryEnabled,
  );

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-100">Client Inventory</h1>
          <p className="text-sm text-slate-400 mt-1">
            Wi-Fi and wired bridge clients are confirmed from active router tables. ARP neighbors are also listed, but remain unconfirmed because an ARP cache entry does not prove a device is online. Clients are retained after disconnects.
          </p>
        </div>
        <button
          type="button"
          onClick={() => void loadClients()}
          disabled={refreshing}
          className="btn-secondary flex items-center gap-2"
        >
          <RefreshCw className={`h-4 w-4 ${refreshing ? "animate-spin" : ""}`} />
          Reload latest data
        </button>
      </div>

      {error && (
        <div role="alert" className="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
          Client inventory refresh failed: {error}. Previously saved clients are still shown.
        </div>
      )}
      {counts.stale > 0 && (
        <div role="status" className="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
          Current presence is unconfirmed for {counts.stale} {counts.stale === 1 ? "client" : "clients"}; these are saved observations, not live status.
          {staleTelemetryClient && (
            <span className="block mt-1">
              Router telemetry last arrived {staleTelemetryClient.routerTelemetryReceivedAt
                ? `${timeAgo(staleTelemetryClient.routerTelemetryReceivedAt)} (${new Date(staleTelemetryClient.routerTelemetryReceivedAt).toLocaleString()})`
                : "never received"}.
              “Reload latest data” cannot run or enable the MikroTik Scheduler.
            </span>
          )}
        </div>
      )}
      {loading && <div className="text-xs text-slate-500">Loading saved client inventory…</div>}

      <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
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
          <div className="table-scroll">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-noc-border text-left text-xs uppercase tracking-wider text-slate-500">
                  <th className="px-5 py-3 font-medium">Status</th>
                  <th className="px-5 py-3 font-medium">Client</th>
                  <th className="px-5 py-3 font-medium">Connection</th>
                  <th className="px-5 py-3 font-medium">IP</th>
                  <th className="px-5 py-3 font-medium">MAC</th>
                  <th className="px-5 py-3 font-medium">Router</th>
                  <th className="px-5 py-3 font-medium">Interface</th>
                  <th className="px-5 py-3 font-medium text-right">Signal</th>
                  <th className="px-5 py-3 font-medium text-right">RX / TX</th>
                  <th className="px-5 py-3 font-medium">Last seen</th>
                  <th className="px-5 py-3 font-medium">Device name</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((client) => {
                  const status = clientStatus(client);
                  const details = [
                    client.routerTelemetryEnabled
                      ? `Router report: ${client.routerTelemetryReceivedAt
                        ? `${timeAgo(client.routerTelemetryReceivedAt)} (${new Date(client.routerTelemetryReceivedAt).toLocaleString()})`
                        : "never received"}`
                      : null,
                    client.monitoringIssue,
                    client.offlineReason,
                  ].filter(Boolean).join("\n");
                  const addressNote = [
                    client.applicationConfiguredIpAddress
                      ? `Console-configured (application only): ${client.configuredIpAddress}`
                      : (!client.ipAddress && client.routerObservedIpAddress ? `Last router-observed: ${client.routerObservedIpAddress}` : null),
                    !client.applicationConfiguredIpAddress && client.routerStaticLeaseIpAddress
                      ? `Router static DHCP lease: ${client.routerStaticLeaseIpAddress}`
                      : null,
                    client.addressMismatch && client.routerObservedIpAddress
                      ? `Router currently reports: ${client.routerObservedIpAddress}`
                      : null,
                  ].filter(Boolean).join("\n");
                  return (
                    <tr key={client.id} className="border-b border-noc-border/50 last:border-0 hover:bg-noc-hover/50">
                      <td className="px-4 py-2">
                        <div className="flex items-center gap-2">
                          <span className={`inline-flex items-center gap-1.5 text-xs font-medium ${
                            status === "online" ? "text-green-400" : status === "offline" ? "text-red-400" : "text-slate-400"
                          }`}>
                            {status === "online" ? <Wifi className="h-3.5 w-3.5" /> : status === "offline" ? <WifiOff className="h-3.5 w-3.5" /> : null}
                            {status}
                          </span>
                          {client.monitoringStale && (
                            <span className="text-[10px] font-medium uppercase tracking-wide text-amber-300" title={client.monitoringIssue ?? undefined}>
                              stale
                            </span>
                          )}
                        </div>
                      </td>
                      <td className="px-4 py-2 font-medium text-slate-200">{client.displayName || client.alias || client.hostname || "Unknown Device"}</td>
                      <td className="px-4 py-2 text-xs text-slate-400">
                        {client.connectionType === "arp"
                          ? "Neighbor (ARP)"
                          : client.connectionType === "dhcp"
                            ? "DHCP lease (unconfirmed)"
                            : client.connectionType === "wired"
                              ? "Wired / bridge"
                              : "Wi-Fi"}
                      </td>
                      <td className="px-4 py-2 font-mono text-xs text-slate-400">
                        <span title={addressNote || undefined}>{client.ipAddress || "—"}</span>
                        {addressNote.length > 0 && <span className="ml-1 text-[10px] text-cyan-300">&#9432;</span>}
                      </td>
                      <td className="px-4 py-2 font-mono text-xs text-slate-500">{client.macAddress}</td>
                      <td className="px-4 py-2">
                        <Link to={`/routers/${client.routerId}`} className="text-xs text-noc-accent hover:text-blue-400">
                          {client.routerName || "Router"}
                        </Link>
                      </td>
                      <td className="px-4 py-2 text-xs text-slate-400">{client.interfaceName || "—"}</td>
                      <td className="px-4 py-2 text-right font-mono text-xs text-slate-400">
                        {formatSignal(client.signal)}
                      </td>
                      <td className="whitespace-nowrap px-4 py-2 text-right font-mono text-xs text-slate-400">
                        {formatBytes(client.rxBytes)} / {formatBytes(client.txBytes)}
                      </td>
                      <td className="px-4 py-2">
                        <span
                          title={details || undefined}
                          className={`whitespace-nowrap font-mono text-xs ${details ? "text-amber-300" : "text-slate-400"}`}
                        >
                          {elapsed(client.lastSeenAt, now)}
                        </span>
                      </td>
                      <td className="px-4 py-2">
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
