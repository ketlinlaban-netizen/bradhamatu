import { useEffect, useState } from "react";
import { Activity, BarChart3, Clock3 } from "lucide-react";
import { getCustomerPackages, getServiceStatus } from "../../services/customerApi";
import type { Package, ServiceAccount } from "../../types/billing";
import { CustomerMobileShell } from "./CustomerMobileShell";

export function CustomerUsagePage() {
  const [packages, setPackages] = useState<Package[]>([]);
  const [serviceStatus, setServiceStatus] = useState<{ active_package: ServiceAccount | null; next_due_at: string | null; last_expired: ServiceAccount | null }>({
    active_package: null,
    next_due_at: null,
    last_expired: null,
  });

  useEffect(() => {
    void (async () => {
      const [data, status] = await Promise.all([
        getCustomerPackages(),
        getServiceStatus().catch(() => ({ active_package: null, next_due_at: null, last_expired: null })),
      ]);
      setPackages(data);
      setServiceStatus(status);
    })();
  }, []);

  const activePackage = serviceStatus.active_package;
  const packageInfo = packages.find((pkg) => pkg.id === activePackage?.package_id) ?? null;

  return (
    <CustomerMobileShell title="My Usage">
      <div className="space-y-4 pb-24">
        <div className="rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
          <div className="flex items-center justify-between">
            <div>
              <div className="text-xs uppercase tracking-[0.2em] text-slate-500">Current plan</div>
              <div className="mt-2 text-xl font-bold text-white">{activePackage?.package_name || "No active plan"}</div>
            </div>
            <div className="rounded-full bg-emerald-500/10 px-2 py-1 text-xs font-medium text-emerald-300">
              {packageInfo ? `${Math.round((packageInfo.download_kbps || 0) / 1000)} Mbps` : "—"}
            </div>
          </div>

          <div className="mt-4 rounded-2xl bg-slate-950/60 p-3">
            <div className="text-sm text-slate-400">Remaining data</div>
            <div className="mt-1 text-2xl font-bold text-white">{packageInfo ? `${Math.max(0, Math.round((packageInfo.download_kbps || 0) / 1000))} Mbps` : "Unavailable"}</div>
            <div className="mt-2 text-xs text-slate-400">No detailed live usage telemetry is available from the current backend records.</div>
          </div>
        </div>

        <div className="rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
          <div className="mb-3 flex items-center gap-2 text-base font-semibold text-white">
            <BarChart3 className="h-4 w-4 text-blue-300" />
            Usage breakdown
          </div>
          <div className="space-y-3 text-sm text-slate-300">
            <div className="rounded-2xl bg-slate-950/60 p-3">
              <div className="flex items-center justify-between">
                <span>Browsing</span>
                <span>Not tracked</span>
              </div>
            </div>
            <div className="rounded-2xl bg-slate-950/60 p-3">
              <div className="flex items-center justify-between">
                <span>Streaming</span>
                <span>Not tracked</span>
              </div>
            </div>
            <div className="rounded-2xl bg-slate-950/60 p-3">
              <div className="flex items-center justify-between">
                <span>Social media</span>
                <span>Not tracked</span>
              </div>
            </div>
          </div>
        </div>

        <div className="rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
          <div className="mb-3 flex items-center gap-2 text-base font-semibold text-white">
            <Clock3 className="h-4 w-4 text-purple-300" />
            Session history
          </div>
          <div className="rounded-2xl bg-slate-950/60 p-3 text-sm text-slate-400">
            Connection history is only visible after the network backend confirms a session. No connection logs are currently available.
          </div>
        </div>

        <div className="rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
          <div className="mb-3 flex items-center gap-2 text-base font-semibold text-white">
            <Activity className="h-4 w-4 text-emerald-300" />
            Connected devices
          </div>
          <div className="rounded-2xl bg-slate-950/60 p-3 text-sm text-slate-400">No device inventory is available from the current backend data source.</div>
        </div>
      </div>
    </CustomerMobileShell>
  );
}
