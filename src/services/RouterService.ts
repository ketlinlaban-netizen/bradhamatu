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
import type { RouterProvider } from "./RouterProvider";
import { RouterReadOnlyGuard } from "./RouterReadOnlyGuard";

/**
 * RouterService
 *
 * The single entry point for all router operations. Every call passes
 * through the RouterReadOnlyGuard before reaching the provider.
 *
 * Architecture:
 *   Controller → RouterService → ReadOnlyGuard → LaravelApiProvider
 *                                              ↓ HTTP GET
 *                                              Laravel API
 *                                              ↓ MikroTikRouterProvider
 *                                              MikroTik Router(s)
 *
 * To swap providers, change the constructor injection. Nothing else
 * in the frontend changes.
 */
export class RouterService {
  private provider: RouterProvider;
  private guard: RouterReadOnlyGuard;

  constructor(provider: RouterProvider, guard?: RouterReadOnlyGuard) {
    this.provider = provider;
    this.guard = guard ?? new RouterReadOnlyGuard();
  }

  private guarded<T>(operation: string, fn: () => Promise<T>): Promise<T> {
    this.guard.enforce(operation);
    return fn();
  }

  getRouters(): Promise<Router[]> {
    return this.guarded("getRouters", () => this.provider.getRouters());
  }

  getRouter(routerId: string): Promise<Router | null> {
    return this.guarded("getRouter", () => this.provider.getRouter(routerId));
  }

  getStatus(routerId: string): Promise<RouterStatusInfo | null> {
    return this.guarded("getStatus", () => this.provider.getStatus(routerId));
  }

  getInterfaces(routerId: string): Promise<RouterInterface[]> {
    return this.guarded("getInterfaces", () => this.provider.getInterfaces(routerId));
  }

  getClients(routerId: string): Promise<RouterClient[]> {
    return this.guarded("getClients", () => this.provider.getClients(routerId));
  }

  getTraffic(routerId: string): Promise<RouterTraffic | null> {
    return this.guarded("getTraffic", () => this.provider.getTraffic(routerId));
  }

  getWireless(routerId: string): Promise<WirelessInfo | null> {
    return this.guarded("getWireless", () => this.provider.getWireless(routerId));
  }

  getMetrics(routerId: string): Promise<RouterMetric[]> {
    return this.guarded("getMetrics", () => this.provider.getMetrics(routerId));
  }

  getDashboardSummary(): Promise<DashboardSummary> {
    return this.guarded("getDashboardSummary", () => this.provider.getDashboardSummary());
  }

  getIncidents(): Promise<Incident[]> {
    return this.guarded("getIncidents", () => this.provider.getIncidents());
  }

  connect(routerId: string): Promise<RouterConnection> {
    return this.guarded("connect", () => this.provider.connect(routerId));
  }

  searchRouters(query: string): Promise<Router[]> {
    return this.guarded("searchRouters", () => this.provider.searchRouters(query));
  }
}

// ── singleton instance ──────────────────────────────────────────────
// Wired to the real Laravel backend — no mock data.
import { LaravelApiProvider } from "./LaravelApiProvider";

export const routerService = new RouterService(new LaravelApiProvider());
