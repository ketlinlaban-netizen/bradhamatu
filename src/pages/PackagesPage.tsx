import { useEffect, useState, useCallback } from "react";
import { Plus, Trash2, Pencil, Loader2, Package as PackageIcon, X, CheckCircle2, XCircle } from "lucide-react";
import {
  getAdminPackages, createAdminPackage, updateAdminPackage, deleteAdminPackage,
  type AdminBillingPackage,
} from "../services/adminBillingApi";
import { apiGet } from "../services/api";
import { LoadingSpinner, EmptyState } from "../components/ui";

interface RouterOption { id: string; name: string; }

interface PackageForm {
  name: string;
  description: string;
  price_minor: number;
  duration_minutes: number;
  access_type: string;
  download_limit_kbps: number;
  upload_limit_kbps: number;
  quota_mb: number;
  max_devices: number;
  router_id: string;
  is_enabled: boolean;
}

const EMPTY_FORM: PackageForm = {
  name: "", description: "", price_minor: 0, duration_minutes: 1440,
  access_type: "hotspot", download_limit_kbps: 0, upload_limit_kbps: 0,
  quota_mb: 0, max_devices: 1, router_id: "", is_enabled: true,
};

export function PackagesPage() {
  const [packages, setPackages] = useState<AdminBillingPackage[]>([]);
  const [routers, setRouters] = useState<RouterOption[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [form, setForm] = useState<PackageForm>(EMPTY_FORM);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [toast, setToast] = useState<{ type: "success" | "error"; msg: string } | null>(null);
  const [deleteConfirm, setDeleteConfirm] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const pkgs = await getAdminPackages();
      setPackages(pkgs);
      try {
        const r = await apiGet<RouterOption[]>("/routers");
        setRouters(r);
      } catch { setRouters([]); }
    } catch (e) {
      setError(e instanceof Error ? e.message : "Failed to load packages");
    } finally { setLoading(false); }
  }, []);

  useEffect(() => { load(); }, [load]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const payload = {
        name: form.name,
        description: form.description || null,
        price_minor: form.price_minor,
        duration_minutes: form.duration_minutes,
        access_type: form.access_type,
        download_limit_kbps: form.download_limit_kbps,
        upload_limit_kbps: form.upload_limit_kbps,
        quota_mb: form.quota_mb > 0 ? form.quota_mb : null,
        max_devices: form.max_devices,
        router_id: form.router_id || null,
        is_enabled: form.is_enabled,
      };
      if (editingId) {
        await updateAdminPackage(editingId, payload);
        setToast({ type: "success", msg: "Package updated" });
      } else {
        await createAdminPackage(payload);
        setToast({ type: "success", msg: "Package created" });
      }
      setForm(EMPTY_FORM);
      setEditingId(null);
      setShowForm(false);
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Failed to save package");
    } finally {
      setSaving(false);
      setTimeout(() => setToast(null), 4000);
    }
  };

  const handleEdit = (pkg: AdminBillingPackage) => {
    setEditingId(pkg.id);
    setForm({
      name: pkg.name,
      description: pkg.description ?? "",
      price_minor: pkg.price_minor ?? 0,
      duration_minutes: pkg.duration_minutes,
      access_type: pkg.access_type ?? "hotspot",
      download_limit_kbps: pkg.download_kbps ?? 0,
      upload_limit_kbps: pkg.upload_kbps ?? 0,
      quota_mb: pkg.quota_mb ?? 0,
      max_devices: pkg.max_devices ?? 1,
      router_id: pkg.router_id ?? "",
      is_enabled: pkg.is_enabled,
    });
    setShowForm(true);
  };

  const handleDelete = async (id: string) => {
    try {
      await deleteAdminPackage(id);
      setToast({ type: "success", msg: "Package deleted" });
      setDeleteConfirm(null);
      await load();
    } catch (e) {
      setToast({ type: "error", msg: e instanceof Error ? e.message : "Delete failed" });
    }
    setTimeout(() => setToast(null), 4000);
  };

  if (loading) return <LoadingSpinner label="Loading packages..." />;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-100">Packages</h1>
          <p className="text-sm text-slate-400 mt-1">Create and manage internet packages with bandwidth limits and durations.</p>
        </div>
        {!showForm && (
          <button onClick={() => { setForm(EMPTY_FORM); setEditingId(null); setShowForm(true); }} className="btn-primary flex items-center gap-2 text-sm">
            <Plus className="w-4 h-4" /> Add Package
          </button>
        )}
      </div>

      {toast && (
        <div className={`card p-3 flex items-center gap-3 ${toast.type === "success" ? "border-green-500/30 bg-green-500/5" : "border-red-500/30 bg-red-500/5"}`}>
          {toast.type === "success" ? <CheckCircle2 className="w-4 h-4 text-green-400" /> : <XCircle className="w-4 h-4 text-red-400" />}
          <span className={`text-sm ${toast.type === "success" ? "text-green-300" : "text-red-300"}`}>{toast.msg}</span>
        </div>
      )}

      {error && (
        <div className="card p-3 border-red-500/30 bg-red-500/5">
          <span className="text-sm text-red-300">{error}</span>
        </div>
      )}

      {showForm && (
        <form onSubmit={handleSubmit} className="card p-6 space-y-4 animate-fade-in">
          <div className="flex items-center gap-2 mb-2">
            <PackageIcon className="w-5 h-5 text-noc-accent" />
            <h2 className="text-lg font-semibold text-slate-100">{editingId ? "Edit Package" : "New Package"}</h2>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-medium text-slate-400 mb-1.5">Name *</label>
              <input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="input-field" placeholder="e.g. Daily 5Mbps" />
            </div>
            <div>
              <label className="block text-xs font-medium text-slate-400 mb-1.5">Price (KSh) *</label>
              <input type="number" required min={0} value={form.price_minor / 100} onChange={(e) => setForm({ ...form, price_minor: Math.round(parseFloat(e.target.value || "0") * 100) })} className="input-field" placeholder="50" />
            </div>
            <div>
              <label className="block text-xs font-medium text-slate-400 mb-1.5">Duration (minutes) *</label>
              <input type="number" required min={1} value={form.duration_minutes} onChange={(e) => setForm({ ...form, duration_minutes: parseInt(e.target.value || "1440") })} className="input-field" />
            </div>
            <div>
              <label className="block text-xs font-medium text-slate-400 mb-1.5">Access Type</label>
              <select value={form.access_type} onChange={(e) => setForm({ ...form, access_type: e.target.value })} className="input-field">
                <option value="hotspot">Hotspot</option>
                <option value="pppoe">PPPoE</option>
              </select>
            </div>
            <div>
              <label className="block text-xs font-medium text-slate-400 mb-1.5">Download Limit (kbps, 0=unlimited)</label>
              <input type="number" min={0} value={form.download_limit_kbps} onChange={(e) => setForm({ ...form, download_limit_kbps: parseInt(e.target.value || "0") })} className="input-field" placeholder="5120" />
            </div>
            <div>
              <label className="block text-xs font-medium text-slate-400 mb-1.5">Upload Limit (kbps, 0=unlimited)</label>
              <input type="number" min={0} value={form.upload_limit_kbps} onChange={(e) => setForm({ ...form, upload_limit_kbps: parseInt(e.target.value || "0") })} className="input-field" placeholder="2048" />
            </div>
            <div>
              <label className="block text-xs font-medium text-slate-400 mb-1.5">Quota (MB, 0=unlimited)</label>
              <input type="number" min={0} value={form.quota_mb} onChange={(e) => setForm({ ...form, quota_mb: parseInt(e.target.value || "0") })} className="input-field" />
            </div>
            <div>
              <label className="block text-xs font-medium text-slate-400 mb-1.5">Max Devices</label>
              <input type="number" min={1} value={form.max_devices} onChange={(e) => setForm({ ...form, max_devices: parseInt(e.target.value || "1") })} className="input-field" />
            </div>
            <div>
              <label className="block text-xs font-medium text-slate-400 mb-1.5">Assigned Router (optional)</label>
              <select value={form.router_id} onChange={(e) => setForm({ ...form, router_id: e.target.value })} className="input-field">
                <option value="">Any router</option>
                {routers.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
              </select>
            </div>
            <div>
              <label className="block text-xs font-medium text-slate-400 mb-1.5">Description</label>
              <input value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} className="input-field" placeholder="Package description" />
            </div>
            <div className="md:col-span-2">
              <label className="flex items-center gap-2">
                <input type="checkbox" checked={form.is_enabled} onChange={(e) => setForm({ ...form, is_enabled: e.target.checked })} className="w-4 h-4 rounded border-noc-border bg-noc-panel" />
                <span className="text-sm text-slate-300">Enabled (visible to customers)</span>
              </label>
            </div>
          </div>

          <div className="flex items-center gap-3 pt-2">
            <button type="submit" disabled={saving} className="btn-primary flex items-center gap-2 text-sm">
              {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <Plus className="w-4 h-4" />}
              {saving ? "Saving..." : editingId ? "Update Package" : "Create Package"}
            </button>
            <button type="button" onClick={() => { setShowForm(false); setEditingId(null); setForm(EMPTY_FORM); }} className="btn-secondary text-sm flex items-center gap-2">
              <X className="w-4 h-4" /> Cancel
            </button>
          </div>
        </form>
      )}

      {packages.length === 0 && !showForm ? (
        <div className="card"><EmptyState message="No packages yet. Click 'Add Package' to create your first internet package." /></div>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {packages.map((pkg) => (
            <div key={pkg.id} className="card p-5">
              <div className="flex items-start justify-between mb-3">
                <div>
                  <h3 className="text-sm font-semibold text-slate-100">{pkg.name}</h3>
                  <p className="text-xs text-slate-500 mt-0.5">{pkg.access_type}</p>
                </div>
                <span className={`text-xs px-2 py-0.5 rounded ${pkg.is_enabled ? "bg-emerald-500/10 text-emerald-400" : "bg-slate-700 text-slate-400"}`}>
                  {pkg.is_enabled ? "Active" : "Disabled"}
                </span>
              </div>
              <div className="space-y-1.5 text-sm">
                <div className="flex justify-between"><span className="text-slate-500">Price</span><span className="font-mono text-slate-200">KSh {(pkg.price_minor / 100).toLocaleString()}</span></div>
                <div className="flex justify-between"><span className="text-slate-500">Duration</span><span className="text-slate-300">{pkg.duration_minutes >= 60 ? `${pkg.duration_minutes / 60}h` : `${pkg.duration_minutes}m`}</span></div>
                {pkg.download_kbps ? <div className="flex justify-between"><span className="text-slate-500">Download</span><span className="text-slate-300">{pkg.download_kbps >= 1024 ? `${(pkg.download_kbps / 1024).toFixed(1)} Mbps` : `${pkg.download_kbps} Kbps`}</span></div> : null}
                {pkg.upload_kbps ? <div className="flex justify-between"><span className="text-slate-500">Upload</span><span className="text-slate-300">{pkg.upload_kbps >= 1024 ? `${(pkg.upload_kbps / 1024).toFixed(1)} Mbps` : `${pkg.upload_kbps} Kbps`}</span></div> : null}
              </div>
              <div className="flex items-center gap-2 mt-4 pt-3 border-t border-noc-border">
                <button onClick={() => handleEdit(pkg)} className="btn-secondary text-xs flex items-center gap-1.5">
                  <Pencil className="w-3 h-3" /> Edit
                </button>
                {deleteConfirm === pkg.id ? (
                  <>
                    <button onClick={() => handleDelete(pkg.id)} className="px-3 py-1.5 rounded-lg text-xs font-medium text-white bg-red-600 hover:bg-red-500">Confirm</button>
                    <button onClick={() => setDeleteConfirm(null)} className="text-xs text-slate-400 hover:text-slate-200">Cancel</button>
                  </>
                ) : (
                  <button onClick={() => setDeleteConfirm(pkg.id)} className="p-2 rounded-lg text-slate-400 hover:text-red-400 hover:bg-red-500/10 transition-colors">
                    <Trash2 className="w-4 h-4" />
                  </button>
                )}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
