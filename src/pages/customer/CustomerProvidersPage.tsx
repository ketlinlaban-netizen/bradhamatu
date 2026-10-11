import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { Search, Star } from "lucide-react";
import { getCustomerPackages } from "../../services/customerApi";
import type { Package } from "../../types/billing";
import { CustomerMobileShell } from "./CustomerMobileShell";

const accessOptions = ["all", "home", "fiber", "business", "hotspot"] as const;

function normalizeAccess(value: string): string {
  const lower = value.toLowerCase();
  if (lower.includes("hotspot")) return "hotspot";
  if (lower.includes("fiber") || lower.includes("fibre")) return "fiber";
  if (lower.includes("business") || lower.includes("enterprise")) return "business";
  return "home";
}

export function CustomerProvidersPage() {
  const [packages, setPackages] = useState<Package[]>([]);
  const [loading, setLoading] = useState(true);
  const [query, setQuery] = useState("");
  const [filter, setFilter] = useState<(typeof accessOptions)[number]>("all");

  useEffect(() => {
    void (async () => {
      try {
        const data = await getCustomerPackages();
        setPackages(data);
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  const filteredPackages = useMemo(() => {
    const term = query.trim().toLowerCase();
    return packages.filter((pkg) => {
      const matchesText = !term || `${pkg.name} ${pkg.description ?? ""}`.toLowerCase().includes(term);
      const category = normalizeAccess(pkg.access_type || pkg.name);
      const matchesFilter = filter === "all" || category === filter;
      return matchesText && matchesFilter;
    });
  }, [filter, packages, query]);

  return (
    <CustomerMobileShell title="Internet Providers">
      <div className="space-y-4 pb-24">
        <div className="rounded-2xl border border-slate-800 bg-slate-900/80 p-3">
          <label className="flex items-center gap-2 rounded-xl border border-slate-700 bg-slate-950/60 px-3 py-2 text-sm text-slate-300">
            <Search className="h-4 w-4 text-slate-400" />
            <input
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder="Search providers..."
              className="w-full bg-transparent text-sm text-white placeholder:text-slate-500 focus:outline-none"
            />
          </label>
        </div>

        <div className="flex gap-2 overflow-x-auto pb-1">
          {accessOptions.map((option) => (
            <button
              key={option}
              type="button"
              onClick={() => setFilter(option)}
              className={`whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-medium ${
                filter === option ? "bg-blue-500 text-white" : "border border-slate-700 bg-slate-900/80 text-slate-300"
              }`}
            >
              {option === "all" ? "All" : option.charAt(0).toUpperCase() + option.slice(1)}
            </button>
          ))}
        </div>

        {loading ? (
          <div className="rounded-2xl border border-slate-800 bg-slate-900/80 p-4 text-sm text-slate-400">Loading provider catalog…</div>
        ) : filteredPackages.length === 0 ? (
          <div className="rounded-2xl border border-slate-800 bg-slate-900/80 p-4 text-sm text-slate-400">No packages match the current filter.</div>
        ) : (
          <div className="space-y-3">
            {filteredPackages.map((pkg) => {
              const category = normalizeAccess(pkg.access_type || pkg.name);
              const detailUrl = `/customer/providers/${pkg.id}`;

              return (
                <div key={pkg.id} className="rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <div className="text-xs uppercase tracking-[0.2em] text-slate-500">{category}</div>
                      <div className="mt-2 text-lg font-semibold text-white">{pkg.name}</div>
                    </div>
                    <div className="flex items-center gap-1 rounded-full border border-amber-500/20 bg-amber-500/10 px-2 py-1 text-xs text-amber-300">
                      <Star className="h-3.5 w-3.5" />
                      No public rating
                    </div>
                  </div>

                  <p className="mt-3 text-sm text-slate-400">{pkg.description || "No description available."}</p>

                  <div className="mt-4 grid grid-cols-3 gap-2 text-center text-xs text-slate-300">
                    <div className="rounded-2xl bg-slate-950/60 p-2">
                      <div className="text-slate-500">Speed</div>
                      <div className="mt-1 font-semibold text-white">{pkg.download_kbps > 0 ? `${Math.round(pkg.download_kbps / 1000)} Mbps` : "Unlimited"}</div>
                    </div>
                    <div className="rounded-2xl bg-slate-950/60 p-2">
                      <div className="text-slate-500">Support</div>
                      <div className="mt-1 font-semibold text-white">{pkg.access_type}</div>
                    </div>
                    <div className="rounded-2xl bg-slate-950/60 p-2">
                      <div className="text-slate-500">Price</div>
                      <div className="mt-1 font-semibold text-white">KSh {pkg.price_ksh.toLocaleString()}</div>
                    </div>
                  </div>

                  <div className="mt-4 flex items-center justify-between">
                    <div className="text-sm text-slate-300">{pkg.duration_display}</div>
                    <Link to={detailUrl} className="rounded-full bg-blue-500 px-3 py-1.5 text-xs font-medium text-white">
                      View Plans
                    </Link>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>
    </CustomerMobileShell>
  );
}
