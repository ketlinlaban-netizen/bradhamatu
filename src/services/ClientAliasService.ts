import type { RouterClient } from "../types";
import { apiDelete, apiPatch } from "./api";

function aliasPath(client: RouterClient): string {
  return `/routers/${encodeURIComponent(client.routerId)}/clients/${encodeURIComponent(client.id)}/alias`;
}

export function saveClientAlias(client: RouterClient, alias: string): Promise<RouterClient> {
  return apiPatch<RouterClient>(aliasPath(client), { alias });
}

export function clearClientAlias(client: RouterClient): Promise<RouterClient> {
  return apiDelete<RouterClient>(aliasPath(client));
}
