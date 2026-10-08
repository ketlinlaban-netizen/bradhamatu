import { Link } from "react-router-dom";
import type { RouterStatus } from "../types";
import { STATUS_CONFIG } from "../utils/format";

interface StatusBadgeProps {
  status: RouterStatus;
  size?: "sm" | "md";
}

export function StatusBadge({ status, size = "sm" }: StatusBadgeProps) {
  const cfg = STATUS_CONFIG[status];
  const sizeClass = size === "md" ? "px-3 py-1.5 text-sm" : "px-2.5 py-1 text-xs";
  return (
    <span className={`inline-flex items-center gap-2 rounded-full border font-medium ${cfg.bgClass} ${cfg.textClass} ${sizeClass}`}>
      <span className={`status-dot ${cfg.dotClass} ${status === "online" ? "animate-pulse-slow" : ""}`} />
      {cfg.label}
    </span>
  );
}

interface RouterLinkProps {
  routerId: string;
  routerName: string;
  className?: string;
}

export function RouterLink({ routerId, routerName, className = "" }: RouterLinkProps) {
  return (
    <Link to={`/routers/${routerId}`} className={`text-noc-accent hover:text-blue-400 hover:underline font-medium ${className}`}>
      {routerName}
    </Link>
  );
}

export function Card({ children, className = "" }: { children: React.ReactNode; className?: string }) {
  return <div className={`card ${className}`}>{children}</div>;
}

export function SectionTitle({ children, className = "" }: { children: React.ReactNode; className?: string }) {
  return <h2 className={`text-lg font-semibold text-slate-100 ${className}`}>{children}</h2>;
}

export function LoadingSpinner({ label = "Loading..." }: { label?: string }) {
  return (
    <div className="flex items-center justify-center py-12 text-slate-400">
      <div className="w-6 h-6 border-2 border-noc-border border-t-noc-accent rounded-full animate-spin mr-3" />
      {label}
    </div>
  );
}

export function EmptyState({ message }: { message: string }) {
  return (
    <div className="flex flex-col items-center justify-center py-12 text-slate-500">
      <div className="w-12 h-12 rounded-full bg-noc-border/50 flex items-center justify-center mb-3">
        <span className="text-2xl">∅</span>
      </div>
      <p className="text-sm">{message}</p>
    </div>
  );
}
