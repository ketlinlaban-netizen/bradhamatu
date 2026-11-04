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
  Clipboard,
  Download,
  RotateCcw,
} from "lucide-react";
import { useAuth } from "../auth/AuthContext";
import { apiGet, apiPost, apiDelete } from "../services/api";
import type { MikroTikConfig, MikroTikConnectResult, MikroTikReadResult } from "../types";
import { LoadingSpinner, EmptyState } from "../components/ui";
import { MikroTikTerminalScriptGenerator } from "../components/MikroTikTerminalScriptGenerator";
import {
  generateMikroTikTelemetryScript,
  MIKROTIK_TELEMETRY_GENERATOR_VERSION,
} from "../services/mikrotikTelemetryScript";
import { copyRouterOsScript, downloadRouterOsScript } from "../services/mikrotikScriptActions";

type AddForm = {
  name: string;
  host: string;
  routerIdentity: string;
  apiTransport: "binary" | "rest" | "telemetry";
  apiPort: string;
  useTls: boolean;
  username: string;
  password: string;
  location: string;
  site: string;
};

const EMPTY_FORM: AddForm = {
  name: "",
  host: "",
  routerIdentity: "",
  apiTransport: "binary",
  apiPort: "8729",
  useTls: true,
  username: "monitoring",
  password: "",
  location: "",
  site: "",
};

