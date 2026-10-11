import { useEffect, useMemo, useState } from "react";
import { Wifi, Ticket } from "lucide-react";
import { getCustomerPackages, getCustomerPaymentMethods, getServiceStatus, initiatePurchase, redeemVoucher } from "../../services/customerApi";
import type { Package, ServiceAccount } from "../../types/billing";
import { CustomerMobileShell } from "./CustomerMobileShell";

const mapDuration = (minutes: number) => {
  if (minutes <= 60) return "1 hour";
  if (minutes <= 1440) return "1 day";
  if (minutes <= 10080) return "1 week";
  return "1 month";
};

export function CustomerHotspotPage() {
  const [packages, setPackages] = useState<Package[]>([]);
  const [serviceStatus, setServiceStatus] = useState<{ active_package: ServiceAccount | null; next_due_at: string | null; last_expired: ServiceAccount | null }>({
    active_package: null,
    next_due_at: null,
    last_expired: null,
  });
  const [voucher, setVoucher] = useState("");
  const [paymentMethod, setPaymentMethod] = useState("mpesa");
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    void (async () => {
      const [data, status, methods] = await Promise.all([
        getCustomerPackages(),
        getServiceStatus().catch(() => ({ active_package: null, next_due_at: null, last_expired: null })),
        getCustomerPaymentMethods().catch(() => [{ id: "mpesa", label: "M-Pesa" }]),
      ]);
      setPackages(data.filter((pkg) => pkg.access_type === "hotspot" || pkg.name.toLowerCase().includes("hotspot")));
      setServiceStatus(status);
      if (methods[0]) setPaymentMethod(methods[0].id);
    })();
  }, []);

  const activeHotspot = useMemo(
    () => (serviceStatus.active_package && serviceStatus.active_package.access_type === "hotspot" ? serviceStatus.active_package : null),
    [serviceStatus],
  );

  const handleBuy = async (pkg: Package) => {
    try {
      setMessage(null);
      const result = await initiatePurchase(pkg.id, paymentMethod as "mpesa" | "pesapal" | "flutterwave" | "paystack");
      if (result.checkout_url) {
        window.location.assign(result.checkout_url);
        return;
      }
      setMessage("Hotspot purchase started. Confirm payment status in the payments screen.");
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Unable to begin purchase.");
    }
  };

  const handleVoucher = async () => {
    try {
      setMessage(null);
      const result = await redeemVoucher(voucher);
      setMessage(result.purchase ? "Voucher accepted and purchase updated." : "Voucher accepted.");
      setVoucher("");
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Voucher could not be validated.");
    }
  };

  return (
    <CustomerMobileShell title="Hotspot">
      <div className="space-y-4 pb-24">
        <div className="rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
          <div className="flex items-center justify-between">
            <div>
              <div className="text-xs uppercase tracking-[0.2em] text-slate-500">Session</div>
              <div className="mt-2 text-lg font-semibold text-white">{activeHotspot ? "Active hotspot session" : "No confirmed hotspot session"}</div>
            </div>
            <div className="rounded-full bg-cyan-500/10 p-2 text-cyan-300">
              <Wifi className="h-4 w-4" />
            </div>
          </div>

          <div className="mt-4 rounded-2xl bg-slate-950/60 p-3 text-sm text-slate-300">
            {activeHotspot ? (
              <div className="flex items-center justify-between">
                <span>Connected access is only shown when the backend confirms it.</span>
                <button type="button" className="rounded-full bg-green-500/10 px-2 py-1 text-xs text-green-300">Reconnect</button>
              </div>
            ) : (
              "Purchase a package or redeem a voucher to get access. No live network connection is assumed until provisioning confirms it."
            )}
          </div>
        </div>

        <div className="rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
          <div className="mb-3 flex items-center gap-2 text-sm font-medium text-white">
            <Ticket className="h-4 w-4 text-blue-300" />
            Voucher / passcode
          </div>
          <div className="flex gap-2">
            <input
              value={voucher}
              onChange={(event) => setVoucher(event.target.value)}
              placeholder="Enter voucher"
              className="w-full rounded-xl border border-slate-700 bg-slate-950/60 px-3 py-2.5 text-sm text-white outline-none"
            />
            <button type="button" onClick={handleVoucher} className="rounded-xl bg-blue-500 px-3 py-2 text-sm font-medium text-white">
              Redeem
            </button>
          </div>
        </div>

        <div className="rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
          <div className="mb-3 text-base font-semibold text-white">Hotspot packages</div>
          <div className="space-y-3">
            {packages.length === 0 ? (
              <div className="rounded-2xl bg-slate-950/60 p-3 text-sm text-slate-400">No hotspot packages are configured yet.</div>
            ) : (
              packages.map((pkg) => (
                <div key={pkg.id} className="rounded-2xl border border-slate-700 bg-slate-950/60 p-3">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <div className="font-medium text-white">{pkg.name}</div>
                      <div className="text-xs text-slate-400">{mapDuration(pkg.duration_minutes)}</div>
                    </div>
                    <div className="text-sm font-semibold text-white">KSh {pkg.price_ksh.toLocaleString()}</div>
                  </div>

                  <div className="mt-3 flex items-center justify-between">
                    <div className="text-xs text-slate-400">{pkg.duration_display}</div>
                    <div className="flex items-center gap-2">
                      <select
                        value={paymentMethod}
                        onChange={(event) => setPaymentMethod(event.target.value)}
                        className="rounded-lg border border-slate-700 bg-slate-900 px-2 py-1 text-xs text-white outline-none"
                      >
                        <option value="mpesa">M-Pesa</option>
                        <option value="pesapal">Pesapal</option>
                        <option value="flutterwave">Flutterwave</option>
                        <option value="paystack">Paystack</option>
                      </select>
                      <button type="button" onClick={() => handleBuy(pkg)} className="rounded-lg bg-emerald-500 px-2.5 py-1.5 text-xs font-medium text-white">
                        Buy now
                      </button>
                    </div>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>

        {message && (
          <div className="rounded-2xl border border-blue-500/20 bg-blue-500/10 px-3 py-2 text-sm text-blue-200">
            {message}
          </div>
        )}
      </div>
    </CustomerMobileShell>
  );
}
