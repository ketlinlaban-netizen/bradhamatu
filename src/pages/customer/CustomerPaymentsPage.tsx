import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Download } from "lucide-react";
import { getPayments, getPurchases } from "../../services/customerApi";
import type { Payment, Purchase } from "../../types/billing";
import { CustomerMobileShell } from "./CustomerMobileShell";

export function CustomerPaymentsPage() {
  const [payments, setPayments] = useState<Payment[]>([]);
  const [purchases, setPurchases] = useState<Purchase[]>([]);

  useEffect(() => {
    void (async () => {
      const [paymentData, purchaseData] = await Promise.all([
        getPayments().catch(() => []),
        getPurchases().catch(() => []),
      ]);
      setPayments(paymentData);
      setPurchases(purchaseData);
    })();
  }, []);

  const handleDownloadReceipt = (purchase: Purchase) => {
    const content = JSON.stringify({
      invoice: purchase.invoice_number ?? purchase.id,
      package_name: purchase.package_name,
      amount_ksh: purchase.amount_ksh,
      status: purchase.status,
      created_at: purchase.created_at,
    }, null, 2);
    const blob = new Blob([content], { type: "application/json" });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = `receipt-${purchase.id}.json`;
    anchor.click();
    URL.revokeObjectURL(url);
  };

  return (
    <CustomerMobileShell title="Payments">
      <div className="space-y-4 pb-24">
        <div className="rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
          <div className="flex items-center justify-between">
            <div className="text-base font-semibold text-white">Transaction history</div>
            <div className="rounded-full bg-blue-500/10 px-2 py-1 text-xs font-medium text-blue-300">{payments.length}</div>
          </div>
          {payments.length === 0 ? (
            <div className="mt-3 rounded-2xl bg-slate-950/60 p-3 text-sm text-slate-400">No payment history yet.</div>
          ) : (
            <div className="mt-3 space-y-2">
              {payments.map((payment) => (
                <div key={payment.id} className="rounded-2xl bg-slate-950/60 p-3">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <div className="font-medium text-white">{payment.provider.toUpperCase()}</div>
                      <div className="text-xs text-slate-400">{payment.initiated_at ? new Date(payment.initiated_at).toLocaleDateString() : "—"}</div>
                    </div>
                    <div className="text-right">
                      <div className="font-medium text-white">KSh {payment.amount_ksh.toLocaleString()}</div>
                      <div className="text-xs text-slate-400">{payment.state}</div>
                    </div>
                  </div>

                  {payment.failure_reason && <div className="mt-2 text-xs text-amber-300">{payment.failure_reason}</div>}

                  <div className="mt-3 flex gap-2">
                    {payment.state === "failed" || payment.state === "cancelled" || payment.state === "timed_out" ? (
                      <Link to="/customer/providers" className="flex-1 rounded-xl bg-amber-500/10 px-3 py-2 text-center text-xs font-medium text-amber-300">
                        Retry purchase
                      </Link>
                    ) : null}
                    <button
                      type="button"
                      onClick={() => {
                        const purchase = purchases.find((item) => item.payment?.id === payment.id) ?? null;
                        if (purchase) handleDownloadReceipt(purchase);
                      }}
                      className="flex items-center justify-center gap-1 rounded-xl border border-slate-700 px-3 py-2 text-xs text-slate-200"
                    >
                      <Download className="h-3.5 w-3.5" />
                      Receipt
                    </button>
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
