import { useEffect, useState, useCallback } from "react";
import {
  Radio, RefreshCw, Loader2, CheckCircle2, XCircle,
  Trash2, Plus, Wifi, Clock, Database, Users as UsersIcon,
} from "lucide-react";
import {
  getRadiusStatus, getRadiusNas, createRadiusNas, deleteRadiusNas,
  syncRadiusNas, getRadiusSessions, disconnectRadiusSession,
  getRadiusAccounting, type RadiusStatus, type RadiusNas, type RadiusSession,
} from "../services/radiusApi";
import { LoadingSpinner, EmptyState } from "../components/ui";

type Tab = "nas" | "sessions" | "accounting";

export function RadiusPage() {
  const [tab, setTab] = useState<Tab>("nas");
  const [status, setStatus] = useState<RadiusStatus | null>(null);
  const [nas, setNas] = useState<RadiusNas[]>([]);
  const [sessions, setSessions] = useState<RadiusSession[]>([]);
  const [accounting, setAccounting] = useState<RadiusSession[]>([]);
  const [loading, setLoading] = useState(true);
  const [actionLoading, setActionLoading] = useState<string | null>(null);
  const [toast, setToast] = useState<{ type: "success" | "error"; msg: string } | null>(null);
  const [showAddNas, setShowAddNas] = useState(false);
  const [nasForm, setNasForm] = useState({ nasname: "", shortname: "", secret: "", description: "" });
  const [deleteConfirm, setDeleteConfirm] = useState<number | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [s, n] = await Promise.all([getRadiusStatus(), getRadiusNas()]);
      setStatus(s);
      setNas((n as unknown as { data: RadiusNas[] }).data ?? []);
      try {
        const sess = await getRadiusSessions();
        setSessions((sess as unknown as { data: RadiusSession[] }).data ?? []);
        const acct = await getRadiusAccounting();
        setAccounting((acct as unknown as { data: RadiusSession[] }).data ?? []);
      } catch { setSessions([]); setAccounting([]); }
    } catch {
      setStatus(null);
    } finally { setLoading(false); }
  }, []);

  useEffect(() => { load(); }, [load]);

  const handleSync = async () => {
    setActionLoading("sync");
    try {
      const r = await syncRadiusNas();
      setToast({ type: "success", msg: `Synced ${r.synced} NAS entries` });
      await load();
    } catch (e) {
      setToast({ type: "error", msg: e instanceof Error ? e.message : "Sync failed" });
    } finally { setActionLoading(null); setTimeout(() => setToast(null), 4000); }
  };

  const handleAddNas = async (e: React.FormEvent) => {
    e.preventDefault();
    setActionLoading("addNas");
    try {
      await createRadiusNas({
        nasname: nasForm.nasname,
        shortname: nasForm.shortname || undefined,
        secret: nasForm.secret,
        description: nasForm.description || undefined,
      });
      setToast({ type: "success", msg: "NAS added" });
      setNasForm({ nasname: "", shortname: "", secret: "", description: "" });
      setShowAddNas(false);
      await load();
    } catch (e) {
      setToast({ type: "error", msg: e instanceof Error ? e.message : "Failed to add NAS" });
    } finally { setActionLoading(null); setTimeout(() => setToast(null), 4000); }
  };

  const handleDeleteNas = async (id: number) => {
    setActionLoading(`del-${id}`);
    try {
      await deleteRadiusNas(id);
      setToast({ type: "success", msg: "NAS deleted" });
      setDeleteConfirm(null);
      await load();
    } catch (e) {
      setToast({ type: "error", msg: e instanceof Error ? e.message : "Delete failed" });
    } finally { setActionLoading(null); setTimeout(() => setToast(null), 4000); }
  };

  const handleDisconnect = async (username: string) => {
    setActionLoading(`disc-${username}`);
    try {
      const r = await disconnectRadiusSession(username);
      setToast({ type: "success", msg: `Disconnected ${r.disconnected} session(s)` });
      await load();
    } catch (e) {
      setToast({ type: "error", msg: e instanceof Error ? e.message : "Disconnect failed" });
    } finally { setActionLoading(null); setTimeout(() => setToast(null), 4000); }
  };

  const formatDuration = (seconds: number) => {
    if (!seconds) return "—";
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    return h > 0 ? `${h}h ${m}m` : `${m}m`;
  };

  const formatBytes = (bytes: number) => {
    if (!bytes) return "0 MB";
    if (bytes >= 1073741824) return `${(bytes / 1073741824).toFixed(2)} GB`;
    return `${(bytes / 1048576).toFixed(1)} MB`;
  };

  if (loading) return <LoadingSpinner label="Loading RADIUS data..." />;

  const tabs: { id: Tab; label: string; icon: typeof Radio }[] = [
    { id: "nas", label: "NAS Clients", icon: Wifi },
    { id: "sessions", label: "Active Sessions", icon: Radio },
    { id: "accounting", label: "Accounting History", icon: Database },
  ];

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-100">RADIUS Management</h1>
          <p className="text-sm text-slate-400 mt-1">Manage NAS clients, view active sessions, and browse accounting history.</p>
        </div>
        <button onClick={handleSync} disabled={actionLoading === "sync"} className="btn-secondary flex items-center gap-2 text-sm">
          {actionLoading === "sync" ? <Loader2 className="w-4 h-4 animate-spin" /> : <RefreshCw className="w-4 h-4" />}
          Sync NAS from Routers
        </button>
      </div>

      {toast && (
        <div className={`card p-3 flex items-center gap-3 ${toast.type === "success" ? "border-green-500/30 bg-green-500/5" : "border-red-500/30 bg-red-500/5"}`}>
          {toast.type === "success" ? <CheckCircle2 className="w-4 h-4 text-green-400" /> : <XCircle className="w-4 h-4 text-red-400" />}
          <span className={`text-sm ${toast.type === "success" ? "text-green-300" : "text-red-300"}`}>{toast.msg}</span>
        </div>
      )}

      {/* Status cards */}
      <div className="grid gap-4 md:grid-cols-4">
        <div className="stat-card">
          <div className="flex items-center gap-2 mb-1"><Wifi className="w-3.5 h-3.5 text-cyan-400" /><span className="text-xs text-slate-500">NAS Clients</span></div>
          <p className="text-2xl font-bold text-slate-100">{status?.nas_count ?? 0}</p>
        </div>
        <div className="stat-card">
          <div className="flex items-center gap-2 mb-1"><Radio className="w-3.5 h-3.5 text-emerald-400" /><span className="text-xs text-slate-500">Active Sessions</span></div>
          <p className="text-2xl font-bold text-emerald-400">{status?.active_sessions ?? 0}</p>
        </div>
        <div className="stat-card">
          <div className="flex items-center gap-2 mb-1"><UsersIcon className="w-3.5 h-3.5 text-blue-400" /><span className="text-xs text-slate-500">Total Users</span></div>
          <p className="text-2xl font-bold text-slate-100">{status?.total_users ?? 0}</p>
        </div>
        <div className="stat-card">
          <div className="flex items-center gap-2 mb-1"><Clock className="w-3.5 h-3.5 text-amber-400" /><span className="text-xs text-slate-500">Status</span></div>
          <p className={`text-lg font-bold ${status?.enabled ? "text-emerald-400" : "text-slate-500"}`}>{status?.enabled ? "Enabled" : "Disabled"}</p>
        </div>
      </div>

      {/* Tabs */}
      <div className="flex gap-1 border-b border-noc-border overflow-x-auto">
        {tabs.map((t) => {
          const Icon = t.icon;
          return (
            <button key={t.id} onClick={() => setTab(t.id)} className={`flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 transition-colors whitespace-nowrap ${tab === t.id ? "border-noc-accent text-noc-accent" : "border-transparent text-slate-400 hover:text-slate-200"}`}>
              <Icon className="w-4 h-4" />{t.label}
            </button>
          );
        })}
      </div>

      {tab === "nas" && (
        <div className="space-y-4">
          {!showAddNas ? (
            <button onClick={() => setShowAddNas(true)} className="btn-primary flex items-center gap-2 text-sm">
              <Plus className="w-4 h-4" /> Add NAS
            </button>
          ) : (
            <form onSubmit={handleAddNas} className="card p-6 space-y-4 animate-fade-in">
              <h2 className="text-lg font-semibold text-slate-100">Add NAS Client</h2>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-medium text-slate-400 mb-1.5">IP Address *</label>
                  <input required value={nasForm.nasname} onChange={(e) => setNasForm({ ...nasForm, nasname: e.target.value })} className="input-field" placeholder="192.168.88.1" />
                </div>
                <div>
                  <label className="block text-xs font-medium text-slate-400 mb-1.5">Short Name</label>
                  <input value={nasForm.shortname} onChange={(e) => setNasForm({ ...nasForm, shortname: e.target.value })} className="input-field" placeholder="Main Router" />
                </div>
                <div>
                  <label className="block text-xs font-medium text-slate-400 mb-1.5">Shared Secret *</label>
                  <input required value={nasForm.secret} onChange={(e) => setNasForm({ ...nasForm, secret: e.target.value })} className="input-field" placeholder="min 8 characters" minLength={8} />
                </div>
                <div>
                  <label className="block text-xs font-medium text-slate-400 mb-1.5">Description</label>
                  <input value={nasForm.description} onChange={(e) => setNasForm({ ...nasForm, description: e.target.value })} className="input-field" />
                </div>
              </div>
              <div className="flex items-center gap-3">
                <button type="submit" disabled={actionLoading === "addNas"} className="btn-primary text-sm flex items-center gap-2">
                  {actionLoading === "addNas" ? <Loader2 className="w-4 h-4 animate-spin" /> : <Plus className="w-4 h-4" />}
                  Add NAS
                </button>
                <button type="button" onClick={() => setShowAddNas(false)} className="btn-secondary text-sm">Cancel</button>
              </div>
            </form>
          )}

          {nas.length === 0 && !showAddNas ? (
            <div className="card"><EmptyState message="No NAS clients registered. Click 'Add NAS' or 'Sync NAS from Routers'." /></div>
          ) : (
            <div className="card overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-left text-xs text-slate-500 uppercase tracking-wider border-b border-noc-border">
                      <th className="px-5 py-3 font-medium">IP Address</th>
                      <th className="px-5 py-3 font-medium">Name</th>
                      <th className="px-5 py-3 font-medium">Type</th>
                      <th className="px-5 py-3 font-medium">Secret</th>
                      <th className="px-5 py-3 font-medium">Router</th>
                      <th className="px-5 py-3 font-medium"></th>
                    </tr>
                  </thead>
                  <tbody>
                    {nas.map((n) => (
                      <tr key={n.id} className="border-b border-noc-border/50 last:border-0 hover:bg-noc-hover/30">
                        <td className="px-5 py-3 font-mono text-slate-200">{n.nasname}</td>
                        <td className="px-5 py-3 text-slate-300">{n.shortname ?? "—"}</td>
                        <td className="px-5 py-3 text-slate-400">{n.type}</td>
                        <td className="px-5 py-3 font-mono text-xs text-slate-500">{n.secret?.slice(0, 4)}••••</td>
                        <td className="px-5 py-3 text-slate-400">{n.router?.name ?? "—"}</td>
                        <td className="px-5 py-3">
                          {deleteConfirm === n.id ? (
                            <div className="flex items-center gap-2">
                              <button onClick={() => handleDeleteNas(n.id)} disabled={actionLoading === `del-${n.id}`} className="px-3 py-1.5 rounded-lg text-xs font-medium text-white bg-red-600 hover:bg-red-500">
                                {actionLoading === `del-${n.id}` ? <Loader2 className="w-3 h-3 animate-spin" /> : "Confirm"}
                              </button>
                              <button onClick={() => setDeleteConfirm(null)} className="text-xs text-slate-400 hover:text-slate-200">Cancel</button>
                            </div>
                          ) : (
                            <button onClick={() => setDeleteConfirm(n.id)} className="p-2 rounded-lg text-slate-400 hover:text-red-400 hover:bg-red-500/10 transition-colors">
                              <Trash2 className="w-4 h-4" />
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
      )}

      {tab === "sessions" && (
        <div className="card overflow-hidden">
          {sessions.length === 0 ? (
            <EmptyState message="No active RADIUS sessions." />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-xs text-slate-500 uppercase tracking-wider border-b border-noc-border">
                    <th className="px-5 py-3 font-medium">Username</th>
                    <th className="px-5 py-3 font-medium">NAS IP</th>
                    <th className="px-5 py-3 font-medium">Started</th>
                    <th className="px-5 py-3 font-medium">Duration</th>
                    <th className="px-5 py-3 font-medium">Download</th>
                    <th className="px-5 py-3 font-medium">Upload</th>
                    <th className="px-5 py-3 font-medium">Client IP</th>
                    <th className="px-5 py-3 font-medium"></th>
                  </tr>
                </thead>
                <tbody>
                  {sessions.map((s) => (
                    <tr key={s.radacctid} className="border-b border-noc-border/50 last:border-0 hover:bg-noc-hover/30">
                      <td className="px-5 py-3 font-mono text-slate-200">{s.username}</td>
                      <td className="px-5 py-3 font-mono text-xs text-slate-400">{s.nasipaddress}</td>
                      <td className="px-5 py-3 text-xs text-slate-500">{s.acctstarttime?.slice(0, 19).replace("T", " ")}</td>
                      <td className="px-5 py-3 text-slate-300">{formatDuration(s.acctsessiontime)}</td>
                      <td className="px-5 py-3 text-slate-300">{formatBytes(s.acctoutputoctets)}</td>
                      <td className="px-5 py-3 text-slate-300">{formatBytes(s.acctinputoctets)}</td>
                      <td className="px-5 py-3 font-mono text-xs text-slate-400">{s.framedipaddress || "—"}</td>
                      <td className="px-5 py-3">
                        <button onClick={() => handleDisconnect(s.username)} disabled={actionLoading === `disc-${s.username}`} className="text-xs text-red-400 hover:text-red-300 flex items-center gap-1">
                          {actionLoading === `disc-${s.username}` ? <Loader2 className="w-3 h-3 animate-spin" /> : <XCircle className="w-3 h-3" />}
                          Disconnect
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {tab === "accounting" && (
        <div className="card overflow-hidden">
          {accounting.length === 0 ? (
            <EmptyState message="No accounting records." />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-xs text-slate-500 uppercase tracking-wider border-b border-noc-border">
                    <th className="px-5 py-3 font-medium">Username</th>
                    <th className="px-5 py-3 font-medium">NAS IP</th>
                    <th className="px-5 py-3 font-medium">Started</th>
                    <th className="px-5 py-3 font-medium">Stopped</th>
                    <th className="px-5 py-3 font-medium">Duration</th>
                    <th className="px-5 py-3 font-medium">Data In</th>
                    <th className="px-5 py-3 font-medium">Data Out</th>
                    <th className="px-5 py-3 font-medium">Termination</th>
                  </tr>
                </thead>
                <tbody>
                  {accounting.map((s) => (
                    <tr key={s.radacctid} className="border-b border-noc-border/50 last:border-0 hover:bg-noc-hover/30">
                      <td className="px-5 py-3 font-mono text-slate-200">{s.username}</td>
                      <td className="px-5 py-3 font-mono text-xs text-slate-400">{s.nasipaddress}</td>
                      <td className="px-5 py-3 text-xs text-slate-500">{s.acctstarttime?.slice(0, 19).replace("T", " ")}</td>
                      <td className="px-5 py-3 text-xs text-slate-500">{s.acctstoptime?.slice(0, 19).replace("T", " ") ?? "—"}</td>
                      <td className="px-5 py-3 text-slate-300">{formatDuration(s.acctsessiontime)}</td>
                      <td className="px-5 py-3 text-slate-300">{formatBytes(s.acctinputoctets)}</td>
                      <td className="px-5 py-3 text-slate-300">{formatBytes(s.acctoutputoctets)}</td>
                      <td className="px-5 py-3 text-xs text-slate-500">{s.acctterminatecause ?? "—"}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
