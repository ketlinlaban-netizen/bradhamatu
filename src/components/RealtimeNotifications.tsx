import { useEffect, useMemo, useRef, useState } from "react";
import { Bell, CheckCircle2, AlertTriangle, Info, X } from "lucide-react";

export type RealtimeNotificationKind = "info" | "warning" | "critical";

export interface RealtimeNotification {
  id: string;
  title: string;
  summary: string;
  createdAt: string;
  kind: RealtimeNotificationKind;
}

export function RealtimeNotifications({
  loadNotifications,
  emptyMessage,
  title = "Notifications",
  refreshIntervalMs = 15000,
}: {
  loadNotifications: () => Promise<RealtimeNotification[]>;
  emptyMessage: string;
  title?: string;
  refreshIntervalMs?: number;
}) {
  const [items, setItems] = useState<RealtimeNotification[]>([]);
  const [open, setOpen] = useState(false);
  const [permission, setPermission] = useState<NotificationPermission | "unsupported">("unsupported");
  const [isRefreshing, setIsRefreshing] = useState(false);
  const previousIdsRef = useRef<Set<string>>(new Set());

  useEffect(() => {
    if (typeof window !== "undefined" && "Notification" in window) {
      setPermission(Notification.permission);
    }
  }, []);

  useEffect(() => {
    let cancelled = false;

    const refresh = async () => {
      setIsRefreshing(true);
      try {
        const nextItems = await loadNotifications();
        if (cancelled) return;

        setItems(nextItems);
        const nextIds = new Set(nextItems.map((item) => item.id));
        const newlyAdded = nextItems.filter((item) => !previousIdsRef.current.has(item.id));

        if (typeof window !== "undefined" && "Notification" in window && Notification.permission === "granted" && newlyAdded.length > 0) {
          newlyAdded.slice(0, 3).forEach((item) => {
            const toast = new Notification(item.title, {
              body: item.summary,
              tag: item.id,
            });
            window.setTimeout(() => toast.close(), 4000);
          });
        }

        previousIdsRef.current = nextIds;
      } finally {
        if (!cancelled) {
          setIsRefreshing(false);
        }
      }
    };

    void refresh();
    const timer = window.setInterval(() => {
      void refresh();
    }, refreshIntervalMs);

    return () => {
      cancelled = true;
      window.clearInterval(timer);
    };
  }, [loadNotifications, refreshIntervalMs]);

  const unreadCount = items.length;

  const requestPermission = async () => {
    if (typeof window === "undefined" || !("Notification" in window)) {
      return;
    }

    const nextPermission = await Notification.requestPermission();
    setPermission(nextPermission);
  };

  const kindStyles: Record<RealtimeNotificationKind, string> = useMemo(
    () => ({
      info: "border-blue-500/20 bg-blue-500/10 text-blue-200",
      warning: "border-amber-500/20 bg-amber-500/10 text-amber-200",
      critical: "border-red-500/20 bg-red-500/10 text-red-200",
    }),
    [],
  );

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setOpen((current) => !current)}
        className="relative flex h-9 w-9 items-center justify-center rounded-full border border-slate-700 text-slate-300 transition-colors hover:border-noc-accent hover:text-white"
        aria-label={title}
        title={title}
      >
        <Bell className={`h-4 w-4 ${isRefreshing ? "animate-pulse" : ""}`} />
        {unreadCount > 0 && (
          <span className="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[9px] font-bold text-white">
            {unreadCount > 9 ? "9+" : unreadCount}
          </span>
        )}
      </button>

      {open && (
        <div className="absolute right-0 top-12 z-50 w-80 overflow-hidden rounded-2xl border border-slate-700 bg-slate-950/95 shadow-2xl shadow-slate-950/70">
          <div className="flex items-center justify-between border-b border-slate-800 px-3 py-2.5">
            <div className="text-sm font-semibold text-white">{title}</div>
            <button type="button" onClick={() => setOpen(false)} className="text-slate-400 hover:text-white" aria-label="Close notifications">
              <X className="h-4 w-4" />
            </button>
          </div>

          {permission === "default" && (
            <div className="border-b border-slate-800 px-3 py-2 text-xs text-slate-300">
              Browser notifications are off. <button type="button" onClick={() => void requestPermission()} className="ml-1 font-medium text-blue-300 underline">Enable</button>
            </div>
          )}

          <div className="max-h-80 overflow-y-auto">
            {items.length === 0 ? (
              <div className="px-3 py-5 text-sm text-slate-400">{emptyMessage}</div>
            ) : (
              items.map((item) => {
                const kindClass = kindStyles[item.kind] ?? kindStyles.info;
                const Icon = item.kind === "critical" ? X : item.kind === "warning" ? AlertTriangle : Info;
                return (
                  <div key={item.id} className={`border-b border-slate-800 px-3 py-3 ${kindClass}`}>
                    <div className="flex items-start gap-2">
                      <div className="mt-0.5 flex h-6 w-6 items-center justify-center rounded-md bg-slate-950/40">
                        <Icon className="h-3.5 w-3.5" />
                      </div>
                      <div className="min-w-0 flex-1">
                        <div className="mb-1 flex items-center justify-between gap-2">
                          <div className="truncate text-sm font-medium text-white">{item.title}</div>
                          <span className="text-[10px] uppercase tracking-[0.15em] text-slate-300">{item.kind}</span>
                        </div>
                        <div className="text-xs text-slate-300">{item.summary}</div>
                        <div className="mt-1 flex items-center gap-1 text-[10px] uppercase tracking-[0.12em] text-slate-400">
                          <CheckCircle2 className="h-3 w-3" />
                          {new Date(item.createdAt).toLocaleString([], { dateStyle: "short", timeStyle: "short" })}
                        </div>
                      </div>
                    </div>
                  </div>
                );
              })
            )}
          </div>
        </div>
      )}
    </div>
  );
}
