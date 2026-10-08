import { useEffect, useState, useMemo } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { ArrowUpDown, Search } from "lucide-react";
import { routerService } from "../services/RouterService";
import type { Router, RouterStatus } from "../types";
import { timeAgo, statusBadge } from "../utils/format";
import { LoadingSpinner, EmptyState } from "../components/ui";

type SortKey = "name" | "status" | "clients" | "uptime" | "lastSeen" | "traffic";

const STATUS_FILTERS: Array<{ key: RouterStatus | "all"; label: string }> = [
  { key: "all", label: "All" },
  { key: "online", label: "Online" },
  { key: "offline", label: "Offline" },
  { key: "degraded", label: "Degraded" },
  { key: "unknown", label: "Unknown" },
];

export function RoutersPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const [routers, setRouters] = useState<Router[]>([]);
  const [clientCounts, setClientCounts] = useState<Record<string, number>>({});
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const [sortKey, setSortKey] = useState<SortKey>("name");
  const [sortAsc, setSortAsc] = useState(true);

  const statusFilter = (searchParams.get("status") as RouterStatus | "all") || "all";

  useEffect(() => {
    setLoading(true);
    routerService.getRouters().then(async (all) => {
      setRouters(all);
      const counts: Record<string, number> = {};
      for (const r of all) {
        if (r.status !== "offline") {
          const clients = await routerService.getClients(r.id);
          counts[r.id] = clients.length;
        } else {
          counts[r.id] = 0;
        }
      }
      setClientCounts(counts);
      setLoading(false);
    });
  }, []);

  const filtered = useMemo(() => {
    let result = routers;
    if (statusFilter !== "all") {
      result = result.filter((r) => r.status === statusFilter);
    }
    if (search.trim()) {
      const q = search.toLowerCase();
      result = result.filter(
        (r) =>
          r.name.toLowerCase().includes(q) ||
          r.ipAddress.includes(q) ||
          r.macAddress.toLowerCase().includes(q) ||
          r.location.toLowerCase().includes(q) ||
          r.site.toLowerCase().includes(q) ||
          r.model.toLowerCase().includes(q) ||
          r.serialNumber.toLowerCase().includes(q) ||
          r.hostname.toLowerCase().includes(q)
      );
    }
    const sorted = [...result].sort((a, b) => {
      let cmp = 0;
      switch (sortKey) {
        case "name": cmp = a.name.localeCompare(b.name); break;
        case "status": cmp = a.status.localeCompare(b.status); break;
        case "clients": cmp = (clientCounts[a.id] ?? 0) - (clientCounts[b.id] ?? 0); break;
        case "lastSeen": cmp = new Date(a.lastSeenAt).getTime() - new Date(b.lastSeenAt).getTime(); break;
        case "uptime": cmp = a.status === "offline" ? -1 : 0; break;
        case "traffic": cmp = 0; break;
      }
      return sortAsc ? cmp : -cmp;
    });
    return sorted;
  }, [routers, statusFilter, search, sortKey, sortAsc, clientCounts]);

  const setStatusFilter = (status: RouterStatus | "all") => {
    if (status === "all") {
      searchParams.delete("status");
    } else {
      searchParams.set("status", status);
    }
    setSearchParams(searchParams);
  };

  const toggleSort = (key: SortKey) => {
    if (sortKey === key) {
      setSortAsc(!sortAsc);
    } else {
      setSortKey(key);
      setSortAsc(true);
    }
  };

  if (loading) return <LoadingSpinner label="Loading router inventory..." />;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-100">Routers</h1>
        <p className="text-sm text-slate-400 mt-1">{filtered.length} of {routers.length} routers</p>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap items-center gap-3">
        <div className="flex gap-1.5">
          {STATUS_FILTERS.map((f) => (
            <button
              key={f.key}
              onClick={() => setStatusFilter(f.key)}
              className={`px-3 py-1.5 rounded-lg text-sm font-medium transition-colors ${
                statusFilter === f.key
                  ? "bg-noc-accent text-white"
                  : "bg-noc-panel text-slate-400 hover:text-slate-200 border border-noc-border"
              }`}
            >
              {f.label}
            </button>
          ))}
        </div>

        <div className="relative flex-1 min-w-[200px] max-w-xs">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Filter routers..."
            className="input-field pl-9"
          />
        </div>
      </div>

      {/* Table */}
      {filtered.length === 0 ? (
        <div className="card">
          <EmptyState message="No routers match the current filters." />
        </div>
      ) : (
        <div className="card overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-xs text-slate-500 uppercase tracking-wider border-b border-noc-border">
                  <th className="px-5 py-3 font-medium cursor-pointer hover:text-slate-300" onClick={() => toggleSort("name")}>
                    <span className="flex items-center gap-1">Router <ArrowUpDown className="w-3 h-3" /></span>
                  </th>
                  <th className="px-5 py-3 font-medium">Location</th>
                  <th className="px-5 py-3 font-medium">IP</th>
                  <th className="px-5 py-3 font-medium cursor-pointer hover:text-slate-300" onClick={() => toggleSort("status")}>
                    <span className="flex items-center gap-1">Status <ArrowUpDown className="w-3 h-3" /></span>
                  </th>
                  <th className="px-5 py-3 font-medium text-right cursor-pointer hover:text-slate-300" onClick={() => toggleSort("clients")}>
                    <span className="flex items-center gap-1 justify-end">Clients <ArrowUpDown className="w-3 h-3" /></span>
                  </th>
                  <th className="px-5 py-3 font-medium cursor-pointer hover:text-slate-300" onClick={() => toggleSort("lastSeen")}>
                    <span className="flex items-center gap-1">Last Seen <ArrowUpDown className="w-3 h-3" /></span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((router) => (
                  <tr key={router.id} className="table-row-hover border-b border-noc-border/50 last:border-0">
                    <td className="px-5 py-3">
                      <Link to={`/routers/${router.id}`} className="font-medium text-slate-100 hover:text-noc-accent">
                        {router.name}
                      </Link>
                      <div className="text-xs text-slate-500">{router.model}</div>
                    </td>
                    <td className="px-5 py-3 text-slate-400">{router.location}</td>
                    <td className="px-5 py-3 font-mono text-xs text-slate-400">{router.ipAddress}</td>
                    <td className="px-5 py-3">
                      <span className={statusBadge(router.status)}>
                        <span className={`status-dot ${
                          router.status === "online" ? "bg-green-500" :
                          router.status === "offline" ? "bg-red-500" :
                          router.status === "degraded" ? "bg-amber-500" : "bg-slate-500"
                        }`} />
                        {router.status.charAt(0).toUpperCase() + router.status.slice(1)}
                      </span>
                    </td>
                    <td className="px-5 py-3 text-right text-slate-400">
                      {router.status === "offline" ? "—" : (clientCounts[router.id] ?? "—")}
                    </td>
                    <td className="px-5 py-3 text-slate-400 text-xs">{timeAgo(router.lastSeenAt)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
