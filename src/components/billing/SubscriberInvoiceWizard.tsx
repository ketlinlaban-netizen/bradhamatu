import { useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { AlertCircle, Check, ChevronLeft, ChevronRight, Loader2, Router, X } from "lucide-react";
import {
  getAdminCustomers,
  getAdminPackages,
  getRouterConfigs,
  getRouterDhcpLeases,
  getSubscriberIdentities,
  initiateInvoicePayment,
  issueWorkflowInvoice,
  quoteWorkflowInvoice,
  type AdminBillingPackage,
  type AdminCustomer,
  type AdminPage,
  type DhcpLeaseObservation,
  type SubscriberIdentity,
  type WorkflowInvoiceResponse,
  type WorkflowInvoiceQuote,
} from "../../services/adminBillingApi";
import { apiGet, apiPost } from "../../services/api";
import type { MikroTikConnectResult, MikroTikConfig } from "../../types";

type IdentityMode = "existing" | "dhcp" | "manual" | "none";

const emptyPage: AdminPage<AdminCustomer> = {
  data: [],
  current_page: 1,
  last_page: 1,
  total: 0,
};

function dateValue(date: Date): string {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

export function SubscriberInvoiceWizard({ onClose }: { onClose: () => void }) {
  const [step, setStep] = useState(1);
  const [routers, setRouters] = useState<MikroTikConfig[]>([]);
  const [provider, setProvider] = useState<"mock" | "mikrotik" | "rest" | "unavailable">("unavailable");
  const [showRouterForm, setShowRouterForm] = useState(false);
  const [savingRouter, setSavingRouter] = useState(false);
  const [routerForm, setRouterForm] = useState({
    name: "",
    host: "",
    apiPort: "8729",
    username: "",
    password: "",
    location: "",
    site: "",
  });
  const [packages, setPackages] = useState<AdminBillingPackage[]>([]);
  const [customers, setCustomers] = useState<AdminPage<AdminCustomer>>(emptyPage);
  const [customerSearch, setCustomerSearch] = useState("");
  const [customerId, setCustomerId] = useState("");
  const [newCustomer, setNewCustomer] = useState({
    full_name: "",
    phone: "",
    email: "",
    password: "",
    password_confirmation: "",
    location: "",
    notes: "",
  });
  const [routerId, setRouterId] = useState("");
  const [connectResult, setConnectResult] = useState<MikroTikConnectResult | null>(null);
  const [testingConnection, setTestingConnection] = useState(false);
  const [identityMode, setIdentityMode] = useState<IdentityMode>("none");
  const [identities, setIdentities] = useState<SubscriberIdentity[]>([]);
  const [identityId, setIdentityId] = useState("");
  const [leases, setLeases] = useState<DhcpLeaseObservation | null>(null);
  const [leaseToken, setLeaseToken] = useState("");
  const [friendlyName, setFriendlyName] = useState("");
  const [macAddress, setMacAddress] = useState("");
  const [ipAddress, setIpAddress] = useState("");
  const [packageId, setPackageId] = useState("");
  const [invoiceQuote, setInvoiceQuote] = useState<WorkflowInvoiceQuote | null>(null);
  const [loadingQuote, setLoadingQuote] = useState(false);
  const [periodStart, setPeriodStart] = useState(() => dateValue(new Date()));
  const [dueDate, setDueDate] = useState(() => dateValue(new Date()));
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [paying, setPaying] = useState(false);
  const [error, setError] = useState("");
  const [invoiceResult, setInvoiceResult] = useState<WorkflowInvoiceResponse | null>(null);
  const [paymentProvider, setPaymentProvider] = useState("mpesa");
  const [paymentEmail, setPaymentEmail] = useState("");
  const invoiceKey = useRef<{ fingerprint: string; key: string } | null>(null);
  const paymentKey = useRef<{ purchaseId: string; key: string } | null>(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    Promise.all([getRouterConfigs(), getAdminPackages()])
      .then(([nextRouters, nextPackages]) => {
        if (cancelled) return;
        setRouters(nextRouters);
        const enabledPackages = nextPackages.filter((item) => item.is_enabled);
        setPackages(enabledPackages);
        if (enabledPackages.length === 1) setPackageId(enabledPackages[0].id);
      })
      .catch((cause: unknown) => {
        if (!cancelled) setError(cause instanceof Error ? cause.message : "Unable to load routers and packages.");
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    apiGet<{ provider: "mock" | "mikrotik" | "rest" }>("/health")
      .then((health) => setProvider(health.provider))
      .catch(() => setProvider("unavailable"));
  }, []);

  useEffect(() => {
    setConnectResult(null);
    setLeases(null);
    setLeaseToken("");
    if (!routerId) return;
    getRouterDhcpLeases(routerId)
      .then(setLeases)
      .catch((cause: unknown) => {
        setLeases(null);
        setError(cause instanceof Error ? cause.message : "Unable to load DHCP observations.");
      });
  }, [routerId]);

  useEffect(() => {
    if (!customerId) {
      setIdentities([]);
      setIdentityId("");
      return;
    }
    getSubscriberIdentities(customerId)
      .then(({ data }) => setIdentities(data))
      .catch((cause: unknown) => setError(cause instanceof Error ? cause.message : "Unable to load subscriber identities."));
  }, [customerId]);

  useEffect(() => {
    if (!packageId || !periodStart) {
      setInvoiceQuote(null);
      return;
    }
    let current = true;
    setLoadingQuote(true);
    quoteWorkflowInvoice({ package_id: packageId, billing_period_start_at: periodStart })
      .then((quote) => {
        if (current) setInvoiceQuote(quote);
      })
      .catch((cause: unknown) => {
        if (current) {
          setInvoiceQuote(null);
          setError(cause instanceof Error ? cause.message : "Could not calculate the invoice amount.");
        }
      })
      .finally(() => {
        if (current) setLoadingQuote(false);
      });
    return () => {
      current = false;
    };
  }, [packageId, periodStart]);

  const selectedCustomer = customers.data.find((customer) => customer.id === customerId);
  const selectedRouter = routers.find((router) => router.id === routerId);
  const selectedPackage = packages.find((item) => item.id === packageId);
  const selectedIdentity = identities.find((identity) => identity.id === identityId);
  const selectedLease = leases?.leases.find((lease) => lease.leaseToken === leaseToken);
  const selectedConnectionStatus = identityMode === "existing"
    ? selectedIdentity?.connection_status ?? "unknown"
    : "unknown";
  const formPayload = useMemo(() => ({
    customer_id: customerId || undefined,
    customer: customerId ? undefined : {
      ...newCustomer,
      email: newCustomer.email || undefined,
      location: newCustomer.location || undefined,
      notes: newCustomer.notes || undefined,
    },
    package_id: packageId,
    router_id: routerId || undefined,
    identity_mode: identityMode,
    network_identity_id: identityMode === "existing" ? identityId : undefined,
    lease_token: identityMode === "dhcp" ? leaseToken : undefined,
    friendly_name: identityMode === "existing" ? undefined : friendlyName || undefined,
    mac_address: identityMode === "manual" ? macAddress || undefined : undefined,
    ip_address: identityMode === "manual" ? ipAddress || undefined : undefined,
    billing_period_start_at: periodStart,
    due_at: dueDate,
  }), [customerId, dueDate, friendlyName, identityId, identityMode, ipAddress, leaseToken, macAddress, newCustomer, packageId, periodStart, routerId]);

  const searchCustomers = async (event: FormEvent) => {
    event.preventDefault();
    setError("");
    try {
      setCustomers(await getAdminCustomers(customerSearch.trim()));
      setCustomerId("");
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Unable to search subscribers.");
    }
  };

  const testConnection = async () => {
    if (!routerId) return;
    setTestingConnection(true);
    setError("");
    try {
      setConnectResult(await apiPost<MikroTikConnectResult>(`/mikrotik-configs/${encodeURIComponent(routerId)}/connect`));
    } catch (cause) {
      setConnectResult(null);
      setError(cause instanceof Error ? cause.message : "Router connection test failed.");
    } finally {
      setTestingConnection(false);
    }
  };

  const saveRouter = async (event: FormEvent) => {
    event.preventDefault();
    setSavingRouter(true);
    setError("");
    try {
      const created = await apiPost<{ id: string }>("/mikrotik-configs", {
        name: routerForm.name.trim(),
        host: routerForm.host.trim(),
        ...(provider !== "rest" ? {
          apiPort: Number(routerForm.apiPort),
          useTls: routerForm.apiPort === "8729",
          verifyCert: true,
        } : {}),
        username: routerForm.username.trim(),
        password: routerForm.password,
        location: routerForm.location.trim() || null,
        site: routerForm.site.trim() || null,
      });
      setRouterId(created.id);
      setRouterForm({ name: "", host: "", apiPort: "8729", username: "", password: "", location: "", site: "" });
      setShowRouterForm(false);
      setConnectResult(null);
      try {
        setRouters(await getRouterConfigs());
      } catch (reloadError) {
        const detail = reloadError instanceof Error ? ` ${reloadError.message}` : "";
        setError(`Router was saved, but the updated list could not be loaded.${detail} Close and reopen this workflow to refresh.`);
      }
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Router configuration could not be saved.");
    } finally {
      setSavingRouter(false);
    }
  };

  const submitInvoice = async () => {
    const fingerprint = JSON.stringify(formPayload);
    if (invoiceKey.current?.fingerprint !== fingerprint) {
      invoiceKey.current = { fingerprint, key: crypto.randomUUID() };
    }
    setSaving(true);
    setError("");
    try {
      const result = await issueWorkflowInvoice({
        ...formPayload,
        idempotency_key: invoiceKey.current.key,
      });
      setInvoiceResult(result);
      paymentKey.current = null;
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Invoice could not be issued.");
    } finally {
      setSaving(false);
    }
  };

  const startPayment = async () => {
    if (!invoiceResult) return;
    const approved = window.confirm(`Start a ${paymentProvider.toUpperCase()} payment for invoice ${invoiceResult.invoice.invoice_number}? This sends a request to the selected payment provider.`);
    if (!approved) return;

    if (paymentKey.current?.purchaseId !== invoiceResult.purchase.id) {
      paymentKey.current = { purchaseId: invoiceResult.purchase.id, key: crypto.randomUUID() };
    }
    setPaying(true);
    setError("");
    try {
      const result = await initiateInvoicePayment(invoiceResult.purchase.id, {
        provider: paymentProvider,
        idempotency_key: paymentKey.current.key,
        email: paymentEmail || undefined,
      });
      setInvoiceResult((current) => current ? {
        ...current,
        purchase: {
          ...current.purchase,
          status: result.purchase.status,
          payments: [...current.purchase.payments.filter((payment) => payment.id !== result.payment.id), result.payment],
        },
      } : current);
      if (result.checkout_url) window.open(result.checkout_url, "_blank", "noopener,noreferrer");
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Payment request failed.");
    } finally {
      setPaying(false);
    }
  };

  const canContinue = step === 1
    ? Boolean(routerId)
    : step === 2
      ? Boolean(customerId || (newCustomer.full_name.trim() && newCustomer.phone.trim() && newCustomer.password.length >= 8 && newCustomer.password === newCustomer.password_confirmation))
      : step === 3
        ? identityMode === "none" || (identityMode === "existing" ? Boolean(identityId) : identityMode === "dhcp" ? Boolean(leaseToken && leases?.source === "dhcp") : Boolean(macAddress.trim() || ipAddress.trim()))
        : step === 4
          ? Boolean(packageId && periodStart && dueDate && invoiceQuote && !loadingQuote)
          : true;

  if (loading) {
    return <div className="fixed inset-0 z-50 grid place-items-center bg-slate-950/80 p-4"><div className="card flex items-center gap-3 p-6 text-slate-300"><Loader2 className="h-5 w-5 animate-spin" />Loading billing options…</div></div>;
  }

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-950/80 p-3 sm:p-6" role="dialog" aria-modal="true" aria-labelledby="subscriber-wizard-title">
      <div className="mx-auto my-6 max-w-4xl space-y-5">
        <section className="card overflow-hidden">
          <header className="flex items-start justify-between gap-4 border-b border-noc-border p-5 sm:p-6">
            <div>
              <p className="text-xs font-semibold uppercase tracking-widest text-cyan-300">Billing workflow · Step {step} of 5</p>
              <h2 id="subscriber-wizard-title" className="mt-2 text-xl font-semibold text-slate-100">{invoiceResult ? "Invoice review" : "Create subscriber invoice"}</h2>
              <p className="mt-1 text-sm text-slate-400">Router and customer identity, service period, invoice, and explicit payment action.</p>
            </div>
            <button className="btn-secondary !px-2 !py-2" onClick={onClose} aria-label="Close billing workflow"><X className="h-4 w-4" /></button>
          </header>

          {error && <div role="alert" className="mx-5 mt-4 flex gap-2 rounded-lg border border-red-500/20 bg-red-500/10 p-3 text-sm text-red-200 sm:mx-6"><AlertCircle className="h-4 w-4 shrink-0" />{error}</div>}

          {!invoiceResult && (
            <div className="space-y-5 p-5 sm:p-6">
              {step === 1 && (
                <div className="space-y-4">
                  <h3 className="font-semibold text-slate-100">1. Select or configure a router</h3>
                  <p className="text-sm text-slate-400">Saved routers retain encrypted credentials. Passwords are never returned to this workflow.</p>
                  <label className="block text-sm text-slate-300">Configured MikroTik router
                    <select className="input-field mt-1.5" value={routerId} onChange={(event) => setRouterId(event.target.value)}>
                      <option value="">Choose a router</option>
                      {routers.map((router) => <option key={router.id} value={router.id}>{router.name} · {router.host} · API {router.apiPort}{router.useTls ? " TLS" : " plaintext"}</option>)}
                    </select>
                  </label>
                  <div className="flex flex-wrap items-center gap-3">
                    <button type="button" className="btn-secondary flex items-center gap-2 text-sm" onClick={testConnection} disabled={!routerId || testingConnection || provider === "mock"}>
                      {testingConnection ? <Loader2 className="h-4 w-4 animate-spin" /> : <Router className="h-4 w-4" />}{provider === "mock" ? "Connection test unavailable in mock mode" : "Test read-only connection"}
                    </button>
                    <button type="button" className="btn-secondary text-sm" onClick={() => setShowRouterForm((visible) => !visible)}>
                      {showRouterForm ? "Cancel router setup" : "Add router here"}
                    </button>
                    <Link className="text-sm text-cyan-300 hover:text-cyan-200" to="/admin/mikrotik-config">Open router management</Link>
                  </div>
                  {provider === "mock" && <p className="text-xs text-amber-200">Mock mode is active: connection testing is disabled, and DHCP leases are demonstration data only.</p>}
                  {showRouterForm && (
                    <form onSubmit={saveRouter} className="space-y-4 rounded-xl border border-noc-border bg-slate-950/30 p-4">
                      <div>
                        <h4 className="font-medium text-slate-100">Add a configured router</h4>
                        <p className="mt-1 text-xs text-slate-400">Credentials are sent only to Laravel and stored encrypted. The saved password is never returned to the browser.</p>
                      </div>
                      <div className="grid gap-3 sm:grid-cols-2">
                        <label className="text-sm text-slate-300">Friendly router name<input required maxLength={255} className="input-field mt-1.5" value={routerForm.name} onChange={(event) => setRouterForm({ ...routerForm, name: event.target.value })} /></label>
                        <label className="text-sm text-slate-300">Management IP / hostname<input required maxLength={255} className="input-field mt-1.5 font-mono" value={routerForm.host} onChange={(event) => setRouterForm({ ...routerForm, host: event.target.value })} /></label>
                        <label className="text-sm text-slate-300">RouterOS API username<input required maxLength={255} autoComplete="username" className="input-field mt-1.5" value={routerForm.username} onChange={(event) => setRouterForm({ ...routerForm, username: event.target.value })} /></label>
                        <label className="text-sm text-slate-300">RouterOS API password<input required type="password" maxLength={255} autoComplete="new-password" className="input-field mt-1.5" value={routerForm.password} onChange={(event) => setRouterForm({ ...routerForm, password: event.target.value })} /></label>
                        {provider === "rest" ? (
                          <p className="sm:col-span-2 text-xs text-cyan-200">The server uses HTTPS REST. REST port and TLS certificate validation are configured server-side and cannot be weakened here.</p>
                        ) : (
                          <label className="text-sm text-slate-300">Binary API transport<select className="input-field mt-1.5" value={routerForm.apiPort} onChange={(event) => setRouterForm({ ...routerForm, apiPort: event.target.value })}><option value="8728">API · TCP 8728 · TLS off</option><option value="8729">API-SSL · TCP 8729 · TLS on</option></select></label>
                        )}
                        <label className="text-sm text-slate-300">Location<input maxLength={255} className="input-field mt-1.5" value={routerForm.location} onChange={(event) => setRouterForm({ ...routerForm, location: event.target.value })} /></label>
                        <label className="text-sm text-slate-300">Site<input maxLength={255} className="input-field mt-1.5" value={routerForm.site} onChange={(event) => setRouterForm({ ...routerForm, site: event.target.value })} /></label>
                      </div>
                      {provider === "unavailable" && <p role="alert" className="text-xs text-amber-200">The active router transport could not be verified. Saving will use the server-selected transport.</p>}
                      <button type="submit" disabled={savingRouter} className="btn-primary text-sm disabled:opacity-50">{savingRouter ? "Saving router…" : "Save router configuration"}</button>
                    </form>
                  )}
                  {connectResult && (
                    <div className={`rounded-lg border p-3 text-sm ${connectResult.success ? "border-emerald-500/20 bg-emerald-500/10 text-emerald-200" : "border-amber-500/20 bg-amber-500/10 text-amber-100"}`}>
                      <p className="font-medium">{connectResult.success ? "Read-only connection succeeded" : "Connection not established"}</p>
                      <p className="mt-1">{connectResult.message}</p>
                      {connectResult.router && <p className="mt-1 text-xs">RouterOS {connectResult.router.routerosVersion} · {connectResult.router.model}</p>}
                    </div>
                  )}
                  {!routers.length && <p className="text-sm text-amber-200">No routers are configured. Add one using the existing router-management page, then return here.</p>}
                </div>
              )}

              {step === 2 && (
                <div className="space-y-4">
                  <h3 className="font-semibold text-slate-100">2. Select or register a subscriber</h3>
                  <form className="flex gap-2" onSubmit={searchCustomers}>
                    <input className="input-field" value={customerSearch} onChange={(event) => setCustomerSearch(event.target.value)} placeholder="Search name, account, phone, email or location" />
                    <button type="submit" className="btn-secondary">Search</button>
                  </form>
                  {customers.data.length > 0 && (
                    <label className="block text-sm text-slate-300">Existing subscriber
                      <select className="input-field mt-1.5" value={customerId} onChange={(event) => setCustomerId(event.target.value)}>
                        <option value="">Register a new subscriber instead</option>
                        {customers.data.map((customer) => <option key={customer.id} value={customer.id}>{customer.account_number ?? "No account"} · {customer.full_name} · {customer.phone_display}</option>)}
                      </select>
                    </label>
                  )}
                  {!customerId && (
                    <div className="grid gap-3 sm:grid-cols-2">
                      <label className="text-sm text-slate-300">Full name<input className="input-field mt-1.5" required maxLength={100} value={newCustomer.full_name} onChange={(event) => setNewCustomer({ ...newCustomer, full_name: event.target.value })} /></label>
                      <label className="text-sm text-slate-300">Phone number<input className="input-field mt-1.5" required maxLength={32} value={newCustomer.phone} onChange={(event) => setNewCustomer({ ...newCustomer, phone: event.target.value })} /></label>
                      <label className="text-sm text-slate-300">Email<input type="email" className="input-field mt-1.5" value={newCustomer.email} onChange={(event) => setNewCustomer({ ...newCustomer, email: event.target.value })} /></label>
                      <label className="text-sm text-slate-300">Location<input className="input-field mt-1.5" maxLength={255} value={newCustomer.location} onChange={(event) => setNewCustomer({ ...newCustomer, location: event.target.value })} /></label>
                      <label className="text-sm text-slate-300">Portal password<input type="password" autoComplete="new-password" minLength={8} required className="input-field mt-1.5" value={newCustomer.password} onChange={(event) => setNewCustomer({ ...newCustomer, password: event.target.value })} /><span className="mt-1 block text-xs text-slate-500">Sent only in the create request; not saved in browser storage or returned.</span></label>
                      <label className="text-sm text-slate-300">Confirm portal password<input type="password" autoComplete="new-password" minLength={8} required className="input-field mt-1.5" value={newCustomer.password_confirmation} onChange={(event) => setNewCustomer({ ...newCustomer, password_confirmation: event.target.value })} /></label>
                      <label className="text-sm text-slate-300 sm:col-span-2">Notes<textarea className="input-field mt-1.5" rows={2} maxLength={2000} value={newCustomer.notes} onChange={(event) => setNewCustomer({ ...newCustomer, notes: event.target.value })} /></label>
                    </div>
                  )}
                </div>
              )}

              {step === 3 && (
                <div className="space-y-4">
                  <h3 className="font-semibold text-slate-100">3. Associate a customer router or observed identity</h3>
                  <label className="block text-sm text-slate-300">Identity source
                    <select className="input-field mt-1.5" value={identityMode} onChange={(event) => setIdentityMode(event.target.value as IdentityMode)}>
                      <option value="none">No current identity available</option>
                      {customerId && <option value="existing">Existing subscriber identity</option>}
                      <option value="dhcp">Observed DHCP lease</option>
                      <option value="manual">Record identity manually</option>
                    </select>
                  </label>
                  {identityMode === "existing" && <label className="block text-sm text-slate-300">Saved identity<select className="input-field mt-1.5" value={identityId} onChange={(event) => setIdentityId(event.target.value)}><option value="">Select identity</option>{identities.map((identity) => <option key={identity.id} value={identity.id}>{identity.friendly_name} · {identity.mac_address ?? "No MAC"} · {identity.ip_address ?? "No IP"}</option>)}</select></label>}
                  {identityMode === "dhcp" && (
                    <div className="space-y-3">
                      {!routerId && <p className="text-sm text-amber-200">Choose a router first.</p>}
                      {leases?.source === "mock" && <p className="rounded-lg border border-amber-500/20 bg-amber-500/10 p-3 text-sm text-amber-100">Mock/demo lease data is displayed for reference only and cannot be assigned as a real subscriber identity.</p>}
                      <label className="block text-sm text-slate-300">Freshly observed DHCP lease
                        <select className="input-field mt-1.5" value={leaseToken} onChange={(event) => { setLeaseToken(event.target.value); const lease = leases?.leases.find((item) => item.leaseToken === event.target.value); setFriendlyName(lease?.hostname ?? ""); setMacAddress(lease?.macAddress ?? ""); setIpAddress(lease?.ipAddress ?? ""); }}>
                          <option value="">Select a lease</option>
                          {leases?.source === "dhcp" && leases.leases.map((lease) => <option key={lease.leaseToken} value={lease.leaseToken}>{lease.hostname || "Unknown device"} · {lease.macAddress} · {lease.ipAddress} · {lease.leaseStatus}</option>)}
                        </select>
                      </label>
                      {leases?.source === "dhcp" && selectedLease && <p className="text-xs text-slate-500">Observed {new Date(leases.observedAt).toLocaleString()} · DHCP presence is not proof of internet connectivity or payment.</p>}
                      {leases?.source === "dhcp" && !leases.leases.length && <p className="text-sm text-slate-500">No current DHCP leases were returned by this router.</p>}
                      {selectedLease && <label className="block text-sm text-slate-300">Customer router name<input className="input-field mt-1.5" value={friendlyName} onChange={(event) => setFriendlyName(event.target.value)} placeholder="e.g. Joseph's Home Router" /></label>}
                    </div>
                  )}
                  {identityMode === "manual" && (
                    <div className="grid gap-3 sm:grid-cols-2">
                      <label className="text-sm text-slate-300 sm:col-span-2">Friendly device name<input className="input-field mt-1.5" value={friendlyName} onChange={(event) => setFriendlyName(event.target.value)} placeholder="e.g. Joseph's Home Router" /></label>
                      <label className="text-sm text-slate-300">Full MAC address<input className="input-field mt-1.5 font-mono" value={macAddress} onChange={(event) => setMacAddress(event.target.value)} placeholder="AA:BB:CC:DD:EE:FF" /></label>
                      <label className="text-sm text-slate-300">Assigned IP address<input className="input-field mt-1.5 font-mono" value={ipAddress} onChange={(event) => setIpAddress(event.target.value)} placeholder="192.168.1.25" /></label>
                    </div>
                  )}
                  {identityMode === "none" && <p className="text-sm text-slate-400">No individual device identity is available. Devices behind a customer router may not appear individually on the central MikroTik.</p>}
                  {identityMode !== "none" && <label className="block text-sm text-slate-300">Customer router friendly name<input className="input-field mt-1.5" value={friendlyName} onChange={(event) => setFriendlyName(event.target.value)} placeholder="e.g. Joseph's Home Router" /></label>}
                  {identityMode !== "none" && <p className="font-mono text-xs text-slate-400">MAC: {macAddress || selectedIdentity?.mac_address || "—"} · IP: {ipAddress || selectedIdentity?.ip_address || "—"}</p>}
                </div>
              )}

              {step === 4 && (
                <div className="space-y-4">
                  <h3 className="font-semibold text-slate-100">4. Configure the service and billing period</h3>
                  <label className="block text-sm text-slate-300">Enabled service package
                    <select className="input-field mt-1.5" value={packageId} onChange={(event) => setPackageId(event.target.value)}>
                      <option value="">Select a package</option>
                      {packages.map((item) => <option key={item.id} value={item.id}>{item.name} · KSh {item.price_ksh.toLocaleString()} · {item.duration_minutes} minutes</option>)}
                    </select>
                  </label>
                  <div className="grid gap-3 sm:grid-cols-2">
                    <label className="text-sm text-slate-300">Billing period starts<input type="date" required className="input-field mt-1.5" value={periodStart} onChange={(event) => setPeriodStart(event.target.value)} /></label>
                    <label className="text-sm text-slate-300">Invoice due date<input type="date" required className="input-field mt-1.5" value={dueDate} onChange={(event) => setDueDate(event.target.value)} /></label>
                  </div>
                  {selectedPackage && <p className="text-xs text-slate-500">Package duration: {durationLabel(selectedPackage.duration_minutes)}. Billing period ends {invoiceQuote ? new Date(invoiceQuote.billing_period_end_at).toLocaleDateString("en-KE", { dateStyle: "medium" }) : "—"}. Issuing an invoice does not activate or suspend network access.</p>}
                  {loadingQuote && <p role="status" className="text-sm text-slate-400">Calculating tax and amount due…</p>}
                  {invoiceQuote && selectedPackage && (
                    <dl className="grid gap-2 rounded-lg border border-noc-border bg-slate-950/30 p-4 text-sm sm:grid-cols-3">
                      <div><dt className="text-xs text-slate-500">Subtotal</dt><dd className="mt-1 text-slate-200">{invoiceQuote.currency} {(invoiceQuote.subtotal_minor / 100).toLocaleString()}</dd></div>
                      <div><dt className="text-xs text-slate-500">Tax ({invoiceQuote.tax_rate_percent}%)</dt><dd className="mt-1 text-slate-200">{invoiceQuote.currency} {(invoiceQuote.tax_minor / 100).toLocaleString()}{invoiceQuote.tax_inclusive ? " · included in listed price" : ""}</dd></div>
                      <div><dt className="text-xs text-slate-500">Amount due</dt><dd className="mt-1 font-semibold text-cyan-200">{invoiceQuote.currency} {(invoiceQuote.total_minor / 100).toLocaleString()}</dd></div>
                    </dl>
                  )}
                  {!packages.length && <p className="text-sm text-amber-200">No enabled packages are available. Configure a package in the Packages tab first.</p>}
                </div>
              )}

              {step === 5 && (
                <div className="space-y-4">
                  <h3 className="font-semibold text-slate-100">5. Review before issuing the invoice</h3>
                  <dl className="grid gap-3 rounded-xl border border-noc-border bg-slate-950/30 p-4 text-sm sm:grid-cols-2">
                    <div><dt className="text-xs text-slate-500">Subscriber / account</dt><dd className="mt-1 text-slate-200">{selectedCustomer ? `${selectedCustomer.full_name} · ${selectedCustomer.account_number ?? "Account assigned on issue"}` : `${newCustomer.full_name} · Account assigned on issue`}</dd></div>
                    <div><dt className="text-xs text-slate-500">Phone and location</dt><dd className="mt-1 text-slate-200">{selectedCustomer?.phone_display ?? newCustomer.phone} · {selectedCustomer?.location ?? (newCustomer.location || "Location not recorded")}</dd></div>
                    <div><dt className="text-xs text-slate-500">MikroTik router</dt><dd className="mt-1 text-slate-200">{selectedRouter ? `${selectedRouter.name} · ${selectedRouter.host}` : "Not selected"}</dd></div>
                    <div><dt className="text-xs text-slate-500">Customer router identity</dt><dd className="mt-1 text-slate-200">{identityMode === "none" ? "Not recorded" : friendlyName || selectedIdentity?.friendly_name || selectedLease?.hostname || "Customer router"}<br /><span className="font-mono text-xs text-slate-400">{macAddress || selectedIdentity?.mac_address || selectedLease?.macAddress || "—"} · {ipAddress || selectedIdentity?.ip_address || selectedLease?.ipAddress || "—"}</span></dd></div>
                    <div><dt className="text-xs text-slate-500">Package / billing</dt><dd className="mt-1 text-slate-200">{selectedPackage?.name ?? "Not selected"} · {invoiceQuote ? `${invoiceQuote.currency} ${(invoiceQuote.total_minor / 100).toLocaleString()} due` : "Amount unavailable"}<br />{periodStart} → {invoiceQuote ? new Date(invoiceQuote.billing_period_end_at).toLocaleDateString("en-KE", { dateStyle: "medium" }) : "—"} · Due {dueDate}</dd></div>
                    <div><dt className="text-xs text-slate-500">Network status</dt><dd className="mt-1 text-slate-200">{selectedConnectionStatus}{identityMode === "dhcp" ? " · DHCP lease presence does not confirm connectivity" : ""}</dd></div>
                  </dl>
                  <p className="text-xs text-slate-500">Payment is not initiated by issuing an invoice. No network access changes are performed here.</p>
                </div>
              )}

              <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-noc-border pt-4">
                <button type="button" className="btn-secondary flex items-center gap-2 text-sm" onClick={() => setStep((current) => Math.max(1, current - 1))} disabled={step === 1}><ChevronLeft className="h-4 w-4" />Back</button>
                {step < 5
                  ? <button type="button" className="btn-primary flex items-center gap-2 text-sm" disabled={!canContinue} onClick={() => { setError(""); setStep((current) => current + 1); }}>Continue<ChevronRight className="h-4 w-4" /></button>
                  : <button type="button" className="btn-primary flex items-center gap-2 text-sm disabled:opacity-50" disabled={!canContinue || saving} onClick={submitInvoice}>{saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Check className="h-4 w-4" />}{saving ? "Issuing…" : "Issue invoice"}</button>}
              </footer>
            </div>
          )}

          {invoiceResult && (
            <div className="space-y-5 p-5 sm:p-6">
              <div className="rounded-xl border border-emerald-500/20 bg-emerald-500/10 p-4">
                <p className="font-semibold text-emerald-200">Invoice {invoiceResult.invoice.invoice_number} {invoiceResult.created ? "issued" : "already exists"}</p>
                <p className="mt-1 text-sm text-emerald-100">{invoiceResult.customer.full_name} · {invoiceResult.customer.account_number} · {invoiceResult.purchase.order_id}</p>
              </div>
              <dl className="grid gap-3 rounded-xl border border-noc-border bg-slate-950/30 p-4 text-sm sm:grid-cols-2">
                <div><dt className="text-xs text-slate-500">Subscriber</dt><dd className="mt-1 text-slate-200">{invoiceResult.customer.full_name} · {invoiceResult.customer.account_number}<br />{invoiceResult.customer.phone_display} · {invoiceResult.customer.location || "Location not recorded"}</dd></div>
                <div><dt className="text-xs text-slate-500">Router identity</dt><dd className="mt-1 text-slate-200">{invoiceResult.identity?.friendly_name ?? "Not recorded"}<br /><span className="font-mono text-xs">{invoiceResult.identity?.mac_address ?? "—"} · {invoiceResult.identity?.ip_address ?? "—"}</span><br />Connection: {invoiceResult.identity?.connection_status ?? "unknown"}</dd></div>
                <div><dt className="text-xs text-slate-500">Invoice / billing period</dt><dd className="mt-1 text-slate-200">{invoiceResult.invoice.package?.name ?? "Package"} · {invoiceResult.invoice.currency} {invoiceResult.invoice.total.toLocaleString()}<br />{invoiceResult.invoice.billing_period_start_at ?? "—"} to {invoiceResult.invoice.billing_period_end_at ?? "—"}<br />Due {invoiceResult.invoice.due_at ?? "—"}</dd></div>
                <div><dt className="text-xs text-slate-500">Billing status / payment</dt><dd className="mt-1 text-slate-200">{invoiceResult.purchase.status} · {invoiceResult.purchase.payments.map((payment) => payment.state).join(", ") || "Unpaid"}</dd></div>
              </dl>
              {invoiceResult.purchase.payments.some((payment) => payment.state === "successful") ? (
                <p className="text-sm text-emerald-200">A verified successful payment is already recorded.</p>
              ) : (
                <div className="space-y-3 rounded-xl border border-noc-border p-4">
                  <div><h3 className="font-semibold text-slate-100">Optional: start a payment</h3><p className="mt-1 text-xs text-slate-500">This is a separate explicit provider request; invoice creation alone never charges a subscriber.</p></div>
                  <div className="grid gap-3 sm:grid-cols-2">
                    <label className="text-sm text-slate-300">Provider<select className="input-field mt-1.5" value={paymentProvider} onChange={(event) => setPaymentProvider(event.target.value)}><option value="mpesa">M-Pesa</option><option value="pesapal">Pesapal</option><option value="flutterwave">Flutterwave</option><option value="paystack">Paystack</option></select></label>
                    {paymentProvider !== "mpesa" && <label className="text-sm text-slate-300">Checkout email<input type="email" className="input-field mt-1.5" value={paymentEmail || invoiceResult.customer.email || ""} onChange={(event) => setPaymentEmail(event.target.value)} /></label>}
                  </div>
                  <button type="button" className="btn-secondary text-sm disabled:opacity-50" disabled={paying || invoiceResult.purchase.payments.some((payment) => ["pending", "initiating", "reconciliation_required"].includes(payment.state))} onClick={startPayment}>{paying ? "Contacting provider…" : "Start payment request"}</button>
                </div>
              )}
              <div className="flex justify-end gap-2 border-t border-noc-border pt-4">
                <button type="button" className="btn-secondary text-sm" onClick={onClose}>Close</button>
                <button type="button" className="btn-primary text-sm" onClick={() => { setInvoiceResult(null); setStep(1); invoiceKey.current = null; }}>Create another invoice</button>
              </div>
            </div>
          )}
        </section>
      </div>
    </div>
  );
}

function durationLabel(minutes: number): string {
  if (minutes % (24 * 60) === 0) return `${minutes / (24 * 60)} days`;
  if (minutes % 60 === 0) return `${minutes / 60} hours`;
  return `${minutes} minutes`;
}
