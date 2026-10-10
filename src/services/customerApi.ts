import { apiGet, apiPost, setToken, clearToken } from "./api";
import type { Customer, Package, Purchase, Payment, ServiceAccount, SupportTicket } from "../types/billing";

const CUSTOMER_TOKEN_KEY = "bradha-matu-customer-token";
const CUSTOMER_USER_KEY = "bradha-matu-customer-auth";

export function getCustomerToken(): string | null {
  return localStorage.getItem(CUSTOMER_TOKEN_KEY);
}

export function setCustomerToken(token: string): void {
  localStorage.setItem(CUSTOMER_TOKEN_KEY, token);
  setToken(token);
}

export function clearCustomerToken(): void {
  localStorage.removeItem(CUSTOMER_TOKEN_KEY);
  localStorage.removeItem(CUSTOMER_USER_KEY);
  clearToken();
}

export async function customerRegister(data: {
  full_name: string;
  phone: string;
  email?: string;
  password: string;
  terms_accepted: boolean;
}): Promise<{ success: boolean; token?: string; customer?: Customer; error?: string }> {
  try {
    const result = await apiPost<{ success: boolean; token: string; customer: Customer }>(
      "/customer/register",
      data,
    );
    if (result.success && result.token) {
      setCustomerToken(result.token);
      localStorage.setItem(CUSTOMER_USER_KEY, JSON.stringify(result.customer));
      return { success: true, token: result.token, customer: result.customer };
    }
    return { success: false, error: "Registration failed" };
  } catch (err) {
    return { success: false, error: err instanceof Error ? err.message : "Registration failed" };
  }
}

export async function customerLogin(phone: string, password: string): Promise<{ success: boolean; error?: string }> {
  try {
    const result = await apiPost<{ success: boolean; token: string; customer: Customer }>(
      "/customer/login",
      { phone, password },
    );
    if (result.success && result.token) {
      setCustomerToken(result.token);
      localStorage.setItem(CUSTOMER_USER_KEY, JSON.stringify(result.customer));
      return { success: true };
    }
    return { success: false, error: "Login failed" };
  } catch (err) {
    return { success: false, error: err instanceof Error ? err.message : "Login failed" };
  }
}

export async function customerLogout(): Promise<void> {
  try {
    await apiPost("/customer/logout");
  } catch {
    // ignore
  }
  clearCustomerToken();
}

export async function getCustomerProfile(): Promise<Customer | null> {
  const stored = localStorage.getItem(CUSTOMER_USER_KEY);
  try {
    const profile = await apiGet<Customer>("/customer/me");
    localStorage.setItem(CUSTOMER_USER_KEY, JSON.stringify(profile));
    return profile;
  } catch {
    return stored ? (JSON.parse(stored) as Customer) : null;
  }
}

export async function getCustomerPackages(): Promise<Package[]> {
  const result = await apiGet<{ packages: Package[] }>("/customer/packages");
  return result.packages;
}

export async function getPublicPackages(): Promise<Package[]> {
  const result = await apiGet<{ packages: Package[] }>("/public/packages");
  return result.packages;
}

export async function getServiceStatus(): Promise<{ active_package: ServiceAccount | null; next_due_at: string | null; last_expired: ServiceAccount | null }> {
  return apiGet("/customer/service-status");
}

export async function getPurchases(): Promise<Purchase[]> {
  const result = await apiGet<{ purchases: Purchase[] }>("/customer/purchases");
  return result.purchases;
}

export async function getPayments(): Promise<Payment[]> {
  const result = await apiGet<{ payments: Payment[] }>("/customer/payments");
  return result.payments;
}

export async function initiatePurchase(packageId: string): Promise<{
  purchase: Purchase;
  payment: Payment;
  daraja_response: unknown;
}> {
  return apiPost("/customer/purchase", { package_id: packageId });
}

export async function getPaymentStatus(paymentId: string): Promise<Payment> {
  return apiGet(`/customer/payments/${paymentId}/status`);
}

export async function getSupportTickets(): Promise<SupportTicket[]> {
  const result = await apiGet<{ tickets: SupportTicket[] }>("/customer/support-tickets");
  return result.tickets;
}

export async function createSupportTicket(data: {
  subject: string;
  body: string;
  priority?: string;
}): Promise<SupportTicket> {
  const result = await apiPost<{ ticket: SupportTicket }>("/customer/support-tickets", data);
  return result.ticket;
}

export async function changePassword(currentPassword: string, newPassword: string): Promise<void> {
  await apiPost("/customer/change-password", {
    current_password: currentPassword,
    new_password: newPassword,
  });
}

export async function redeemVoucher(code: string): Promise<{ purchase: Purchase }> {
  return apiPost("/customer/vouchers/redeem", { code });
}

export async function getWalletBalance(): Promise<{
  balance_minor: number;
  balance_ksh: number;
  auto_renewal: boolean;
}> {
  return apiGet("/customer/wallet/balance");
}

export async function topUpWallet(amountMinor: number): Promise<{ transaction: unknown }> {
  return apiPost("/customer/wallet/topup", { amount_minor: amountMinor });
}

export async function toggleAutoRenewal(enabled: boolean): Promise<{ auto_renewal: boolean }> {
  return apiPost("/customer/wallet/auto-renewal", { enabled });
}

export async function getWalletTransactions(): Promise<unknown[]> {
  const result = await apiGet<{ data: unknown[] }>("/customer/wallet/transactions");
  return result.data ?? [];
}
