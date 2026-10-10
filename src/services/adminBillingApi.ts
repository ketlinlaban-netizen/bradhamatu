import { apiDelete, apiGet, apiPost, apiPut } from "./api";

export interface AdminPage<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
}

export interface RevenueReport {
  period: string;
  total_revenue_ksh: number;
  successful_payments: number;
  failed_payments: number;
  pending_payments: number;
  active_subscribers: number;
  expired_packages: number;
  activation_failures: number;
}

export interface AdminBillingPackage {
  id: string;
  name: string;
  price_ksh: number;
  duration_minutes: number;
  is_enabled: boolean;
}

export interface AdminPayment {
  id: string;
  state: string;
  amount_minor: number;
  provider_reference?: string | null;
  purchase?: { customer?: { full_name?: string; phone?: string } };
}

export async function getAdminPackages(): Promise<AdminBillingPackage[]> {
  const result = await apiGet<{ packages: AdminBillingPackage[] }>("/admin/packages");
  return result.packages;
}

export function createAdminPackage(payload: Record<string, unknown>): Promise<AdminBillingPackage> {
  return apiPost<AdminBillingPackage>("/admin/packages", payload);
}

export function updateAdminPackage(id: string, payload: Record<string, unknown>): Promise<AdminBillingPackage> {
  return apiPut<AdminBillingPackage>(`/admin/packages/${id}`, payload);
}

export function deleteAdminPackage(id: string): Promise<void> {
  return apiDelete<void>(`/admin/packages/${id}`);
}

export function getAdminCustomers(search?: string): Promise<AdminPage<Record<string, unknown>>> {
  return apiGet(`/admin/customers${search ? `?search=${encodeURIComponent(search)}` : ""}`);
}

export function getAdminCustomer(id: string): Promise<Record<string, unknown>> {
  return apiGet(`/admin/customers/${id}`);
}

export function getAdminPayments(state?: string): Promise<AdminPage<AdminPayment>> {
  return apiGet(`/admin/payments${state ? `?state=${encodeURIComponent(state)}` : ""}`);
}

export function getPendingAdminPayments(): Promise<AdminPage<AdminPayment>> {
  return apiGet("/admin/payments/pending");
}

export function getReconciliationQueue(): Promise<{ payments: AdminPayment[] }> {
  return apiGet("/admin/payments/reconciliation");
}

export function reconcileAdminPayment(id: string): Promise<{ payment: AdminPayment }> {
  return apiPost(`/admin/payments/${id}/reconcile`);
}

export function retryAdminActivation(id: string): Promise<Record<string, unknown>> {
  return apiPost(`/admin/service-accounts/${id}/retry-activation`);
}

export function getRevenueReport(period: "today" | "week" | "month" | "all" = "today"): Promise<RevenueReport> {
  return apiGet(`/admin/reports/revenue?period=${period}`);
}

export function getAdminAuditLogs(action?: string): Promise<AdminPage<Record<string, unknown>>> {
  return apiGet(`/admin/audit-logs${action ? `?action=${encodeURIComponent(action)}` : ""}`);
}

export interface AdminVoucher {
  id: string;
  code: string;
  package_id: string;
  status: string;
  batch_id?: string;
  used_at?: string | null;
  package?: AdminBillingPackage;
}

export function getAdminVouchers(status?: string): Promise<AdminPage<AdminVoucher>> {
  return apiGet(`/admin/vouchers${status ? `?status=${encodeURIComponent(status)}` : ""}`);
}

export function generateVoucherBatch(packageId: string, quantity: number): Promise<{
  vouchers: AdminVoucher[];
  count: number;
}> {
  return apiPost("/admin/vouchers/generate", { package_id: packageId, quantity });
}

export interface SmsTemplate {
  welcome: string;
  payment_confirmation: string;
  expiry_reminder: string;
  expiry_notification: string;
  voucher_purchase: string;
  activation_success: string;
}

export function getSmsTemplates(): Promise<{ templates: SmsTemplate }> {
  return apiGet("/admin/sms/templates");
}

export function getSmsSettings(): Promise<{
  sms_endpoint: string;
  sms_sender_id: string;
  sms_api_key_configured: boolean;
}> {
  return apiGet("/admin/sms/settings");
}

export function updateSmsSettings(data: {
  sms_endpoint?: string;
  sms_sender_id?: string;
  sms_api_key?: string;
}): Promise<{ success: boolean }> {
  return apiPut("/admin/sms/settings", data);
}

export function getSmsHistory(status?: string): Promise<AdminPage<Record<string, unknown>>> {
  return apiGet(`/admin/sms/history${status ? `?status=${encodeURIComponent(status)}` : ""}`);
}

export function getAdminSettings(): Promise<{ settings: Record<string, string> }> {
  return apiGet("/admin/settings");
}

export function updateAdminSettings(settings: Record<string, string>): Promise<{ success: boolean }> {
  return apiPut("/admin/settings", { settings });
}
