import { apiDelete, apiGet, apiPost, apiPut } from "./api";
import type { MikroTikConfig } from "../types";

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
  daily_revenue: Array<{
    date: string;
    revenue_ksh: number;
    successful_payments: number;
  }>;
}

export interface AdminBillingPackage {
  id: string;
  name: string;
  description: string | null;
  price_ksh: number;
  price_minor: number;
  duration_minutes: number;
  access_type: string | null;
  download_kbps: number | null;
  upload_kbps: number | null;
  quota_mb: number | null;
  max_devices: number | null;
  router_id: string | null;
  is_enabled: boolean;
  sort_order: number | null;
}

export interface AdminPayment {
  id: string;
  state: string;
  amount_minor: number;
  provider?: string;
  provider_reference?: string | null;
  initiated_at?: string | null;
  completed_at?: string | null;
  created_at?: string;
  purchase?: {
    order_id?: string;
    customer?: { full_name?: string; phone?: string; phone_display?: string; phone_normalized?: string };
  };
}

export interface AdminCustomer {
  id: string;
  account_number?: string;
  full_name: string;
  phone_display: string;
  email?: string | null;
  location?: string | null;
  status: string;
  created_at: string;
  purchases_count: number;
  service_accounts_count: number;
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

export interface AdminActivationFailure {
  id: string;
  activation_status: string;
  access_type: string;
  created_at: string;
  customer?: { full_name?: string; phone_display?: string };
  purchase?: { order_id?: string; amount_minor?: number };
}

export interface AdminInvoice {
  id: string;
  invoice_number: string;
  order_id: string;
  amount_minor: number;
  subtotal_minor: number;
  tax_minor: number;
  status: string;
  created_at: string;
  customer?: { full_name?: string; phone_display?: string; email?: string | null };
  package_snapshot?: { name?: string };
  billing_period_start_at?: string | null;
  billing_period_end_at?: string | null;
  due_at?: string | null;
  network_identity?: {
    friendly_name?: string;
    mac_address?: string | null;
    ip_address?: string | null;
    router?: { name?: string } | null;
  } | null;
}

export interface InvoiceDetails {
  invoice_number: string;
  date: string | null;
  company: { name: string; phone: string; email: string };
  customer: { name: string | null; phone: string | null; email: string | null };
  package: { name?: string; price_minor?: number; duration_minutes?: number } | null;
  subtotal: number;
  tax_rate: number;
  tax_amount: number;
  total: number;
  currency: string;
  status: string;
  billing_period_start_at?: string | null;
  billing_period_end_at?: string | null;
  due_at?: string | null;
  account_number?: string | null;
  location?: string | null;
  network_identity?: {
    friendly_name: string;
    mac_address: string | null;
    ip_address: string | null;
    router_name: string | null;
  } | null;
}

export interface DhcpLeaseObservation {
  routerId: string;
  routerName: string;
  source: "dhcp" | "mock";
  observedAt: string;
  leases: Array<{
    routerId: string;
    hostname: string | null;
    ipAddress: string;
    macAddress: string;
    interfaceName: string | null;
    leaseStatus: string;
    leaseToken: string;
  }>;
}

export interface SubscriberIdentity {
  id: string;
  router_id: string | null;
  router_name: string | null;
  router_host: string | null;
  friendly_name: string;
  mac_address: string | null;
  ip_address: string | null;
  source: string;
  observed_at: string | null;
  connection_status: string;
}

export interface WorkflowInvoiceResponse {
  created: boolean;
  customer: {
    id: string;
    account_number: string;
    full_name: string;
    phone_display: string;
    email: string | null;
    location: string | null;
    notes: string | null;
    status: string;
  };
  identity: SubscriberIdentity | null;
  invoice: InvoiceDetails;
  purchase: {
    id: string;
    order_id: string;
    status: string;
    amount_minor: number;
    invoice_number: string;
    due_at: string | null;
    billing_period_start_at: string | null;
    billing_period_end_at: string | null;
    payments: AdminPayment[];
  };
}

export interface WorkflowInvoiceQuote {
  subtotal_minor: number;
  tax_minor: number;
  total_minor: number;
  tax_rate_percent: number;
  tax_inclusive: boolean;
  currency: string;
  billing_period_end_at: string;
}

export function getRouterConfigs(): Promise<MikroTikConfig[]> {
  return apiGet("/mikrotik-configs");
}

export function getRouterDhcpLeases(routerId: string): Promise<DhcpLeaseObservation> {
  return apiGet(`/routers/${encodeURIComponent(routerId)}/dhcp-leases`);
}

export function getSubscriberIdentities(customerId: string): Promise<{ data: SubscriberIdentity[] }> {
  return apiGet(`/admin/customers/${encodeURIComponent(customerId)}/network-identities`);
}

export function issueWorkflowInvoice(payload: Record<string, unknown>): Promise<WorkflowInvoiceResponse> {
  return apiPost("/admin/workflow/invoices", payload);
}

export function quoteWorkflowInvoice(payload: {
  package_id: string;
  billing_period_start_at: string;
}): Promise<WorkflowInvoiceQuote> {
  return apiPost("/admin/workflow/quote", payload);
}

export function initiateInvoicePayment(
  purchaseId: string,
  payload: { provider: string; idempotency_key: string; email?: string },
): Promise<{
  purchase: { id: string; order_id: string; status: string };
  payment: AdminPayment;
  checkout_url: string | null;
  reused: boolean;
}> {
  return apiPost(`/admin/invoices/${encodeURIComponent(purchaseId)}/payment`, payload);
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

export function getAdminCustomers(search?: string, page = 1): Promise<AdminPage<AdminCustomer>> {
  const params = new URLSearchParams();
  if (search) params.set("search", search);
  if (page > 1) params.set("page", String(page));
  return apiGet(`/admin/customers${params.size ? `?${params}` : ""}`);
}

export function getAdminCustomer(id: string): Promise<Record<string, unknown>> {
  return apiGet(`/admin/customers/${id}`);
}

export function getAdminPayments(state?: string, page = 1): Promise<AdminPage<AdminPayment>> {
  const params = new URLSearchParams();
  if (state) params.set("state", state);
  if (page > 1) params.set("page", String(page));
  return apiGet(`/admin/payments${params.size ? `?${params}` : ""}`);
}

export function getAdminInvoices(page = 1): Promise<AdminPage<AdminInvoice>> {
  return apiGet(`/admin/invoices${page > 1 ? `?page=${page}` : ""}`);
}

export function getAdminInvoice(id: string): Promise<InvoiceDetails> {
  return apiGet(`/admin/invoices/${id}`);
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

export function getActivationFailures(page = 1): Promise<AdminPage<AdminActivationFailure>> {
  return apiGet(`/admin/service-accounts/activation-failures${page > 1 ? `?page=${page}` : ""}`);
}

export function getRevenueReport(period: "today" | "week" | "month" | "all" = "today"): Promise<RevenueReport> {
  return apiGet(`/admin/reports/revenue?period=${period}`);
}

export function getAdminAuditLogs(action?: string): Promise<AdminPage<Record<string, unknown>>> {
  return apiGet(`/admin/audit-logs${action ? `?action=${encodeURIComponent(action)}` : ""}`);
}

export function getAdminVouchers(status?: string, page = 1): Promise<AdminPage<AdminVoucher>> {
  const params = new URLSearchParams();
  if (status) params.set("status", status);
  if (page > 1) params.set("page", String(page));
  return apiGet(`/admin/vouchers${params.size ? `?${params}` : ""}`);
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
