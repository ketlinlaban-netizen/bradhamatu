import { useEffect, useState } from "react";
import { Headphones, MessageSquare, Phone } from "lucide-react";
import { createSupportTicket, getSupportTickets } from "../../services/customerApi";
import type { SupportTicket } from "../../types/billing";
import { CustomerMobileShell } from "./CustomerMobileShell";

export function CustomerSupportPage() {
  const [tickets, setTickets] = useState<SupportTicket[]>([]);
  const [subject, setSubject] = useState("");
  const [body, setBody] = useState("");
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    void (async () => {
      const data = await getSupportTickets().catch(() => []);
      setTickets(data);
    })();
  }, []);

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    try {
      const ticket = await createSupportTicket({ subject, body, priority: "normal" });
      setTickets((current) => [ticket, ...current]);
      setMessage("Support ticket created successfully.");
      setSubject("");
      setBody("");
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Unable to submit ticket.");
    }
  };

  return (
    <CustomerMobileShell title="Support">
      <div className="space-y-4 pb-24">
        <div className="rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
          <div className="mb-3 text-lg font-semibold text-white">Need help?</div>
          <div className="grid grid-cols-3 gap-2">
            <div className="rounded-2xl bg-slate-950/60 p-3 text-center">
              <MessageSquare className="mx-auto h-5 w-5 text-blue-300" />
              <div className="mt-2 text-xs text-slate-400">Live chat</div>
              <div className="mt-1 text-[10px] text-slate-500">Not configured</div>
            </div>
            <div className="rounded-2xl bg-slate-950/60 p-3 text-center">
              <Phone className="mx-auto h-5 w-5 text-emerald-300" />
              <div className="mt-2 text-xs text-slate-400">Call us</div>
              <div className="mt-1 text-[10px] text-slate-500">Not configured</div>
            </div>
            <div className="rounded-2xl bg-slate-950/60 p-3 text-center">
              <Headphones className="mx-auto h-5 w-5 text-violet-300" />
              <div className="mt-2 text-xs text-slate-400">FAQs</div>
              <div className="mt-1 text-[10px] text-slate-500">See portal docs</div>
            </div>
          </div>
        </div>

        <form onSubmit={handleSubmit} className="rounded-3xl border border-slate-800 bg-slate-900/80 p-4 space-y-3">
          <div className="text-base font-semibold text-white">Open a support ticket</div>
          <input
            value={subject}
            onChange={(event) => setSubject(event.target.value)}
            placeholder="Subject"
            className="w-full rounded-xl border border-slate-700 bg-slate-950/60 px-3 py-2.5 text-sm text-white outline-none"
            required
          />
          <textarea
            value={body}
            onChange={(event) => setBody(event.target.value)}
            placeholder="Describe your issue"
            className="min-h-[100px] w-full rounded-xl border border-slate-700 bg-slate-950/60 px-3 py-2.5 text-sm text-white outline-none"
            required
          />
          <button type="submit" className="w-full rounded-xl bg-blue-500 px-4 py-3 text-sm font-semibold text-white">
            Submit ticket
          </button>
        </form>

        {message && <div className="rounded-2xl border border-blue-500/20 bg-blue-500/10 px-3 py-2 text-sm text-blue-200">{message}</div>}

        <div className="rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
          <div className="mb-3 text-base font-semibold text-white">Ticket history</div>
          {tickets.length === 0 ? (
            <div className="rounded-2xl bg-slate-950/60 p-3 text-sm text-slate-400">No tickets yet.</div>
          ) : (
            <div className="space-y-2">
              {tickets.map((ticket) => (
                <div key={ticket.id} className="rounded-2xl bg-slate-950/60 p-3">
                  <div className="flex items-center justify-between gap-2">
                    <div className="font-medium text-white">{ticket.subject}</div>
                    <div className="rounded-full bg-slate-800 px-2 py-1 text-[10px] uppercase tracking-[0.12em] text-slate-300">{ticket.status}</div>
                  </div>
                  <div className="mt-2 text-xs text-slate-400">{ticket.body}</div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </CustomerMobileShell>
  );
}
