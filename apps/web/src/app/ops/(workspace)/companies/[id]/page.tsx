"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { ArrowLeft, CircleCheck, CircleDashed } from "lucide-react";
import { opsApi } from "@/lib/ops-api";

type Organization = {
  id: string; name: string; status: string; created_at: string | null;
  owner: { name: string; email: string } | null; members_count: number;
  onboarding: { primary_entity_configured: boolean; branch_configured: boolean };
  subscription: { status: string; plan_name: string; current_period_end: string | null } | null;
  usage: { metric: string; count: number; period: string }[];
};

export default function OperationsCompanyPage() {
  const { id } = useParams<{ id: string }>();
  const [organization, setOrganization] = useState<Organization | null>(null);
  const [error, setError] = useState("");
  useEffect(() => {
    let cancelled = false;
    opsApi<{ organization: Organization }>(`organizations/${encodeURIComponent(id)}`)
      .then((data) => { if (!cancelled) setOrganization(data.organization); })
      .catch((reason: Error) => { if (!cancelled) setError(reason.message); });
    return () => { cancelled = true; };
  }, [id]);

  return (
    <div className="space-y-6">
      <Link href="/ops/companies" className="inline-flex items-center gap-2 text-sm font-semibold text-emerald-800 hover:underline"><ArrowLeft size={16} />Company directory</Link>
      {error && <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">{error}</p>}
      {!error && !organization && <p role="status" className="text-sm text-slate-500">Loading company record…</p>}
      {organization && <>
        <header className="rounded-2xl border border-[#E1E9E2] bg-white p-6">
          <p className="text-xs font-bold uppercase tracking-[0.16em] text-emerald-800">Company operations record</p>
          <div className="mt-2 flex flex-wrap items-start justify-between gap-4"><div><h1 className="text-3xl font-semibold tracking-tight">{organization.name}</h1><p className="mt-2 text-sm text-slate-500">Customer since {organization.created_at ? new Date(organization.created_at).toLocaleDateString() : "date unavailable"}</p></div><span className="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold capitalize text-slate-700">{organization.status}</span></div>
        </header>
        <section className="grid gap-4 lg:grid-cols-2">
          <article className="rounded-2xl border border-[#E1E9E2] bg-white p-5"><h2 className="font-semibold">Account owner</h2><div className="mt-4 text-sm font-medium">{organization.owner?.name || "Not assigned"}</div><div className="mt-1 text-sm text-slate-500">{organization.owner?.email || "No owner email"}</div><div className="mt-4 border-t border-slate-100 pt-4 text-sm text-slate-600">{organization.members_count} workspace members</div></article>
          <article className="rounded-2xl border border-[#E1E9E2] bg-white p-5"><h2 className="font-semibold">Onboarding checklist</h2>{[["Primary entity", organization.onboarding.primary_entity_configured], ["Operating branch", organization.onboarding.branch_configured]].map(([label, complete]) => <div key={String(label)} className="mt-4 flex items-center gap-2 text-sm">{complete ? <CircleCheck className="text-emerald-700" size={17} /> : <CircleDashed className="text-amber-600" size={17} />}<span>{label}</span><span className="ml-auto text-xs text-slate-500">{complete ? "Configured" : "Follow-up needed"}</span></div>)}</article>
          <article className="rounded-2xl border border-[#E1E9E2] bg-white p-5"><h2 className="font-semibold">Subscription overview</h2>{organization.subscription ? <><p className="mt-4 font-medium">{organization.subscription.plan_name} · <span className="capitalize">{organization.subscription.status}</span></p><p className="mt-1 text-sm text-slate-500">Period ends {organization.subscription.current_period_end ? new Date(organization.subscription.current_period_end).toLocaleDateString() : "not available"}</p><p className="mt-4 rounded-lg bg-slate-50 p-3 text-xs leading-5 text-slate-600">Plan changes and billing actions are not enabled in the staff portal. Use the approved billing process; do not modify a customer plan from this view.</p></> : <p className="mt-4 text-sm text-slate-500">No subscription record.</p>}</article>
          <article className="rounded-2xl border border-[#E1E9E2] bg-white p-5"><h2 className="font-semibold">Usage summary</h2>{organization.usage.length ? <ul className="mt-4 space-y-3">{organization.usage.map((item) => <li key={item.metric} className="flex justify-between gap-4 text-sm"><span className="capitalize text-slate-600">{item.metric.replaceAll("_", " ")}</span><span className="font-semibold">{item.count.toLocaleString()}</span></li>)}</ul> : <p className="mt-4 text-sm text-slate-500">No allowlisted usage recorded for this period.</p>}</article>
        </section>
        <p className="text-xs leading-5 text-slate-500">Limited operations profile only. This view does not grant access to the company’s invoices, bills, banking, journals, or customer documents.</p>
      </>}
    </div>
  );
}
