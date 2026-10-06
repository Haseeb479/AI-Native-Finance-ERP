"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { erpApi } from "@/lib/api";

const money = (value: unknown) =>
  Number(value || 0).toLocaleString("en-PK", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const card = "rounded-xl border border-[#E2E8F0] bg-white";
const input = "rounded-lg border border-[#E2E8F0] bg-white px-3 py-2 text-sm";
const primary = "rounded-lg bg-[#4F46E5] px-3 py-2 text-xs font-semibold text-white disabled:opacity-50";

function ErrorLine({ error }: { error: unknown }) {
  if (!error) return null;
  return <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 p-2 text-xs text-rose-700">{(error as Error).message || "Request failed."}</div>;
}

export function LiveConsolidationView({ orgId, orgName }: { orgId: string; orgName?: string }) {
  const queryClient = useQueryClient();
  const today = new Date().toISOString().slice(0, 10);

  const entities = useQuery({ queryKey: ["entities", orgId], queryFn: () => erpApi.getEntities(orgId) });
  const rates = useQuery({ queryKey: ["exchange-rates", orgId], queryFn: () => erpApi.getExchangeRates(orgId) });
  const intercompany = useQuery({ queryKey: ["intercompany", orgId], queryFn: () => erpApi.getIntercompanyTransactions(orgId) });
  const periods = useQuery({ queryKey: ["periods", orgId], queryFn: () => erpApi.getPeriods(orgId) });
  const [selectedPeriodId, setPeriodId] = useState("");
  const currentPeriodId = (periods.data ?? []).find((period: any) => String(period.start_date).slice(0, 10) <= today && today <= String(period.end_date).slice(0, 10))?.id ?? "";
  const periodId = selectedPeriodId || currentPeriodId;
  const consolidated = useQuery({
    queryKey: ["consolidated-tb", orgId, periodId],
    queryFn: () => erpApi.getConsolidatedReport(orgId, "trial_balance", periodId || undefined),
  });

  const [entityForm, setEntityForm] = useState({ name: "", code: "", currency: "PKR" });
  const [rateForm, setRateForm] = useState({ from_currency: "USD", to_currency: "PKR", rate: "", effective_date: today });
  const [icForm, setIcForm] = useState({ from_entity_id: "", to_entity_id: "", amount: "", description: "", transaction_date: today });

  const refresh = (...keys: string[]) => keys.forEach((key) => queryClient.invalidateQueries({ queryKey: [key, orgId] }));

  const addEntity = useMutation({
    mutationFn: () => erpApi.createEntity(orgId, { name: entityForm.name.trim(), code: entityForm.code.trim(), currency: entityForm.currency.trim().toUpperCase() }),
    onSuccess: () => { setEntityForm({ name: "", code: "", currency: "PKR" }); refresh("entities", "consolidated-tb"); },
  });
  const addRate = useMutation({
    mutationFn: () => erpApi.createExchangeRate(orgId, { ...rateForm, rate: Number(rateForm.rate), from_currency: rateForm.from_currency.toUpperCase(), to_currency: rateForm.to_currency.toUpperCase() }),
    onSuccess: () => { setRateForm({ ...rateForm, rate: "" }); refresh("exchange-rates"); },
  });
  const addIntercompany = useMutation({
    mutationFn: () => erpApi.createIntercompanyTransaction(orgId, { ...icForm, amount: Number(icForm.amount) }),
    onSuccess: () => { setIcForm({ ...icForm, amount: "", description: "" }); refresh("intercompany"); },
  });
  const postIntercompany = useMutation({
    mutationFn: (id: string) => erpApi.postIntercompanyTransaction(orgId, id),
    onSuccess: () => refresh("intercompany", "consolidated-tb"),
  });
  const eliminate = useMutation({
    mutationFn: () => erpApi.runIntercompanyElimination(orgId, { accounting_period_id: periodId || consolidated.data?.period?.id }),
    onSuccess: () => refresh("consolidated-tb", "intercompany"),
  });

  const entityList: any[] = entities.data ?? [];
  const report: any = consolidated.data;
  const entityCodes: string[] = (report?.entities ?? []).map((entity: any) => entity.code);
  const entityName = (id: string) => entityList.find((entity) => entity.id === id)?.name ?? "—";

  return (
    <div className="mx-auto w-full max-w-[1100px] space-y-8 px-6 py-7 sm:px-10">
      <div>
        <p className="text-xs font-semibold uppercase tracking-wide text-[#6366F1]">Multi-entity</p>
        <h1 className="text-2xl font-bold text-[#0F172A]">Entities, currencies &amp; consolidation</h1>
        <p className="mt-1 text-sm text-[#64748B]">Live data{orgName ? ` for ${orgName}` : ""}. Add legal entities, exchange rates and intercompany activity, then review the consolidated trial balance.</p>
      </div>

      <section className={`${card} p-4 space-y-3`}>
        <h2 className="text-sm font-bold text-[#0F172A]">Legal entities</h2>
        {entities.error && <ErrorLine error={entities.error} />}
        <div className="grid gap-2 sm:grid-cols-3">
          {entityList.map((entity) => (
            <div key={entity.id} className="rounded-lg bg-[#F8FAFC] p-3 text-sm">
              <p className="font-semibold text-[#0F172A]">{entity.name}</p>
              <p className="text-xs text-[#64748B]">{entity.code} · {entity.currency}{entity.is_primary ? " · Primary" : ""}</p>
            </div>
          ))}
          {!entities.isLoading && entityList.length === 0 && <p className="text-xs text-[#94A3B8]">No entities yet.</p>}
        </div>
        <ErrorLine error={addEntity.error} />
        <div className="flex flex-wrap gap-2">
          <input aria-label="Entity name" placeholder="Entity name" maxLength={150} value={entityForm.name} onChange={(e) => setEntityForm({ ...entityForm, name: e.target.value })} className={input} />
          <input aria-label="Entity code" placeholder="Code" maxLength={20} value={entityForm.code} onChange={(e) => setEntityForm({ ...entityForm, code: e.target.value })} className={`${input} w-24`} />
          <input aria-label="Entity currency" placeholder="PKR" maxLength={3} value={entityForm.currency} onChange={(e) => setEntityForm({ ...entityForm, currency: e.target.value })} className={`${input} w-20`} />
          <button type="button" className={primary} disabled={!entityForm.name.trim() || !entityForm.code.trim() || entityForm.currency.length !== 3 || addEntity.isPending} onClick={() => addEntity.mutate()}>Add entity</button>
        </div>
      </section>

      <section className={`${card} p-4 space-y-3`}>
        <h2 className="text-sm font-bold text-[#0F172A]">Exchange rates</h2>
        {rates.error && <ErrorLine error={rates.error} />}
        <table className="w-full text-sm">
          <thead className="text-left text-xs uppercase text-[#64748B]"><tr><th className="py-1">Pair</th><th className="py-1 text-right">Rate</th><th className="py-1 text-right">Effective</th></tr></thead>
          <tbody>
            {(rates.data ?? []).map((rate: any) => (
              <tr key={rate.id} className="border-t border-[#F1F5F9]"><td className="py-1.5">{rate.from_currency}/{rate.to_currency}</td><td className="py-1.5 text-right">{Number(rate.rate)}</td><td className="py-1.5 text-right">{String(rate.effective_date).slice(0, 10)}</td></tr>
            ))}
            {!rates.isLoading && (rates.data ?? []).length === 0 && <tr><td colSpan={3} className="py-2 text-xs text-[#94A3B8]">No exchange rates recorded.</td></tr>}
          </tbody>
        </table>
        <ErrorLine error={addRate.error} />
        <div className="flex flex-wrap gap-2">
          <input aria-label="From currency" maxLength={3} value={rateForm.from_currency} onChange={(e) => setRateForm({ ...rateForm, from_currency: e.target.value })} className={`${input} w-20`} />
          <input aria-label="To currency" maxLength={3} value={rateForm.to_currency} onChange={(e) => setRateForm({ ...rateForm, to_currency: e.target.value })} className={`${input} w-20`} />
          <input aria-label="Rate" type="number" min="0" step="any" placeholder="Rate" value={rateForm.rate} onChange={(e) => setRateForm({ ...rateForm, rate: e.target.value })} className={`${input} w-28`} />
          <input aria-label="Effective date" type="date" value={rateForm.effective_date} onChange={(e) => setRateForm({ ...rateForm, effective_date: e.target.value })} className={input} />
          <button type="button" className={primary} disabled={!(Number(rateForm.rate) > 0) || rateForm.from_currency.length !== 3 || rateForm.to_currency.length !== 3 || addRate.isPending} onClick={() => addRate.mutate()}>Save rate</button>
        </div>
      </section>

      <section className={`${card} p-4 space-y-3`}>
        <h2 className="text-sm font-bold text-[#0F172A]">Intercompany transactions</h2>
        {intercompany.error && <ErrorLine error={intercompany.error} />}
        <table className="w-full text-sm">
          <thead className="text-left text-xs uppercase text-[#64748B]"><tr><th className="py-1">From → To</th><th className="py-1">Description</th><th className="py-1 text-right">Amount</th><th className="py-1">Status</th><th /></tr></thead>
          <tbody>
            {(intercompany.data ?? []).map((tx: any) => (
              <tr key={tx.id} className="border-t border-[#F1F5F9]">
                <td className="py-1.5">{tx.from_entity?.name ?? entityName(tx.from_entity_id)} → {tx.to_entity?.name ?? entityName(tx.to_entity_id)}</td>
                <td className="py-1.5">{tx.description}</td>
                <td className="py-1.5 text-right">{tx.currency} {money(tx.amount)}</td>
                <td className="py-1.5 capitalize">{tx.status}</td>
                <td className="py-1.5 text-right">{String(tx.status).toLowerCase() === "draft" && <button type="button" className="text-xs font-semibold text-[#4F46E5]" disabled={postIntercompany.isPending} onClick={() => postIntercompany.mutate(tx.id)}>Post</button>}</td>
              </tr>
            ))}
            {!intercompany.isLoading && (intercompany.data ?? []).length === 0 && <tr><td colSpan={5} className="py-2 text-xs text-[#94A3B8]">No intercompany transactions.</td></tr>}
          </tbody>
        </table>
        <ErrorLine error={addIntercompany.error || postIntercompany.error} />
        {entityList.length < 2 ? (
          <p className="text-xs text-[#94A3B8]">Add at least two entities to record intercompany activity.</p>
        ) : (
          <div className="flex flex-wrap gap-2">
            <select aria-label="From entity" value={icForm.from_entity_id} onChange={(e) => setIcForm({ ...icForm, from_entity_id: e.target.value })} className={input}><option value="">From entity</option>{entityList.map((entity) => <option key={entity.id} value={entity.id}>{entity.name}</option>)}</select>
            <select aria-label="To entity" value={icForm.to_entity_id} onChange={(e) => setIcForm({ ...icForm, to_entity_id: e.target.value })} className={input}><option value="">To entity</option>{entityList.map((entity) => <option key={entity.id} value={entity.id}>{entity.name}</option>)}</select>
            <input aria-label="Intercompany amount" type="number" min="0" step="any" placeholder="Amount" value={icForm.amount} onChange={(e) => setIcForm({ ...icForm, amount: e.target.value })} className={`${input} w-28`} />
            <input aria-label="Intercompany description" placeholder="Description" maxLength={500} value={icForm.description} onChange={(e) => setIcForm({ ...icForm, description: e.target.value })} className={input} />
            <input aria-label="Transaction date" type="date" value={icForm.transaction_date} onChange={(e) => setIcForm({ ...icForm, transaction_date: e.target.value })} className={input} />
            <button type="button" className={primary} disabled={!icForm.from_entity_id || !icForm.to_entity_id || icForm.from_entity_id === icForm.to_entity_id || !(Number(icForm.amount) > 0) || !icForm.description.trim() || addIntercompany.isPending} onClick={() => addIntercompany.mutate()}>Record</button>
          </div>
        )}
      </section>

      <section className={`${card} p-4 space-y-3`}>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-sm font-bold text-[#0F172A]">Consolidated trial balance</h2>
          <div className="flex items-center gap-2">
            <select aria-label="Consolidation period" value={periodId} onChange={(e) => setPeriodId(e.target.value)} className={input}>
              <option value="">Latest period</option>
              {(periods.data ?? []).map((period: any) => <option key={period.id} value={period.id}>{period.name}</option>)}
            </select>
            <button type="button" className={primary} disabled={eliminate.isPending || entityList.length < 2} onClick={() => eliminate.mutate()}>Run eliminations</button>
          </div>
        </div>
        <ErrorLine error={consolidated.error || eliminate.error} />
        {report && (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-left text-xs uppercase text-[#64748B]">
                <tr><th className="py-1">Account</th>{entityCodes.map((code) => <th key={code} className="py-1 text-right">{code}</th>)}<th className="py-1 text-right">Elims</th><th className="py-1 text-right">Consolidated</th></tr>
              </thead>
              <tbody>
                {(report.items ?? []).filter((item: any) => Number(item.consolidated_balance) !== 0 || Object.values(item.entity_breakdown ?? {}).some((value) => Number(value) !== 0)).map((item: any) => (
                  <tr key={item.account_code} className="border-t border-[#F1F5F9]">
                    <td className="py-1.5">{item.account_code} — {item.account_name}</td>
                    {entityCodes.map((code) => <td key={code} className="py-1.5 text-right">{money(item.entity_breakdown?.[code])}</td>)}
                    <td className="py-1.5 text-right">{money(item.eliminations)}</td>
                    <td className="py-1.5 text-right font-semibold">{money(item.consolidated_balance)}</td>
                  </tr>
                ))}
                {(report.items ?? []).every((item: any) => Number(item.consolidated_balance) === 0) && <tr><td colSpan={entityCodes.length + 3} className="py-2 text-xs text-[#94A3B8]">No posted activity in this period.</td></tr>}
              </tbody>
            </table>
            <p className={`mt-2 text-xs font-semibold ${report.is_balanced ? "text-emerald-700" : "text-rose-700"}`}>Debits {money(report.total_debit)} · Credits {money(report.total_credit)} — {report.is_balanced ? "Balanced" : "NOT balanced"}</p>
          </div>
        )}
      </section>
    </div>
  );
}
