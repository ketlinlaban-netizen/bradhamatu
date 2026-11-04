// Core domain types for the Bratha Matu NOC console.
// These mirror the Laravel API contract exactly so the frontend
// can switch from the mock provider to a real Laravel backend
// without any component changes.

export type RouterStatus = "online" | "offline" | "degraded" | "stale" | "unknown";

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
  configuredIpAddress?: string | null;
  routerObservedIpAddress?: string | null;
  addressMismatch?: boolean;
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
  dataSource?: "live" | "telemetry" | "stale";
  telemetryObservedAt?: string | null;
  telemetryReceivedAt?: string | null;
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
  routerMacAddress?: string | null;
  ipAddresses: string[];
  interfaceCollectionSucceeded?: boolean | null;
  interfaceCollectionComplete?: boolean | null;
  interfaceCollectedCount?: number | null;
  interfaceReceivedCount?: number | null;
  interfaceObservedAt?: string | null;
  interfaceFresh?: boolean;
  wirelessCollectionSucceeded?: boolean | null;
  wirelessCollectionComplete?: boolean | null;
  wirelessExpectedCount?: number | null;
  wirelessCollectedCount?: number | null;
  wirelessReceivedCount?: number | null;
  wirelessObservedAt?: string | null;
  wirelessFresh?: boolean;
  gatewayRoutes: Array<{
    routeId: string | null;
    destination?: string;
    gateway: string | null;
    immediateGateway: string | null;
    interface: string | null;
    distance: number | null;
    routingTable: string;
    active: boolean;
    dynamic: boolean;
    static: boolean;
    disabled: boolean;
    ecmp: boolean;
  }>;
  gatewayObservedAt: string | null;
  routeCollectionSucceeded?: boolean | null;
  routeCollectionComplete?: boolean | null;
  routeCollectedCount?: number | null;
  routeReceivedCount?: number | null;
  routeFresh?: boolean;
  ipAddressCollectionSucceeded?: boolean | null;
  ipAddressCollectionComplete?: boolean | null;
  ipAddressCollectedCount?: number | null;
  ipAddressReceivedCount?: number | null;
  ipAddressObservedAt?: string | null;
  ipAddressFresh?: boolean;
  lastCommunication: string;
  connectionLatencyMs: number | null;
  dataSource?: "live" | "telemetry" | "stale";
  staleReason?: string;
  telemetryObservedAt?: string | null;
  telemetryReceivedAt?: string | null;
  telemetryStatus?: "online" | "stale" | "unknown";
}

export interface RouterPppoeInfo {
  routerId: string;
  observedAt: string;
  accounts: Array<{
    name: string;
    service: string;
    profile: string;
    disabled: boolean;
    localAddress: string | null;
    remoteAddress: string | null;
  }>;
  activeSessions: Array<{
    name: string;
    service: string;
    callerId: string | null;
    address: string | null;
    uptime: string | null;
    sessionId: string | null;
  }>;
  available?: boolean;
  message?: string;
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
  observedAt?: string | null;
  receivedAt?: string | null;
  collectionSucceeded?: boolean | null;
  collectionComplete?: boolean | null;
  collectedCount?: number | null;
  receivedCount?: number | null;
  stale?: boolean;
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
  configuredIpAddress?: string | null;
  macAddress: string;
  connectionType?: "wireless" | "wired" | "arp" | "dhcp";
  applicationConfiguredIpAddress?: string | null;
  routerStaticLeaseIpAddress?: string | null;
  routerObservedIpAddress?: string | null;
  ipAddressObservedAt?: string | null;
  ipConfigurationScope?: "application-only" | "router-static-dhcp-lease" | "router-observation";
  addressMismatch?: boolean;
  deviceIdentity?: string;
  observationSource?: "wireless-registration" | "dynamic-bridge-host" | "arp-neighbor" | "dhcp-lease";
  routerLastSuccessfulReportAt?: string | null;
  monitoringFresh?: boolean;
  signal: number | null;
  rxBytes: number;
  txBytes: number;
  connectedAt: string;
  lastSeenAt: string;
  firstSeenAt?: string;
  routerTelemetryEnabled?: boolean;
  routerTelemetryObservedAt?: string | null;
  routerTelemetryReceivedAt?: string | null;
  status?: "online" | "offline" | "unknown";
  lastConfirmedStatus?: "online" | "offline" | null;
  monitoringStale?: boolean;
  monitoringIssue?: string | null;
  offlineReason?: string | null;
}

