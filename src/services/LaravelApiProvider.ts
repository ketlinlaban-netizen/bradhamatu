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
import { apiGet, ApiError } from "./api";

/**
 * LaravelApiProvider
 *
 * Talks to the real Laravel backend, which in turn talks to MikroTik
 * routers via the native binary API. No mock data —
 * every call is a live HTTP GET to the Laravel API.
 *
 * The Laravel backend handles:
 *   - MikroTik binary API protocol (TCP 8728/8729)
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
    } catch (error) {
      if (error instanceof ApiError && error.status === 404) return null;
      throw error;
    }
  }

  async getStatus(routerId: string): Promise<RouterStatusInfo | null> {
    return apiGet<RouterStatusInfo>(`/routers/${routerId}/status`);
  }

  async getInterfaces(routerId: string): Promise<RouterInterface[]> {
    return apiGet<RouterInterface[]>(`/routers/${routerId}/interfaces`);
  }

  async getClients(routerId: string): Promise<RouterClient[]> {
    return apiGet<RouterClient[]>(`/routers/${routerId}/clients`);
  }

  async getAllClients(): Promise<RouterClient[]> {
    return apiGet<RouterClient[]>("/clients");
  }

  async refreshAllClients(): Promise<RouterClient[]> {
    return apiGet<RouterClient[]>("/clients/refresh");
  }

  async getTraffic(routerId: string): Promise<RouterTraffic | null> {
    return apiGet<RouterTraffic>(`/routers/${routerId}/traffic`);
  }

  async getWireless(routerId: string): Promise<WirelessInfo | null> {
    return apiGet<WirelessInfo>(`/routers/${routerId}/wireless`);
  }

  async getMetrics(routerId: string): Promise<RouterMetric[]> {
    return apiGet<RouterMetric[]>(`/routers/${routerId}/metrics`);
  }

  async getDashboardSummary(): Promise<DashboardSummary> {
    return apiGet<DashboardSummary>("/dashboard");
  }

  async getIncidents(): Promise<Incident[]> {
    return apiGet<Incident[]>("/incidents");
  }

  async connect(routerId: string): Promise<RouterConnection> {
    return apiGet<RouterConnection>(`/routers/${routerId}/connect`);
  }

  async searchRouters(query: string): Promise<Router[]> {
    return apiGet<Router[]>(`/routers/search?q=${encodeURIComponent(query)}`);
  }
}
