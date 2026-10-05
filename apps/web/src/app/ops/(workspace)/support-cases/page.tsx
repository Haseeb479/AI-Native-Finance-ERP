"use client";

import { FormEvent, useEffect, useState } from "react";
import { Headset, Plus, RefreshCw } from "lucide-react";
import { useOpsStaff } from "@/components/ops/OpsShell";
import { opsApi } from "@/lib/ops-api";

type SupportCase = {
  id: string; organization_id: string | null; customer_email: string; category: string;
  subject: string; description: string; priority: string; status: string; created_at: string;
};
type Organization = { id: string; name: string };

export default function OperationsSupportPage() {
  const staff = useOpsStaff();
  const canManage = ["ops_admin", "ops_manager", "ops_support"].includes(staff?.role || "");
  const [cases, setCases] = useState<SupportCase[]>([]);
  const [organizations, setOrganizations] = useState<Organization[]>([]);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const [busyId, setBusyId] = useState("");
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ customer_email: "", organization_id: "", category: "access", subject: "", description: "", priority: "normal" });

  useEffect(() => {
    let cancelled = false;
    Promise.all([
      opsApi<{ cases: SupportCase[] }>("support-cases?per_page=100"),
      opsApi<{ organizations: Organization[] }>("organizations?per_page=100"),
    ])
      .then(([caseData, organizationData]) => {
        if (!cancelled) {
          setCases(caseData.cases);
          setOrganizations(organizationData.organizations);
        }
      })
      .catch((reason: Error) => { if (!cancelled) setError(reason.message); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, []);

  async function refresh() {
    setLoading(true);
    setError("");
    try {
      const [caseData, organizationData] = await Promise.all([
        opsApi<{ cases: SupportCase[] }>("support-cases?per_page=100"),
        opsApi<{ organizations: Organization[] }>("organizations?per_page=100"),
      ]);
      setCases(caseData.cases);
      setOrganizations(organizationData.organizations);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Support queue unavailable.");
    } finally {
      setLoading(false);
    }
  }
  async function createCase(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    try {
      const data = await opsApi<{ case: SupportCase }>("support-cases", {
        method: "POST",
        body: JSON.stringify({ ...form, organization_id: form.organization_id || null }),
      });
      setCases((previous) => [data.case, ...previous]);
      setForm({ customer_email: "", organization_id: "", category: "access", subject: "", description: "", priority: "normal" });
      setShowForm(false);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Could not create support case.");
    }
  }

  async function updateCase(id: string, status: string) {
    setBusyId(id);
    setError("");
    try {
      const data = await opsApi<{ case: SupportCase }>(`support-cases/${id}`, {
        method: "PATCH",
        body: JSON.stringify({ status }),
      });
      setCases((previous) => previous.map((item) => item.id === id ? data.case : item));
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Could not update case.");
    } finally {
      setBusyId("");
    }
  }

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-end justify-between gap-4"><div><p className="text-xs font-bold uppercase tracking-[0.17em] text-emerald-800">Customer care</p><h1 className="mt-2 text-3xl font-semibold tracking-tight">Support & access</h1><p className="mt-2 text-sm text-slate-500">Track customer access, billing, onboarding and technical follow-ups. Staff changes are audited.</p></div><div className="flex gap-2"><button onClick={() => void refresh()} disabled={loading} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700"><RefreshCw size={15} />Refresh</button>{canManage && <button onClick={() => setShowForm((open) => !open)} className="inline-flex items-center gap-2 rounded-xl bg-[#174C38] px-3.5 py-2.5 text-sm font-semibold text-white"><Plus size={16} />New case</button>}</div></header>
      {error && <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">{error}</p>}
      {!canManage && <p className="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">Your staff role does not include customer support case access.</p>}
      {showForm && canManage && <form onSubmit={createCase} className="grid gap-4 rounded-2xl border border-[#DCE8DE] bg-white p-5 sm:grid-cols-2">
        <label className="text-xs font-semibold text-slate-600">Customer email<input required type="email" maxLength={254} value={form.customer_email} onChange={(event) => setForm({ ...form, customer_email: event.target.value })} className="mt-1.5 block w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm font-normal" /></label>
        <label className="text-xs font-semibold text-slate-600">Related company (optional)<select value={form.organization_id} onChange={(event) => setForm({ ...form, organization_id: event.target.value })} className="mt-1.5 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-normal"><option value="">No company linked</option>{organizations.map((organization) => <option key={organization.id} value={organization.id}>{organization.name}</option>)}</select></label>
        <label className="text-xs font-semibold text-slate-600">Issue type<select value={form.category} onChange={(event) => setForm({ ...form, category: event.target.value })} className="mt-1.5 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-normal">{["access", "billing", "onboarding", "technical", "other"].map((value) => <option key={value} value={value}>{value[0].toUpperCase() + value.slice(1)}</option>)}</select></label>
        <label className="text-xs font-semibold text-slate-600">Priority<select value={form.priority} onChange={(event) => setForm({ ...form, priority: event.target.value })} className="mt-1.5 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-normal">{["low", "normal", "high", "urgent"].map((value) => <option key={value} value={value}>{value[0].toUpperCase() + value.slice(1)}</option>)}</select></label>
        <label className="text-xs font-semibold text-slate-600 sm:col-span-2">Subject<input required maxLength={200} value={form.subject} onChange={(event) => setForm({ ...form, subject: event.target.value })} className="mt-1.5 block w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm font-normal" /></label>
        <label className="text-xs font-semibold text-slate-600 sm:col-span-2">Case details<textarea required maxLength={10000} rows={4} value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} className="mt-1.5 block w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm font-normal" /></label>
        <div className="flex justify-end gap-2 sm:col-span-2"><button type="button" onClick={() => setShowForm(false)} className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600">Cancel</button><button className="rounded-lg bg-[#174C38] px-4 py-2 text-sm font-semibold text-white">Create case</button></div>
      </form>}
      {canManage && <section className="overflow-hidden rounded-2xl border border-[#E1E9E2] bg-white">
        <div className="overflow-x-auto"><table className="w-full min-w-[920px] text-left text-sm"><thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Case</th><th className="px-5 py-3">Customer</th><th className="px-5 py-3">Type</th><th className="px-5 py-3">Priority</th><th className="px-5 py-3">Opened</th><th className="px-5 py-3">Status</th></tr></thead><tbody className="divide-y divide-slate-100">
          {cases.map((item) => <tr key={item.id} className="align-top"><td className="px-5 py-4"><div className="font-semibold">{item.subject}</div><div className="mt-1 max-w-md text-xs leading-5 text-slate-500">{item.description}</div></td><td className="px-5 py-4"><div className="text-xs">{item.customer_email}</div><div className="mt-1 font-mono text-[10px] text-slate-400">{item.organization_id || "No linked company"}</div></td><td className="px-5 py-4 capitalize">{item.category}</td><td className="px-5 py-4"><span className={`rounded-full px-2.5 py-1 text-xs font-semibold capitalize ${item.priority === "urgent" || item.priority === "high" ? "bg-rose-50 text-rose-700" : "bg-slate-100 text-slate-600"}`}>{item.priority}</span></td><td className="whitespace-nowrap px-5 py-4 text-xs text-slate-500">{item.created_at ? new Date(item.created_at).toLocaleDateString() : "—"}</td><td className="px-5 py-4"><select aria-label={`Status for ${item.subject}`} disabled={busyId === item.id} value={item.status} onChange={(event) => void updateCase(item.id, event.target.value)} className="rounded-lg border border-slate-200 bg-white px-2 py-2 text-xs capitalize">{["open", "in_progress", "waiting_on_customer", "resolved", "closed"].map((status) => <option key={status} value={status}>{status.replaceAll("_", " ")}</option>)}</select></td></tr>)}
          {!loading && cases.length === 0 && <tr><td colSpan={6} className="px-5 py-14 text-center text-sm text-slate-500"><Headset className="mx-auto mb-3 h-6 w-6 text-slate-300" />No active support cases yet.</td></tr>}
          {loading && <tr><td colSpan={6} className="px-5 py-10 text-center text-sm text-slate-500">Loading support queue…</td></tr>}
        </tbody></table></div>
      </section>}
    </div>
  );
}
