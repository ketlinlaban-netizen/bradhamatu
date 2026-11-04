import { useEffect, useState } from "react";
import { useParams, Link } from "react-router-dom";
import {
  ArrowLeft,
  Cpu,
  MemoryStick,
  HardDrive,
  Thermometer,
  Clock,
  CircuitBoard,
  Network,
  Wifi,
  Users,
  Activity,
  ArrowDownToLine,
  ArrowUpFromLine,
  ShieldCheck,
  Loader2,
  CheckCircle2,
  Lock,
  Radio,
  Signal,
} from "lucide-react";
import {
  AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
} from "recharts";
import { routerService } from "../services/RouterService";
import type {
  Router,
  RouterStatusInfo,
  RouterInterface,
  RouterClient,
  RouterTraffic,
  WirelessInfo,
  RouterConnection,
  RouterPppoeInfo,
  RouterDhcpLeases,
} from "../types";
import { formatBytes, formatSignal, formatUptime, timeAgo } from "../utils/format";
import { StatusBadge, EmptyState } from "../components/ui";
import { ClientAliasControl } from "../components/ClientAliasControl";
import { apiGet } from "../services/api";

type Tab = "overview" | "interfaces" | "clients" | "leases" | "wireless" | "pppoe" | "traffic";

export function RouterDetailPage() {
  const { routerId } = useParams<{ routerId: string }>();
  const [router, setRouter] = useState<Router | null>(null);
  const [status, setStatus] = useState<RouterStatusInfo | null>(null);
  const [interfaces, setInterfaces] = useState<RouterInterface[]>([]);
  const [clients, setClients] = useState<RouterClient[]>([]);
  const [leases, setLeases] = useState<RouterDhcpLeases | null>(null);
  const [traffic, setTraffic] = useState<RouterTraffic | null>(null);
  const [wireless, setWireless] = useState<WirelessInfo | null>(null);
  const [pppoe, setPppoe] = useState<RouterPppoeInfo | null>(null);
  const [pppoeError, setPppoeError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState<Tab>("overview");
  const [connection, setConnection] = useState<RouterConnection | null>(null);
  const [connecting, setConnecting] = useState(false);

  useEffect(() => {
    if (!routerId) return;
    setLoading(true);
    routerService.getRouter(routerId).then((r) => {
      setRouter(r);
      setLoading(false);
    }).catch(() => {
      setRouter(null);
      setLoading(false);
    });
    Promise.all([
      routerService.getStatus(routerId),
      routerService.getInterfaces(routerId),
      routerService.getClients(routerId),
      routerService.getTraffic(routerId),
      routerService.getWireless(routerId),
    ]).then(([s, i, c, t, w]) => {
      setStatus(s);
      setInterfaces(i);
      setClients(c);
      setTraffic(t);
      setWireless(w);
    });
    apiGet<RouterPppoeInfo>(`/routers/${routerId}/pppoe`)
      .then(setPppoe)
      .catch((error: unknown) => {
        setPppoeError(error instanceof Error ? error.message : "Failed to load PPPoE data.");
      });
    apiGet<RouterDhcpLeases>(`/routers/${routerId}/dhcp-leases`)
      .then(setLeases)
      .catch(() => setLeases(null));
  }, [routerId]);

  const handleConnect = async () => {
    if (!routerId) return;
    setConnecting(true);

    try {
      const conn = await routerService.connect(routerId);
      setConnection(conn);
    } catch (error) {
      setConnection({
        routerId,
        state: "failed",
        message: error instanceof Error ? error.message : "The connection test failed.",
        readOnly: true,
        sessionToken: null,
      });
    } finally {
      setConnecting(false);
    }
  };

  if (!router) {
    return (
      <div className="card">
        {loading ? (
          <div className="p-6 text-sm text-slate-400">Loading router information…</div>
        ) : (
          <>
            <EmptyState message="Router not found." />
            <div className="text-center pb-6">
              <Link to="/routers" className="text-sm text-noc-accent hover:text-blue-400">← Back to routers</Link>
            </div>
          </>
        )}
      </div>
    );
  }

  function PppoeTab({ data, error }: { data: RouterPppoeInfo | null; error: string | null }) {
    if (error) return <EmptyState message={`PPPoE data unavailable: ${error}`} />;
    if (!data) return <EmptyState message="Loading PPPoE account and session data…" />;

    if (data.available === false) {
      return (
        <div className="card p-5 animate-fade-in">
          <div className="flex items-start gap-3">
            <ShieldCheck className="w-5 h-5 text-amber-400 flex-shrink-0 mt-0.5" />
            <div>
              <h2 className="text-sm font-semibold text-amber-300">PPPoE is not available for this router</h2>
              <p className="mt-1 text-sm text-slate-400">
                {data.message || "This router report does not include PPPoE accounts or sessions."}
              </p>
              <p className="mt-2 text-xs text-slate-500">
                Read-only telemetry only shows what this router's report collects. To monitor PPPoE here, either add PPPoE collection to this router's telemetry script or configure the router with an inbound read-only API transport.
              </p>
            </div>
          </div>
        </div>
      );
    }

    return (
      <div className="space-y-6 animate-fade-in">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-sm font-semibold text-slate-200">
            {data.accounts.length} configured account{data.accounts.length === 1 ? "" : "s"} · {data.activeSessions.length} active session{data.activeSessions.length === 1 ? "" : "s"}
          </h2>
          <span className="text-xs text-slate-500">Observed {timeAgo(data.observedAt)} · read-only</span>
        </div>
        <div className="card overflow-hidden">
          <h3 className="border-b border-noc-border px-5 py-3 text-sm font-semibold text-slate-200">Configured PPPoE accounts</h3>
          {data.accounts.length === 0 ? (
            <p className="p-5 text-sm text-slate-400">No PPPoE or all-service accounts were returned.</p>
          ) : (
            <div className="table-scroll">
              <table className="w-full text-sm">
                <thead><tr className="border-b border-noc-border text-left text-xs uppercase text-slate-500">
                  <th className="px-5 py-3">Account</th><th className="px-5 py-3">Profile</th><th className="px-5 py-3">Service</th><th className="px-5 py-3">State</th><th className="px-5 py-3">Remote address</th>
                </tr></thead>
                <tbody>{data.accounts.map((account) => (
                  <tr key={account.name} className="border-b border-noc-border/50 last:border-0">
                    <td className="px-5 py-3 font-mono text-slate-200">{account.name}</td>
                    <td className="px-5 py-3 text-slate-300">{account.profile || "—"}</td>
                    <td className="px-5 py-3 text-slate-400">{account.service}</td>
                    <td className={`px-5 py-3 ${account.disabled ? "text-slate-500" : "text-green-400"}`}>{account.disabled ? "disabled" : "enabled"}</td>
                    <td className="px-5 py-3 font-mono text-slate-400">{account.remoteAddress || "—"}</td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
          )}
        </div>
        <div className="card overflow-hidden">
          <h3 className="border-b border-noc-border px-5 py-3 text-sm font-semibold text-slate-200">Active PPPoE sessions</h3>
          {data.activeSessions.length === 0 ? (
            <p className="p-5 text-sm text-slate-400">No active PPPoE sessions.</p>
          ) : (
            <div className="table-scroll">
              <table className="w-full text-sm">
                <thead><tr className="border-b border-noc-border text-left text-xs uppercase text-slate-500">
                  <th className="px-5 py-3">Account</th><th className="px-5 py-3">Caller ID</th><th className="px-5 py-3">Address</th><th className="px-5 py-3">Uptime</th>
                </tr></thead>
                <tbody>{data.activeSessions.map((session) => (
                  <tr key={session.sessionId ?? `${session.name}-${session.address}`} className="border-b border-noc-border/50 last:border-0">
                    <td className="px-5 py-3 font-mono text-slate-200">{session.name}</td>
                    <td className="px-5 py-3 font-mono text-slate-400">{session.callerId || "—"}</td>
                    <td className="px-5 py-3 font-mono text-slate-300">{session.address || "—"}</td>
                    <td className="px-5 py-3 text-slate-400">{session.uptime || "—"}</td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
          )}
        </div>
        <p className="text-xs text-slate-500">PPP secrets are queried without their password field; credentials are never returned or displayed.</p>
      </div>
    );
  }

  const tabs: Array<{ key: Tab; label: string; icon: typeof Cpu }> = [
    { key: "overview", label: "Overview", icon: Cpu },
    { key: "interfaces", label: "Interfaces", icon: Network },
    { key: "clients", label: `Clients (${clients.filter((client) => resolveClientStatus(client) === "online").length} current / ${clients.length} saved)`, icon: Users },
    { key: "leases", label: `DHCP leases (${leases?.receivedCount ?? leases?.leases.length ?? 0} received)`, icon: Network },
    { key: "wireless", label: "Wireless", icon: Wifi },
    { key: "pppoe", label: "PPPoE", icon: Users },
    { key: "traffic", label: "Traffic", icon: Activity },
  ];

  return (
    <div className="space-y-6">
      {/* Breadcrumb */}
      <Link to="/routers" className="inline-flex items-center gap-2 text-sm text-slate-400 hover:text-slate-200">
        <ArrowLeft className="w-4 h-4" /> Back to Routers
      </Link>

      {/* Router header */}
      <div className="card p-6">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <div className="flex items-center gap-3 mb-2">
              <h1 className="text-2xl font-bold text-slate-100">{router.name}</h1>
              <StatusBadge status={router.status} size="md" />
            </div>
            <div className="text-sm text-slate-400">{router.site}</div>
            <div className="flex flex-wrap gap-x-6 gap-y-1 mt-2 text-sm text-slate-500 font-mono">
              <span>{router.ipAddress}</span>
              <span>· {router.routerosVersion}</span>
              <span>· {router.model}</span>
              <span>· Last seen {timeAgo(router.lastSeenAt)}</span>
            </div>
          </div>

          <div className="flex flex-col items-end gap-3">
            {/* Read-only badge */}
            <div className="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-green-500/5 border border-green-500/20">
              <ShieldCheck className="w-4 h-4 text-green-500" />
              <span className="text-xs font-medium text-green-400">READ-ONLY API</span>
            </div>

            {/* Connect button */}
            {connection?.state === "established" ? (
              <div className="flex items-center gap-2 text-sm text-green-400">
                <CheckCircle2 className="w-4 h-4" />
                <span className="font-medium">Connection test succeeded</span>
                <span className="text-xs text-slate-500">{connection.message}</span>
              </div>
            ) : connection?.state === "telemetry" ? (
              <div role="status" className="max-w-sm text-right text-sm text-green-300">
                {connection.message}
                {connection.telemetryReceivedAt && <span className="block text-xs text-slate-500">Received {timeAgo(connection.telemetryReceivedAt)}</span>}
              </div>
            ) : connection?.state === "waiting" ? (
              <div role="status" className="max-w-sm text-right text-sm text-amber-300">
                {connection.message}
                {connection.telemetryReceivedAt && <span className="block text-xs text-slate-500">Last received {timeAgo(connection.telemetryReceivedAt)}</span>}
              </div>
            ) : connection?.state === "failed" ? (
              <div className="max-w-sm text-right">
                <p role="alert" className="text-sm text-red-300">{connection.message}</p>
                <button onClick={handleConnect} className="mt-2 text-sm text-noc-accent hover:text-blue-400">
                  Retry connection test
                </button>
              </div>
            ) : connecting ? (
              <div className="flex items-center gap-2 text-sm text-slate-300">
                <Loader2 className="w-4 h-4 animate-spin" />
                <span>Testing read-only API access...</span>
              </div>
            ) : (
              <button onClick={handleConnect} className="btn-primary flex items-center gap-2 text-sm">
                <Lock className="w-4 h-4" />
                Connect to Router
              </button>
            )}
          </div>
        </div>
      </div>

      {/* Tabs */}
      <div className="tab-scroll border-b border-noc-border">
        <div className="flex w-max min-w-full gap-1">
          {tabs.map((tab) => {
            const Icon = tab.icon;
            return (
              <button
                key={tab.key}
                onClick={() => setActiveTab(tab.key)}
                className={`flex min-h-11 items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 transition-colors -mb-px ${
                  activeTab === tab.key
                    ? "border-noc-accent text-noc-accent"
                    : "border-transparent text-slate-400 hover:text-slate-200"
                }`}
              >
                <Icon className="w-4 h-4" />
                {tab.label}
              </button>
            );
          })}
        </div>
      </div>

      {/* Tab content */}
      {activeTab === "overview" && (
        <OverviewTab router={router} status={status} />
      )}
      {activeTab === "interfaces" && (
        <InterfacesTab interfaces={interfaces} status={status} />
      )}
      {activeTab === "clients" && (
        <ClientsTab
          clients={clients}
          routerStatus={router.status}
          onClientUpdated={(updated) => setClients((current) =>
            current.map((client) => client.id === updated.id ? updated : client)
          )}
        />
      )}
      {activeTab === "leases" && (
        <DhcpLeasesTab data={leases} />
      )}
      {activeTab === "wireless" && (
        <WirelessTab wireless={wireless} routerStatus={router.status} />
      )}
      {activeTab === "pppoe" && (
        <PppoeTab data={pppoe} error={pppoeError} />
      )}
      {activeTab === "traffic" && (
        <TrafficTab traffic={traffic} />
      )}
    </div>
  );
}

function MetricCard({ icon: Icon, label, value, unit, color }: {
  icon: typeof Cpu; label: string; value: string | number | null; unit?: string; color: string;
}) {
  return (
    <div className="card p-5">
      <div className="flex items-center gap-2 mb-2">
        <Icon className={`w-4 h-4 ${color}`} />
        <span className="text-xs text-slate-400">{label}</span>
      </div>
      <div className="text-xl font-bold text-slate-100">
        {value}
        {unit && <span className="text-sm text-slate-400 ml-1">{unit}</span>}
      </div>
    </div>
  );
}

function OverviewTab({ router, status }: { router: Router; status: RouterStatusInfo | null }) {
  if (!status) return <EmptyState message="No status data available." />;
  const isOffline = router.status === "offline";

  return (
    <div className="space-y-6 animate-fade-in">
      {/* Resource metrics */}
      <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4">
        <MetricCard icon={Cpu} label="CPU" value={status.cpuUsage} unit="%" color="text-blue-400" />
        <MetricCard icon={MemoryStick} label="Memory" value={status.memoryUsage} unit="%" color="text-cyan-400" />
        <MetricCard icon={HardDrive} label="Storage" value={status.storageUsage} unit="%" color="text-purple-400" />
        <MetricCard icon={Thermometer} label="Temperature" value={status.temperature} unit="°C" color="text-amber-400" />
        <MetricCard icon={Clock} label="Uptime" value={status.uptimeSeconds === null ? "—" : formatUptime(status.uptimeSeconds)} color="text-emerald-400" />
        <MetricCard icon={Activity} label="Latency" value={status.connectionLatencyMs} unit="ms" color="text-green-400" />
      </div>

      <div className="card p-5">
        <div className="mb-4 flex flex-wrap items-baseline justify-between gap-2">
          <h3 className="text-sm font-semibold text-slate-200">Default routes and gateways</h3>
          <span className="text-xs text-slate-500">
            {status.gatewayObservedAt ? `Observed ${timeAgo(status.gatewayObservedAt)}` : "Observation time unavailable"}
          </span>
        </div>
        {status.dataSource === "stale" && (
          <p role="status" className="mb-3 rounded border border-amber-500/20 bg-amber-500/5 px-3 py-2 text-xs text-amber-300">
            {status.telemetryStatus
              ? status.telemetryStatus === "unknown"
                ? `Router status is unknown; no telemetry report has been received yet. ${status.staleReason || ""}`
                : `No recent telemetry report; reachability is unconfirmed. Last report received ${status.telemetryReceivedAt ? timeAgo(status.telemetryReceivedAt) : "never"}. ${status.staleReason || ""}`
              : `Showing the last saved observation because the router could not be polled: ${status.staleReason || "poll failed"}.`}
          </p>
        )}
        {status.routeCollectionSucceeded !== true ? (
          <p className="text-sm text-amber-300">
            Default route is unknown; route collection {status.routeCollectionSucceeded === false ? "failed" : "was not reported"}.
          </p>
        ) : status.routeCollectionComplete !== true ? (
          <p className="text-sm text-amber-300">
            Route collection is incomplete ({status.routeReceivedCount ?? 0} received); saved route observations are shown where available.
          </p>
        ) : status.gatewayRoutes.length === 0 ? (
          <p className="text-sm text-slate-400">No default routes were present in the complete router report.</p>
        ) : (
          <div className="space-y-2">
            {status.gatewayRoutes.map((route, index) => (
              <div key={route.routeId ?? `${route.routingTable}-${route.gateway ?? "unknown"}-${index}`} className="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-md border border-noc-border/60 px-3 py-2 text-xs">
                <span className={route.active && !route.disabled ? "font-medium text-green-400" : "font-medium text-slate-500"}>
                  {route.disabled ? "disabled" : route.active ? "active" : "inactive"}
                </span>
                <span className="font-mono text-slate-200">{route.immediateGateway || route.gateway || "gateway unknown"}</span>
                {route.destination && <span className="font-mono text-slate-400">{route.destination}</span>}
                <span className="text-slate-400">{route.interface || "interface unknown"}</span>
                <span className="text-slate-400">distance {route.distance ?? "unknown"}</span>
                <span className="text-slate-500">{route.routingTable}</span>
                {route.ecmp && <span className="text-cyan-300">ECMP</span>}
                {route.dynamic && <span className="text-slate-500">dynamic</span>}
                {route.static && <span className="text-slate-500">static</span>}
              </div>
            ))}
          </div>
        )}
        <p className="mt-3 text-xs text-slate-500">
          Observed route gateways are router state, not this router’s saved management address.
        </p>
      </div>

      {/* Usage bars */}
      {!isOffline && (
        <div className="card p-5 space-y-4">
          <h3 className="text-sm font-semibold text-slate-200">Resource Usage</h3>
          {status.cpuUsage !== null && <UsageBar label="CPU" value={status.cpuUsage} color="bg-blue-500" />}
          {status.memoryUsage !== null && <UsageBar label="Memory" value={status.memoryUsage} color="bg-cyan-500" />}
          {status.storageUsage !== null && <UsageBar label="Storage" value={status.storageUsage} color="bg-purple-500" />}
        </div>
      )}

      {/* System information */}
      <div className="card p-5">
        <h3 className="text-sm font-semibold text-slate-200 mb-4">System Information</h3>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-3 text-sm">
          <InfoRow icon={CircuitBoard} label="Board Model" value={status.boardModel} />
          <InfoRow icon={Cpu} label="Architecture" value={status.architecture} />
          <InfoRow icon={Activity} label="RouterOS Version" value={status.routerosVersion} />
          <InfoRow icon={CircuitBoard} label="Serial Number" value={status.serialNumber} />
          <InfoRow icon={Network} label="System Identity" value={status.systemIdentity} />
          <InfoRow icon={Clock} label="Last Communication" value={status.lastCommunication ? timeAgo(status.lastCommunication) : "never"} />
          {status.telemetryObservedAt && <InfoRow icon={Clock} label="Router report time" value={timeAgo(status.telemetryObservedAt)} />}
          {status.telemetryReceivedAt && <InfoRow icon={Clock} label="Server received" value={timeAgo(status.telemetryReceivedAt)} />}
          <InfoRow icon={Network} label="Bridge MAC Address" value={status.routerMacAddress || "—"} />
          <InfoRow icon={Network} label="IP Addresses" value={status.ipAddresses.join(", ") || "—"} />
          <InfoRow
            icon={Network}
            label="Interface collection"
            value={status.interfaceCollectionSucceeded === true
              ? `${status.interfaceCollectionComplete === true ? "complete" : "incomplete"} · ${status.interfaceReceivedCount ?? 0} received`
              : status.interfaceCollectionSucceeded === false ? "failed" : "not reported"}
          />
          <InfoRow
            icon={Clock}
            label="Interface observation"
            value={status.interfaceObservedAt
              ? `${timeAgo(status.interfaceObservedAt)} · ${status.interfaceFresh ? "fresh" : "stale"}`
              : "unavailable"}
          />
          <InfoRow
            icon={Network}
            label="Wireless registrations"
            value={status.wirelessCollectionSucceeded === true
              ? `${status.wirelessCollectionComplete === true ? "complete" : "incomplete"} · ${status.wirelessReceivedCount ?? 0} received${status.wirelessExpectedCount == null ? "" : ` of ${status.wirelessExpectedCount}`}`
              : status.wirelessCollectionSucceeded === false ? "failed" : "not reported"}
          />
          <InfoRow
            icon={Clock}
            label="Wireless observation"
            value={status.wirelessObservedAt
              ? `${timeAgo(status.wirelessObservedAt)} · ${status.wirelessFresh ? "fresh" : "stale"}`
              : "unavailable"}
          />
          <InfoRow
            icon={Clock}
            label="IP address observation"
            value={status.ipAddressObservedAt ? timeAgo(status.ipAddressObservedAt) : "unavailable"}
          />
          <InfoRow
            icon={Network}
            label="IP address collection"
            value={status.ipAddressCollectionSucceeded === true
              ? status.ipAddressCollectionComplete === true ? "complete" : "incomplete"
              : status.ipAddressCollectionSucceeded === false ? "failed" : "not reported"}
          />
        </div>
      </div>

      {isOffline && (
        <div className="card p-5 border-red-500/20 bg-red-500/5">
          <div className="flex items-start gap-3">
            <div className="w-10 h-10 rounded-lg bg-red-500/10 flex items-center justify-center flex-shrink-0">
              <Activity className="w-5 h-5 text-red-400" />
            </div>
            <div>
              <h3 className="text-sm font-semibold text-red-400">Router is currently offline</h3>
              <p className="text-sm text-slate-400 mt-1">
                The data shown above is the last known state captured before the router went offline.
                No live data is available. Last seen {timeAgo(router.lastSeenAt)}.
              </p>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function UsageBar({ label, value, color }: { label: string; value: number; color: string }) {
  return (
    <div>
      <div className="flex items-center justify-between text-xs mb-1">
        <span className="text-slate-400">{label}</span>
        <span className="text-slate-300 font-mono">{value}%</span>
      </div>
      <div className="h-2 bg-noc-border rounded-full overflow-hidden">
        <div className={`h-full ${color} rounded-full transition-all duration-500`} style={{ width: `${value}%` }} />
      </div>
    </div>
  );
}

function InfoRow({ icon: Icon, label, value }: { icon: typeof Cpu; label: string; value: string }) {
  return (
    <div className="flex min-w-0 items-start gap-3 py-1.5 border-b border-noc-border/30 last:border-0">
      <Icon className="w-4 h-4 text-slate-500 flex-shrink-0" />
      <span className="w-24 flex-shrink-0 text-slate-400 sm:w-36">{label}</span>
      <span className="min-w-0 flex-1 break-all text-slate-200 font-mono text-xs">{value}</span>
    </div>
  );
}

function InterfacesTab({ interfaces, status }: { interfaces: RouterInterface[]; status: RouterStatusInfo | null }) {
  return (
    <div className="card overflow-hidden animate-fade-in">
      {status?.telemetryObservedAt && (
        <p className={`border-b border-noc-border px-5 py-3 text-xs ${status.telemetryStatus === "stale" ? "text-amber-300" : "text-slate-500"}`}>
          {status.telemetryStatus === "stale" ? "Stale snapshot" : "Latest snapshot"} · router observed {timeAgo(status.telemetryObservedAt)} · received {status.telemetryReceivedAt ? timeAgo(status.telemetryReceivedAt) : "never"}
        </p>
      )}
      <div className="table-scroll">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-xs text-slate-500 uppercase tracking-wider border-b border-noc-border">
              <th className="px-5 py-3 font-medium">Interface</th>
              <th className="px-5 py-3 font-medium">Type</th>
              <th className="px-5 py-3 font-medium">Status</th>
              <th className="px-5 py-3 font-medium">MAC</th>
              <th className="px-5 py-3 font-medium text-right">RX</th>
              <th className="px-5 py-3 font-medium text-right">TX</th>
              <th className="px-5 py-3 font-medium">IP</th>
            </tr>
          </thead>
          <tbody>
            {interfaces.map((iface) => (
              <tr key={iface.id} className="border-b border-noc-border/50 last:border-0 hover:bg-noc-hover/50">
                <td className="px-5 py-3 font-medium text-slate-200">{iface.name}</td>
                <td className="px-5 py-3 text-slate-400 capitalize">{iface.type}</td>
                <td className="px-5 py-3">
                  <span className={`inline-flex items-center gap-1.5 text-xs font-medium ${
                    iface.status === "up" ? "text-green-400" : "text-red-400"
                  }`}>
                    <span className={`status-dot ${iface.status === "up" ? "bg-green-500" : "bg-red-500"}`} />
                    {iface.status.toUpperCase()}
                  </span>
                </td>
                <td className="px-5 py-3 font-mono text-xs text-slate-500">{iface.macAddress}</td>
                <td className="px-5 py-3 text-right text-slate-400 font-mono text-xs">{formatBytes(iface.rxBytes)}</td>
                <td className="px-5 py-3 text-right text-slate-400 font-mono text-xs">{formatBytes(iface.txBytes)}</td>
                <td className="px-5 py-3 font-mono text-xs text-slate-400">{iface.ipAddress ?? "—"}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="px-5 py-3 border-t border-noc-border text-xs text-slate-500">
        All interfaces are displayed in read-only mode. No configuration actions are available.
      </div>
    </div>
  );
}

function DhcpLeasesTab({ data }: { data: RouterDhcpLeases | null }) {
  if (!data) return <EmptyState message="DHCP lease snapshot is unavailable." />;
  const incompleteMessage = data.collectionSucceeded === false
    ? "The latest DHCP lease collection failed; displayed leases are the last complete saved observation, if available."
    : data.collectionComplete === false
    ? `Collection incomplete: ${data.receivedCount ?? data.leases.length} lease records were received${
      data.expectedCount == null ? "." : ` of ${data.expectedCount} expected.`
    }`
    : data.collectionComplete == null
      ? "Collection completeness was not reported; this is only the number of lease records received."
      : null;

  return (
    <div className="card overflow-hidden animate-fade-in">
      {data.stale && (
        <p role="status" className="border-b border-amber-500/20 bg-amber-500/5 px-5 py-3 text-xs text-amber-300">
          This DHCP snapshot is stale; lease states reflect the last report received {data.receivedAt ? timeAgo(data.receivedAt) : "never"}.
        </p>
      )}
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-noc-border px-5 py-3">
        <h2 className="text-sm font-semibold text-slate-200">
          {data.usingLastGoodSnapshot
            ? `${data.leases.length} saved leases`
            : `${data.receivedCount ?? data.leases.length} reported DHCP leases`}
        </h2>
        <span className="text-xs text-slate-500">
          Lease source observed {data.observedAt ? timeAgo(data.observedAt) : "never"} · source received {data.receivedAt ? timeAgo(data.receivedAt) : "never"}
          {data.reportObservedAt && <> · report {timeAgo(data.reportObservedAt)}</>}
        </span>
      </div>
      {incompleteMessage && (
        <p role="status" className="border-b border-amber-500/20 bg-amber-500/5 px-5 py-3 text-xs text-amber-300">
          {incompleteMessage}
        </p>
      )}
      {data.leases.length === 0 ? (
        <p className="p-5 text-sm text-slate-400">
          {data.usingLastGoodSnapshot
            ? "No complete DHCP lease snapshot has been saved."
            : `${data.receivedCount ?? 0} DHCP lease records were received in the latest router report.`}
        </p>
      ) : (
        <div className="table-scroll">
          <table className="w-full text-sm">
            <thead><tr className="border-b border-noc-border text-left text-xs uppercase text-slate-500">
              <th className="px-5 py-3">Host</th><th className="px-5 py-3">Address</th><th className="px-5 py-3">MAC</th><th className="px-5 py-3">Interface</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Expires after</th>
            </tr></thead>
            <tbody>{data.leases.map((lease) => (
              <tr key={`${lease.macAddress}-${lease.address}`} className="border-b border-noc-border/50 last:border-0">
                <td className="px-5 py-3 text-slate-200">{lease.hostName || "—"}</td>
                <td className="px-5 py-3 font-mono text-slate-300">{lease.address}</td>
                <td className="px-5 py-3 font-mono text-xs text-slate-500">{lease.macAddress}</td>
                <td className="px-5 py-3 text-slate-400">{lease.interface}</td>
                <td className="px-5 py-3 text-slate-300">{lease.status}</td>
                <td className="px-5 py-3 text-slate-400">{lease.expiresAfter || "—"}</td>
              </tr>
            ))}</tbody>
          </table>
        </div>
      )}
      <p className="border-t border-noc-border px-5 py-3 text-xs text-slate-500">
        DHCP leases indicate address assignment, not active connectivity. Wi-Fi presence is confirmed by wireless registration; wired presence is confirmed by dynamic bridge-host entries.
      </p>
    </div>
  );
}

function resolveClientStatus(client: RouterClient): NonNullable<RouterClient["status"]> {
  if (client.status === "online" || client.status === "offline" || client.status === "unknown") {
    return client.status;
  }

  return client.lastConfirmedStatus ?? (client.offlineReason ? "offline" : "online");
}

function ClientsTab({
  clients,
  routerStatus,
  onClientUpdated,
}: {
  clients: RouterClient[];
  routerStatus: string;
  onClientUpdated: (client: RouterClient) => void;
}) {
  if (clients.length === 0) {
    return (
      <div className="card">
        <EmptyState message={routerStatus === "offline" ? "No saved client history is available for this offline router." : "No clients have been discovered yet."} />
      </div>
    );
  }
  return (
    <div className="card overflow-hidden animate-fade-in">
      <div className="table-scroll">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-xs text-slate-500 uppercase tracking-wider border-b border-noc-border">
              <th className="px-5 py-3 font-medium">Status</th>
              <th className="px-5 py-3 font-medium">Client</th>
              <th className="px-5 py-3 font-medium">Connection</th>
              <th className="px-5 py-3 font-medium">IP</th>
              <th className="px-5 py-3 font-medium">MAC</th>
              <th className="px-5 py-3 font-medium">Interface</th>
              <th className="px-5 py-3 font-medium text-right">Signal</th>
              <th className="px-5 py-3 font-medium text-right">RX</th>
              <th className="px-5 py-3 font-medium text-right">TX</th>
              <th className="px-5 py-3 font-medium">Last seen / reason</th>
              <th className="px-5 py-3 font-medium">Device name</th>
            </tr>
          </thead>
          <tbody>
            {clients.map((client) => {
              const status = resolveClientStatus(client);
              return (
                <tr key={client.id} className="border-b border-noc-border/50 last:border-0 hover:bg-noc-hover/50">
                  <td className={`px-5 py-3 text-xs font-medium ${status === "offline" ? "text-red-400" : status === "online" ? "text-green-400" : "text-slate-400"}`}>
                    <div>{status}</div>
                    {client.monitoringStale && (
                      <div className="mt-1 text-[10px] font-medium uppercase tracking-wide text-amber-300" title={client.monitoringIssue ?? undefined}>
                        stale
                      </div>
                    )}
                  </td>
                  <td className="px-5 py-3 font-medium text-slate-200">{client.displayName || client.alias || client.hostname || "Unknown Device"}</td>
                  <td className="px-5 py-3 text-xs text-slate-400">
                    {client.connectionType === "arp" ? "Neighbor (ARP)" : client.connectionType === "wired" ? "Wired" : "Wi-Fi"}
                  </td>
                  <td className="px-5 py-3 font-mono text-xs text-slate-400">{client.ipAddress || "—"}</td>
                  <td className="px-5 py-3 font-mono text-xs text-slate-500">{client.macAddress}</td>
                  <td className="px-5 py-3 text-slate-400">{client.interfaceName}</td>
                  <td className="px-5 py-3 text-right">
                    {client.signal === null || client.signal === 0
                      ? <span className="font-mono text-xs text-slate-500">{formatSignal(client.signal)}</span>
                      : <span className={`font-mono text-xs ${client.signal > -60 ? "text-green-400" : client.signal > -75 ? "text-amber-400" : "text-red-400"}`}>
                        {formatSignal(client.signal)}
                      </span>}
                  </td>
                  <td className="px-5 py-3 text-right text-slate-400 font-mono text-xs">{formatBytes(client.rxBytes)}</td>
                  <td className="px-5 py-3 text-right text-slate-400 font-mono text-xs">{formatBytes(client.txBytes)}</td>
                  <td className="px-5 py-3 text-xs text-slate-400">
                    {client.monitoringIssue && <div className="mb-1 text-amber-300">{client.monitoringIssue}</div>}
                    {client.offlineReason ?? (client.lastSeenAt ? timeAgo(client.lastSeenAt) : "—")}
                  </td>
                  <td className="px-5 py-3">
                    <ClientAliasControl client={client} onSaved={onClientUpdated} />
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      <div className="px-5 py-3 border-t border-noc-border text-xs text-slate-500">
        {clients.filter((client) => resolveClientStatus(client) === "online").length} online · {clients.filter((client) => resolveClientStatus(client) === "offline").length} offline · {clients.filter((client) => resolveClientStatus(client) === "unknown").length} unknown · {clients.filter((client) => client.monitoringStale).length} with stale monitoring. Read-only view.
      </div>
    </div>
  );
}

function WirelessTab({ wireless, routerStatus }: { wireless: WirelessInfo | null; routerStatus: string }) {
  if (routerStatus === "offline" || !wireless) {
    return (
      <div className="card">
        <EmptyState message="No wireless information available — router is offline or has no wireless interfaces." />
      </div>
    );
  }
  const items = [
    { label: "SSID", value: wireless.ssid, icon: Wifi },
    { label: "Frequency", value: wireless.frequency, icon: Radio },
    { label: "Channel", value: wireless.channel, icon: Radio },
    { label: "Channel Width", value: wireless.channelWidth, icon: Radio },
    { label: "Mode", value: wireless.mode, icon: Wifi },
    { label: "Signal", value: `${wireless.signal} dBm`, icon: Signal },
    { label: "Noise Floor", value: `${wireless.noiseFloor} dBm`, icon: Signal },
    {
      label: "Connected Clients",
      value: wireless.connectedClients === null
        ? "Unknown (source not confirmed)"
        : wireless.collectionComplete === true
          ? String(wireless.connectedClients)
          : `${wireless.connectedClients} observed (incomplete)`,
      icon: Users,
    },
    { label: "TX Rate", value: wireless.txRate, icon: ArrowUpFromLine },
    { label: "RX Rate", value: wireless.rxRate, icon: ArrowDownToLine },
    { label: "Protocol", value: wireless.protocol, icon: Radio },
    { label: "Security", value: wireless.securityMode, icon: ShieldCheck },
  ];
  return (
    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 animate-fade-in">
      {items.map((item) => {
        const Icon = item.icon;
        return (
          <div key={item.label} className="card p-5">
            <div className="flex items-center gap-2 mb-2">
              <Icon className="w-4 h-4 text-noc-accent" />
              <span className="text-xs text-slate-400">{item.label}</span>
            </div>
            <div className="text-lg font-semibold text-slate-100">{item.value}</div>
          </div>
        );
      })}
    </div>
  );
}

function TrafficTab({ traffic }: { traffic: RouterTraffic | null }) {
  if (!traffic) return <EmptyState message="No traffic data available." />;
  return (
    <div className="space-y-6 animate-fade-in">
      {traffic.dataSource && (
        <p className={`text-xs ${traffic.stale ? "text-amber-300" : "text-slate-500"}`}>
          {traffic.stale ? "Stale traffic snapshot" : "Latest router traffic report"}
          {traffic.receivedAt ? ` · received ${timeAgo(traffic.receivedAt)}` : ""}
        </p>
      )}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div className="card p-5">
          <div className="flex items-center gap-2 mb-2">
            <ArrowDownToLine className="w-4 h-4 text-green-400" />
            <span className="text-xs text-slate-400">Total RX</span>
          </div>
          <div className="text-xl font-bold text-slate-100">{formatBytes(traffic.totalRxBytes)}</div>
        </div>
        <div className="card p-5">
          <div className="flex items-center gap-2 mb-2">
            <ArrowUpFromLine className="w-4 h-4 text-cyan-400" />
            <span className="text-xs text-slate-400">Total TX</span>
          </div>
          <div className="text-xl font-bold text-slate-100">{formatBytes(traffic.totalTxBytes)}</div>
        </div>
        <div className="card p-5">
          <div className="flex items-center gap-2 mb-2">
            <ArrowDownToLine className="w-4 h-4 text-green-400" />
            <span className="text-xs text-slate-400">Current RX</span>
          </div>
          <div className="text-xl font-bold text-slate-100">{traffic.currentRxMbps == null ? "Waiting for next telemetry report" : <>{traffic.currentRxMbps} <span className="text-sm text-slate-400">Mbps</span></>}</div>
        </div>
        <div className="card p-5">
          <div className="flex items-center gap-2 mb-2">
            <ArrowUpFromLine className="w-4 h-4 text-cyan-400" />
            <span className="text-xs text-slate-400">Current TX</span>
          </div>
          <div className="text-xl font-bold text-slate-100">{traffic.currentTxMbps == null ? "Waiting for next telemetry report" : <>{traffic.currentTxMbps} <span className="text-sm text-slate-400">Mbps</span></>}</div>
        </div>
      </div>

      <div className="card p-5">
        <h3 className="text-sm font-semibold text-slate-200 mb-4">Traffic History (last 2 hours)</h3>
        <ResponsiveContainer width="100%" height={300}>
          <AreaChart data={traffic.history}>
            <defs>
              <linearGradient id="rxGrad" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor="#22c55e" stopOpacity={0.4} />
                <stop offset="100%" stopColor="#22c55e" stopOpacity={0} />
              </linearGradient>
              <linearGradient id="txGrad" x1="0" y1="0" x2="0" y2="1">
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
            <Area type="monotone" dataKey="rxMbps" stroke="#22c55e" strokeWidth={2} fill="url(#rxGrad)" name="RX (Mbps)" />
            <Area type="monotone" dataKey="txMbps" stroke="#06b6d4" strokeWidth={2} fill="url(#txGrad)" name="TX (Mbps)" />
          </AreaChart>
        </ResponsiveContainer>
      </div>
    </div>
  );
}
