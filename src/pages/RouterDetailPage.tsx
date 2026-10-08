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
} from "../types";
import { formatBytes, formatUptime, timeAgo } from "../utils/format";
import { StatusBadge, LoadingSpinner, EmptyState } from "../components/ui";

type Tab = "overview" | "interfaces" | "clients" | "wireless" | "traffic";

export function RouterDetailPage() {
  const { routerId } = useParams<{ routerId: string }>();
  const [router, setRouter] = useState<Router | null>(null);
  const [status, setStatus] = useState<RouterStatusInfo | null>(null);
  const [interfaces, setInterfaces] = useState<RouterInterface[]>([]);
  const [clients, setClients] = useState<RouterClient[]>([]);
  const [traffic, setTraffic] = useState<RouterTraffic | null>(null);
  const [wireless, setWireless] = useState<WirelessInfo | null>(null);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState<Tab>("overview");
  const [connection, setConnection] = useState<RouterConnection | null>(null);
  const [connecting, setConnecting] = useState(false);
  const [connectStep, setConnectStep] = useState(0);

  useEffect(() => {
    if (!routerId) return;
    setLoading(true);
    Promise.all([
      routerService.getRouter(routerId),
      routerService.getStatus(routerId),
      routerService.getInterfaces(routerId),
      routerService.getClients(routerId),
      routerService.getTraffic(routerId),
      routerService.getWireless(routerId),
    ]).then(([r, s, i, c, t, w]) => {
      setRouter(r);
      setStatus(s);
      setInterfaces(i);
      setClients(c);
      setTraffic(t);
      setWireless(w);
      setLoading(false);
    });
  }, [routerId]);

  const handleConnect = async () => {
    if (!routerId) return;
    setConnecting(true);
    setConnectStep(0);

    // Simulate connection phases
    setConnectStep(1); // Connecting...
    await new Promise((r) => setTimeout(r, 800));
    setConnectStep(2); // Authenticating...
    await new Promise((r) => setTimeout(r, 800));
    setConnectStep(3); // Established
    await new Promise((r) => setTimeout(r, 400));

    const conn = await routerService.connect(routerId);
    setConnection(conn);
    setConnecting(false);
  };

  if (loading) return <LoadingSpinner label="Loading router details..." />;
  if (!router) {
    return (
      <div className="card">
        <EmptyState message="Router not found." />
        <div className="text-center pb-6">
          <Link to="/routers" className="text-sm text-noc-accent hover:text-blue-400">← Back to routers</Link>
        </div>
      </div>
    );
  }

  const tabs: Array<{ key: Tab; label: string; icon: typeof Cpu }> = [
    { key: "overview", label: "Overview", icon: Cpu },
    { key: "interfaces", label: "Interfaces", icon: Network },
    { key: "clients", label: `Clients (${clients.length})`, icon: Users },
    { key: "wireless", label: "Wireless", icon: Wifi },
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
              <span className="text-xs font-medium text-green-400">READ-ONLY SESSION</span>
            </div>

            {/* Connect button */}
            {connection ? (
              <div className="flex items-center gap-2 text-sm text-green-400">
                <CheckCircle2 className="w-4 h-4" />
                <span className="font-medium">Connected</span>
                <span className="text-xs text-slate-500 font-mono">{connection.sessionToken?.slice(0, 20)}...</span>
              </div>
            ) : connecting ? (
              <div className="flex flex-col items-end gap-2">
                <div className="flex items-center gap-2 text-sm text-slate-300">
                  <Loader2 className="w-4 h-4 animate-spin" />
                  <span>
                    {connectStep === 1 && "Connecting..."}
                    {connectStep === 2 && "Authenticating..."}
                    {connectStep === 3 && "Connection established."}
                  </span>
                </div>
                <div className="w-40 h-1 bg-noc-border rounded-full overflow-hidden">
                  <div
                    className="h-full bg-noc-accent transition-all duration-500"
                    style={{ width: `${connectStep * 33}%` }}
                  />
                </div>
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
      <div className="flex gap-1 border-b border-noc-border">
        {tabs.map((tab) => {
          const Icon = tab.icon;
          return (
            <button
              key={tab.key}
              onClick={() => setActiveTab(tab.key)}
              className={`flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 transition-colors -mb-px ${
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

      {/* Tab content */}
      {activeTab === "overview" && (
        <OverviewTab router={router} status={status} />
      )}
      {activeTab === "interfaces" && (
        <InterfacesTab interfaces={interfaces} />
      )}
      {activeTab === "clients" && (
        <ClientsTab clients={clients} routerStatus={router.status} />
      )}
      {activeTab === "wireless" && (
        <WirelessTab wireless={wireless} routerStatus={router.status} />
      )}
      {activeTab === "traffic" && (
        <TrafficTab traffic={traffic} />
      )}
    </div>
  );
}

function MetricCard({ icon: Icon, label, value, unit, color }: {
  icon: typeof Cpu; label: string; value: string | number; unit?: string; color: string;
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
        <MetricCard icon={Clock} label="Uptime" value={formatUptime(status.uptimeSeconds)} color="text-emerald-400" />
        <MetricCard icon={Activity} label="Latency" value={status.connectionLatencyMs} unit="ms" color="text-green-400" />
      </div>

      {/* Usage bars */}
      {!isOffline && (
        <div className="card p-5 space-y-4">
          <h3 className="text-sm font-semibold text-slate-200">Resource Usage</h3>
          <UsageBar label="CPU" value={status.cpuUsage} color="bg-blue-500" />
          <UsageBar label="Memory" value={status.memoryUsage} color="bg-cyan-500" />
          <UsageBar label="Storage" value={status.storageUsage} color="bg-purple-500" />
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
          <InfoRow icon={Clock} label="Last Communication" value={timeAgo(status.lastCommunication)} />
          <InfoRow icon={Network} label="MAC Address" value={status.macAddresses.join(", ")} />
          <InfoRow icon={Network} label="IP Addresses" value={status.ipAddresses.join(", ")} />
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
    <div className="flex items-center gap-3 py-1.5 border-b border-noc-border/30 last:border-0">
      <Icon className="w-4 h-4 text-slate-500 flex-shrink-0" />
      <span className="text-slate-400 w-36 flex-shrink-0">{label}</span>
      <span className="text-slate-200 font-mono text-xs">{value}</span>
    </div>
  );
}

function InterfacesTab({ interfaces }: { interfaces: RouterInterface[] }) {
  return (
    <div className="card overflow-hidden animate-fade-in">
      <div className="overflow-x-auto">
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

function ClientsTab({ clients, routerStatus }: { clients: RouterClient[]; routerStatus: string }) {
  if (routerStatus === "offline") {
    return (
      <div className="card">
        <EmptyState message="No active clients — router is offline." />
      </div>
    );
  }
  if (clients.length === 0) {
    return (
      <div className="card">
        <EmptyState message="No clients currently connected." />
      </div>
    );
  }
  return (
    <div className="card overflow-hidden animate-fade-in">
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-xs text-slate-500 uppercase tracking-wider border-b border-noc-border">
              <th className="px-5 py-3 font-medium">Client</th>
              <th className="px-5 py-3 font-medium">IP</th>
              <th className="px-5 py-3 font-medium">MAC</th>
              <th className="px-5 py-3 font-medium">Interface</th>
              <th className="px-5 py-3 font-medium text-right">Signal</th>
              <th className="px-5 py-3 font-medium text-right">RX</th>
              <th className="px-5 py-3 font-medium text-right">TX</th>
            </tr>
          </thead>
          <tbody>
            {clients.map((client) => (
              <tr key={client.id} className="border-b border-noc-border/50 last:border-0 hover:bg-noc-hover/50">
                <td className="px-5 py-3 font-medium text-slate-200">{client.hostname}</td>
                <td className="px-5 py-3 font-mono text-xs text-slate-400">{client.ipAddress}</td>
                <td className="px-5 py-3 font-mono text-xs text-slate-500">{client.macAddress}</td>
                <td className="px-5 py-3 text-slate-400">{client.interfaceName}</td>
                <td className="px-5 py-3 text-right">
                  <span className={`font-mono text-xs ${client.signal > -60 ? "text-green-400" : client.signal > -75 ? "text-amber-400" : "text-red-400"}`}>
                    {client.signal} dBm
                  </span>
                </td>
                <td className="px-5 py-3 text-right text-slate-400 font-mono text-xs">{formatBytes(client.rxBytes)}</td>
                <td className="px-5 py-3 text-right text-slate-400 font-mono text-xs">{formatBytes(client.txBytes)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="px-5 py-3 border-t border-noc-border text-xs text-slate-500">
        {clients.length} clients connected. Read-only view — no disconnect or bandwidth controls available.
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
    { label: "Connected Clients", value: String(wireless.connectedClients), icon: Users },
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
          <div className="text-xl font-bold text-slate-100">{traffic.currentRxMbps} <span className="text-sm text-slate-400">Mbps</span></div>
        </div>
        <div className="card p-5">
          <div className="flex items-center gap-2 mb-2">
            <ArrowUpFromLine className="w-4 h-4 text-cyan-400" />
            <span className="text-xs text-slate-400">Current TX</span>
          </div>
          <div className="text-xl font-bold text-slate-100">{traffic.currentTxMbps} <span className="text-sm text-slate-400">Mbps</span></div>
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
