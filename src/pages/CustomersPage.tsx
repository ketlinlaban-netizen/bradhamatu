import { useEffect, useState, useCallback } from "react";
import { Search, Eye, ArrowLeft } from "lucide-react";
import { getAdminCustomers, getAdminCustomer, type AdminPage } from "../services/adminBillingApi";
import { LoadingSpinner, EmptyState } from "../components/ui";

interface Customer {
  id: string;
  full_name: string;
  phone_normalized: string;
  phone_display: string;
  email: string | null;
  status: string;
  purchases_count?: number;
  service_accounts_count?: number;
  created_at: string;
}

interface CustomerDetail {
  id: string;
  full_name: string;
  phone_normalized: string;
  phone_display: string;
  email: string | null;
  status: string;
  purchases: Array<{
    id: string;
    status: string;
    package_snapshot: { name: string; price_minor: number };
    created_at: string;
    payments: Array<{ id: string; state: string; amount_minor: number }>;
    serviceAccount: { id: string; username: string; activation_status: string; expires_at: string | null } | null;
  }>;
  supportTickets: Array<{ id: string; subject: string; status: string }>;
}

export function CustomersPage() {
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [detail, setDetail] = useState<CustomerDetail | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const result = await getAdminCustomers(search);
      const data = result as unknown as AdminPage<Customer>;
      setCustomers(data.data ?? []);
      setTotalPages(data.last_page ?? 1);
      setPage(data.current_page ?? 1);
    } catch {
      setCustomers([]);
    } finally { setLoading(false); }
  }, [search]);

  useEffect(() => { load(); }, [load]);

  const viewDetail = async (id: string) => {
    setSelectedId(id);
    setDetailLoading(true);
    try {
      const d = await getAdminCustomer(id);
      setDetail(d as unknown as CustomerDetail);
    } catch {
      setDetail(null);
    } finally { setDetailLoading(false); }
  };

  if (selectedId) {
    return (
      <div className="space-y-6">
        <button onClick={() => { setSelectedId(null); setDetail(null); }} className="btn-secondary text-sm flex items-center gap-2">
          <ArrowLeft className="w-4 h-4" /> Back to Customers
        </button>

        {detailLoading ? (
          <LoadingSpinner label="Loading customer details..." />
        ) : detail ? (
          <>
            <div className="card p-5">
              <div className="flex items-center gap-4">
                <div className="w-12 h-12 rounded-full bg-noc-accent/20 flex items-center justify-center text-lg font-semibold text-noc-accent">
                  {detail.full_name.charAt(0).toUpperCase()}
                </div>
                <div>
                  <h1 className="text-xl font-bold text-slate-100">{detail.full_name}</h1>
                  <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-400 mt-1">
                    <span>{detail.phone_display}</span>
                    {detail.email && <span>{detail.email}</span>}
                    <span className={`text-xs font-medium px-2 py-0.5 rounded ${detail.status === "active" ? "bg-emerald-500/10 text-emerald-400" : "bg-slate-700 text-slate-400"}`}>{detail.status}</span>
                  </div>
                </div>
              </div>
            </div>

            <div className="card overflow-hidden">
              <div className="px-5 py-4 border-b border-noc-border">
                <h2 className="text-sm font-semibold text-slate-100">Purchases & Service Accounts</h2>
              </div>
              {detail.purchases.length === 0 ? (
                <div className="p-8 text-center text-sm text-slate-500">No purchases yet.</div>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="text-left text-xs text-slate-500 uppercase tracking-wider border-b border-noc-border">
                        <th className="px-5 py-3 font-medium">Package</th>
                        <th className="px-5 py-3 font-medium">Price</th>
                        <th className="px-5 py-3 font-medium">Payment</th>
                        <th className="px-5 py-3 font-medium">Account Status</th>
                        <th className="px-5 py-3 font-medium">Username</th>
                        <th className="px-5 py-3 font-medium">Expires</th>
                      </tr>
                    </thead>
                    <tbody>
                      {detail.purchases.map((p) => (
                        <tr key={p.id} className="border-b border-noc-border/50 last:border-0">
                          <td className="px-5 py-3 text-slate-200">{p.package_snapshot?.name ?? "—"}</td>
                          <td className="px-5 py-3 font-mono text-slate-400">KSh {((p.package_snapshot?.price_minor ?? 0) / 100).toLocaleString()}</td>
                          <td className="px-5 py-3">
                            <span className={`text-xs font-medium px-2 py-0.5 rounded ${p.payments[0]?.state === "successful" ? "bg-emerald-500/10 text-emerald-400" : "bg-amber-500/10 text-amber-400"}`}>
                              {p.payments[0]?.state ?? "no payment"}
                            </span>
                          </td>
                          <td className="px-5 py-3">
                            <span className={`text-xs font-medium px-2 py-0.5 rounded ${
                              p.serviceAccount?.activation_status === "active" ? "bg-emerald-500/10 text-emerald-400" :
                              p.serviceAccount?.activation_status === "expired" ? "bg-slate-700 text-slate-400" :
                              p.serviceAccount?.activation_status === "activation_failed" ? "bg-red-500/10 text-red-400" :
                              "bg-amber-500/10 text-amber-400"
                            }`}>
                              {p.serviceAccount?.activation_status ?? "no account"}
                            </span>
                          </td>
                          <td className="px-5 py-3 font-mono text-xs text-slate-400">{p.serviceAccount?.username ?? "—"}</td>
                          <td className="px-5 py-3 text-xs text-slate-500">{p.serviceAccount?.expires_at ?? "—"}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>

            {detail.supportTickets.length > 0 && (
              <div className="card overflow-hidden">
                <div className="px-5 py-4 border-b border-noc-border">
                  <h2 className="text-sm font-semibold text-slate-100">Support Tickets</h2>
                </div>
                <div className="divide-y divide-noc-border/50">
                  {detail.supportTickets.map((t) => (
                    <div key={t.id} className="px-5 py-3 flex items-center justify-between">
                      <span className="text-sm text-slate-300">{t.subject}</span>
                      <span className="text-xs text-slate-500">{t.status}</span>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </>
        ) : (
          <div className="card"><EmptyState message="Failed to load customer details." /></div>
        )}
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-100">Customers</h1>
        <p className="text-sm text-slate-400 mt-1">View customer accounts, purchases, and service status.</p>
      </div>

      <div className="flex gap-2">
        <div className="relative flex-1 max-w-md">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search by name, phone, or email..."
            className="input-field pl-10"
          />
        </div>
      </div>

      {loading ? (
        <LoadingSpinner label="Loading customers..." />
      ) : customers.length === 0 ? (
        <div className="card"><EmptyState message="No customers found." /></div>
      ) : (
        <div className="card overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-xs text-slate-500 uppercase tracking-wider border-b border-noc-border">
                  <th className="px-5 py-3 font-medium">Name</th>
                  <th className="px-5 py-3 font-medium">Phone</th>
                  <th className="px-5 py-3 font-medium">Status</th>
                  <th className="px-5 py-3 font-medium">Purchases</th>
                  <th className="px-5 py-3 font-medium">Accounts</th>
                  <th className="px-5 py-3 font-medium">Joined</th>
                  <th className="px-5 py-3 font-medium"></th>
                </tr>
              </thead>
              <tbody>
                {customers.map((c) => (
                  <tr key={c.id} className="border-b border-noc-border/50 last:border-0 hover:bg-noc-hover/30">
                    <td className="px-5 py-3">
                      <div className="flex items-center gap-2">
                        <div className="w-8 h-8 rounded-full bg-noc-accent/20 flex items-center justify-center text-xs font-semibold text-noc-accent">
                          {c.full_name.charAt(0).toUpperCase()}
                        </div>
                        <span className="text-slate-200">{c.full_name}</span>
                      </div>
                    </td>
                    <td className="px-5 py-3 font-mono text-xs text-slate-400">{c.phone_display}</td>
                    <td className="px-5 py-3">
                      <span className={`text-xs font-medium px-2 py-0.5 rounded ${c.status === "active" ? "bg-emerald-500/10 text-emerald-400" : "bg-slate-700 text-slate-400"}`}>{c.status}</span>
                    </td>
                    <td className="px-5 py-3 text-slate-300">{c.purchases_count ?? 0}</td>
                    <td className="px-5 py-3 text-slate-300">{c.service_accounts_count ?? 0}</td>
                    <td className="px-5 py-3 text-xs text-slate-500">{c.created_at?.slice(0, 10)}</td>
                    <td className="px-5 py-3">
                      <button onClick={() => viewDetail(c.id)} className="text-slate-400 hover:text-noc-accent transition-colors">
                        <Eye className="w-4 h-4" />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {totalPages > 1 && (
            <div className="px-5 py-3 border-t border-noc-border flex items-center justify-between text-xs text-slate-500">
              <span>Page {page} of {totalPages}</span>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
