import { ShieldCheck, Lock, Database, Server, Code, Wifi, CheckCircle2 } from "lucide-react";
import { useAuth } from "../auth/AuthContext";

export function SystemPage() {
  const { user } = useAuth();

  const architecture = [
    { layer: "Frontend (React + Vite)", desc: "NOC dashboard — dark professional interface, responsive", icon: Wifi },
    { layer: "API Layer (Laravel REST)", desc: "GET-only endpoints, no mutation routes exposed", icon: Server },
    { layer: "RouterService", desc: "Business logic, delegates to provider via interface", icon: Code },
    { layer: "RouterReadOnlyGuard", desc: "Classifies every operation as READ or WRITE, blocks WRITE", icon: ShieldCheck },
    { layer: "MikroTikApiClient", desc: "Binary API protocol (TCP 8728/8729) with REST API fallback", icon: Database },
  ];

  const securityPrinciples = [
    { text: "100% read-only — no write endpoints exist in the API", status: "enforced" },
    { text: "ReadOnlyGuard blocks WRITE operations at service layer", status: "enforced" },
    { text: "No reboot, shutdown, config, firewall, or queue modification", status: "enforced" },
    { text: "No terminal, command execution, or write API calls", status: "enforced" },
    { text: "Live data from MikroTik binary API with REST fallback", status: "enforced" },
    { text: "Role-based access (super_admin, admin, viewer) — all read-only", status: "enforced" },
  ];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-100">System</h1>
        <p className="text-sm text-slate-400 mt-1">Architecture, security model, and system information.</p>
      </div>

      <div className="card p-5">
        <h2 className="text-lg font-semibold text-slate-100 mb-4">System Architecture</h2>
        <div className="space-y-3">
          {architecture.map((a, i) => {
            const Icon = a.icon;
            return (
              <div key={a.layer} className="flex items-center gap-4">
                <div className="w-10 h-10 rounded-lg bg-noc-accent/10 flex items-center justify-center flex-shrink-0">
                  <Icon className="w-5 h-5 text-noc-accent" />
                </div>
                <div className="flex-1">
                  <div className="text-sm font-medium text-slate-100">{a.layer}</div>
                  <div className="text-xs text-slate-400">{a.desc}</div>
                </div>
                {i < architecture.length - 1 && (
                  <div className="hidden sm:block text-slate-600 text-xl">↓</div>
                )}
              </div>
            );
          })}
        </div>
      </div>

      <div className="card p-5 border-green-500/20 bg-green-500/5">
        <div className="flex items-center gap-3 mb-4">
          <ShieldCheck className="w-5 h-5 text-green-500" />
          <h2 className="text-lg font-semibold text-slate-100">Read-Only Security Model</h2>
        </div>
        <div className="space-y-2">
          {securityPrinciples.map((p) => (
            <div key={p.text} className="flex items-center gap-3 text-sm">
              <CheckCircle2 className={`w-4 h-4 flex-shrink-0 ${p.status === "enforced" ? "text-green-500" : "text-blue-400"}`} />
              <span className="text-slate-300">{p.text}</span>
              <span className={`text-xs px-2 py-0.5 rounded font-mono uppercase ${
                p.status === "enforced" ? "bg-green-500/10 text-green-400" : "bg-blue-500/10 text-blue-400"
              }`}>
                {p.status}
              </span>
            </div>
          ))}
        </div>
      </div>

      <div className="card p-5">
        <h2 className="text-lg font-semibold text-slate-100 mb-4">Data Flow (Live)</h2>
        <div className="rounded-lg border border-noc-border p-4 bg-noc-panel">
          <div className="text-sm text-slate-300 font-mono space-y-1">
            <div>React Frontend</div>
            <div className="text-slate-500">↓ HTTP GET (Bearer token)</div>
            <div>Laravel API (Sanctum auth)</div>
            <div className="text-slate-500">↓</div>
            <div>RouterService → ReadOnlyGuard</div>
            <div className="text-slate-500">↓</div>
            <div className="text-noc-accent">MikroTikRouterProvider</div>
            <div className="text-slate-500">↓ Binary API (TCP 8728/8729)</div>
            <div className="text-noc-accent">MikroTik Router(s)</div>
          </div>
        </div>
        <p className="text-xs text-slate-500 mt-4">
          All data is live from MikroTik routers. No mock data. Binary API is primary, REST API is fallback.
        </p>
      </div>

      <div className="card p-5">
        <h2 className="text-lg font-semibold text-slate-100 mb-4">Current Session</h2>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
          <div>
            <div className="text-xs text-slate-400 mb-1">Administrator</div>
            <div className="text-slate-200 font-medium">{user?.name}</div>
          </div>
          <div>
            <div className="text-xs text-slate-400 mb-1">Email</div>
            <div className="text-slate-200 font-mono text-xs">{user?.email}</div>
          </div>
          <div>
            <div className="text-xs text-slate-400 mb-1">Role</div>
            <div className="text-slate-200 font-medium capitalize">{user?.role.replace("_", " ")}</div>
          </div>
        </div>
        <div className="mt-4 flex items-center gap-2 text-xs text-slate-500">
          <Lock className="w-3.5 h-3.5 text-green-500" />
          Session is read-only. No router mutations are possible from this console.
        </div>
      </div>

      <div className="card p-5">
        <h2 className="text-lg font-semibold text-slate-100 mb-4">Technology Stack</h2>
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
          {[
            { label: "Frontend", value: "React 18 + Vite" },
            { label: "Language", value: "TypeScript" },
            { label: "Styling", value: "Tailwind CSS" },
            { label: "Charts", value: "Recharts" },
            { label: "Backend", value: "Laravel 11" },
            { label: "Database", value: "MySQL / PostgreSQL" },
            { label: "Router API", value: "MikroTik RouterOS" },
            { label: "Auth", value: "Laravel Sanctum" },
          ].map((t) => (
            <div key={t.label} className="rounded-lg border border-noc-border p-3 bg-noc-panel">
              <div className="text-xs text-slate-400">{t.label}</div>
              <div className="text-sm text-slate-200 font-medium mt-0.5">{t.value}</div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
