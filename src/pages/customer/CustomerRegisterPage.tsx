import { useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { Wifi, User, Phone, Mail, Lock, ArrowLeft, AlertCircle } from "lucide-react";
import { useCustomerAuth } from "../../auth/CustomerAuthContext";

export function CustomerRegisterPage() {
  const { register } = useCustomerAuth();
  const navigate = useNavigate();
  const [fullName, setFullName] = useState("");
  const [phone, setPhone] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [termsAccepted, setTermsAccepted] = useState(false);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    if (!termsAccepted) {
      setError("You must accept the Terms of Service to register");
      return;
    }
    if (password.length < 6) {
      setError("Password must be at least 6 characters");
      return;
    }
    setLoading(true);
    const result = await register({
      full_name: fullName,
      phone,
      email: email || undefined,
      password,
      terms_accepted: termsAccepted,
    });
    setLoading(false);
    if (result.success) {
      navigate("/customer/dashboard");
    } else {
      setError(result.error || "Registration failed");
    }
  };

  return (
    <div className="min-h-screen bg-noc-bg flex flex-col justify-center px-4 py-8">
      <div className="max-w-md mx-auto w-full">
        <Link to="/" className="inline-flex items-center gap-2 text-slate-400 hover:text-white mb-6 text-sm">
          <ArrowLeft className="w-4 h-4" /> Back to home
        </Link>
        <div className="card p-8">
          <div className="flex items-center gap-3 mb-6">
            <div className="w-12 h-12 rounded-xl bg-gradient-to-br from-noc-accent to-noc-accent2 flex items-center justify-center">
              <Wifi className="w-6 h-6 text-white" />
            </div>
            <div>
              <h1 className="text-xl font-bold text-slate-100">Create Account</h1>
              <p className="text-xs text-slate-400">Community WiFi Bradha Matu</p>
            </div>
          </div>
          {error && (
            <div className="mb-4 p-3 rounded-lg bg-red-500/10 border border-red-500/20 flex items-center gap-2 text-sm text-red-400">
              <AlertCircle className="w-4 h-4 flex-shrink-0" /> {error}
            </div>
          )}
          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="block text-sm text-slate-400 mb-1.5">Full Name</label>
              <div className="relative">
                <User className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" />
                <input type="text" value={fullName} onChange={(e) => setFullName(e.target.value)} required className="input-field pl-10" placeholder="John Doe" />
              </div>
            </div>
            <div>
              <label className="block text-sm text-slate-400 mb-1.5">Phone Number (Safaricom)</label>
              <div className="relative">
                <Phone className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" />
                <input type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} required className="input-field pl-10" placeholder="07XXXXXXXX" />
              </div>
              <p className="text-xs text-slate-500 mt-1">This is the number your M-Pesa STK Push will be sent to.</p>
            </div>
            <div>
              <label className="block text-sm text-slate-400 mb-1.5">Email (optional)</label>
              <div className="relative">
                <Mail className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" />
                <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} className="input-field pl-10" placeholder="you@example.com" />
              </div>
            </div>
            <div>
              <label className="block text-sm text-slate-400 mb-1.5">Password</label>
              <div className="relative">
                <Lock className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" />
                <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required className="input-field pl-10" placeholder="Minimum 6 characters" />
              </div>
            </div>
            <label className="flex items-start gap-2 cursor-pointer text-sm text-slate-400">
              <input type="checkbox" checked={termsAccepted} onChange={(e) => setTermsAccepted(e.target.checked)} className="mt-0.5" />
              <span>I agree to the Terms of Service and Acceptable Use Policy</span>
            </label>
            <button type="submit" disabled={loading} className="btn-primary w-full disabled:opacity-50">
              {loading ? "Creating account..." : "Create Account"}
            </button>
          </form>
          <p className="mt-6 text-center text-sm text-slate-400">
            Already have an account?{" "}
            <Link to="/customer/login" className="text-noc-accent hover:text-blue-400">
              Sign in
            </Link>
          </p>
        </div>
      </div>
    </div>
  );
}
