import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Wifi, ArrowRight, Check, Phone, MapPin, Zap, Clock, Users } from "lucide-react";
import { getPublicPackages } from "../../services/customerApi";
import type { Package } from "../../types/billing";

export function PublicHomePage() {
  const [packages, setPackages] = useState<Package[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    getPublicPackages()
      .then(setPackages)
      .catch(() => setPackages([]))
      .finally(() => setLoading(false));
  }, []);

  return (
    <div className="min-h-screen bg-noc-bg text-slate-200">
      {/* Hero */}
      <header className="relative overflow-hidden">
        <div className="absolute inset-0 bg-gradient-to-br from-noc-accent/10 via-transparent to-noc-accent2/10" />
        <div className="relative max-w-5xl mx-auto px-4 pt-20 pb-24 text-center">
          <div className="inline-flex items-center gap-3 mb-6">
            <div className="w-14 h-14 rounded-xl bg-gradient-to-br from-noc-accent to-noc-accent2 flex items-center justify-center">
              <Wifi className="w-7 h-7 text-white" />
            </div>
            <div className="text-left">
              <div className="text-xl font-bold text-slate-100">Community WiFi</div>
              <div className="text-sm text-slate-400">Bradha Matu</div>
            </div>
          </div>
          <h1 className="text-4xl md:text-5xl font-bold text-slate-100 mb-4 leading-tight">
            Fast, affordable internet<br />for the whole community
          </h1>
          <p className="text-lg text-slate-400 mb-8 max-w-xl mx-auto">
            Pay with M-Pesa and get connected instantly. Choose from our flexible packages below.
          </p>
          <div className="flex flex-col sm:flex-row gap-3 justify-center">
            <Link to="/customer/register" className="btn-primary inline-flex items-center gap-2 text-base px-6 py-3">
              Get Started <ArrowRight className="w-4 h-4" />
            </Link>
            <Link to="/customer/login" className="btn-secondary inline-flex items-center gap-2 text-base px-6 py-3">
              Login to Your Account
            </Link>
          </div>
        </div>
      </header>

      {/* Packages */}
      <section className="max-w-5xl mx-auto px-4 py-16">
        <h2 className="text-2xl font-bold text-slate-100 mb-2 text-center">Internet Packages</h2>
        <p className="text-slate-400 text-center mb-10">Prices in Kenyan Shillings. Pay via M-Pesa STK Push.</p>
        {loading ? (
          <div className="text-center py-12 text-slate-400">Loading packages...</div>
        ) : packages.length === 0 ? (
          <div className="text-center py-12 text-slate-500">No packages available at this time. Please check back later.</div>
        ) : (
          <div className="grid md:grid-cols-3 gap-6">
            {packages.map((pkg) => (
              <div
                key={pkg.id}
                className="card p-6 flex flex-col hover:border-noc-accent/40 transition-all hover:bg-noc-hover"
              >
                <div className="mb-4">
                  <h3 className="text-lg font-bold text-slate-100">{pkg.name}</h3>
                  {pkg.description && <p className="text-sm text-slate-400 mt-1">{pkg.description}</p>}
                </div>
                <div className="mb-4">
                  <span className="text-3xl font-bold text-noc-accent">KSh {pkg.price_ksh.toLocaleString()}</span>
                  <span className="text-sm text-slate-500 ml-1">/ {pkg.duration_display}</span>
                </div>
                <ul className="space-y-2 mb-6 flex-1">
                  <li className="flex items-center gap-2 text-sm text-slate-300">
                    <Check className="w-4 h-4 text-green-500" />
                    {pkg.download_kbps > 0 ? `${pkg.download_kbps / 1000} Mbps download` : "Unlimited download"}
                  </li>
                  <li className="flex items-center gap-2 text-sm text-slate-300">
                    <Check className="w-4 h-4 text-green-500" />
                    {pkg.upload_kbps > 0 ? `${pkg.upload_kbps / 1000} Mbps upload` : "Unlimited upload"}
                  </li>
                  <li className="flex items-center gap-2 text-sm text-slate-300">
                    <Check className="w-4 h-4 text-green-500" />
                    Up to {pkg.max_devices} device{pkg.max_devices > 1 ? "s" : ""}
                  </li>
                  <li className="flex items-center gap-2 text-sm text-slate-300">
                    <Check className="w-4 h-4 text-green-500" />
                    {pkg.access_type === "hotspot" ? "Hotspot access" : "PPPoE access"}
                  </li>
                </ul>
                <Link to="/customer/register" className="btn-primary w-full text-center">
                  Get Connected
                </Link>
              </div>
            ))}
          </div>
        )}
      </section>

      {/* How to Connect */}
      <section className="max-w-5xl mx-auto px-4 py-16 border-t border-noc-border">
        <h2 className="text-2xl font-bold text-slate-100 mb-10 text-center">How to Get Connected</h2>
        <div className="grid md:grid-cols-4 gap-6">
          {[
            { icon: Users, title: "1. Register", desc: "Create your account with your phone number" },
            { icon: Zap, title: "2. Choose a Package", desc: "Pick the package that fits your needs" },
            { icon: Phone, title: "3. Pay via M-Pesa", desc: "Enter your M-Pesa PIN when prompted on your phone" },
            { icon: Clock, title: "4. Get Connected", desc: "Your internet activates automatically after payment" },
          ].map((step) => (
            <div key={step.title} className="text-center">
              <div className="w-12 h-12 rounded-xl bg-noc-accent/10 flex items-center justify-center mx-auto mb-3">
                <step.icon className="w-6 h-6 text-noc-accent" />
              </div>
              <h3 className="text-sm font-semibold text-slate-200 mb-1">{step.title}</h3>
              <p className="text-xs text-slate-500">{step.desc}</p>
            </div>
          ))}
        </div>
      </section>

      {/* Coverage */}
      <section className="max-w-5xl mx-auto px-4 py-16 border-t border-noc-border">
        <div className="flex items-start gap-4">
          <MapPin className="w-6 h-6 text-noc-accent flex-shrink-0 mt-1" />
          <div>
            <h2 className="text-xl font-bold text-slate-100 mb-2">Service Area</h2>
            <p className="text-slate-400 text-sm">
              Community WiFi Bradha Matu serves the Bradha Matu community and surrounding areas.
              Contact support to confirm coverage at your location.
            </p>
          </div>
        </div>
      </section>

      {/* Footer */}
      <footer className="border-t border-noc-border py-8 mt-8">
        <div className="max-w-5xl mx-auto px-4 text-center text-sm text-slate-500">
          <p>Community WiFi Bradha Matu &mdash; Powered by Pandatechs Softwares</p>
          <p className="mt-1">By using this service you agree to our Terms of Service and Acceptable Use Policy.</p>
        </div>
      </footer>
    </div>
  );
}
