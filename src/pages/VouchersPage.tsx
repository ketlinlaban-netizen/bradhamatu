import { useEffect, useState, useCallback } from "react";
import { Ticket, Plus, Loader2, CheckCircle2, XCircle, Copy } from "lucide-react";
import {
  getAdminVouchers, generateVoucherBatch, getAdminPackages,
  type AdminVoucher, type AdminBillingPackage,
} from "../services/adminBillingApi";
import { LoadingSpinner, EmptyState } from "../components/ui";

export function VouchersPage() {
  const [vouchers, setVouchers] = useState<AdminVoucher[]>([]);
  const [packages, setPackages] = useState<AdminBillingPackage[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [selectedPackage, setSelectedPackage] = useState("");
  const [quantity, setQuantity] = useState(10);
  const [generating, setGenerating] = useState(false);
  const [statusFilter, setStatusFilter] = useState("");
  const [toast, setToast] = useState<{ type: "success" | "error"; msg: string } | null>(null);
  const [copiedCode, setCopiedCode] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [v, p] = await Promise.all([
        getAdminVouchers(statusFilter || undefined),
        getAdminPackages(),
      ]);
      setVouchers((v as unknown as { data: AdminVoucher[] }).data ?? (v as unknown as AdminVoucher[]));
      setPackages(p);
    } catch {
      setVouchers([]);
    } finally { setLoading(false); }
  }, [statusFilter]);

  useEffect(() => { load(); }, [load]);

  const handleGenerate = async (e: React.FormEvent) => {
    e.preventDefault();
    setGenerating(true);
    try {
      const result = await generateVoucherBatch(selectedPackage, quantity);
      setToast({ type: "success", msg: `Generated ${result.count} vouchers` });
      setShowForm(false);
      await load();
    } catch (e) {
      setToast({ type: "error", msg: e instanceof Error ? e.message : "Generation failed" });
    } finally {
      setGenerating(false);
      setTimeout(() => setToast(null), 4000);
    }
  };

  const copyCode = (code: string) => {
    navigator.clipboard.writeText(code);
    setCopiedCode(code);
    setTimeout(() => setCopiedCode(null), 2000);
  };

  if (loading) return <LoadingSpinner label="Loading vouchers..." />;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-100">Vouchers</h1>
          <p className="text-sm text-slate-400 mt-1">Generate voucher codes for offline sales and track redemption.</p>
        </div>
        <button onClick={() => setShowForm(!showForm)} className="btn-primary flex items-center gap-2 text-sm">
          {showForm ? <XCircle className="w-4 h-4" /> : <Plus className="w-4 h-4" />}
          {showForm ? "Cancel" : "Generate Vouchers"}
        </button>
      </div>

      {toast && (
        <div className={`card p-3 flex items-center gap-3 ${toast.type === "success" ? "border-green-500/30 bg-green-500/5" : "border-red-500/30 bg-red-500/5"}`}>
          {toast.type === "success" ? <CheckCircle2 className="w-4 h-4 text-green-400" /> : <XCircle className="w-4 h-4 text-red-400" />}
          <span className={`text-sm ${toast.type === "success" ? "text-green-300" : "text-red-300"}`}>{toast.msg}</span>
        </div>
      )}

      {showForm && (
        <form onSubmit={handleGenerate} className="card p-6 space-y-4 animate-fade-in">
          <div className="flex items-center gap-2 mb-2">
            <Ticket className="w-5 h-5 text-noc-accent" />
            <h2 className="text-lg font-semibold text-slate-100">Generate Voucher Batch</h2>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-medium text-slate-400 mb-1.5">Package *</label>
              <select required value={selectedPackage} onChange={(e) => setSelectedPackage(e.target.value)} className="input-field">
                <option value="">Select a package...</option>
                {packages.map((p) => <option key={p.id} value={p.id}>{p.name} — KSh {(p.price_minor / 100).toLocaleString()}</option>)}
              </select>
            </div>
            <div>
              <label className="block text-xs font-medium text-slate-400 mb-1.5">Quantity (1-500) *</label>
              <input type="number" required min={1} max={500} value={quantity} onChange={(e) => setQuantity(parseInt(e.target.value || "1"))} className="input-field" />
            </div>
          </div>
          <button type="submit" disabled={generating || !selectedPackage} className="btn-primary flex items-center gap-2 text-sm">
            {generating ? <Loader2 className="w-4 h-4 animate-spin" /> : <Ticket className="w-4 h-4" />}
            {generating ? "Generating..." : "Generate"}
          </button>
        </form>
      )}

      <div className="flex gap-2">
        {["", "active", "used", "expired"].map((s) => (
          <button key={s} onClick={() => setStatusFilter(s)} className={`px-3 py-1.5 rounded-lg text-xs font-medium ${statusFilter === s ? "bg-noc-accent/10 text-noc-accent" : "text-slate-400 hover:text-slate-200"}`}>
            {s === "" ? "All" : s.charAt(0).toUpperCase() + s.slice(1)}
          </button>
        ))}
      </div>

      {vouchers.length === 0 ? (
        <div className="card"><EmptyState message="No vouchers found. Generate a batch to create voucher codes." /></div>
      ) : (
        <div className="card overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-xs text-slate-500 uppercase tracking-wider border-b border-noc-border">
                  <th className="px-5 py-3 font-medium">Code</th>
                  <th className="px-5 py-3 font-medium">Package</th>
                  <th className="px-5 py-3 font-medium">Status</th>
                  <th className="px-5 py-3 font-medium">Used At</th>
                  <th className="px-5 py-3 font-medium"></th>
                </tr>
              </thead>
              <tbody>
                {vouchers.map((v) => (
                  <tr key={v.id} className="border-b border-noc-border/50 last:border-0 hover:bg-noc-hover/30">
                    <td className="px-5 py-3 font-mono text-slate-200">{v.code}</td>
                    <td className="px-5 py-3 text-slate-400">{v.package?.name ?? "—"}</td>
                    <td className="px-5 py-3">
                      <span className={`text-xs font-medium px-2 py-0.5 rounded ${v.status === "active" ? "bg-emerald-500/10 text-emerald-400" : v.status === "used" ? "bg-blue-500/10 text-blue-400" : "bg-slate-700 text-slate-400"}`}>
                        {v.status}
                      </span>
                    </td>
                    <td className="px-5 py-3 text-xs text-slate-500">{v.used_at ?? "—"}</td>
                    <td className="px-5 py-3">
                      {v.status === "active" && (
                        <button onClick={() => copyCode(v.code)} className="text-slate-400 hover:text-noc-accent transition-colors">
                          {copiedCode === v.code ? <CheckCircle2 className="w-4 h-4 text-green-400" /> : <Copy className="w-4 h-4" />}
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
