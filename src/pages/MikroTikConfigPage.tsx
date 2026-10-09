import { useEffect, useState, useCallback } from "react";
import {
  Plus,
  Trash2,
  Lock,
  Loader2,
  CheckCircle2,
  XCircle,
  Server,
  Cpu,
  MemoryStick,
  HardDrive,
  Clock,
  CircuitBoard,
  Network,
  EyeOff,
  Eye,
  ShieldCheck,
  Wifi,
} from "lucide-react";
import { apiGet, apiPost, apiDelete } from "../services/api";
import type { MikroTikConfig, MikroTikConnectResult } from "../types";
import { LoadingSpinner, EmptyState } from "../components/ui";

type AddForm = {
  name: string;
  host: string;
  apiPort: string;
  useTls: boolean;
  verifyCert: boolean;
  username: string;
  password: string;
  location: string;
  site: string;
};

const EMPTY_FORM: AddForm = {
  name: "",
  host: "",
  apiPort: "8729",
  useTls: true,
  verifyCert: true,
  username: "monitoring",
  password: "",
  location: "",
  site: "",
};

export function MikroTikConfigPage() {
  const [configs, setConfigs] = useState<MikroTikConfig[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState<AddForm>(EMPTY_FORM);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [connectResults, setConnectResults] = useState<Record<string, MikroTikConnectResult | null>>({});
  const [connectingIds, setConnectingIds] = useState<Set<string>>(new Set());
  const [deleteConfirm, setDeleteConfirm] = useState<string | null>(null);
  const [showPassword, setShowPassword] = useState(false);

  const loadConfigs = useCallback(async () => {
    setLoading(true);
    try {
      const data = await apiGet<MikroTikConfig[]>("/mikrotik-configs");
      setConfigs(data);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load configurations");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadConfigs();
  }, [loadConfigs]);

  const handleAdd = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiPost("/mikrotik-configs", {
        name: form.name,
        host: form.host,
        apiPort: parseInt(form.apiPort, 10) || 8728,
        useTls: form.useTls,
        verifyCert: form.verifyCert,
        username: form.username,
        password: form.password,
        location: form.location || null,
        site: form.site || null,
      });
      setForm(EMPTY_FORM);
      setShowForm(false);
      setShowPassword(false);
      await loadConfigs();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to save configuration");
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (id: string) => {
    try {
      await apiDelete(`/mikrotik-configs/${id}`);
      setDeleteConfirm(null);
      setConnectResults((prev) => {
        const next = { ...prev };
        delete next[id];
        return next;
      });
      await loadConfigs();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to delete configuration");
    }
  };

  const handleConnect = async (id: string) => {
    setConnectingIds((prev) => new Set(prev).add(id));
    setError(null);
    try {
      const result = await apiPost<MikroTikConnectResult>(`/mikrotik-configs/${id}/connect`);
      setConnectResults((prev) => ({ ...prev, [id]: result }));
      if (result.success) {
        await loadConfigs();
      }
    } catch (err) {
      setConnectResults((prev) => ({
        ...prev,
        [id]: {
          success: false,
          state: "failed",
          message: err instanceof Error ? err.message : "Connection failed",
          readOnly: true,
          router: null,
        },
      }));
    } finally {
      setConnectingIds((prev) => {
        const next = new Set(prev);
        next.delete(id);
        return next;
      });
    }
  };

  if (loading) return <LoadingSpinner label="Loading MikroTik configurations..." />;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-100">MikroTik Configuration</h1>
          <p className="text-sm text-slate-400 mt-1">
            Add router credentials to connect and load live data. Passwords are masked once saved — you can delete and re-add, but never view them.
          </p>
        </div>
        {!showForm && (
          <button
            onClick={() => setShowForm(true)}
            className="btn-primary flex items-center gap-2 text-sm"
          >
            <Plus className="w-4 h-4" />
            Add Router
          </button>
        )}
      </div>

      {/* Read-only notice */}
      <div className="card p-4 border-green-500/20 bg-green-500/5">
        <div className="flex items-center gap-3">
          <ShieldCheck className="w-5 h-5 text-green-500 flex-shrink-0" />
          <div className="text-sm">
            <span className="text-green-400 font-medium">READ-ONLY:</span>
            <span className="text-slate-300 ml-2">
              Connections only fetch system information. No configuration changes, reboots, or commands are sent to the router.
            </span>
          </div>
        </div>
      </div>

      {error && (
        <div className="card p-4 border-red-500/30 bg-red-500/5">
          <div className="flex items-center gap-3">
            <XCircle className="w-5 h-5 text-red-400 flex-shrink-0" />
            <span className="text-sm text-red-300">{error}</span>
            <button onClick={() => setError(null)} className="ml-auto text-xs text-slate-400 hover:text-slate-200">
              Dismiss
            </button>
          </div>
        </div>
      )}

      {/* Add form */}
      {showForm && (
        <AddConfigForm
          form={form}
          setForm={setForm}
          onSave={handleAdd}
          onCancel={() => { setShowForm(false); setForm(EMPTY_FORM); setShowPassword(false); }}
          saving={saving}
          showPassword={showPassword}
          togglePassword={() => setShowPassword(!showPassword)}
        />
      )}

      {/* Config list */}
      {configs.length === 0 && !showForm ? (
        <div className="card">
          <EmptyState message="No MikroTik routers configured yet. Click 'Add Router' to add your first router credentials." />
        </div>
      ) : (
        <div className="space-y-4">
          {configs.map((cfg) => (
            <ConfigCard
              key={cfg.id}
              config={cfg}
              connecting={connectingIds.has(cfg.id)}
              connectResult={connectResults[cfg.id] ?? null}
              onConnect={() => handleConnect(cfg.id)}
              onDelete={() => setDeleteConfirm(cfg.id)}
              deleteConfirm={deleteConfirm === cfg.id}
              confirmDelete={() => handleDelete(cfg.id)}
              cancelDelete={() => setDeleteConfirm(null)}
            />
          ))}
        </div>
      )}
    </div>
  );
}

// ── Add Config Form ──────────────────────────────────────────────

function AddConfigForm({
  form,
  setForm,
  onSave,
  onCancel,
  saving,
  showPassword,
  togglePassword,
}: {
  form: AddForm;
  setForm: React.Dispatch<React.SetStateAction<AddForm>>;
  onSave: (e: React.FormEvent) => void;
  onCancel: () => void;
  saving: boolean;
  showPassword: boolean;
  togglePassword: () => void;
}) {
  return (
    <form onSubmit={onSave} className="card p-6 space-y-4 animate-fade-in">
      <div className="flex items-center gap-2 mb-2">
        <Server className="w-5 h-5 text-noc-accent" />
        <h2 className="text-lg font-semibold text-slate-100">Add MikroTik Router</h2>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <FormField label="Router Name" required>
          <input
            type="text"
            required
            value={form.name}
            onChange={(e) => setForm({ ...form, name: e.target.value })}
            placeholder="e.g. Mombasa-01-A"
            className="input-field"
          />
        </FormField>

        <FormField label="Host / IP Address" required>
          <input
            type="text"
            required
            value={form.host}
            onChange={(e) => setForm({ ...form, host: e.target.value })}
            placeholder="e.g. 192.168.88.1"
            className="input-field"
          />
        </FormField>

        <FormField label="API Port">
          <input
            type="number"
            value={form.apiPort}
            onChange={(e) => setForm({ ...form, apiPort: e.target.value })}
            className="input-field"
          />
        </FormField>

        <FormField label="Use TLS (API-SSL)">
          <label className="flex items-center gap-2 mt-2">
            <input
              type="checkbox"
              checked={form.useTls}
              onChange={(e) => {
                const useTls = e.target.checked;
                const defaultPort = useTls ? "8728" : "8729";
                const tlsPort = useTls ? "8729" : "8728";
                setForm({
                  ...form,
                  useTls,
                  apiPort: form.apiPort === defaultPort ? tlsPort : form.apiPort,
                });
              }}
              className="w-4 h-4 rounded border-noc-border bg-noc-panel"
            />
            <span className="text-sm text-slate-300">Enable TLS encryption for binary API (default port 8729)</span>
          </label>
        </FormField>

        <FormField label="Verify TLS/HTTPS certificate">
          <label className="flex items-center gap-2 mt-2">
            <input
              type="checkbox"
              checked={form.verifyCert}
              onChange={(e) => setForm({ ...form, verifyCert: e.target.checked })}
              className="w-4 h-4 rounded border-noc-border bg-noc-panel"
            />
            <span className="text-sm text-slate-300">Verify the router certificate (recommended)</span>
          </label>
          {!form.verifyCert && (
            <p className="mt-1 text-xs text-amber-400">
              Certificate verification is disabled for API-SSL and the REST HTTPS fallback. Use only on a trusted network or with a self-signed certificate.
            </p>
          )}
        </FormField>

        {!form.useTls && (
          <p className="md:col-span-2 text-xs text-amber-400">
            The binary API login sends credentials in plaintext when TLS is disabled. Prefer API-SSL on port 8729.
          </p>
        )}

        <FormField label="Username" required>
          <input
            type="text"
            required
            value={form.username}
            onChange={(e) => setForm({ ...form, username: e.target.value })}
            placeholder="monitoring"
            className="input-field"
          />
        </FormField>

        <FormField label="Password" required>
          <div className="relative">
            <input
              type={showPassword ? "text" : "password"}
              required
              value={form.password}
              onChange={(e) => setForm({ ...form, password: e.target.value })}
              placeholder="Enter API password"
              className="input-field pr-10"
            />
            <button
              type="button"
              onClick={togglePassword}
              className="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-200"
            >
              {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
            </button>
          </div>
        </FormField>

        <FormField label="Location (optional)">
          <input
            type="text"
            value={form.location}
            onChange={(e) => setForm({ ...form, location: e.target.value })}
            placeholder="e.g. Mombasa"
            className="input-field"
          />
        </FormField>

        <FormField label="Site (optional)">
          <input
            type="text"
            value={form.site}
            onChange={(e) => setForm({ ...form, site: e.target.value })}
            placeholder="e.g. Coast"
            className="input-field"
          />
        </FormField>
      </div>

      <div className="flex items-center gap-2 text-xs text-slate-500">
        <Lock className="w-3.5 h-3.5" />
        The password will be encrypted and stored securely. Once saved, it cannot be viewed — only deleted and re-entered.
      </div>

      <div className="flex items-center gap-3 pt-2">
        <button type="submit" disabled={saving} className="btn-primary flex items-center gap-2 text-sm">
          {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <Plus className="w-4 h-4" />}
          {saving ? "Saving..." : "Save Configuration"}
        </button>
        <button type="button" onClick={onCancel} className="btn-secondary text-sm">
          Cancel
        </button>
      </div>
    </form>
  );
}

function FormField({ label, required, children }: { label: string; required?: boolean; children: React.ReactNode }) {
  return (
    <div>
      <label className="block text-xs font-medium text-slate-400 mb-1.5">
        {label}
        {required && <span className="text-red-400 ml-1">*</span>}
      </label>
      {children}
    </div>
  );
}

// ── Config Card ──────────────────────────────────────────────────

function ConfigCard({
  config,
  connecting,
  connectResult,
  onConnect,
  onDelete,
  deleteConfirm,
  confirmDelete,
  cancelDelete,
}: {
  config: MikroTikConfig;
  connecting: boolean;
  connectResult: MikroTikConnectResult | null;
  onConnect: () => void;
  onDelete: () => void;
  deleteConfirm: boolean;
  confirmDelete: () => void;
  cancelDelete: () => void;
}) {
  return (
    <div className="card overflow-hidden">
      {/* Header */}
      <div className="p-5 flex flex-wrap items-start justify-between gap-4">
        <div className="flex items-start gap-4">
          <div className="w-11 h-11 rounded-lg bg-noc-accent/10 flex items-center justify-center flex-shrink-0">
            <Wifi className="w-5 h-5 text-noc-accent" />
          </div>
          <div>
            <div className="flex items-center gap-3">
              <h3 className="text-lg font-semibold text-slate-100">{config.name}</h3>
              {config.isConnected ? (
                <span className="inline-flex items-center gap-1.5 text-xs font-medium text-green-400">
                  <span className="status-dot bg-green-500 animate-pulse-slow" />
                  Connected
                </span>
              ) : (
                <span className="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500">
                  <span className="status-dot bg-slate-600" />
                  Not connected
                </span>
              )}
            </div>
            <div className="flex flex-wrap gap-x-4 gap-y-1 mt-1.5 text-sm text-slate-400 font-mono">
              <span>{config.host}</span>
              <span>:{config.apiPort}</span>
              {config.useTls && <span className="text-blue-400">TLS</span>}
              <span>@{config.username}</span>
            </div>
            {config.location && (
              <div className="text-xs text-slate-500 mt-1">
                {config.location}{config.site ? `, ${config.site}` : ""}
              </div>
            )}
          </div>
        </div>

        <div className="flex items-center gap-2">
          {/* Credentials masked indicator */}
          <div className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-noc-panel border border-noc-border">
            <EyeOff className="w-3.5 h-3.5 text-slate-500" />
            <span className="text-xs text-slate-500 font-mono">{config.passwordMasked ?? "—"}</span>
          </div>

          {/* Delete button or confirmation */}
          {deleteConfirm ? (
            <div className="flex items-center gap-2">
              <button
                onClick={confirmDelete}
                className="px-3 py-1.5 rounded-lg text-xs font-medium text-white bg-red-600 hover:bg-red-500 transition-colors"
              >
                Confirm Delete
              </button>
              <button
                onClick={cancelDelete}
                className="px-3 py-1.5 rounded-lg text-xs font-medium text-slate-300 bg-noc-panel hover:bg-noc-hover transition-colors"
              >
                Cancel
              </button>
            </div>
          ) : (
            <button
              onClick={onDelete}
              className="p-2 rounded-lg text-slate-400 hover:text-red-400 hover:bg-red-500/10 transition-colors"
              title="Delete configuration"
            >
              <Trash2 className="w-4 h-4" />
            </button>
          )}
        </div>
      </div>

      {/* Connect button + result */}
      <div className="border-t border-noc-border px-5 py-4 bg-noc-panel/30">
        {connecting ? (
          <div className="flex items-center gap-3 text-sm text-slate-300">
            <Loader2 className="w-4 h-4 animate-spin text-noc-accent" />
            <span>Connecting to {config.host}...</span>
            <div className="w-32 h-1 bg-noc-border rounded-full overflow-hidden ml-2">
              <div className="h-full bg-noc-accent animate-pulse" style={{ width: "60%" }} />
            </div>
          </div>
        ) : connectResult ? (
          <ConnectResultDisplay result={connectResult} onReconnect={onConnect} />
        ) : (
          <button
            onClick={onConnect}
            className="btn-primary flex items-center gap-2 text-sm"
          >
            <Lock className="w-4 h-4" />
            Connect to Router
          </button>
        )}
      </div>
    </div>
  );
}

// ── Connect Result Display ───────────────────────────────────────

function ConnectResultDisplay({ result, onReconnect }: { result: MikroTikConnectResult; onReconnect: () => void }) {
  if (!result.success || !result.router) {
    return (
      <div className="space-y-3">
        <div className="flex items-start gap-3">
          <XCircle className="w-5 h-5 text-red-400 flex-shrink-0 mt-0.5" />
          <div className="flex-1">
            <div className="text-sm font-medium text-red-400">Connection Failed</div>
            <div className="text-xs text-slate-400 mt-1 font-mono break-all">{result.message}</div>
          </div>
          <button onClick={onReconnect} className="btn-secondary text-xs flex items-center gap-1.5">
            <Loader2 className="w-3 h-3" />
            Retry
          </button>
        </div>
      </div>
    );
  }

  const r = result.router;
  const memUsage = r.totalMemory > 0 ? Math.round(((r.totalMemory - r.freeMemory) / r.totalMemory) * 100) : 0;
  const hddUsage = r.totalHdd > 0 ? Math.round(((r.totalHdd - r.freeHdd) / r.totalHdd) * 100) : 0;

  return (
    <div className="space-y-4 animate-fade-in">
      {/* Success header */}
      <div className="flex items-center gap-3">
        <CheckCircle2 className="w-5 h-5 text-green-400" />
        <div className="flex-1">
          <div className="text-sm font-medium text-green-400">{result.message}</div>
          <div className="text-xs text-slate-500">Connected to {r.identity} ({r.host})</div>
        </div>
        <div className="flex items-center gap-2">
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-green-500/10 text-green-400 border border-green-500/20">
            <ShieldCheck className="w-3 h-3" />
            READ-ONLY
          </span>
          <button onClick={onReconnect} className="btn-secondary text-xs flex items-center gap-1.5">
            <Loader2 className="w-3 h-3" />
            Refresh
          </button>
        </div>
      </div>

      {/* Live system data */}
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
        <LiveMetric icon={Cpu} label="CPU Load" value={`${r.cpuLoad}%`} color="text-blue-400" />
        <LiveMetric icon={MemoryStick} label="Memory" value={`${memUsage}%`} color="text-cyan-400" />
        <LiveMetric icon={HardDrive} label="Storage" value={`${hddUsage}%`} color="text-amber-400" />
        <LiveMetric icon={Clock} label="Uptime" value={r.uptime} color="text-emerald-400" />
        <LiveMetric icon={CircuitBoard} label="CPU Count" value={String(r.cpuCount)} color="text-slate-300" />
        <LiveMetric icon={Network} label="Platform" value={r.platform} color="text-slate-300" />
      </div>

      {/* System info */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-2 text-sm">
        <InfoLine icon={Server} label="Board Model" value={r.model} />
        <InfoLine icon={Cpu} label="Architecture" value={r.architecture} />
        <InfoLine icon={CircuitBoard} label="RouterOS Version" value={r.routerosVersion} />
        <InfoLine icon={Server} label="Serial Number" value={r.serialNumber || "—"} />
      </div>
    </div>
  );
}

function LiveMetric({ icon: Icon, label, value, color }: { icon: typeof Cpu; label: string; value: string; color: string }) {
  return (
    <div className="rounded-lg border border-noc-border p-3 bg-noc-panel">
      <div className="flex items-center gap-1.5 mb-1">
        <Icon className={`w-3.5 h-3.5 ${color}`} />
        <span className="text-[10px] text-slate-400 uppercase tracking-wider">{label}</span>
      </div>
      <div className="text-sm font-bold text-slate-100 truncate">{value}</div>
    </div>
  );
}

function InfoLine({ icon: Icon, label, value }: { icon: typeof Cpu; label: string; value: string }) {
  return (
    <div className="flex items-center gap-2.5 py-1 border-b border-noc-border/30">
      <Icon className="w-3.5 h-3.5 text-slate-500 flex-shrink-0" />
      <span className="text-slate-400 text-xs w-32 flex-shrink-0">{label}</span>
      <span className="text-slate-200 font-mono text-xs">{value}</span>
    </div>
  );
}
