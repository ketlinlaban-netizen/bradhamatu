import { apiDelete, apiGet, apiPost } from "./api";
import type { AdminPage } from "./adminBillingApi";

export interface RadiusStatus {
  enabled: boolean;
  configured: boolean;
  nas_count: number;
  active_sessions: number;
  total_users: number;
  total_groups: number;
}

export interface RadiusNas {
  id: number;
  nasname: string;
  shortname: string | null;
  type: string;
  ports: number;
  description: string | null;
  router?: { id: string; name: string; ip_address: string } | null;
}

export interface RadiusSession {
  radacctid: number;
  acctsessionid: string;
  acctuniqueid: string;
  username: string;
  nasipaddress: string;
  acctstarttime: string;
  acctstoptime: string | null;
  acctsessiontime: number;
  acctinputoctets: number;
  acctoutputoctets: number;
  framedipaddress: string;
  callingstationid: string;
  acctterminatecause?: string | null;
}

export interface RadiusGroup {
  groupname: string;
  user_count: number;
  reply_attributes: Array<{ id: number; attribute: string; op: string; value: string }>;
  check_attributes: Array<{ id: number; attribute: string; op: string; value: string }>;
}

export interface RadiusUserDataUsage {
  username: string;
  usage: {
    total_input_bytes: number;
    total_output_bytes: number;
    total_bytes: number;
    session_count: number;
  };
  recent_sessions: RadiusSession[];
}

export function getRadiusStatus(): Promise<RadiusStatus> {
  return apiGet("/admin/radius/status");
}

export function getRadiusNas(): Promise<AdminPage<RadiusNas>> {
  return apiGet("/admin/radius/nas");
}

export function createRadiusNas(data: {
  nasname: string;
  shortname?: string;
  secret: string;
  description?: string;
}): Promise<RadiusNas> {
  return apiPost("/admin/radius/nas", data);
}

export function deleteRadiusNas(id: number): Promise<void> {
  return apiDelete(`/admin/radius/nas/${id}`);
}

export function syncRadiusNas(): Promise<{ synced: number }> {
  return apiPost("/admin/radius/nas/sync");
}

export function getRadiusSessions(filter?: {
  username?: string;
  nasIp?: string;
}): Promise<AdminPage<RadiusSession>> {
  const params = new URLSearchParams();
  if (filter?.username) params.set("username", filter.username);
  if (filter?.nasIp) params.set("nas_ip", filter.nasIp);
  const qs = params.toString();
  return apiGet(`/admin/radius/sessions${qs ? `?${qs}` : ""}`);
}

export function disconnectRadiusSession(username: string): Promise<{ disconnected: number }> {
  return apiPost("/admin/radius/sessions/disconnect", { username });
}

export function getRadiusAccounting(filter?: {
  username?: string;
  nasIp?: string;
  active?: boolean;
}): Promise<AdminPage<RadiusSession>> {
  const params = new URLSearchParams();
  if (filter?.username) params.set("username", filter.username);
  if (filter?.nasIp) params.set("nas_ip", filter.nasIp);
  if (filter?.active) params.set("active", "true");
  const qs = params.toString();
  return apiGet(`/admin/radius/accounting${qs ? `?${qs}` : ""}`);
}

export function getRadiusUserDataUsage(username: string): Promise<RadiusUserDataUsage> {
  return apiGet(`/admin/radius/users/${encodeURIComponent(username)}/usage`);
}

export function getRadiusPostAuth(username?: string): Promise<AdminPage<Record<string, unknown>>> {
  return apiGet(`/admin/radius/post-auth${username ? `?username=${encodeURIComponent(username)}` : ""}`);
}

export function getRadiusGroups(): Promise<{ groups: RadiusGroup[] }> {
  return apiGet("/admin/radius/groups");
}
