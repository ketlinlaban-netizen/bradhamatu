import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { AlertTriangle, AlertCircle, Info, CheckCircle2 } from "lucide-react";
import { routerService } from "../services/RouterService";
import type { Incident } from "../types";
import { timeAgo } from "../utils/format";
import { LoadingSpinner } from "../components/ui";

export function IncidentsPage() {
  const [incidents, setIncidents] = useState<Incident[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    routerService.getIncidents().then((i) => {
      setIncidents(i);
      setLoading(false);
    });
  }, []);

  if (loading) return <LoadingSpinner label="Loading incidents..." />;

  const severityConfig = {
    critical: { icon: AlertCircle, color: "text-red-400", bg: "bg-red-500/10", border: "border-red-500/20" },
    warning: { icon: AlertTriangle, color: "text-amber-400", bg: "bg-amber-500/10", border: "border-amber-500/20" },
    info: { icon: Info, color: "text-blue-400", bg: "bg-blue-500/10", border: "border-blue-500/20" },
  };

  const sorted = [...incidents].sort((a, b) => {
    const order = { critical: 0, warning: 1, info: 2 };
    return order[a.severity] - order[b.severity];
  });

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-100">Incidents</h1>
        <p className="text-sm text-slate-400 mt-1">
          {incidents.filter((i) => !i.resolvedAt).length} active incidents
        </p>
      </div>

      {sorted.length === 0 ? (
        <div className="card">
          <div className="flex flex-col items-center justify-center py-12">
            <CheckCircle2 className="w-12 h-12 text-green-500 mb-3" />
            <p className="text-sm text-slate-400">No incidents. All routers are operating normally.</p>
          </div>
        </div>
      ) : (
        <div className="space-y-3">
          {sorted.map((incident) => {
            const cfg = severityConfig[incident.severity];
            const Icon = cfg.icon;
            return (
              <div key={incident.id} className={`card p-5 border ${cfg.border} ${cfg.bg}`}>
                <div className="flex items-start gap-4">
                  <div className={`w-10 h-10 rounded-lg ${cfg.bg} flex items-center justify-center flex-shrink-0`}>
                    <Icon className={`w-5 h-5 ${cfg.color}`} />
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-3 mb-1">
                      <Link to={`/routers/${incident.routerId}`} className="font-medium text-slate-100 hover:text-noc-accent">
                        {incident.routerName}
                      </Link>
                      <span className={`text-xs font-medium uppercase tracking-wider ${cfg.color}`}>
                        {incident.severity}
                      </span>
                      {incident.resolvedAt && (
                        <span className="text-xs text-green-400 flex items-center gap-1">
                          <CheckCircle2 className="w-3 h-3" /> Resolved
                        </span>
                      )}
                    </div>
                    <p className="text-sm text-slate-400">{incident.message}</p>
                    <div className="text-xs text-slate-500 mt-2">
                      Started {timeAgo(incident.startedAt)}
                      {incident.resolvedAt && ` · Resolved ${timeAgo(incident.resolvedAt)}`}
                    </div>
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
