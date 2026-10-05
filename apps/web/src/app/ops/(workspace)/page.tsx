"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Activity, ArrowUpRight, Building2, CircleAlert, Mail, TicketCheck } from "lucide-react";
import { useOpsStaff } from "@/components/ops/OpsShell";

type Overview = {
  organizations: { id: string; name: string; status: string; onboarding: { primary_entity_configured: boolean; branch_configured: boolean }; subscription: { status: string; plan_name: string } | null }[];
  demos: { id: number; company_name: string; email: string; status: string; created_at: string | null }[];
  cases: { id: string; subject: string; status: string; priority: string }[] | null;
  readiness: { status: string; ready: boolean; passing_checks: number; failing_checks: number } | null;
};

async function load<T>(path: string): Promise<T> {
  const response = await fetch(`/api/control-center/${path}`, { cache: "no-store" });
  const body = await response.json().catch(() => null);
  if (!response.ok) throw new Error(body?.errors?.[0]?.message || body?.errors?.[0] || body?.error || `Could not load ${path}.`);
  return body.data;
}

export default function OperationsOverviewPage() {
  const staff = useOpsStaff();
  const canSeeDemos = ["ops_admin", "ops_manager", "ops_sales"].includes(staff?.role || "");
  const canSeeCases = ["ops_admin", "ops_manager", "ops_support"].includes(staff?.role || "");
  const canSeeReadiness = ["ops_admin", "ops_manager"].includes(staff?.role || "");
  const [overview, setOverview] = useState<Overview | null>(null);
  const [error, setError] = useState("");

  useEffect(() => {
    let cancelled = false;
    async function refresh() {
      const results = await Promise.allSettled([
        load<{ organizations: Overview["organizations"] }>("organizations?per_page=100"),
        canSeeDemos ? load<{ requests: Overview["demos"] }>("demo-requests?per_page=100") : Promise.resolve(null),
        canSeeCases ? load<{ cases: NonNullable<Overview["cases"]> }>("support-cases?per_page=100") : Promise.resolve(null),
        canSeeReadiness ? load<Overview["readiness"]>("service-readiness") : Promise.resolve(null),
      ]);
      if (cancelled) return;
      const [organizations, demos, cases, readiness] = results;
      const requiredFailure = [organizations, ...(canSeeReadiness ? [readiness] : [])].find((result) => result.status === "rejected");
      if (requiredFailure?.status === "rejected") {
        setError(requiredFailure.reason instanceof Error ? requiredFailure.reason.message : "Operations data unavailable.");
      }
      setOverview({
        organizations: organizations.status === "fulfilled" ? organizations.value.organizations : [],
        demos: demos.status === "fulfilled" && demos.value ? demos.value.requests : [],
        cases: cases.status === "fulfilled" && cases.value ? cases.value.cases : null,
        readiness: readiness.status === "fulfilled" && readiness.value ? readiness.value : null,
      });
    }
    void refresh();
    return () => { cancelled = true; };
  }, [canSeeDemos, canSeeCases, canSeeReadiness]);

  const organizations = overview?.organizations || [];
  const openDemos = (overview?.demos || []).filter((item) => item.status === "new" || item.status === "contacted").length;
  const openCases = overview?.cases?.filter((item) => item.status !== "resolved" && item.status !== "closed").length;
  const onboarding = organizations.filter((item) => !item.onboarding.primary_entity_configured || !item.onboarding.branch_configured).length;
  const cards = [
    { label: "Customer organizations", value: overview ? organizations.length : "—", note: `${onboarding} onboarding follow-ups`, href: "/ops/companies", icon: Building2 },
    { label: "Demo pipeline", value: overview ? openDemos : "—", note: "New or awaiting contact", href: "/ops/demo-requests", icon: Mail },
    { label: "Support & access", value: openCases ?? "Restricted", note: openCases === undefined ? "Role-restricted queue" : "Open operational cases", href: "/ops/support-cases", icon: TicketCheck },
    { label: "Service readiness", value: canSeeReadiness ? overview?.readiness?.ready ? "Ready" : overview?.readiness ? "Attention" : "—" : "Restricted", note: canSeeReadiness && overview?.readiness ? `${overview.readiness.failing_checks} checks need attention` : "Live diagnostics", href: "/ops/system", icon: Activity },
  ];

  return (
    <div className="space-y-8">
      <header className="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
        <div><p className="text-xs font-bold uppercase tracking-[0.17em] text-emerald-800">Team overview</p><h1 className="mt-2 text-3xl font-semibold tracking-tight">Operations dashboard</h1><p className="mt-2 text-sm text-slate-500">Customer lifecycle and platform operations, in one staff workspace.</p></div>
        <Link href="/ops/companies" className="inline-flex items-center gap-2 self-start rounded-xl bg-[#174C38] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#103C2C]">Open company directory <ArrowUpRight size={16} /></Link>
      </header>
      {error && <div role="alert" className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><CircleAlert className="mt-0.5 shrink-0" size={17} /><span>{error} Metrics unavailable from failed services are not presented as zero.</span></div>}
      <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {cards.map(({ label, value, note, href, icon: Icon }) => (
          <Link key={label} href={href} className="rounded-2xl border border-[#E1E9E2] bg-white p-5 shadow-[0_2px_10px_rgba(16,37,28,0.025)] transition hover:-translate-y-0.5 hover:border-emerald-200 hover:shadow-md">
            <div className="flex items-start justify-between"><span className="text-sm font-medium text-slate-500">{label}</span><span className="grid h-9 w-9 place-items-center rounded-xl bg-[#EDF5EF] text-[#286044]"><Icon size={17} /></span></div>
            <div className="mt-5 text-3xl font-semibold tracking-tight">{value}</div><div className="mt-1 text-xs text-slate-500">{note}</div>
          </Link>
        ))}
      </section>
      <section className="grid gap-5 xl:grid-cols-[1.2fr_0.8fr]">
        <article className="rounded-2xl border border-[#E1E9E2] bg-white">
          <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4"><div><h2 className="font-semibold">Onboarding follow-up</h2><p className="mt-1 text-xs text-slate-500">Organizations missing a primary entity or branch.</p></div><Link className="text-xs font-semibold text-emerald-800 hover:underline" href="/ops/companies">View all</Link></div>
          <div className="divide-y divide-slate-100">
            {organizations.filter((org) => !org.onboarding.primary_entity_configured || !org.onboarding.branch_configured).slice(0, 5).map((org) => (
              <Link key={org.id} href={`/ops/companies/${org.id}`} className="flex items-center justify-between gap-3 px-5 py-4 hover:bg-slate-50"><div><div className="text-sm font-semibold">{org.name}</div><div className="mt-1 text-xs text-slate-500">{org.status} · {org.subscription?.plan_name || "No plan"}</div></div><span className="text-xs font-medium text-amber-700">Setup needed</span></Link>
            ))}
            {overview && onboarding === 0 && <p className="px-5 py-8 text-center text-sm text-slate-500">No onboarding follow-ups currently required.</p>}
            {!overview && <p className="px-5 py-8 text-center text-sm text-slate-500">Loading customer operations…</p>}
          </div>
        </article>
        <article className="rounded-2xl border border-[#E1E9E2] bg-white">
          <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4"><div><h2 className="font-semibold">Recent demo requests</h2><p className="mt-1 text-xs text-slate-500">Follow up with prospective companies.</p></div><Link className="text-xs font-semibold text-emerald-800 hover:underline" href="/ops/demo-requests">Pipeline</Link></div>
          <div className="divide-y divide-slate-100">
            {!canSeeDemos && <p className="px-5 py-8 text-center text-sm text-slate-500">Prospect contact data is restricted to the sales team.</p>}
            {canSeeDemos && (overview?.demos || []).slice(0, 5).map((demo) => <div key={demo.id} className="flex items-center justify-between gap-3 px-5 py-4"><div className="min-w-0"><div className="truncate text-sm font-semibold">{demo.company_name}</div><div className="mt-1 truncate text-xs text-slate-500">{demo.email}</div></div><span className="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold capitalize text-slate-600">{demo.status}</span></div>)}
            {canSeeDemos && overview && overview.demos.length === 0 && <p className="px-5 py-8 text-center text-sm text-slate-500">No demo requests recorded.</p>}
            {canSeeDemos && !overview && <p className="px-5 py-8 text-center text-sm text-slate-500">Loading pipeline…</p>}
          </div>
        </article>
      </section>
    </div>
  );
}
