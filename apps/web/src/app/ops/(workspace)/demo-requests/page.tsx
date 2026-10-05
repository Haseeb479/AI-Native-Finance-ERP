"use client";

import { useEffect, useState } from "react";
import { Mail, RefreshCw } from "lucide-react";
import { useOpsStaff } from "@/components/ops/OpsShell";
import { opsApi } from "@/lib/ops-api";

type DemoRequest = {
  id: number; first_name: string; last_name: string; email: string; company_name: string;
  company_size: string; role: string | null; referral_source: string | null;
  message: string | null; status: "new" | "contacted" | "scheduled" | "closed"; created_at: string | null;
};
const statuses: DemoRequest["status"][] = ["new", "contacted", "scheduled", "closed"];

export default function OperationsDemoRequestsPage() {
  const staff = useOpsStaff();
  const canManage = ["ops_admin", "ops_manager", "ops_sales"].includes(staff?.role || "");
  const [requests, setRequests] = useState<DemoRequest[]>([]);
  const [error, setError] = useState("");
  const [busyId, setBusyId] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    opsApi<{ requests: DemoRequest[] }>("demo-requests?per_page=100")
      .then((data) => { if (!cancelled) setRequests(data.requests); })
      .catch((reason: Error) => { if (!cancelled) setError(reason.message); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, []);

  async function refresh() {
    setLoading(true);
    setError("");
    try {
      const data = await opsApi<{ requests: DemoRequest[] }>("demo-requests?per_page=100");
      setRequests(data.requests);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Unable to load demo requests.");
    } finally {
      setLoading(false);
    }
  }
  async function updateStatus(id: number, status: DemoRequest["status"]) {
    setBusyId(id);
    setError("");
    try {
      await opsApi(`demo-requests/${id}`, { method: "PATCH", body: JSON.stringify({ status }) });
      setRequests((previous) => previous.map((request) => request.id === id ? { ...request, status } : request));
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Could not update demo request.");
    } finally {
      setBusyId(null);
    }
  }

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-end justify-between gap-4"><div><p className="text-xs font-bold uppercase tracking-[0.17em] text-emerald-800">Growth operations</p><h1 className="mt-2 text-3xl font-semibold tracking-tight">Demo pipeline</h1><p className="mt-2 text-sm text-slate-500">Track real demo inquiries and their follow-up state. Status changes are audited.</p></div><button onClick={() => void refresh()} disabled={loading} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"><RefreshCw size={15} />Refresh</button></header>
      {!canManage && <p className="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">Read-only access. Contact an Operations Manager to change request status.</p>}
      {error && <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">{error}</p>}
      <section className="overflow-hidden rounded-2xl border border-[#E1E9E2] bg-white">
        <div className="overflow-x-auto"><table className="w-full min-w-[1020px] text-left text-sm">
          <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Prospect</th><th className="px-5 py-3">Company</th><th className="px-5 py-3">Role / size</th><th className="px-5 py-3">Request</th><th className="px-5 py-3">Received</th><th className="px-5 py-3">Pipeline status</th></tr></thead>
          <tbody className="divide-y divide-slate-100">
            {requests.map((request) => <tr key={request.id} className="align-top hover:bg-slate-50/70">
              <td className="px-5 py-4"><div className="font-semibold">{request.first_name} {request.last_name}</div><a className="mt-1 block text-xs text-emerald-800 hover:underline" href={`mailto:${request.email}`}>{request.email}</a></td>
              <td className="px-5 py-4"><div className="font-medium">{request.company_name}</div><div className="mt-1 text-xs text-slate-500">Source: {request.referral_source || "Not specified"}</div></td>
              <td className="px-5 py-4 text-slate-600">{request.role || "—"}<div className="mt-1 text-xs">{request.company_size} employees</div></td>
              <td className="max-w-sm whitespace-pre-wrap px-5 py-4 text-xs leading-5 text-slate-600">{request.message || "No message included."}</td>
              <td className="whitespace-nowrap px-5 py-4 text-xs text-slate-500">{request.created_at ? new Date(request.created_at).toLocaleString() : "—"}</td>
              <td className="px-5 py-4"><select aria-label={`Status for ${request.company_name}`} disabled={!canManage || busyId === request.id} value={request.status} onChange={(event) => void updateStatus(request.id, event.target.value as DemoRequest["status"])} className="rounded-lg border border-slate-200 bg-white px-2.5 py-2 text-xs font-semibold capitalize outline-none focus:border-emerald-700 disabled:opacity-60">{statuses.map((status) => <option key={status} value={status}>{status.replace("_", " ")}</option>)}</select></td>
            </tr>)}
            {!loading && requests.length === 0 && <tr><td colSpan={6} className="px-5 py-16 text-center text-sm text-slate-500"><Mail className="mx-auto mb-3 h-6 w-6 text-slate-300" />No demo inquiries yet.</td></tr>}
            {loading && <tr><td colSpan={6} className="px-5 py-12 text-center text-sm text-slate-500">Loading demo pipeline…</td></tr>}
          </tbody>
        </table></div>
      </section>
    </div>
  );
}
