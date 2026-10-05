"use client";

import { useEffect, useState } from "react";
import { ScrollText } from "lucide-react";
import { opsApi } from "@/lib/ops-api";

type AuditEvent = {
  id: number; actor_user_id: string | null; actor_name: string | null; actor_email: string | null;
  action: string; organization_id: string | null; outcome: "allowed" | "denied"; created_at: string;
};

export default function OperationsAuditPage() {
  const [events, setEvents] = useState<AuditEvent[]>([]);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    opsApi<{ events: AuditEvent[] }>("audit-events?per_page=250")
      .then((data) => { if (!cancelled) setEvents(data.events); })
      .catch((reason: Error) => { if (!cancelled) setError(reason.message); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, []);

  return (
    <div className="space-y-6">
      <header><p className="text-xs font-bold uppercase tracking-[0.17em] text-emerald-800">Governance</p><h1 className="mt-2 text-3xl font-semibold tracking-tight">Staff audit trail</h1><p className="mt-2 text-sm text-slate-500">Append-only record of Finova Operations access and actions. Sensitive network metadata is not exposed in this view.</p></header>
      {error && <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">{error}</p>}
      <section className="overflow-hidden rounded-2xl border border-[#E1E9E2] bg-white">
        <div className="overflow-x-auto"><table className="w-full min-w-[850px] text-left text-sm">
          <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Staff member</th><th className="px-5 py-3">Action</th><th className="px-5 py-3">Organization</th><th className="px-5 py-3">Decision</th><th className="px-5 py-3">Time</th></tr></thead>
          <tbody className="divide-y divide-slate-100">{events.map((event) => <tr key={event.id}>
            <td className="px-5 py-4"><div className="font-medium">{event.actor_name || "Staff account"}</div><div className="mt-1 text-xs text-slate-500">{event.actor_email || event.actor_user_id || "Unknown actor"}</div></td>
            <td className="px-5 py-4 font-mono text-xs text-slate-700">{event.action}</td>
            <td className="px-5 py-4 font-mono text-xs text-slate-500">{event.organization_id || "—"}</td>
            <td className="px-5 py-4"><span className={`rounded-full px-2.5 py-1 text-[10px] font-bold uppercase ${event.outcome === "allowed" ? "bg-emerald-50 text-emerald-800" : "bg-rose-50 text-rose-800"}`}>{event.outcome}</span></td>
            <td className="whitespace-nowrap px-5 py-4 text-xs text-slate-500">{event.created_at ? new Date(event.created_at).toLocaleString() : "—"}</td>
          </tr>)}
          {!loading && events.length === 0 && <tr><td colSpan={5} className="px-5 py-14 text-center text-sm text-slate-500"><ScrollText className="mx-auto mb-3 h-6 w-6 text-slate-300" />No staff events recorded.</td></tr>}
          {loading && <tr><td colSpan={5} className="px-5 py-12 text-center text-sm text-slate-500">Loading immutable audit events…</td></tr>}
          </tbody>
        </table></div>
      </section>
    </div>
  );
}
