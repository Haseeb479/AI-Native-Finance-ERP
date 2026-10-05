"use client";

import { useEffect, useState } from "react";
import { Activity, CircleAlert, CircleCheck, RefreshCw } from "lucide-react";

type Check = { pass: boolean; status: string; description: string; details?: Record<string, unknown> };
type Readiness = { status: string; ready: boolean; total_checks: number; passing_checks: number; failing_checks: number; checks: Record<string, Check>; system_info?: Record<string, string> };

export default function OperationsSystemPage() {
  const [readiness, setReadiness] = useState<Readiness | null>(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    fetch("/api/control-center/service-readiness", { cache: "no-store" })
      .then(async (response) => {
        const body = await response.json().catch(() => null);
        if (!response.ok && response.status !== 503) throw new Error(body?.error || body?.errors?.[0]?.message || "Could not load service readiness.");
        if (!body?.data?.checks) throw new Error(body?.error || "The readiness service returned no diagnostic results.");
        if (!cancelled) setReadiness(body.data as Readiness);
      })
      .catch((reason: Error) => { if (!cancelled) setError(reason.message); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, []);

  async function refresh() {
    setLoading(true);
    setError("");
    try {
      const response = await fetch("/api/control-center/service-readiness", { cache: "no-store" });
      const body = await response.json().catch(() => null);
      if (!response.ok && response.status !== 503) throw new Error(body?.error || body?.errors?.[0]?.message || "Could not load service readiness.");
      if (!body?.data?.checks) throw new Error(body?.error || "The readiness service returned no diagnostic results.");
      setReadiness(body.data as Readiness);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Service readiness is unavailable.");
    } finally {
      setLoading(false);
    }
  }
  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-end justify-between gap-4"><div><p className="text-xs font-bold uppercase tracking-[0.17em] text-emerald-800">Platform operations</p><h1 className="mt-2 text-3xl font-semibold tracking-tight">Service health</h1><p className="mt-2 text-sm text-slate-500">Live production-readiness checks from the API. A failing dependency remains visible as a failure.</p></div><button onClick={() => void refresh()} disabled={loading} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm font-semibold text-slate-700 disabled:opacity-50"><RefreshCw size={15} />{loading ? "Checking…" : "Run checks"}</button></header>
      {error && <div role="alert" className="flex gap-3 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800"><CircleAlert className="shrink-0" size={17} />{error}</div>}
      {readiness && <>
        <section className={`flex flex-wrap items-center justify-between gap-4 rounded-2xl border p-5 ${readiness.ready ? "border-emerald-200 bg-emerald-50" : "border-amber-200 bg-amber-50"}`}>
          <div className="flex items-center gap-3">{readiness.ready ? <CircleCheck className="text-emerald-700" size={22} /> : <CircleAlert className="text-amber-700" size={22} />}<div><div className="font-semibold">{readiness.ready ? "Production readiness checks passed" : "Production readiness needs attention"}</div><div className="mt-1 text-xs text-slate-600">{readiness.passing_checks} passed · {readiness.failing_checks} failed · {readiness.total_checks} total</div></div></div>
          <span className={`rounded-full px-3 py-1.5 text-xs font-bold uppercase tracking-wide ${readiness.ready ? "bg-emerald-100 text-emerald-800" : "bg-amber-100 text-amber-900"}`}>{readiness.status.replaceAll("_", " ")}</span>
        </section>
        <section className="overflow-hidden rounded-2xl border border-[#E1E9E2] bg-white">
          <div className="border-b border-slate-100 px-5 py-4"><h2 className="font-semibold">Readiness checks</h2><p className="mt-1 text-xs text-slate-500">Restricted diagnostic details are limited to provisioned Finova staff.</p></div>
          <div className="divide-y divide-slate-100">
            {Object.entries(readiness.checks).map(([key, check]) => <div key={key} className="flex flex-wrap items-start justify-between gap-4 px-5 py-4">
              <div className="flex min-w-0 items-start gap-3">{check.pass ? <CircleCheck className="mt-0.5 shrink-0 text-emerald-700" size={17} /> : <CircleAlert className="mt-0.5 shrink-0 text-rose-700" size={17} />}<div><div className="text-sm font-semibold">{check.description}</div><div className="mt-1 text-xs text-slate-500">{key.replaceAll("_", " ")}</div>{check.details && <pre className="mt-2 whitespace-pre-wrap break-words rounded-lg bg-slate-50 p-2.5 text-[11px] text-slate-600">{JSON.stringify(check.details, null, 2)}</pre>}</div></div>
              <span className={`rounded-full px-2.5 py-1 text-[10px] font-bold uppercase ${check.pass ? "bg-emerald-50 text-emerald-800" : "bg-rose-50 text-rose-800"}`}>{check.status}</span>
            </div>)}
          </div>
        </section>
        {readiness.system_info && <p className="text-xs text-slate-500">Runtime: {readiness.system_info.php_version} · {readiness.system_info.laravel_version} · {readiness.system_info.app_env}</p>}
      </>}
      {!readiness && loading && <div role="status" className="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500"><Activity className="mx-auto mb-3 h-6 w-6 animate-pulse text-emerald-800" />Checking backend and configured dependencies…</div>}
    </div>
  );
}
