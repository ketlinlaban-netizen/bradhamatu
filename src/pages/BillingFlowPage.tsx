import { useEffect, useState } from "react";
import { Activity, CheckCircle2, CreditCard, Database, Router, Server, Users } from "lucide-react";
import { getPendingAdminPayments, getReconciliationQueue, getRevenueReport, type RevenueReport } from "../services/adminBillingApi";

interface FlowStep {
  title: string;
  detail: string;
  icon: typeof Activity;
  tone: string;
  metric: string;
}

const steps: FlowStep[] = [
  { title: "Customer request", detail: "Package selected and account verified", icon: Users, tone: "border-blue-400/30 bg-blue-400/10", metric: "24 requests" },
  { title: "M-Pesa checkout", detail: "STK Push awaiting provider confirmation", icon: CreditCard, tone: "border-amber-400/30 bg-amber-400/10", metric: "8 pending" },
  { title: "Payment ledger", detail: "Verified receipts and immutable references", icon: Database, tone: "border-cyan-400/30 bg-cyan-400/10", metric: "16 verified" },
  { title: "Access activation", detail: "Scoped hotspot provisioning only", icon: Router, tone: "border-emerald-400/30 bg-emerald-400/10", metric: "14 active" },
  { title: "Service live", detail: "Expiry and renewal scheduler tracking", icon: CheckCircle2, tone: "border-slate-400/30 bg-slate-400/10", metric: "99.2% healthy" },
];

function Pipe({ active }: { active: boolean }) {
  return (
    <div className="relative hidden min-w-12 flex-1 md:block" aria-hidden="true">
      <div className="h-2 rounded-full border border-slate-600/70 bg-slate-800/70 shadow-inner" />
      {active && <span className="flow-particle" />}
    </div>
  );
}

export function BillingFlowPage() {
  const [report, setReport] = useState<RevenueReport | null>(null);
  const [pendingCount, setPendingCount] = useState(0);
  const [reconciliationCount, setReconciliationCount] = useState(0);

  useEffect(() => {
    Promise.all([getRevenueReport(), getPendingAdminPayments(), getReconciliationQueue()])
      .then(([nextReport, pending, reconciliation]) => {
        setReport(nextReport);
        setPendingCount(pending.total ?? pending.data.length);
        setReconciliationCount(reconciliation.payments.length);
      })
      .catch(() => {
        setReport(null);
      });
  }, []);

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <div className="mb-2 flex items-center gap-2 text-xs uppercase tracking-[0.2em] text-cyan-400">
            <Activity className="h-4 w-4" /> Billing operations
          </div>
          <h1 className="text-2xl font-bold text-slate-100">Payment to service flow</h1>
          <p className="mt-1 max-w-2xl text-sm text-slate-400">A calm operational view of every controlled handoff from customer request to internet activation.</p>
        </div>
        <div className="flex items-center gap-2 text-xs text-slate-400">
          <span className="h-2 w-2 rounded-full bg-emerald-400" /> Live ledger stream
        </div>
      </div>

      <div className="card overflow-hidden p-5 md:p-7">
        <div className="mb-6 flex items-center justify-between">
          <div>
            <p className="text-sm font-semibold text-slate-200">Today&apos;s controlled pipeline</p>
            <p className="mt-1 text-xs text-slate-500">No access is granted before payment verification.</p>
          </div>
          <Server className="h-5 w-5 text-slate-500" />
        </div>
        <div className="flex flex-col gap-3 md:flex-row md:items-center md:gap-0">
          {steps.map((step, index) => {
            const Icon = step.icon;
            return (
              <div key={step.title} className="contents">
                <div className={`glass-pipeline-node ${step.tone}`}>
                  <div className="flex items-center gap-3">
                    <div className="rounded-xl border border-white/10 bg-slate-950/30 p-2">
                      <Icon className="h-5 w-5 text-slate-200" />
                    </div>
                    <div className="min-w-0">
                      <p className="text-sm font-semibold text-slate-100">{step.title}</p>
                      <p className="mt-1 text-[11px] leading-4 text-slate-400">{step.detail}</p>
                      <p className="mt-2 text-xs font-medium text-slate-300">{step.metric}</p>
                    </div>
                  </div>
                </div>
                {index < steps.length - 1 && <Pipe active={index < 4} />}
              </div>
            );
          })}
        </div>
      </div>

      <div className="grid gap-4 md:grid-cols-3">
        <div className="stat-card"><p className="text-xs text-slate-500">Verified revenue today</p><p className="mt-2 text-2xl font-bold text-slate-100">KSh {report?.total_revenue_ksh.toLocaleString() ?? "—"}</p><p className="mt-1 text-xs text-emerald-400">Only successful payments counted</p></div>
        <div className="stat-card"><p className="text-xs text-slate-500">Activation queue</p><p className="mt-2 text-2xl font-bold text-slate-100">{pendingCount}</p><p className="mt-1 text-xs text-amber-400">{reconciliationCount} need reconciliation</p></div>
        <div className="stat-card"><p className="text-xs text-slate-500">Active subscribers</p><p className="mt-2 text-2xl font-bold text-slate-100">{report?.active_subscribers ?? "—"}</p><p className="mt-1 text-xs text-cyan-400">Server-authoritative expiry</p></div>
      </div>

      <div className="card p-5">
        <div className="flex items-start gap-3">
          <div className="rounded-lg bg-cyan-400/10 p-2 text-cyan-400"><Activity className="h-4 w-4" /></div>
          <div><p className="text-sm font-semibold text-slate-200">Flow controls</p><p className="mt-1 text-xs leading-5 text-slate-500">Use the billing pages to review pending payments, reconcile ambiguous callbacks, manage packages, and retry activation failures. Router monitoring remains read-only.</p></div>
        </div>
      </div>
    </div>
  );
}
