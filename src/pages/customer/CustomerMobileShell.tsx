import { CreditCard, Headphones, Home, LogOut, Wifi, BarChart3, ShoppingBag, ArrowLeft } from "lucide-react";
import { Link, NavLink, useNavigate } from "react-router-dom";
import { RealtimeNotifications, type RealtimeNotification } from "../../components/RealtimeNotifications";
import { useCustomerAuth } from "../../auth/CustomerAuthContext";
import { getCustomerPackages, getPayments, getServiceStatus } from "../../services/customerApi";

const navigation = [
  { to: "/customer/dashboard", label: "Home", icon: Home },
  { to: "/customer/providers", label: "Providers", icon: Wifi },
  { to: "/customer/hotspot", label: "Hotspot", icon: ShoppingBag },
  { to: "/customer/usage", label: "Usage", icon: BarChart3 },
  { to: "/customer/payments", label: "Payments", icon: CreditCard },
  { to: "/customer/support", label: "Support", icon: Headphones },
];

export function CustomerMobileShell({
  title,
  children,
  showBack = false,
  backTo = "/customer/dashboard",
}: {
  title: string;
  children: React.ReactNode;
  showBack?: boolean;
  backTo?: string;
}) {
  const { logout } = useCustomerAuth();
  const navigate = useNavigate();

  const loadNotifications = async (): Promise<RealtimeNotification[]> => {
    try {
      const [status, payments, packages] = await Promise.all([
        getServiceStatus().catch(() => ({ active_package: null, next_due_at: null, last_expired: null })),
        getPayments().catch(() => []),
        getCustomerPackages().catch(() => []),
      ]);

      const items: RealtimeNotification[] = [];

      if (!status.active_package) {
        items.push({
          id: "customer-no-plan",
          title: "Plan required",
          summary: "No active internet package is currently assigned to your account.",
          createdAt: new Date().toISOString(),
          kind: "warning",
        });
      }

      const failedPayment = payments.find((payment) => payment.state === "failed" || payment.state === "cancelled" || payment.state === "timed_out");
      if (failedPayment) {
        items.push({
          id: `customer-payment-${failedPayment.id}`,
          title: "Payment attention",
          summary: `A ${failedPayment.state.replace("_", " ")} payment needs review before the next renewal.`,
          createdAt: failedPayment.initiated_at ?? new Date().toISOString(),
          kind: "warning",
        });
      }

      if (packages.length === 0 && !status.active_package) {
        items.push({
          id: "customer-packages-empty",
          title: "Catalog update",
          summary: "No packages are currently available for checkout.",
          createdAt: new Date().toISOString(),
          kind: "info",
        });
      }

      return items.slice(0, 5);
    } catch {
      return [];
    }
  };

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100">
      <div className="mx-auto max-w-md min-h-screen border-x border-slate-800 bg-[radial-gradient(circle_at_top,_rgba(37,99,235,0.25),_transparent_40%),_#020817]">
        <header className="sticky top-0 z-30 border-b border-slate-800 bg-slate-950/90 backdrop-blur">
          <div className="flex items-center justify-between px-4 py-3">
            <div className="flex items-center gap-3 min-w-0">
              {showBack ? (
                <Link to={backTo} className="flex h-9 w-9 items-center justify-center rounded-full border border-slate-700 text-slate-300 hover:border-noc-accent">
                  <ArrowLeft className="h-4 w-4" />
                </Link>
              ) : (
                <div className="flex h-9 w-9 items-center justify-center rounded-full bg-noc-accent/15 text-noc-accent">
                  <Wifi className="h-4 w-4" />
                </div>
              )}
              <div className="min-w-0">
                <div className="truncate text-sm font-semibold text-slate-100">{title}</div>
              </div>
            </div>

            <div className="flex items-center gap-2">
              <RealtimeNotifications
                title="Account alerts"
                emptyMessage="No active alerts for your account."
                loadNotifications={loadNotifications}
              />
              <button
                type="button"
                onClick={() => {
                  logout();
                  navigate("/customer/login");
                }}
                className="flex h-9 w-9 items-center justify-center rounded-full border border-slate-700 text-slate-300 hover:border-red-500/40 hover:text-red-300"
                aria-label="Logout"
              >
                <LogOut className="h-4 w-4" />
              </button>
            </div>
          </div>
        </header>

        <main className="px-4 pb-28 pt-4">{children}</main>

        <nav className="fixed bottom-0 left-1/2 z-30 w-full max-w-md -translate-x-1/2 border-t border-slate-800 bg-slate-950/95 px-3 py-2 backdrop-blur">
          <div className="grid grid-cols-6 gap-1">
            {navigation.map(({ to, label, icon: Icon }) => (
              <NavLink
                key={to}
                to={to}
                className={({ isActive }) =>
                  `flex flex-col items-center justify-center gap-1 rounded-xl px-1 py-2 text-[10px] transition-colors ${
                    isActive ? "bg-noc-accent/15 text-white" : "text-slate-400 hover:text-slate-200"
                  }`
                }
              >
                <Icon className="h-4 w-4" />
                <span>{label}</span>
              </NavLink>
            ))}
          </div>
        </nav>
      </div>
    </div>
  );
}
