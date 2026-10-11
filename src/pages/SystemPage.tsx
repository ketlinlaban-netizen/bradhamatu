import { useEffect, useState } from "react";
import { ShieldCheck, Lock, Database, Server, Code, Wifi, CheckCircle2 } from "lucide-react";
import { useAuth } from "../auth/AuthContext";
import { apiGet } from "../services/api";

export function SystemPage() {
  const { user } = useAuth();
  const [provider, setProvider] = useState<"mock" | "mikrotik" | "rest" | "unavailable">("unavailable");

  useEffect(() => {
    apiGet<{ provider: "mock" | "mikrotik" | "rest" }>("/health")
      .then((health) => setProvider(health.provider))
      .catch(() => setProvider("unavailable"));
  }, []);

  const architecture = [
    { layer: "Frontend (React + Vite)", desc: "NOC dashboard — responsive interface", icon: Wifi },
    { layer: "API Layer (Laravel REST)", desc: "Sanctum-protected reads and super-admin inventory management", icon: Server },
    { layer: "RouterService", desc: "Business logic, delegates to provider via interface", icon: Code },
    { layer: "RouterReadOnlyGuard", desc: "Allows only fixed read commands; rejects RouterOS writes", icon: ShieldCheck },
    { layer: "RouterOS transport", desc: "Server-selected binary API or HTTPS REST; both use the same read-only contract", icon: Database },
  ];

  const securityPrinciples = [
    { text: "RouterOS access is read-only; inventory changes affect Laravel records only", status: "enforced" },
    { text: "ReadOnlyGuard and a fixed command allowlist block RouterOS writes", status: "enforced" },
    { text: "No reboot, shutdown, config, firewall, or queue modification", status: "enforced" },
    { text: "No terminal, command execution, or write API calls", status: "enforced" },
    { text: "Router credentials are encrypted at rest and masked in API responses", status: "enforced" },
    { text: "Administrator authorization protects router inventory management", status: "enforced" },
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
        <h2 className="text-lg font-semibold text-slate-100 mb-4">Data Flow</h2>
        <div className="rounded-lg border border-noc-border p-4 bg-noc-panel">
          <div className="text-sm text-slate-300 font-mono space-y-1">
            <div>React Frontend</div>
            <div className="text-slate-500">↓ HTTP / Sanctum</div>
            <div>Laravel API (Sanctum auth)</div>
            <div className="text-slate-500">↓</div>
            <div>RouterService → ReadOnlyGuard</div>
            <div className="text-slate-500">↓</div>
            <div className="text-noc-accent">
              {provider === "mock"
                ? "MockRouterProvider (demo only)"
                : provider === "mikrotik"
                  ? "MikroTikRouterProvider"
                  : provider === "rest"
                    ? "RouterRestProvider"
                  : "Provider unavailable"}
            </div>
            {(provider === "mikrotik" || provider === "rest") && (
              <>
                <div className="text-slate-500">
                  {provider === "rest" ? "↓ HTTPS REST (TLS verified)" : "↓ Binary API (TCP 8728/8729)"}
                </div>
                <div className="text-noc-accent">MikroTik Router(s)</div>
              </>
            )}
          </div>
        </div>
        <p className="text-xs text-slate-500 mt-4">
          {provider === "mock"
            ? "Demo mode is active. Router information is fictional and no physical router is contacted."
            : provider === "mikrotik"
              ? "The live provider reads the Laravel router inventory and connects through the MikroTik binary API."
              : provider === "rest"
                ? "The live provider reads the Laravel router inventory through HTTPS REST with certificate validation."
              : "Provider configuration is unavailable; the active data source could not be verified."}
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
            { label: "Backend", value: "Laravel 13" },
            { label: "Database", value: "MySQL / PostgreSQL" },
            { label: "Router API", value: provider === "rest" ? "RouterOS HTTPS REST" : "RouterOS Binary API / REST" },
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
