// Core domain types for the Bradha Matu NOC console.
// These mirror the Laravel API contract exactly so the frontend
// can switch from the mock provider to a real Laravel backend
// without any component changes.

export type RouterStatus = "online" | "offline" | "degraded" | "unknown";

export type OperationType = "READ" | "WRITE";

export type UserRole = "super_admin" | "admin" | "viewer";

export interface AdminUser {
  id: string;
  email: string;
  name: string;
  role: UserRole;
}

export interface Router {
  id: string;
  name: string;
  identity: string;
  hostname: string;
  ipAddress: string;
  macAddress: string;
  location: string;
  site: string;
  model: string;
  serialNumber: string;
  routerosVersion: string;
  architecture: string;
  uptimeSeconds?: number | null;
  status: RouterStatus;
  lastSeenAt: string;
  lastErrorCategory?: string | null;
  createdAt: string;
  updatedAt: string;
}

export interface RouterStatusInfo {
  routerId: string;
  cpuUsage: number | null;
  memoryUsage: number | null;
  storageUsage: number | null;
  temperature: number | null;
  uptimeSeconds: number | null;
  boardModel: string;
  architecture: string;
  routerosVersion: string;
  serialNumber: string;
  systemIdentity: string;
  macAddresses: string[];
  ipAddresses: string[];
  lastCommunication: string;
  connectionLatencyMs: number | null;
}

export interface RouterInterface {
  id: string;
  routerId: string;
  name: string;
  type: "ethernet" | "wireless" | "bridge" | "vlan" | "ppp";
  status: "up" | "down" | "disabled";
  macAddress: string;
  rxBytes: number;
  txBytes: number;
  ipAddress: string | null;
}

export interface RouterClient {
  id: string;
  routerId: string;
  routerName?: string;
  interfaceId: string;
  interfaceName: string;
  hostname: string;
  alias?: string | null;
  displayName?: string;
  aliasEditable?: boolean;
  ipAddress: string;
  macAddress: string;
  signal: number;
  rxBytes: number;
  txBytes: number;
  connectedAt: string;
  lastSeenAt: string;
  firstSeenAt?: string;
  status?: "online" | "offline" | "unknown";
  offlineReason?: string | null;
}

export interface WirelessInfo {
  routerId: string;
  ssid: string;
  frequency: string;
  channel: string;
  channelWidth: string;
  mode: string;
  signal: number;
  noiseFloor: number;
  connectedClients: number;
  txRate: string;
  rxRate: string;
  protocol: string;
  securityMode: string;
}

export interface TrafficPoint {
  time: string;
  rxMbps: number;
  txMbps: number;
}

export interface RouterTraffic {
  routerId: string;
  totalRxBytes: number;
  totalTxBytes: number;
  currentRxMbps: number;
  currentTxMbps: number;
  history: TrafficPoint[];
}

export interface RouterMetric {
  id: string;
  routerId: string;
  cpuUsage: number | null;
  memoryUsage: number | null;
  storageUsage: number | null;
  temperature: number | null;
  rxRate: number;
  txRate: number;
  recordedAt: string;
}

export interface DashboardSummary {
  totalRouters: number;
  online: number;
  offline: number;
  degraded: number;
  unknown: number;
  totalClients: number;
  totalTrafficBytes: number;
  uptimePercent: number;
  dataSource?: "mock" | "live";
}

export interface Incident {
  id: string;
  routerId: string;
  routerName: string;
  type: "offline" | "degraded" | "high_cpu" | "high_memory" | "interface_down";
  severity: "critical" | "warning" | "info";
  message: string;
  startedAt: string;
  resolvedAt: string | null;
}

export interface RouterConnection {
  routerId: string;
  state: "connecting" | "authenticating" | "established" | "failed";
  message: string;
  readOnly: boolean;
  sessionToken: string | null;
}

// ── MikroTik Configuration ───────────────────────────────────────
// Stored router credentials for connecting to real MikroTik routers.
// The password is NEVER returned from the API — only a masked placeholder.

export interface MikroTikConfig {
  id: string;
  name: string;
  host: string;
  apiPort: number;
  useTls: boolean;
  verifyCert: boolean;
  username: string;
  passwordMasked: string | null;
  location: string | null;
  site: string | null;
  isConnected: boolean;
  lastSeenAt: string | null;
  createdAt: string;
  monitoringEnabled?: boolean;
  lastRouterosVersion?: string | null;
  lastErrorCategory?: string | null;
}

export interface MikroTikConnectResult {
  success: boolean;
  state: "established" | "failed";
  message: string;
  readOnly: boolean;
  category?: string | null;
  elapsedMs?: number | null;
  router: {
    id: string;
    name: string;
    identity: string;
    host: string;
    model: string | null;
    serialNumber: string | null;
    routerosVersion: string | null;
    architecture: string | null;
    cpuLoad: number | null;
    uptime: string | null;
    totalMemory: number | null;
    freeMemory: number | null;
    totalHdd: number | null;
    freeHdd: number | null;
    cpuCount: number | null;
    platform: string | null;
  } | null;
}