export function MikroTikConfigPage() {
  const { user } = useAuth();
  const [configs, setConfigs] = useState<MikroTikConfig[]>([]);
  const [provider, setProvider] = useState<"mock" | "mikrotik" | "rest" | "unavailable">("unavailable");
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

  useEffect(() => {
    apiGet<{ provider: "mock" | "mikrotik" | "rest" }>("/health")
      .then((health) => {
        setProvider(health.provider);
        if (health.provider === "rest") {
          setForm((current) => current.apiTransport === "binary"
            ? { ...current, apiTransport: "rest" }
            : current);
        }
      })
      .catch(() => setProvider("unavailable"));
  }, []);

  const handleAdd = async (e: React.FormEvent) => {
    e.preventDefault();
    if (form.apiTransport === "binary" && (
      !/^\d{1,5}$/.test(form.apiPort) ||
      Number(form.apiPort) < 1 ||
      Number(form.apiPort) > 65535 ||
      (Number(form.apiPort) === 8728 && form.useTls) ||
      (Number(form.apiPort) === 8729 && !form.useTls)
    )) {
      setError("Use plain Binary API on port 8728 or API-SSL on port 8729. A custom port must be explicitly configured and approved on the server.");
      return;
    }
    setSaving(true);
    setError(null);
    try {
      await apiPost("/mikrotik-configs", {
        name: form.name,
        host: form.host,
        apiTransport: form.apiTransport,
        ...(form.apiTransport === "telemetry" ? { routerIdentity: form.routerIdentity } : {}),
        ...(form.apiTransport === "binary" ? {
          apiPort: Number(form.apiPort),
          useTls: form.useTls,
          verifyCert: true,
        } : {}),
        ...(form.apiTransport !== "telemetry" ? {
          username: form.username,
          password: form.password,
        } : {}),
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
      await loadConfigs();
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
            Configure read-only management or router-originated HTTPS telemetry. Telemetry routers are never polled by this server.
          </p>
          {provider === "rest" && (
            <p className="text-xs text-blue-300 mt-2">
              HTTPS REST is the default for new routers; each router's saved transport can be selected independently.
            </p>
          )}
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
              Console connections issue only fixed read queries. The script generator creates a manual, reviewable setup file; this application never imports or executes it.
            </span>
          </div>
        </div>
      </div>

      <section aria-label="MikroTik connection interfaces" className="grid grid-cols-1 gap-3 lg:grid-cols-3">
        <div className="card p-4">
          <h2 className="text-sm font-semibold text-slate-100">Local Lab / HTTPS REST</h2>
          <p className="mt-2 text-xs leading-relaxed text-slate-400">
            The verified RB951-2n profile uses HTTPS REST with the configured CA certificate and
            read-only requests. Native Binary API remains a separate transport choice.
          </p>
        </div>
        <div className="card p-4">
          <h2 className="text-sm font-semibold text-slate-100">Cloud REST / cPanel</h2>
          <p className="mt-2 text-xs leading-relaxed text-slate-400">
            Uses HTTPS REST only over a verified private or VPN management path. If cPanel cannot route
            privately, use router-initiated HTTPS telemetry instead; Kali is not a production dependency.
          </p>
        </div>
        <div className="card p-4">
          <h2 className="text-sm font-semibold text-slate-100">Kali Bridge / Diagnostics</h2>
          <p className="mt-2 text-xs leading-relaxed text-slate-400">
            On-demand checks run in the Kali Linux terminal. This console does not execute Linux or
            RouterOS terminal commands and does not install a persistent bridge service.
          </p>
        </div>
      </section>

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
              restMode={cfg.apiTransport === "rest"}
              authorized={user?.role === "super_admin"}
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

      {/* Script Generator Section */}
      <div className="mt-8 border-t-2 border-noc-accent/30 pt-8">
        <MikroTikTerminalScriptGenerator authorized={user?.role === "super_admin"} />
      </div>
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

        {form.apiTransport === "telemetry" && (
          <FormField label="Exact RouterOS identity" required>
            <input
              type="text"
              required
              value={form.routerIdentity}
              onChange={(e) => setForm({ ...form, routerIdentity: e.target.value })}
              placeholder="As shown by /system identity print"
              className="input-field"
            />
          </FormField>
        )}

        <FormField label="API Transport">
          <select
            value={form.apiTransport}
            onChange={(e) => setForm({
              ...form,
              apiTransport: e.target.value === "telemetry" ? "telemetry" : e.target.value === "rest" ? "rest" : "binary",
            })}
            className="input-field"
          >
            <option value="binary">Binary API / API-SSL</option>
            <option value="rest">HTTPS REST</option>
            <option value="telemetry">Router-initiated telemetry (no inbound router API)</option>
          </select>
          <span className="mt-1 block text-[11px] text-slate-500">
            Each router uses its saved transport independently of other routers.
          </span>
        </FormField>

        {form.apiTransport === "telemetry" ? (
          <div className="md:col-span-2 rounded-lg border border-green-500/20 bg-green-500/5 p-3 text-xs text-green-200">
            The application will not connect to this host. It is stored as router management metadata only; the router will initiate verified HTTPS reports to this application. No router API username or password is stored for this mode.
          </div>
        ) : form.apiTransport === "rest" ? (
          <div className="md:col-span-2 rounded-lg border border-blue-500/20 bg-blue-500/5 p-3 text-xs text-blue-200">
            HTTPS REST uses the server-approved port with certificate-chain and hostname verification enforced. Router destination and transport policy are validated by the backend.
          </div>
        ) : (
          <>
            <FormField label="Binary API Port">
              <input
                type="number"
                min={1}
                max={65535}
                value={form.apiPort}
                onChange={(e) => setForm({ ...form, apiPort: e.target.value })}
                className="input-field"
              />
              <span className="mt-1 block text-[11px] text-slate-500">
                8728 = plain Binary API; 8729 = TLS-protected API-SSL. The saved port is the actual service port.
              </span>
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
                <span className="text-sm text-slate-300">Use TLS with API-SSL (normally port 8729)</span>
              </label>
            </FormField>

            {((form.useTls && Number(form.apiPort) === 8728) || (!form.useTls && Number(form.apiPort) === 8729)) && (
              <p className="md:col-span-2 text-xs text-amber-300" role="alert">
                The selected port and TLS mode conflict. Use 8728 without TLS or 8729 with TLS, unless the router has a verified custom port.
              </p>
            )}

            {!form.useTls && (
              <p className="md:col-span-2 text-xs text-amber-400">
                The binary API login sends credentials in plaintext when TLS is disabled. Prefer API-SSL on port 8729.
              </p>
            )}

            <div className="md:col-span-2 text-xs text-green-300">
              API-SSL/HTTPS certificate verification is enforced by the backend and cannot be disabled.
            </div>
          </>
        )}

        {form.apiTransport !== "telemetry" && (
          <>
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
          </>
        )}

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

      {form.apiTransport !== "telemetry" && (
        <div className="flex items-center gap-2 text-xs text-slate-500">
          <Lock className="w-3.5 h-3.5" />
          The password will be encrypted and stored securely. Once saved, it cannot be viewed — only deleted and re-entered.
        </div>
      )}

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
  restMode,
  authorized,
  connecting,
  connectResult,
  onConnect,
  onDelete,
  deleteConfirm,
  confirmDelete,
  cancelDelete,
}: {
  config: MikroTikConfig;
  restMode: boolean;
  authorized: boolean;
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
              {config.healthStatus === "misconfigured" || config.transportConfigured === false || config.apiTransport === null ? (
                <span className="inline-flex items-center gap-1.5 text-xs font-medium text-amber-300">
                  <span className="status-dot bg-amber-500" />
                  Misconfigured
                </span>
              ) : config.healthStatus === "stale" ? (
                <span className="inline-flex items-center gap-1.5 text-xs font-medium text-amber-300">
                  <span className="status-dot bg-amber-500" />
                  Stale
                </span>
              ) : config.healthStatus === "unknown" ? (
                <span className="inline-flex items-center gap-1.5 text-xs font-medium text-slate-400">
                  <span className="status-dot bg-slate-500" />
                  Waiting for report
                </span>
              ) : config.healthStatus === "offline" ? (
                <span className="inline-flex items-center gap-1.5 text-xs font-medium text-red-400">
                  <span className="status-dot bg-red-500" />
                  Offline
                </span>
              ) : config.healthStatus === "unauthorized" ? (
                <span className="inline-flex items-center gap-1.5 text-xs font-medium text-red-400">
                  <span className="status-dot bg-red-500" />
                  Unauthorized
                </span>
              ) : config.healthStatus === "degraded" ? (
                <span className="inline-flex items-center gap-1.5 text-xs font-medium text-amber-300">
                  <span className="status-dot bg-amber-500" />
                  Degraded
                </span>
              ) : config.isConnected ? (
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
              {config.transportConfigured === false || config.apiTransport === null ? (
                <span className="text-amber-300">Transport not selected · no connection attempted</span>
              ) : config.telemetryEnabled ? (
                <span className="text-green-400">Outbound HTTPS telemetry · no inbound API</span>
              ) : restMode ? (
                <span className="text-blue-400">HTTPS REST · server port</span>
              ) : (
                <>
                  <span>:{config.apiPort}</span>
                  {config.useTls && <span className="text-blue-400">API-SSL</span>}
                </>
              )}
              {config.apiTransport !== null && !config.telemetryEnabled && <span>@{config.username}</span>}
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
        {config.telemetryEnabled && (
          <div className="mb-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
            <span className={config.telemetryStale ? "text-amber-300" : "text-green-300"}>
              {config.telemetryStale ? "Telemetry stale / waiting" : "Telemetry online"}
            </span>
            <span className="text-slate-400">Last received: {config.telemetryReceivedAt ? new Date(config.telemetryReceivedAt).toLocaleString() : "never"}</span>
            {config.telemetryObservedAt && <span className="text-slate-500">Router clock: {new Date(config.telemetryObservedAt).toLocaleString()}</span>}
          </div>
        )}
        {!config.telemetryEnabled && config.lastSuccessfulCheckAt && (
          <p className="mb-4 text-xs text-slate-400">
            Last successful health check: {new Date(config.lastSuccessfulCheckAt).toLocaleString()}
          </p>
        )}
        {connecting ? (
          <div className="flex items-center gap-3 text-sm text-slate-300">
            <Loader2 className="w-4 h-4 animate-spin text-noc-accent" />
            <span>Connecting to {config.host}...</span>
            <div className="w-32 h-1 bg-noc-border rounded-full overflow-hidden ml-2">
              <div className="h-full bg-noc-accent animate-pulse" style={{ width: "60%" }} />
            </div>
          </div>
        ) : connectResult ? (
          <ConnectResultDisplay result={connectResult} config={config} restMode={restMode} onReconnect={onConnect} />
        ) : config.transportConfigured === false || config.apiTransport === null ? (
          <p className="text-xs text-amber-300">
            No supported transport is configured. Remove and re-add this router with an explicit Binary API, HTTPS REST, or telemetry selection.
          </p>
        ) : (
          <button
            onClick={onConnect}
            className="btn-primary flex items-center gap-2 text-sm"
          >
            <Lock className="w-4 h-4" />
            {config.telemetryEnabled ? "Check last report" : "Connect to Router"}
          </button>
        )}
        {config.telemetryEnabled && (
          <TelemetryProvisioner config={config} authorized={authorized} />
        )}
      </div>
    </div>
  );
}

function TelemetryProvisioner({ config, authorized }: { config: MikroTikConfig; authorized: boolean }) {
  const [intervalSeconds, setIntervalSeconds] = useState(5);
  const [serverCertificateVerified, setServerCertificateVerified] = useState(false);
  const [script, setScript] = useState("");
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const scriptFilename = `bradha-matu-telemetry-${config.id.slice(0, 8)}-v${MIKROTIK_TELEMETRY_GENERATOR_VERSION}.rsc`;
  const interval = `${intervalSeconds}s`;
  const importCommands = `/import file-name="${scriptFilename}"`;

  const issueScript = async () => {
    if (!window.confirm("This rotates the router's telemetry token immediately. Any previously installed script stops authenticating until replaced. Continue?")) {
      return;
    }
    setBusy(true);
    setMessage("");
    setScript("");
    try {
      const credential = await apiPost<{ token: string; endpoint: string }>(`/mikrotik-configs/${config.id}/telemetry-credential`);
      const generatedScript = generateMikroTikTelemetryScript({
        routerId: config.id,
        token: credential.token,
        intervalSeconds,
        endpoint: credential.endpoint,
        serverCertificateVerified,
      });
      setScript(generatedScript);
      setMessage("A new router-specific token was issued. Download/import this script only on the router shown by this configuration.");
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to issue the telemetry token.");
    } finally {
      setBusy(false);
    }
  };

  const copyScript = async () => {
    try {
      await copyRouterOsScript(script);
      setMessage("Sensitive script copied. Clear it after securely importing it.");
    } catch {
      setMessage("Clipboard access failed; select the script text manually.");
    }
  };

  const downloadScript = () => {
    try {
      downloadRouterOsScript(script, scriptFilename);
      setMessage("Sensitive .rsc file downloaded. Keep it private and delete it after import.");
    } catch {
      setMessage("Download failed. Copy the script from the text area instead.");
    }
  };

  return (
    <div className="mt-4 border-t border-noc-border pt-4">
      <div className="mb-4 rounded-lg border border-noc-border bg-slate-950/40 p-3 text-xs leading-relaxed text-slate-300">
        <h3 className="font-semibold text-slate-100">Add this router’s telemetry</h3>
        <ol className="mt-2 list-decimal space-y-1 pl-5">
          <li>Each router needs its own entry, identity, and revocable credential. Never reuse another router’s generated file or token.</li>
          <li>On this router, run the HTTPS preflight below. Keep certificate verification enabled; do not continue if it fails.</li>
          <li>Generate the current <strong>v{MIKROTIK_TELEMETRY_GENERATOR_VERSION}</strong> private <code>{scriptFilename}</code> file. It is a self-contained install package: importing it creates the read-only collector and its guarded scheduler for interval <code>{interval}</code>, then sends the first report immediately. The versioned filename distinguishes it from older downloads. Upload it in Winbox/WebFig <strong>Files</strong>, then run the single import command below in that router’s terminal.</li>
          <li>The collector tries <code>/interface wifi</code> first and falls back to legacy <code>/interface wireless</code>. It only issues read-only <code>find</code>/<code>get</code> queries plus one outbound HTTPS POST; it never modifies DHCP, ARP, firewall, queues, hotspot, addresses, routes, interfaces, users, or services, so client connectivity and billing are untouched. Confirm a fresh report in this console after import; verify each RouterOS model manually.</li>
        </ol>
        <p className="mt-2">
          Re-importing the same file is safe: the package updates only its own named script and scheduler and refuses to run if duplicates of those names exist. Do not rotate the token until ready to replace the file — rotation immediately invalidates the old token.
        </p>
        <pre className="mt-2 overflow-x-auto rounded bg-black/30 p-2 font-mono text-[11px]"><code>{importCommands}</code></pre>
        <p className="mt-2">
          New wireless MAC addresses are saved on the next accepted complete report. The Clients page reloads saved telemetry every five seconds; this does not run the MikroTik Scheduler. A DHCP lease alone does not prove a client is currently connected. Historical offline clients are retained.
        </p>
      </div>
      <div className="flex flex-wrap items-end gap-3">
        <label className="text-xs text-slate-400">
          Report interval
          <select
            value={intervalSeconds}
            disabled={!authorized || busy}
            onChange={(event) => setIntervalSeconds(Number(event.target.value))}
            className="input-field mt-1 block"
          >
            <option value={5}>5 seconds</option>
            <option value={10}>10 seconds</option>
            <option value={60}>60 seconds</option>
            <option value={120}>2 minutes</option>
            <option value={300}>5 minutes</option>
          </select>
        </label>
        <label className="flex max-w-xl items-start gap-2 text-xs text-slate-300">
          <input
            type="checkbox"
            checked={serverCertificateVerified}
            disabled={!authorized || busy}
            onChange={(event) => setServerCertificateVerified(event.target.checked)}
            className="mt-0.5"
          />
          I ran the HTTPS preflight on this router with <code>check-certificate=yes</code> and confirmed it succeeded for <code>{config.telemetryEndpoint ? new URL(config.telemetryEndpoint).host : "the configured application host"}</code>.
        </label>
        <button
          type="button"
          onClick={issueScript}
          disabled={!authorized || busy || !serverCertificateVerified}
          className="btn-secondary flex items-center gap-2 text-sm"
        >
          {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <RotateCcw className="h-4 w-4" />}
          {busy ? "Issuing token..." : config.telemetryCredentialIssued ? "Rotate token & generate script" : "Issue token & generate script"}
        </button>
        {!authorized && <span className="text-xs text-amber-300">Super administrator access is required.</span>}
      </div>
      <p className="mt-2 text-xs text-amber-200">
        RouterOS reported no certificates in the supplied output. First run <code>/tool fetch url="{config.telemetryEndpoint ? `${new URL(config.telemetryEndpoint).origin}/api/health` : "https://your-console-domain.example/api/health"}" check-certificate=yes output=none</code> manually. If it fails, do not continue: install and trust the correct public CA certificate, then repeat the test. Rotation invalidates the old token immediately.
      </p>
      {message && <p role="status" className="mt-2 text-xs text-slate-300">{message}</p>}
      {script && (
        <div className="mt-3 space-y-2">
          <textarea
            readOnly
            value={script}
            rows={12}
            aria-label="Generated RouterOS telemetry script"
            className="input-field w-full font-mono text-xs"
          />
          <div className="flex flex-wrap gap-2">
            <button type="button" onClick={copyScript} className="btn-secondary flex items-center gap-2 text-xs">
              <Clipboard className="h-4 w-4" /> Copy script
            </button>
            <button type="button" onClick={downloadScript} className="btn-secondary flex items-center gap-2 text-xs">
              <Download className="h-4 w-4" /> Download .rsc
            </button>
            <button type="button" onClick={() => { setScript(""); setMessage("Generated secret cleared from this page."); }} className="btn-secondary text-xs">
              Clear sensitive script
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

// ── Connect Result Display ───────────────────────────────────────

function ConnectResultDisplay({
  result,
  config,
  restMode,
  onReconnect,
}: {
  result: MikroTikConnectResult;
  config: MikroTikConfig;
  restMode: boolean;
  onReconnect: () => void;
}) {
  if (config.telemetryEnabled && !result.success) {
    return (
      <div className="flex items-start gap-3">
        <XCircle className="w-5 h-5 text-amber-400 flex-shrink-0 mt-0.5" />
        <div className="flex-1">
          <div className="text-sm font-medium text-amber-300">Waiting for router-originated telemetry</div>
          <div className="text-xs text-slate-400 mt-1">{result.message}</div>
        </div>
        <button onClick={onReconnect} className="btn-secondary text-xs">Refresh</button>
      </div>
    );
  }

  if (!result.success || !result.router) {
    if (config.telemetryEnabled) {
      return (
        <div className="flex items-start gap-3">
          <XCircle className="w-5 h-5 text-amber-400 flex-shrink-0 mt-0.5" />
          <div className="flex-1">
            <div className="text-sm font-medium text-amber-300">Waiting for router-originated telemetry</div>
            <div className="text-xs text-slate-400 mt-1">{result.message}</div>
          </div>
          <button onClick={onReconnect} className="btn-secondary text-xs">Refresh</button>
        </div>
      );
    }
    return (
      <div className="space-y-3">
        <div className="flex items-start gap-3">
          <XCircle className="w-5 h-5 text-red-400 flex-shrink-0 mt-0.5" />
          <div className="flex-1">
            <div className="text-sm font-medium text-red-400">Connection Failed</div>
            <div className="text-xs text-slate-400 mt-1 font-mono break-all">{result.message}</div>
            <div className="mt-3 rounded-lg border border-noc-border bg-noc-panel/50 p-3 text-xs text-slate-400">
              <div className="font-medium text-slate-300">Safe diagnosis — check from the machine running Laravel</div>
              <p className="mt-1">
                Current saved target: {config.host}{restMode ? "" : `:${config.apiPort}`} · {restMode ? "HTTPS REST" : config.useTls ? "Binary API-SSL" : "plain Binary API"}.
              </p>
              <ul className="mt-1.5 list-disc space-y-1 pl-4">
                <li>Router reachability and the private route/VPN between Laravel and the management network.</li>
                <li>Saved port and TLS pairing: plain API is normally 8728; API-SSL is normally 8729.</li>
                <li>RouterOS API/API-SSL service is enabled and listening on the configured port.</li>
                <li>Dedicated account, password, and read/API permissions are correct.</li>
                <li>Router service address restrictions and router/firewall rules allow only this server's approved source.</li>
                <li>Timeouts may indicate an unreachable endpoint or blocked route; changing the port alone does not establish connectivity.</li>
              </ul>
              <p className="mt-2">Do not expose management ports publicly or disable TLS verification to troubleshoot.</p>
            </div>
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

      <div className="text-xs text-slate-400">
        Live read completed at {result.observations?.observedAt
          ? new Date(result.observations.observedAt).toLocaleString()
          : "the successful health check time"}.
      </div>
      <ReadOnlySnapshotTable title="Interfaces" result={result.observations?.interfaces} columns={["name", "type", "running", "rx-byte", "tx-byte"]} />
      <ReadOnlySnapshotTable title="IP addresses" result={result.observations?.addresses} columns={["address", "interface"]} />
      <ReadOnlySnapshotTable title="Routes" result={result.observations?.routes} columns={["dst-address", "gateway", "distance", "active"]} />
      <ReadOnlySnapshotTable title="DHCP leases" result={result.observations?.dhcpLeases} columns={["address", "mac-address", "host-name", "interface", "status", "expires-after"]} />
    </div>
  );
}

function ReadOnlySnapshotTable({
  title,
  result,
  columns,
}: {
  title: string;
  result: MikroTikReadResult | undefined;
  columns: string[];
}) {
  if (!result) {
    return <p className="text-xs text-slate-500">{title}: no live data was returned.</p>;
  }
  if (result.error) {
    return (
      <div className="rounded-lg border border-amber-500/20 bg-amber-500/5 p-3">
        <div className="text-xs font-medium text-amber-300">{title} unavailable ({result.error.category})</div>
        <div className="mt-1 text-xs text-slate-400">{result.error.message}</div>
      </div>
    );
  }

  return (
    <section className="overflow-hidden rounded-lg border border-noc-border">
      <h3 className="border-b border-noc-border bg-noc-panel px-3 py-2 text-xs font-semibold text-slate-200">
        {title} · {result.records.length}
      </h3>
      {result.records.length === 0 ? (
        <p className="p-3 text-xs text-slate-500">No records reported.</p>
      ) : (
        <div className="table-scroll">
          <table className="min-w-full text-left text-xs">
            <thead className="text-slate-500">
              <tr>{columns.map((column) => <th key={column} className="px-3 py-2 font-medium">{column}</th>)}</tr>
            </thead>
            <tbody>
              {result.records.map((record, index) => (
                <tr key={`${String(record[".id"] ?? record.name ?? record.address ?? index)}-${index}`} className="border-t border-noc-border/50">
                  {columns.map((column) => (
                    <td key={column} className="whitespace-nowrap px-3 py-2 font-mono text-slate-300">
                      {record[column] === null || record[column] === undefined || record[column] === ""
                        ? "—"
                        : String(record[column])}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
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
