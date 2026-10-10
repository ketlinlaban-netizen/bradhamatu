import { useEffect, useState, useCallback } from "react";
import { useNavigate, Link } from "react-router-dom";
import { Wifi, LogOut, Package as PackageIcon, Clock, CheckCircle, AlertCircle, Loader, CreditCard, Ticket, RefreshCw } from "lucide-react";
import { useCustomerAuth } from "../../auth/CustomerAuthContext";
import {
  getCustomerPackages,
  getServiceStatus,
  getPurchases,
  initiatePurchase,
  getPaymentStatus,
  createSupportTicket,
} from "../../services/customerApi";
import type { Package, Purchase, ServiceAccount } from "../../types/billing";

const STATE_STYLES: Record<string, { color: string; label: string }> = {
  pending: { color: "text-yellow-400 bg-yellow-500/10 border-yellow-500/20", label: "Payment Pending" },
  initiating: { color: "text-blue-400 bg-blue-500/10 border-blue-500/20", label: "Initiating Payment" },
  successful: { color: "text-green-400 bg-green-500/10 border-green-500/20", label: "Payment Successful" },
  failed: { color: "text-red-400 bg-red-500/10 border-red-500/20", label: "Payment Failed" },
  cancelled: { color: "text-slate-400 bg-slate-500/10 border-slate-500/20", label: "Payment Cancelled" },
  timed_out: { color: "text-orange-400 bg-orange-500/10 border-orange-500/20", label: "Payment Timed Out" },
  activation_pending: { color: "text-blue-400 bg-blue-500/10 border-blue-500/20", label: "Activation Pending" },
  activated: { color: "text-green-400 bg-green-500/10 border-green-500/20", label: "Internet Active" },
  activation_failed: { color: "text-red-400 bg-red-500/10 border-red-500/20", label: "Activation Failed" },
  created: { color: "text-slate-400 bg-slate-500/10 border-slate-500/20", label: "Created" },
  expired: { color: "text-slate-400 bg-slate-500/10 border-slate-500/20", label: "Package Expired" },
  reconciliation_required: { color: "text-orange-400 bg-orange-500/10 border-orange-500/20", label: "Reconciliation Required" },
};

