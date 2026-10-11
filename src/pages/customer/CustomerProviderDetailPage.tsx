import { useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import { CheckCircle2 } from "lucide-react";
import { getCustomerPackages, getCustomerPaymentMethods, initiatePurchase } from "../../services/customerApi";
import type { Package } from "../../types/billing";
import { CustomerMobileShell } from "./CustomerMobileShell";

export function CustomerProviderDetailPage() {
  const { providerId } = useParams();
  const [packages, setPackages] = useState<Package[]>([]);
  const [selectedPackage, setSelectedPackage] = useState<Package | null>(null);
  const [loading, setLoading] = useState(true);
  const [message, setMessage] = useState<string | null>(null);
  const [provider, setProvider] = useState<"mpesa" | "pesapal" | "flutterwave" | "paystack">("mpesa");

  useEffect(() => {
    void (async () => {
      try {
        const data = await getCustomerPackages();
        setPackages(data);
        const match = data.find((item) => item.id === providerId) ?? null;
        setSelectedPackage(match);
      } finally {
        setLoading(false);
      }
    })();
  }, [providerId]);

  useEffect(() => {
    void (async () => {
      const methods = await getCustomerPaymentMethods().catch(() => [{ id: "mpesa", label: "M-Pesa" }]);
      if (methods.length > 0) {
        setProvider(methods[0].id as "mpesa" | "pesapal" | "flutterwave" | "paystack");
      }
    })();
  }, []);

  const handlePurchase = async () => {
    if (!selectedPackage) return;
    try {
      setMessage(null);
      const result = await initiatePurchase(selectedPackage.id, provider);
      if (result.checkout_url) {
        window.location.assign(result.checkout_url);
        return;
      }
      setMessage("Purchase initiated. Check your payment status in the payments screen.");
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Unable to start checkout.");
    }
  };

  const availablePlans = packages.filter((pkg) => pkg.access_type === selectedPackage?.access_type || !selectedPackage);

  return (
    <CustomerMobileShell title="Provider Details" showBack backTo="/customer/providers">
      <div className="space-y-4 pb-24">
        {loading ? (
          <div className="rounded-2xl border border-slate-800 bg-slate-900/80 p-4 text-sm text-slate-400">Loading package details…</div>
        ) : !selectedPackage ? (
          <div className="rounded-2xl border border-slate-800 bg-slate-900/80 p-4 text-sm text-slate-400">Package not found.</div>
        ) : (
          <>
            <div className="rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
              <div className="mb-3 flex items-center justify-between">
                <div>
                  <div className="text-xs uppercase tracking-[0.2em] text-slate-500">{selectedPackage.access_type}</div>
                  <h2 className="mt-2 text-2xl font-bold text-white">{selectedPackage.name}</h2>
                </div>
                <div className="rounded-full bg-green-500/10 px-2 py-1 text-xs font-medium text-green-300">Available</div>
              </div>

              <p className="text-sm text-slate-400">{selectedPackage.description || "Package details are not currently available."}</p>

              <div className="mt-4 grid grid-cols-2 gap-3 text-sm">
                <div className="rounded-2xl bg-slate-950/60 p-3">
                  <div className="text-slate-500">Price</div>
                  <div className="mt-1 font-semibold text-white">KSh {selectedPackage.price_ksh.toLocaleString()}</div>
                </div>
                <div className="rounded-2xl bg-slate-950/60 p-3">
                  <div className="text-slate-500">Billing period</div>
                  <div className="mt-1 font-semibold text-white">{selectedPackage.duration_display}</div>
                </div>
                <div className="rounded-2xl bg-slate-950/60 p-3">
                  <div className="text-slate-500">Download</div>
                  <div className="mt-1 font-semibold text-white">{selectedPackage.download_kbps > 0 ? `${selectedPackage.download_kbps / 1000} Mbps` : "Unlimited"}</div>
                </div>
                <div className="rounded-2xl bg-slate-950/60 p-3">
                  <div className="text-slate-500">Devices</div>
                  <div className="mt-1 font-semibold text-white">Up to {selectedPackage.max_devices}</div>
                </div>
              </div>
            </div>

            <div className="rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
              <div className="mb-2 text-sm font-medium text-white">Choose payment method</div>
              <select
                value={provider}
                onChange={(event) => setProvider(event.target.value as typeof provider)}
                className="w-full rounded-xl border border-slate-700 bg-slate-950/60 px-3 py-2.5 text-sm text-white outline-none"
              >
                <option value="mpesa">M-Pesa</option>
                <option value="pesapal">Pesapal</option>
                <option value="flutterwave">Flutterwave</option>
                <option value="paystack">Paystack</option>
              </select>

              <button type="button" onClick={handlePurchase} className="mt-4 w-full rounded-2xl bg-blue-500 px-4 py-3 text-sm font-semibold text-white">
                Activate subscription
              </button>

              {message && (
                <div className="mt-3 flex items-center gap-2 rounded-2xl border border-emerald-500/20 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300">
                  <CheckCircle2 className="h-4 w-4" />
                  {message}
                </div>
              )}
            </div>

            <div className="rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
              <div className="mb-3 text-base font-semibold text-white">Compare plans</div>
              <div className="space-y-3">
                {availablePlans.map((pkg) => (
                  <button
                    key={pkg.id}
                    type="button"
                    onClick={() => setSelectedPackage(pkg)}
                    className={`w-full rounded-2xl border p-3 text-left ${
                      pkg.id === selectedPackage.id ? "border-blue-500 bg-blue-500/10" : "border-slate-700 bg-slate-950/60"
                    }`}
                  >
                    <div className="flex items-center justify-between">
                      <div>
                        <div className="font-medium text-white">{pkg.name}</div>
                        <div className="text-xs text-slate-400">{pkg.duration_display}</div>
                      </div>
                      <div className="text-sm font-semibold text-white">KSh {pkg.price_ksh.toLocaleString()}</div>
                    </div>
                  </button>
                ))}
              </div>
            </div>
          </>
        )}
      </div>
    </CustomerMobileShell>
  );
}
