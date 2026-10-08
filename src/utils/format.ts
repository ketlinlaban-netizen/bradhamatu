import type { RouterStatus } from "../types";

export function formatBytes(bytes: number): string {
  if (bytes === 0) return "0 B";
  const units = ["B", "KB", "MB", "GB", "TB", "PB"];
  const i = Math.floor(Math.log(bytes) / Math.log(1024));
  return `${(bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 2)} ${units[i]}`;
}

export function formatNumber(n: number): string {
  return n.toLocaleString("en-US");
}

export function formatUptime(seconds: number): string {
  if (seconds === 0) return "—";
  const d = Math.floor(seconds / 86400);
  const h = Math.floor((seconds % 86400) / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  if (d > 0) return `${d}d ${h}h`;
  if (h > 0) return `${h}h ${m}m`;
  return `${m}m`;
}

export function timeAgo(iso: string): string {
  const diff = Date.now() - new Date(iso).getTime();
  const seconds = Math.floor(diff / 1000);
  if (seconds < 60) return `${seconds} sec ago`;
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `${minutes} min ago`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours} hr ago`;
  const days = Math.floor(hours / 24);
  return `${days}d ago`;
}

export const STATUS_CONFIG: Record<RouterStatus, { label: string; color: string; dotClass: string; textClass: string; bgClass: string }> = {
  online: {
    label: "Online",
    color: "#22c55e",
    dotClass: "bg-green-500",
    textClass: "text-green-400",
    bgClass: "bg-green-500/10 border-green-500/20",
  },
  offline: {
    label: "Offline",
    color: "#ef4444",
    dotClass: "bg-red-500",
    textClass: "text-red-400",
    bgClass: "bg-red-500/10 border-red-500/20",
  },
  degraded: {
    label: "Degraded",
    color: "#f59e0b",
    dotClass: "bg-amber-500",
    textClass: "text-amber-400",
    bgClass: "bg-amber-500/10 border-amber-500/20",
  },
  unknown: {
    label: "Unknown",
    color: "#94a3b8",
    dotClass: "bg-slate-500",
    textClass: "text-slate-400",
    bgClass: "bg-slate-500/10 border-slate-500/20",
  },
};

export function statusBadge(status: RouterStatus): string {
  const cfg = STATUS_CONFIG[status];
  return `inline-flex items-center gap-2 px-2.5 py-1 rounded-full text-xs font-medium border ${cfg.bgClass} ${cfg.textClass}`;
}
