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
import { apiGet } from "./api";

/**
 * LaravelApiProvider
 *
 * Talks to the real Laravel backend, which in turn talks to MikroTik
 * routers via the binary API (or REST API fallback). No mock data —
 * every call is a live HTTP GET to the Laravel API.
 *
 * The Laravel backend handles:
 *   - MikroTik binary API protocol (TCP 8728/8729)
 *   - REST API fallback (HTTPS /rest/)
 *   - Read-only enforcement (RouterReadOnlyGuard on the server)
 *   - Sanctum token authentication
 *
 * This provider is a thin transport — it maps each RouterProvider
 * method to the corresponding Laravel endpoint.
 */
export class LaravelApiProvider implements RouterProvider {
  async getRouters(): Promise<Router[]> {
    return apiGet<Router[]>("/routers");
  }

  async getRouter(routerId: string): Promise<Router | null> {
    try {
      return await apiGet<Router>(`/routers/${routerId}`);
    } catch {
      return null;
    }
  }

  async getStatus(routerId: string): Promise<RouterStatusInfo | null> {
    try {
      return await apiGet<RouterStatusInfo>(`/routers/${routerId}/status`);
    } catch {
      return null;
    }
  }

  async getInterfaces(routerId: string): Promise<RouterInterface[]> {
    try {
      return await apiGet<RouterInterface[]>(`/routers/${routerId}/interfaces`);
    } catch {
      return [];
    }
  }

  async getClients(routerId: string): Promise<RouterClient[]> {
    try {
      return await apiGet<RouterClient[]>(`/routers/${routerId}/clients`);
    } catch {
      return [];
    }
  }

  async getTraffic(routerId: string): Promise<RouterTraffic | null> {
    try {
      return await apiGet<RouterTraffic>(`/routers/${routerId}/traffic`);
    } catch {
      return null;
    }
  }

  async getWireless(routerId: string): Promise<WirelessInfo | null> {
    try {
      return await apiGet<WirelessInfo>(`/routers/${routerId}/wireless`);
    } catch {
      return null;
    }
  }

  async getMetrics(routerId: string): Promise<RouterMetric[]> {
    try {
      return await apiGet<RouterMetric[]>(`/routers/${routerId}/metrics`);
    } catch {
      return [];
    }
  }

  async getDashboardSummary(): Promise<DashboardSummary> {
    return apiGet<DashboardSummary>("/dashboard");
  }

  async getIncidents(): Promise<Incident[]> {
    try {
      return await apiGet<Incident[]>("/incidents");
    } catch {
      return [];
    }
  }

  async connect(routerId: string): Promise<RouterConnection> {
    return apiGet<RouterConnection>(`/routers/${routerId}/connect`);
  }

  async searchRouters(query: string): Promise<Router[]> {
    return apiGet<Router[]>(`/routers/search?q=${encodeURIComponent(query)}`);
  }
}
