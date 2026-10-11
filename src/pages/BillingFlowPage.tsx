import { useEffect, useState, useCallback } from "react";
import {
  Activity, CreditCard, Database, Router as RouterIcon,
  CheckCircle2, Server, RefreshCw, AlertTriangle, Users,
  Package as PackageIcon, Ticket, Radio, ArrowRight,
  Loader2, XCircle,
} from "lucide-react";
import {
  getRevenueReport, getPendingAdminPayments, getReconciliationQueue,
  reconcileAdminPayment, retryAdminActivation, type RevenueReport,
  type AdminPayment,
} from "../services/adminBillingApi";
import { getRadiusStatus, syncRadiusNas, type RadiusStatus } from "../services/radiusApi";
import { apiGet } from "../services/api";

type Tab = "overview" | "payments" | "activations" | "radius";

export function BillingFlowPage() {
  const [tab, setTab] = useState<Tab>("overview");
  const [report, setReport] = useState<RevenueReport | null>(null);
  const [pendingPayments, setPendingPayments] = useState<AdminPayment[]>([]);
  const [reconciliationPayments, setReconciliationPayments] = useState<AdminPayment[]>([]);
  const [radiusStatus, setRadiusStatus] = useState<RadiusStatus | null>(null);
  const [loading, setLoading] = useState(true);
  const [actionLoading, setActionLoading] = useState<string | null>(null);
  const [toast, setToast] = useState<{ type: "success" | "error"; msg: string } | null>(null);

  const loadData = useCallback(async () => {
    setLoading(true);
    try {
      const [rpt, pending, recon] = await Promise.all([
        getRevenueReport(),
        getPendingAdminPayments(),
        getReconciliationQueue(),
      ]);
      setReport(rpt);
      setPendingPayments(pending.data ?? []);
      setReconciliationPayments(recon.payments ?? []);
      try {
        const rs = await getRadiusStatus();
        setRadiusStatus(rs);
      } catch {
        setRadiusStatus(null);
      }
    } catch {
      setReport(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { loadData(); }, [loadData]);

  const handleReconcile = async (id: string) => {
    setActionLoading(`reconcile-${id}`);
    try {
      await reconcileAdminPayment(id);
      setToast({ type: "success", msg: "Payment reconciled successfully" });
      await loadData();
    } catch (e) {
      setToast({ type: "error", msg: e instanceof Error ? e.message : "Reconciliation failed" });
    } finally {
      setActionLoading(null);
      setTimeout(() => setToast(null), 4000);
    }
  };

  const handleRetryActivation = async (id: string) => {
    setActionLoading(`retry-${id}`);
    try {
      await retryAdminActivation(id);
      setToast({ type: "success", msg: "Activation retry initiated" });
      await loadData();
    } catch (e) {
      setToast({ type: "error", msg: e instanceof Error ? e.message : "Retry failed" });
    } finally {
      setActionLoading(null);
      setTimeout(() => setToast(null), 4000);
    }
  };

  const pendingCount = pendingPayments.length;
  const reconCount = reconciliationPayments.length;

  const steps = [
    { title: "Customer request", detail: "Package selected and account verified", icon: Users, tone: "border-blue-400/30 bg-blue-400/10", metric: `${report?.active_subscribers ?? 0} active` },
    { title: "M-Pesa checkout", detail: "STK Push awaiting provider confirmation", icon: CreditCard, tone: "border-amber-400/30 bg-amber-400/10", metric: `${pendingCount} pending` },
    { title: "Payment ledger", detail: "Verified receipts and immutable references", icon: Database, tone: "border-cyan-400/30 bg-cyan-400/10", metric: `${report?.successful_payments ?? 0} verified` },
    { title: "Access activation", detail: "RADIUS or hotspot provisioning", icon: RouterIcon, tone: "border-emerald-400/30 bg-emerald-400/10", metric: `${report?.activation_failures ?? 0} failures` },
    { title: "Service live", detail: "Expiry and renewal scheduler tracking", icon: CheckCircle2, tone: "border-slate-400/30 bg-slate-400/10", metric: `${report?.active_subscribers ?? 0} live` },
  ];

  const tabs: { id: Tab; label: string; icon: typeof Activity }[] = [
    { id: "overview", label: "Overview", icon: Activity },
    { id: "payments", label: "Payments & Reconciliation", icon: CreditCard },
    { id: "activations", label: "Activations", icon: RouterIcon },
    { id: "radius", label: "RADIUS", icon: Radio },
  ];

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <div className="mb-2 flex items-center gap-2 text-xs uppercase tracking-[0.2em] text-cyan-400">
            <Activity className="h-4 w-4" /> Billing operations
          </div>
          <h1 className="text-2xl font-bold text-slate-100">Payment to service flow</h1>
          <p className="mt-1 max-w-2xl text-sm text-slate-400">Manage payments, activate services, and monitor RADIUS sessions in real time.</p>
        </div>
        <button onClick={loadData} disabled={loading} className="btn-secondary flex items-center gap-2 text-sm">
          <RefreshCw className={`w-4 h-4 ${loading ? "animate-spin" : ""}`} />
          Refresh
        </button>
      </div>

      {toast && (
        <div className={`card p-3 flex items-center gap-3 ${toast.type === "success" ? "border-green-500/30 bg-green-500/5" : "border-red-500/30 bg-red-500/5"}`}>
          {toast.type === "success" ? <CheckCircle2 className="w-4 h-4 text-green-400" /> : <XCircle className="w-4 h-4 text-red-400" />}
          <span className={`text-sm ${toast.type === "success" ? "text-green-300" : "text-red-300"}`}>{toast.msg}</span>
        </div>
      )}

      {/* Tabs */}
      <div className="flex gap-1 border-b border-noc-border overflow-x-auto">
        {tabs.map((t) => {
          const Icon = t.icon;
          return (
            <button
              key={t.id}
              onClick={() => setTab(t.id)}
              className={`flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 transition-colors whitespace-nowrap ${
                tab === t.id
                  ? "border-noc-accent text-noc-accent"
                  : "border-transparent text-slate-400 hover:text-slate-200"
              }`}
            >
              <Icon className="w-4 h-4" />
              {t.label}
            </button>
          );
        })}
      </div>

      {tab === "overview" && (
        <>
          <div className="card overflow-hidden p-5 md:p-7">
            <div className="mb-6 flex items-center justify-between">
              <div>
                <p className="text-sm font-semibold text-slate-200">Today's controlled pipeline</p>
                <p className="mt-1 text-xs text-slate-500">No access is granted before payment verification.</p>
              </div>
              <Server className="h-5 w-5 text-slate-500" />
            </div>
            <div className="flex flex-col gap-3 md:flex-row md:items-center md:gap-0">
              {steps.map((step, index) => {
                const Icon = step.icon;
                return (
                  <div key={step.title} className="contents">
                    <div className={`glass-pipeline-node ${step.tone}`}>
                      <div className="flex items-center gap-3">
                        <div className="rounded-xl border border-white/10 bg-slate-950/30 p-2">
                          <Icon className="h-5 w-5 text-slate-200" />
                        </div>
                        <div className="min-w-0">
                          <p className="text-sm font-semibold text-slate-100">{step.title}</p>
                          <p className="mt-1 text-[11px] leading-4 text-slate-400">{step.detail}</p>
                          <p className="mt-2 text-xs font-medium text-slate-300">{step.metric}</p>
                        </div>
                      </div>
                    </div>
                    {index < steps.length - 1 && (
                      <div className="relative hidden min-w-12 flex-1 md:block" aria-hidden="true">
                        <div className="h-2 rounded-full border border-slate-600/70 bg-slate-800/70 shadow-inner" />
                        <span className="flow-particle" />
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          </div>

          <div className="grid gap-4 md:grid-cols-3">
            <div className="stat-card">
              <p className="text-xs text-slate-500">Verified revenue today</p>
              <p className="mt-2 text-2xl font-bold text-slate-100">KSh {report?.total_revenue_ksh.toLocaleString() ?? "—"}</p>
              <p className="mt-1 text-xs text-emerald-400">Only successful payments counted</p>
            </div>
            <div className="stat-card">
              <p className="text-xs text-slate-500">Activation queue</p>
              <p className="mt-2 text-2xl font-bold text-slate-100">{pendingCount}</p>
              <button onClick={() => setTab("payments")} className="mt-1 text-xs text-amber-400 hover:text-amber-300 flex items-center gap-1">
                {reconCount} need reconciliation <ArrowRight className="w-3 h-3" />
              </button>
            </div>
            <div className="stat-card">
              <p className="text-xs text-slate-500">Active subscribers</p>
              <p className="mt-2 text-2xl font-bold text-slate-100">{report?.active_subscribers ?? "—"}</p>
              <p className="mt-1 text-xs text-cyan-400">Server-authoritative expiry</p>
            </div>
          </div>

          <div className="grid gap-4 md:grid-cols-2">
            <div className="card p-5">
              <div className="flex items-center gap-3 mb-3">
                <PackageIcon className="w-4 h-4 text-cyan-400" />
                <p className="text-sm font-semibold text-slate-200">Activation Failures</p>
              </div>
              <p className="text-3xl font-bold text-slate-100">{report?.activation_failures ?? 0}</p>
              <p className="mt-1 text-xs text-slate-500">
                {report && report.activation_failures > 0
                  ? "Retry failed activations from the Activations tab"
                  : "No activation failures"}
              </p>
              {report && report.activation_failures > 0 && (
                <button onClick={() => setTab("activations")} className="btn-secondary mt-3 text-xs flex items-center gap-1.5">
                  View activations <ArrowRight className="w-3 h-3" />
                </button>
              )}
            </div>
            <div className="card p-5">
              <div className="flex items-center gap-3 mb-3">
                <Radio className="w-4 h-4 text-emerald-400" />
                <p className="text-sm font-semibold text-slate-200">RADIUS Status</p>
              </div>
              <div className="flex items-center gap-2">
                <span className={`text-2xl font-bold ${radiusStatus?.enabled ? "text-emerald-400" : "text-slate-500"}`}>
                  {radiusStatus?.enabled ? "Enabled" : "Disabled"}
                </span>
              </div>
              <p className="mt-1 text-xs text-slate-500">
                {radiusStatus ? `${radiusStatus.active_sessions} active sessions on ${radiusStatus.nas_count} NAS` : "Not configured"}
              </p>
              {radiusStatus && (
                <button onClick={() => setTab("radius")} className="btn-secondary mt-3 text-xs flex items-center gap-1.5">
                  Manage RADIUS <ArrowRight className="w-3 h-3" />
                </button>
              )}
            </div>
          </div>
        </>
      )}

      {tab === "payments" && (
        <PaymentsTab
          reconciliationPayments={reconciliationPayments}
          pendingPayments={pendingPayments}
          actionLoading={actionLoading}
          onReconcile={handleReconcile}
        />
      )}

      {tab === "activations" && (
        <ActivationsTab
          report={report}
          onRetry={handleRetryActivation}
          actionLoading={actionLoading}
        />
      )}

      {tab === "radius" && <RadiusTab />}
    </div>
  );
}

// ── Payments Tab ─────────────────────────────────────────────────

function PaymentsTab({
  reconciliationPayments, pendingPayments, actionLoading, onReconcile,
}: {
  reconciliationPayments: AdminPayment[];
  pendingPayments: AdminPayment[];
  actionLoading: string | null;
  onReconcile: (id: string) => void;
}) {
  return (
    <div className="space-y-6">
      <div className="card overflow-hidden">
        <div className="px-5 py-4 border-b border-noc-border flex items-center gap-3">
          <AlertTriangle className="w-5 h-5 text-amber-400" />
          <div>
            <h2 className="text-sm font-semibold text-slate-100">Reconciliation Queue</h2>
            <p className="text-xs text-slate-500">Payments that need manual verification or callback reconciliation</p>
          </div>
        </div>
        {reconciliationPayments.length === 0 ? (
          <div className="p-8 text-center text-sm text-slate-500">All payments are reconciled. Nothing needs attention.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-xs text-slate-500 uppercase tracking-wider border-b border-noc-border">
                  <th className="px-5 py-3 font-medium">Customer</th>
                  <th className="px-5 py-3 font-medium">Amount</th>
                  <th className="px-5 py-3 font-medium">State</th>
                  <th className="px-5 py-3 font-medium">Reference</th>
                  <th className="px-5 py-3 font-medium">Action</th>
                </tr>
              </thead>
              <tbody>
                {reconciliationPayments.map((p) => (
                  <tr key={p.id} className="border-b border-noc-border/50 last:border-0 hover:bg-noc-hover/30">
                    <td className="px-5 py-3 text-slate-300">
                      {p.purchase?.customer?.full_name ?? "Unknown"}
                      <div className="text-xs text-slate-500">{p.purchase?.customer?.phone}</div>
                    </td>
                    <td className="px-5 py-3 font-mono text-slate-200">KSh {(p.amount_minor / 100).toLocaleString()}</td>
                    <td className="px-5 py-3">
                      <span className={`text-xs font-medium px-2 py-0.5 rounded ${p.state === "pending" ? "bg-amber-500/10 text-amber-400" : "bg-red-500/10 text-red-400"}`}>
                        {p.state}
                      </span>
                    </td>
                    <td className="px-5 py-3 font-mono text-xs text-slate-500">{p.provider_reference ?? "—"}</td>
                    <td className="px-5 py-3">
                      <button
                        onClick={() => onReconcile(p.id)}
                        disabled={actionLoading === `reconcile-${p.id}`}
                        className="btn-primary text-xs flex items-center gap-1.5"
                      >
                        {actionLoading === `reconcile-${p.id}` ? <Loader2 className="w-3 h-3 animate-spin" /> : <RefreshCw className="w-3 h-3" />}
                        Reconcile
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <div className="card overflow-hidden">
        <div className="px-5 py-4 border-b border-noc-border flex items-center gap-3">
          <CreditCard className="w-5 h-5 text-slate-400" />
          <div>
            <h2 className="text-sm font-semibold text-slate-100">Pending Payments</h2>
            <p className="text-xs text-slate-500">Awaiting M-Pesa STK Push confirmation</p>
          </div>
        </div>
        {pendingPayments.length === 0 ? (
          <div className="p-8 text-center text-sm text-slate-500">No pending payments.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-xs text-slate-500 uppercase tracking-wider border-b border-noc-border">
                  <th className="px-5 py-3 font-medium">Customer</th>
                  <th className="px-5 py-3 font-medium">Amount</th>
                  <th className="px-5 py-3 font-medium">Reference</th>
                  <th className="px-5 py-3 font-medium">Action</th>
                </tr>
              </thead>
              <tbody>
                {pendingPayments.map((p) => (
                  <tr key={p.id} className="border-b border-noc-border/50 last:border-0 hover:bg-noc-hover/30">
                    <td className="px-5 py-3 text-slate-300">
                      {p.purchase?.customer?.full_name ?? "Unknown"}
                      <div className="text-xs text-slate-500">{p.purchase?.customer?.phone}</div>
                    </td>
                    <td className="px-5 py-3 font-mono text-slate-200">KSh {(p.amount_minor / 100).toLocaleString()}</td>
                    <td className="px-5 py-3 font-mono text-xs text-slate-500">{p.provider_reference ?? "—"}</td>
                    <td className="px-5 py-3">
                      <button
                        onClick={() => onReconcile(p.id)}
                        disabled={actionLoading === `reconcile-${p.id}`}
                        className="btn-secondary text-xs flex items-center gap-1.5"
                      >
                        {actionLoading === `reconcile-${p.id}` ? <Loader2 className="w-3 h-3 animate-spin" /> : <RefreshCw className="w-3 h-3" />}
                        Check Status
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}

// ── Activations Tab ──────────────────────────────────────────────

function ActivationsTab({
  report, onRetry, actionLoading,
}: {
  report: RevenueReport | null;
  onRetry: (id: string) => void;
  actionLoading: string | null;
}) {
  const [failedAccounts, setFailedAccounts] = useState<Array<{ id: string; username: string; deactivation_reason: string | null; customer_name?: string }>>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    // Fetch failed service accounts via admin API
    (async () => {
      try {
        const data = await apiGet<{ data: Array<{ id: string; username: string; deactivation_reason: string | null; customer?: { full_name: string } }> }>("/admin/customers?filter=failed");
        setFailedAccounts((data.data ?? []).map((a) => ({
          id: a.id,
          username: a.username,
          deactivation_reason: a.deactivation_reason,
          customer_name: a.customer?.full_name,
        })));
      } catch {
        // Fallback: show summary from report
        setFailedAccounts([]);
      } finally {
        setLoading(false);
      }
    })();
  }, [report]);

  return (
    <div className="space-y-6">
      <div className="grid gap-4 md:grid-cols-4">
        <div className="stat-card">
          <p className="text-xs text-slate-500">Active</p>
          <p className="mt-1 text-2xl font-bold text-emerald-400">{report?.active_subscribers ?? 0}</p>
        </div>
        <div className="stat-card">
          <p className="text-xs text-slate-500">Expired</p>
          <p className="mt-1 text-2xl font-bold text-slate-300">{report?.expired_packages ?? 0}</p>
        </div>
        <div className="stat-card">
          <p className="text-xs text-slate-500">Failed</p>
          <p className="mt-1 text-2xl font-bold text-red-400">{report?.activation_failures ?? 0}</p>
        </div>
        <div className="stat-card">
          <p className="text-xs text-slate-500">Pending Payments</p>
          <p className="mt-1 text-2xl font-bold text-amber-400">{report?.pending_payments ?? 0}</p>
        </div>
      </div>

      <div className="card overflow-hidden">
        <div className="px-5 py-4 border-b border-noc-border flex items-center gap-3">
          <RouterIcon className="w-5 h-5 text-red-400" />
          <div>
            <h2 className="text-sm font-semibold text-slate-100">Failed Activations</h2>
            <p className="text-xs text-slate-500">Retry provisioning for accounts that failed to activate</p>
          </div>
        </div>
        {loading ? (
          <div className="p-8 text-center text-sm text-slate-500">Loading failed activations...</div>
        ) : failedAccounts.length === 0 ? (
          <div className="p-8 text-center text-sm text-slate-500">
            {report && report.activation_failures > 0
              ? `${report.activation_failures} failed activations. Use the retry button for each customer from the Customers page.`
              : "No failed activations. All services are running smoothly."}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-xs text-slate-500 uppercase tracking-wider border-b border-noc-border">
                  <th className="px-5 py-3 font-medium">Customer</th>
                  <th className="px-5 py-3 font-medium">Username</th>
                  <th className="px-5 py-3 font-medium">Failure Reason</th>
                  <th className="px-5 py-3 font-medium">Action</th>
                </tr>
              </thead>
              <tbody>
                {failedAccounts.map((a) => (
                  <tr key={a.id} className="border-b border-noc-border/50 last:border-0 hover:bg-noc-hover/30">
                    <td className="px-5 py-3 text-slate-300">{a.customer_name ?? "—"}</td>
                    <td className="px-5 py-3 font-mono text-xs text-slate-400">{a.username}</td>
                    <td className="px-5 py-3 text-xs text-red-400">{a.deactivation_reason ?? "Unknown"}</td>
                    <td className="px-5 py-3">
                      <button
                        onClick={() => onRetry(a.id)}
                        disabled={actionLoading === `retry-${a.id}`}
                        className="btn-primary text-xs flex items-center gap-1.5"
                      >
                        {actionLoading === `retry-${a.id}` ? <Loader2 className="w-3 h-3 animate-spin" /> : <RefreshCw className="w-3 h-3" />}
                        Retry
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}

// ── RADIUS Tab ───────────────────────────────────────────────────

function RadiusTab() {
  const [status, setStatus] = useState<RadiusStatus | null>(null);
  const [loading, setLoading] = useState(true);
  const [syncing, setSyncing] = useState(false);
  const [toast, setToast] = useState<{ type: "success" | "error"; msg: string } | null>(null);

  useEffect(() => {
    getRadiusStatus()
      .then(setStatus)
      .catch(() => setStatus(null))
      .finally(() => setLoading(false));
  }, []);

  const handleSync = async () => {
    setSyncing(true);
    try {
      const result = await syncRadiusNas();
      setToast({ type: "success", msg: `Synced ${result.synced} NAS entries` });
      const s = await getRadiusStatus();
      setStatus(s);
    } catch (e) {
      setToast({ type: "error", msg: e instanceof Error ? e.message : "Sync failed" });
    } finally {
      setSyncing(false);
      setTimeout(() => setToast(null), 4000);
    }
  };

  if (loading) return <div className="card p-8 text-center text-sm text-slate-500">Loading RADIUS status...</div>;

  return (
    <div className="space-y-6">
      {toast && (
        <div className={`card p-3 flex items-center gap-3 ${toast.type === "success" ? "border-green-500/30 bg-green-500/5" : "border-red-500/30 bg-red-500/5"}`}>
          {toast.type === "success" ? <CheckCircle2 className="w-4 h-4 text-green-400" /> : <XCircle className="w-4 h-4 text-red-400" />}
          <span className={`text-sm ${toast.type === "success" ? "text-green-300" : "text-red-300"}`}>{toast.msg}</span>
        </div>
      )}

      <div className="card p-5">
        <div className="flex items-start justify-between">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-lg bg-emerald-500/10 flex items-center justify-center">
              <Radio className="w-5 h-5 text-emerald-400" />
            </div>
            <div>
              <h2 className="text-sm font-semibold text-slate-100">RADIUS Status</h2>
              <p className="text-xs text-slate-500">FreeRADIUS integration for centralized authentication</p>
            </div>
          </div>
          <span className={`text-xs font-medium px-3 py-1 rounded-full ${status?.enabled ? "bg-emerald-500/10 text-emerald-400 border border-emerald-500/20" : "bg-slate-700 text-slate-400"}`}>
            {status?.enabled ? "ENABLED" : "DISABLED"}
          </span>
        </div>
      </div>

      <div className="grid gap-4 md:grid-cols-4">
        <div className="stat-card">
          <p className="text-xs text-slate-500">NAS Clients</p>
          <p className="mt-1 text-2xl font-bold text-slate-100">{status?.nas_count ?? 0}</p>
        </div>
        <div className="stat-card">
          <p className="text-xs text-slate-500">Active Sessions</p>
          <p className="mt-1 text-2xl font-bold text-emerald-400">{status?.active_sessions ?? 0}</p>
        </div>
        <div className="stat-card">
          <p className="text-xs text-slate-500">Total Users</p>
          <p className="mt-1 text-2xl font-bold text-slate-100">{status?.total_users ?? 0}</p>
        </div>
        <div className="stat-card">
          <p className="text-xs text-slate-500">Bandwidth Groups</p>
          <p className="mt-1 text-2xl font-bold text-slate-100">{status?.total_groups ?? 0}</p>
        </div>
      </div>

      <div className="card p-5">
        <div className="flex items-center justify-between mb-4">
          <div>
            <h2 className="text-sm font-semibold text-slate-100">NAS Management</h2>
            <p className="text-xs text-slate-500">Register MikroTik routers as RADIUS clients</p>
          </div>
          <button onClick={handleSync} disabled={syncing} className="btn-primary text-sm flex items-center gap-2">
            {syncing ? <Loader2 className="w-4 h-4 animate-spin" /> : <RefreshCw className="w-4 h-4" />}
            Sync NAS from Routers
          </button>
        </div>
        <p className="text-xs text-slate-500">
          When enabled, all user provisioning goes through FreeRADIUS instead of direct MikroTik API calls.
          Configure FreeRADIUS to read from the same database using the guide in <code className="text-slate-400">docs/RADIUS_SETUP_GUIDE.md</code>.
        </p>
      </div>

      <div className="card p-5">
        <div className="flex items-center gap-3 mb-2">
          <Ticket className="w-4 h-4 text-cyan-400" />
          <h2 className="text-sm font-semibold text-slate-100">Quick Actions</h2>
        </div>
        <div className="grid gap-3 md:grid-cols-2">
          <a href="/admin/radius" className="block p-4 rounded-lg border border-noc-border bg-noc-panel hover:border-noc-accent/30 transition-colors">
            <div className="text-sm font-medium text-slate-200">RADIUS Management</div>
            <div className="text-xs text-slate-500 mt-1">View sessions, manage NAS, disconnect users, view accounting history</div>
          </a>
          <a href="/admin/packages" className="block p-4 rounded-lg border border-noc-border bg-noc-panel hover:border-noc-accent/30 transition-colors">
            <div className="text-sm font-medium text-slate-200">Package Management</div>
            <div className="text-xs text-slate-500 mt-1">Create and manage internet packages with bandwidth limits</div>
          </a>
          <a href="/admin/vouchers" className="block p-4 rounded-lg border border-noc-border bg-noc-panel hover:border-noc-accent/30 transition-colors">
            <div className="text-sm font-medium text-slate-200">Voucher Management</div>
            <div className="text-xs text-slate-500 mt-1">Generate and manage voucher codes for offline sales</div>
          </a>
          <a href="/admin/customers" className="block p-4 rounded-lg border border-noc-border bg-noc-panel hover:border-noc-accent/30 transition-colors">
            <div className="text-sm font-medium text-slate-200">Customer Management</div>
            <div className="text-xs text-slate-500 mt-1">View customers, their purchases, and service accounts</div>
          </a>
        </div>
      </div>
    </div>
  );
}
