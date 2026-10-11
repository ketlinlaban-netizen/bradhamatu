import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { ArrowRight, BellRing, CheckCircle2, CreditCard, Globe2, Package2, TrendingUp, Wifi, Zap } from "lucide-react";
import { useCustomerAuth } from "../../auth/CustomerAuthContext";
import { getCustomerPackages, getPayments, getPurchases, getServiceStatus } from "../../services/customerApi";
import type { Package, Payment, Purchase, ServiceAccount } from "../../types/billing";
import { CustomerMobileShell } from "./CustomerMobileShell";

const quickLinks = [
  { label: "Providers", to: "/customer/providers", icon: Globe2, tone: "bg-blue-500/15 text-blue-300" },
  { label: "Hotspot", to: "/customer/hotspot", icon: Wifi, tone: "bg-emerald-500/15 text-emerald-300" },
  { label: "My Subscription", to: "/customer/subscription", icon: Package2, tone: "bg-violet-500/15 text-violet-300" },
  { label: "Payments", to: "/customer/payments", icon: CreditCard, tone: "bg-amber-500/15 text-amber-300" },
];

function formatDuration(value: string | null): string {
  if (!value) return "No expiry";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "No expiry";

  const diffMs = date.getTime() - Date.now();
  if (diffMs <= 0) return "Expired";

  const hours = Math.max(1, Math.floor(diffMs / (1000 * 60 * 60)));
  if (hours < 24) return `${hours}h remaining`;
  const days = Math.floor(hours / 24);
  return `${days}d remaining`;
}