export interface RouterDhcpLeases {
  routerId: string;
  observedAt: string | null;
  receivedAt: string | null;
  collectionComplete?: boolean | null;
  collectionSucceeded?: boolean | null;
  expectedCount?: number | null;
  collectedCount?: number | null;
  receivedCount?: number;
  usingLastGoodSnapshot?: boolean;
  stale?: boolean;
  dataSource?: "telemetry" | "stale";
  reportObservedAt?: string | null;
  reportReceivedAt?: string | null;
  leases: Array<{
    macAddress: string;
    hostName: string;
    address: string;
    interface: string;
    status: "bound" | "waiting" | "offered" | "unknown";
    expiresAfter: string;
  }>;
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
  connectedClients: number | null;
  txRate: string;
  rxRate: string;
  protocol: string;
  securityMode: string;
  observedAt?: string | null;
  collectionSucceeded?: boolean | null;
  collectionComplete?: boolean | null;
  expectedCount?: number | null;
  receivedCount?: number | null;
  usingLastGoodSnapshot?: boolean;
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
  currentRxMbps: number | null;
  currentTxMbps: number | null;
  history: TrafficPoint[];
  observedAt?: string | null;
  receivedAt?: string | null;
  stale?: boolean;
  dataSource?: "live" | "telemetry" | "stale";
}

export interface RouterMetric {
  id: string;
  routerId: string;
  cpuUsage: number | null;
  memoryUsage: number | null;
  storageUsage: number | null;
  temperature: number | null;
  rxRate: number | null;
  txRate: number | null;
  recordedAt: string;
}

export interface DashboardSummary {
  totalRouters: number;
  online: number;
  offline: number;
  degraded: number;
  stale: number;
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
  type: "offline" | "degraded" | "stale" | "unknown" | "high_cpu" | "high_memory" | "interface_down";
  severity: "critical" | "warning" | "info";
  message: string;
  startedAt: string;
  resolvedAt: string | null;
}

export interface RouterConnection {
  routerId: string;
  state: "connecting" | "authenticating" | "established" | "failed" | "waiting" | "telemetry";
  message: string;
  readOnly: boolean;
  sessionToken: string | null;
  dataSource?: "live" | "telemetry" | "stale" | "unknown";
  telemetryReceivedAt?: string | null;
}

// ── MikroTik Configuration ───────────────────────────────────────
// Stored router credentials for connecting to real MikroTik routers.
// The password is NEVER returned from the API — only a masked placeholder.

export interface MikroTikConfig {
  id: string;
  name: string;
  host: string;
  apiTransport: "binary" | "rest" | "telemetry" | null;
  transportConfigured?: boolean;
  healthStatus?: "online" | "offline" | "stale" | "unknown" | "misconfigured" | "unauthorized" | "degraded";
  apiPort: number;
  useTls: boolean;
  verifyCert: boolean;
  username: string;
  passwordMasked: string | null;
  location: string | null;
  site: string | null;
  isConnected: boolean;
  lastSeenAt: string | null;
  lastSuccessfulCheckAt?: string | null;
  telemetryEnabled?: boolean;
  telemetryObservedAt?: string | null;
  telemetryReceivedAt?: string | null;
  telemetryStale?: boolean;
  telemetryCredentialIssued?: boolean;
  telemetryEndpoint?: string | null;
  createdAt: string;
}

export interface MikroTikConnectResult {
  success: boolean;
  state: "established" | "failed" | "waiting" | "telemetry";
  message: string;
  telemetryReceivedAt?: string | null;
  observations?: {
    observedAt: string;
    interfaces: MikroTikReadResult;
    addresses: MikroTikReadResult;
    routes: MikroTikReadResult;
    dhcpLeases: MikroTikReadResult;
  };
  readOnly: boolean;
  router: {
    id: string;
    name: string;
    identity: string;
    host: string;
    model: string;
    serialNumber: string;
    routerosVersion: string;
    architecture: string;
    cpuLoad: number;
    uptime: string;
    totalMemory: number;
    freeMemory: number;
    totalHdd: number;
    freeHdd: number;
    cpuCount: number;
    platform: string;
  } | null;
}

export interface MikroTikReadResult {
  records: Array<Record<string, string | number | boolean | null>>;
  error: {
    category: string;
    message: string;
  } | null;
}
