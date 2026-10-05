"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Building2, Search } from "lucide-react";
import { opsApi } from "@/lib/ops-api";

type Organization = {
  id: string; name: string; status: string; created_at: string | null;
  owner: { name: string; email: string } | null; members_count: number;
  onboarding: { primary_entity_configured: boolean; branch_configured: boolean };
  subscription: { status: string; plan_name: string; current_period_end: string | null } | null;
};

export default function OperationsCompaniesPage() {
  const [organizations, setOrganizations] = useState<Organization[]>([]);
  const [query, setQuery] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    opsApi<{ organizations: Organization[] }>("organizations?per_page=100")
      .then((data) => { if (!cancelled) setOrganizations(data.organizations); })
      .catch((reason: Error) => { if (!cancelled) setError(reason.message); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, []);

  const filtered = organizations.filter((organization) =>
    [organization.name, organization.owner?.name, organization.owner?.email]
      .some((value) => value?.toLowerCase().includes(query.toLowerCase())),
  );

  return (
    <div className="space-y-6">
      <header><p className="text-xs font-bold uppercase tracking-[0.17em] text-emerald-800">Customer lifecycle</p><h1 className="mt-2 text-3xl font-semibold tracking-tight">Companies</h1><p className="mt-2 text-sm text-slate-500">Organization access and setup indicators. Customer financial records are intentionally not exposed here.</p></header>
      <div className="flex flex-col justify-between gap-3 rounded-2xl border border-[#E1E9E2] bg-white p-4 sm:flex-row sm:items-center">
        <div className="relative w-full sm:max-w-sm"><Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" /><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search company or owner" className="w-full rounded-xl border border-slate-200 py-2.5 pl-9 pr-3 text-sm outline-none focus:border-emerald-700" /></div>
        <span className="text-xs text-slate-500">{loading ? "Loading organizations…" : `${filtered.length} organizations`}</span>
      </div>
      {error && <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">{error}</div>}
      {!error && <div className="overflow-x-auto rounded-2xl border border-[#E1E9E2] bg-white">
        <table className="w-full min-w-[850px] text-left text-sm">
          <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Company</th><th className="px-5 py-3">Owner</th><th className="px-5 py-3">Members</th><th className="px-5 py-3">Plan</th><th className="px-5 py-3">Onboarding</th></tr></thead>
          <tbody className="divide-y divide-slate-100">
            {filtered.map((organization) => {
              const ready = organization.onboarding.primary_entity_configured && organization.onboarding.branch_configured;
              return <tr key={organization.id} className="hover:bg-slate-50">
                <td className="px-5 py-4"><Link href={`/ops/companies/${organization.id}`} className="font-semibold text-[#174C38] hover:underline">{organization.name}</Link><div className="mt-1 text-xs text-slate-500">{organization.status} · {organization.created_at ? new Date(organization.created_at).toLocaleDateString() : "date unavailable"}</div></td>
                <td className="px-5 py-4">{organization.owner ? <><div className="font-medium">{organization.owner.name}</div><div className="mt-1 text-xs text-slate-500">{organization.owner.email}</div></> : "Not assigned"}</td>
                <td className="px-5 py-4">{organization.members_count}</td>
                <td className="px-5 py-4">{organization.subscription ? <><div className="font-medium">{organization.subscription.plan_name}</div><div className="mt-1 text-xs capitalize text-slate-500">{organization.subscription.status}</div></> : <span className="text-slate-500">No subscription</span>}</td>
                <td className="px-5 py-4"><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${ready ? "bg-emerald-50 text-emerald-800" : "bg-amber-50 text-amber-800"}`}>{ready ? "Setup complete" : "Follow-up needed"}</span></td>
              </tr>;
            })}
            {!loading && filtered.length === 0 && <tr><td colSpan={5} className="px-5 py-12 text-center text-slate-500"><Building2 className="mx-auto mb-3 h-6 w-6 text-slate-300" />No organizations match this search.</td></tr>}
          </tbody>
        </table>
      </div>}
    </div>
  );
}