export function CustomerDashboardPage() {
  const { customer } = useCustomerAuth();
  const [packages, setPackages] = useState<Package[]>([]);
  const [serviceStatus, setServiceStatus] = useState<{ active_package: ServiceAccount | null; next_due_at: string | null; last_expired: ServiceAccount | null }>({
    active_package: null,
    next_due_at: null,
    last_expired: null,
  });
  const [purchases, setPurchases] = useState<Purchase[]>([]);
  const [payments, setPayments] = useState<Payment[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    void (async () => {
      try {
        const [availablePackages, status, purchaseData, paymentData] = await Promise.all([
          getCustomerPackages(),
          getServiceStatus().catch(() => ({ active_package: null, next_due_at: null, last_expired: null })),
          getPurchases().catch(() => []),
          getPayments().catch(() => []),
        ]);

        setPackages(availablePackages);
        setServiceStatus(status);
        setPurchases(purchaseData);
        setPayments(paymentData);
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  const packageMap = useMemo(() => Object.fromEntries(packages.map((pkg) => [pkg.id, pkg])), [packages]);
  const activePlan = serviceStatus.active_package;
  const activePackageMeta = activePlan?.package_id ? packageMap[activePlan.package_id] : null;

  return (
    <CustomerMobileShell title="Home">
      <div className="space-y-4 pb-6">
        <div className="rounded-3xl bg-gradient-to-br from-blue-700 via-blue-600 to-cyan-500 p-4 shadow-xl shadow-blue-900/20">
          <div className="flex items-center justify-between">
            <div>
              <div className="text-xs uppercase tracking-[0.2em] text-blue-100/70">Connected</div>
              <h1 className="mt-2 text-2xl font-bold text-white">Hello, {customer?.full_name?.split(" ")[0] || "Customer"}</h1>
            </div>
            <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-white/10 text-white">
              <BellRing className="h-5 w-5" />
            </div>
          </div>

          <div className="mt-4 flex items-center justify-between rounded-2xl border border-white/10 bg-slate-950/10 px-3 py-2 text-sm text-blue-50">
            <div className="flex items-center gap-2">
              <span className="inline-block h-2.5 w-2.5 rounded-full bg-emerald-400" />
              {activePlan && activePlan.activation_status === "active" ? "Service active" : "Waiting for activation"}
            </div>
            <Link to="/customer/subscription" className="text-sm font-medium text-white/80">View status</Link>
          </div>
        </div>

        <div className="rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
          <div className="flex items-center justify-between">
            <div>
              <div className="text-xs uppercase tracking-[0.2em] text-slate-500">Active plan</div>
              <div className="mt-2 text-lg font-semibold text-white">{activePlan?.package_name || "No active subscription"}</div>
            </div>
            <div className="rounded-full border border-green-500/20 bg-green-500/10 px-2.5 py-1 text-xs font-medium text-green-300">
              {activePlan ? (activePlan.activation_status === "active" ? "Active" : activePlan.activation_status) : "Inactive"}
            </div>
          </div>

          <div className="mt-4 grid grid-cols-2 gap-3 text-sm text-slate-300">
            <div className="rounded-2xl bg-slate-950/60 p-3">
              <div className="text-slate-400">Speed</div>
              <div className="mt-1 font-semibold text-white">
                {activePackageMeta ? `${Math.max(10, Math.round((activePackageMeta.download_kbps || 0) / 1000))} Mbps` : "—"}
              </div>
            </div>
            <div className="rounded-2xl bg-slate-950/60 p-3">
              <div className="text-slate-400">Expiry</div>
              <div className="mt-1 font-semibold text-white">{formatDuration(activePlan?.expires_at || null)}</div>
            </div>
          </div>
        </div>

        <div className="rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
          <div className="flex items-center justify-between">
            <div>
              <div className="text-xs uppercase tracking-[0.2em] text-slate-500">Internet</div>
              <div className="mt-2 text-lg font-semibold text-white">Usage</div>
            </div>
            <div className="flex items-center gap-1 text-sm text-emerald-300">
              <TrendingUp className="h-4 w-4" />
              {activePackageMeta ? `${Math.min(100, Math.max(0, (activePackageMeta.download_kbps || 0) / 100))}%` : "0%"}
            </div>
          </div>

          <div className="mt-4 space-y-3">
            <div className="h-2.5 overflow-hidden rounded-full bg-slate-800">
              <div className="h-full w-0 rounded-full bg-gradient-to-r from-emerald-400 to-cyan-400" />
            </div>
            <div className="grid grid-cols-2 gap-3 text-sm">
              <div className="rounded-2xl bg-slate-950/60 p-3">
                <div className="text-slate-400">Data used</div>
                <div className="mt-1 font-semibold text-white">No live usage record</div>
              </div>
              <div className="rounded-2xl bg-slate-950/60 p-3">
                <div className="text-slate-400">Allowance</div>
                <div className="mt-1 font-semibold text-white">
                  {activePackageMeta ? `${Math.max(0, Math.round((activePackageMeta.download_kbps || 0) / 1000))} Mbps` : "Not assigned"}
                </div>
              </div>
            </div>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-3">
          {quickLinks.map(({ label, to, icon: Icon, tone }) => (
            <Link key={label} to={to} className="rounded-3xl border border-slate-800 bg-slate-900/80 p-4 text-left transition-colors hover:border-noc-accent/40">
              <div className={`mb-3 flex h-12 w-12 items-center justify-center rounded-2xl ${tone}`}>
                <Icon className="h-5 w-5" />
              </div>
              <div className="text-base font-semibold text-white">{label}</div>
              <div className="mt-1 text-xs text-slate-400">Open</div>
            </Link>
          ))}
        </div>

        <div className="rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
          <div className="flex items-center justify-between">
            <div>
              <div className="text-xs uppercase tracking-[0.2em] text-slate-500">Hotspot</div>
              <div className="mt-2 text-lg font-semibold text-white">Get online instantly</div>
            </div>
            <div className="rounded-full bg-cyan-500/10 p-2 text-cyan-300">
              <Zap className="h-4 w-4" />
            </div>
          </div>

          <div className="mt-4 flex items-center justify-between rounded-2xl bg-slate-950/60 p-3">
            <div>
              <div className="text-sm font-medium text-white">Community WiFi hotspot</div>
              <div className="text-xs text-slate-400">Purchase a hotspot package or voucher</div>
            </div>
            <Link to="/customer/hotspot" className="inline-flex items-center gap-1 rounded-full bg-blue-500 px-3 py-1.5 text-xs font-medium text-white">
              Open <ArrowRight className="h-3.5 w-3.5" />
            </Link>
          </div>
        </div>

        <div className="rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
          <div className="flex items-center justify-between">
            <div className="text-base font-semibold text-white">Recent payments</div>
            <Link to="/customer/payments" className="text-xs text-blue-300">View</Link>
          </div>
          {loading ? (
            <div className="mt-3 text-sm text-slate-400">Loading…</div>
          ) : payments.length === 0 ? (
            <div className="mt-3 rounded-2xl bg-slate-950/60 p-3 text-sm text-slate-400">No payment records yet.</div>
          ) : (
            <div className="mt-3 space-y-2">
              {payments.slice(0, 2).map((payment) => (
                <div key={payment.id} className="flex items-center justify-between rounded-2xl bg-slate-950/60 p-3 text-sm">
                  <div>
                    <div className="font-medium text-white">{payment.provider.toUpperCase()}</div>
                    <div className="text-xs text-slate-400">{payment.state}</div>
                  </div>
                  <div className="text-right">
                    <div className="font-medium text-white">KSh {payment.amount_ksh.toLocaleString()}</div>
                    <div className="text-xs text-slate-400">{payment.initiated_at ? new Date(payment.initiated_at).toLocaleDateString() : "—"}</div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
          <div className="flex items-center justify-between">
            <div className="text-base font-semibold text-white">Recent purchases</div>
            <Link to="/customer/subscription" className="text-xs text-blue-300">Manage</Link>
          </div>
          {purchases.length === 0 ? (
            <div className="mt-3 rounded-2xl bg-slate-950/60 p-3 text-sm text-slate-400">No purchases recorded yet.</div>
          ) : (
            <div className="mt-3 space-y-2">
              {purchases.slice(0, 2).map((purchase) => (
                <div key={purchase.id} className="flex items-center justify-between rounded-2xl bg-slate-950/60 p-3 text-sm">
                  <div>
                    <div className="font-medium text-white">{purchase.package_name}</div>
                    <div className="text-xs text-slate-400">{new Date(purchase.created_at).toLocaleDateString()}</div>
                  </div>
                  <div className="text-right">
                    <div className="font-medium text-white">KSh {purchase.amount_ksh.toLocaleString()}</div>
                    <div className="flex items-center gap-1 text-xs text-green-300">
                      <CheckCircle2 className="h-3.5 w-3.5" />
                      {purchase.status}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </CustomerMobileShell>
  );
}
