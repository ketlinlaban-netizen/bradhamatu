import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { Wifi, Lock, Mail, ShieldCheck, AlertCircle } from "lucide-react";
import { useAuth } from "../auth/AuthContext";

export function LoginPage() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    setLoading(true);
    const result = await login(email, password);
    setLoading(false);
    if (result.success) {
      navigate("/");
    } else {
      setError(result.error ?? "Login failed");
    }
  };

  return (
    <div className="min-h-screen flex flex-col items-center justify-center px-4 py-8 sm:px-6 bg-noc-bg relative overflow-hidden">
      {/* background grid */}
      <div
        className="absolute inset-0 opacity-[0.03] pointer-events-none"
        style={{
          backgroundImage: "linear-gradient(#fff 1px, transparent 1px), linear-gradient(90deg, #fff 1px, transparent 1px)",
          backgroundSize: "40px 40px",
        }}
      />
      {/* glow */}
      <div className="absolute top-1/4 left-1/2 -translate-x-1/2 w-72 h-72 sm:w-96 sm:h-96 bg-noc-accent/10 rounded-full blur-[100px] sm:blur-[120px] pointer-events-none" />

      <div className="relative w-full max-w-md animate-slide-up flex flex-col">
        {/* brand */}
        <div className="text-center mb-6 sm:mb-8">
          <div className="inline-flex items-center justify-center w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-gradient-to-br from-noc-accent to-noc-accent2 mb-3 sm:mb-4 shadow-lg shadow-noc-accent/20">
            <Wifi className="w-7 h-7 sm:w-8 sm:h-8 text-white" />
          </div>
          <h1 className="text-xl sm:text-2xl font-bold text-slate-100">Community WiFi</h1>
          <p className="text-sm text-slate-400">Bradha Matu</p>
          <p className="mt-2 sm:mt-3 text-[10px] sm:text-xs font-mono uppercase tracking-widest text-slate-500">
            Network Operations Console
          </p>
        </div>

        <div className="card p-5 sm:p-8">
          <h2 className="text-base sm:text-lg font-semibold text-slate-100 mb-1">Administrator Console</h2>
          <p className="text-xs sm:text-sm text-slate-400 mb-5 sm:mb-6">Sign in to access the read-only monitoring dashboard.</p>

          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="block text-xs font-medium text-slate-400 mb-1.5">Email</label>
              <div className="relative">
                <Mail className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500 flex-shrink-0" />
                <input
                  type="text"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  className="input-field pl-10 text-sm"
                  placeholder="admin@communitywifi"
                  autoComplete="username"
                />
              </div>
            </div>

            <div>
              <label className="block text-xs font-medium text-slate-400 mb-1.5">Password</label>
              <div className="relative">
                <Lock className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500 flex-shrink-0" />
                <input
                  type="password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  className="input-field pl-10 text-sm"
                  placeholder="••••••••"
                  autoComplete="current-password"
                />
              </div>
            </div>

            {error && (
              <div className="flex items-start gap-2 text-sm text-red-400 bg-red-500/10 border border-red-500/20 rounded-lg px-3 py-2.5">
                <AlertCircle className="w-4 h-4 flex-shrink-0 mt-0.5" />
                <span className="break-words">{error}</span>
              </div>
            )}

            <button
              type="submit"
              disabled={loading}
              className="btn-primary w-full flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed py-2.5"
            >
              {loading ? (
                <>
                  <div className="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                  <span className="text-sm">Authenticating...</span>
                </>
              ) : (
                <span className="text-sm">Sign In</span>
              )}
            </button>
          </form>

          <div className="mt-5 sm:mt-6 pt-5 sm:pt-6 border-t border-noc-border">
            <div className="flex items-center gap-2 text-xs text-slate-500 mb-3">
              <ShieldCheck className="w-4 h-4 text-green-500 flex-shrink-0" />
              <span>All sessions are strictly read-only.</span>
            </div>
            <div className="text-xs text-slate-500">
              <div className="font-medium text-slate-400 mb-1">Authentication:</div>
              <div className="text-[11px] text-slate-500">Credentials are verified against the Laravel backend (Sanctum).</div>
            </div>
          </div>
        </div>

        {/* credit */}
        <div className="mt-6 sm:mt-8 text-center">
          <p className="text-xs text-slate-500">
            Powered by{" "}
            <span className="font-semibold text-slate-400 tracking-wide">Pandatechs Softwares</span>
          </p>
        </div>
      </div>
    </div>
  );
}
