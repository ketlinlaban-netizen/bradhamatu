import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { CheckCircle2, CreditCard, TimerReset } from "lucide-react";
import { getCustomerPackages, getPurchases, getServiceStatus } from "../../services/customerApi";
import type { Package, Purchase, ServiceAccount } from "../../types/billing";
import { CustomerMobileShell } from "./CustomerMobileShell";

export function CustomerSubscriptionPage() {
  const [packages, setPackages] = useState<Package[]>([]);
  const [serviceStatus, setServiceStatus] = useState<{ active_package: ServiceAccount | null; next_due_at: string | null; last_expired: ServiceAccount | null }>({
    active_package: null,
    next_due_at: null,
    last_expired: null,
  });
  const [purchases, setPurchases] = useState<Purchase[]>([]);

  useEffect(() => {
    void (async () => {
      const [availablePackages, status, purchaseData] = await Promise.all([
        getCustomerPackages(),
        getServiceStatus().catch(() => ({ active_package: null, next_due_at: null, last_expired: null })),
        getPurchases().catch(() => []),
      ]);
      setPackages(availablePackages);
      setServiceStatus(status);
      setPurchases(purchaseData);
    })();
  }, []);

  const activePackage = serviceStatus.active_package;
  const currentPackage = packages.find((pkg) => pkg.id === activePackage?.package_id) ?? null;

  return (
    <CustomerMobileShell title="My Subscription">
      <div className="space-y-4 pb-24">
        <div className="rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
          <div className="flex items-center justify-between">
            <div>
              <div className="text-xs uppercase tracking-[0.2em] text-slate-500">Current plan</div>
              <div className="mt-2 text-xl font-bold text-white">{activePackage?.package_name || "No active plan"}</div>
            </div>
            <div className="rounded-full border border-green-500/20 bg-green-500/10 px-2 py-1 text-xs font-medium text-green-300">
              {activePackage ? activePackage.activation_status : "Inactive"}
            </div>
          </div>

          <div className="mt-4 grid grid-cols-2 gap-3 text-sm">
            <div className="rounded-2xl bg-slate-950/60 p-3">
              <div className="text-slate-400">Speed</div>
              <div className="mt-1 font-semibold text-white">{currentPackage ? `${Math.round((currentPackage.download_kbps || 0) / 1000)} Mbps` : "—"}</div>
            </div>
            <div className="rounded-2xl bg-slate-950/60 p-3">
              <div className="text-slate-400">Price</div>
              <div className="mt-1 font-semibold text-white">{currentPackage ? `KSh ${currentPackage.price_ksh.toLocaleString()}` : "—"}</div>
            </div>
          </div>

          <div className="mt-4 flex gap-2">
            <Link to="/customer/providers" className="flex-1 rounded-xl bg-blue-500 px-3 py-2 text-center text-sm font-medium text-white">
              Manage Plan
            </Link>
            <Link to="/customer/payments" className="flex-1 rounded-xl border border-slate-700 bg-slate-950/60 px-3 py-2 text-center text-sm font-medium text-slate-200">
              Renew
            </Link>
          </div>
        </div>

        <div className="rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
          <div className="mb-3 flex items-center gap-2 text-base font-semibold text-white">
            <TimerReset className="h-4 w-4 text-blue-300" />
            Quick actions
          </div>
          <div className="space-y-2">
            <button type="button" className="flex w-full items-center justify-between rounded-2xl bg-slate-950/60 p-3 text-sm text-slate-200">
              <span>Upgrade</span>
              <CreditCard className="h-4 w-4 text-slate-400" />
            </button>
            <button type="button" className="flex w-full items-center justify-between rounded-2xl bg-slate-950/60 p-3 text-sm text-slate-200">
              <span>Pause or resume</span>
              <CheckCircle2 className="h-4 w-4 text-slate-400" />
            </button>
            <button type="button" className="flex w-full items-center justify-between rounded-2xl bg-slate-950/60 p-3 text-sm text-slate-200">
              <span>Cancel</span>
              <CreditCard className="h-4 w-4 text-slate-400" />
            </button>
          </div>
          <p className="mt-3 text-xs text-slate-400">Plan changes are processed only through the configured backend provisioning flow and verified payment status.</p>
        </div>

        <div className="rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
          <div className="mb-3 text-base font-semibold text-white">Previous purchases</div>
          {purchases.length === 0 ? (
            <div className="rounded-2xl bg-slate-950/60 p-3 text-sm text-slate-400">No prior purchases yet.</div>
          ) : (
            <div className="space-y-2">
              {purchases.slice(0, 4).map((purchase) => (
                <div key={purchase.id} className="flex items-center justify-between rounded-2xl bg-slate-950/60 p-3 text-sm">
                  <div>
                    <div className="font-medium text-white">{purchase.package_name}</div>
                    <div className="text-xs text-slate-400">{new Date(purchase.created_at).toLocaleDateString()}</div>
                  </div>
                  <div className="text-right">
                    <div className="font-medium text-white">KSh {purchase.amount_ksh.toLocaleString()}</div>
                    <div className="text-xs text-slate-400">{purchase.status}</div>
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