function StateBadge({ state }: { state: string }) {
  const cfg = STATE_STYLES[state] || { color: "text-slate-400 bg-slate-500/10", label: state };
  return <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium border ${cfg.color}`}>{cfg.label}</span>;
}

function formatExpiry(expiresAt: string | null): string {
  if (!expiresAt) return "—";
  const d = new Date(expiresAt);
  const now = new Date();
  const diff = d.getTime() - now.getTime();
  if (diff <= 0) return "Expired";
  const hours = Math.floor(diff / 3600000);
  const mins = Math.floor((diff % 3600000) / 60000);
  if (hours > 24) return `${Math.floor(hours / 24)}d ${hours % 24}h remaining`;
  if (hours > 0) return `${hours}h ${mins}m remaining`;
  return `${mins}m remaining`;
}

export function CustomerDashboardPage() {
  const { customer, logout } = useCustomerAuth();
  const navigate = useNavigate();
  const [packages, setPackages] = useState<Package[]>([]);
  const [serviceStatus, setServiceStatus] = useState<{ active_package: ServiceAccount | null; next_due_at: string | null; last_expired: ServiceAccount | null }>({ active_package: null, next_due_at: null, last_expired: null });
  const [purchases, setPurchases] = useState<Purchase[]>([]);
  const [loading, setLoading] = useState(true);
  const [purchasing, setPurchasing] = useState(false);
  const [pendingPaymentId, setPendingPaymentId] = useState<string | null>(null);
  const [purchaseMsg, setPurchaseMsg] = useState<string | null>(null);
  const [showTicketForm, setShowTicketForm] = useState(false);
  const [ticketSubject, setTicketSubject] = useState("");
  const [ticketBody, setTicketBody] = useState("");
  const [ticketMsg, setTicketMsg] = useState<string | null>(null);

  const loadAll = useCallback(async () => {
    const [pkgs, status, purchs] = await Promise.all([
      getCustomerPackages(),
      getServiceStatus().catch(() => ({ active_package: null, next_due_at: null, last_expired: null })),
      getPurchases().catch(() => []),
    ]);
    setPackages(pkgs);
    setServiceStatus(status);
    setPurchases(purchs);
    setLoading(false);
  }, []);

  useEffect(() => {
    loadAll();
  }, [loadAll]);

  // Poll payment status
  useEffect(() => {
    if (!pendingPaymentId) return;
    let attempts = 0;
    const interval = setInterval(async () => {
      attempts++;
      if (attempts > 60) {
        clearInterval(interval);
        setPendingPaymentId(null);
        setPurchaseMsg("Payment status check timed out. Your payment may still be processing. Check your payment history for updates.");
        return;
      }
      try {
        const payment = await getPaymentStatus(pendingPaymentId);
        if (["successful", "failed", "cancelled", "timed_out", "reconciliation_required"].includes(payment.state)) {
          clearInterval(interval);
          setPendingPaymentId(null);
          if (payment.state === "successful") {
            setPurchaseMsg("Payment successful! Your internet is being activated.");
          } else {
            setPurchaseMsg(`Payment ${payment.state}. ${payment.failure_reason || ""}`);
          }
          loadAll();
        }
      } catch {
        // ignore polling errors
      }
    }, 3000);
    return () => clearInterval(interval);
  }, [pendingPaymentId, loadAll]);

  const handlePurchase = async (pkg: Package) => {
    setPurchaseMsg(null);
    setPurchasing(true);
    try {
      const result = await initiatePurchase(pkg.id);
      if (result.payment?.id) {
        setPendingPaymentId(result.payment.id);
        setPurchaseMsg("STK Push sent to your phone. Please enter your M-Pesa PIN to complete payment.");
      } else {
        setPurchaseMsg("Purchase initiated. Check your payment history for status.");
      }
      loadAll();
    } catch (err) {
      setPurchaseMsg(err instanceof Error ? err.message : "Purchase failed. Please try again.");
    }
    setPurchasing(false);
  };

  const handleTicketSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setTicketMsg(null);
    try {
      await createSupportTicket({ subject: ticketSubject, body: ticketBody });
      setTicketMsg("Support ticket submitted. We will contact you soon.");
      setTicketSubject("");
      setTicketBody("");
      setShowTicketForm(false);
    } catch (err) {
      setTicketMsg(err instanceof Error ? err.message : "Failed to submit ticket");
    }
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-noc-bg flex items-center justify-center">
        <div className="w-8 h-8 border-2 border-noc-border border-t-noc-accent rounded-full animate-spin" />
      </div>
    );
  }

  const activePkg = serviceStatus.active_package;
  const nextDueDate = serviceStatus.next_due_at ? new Date(serviceStatus.next_due_at) : null;

  return (
    <div className="min-h-screen bg-noc-bg">
      {/* Header */}
      <header className="glass border-b border-noc-border sticky top-0 z-30">
        <div className="max-w-4xl mx-auto px-4 py-3 flex items-center gap-3">
          <div className="w-9 h-9 rounded-lg bg-gradient-to-br from-noc-accent to-noc-accent2 flex items-center justify-center">
            <Wifi className="w-5 h-5 text-white" />
          </div>
          <div className="flex-1">
            <div className="text-sm font-bold text-slate-100">Customer Portal</div>
            <div className="text-xs text-slate-400">Community WiFi Bradha Matu</div>
          </div>
          <button onClick={() => { logout(); navigate("/"); }} className="flex items-center gap-1.5 text-xs text-slate-400 hover:text-red-400 transition-colors">
            <LogOut className="w-3.5 h-3.5" /> Logout
          </button>
        </div>
      </header>

      <div className="max-w-4xl mx-auto px-4 py-6 space-y-6">
        {/* Welcome */}
        <div>
          <h1 className="text-xl font-bold text-slate-100">Welcome, {customer?.full_name}</h1>
          <p className="text-sm text-slate-400">{customer?.phone}</p>
        </div>

        {/* Active Package */}
        {activePkg && (
          <div className="card p-5 border-green-500/20">
            <div className="flex items-start justify-between">
              <div>
                <div className="text-xs text-slate-500 mb-1">Current Active Package</div>
                <div className="flex items-center gap-2 mb-3">
                  <CheckCircle className="w-5 h-5 text-green-500" />
                  <StateBadge state={activePkg.activation_status} />
                </div>
                <div className="flex items-center gap-2 text-sm text-slate-300">
                  <Clock className="w-4 h-4 text-slate-500" />
                  {formatExpiry(activePkg.expires_at)}
                </div>
                <div className="mt-2 text-xs text-slate-500">
                  Next due date: {nextDueDate ? nextDueDate.toLocaleString() : "Not scheduled"}
                </div>
              </div>
              <div className="text-right">
                <div className="text-xs text-slate-500">{activePkg.package_name || "Current service"}</div>
                <div className="text-sm text-slate-300">{activePkg.activated_at ? new Date(activePkg.activated_at).toLocaleDateString() : "—"}</div>
                <button onClick={() => document.getElementById("available-packages")?.scrollIntoView({ behavior: "smooth" })} className="mt-3 text-xs text-noc-accent hover:text-blue-300">Renew or upgrade</button>
              </div>
            </div>
          </div>
        )}

        {/* Purchase message */}
        {purchaseMsg && (
          <div className={`p-4 rounded-lg border flex items-start gap-3 ${
            purchaseMsg.includes("successful") ? "bg-green-500/10 border-green-500/20 text-green-400" :
            purchaseMsg.includes("failed") || purchaseMsg.includes("Failed") ? "bg-red-500/10 border-red-500/20 text-red-400" :
            "bg-blue-500/10 border-blue-500/20 text-blue-400"
          }`}>
            {purchaseMsg.includes("successful") ? <CheckCircle className="w-5 h-5 flex-shrink-0" /> :
             purchaseMsg.includes("failed") || purchaseMsg.includes("Failed") ? <AlertCircle className="w-5 h-5 flex-shrink-0" /> :
             <Loader className="w-5 h-5 flex-shrink-0 animate-spin" />}
            <span className="text-sm">{purchaseMsg}</span>
          </div>
        )}

        {/* Pending payment notice */}
        {pendingPaymentId && (
          <div className="card p-4 border-blue-500/20 flex items-center gap-3">
            <Loader className="w-5 h-5 text-blue-400 animate-spin" />
            <div className="text-sm text-blue-400">
              Waiting for M-Pesa payment confirmation... Check your phone for the STK Push prompt.
            </div>
          </div>
        )}

        {/* Available Packages */}
        <div id="available-packages">
          <h2 className="text-lg font-semibold text-slate-100 mb-4 flex items-center gap-2">
            <PackageIcon className="w-5 h-5 text-noc-accent" /> Renew or Upgrade
          </h2>
          {packages.length === 0 ? (
            <div className="card p-8 text-center text-slate-500">No packages available at this time.</div>
          ) : (
            <div className="grid sm:grid-cols-2 gap-4">
              {packages.map((pkg) => (
                <div key={pkg.id} className="card p-5 flex flex-col hover:border-noc-accent/40 transition-all">
                  <div className="mb-3">
                    <h3 className="font-bold text-slate-100">{pkg.name}</h3>
                    {pkg.description && <p className="text-xs text-slate-400 mt-0.5">{pkg.description}</p>}
                  </div>
                  <div className="mb-3">
                    <span className="text-2xl font-bold text-noc-accent">KSh {pkg.price_ksh.toLocaleString()}</span>
                    <span className="text-xs text-slate-500 ml-1">/ {pkg.duration_display}</span>
                  </div>
                  <div className="text-xs text-slate-400 space-y-1 mb-4 flex-1">
                    <div>Download: {pkg.download_kbps > 0 ? `${pkg.download_kbps / 1000} Mbps` : "Unlimited"}</div>
                    <div>Upload: {pkg.upload_kbps > 0 ? `${pkg.upload_kbps / 1000} Mbps` : "Unlimited"}</div>
                    <div>Devices: {pkg.max_devices}</div>
                  </div>
                  <button
                    onClick={() => handlePurchase(pkg)}
                    disabled={purchasing || !!pendingPaymentId}
                    className="btn-primary w-full disabled:opacity-50 flex items-center justify-center gap-2"
                  >
                    <CreditCard className="w-4 h-4" />
                    {purchasing ? "Processing..." : activePkg && pkg.price_ksh > (activePkg.price_ksh || 0) ? "Upgrade via M-Pesa" : "Renew via M-Pesa"}
                  </button>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Purchase History */}
        <div>
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-lg font-semibold text-slate-100 flex items-center gap-2">
              <CreditCard className="w-5 h-5 text-noc-accent" /> Payment History
            </h2>
            <button onClick={loadAll} className="btn-ghost text-xs flex items-center gap-1">
              <RefreshCw className="w-3.5 h-3.5" /> Refresh
            </button>
          </div>
          {purchases.length === 0 ? (
            <div className="card p-8 text-center text-slate-500">No purchases yet. Buy a package above to get started.</div>
          ) : (
            <div className="space-y-2">
              {purchases.map((p) => (
                <div key={p.id} className="card p-4 flex items-center justify-between">
                  <div>
                    <div className="font-medium text-slate-200 text-sm">{p.package_name}</div>
                    <div className="text-xs text-slate-500 mt-0.5">
                      {new Date(p.created_at).toLocaleDateString()} &middot; {p.order_id}
                    </div>
                  </div>
                  <div className="flex items-center gap-3">
                    <span className="text-sm font-medium text-slate-300">KSh {p.amount_ksh.toLocaleString()}</span>
                    <StateBadge state={p.payment?.state || p.status} />
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Support Ticket */}
        <div>
          <h2 className="text-lg font-semibold text-slate-100 mb-4 flex items-center gap-2">
            <Ticket className="w-5 h-5 text-noc-accent" /> Support
          </h2>
          {showTicketForm ? (
            <form onSubmit={handleTicketSubmit} className="card p-5 space-y-3">
              <input
                type="text"
                value={ticketSubject}
                onChange={(e) => setTicketSubject(e.target.value)}
                placeholder="Subject"
                required
                className="input-field"
              />
              <textarea
                value={ticketBody}
                onChange={(e) => setTicketBody(e.target.value)}
                placeholder="Describe your issue..."
                required
                rows={4}
                className="input-field resize-none"
              />
              <div className="flex gap-2">
                <button type="submit" className="btn-primary">Submit Ticket</button>
                <button type="button" onClick={() => setShowTicketForm(false)} className="btn-secondary">Cancel</button>
              </div>
              {ticketMsg && <p className="text-sm text-green-400">{ticketMsg}</p>}
            </form>
          ) : (
            <button onClick={() => setShowTicketForm(true)} className="btn-secondary w-full">
              Submit a Support Ticket
            </button>
          )}
        </div>

        {/* Footer link */}
        <div className="text-center pt-4">
          <Link to="/" className="text-xs text-slate-500 hover:text-slate-300">Back to homepage</Link>
        </div>
      </div>
    </div>
  );
}
