import type {
  Router,
  RouterInterface,
  RouterClient,
  RouterStatusInfo,
  RouterTraffic,
  RouterMetric,
  WirelessInfo,
  DashboardSummary,
  Incident,
  RouterConnection,
} from "../types";

/**
 * RouterProvider interface.
 *
 * This is the contract that LaravelApiProvider implements. The
 * RouterService depends on this interface, not on any concrete
 * implementation. The LaravelApiProvider calls the Laravel backend,
 * which in turn talks to MikroTik routers via the binary API.
 *
 * Laravel equivalent: app/Services/RouterProvider.php (interface)
 */
export interface RouterProvider {
  getRouters(): Promise<Router[]>;
  getRouter(routerId: string): Promise<Router | null>;
  getStatus(routerId: string): Promise<RouterStatusInfo | null>;
  getInterfaces(routerId: string): Promise<RouterInterface[]>;
  getClients(routerId: string): Promise<RouterClient[]>;
  getTraffic(routerId: string): Promise<RouterTraffic | null>;
  getWireless(routerId: string): Promise<WirelessInfo | null>;
  getMetrics(routerId: string): Promise<RouterMetric[]>;
  getDashboardSummary(): Promise<DashboardSummary>;
  getIncidents(): Promise<Incident[]>;
  connect(routerId: string): Promise<RouterConnection>;
  searchRouters(query: string): Promise<Router[]>;
}
