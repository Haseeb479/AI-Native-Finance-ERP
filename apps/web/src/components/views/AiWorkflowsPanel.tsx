"use client";

import { useState } from "react";
import { useMutation } from "@tanstack/react-query";
import { erpApi } from "@/lib/api";

const WORKFLOWS = [
  { key: "unreconciled-transactions", label: "Review unreconciled bank items" },
  { key: "ar-collections-queue", label: "Collections queue" },
  { key: "invoice-approval-queue", label: "Invoice approval queue" },
  { key: "missing-vendor-documents", label: "Missing vendor documents" },
] as const;

const humanize = (key: string) => key.replace(/_/g, " ").replace(/^\w/, (c) => c.toUpperCase());

function Value({ value }: { value: unknown }) {
  if (value === null || value === undefined || value === "") return <span className="text-[#94A3B8]">—</span>;
  if (Array.isArray(value)) {
    if (value.length === 0) return <span className="text-[#94A3B8]">None</span>;
    return (
      <ul className="mt-1 space-y-1">
        {value.map((item, index) => (
          <li key={index} className="rounded-lg border border-[#E2E8F0] bg-white p-2">
            {typeof item === "object" && item ? <Fields data={item as Record<string, unknown>} /> : String(item)}
          </li>
        ))}
      </ul>
    );
  }
  if (typeof value === "object") return <Fields data={value as Record<string, unknown>} />;
  return <span>{String(value)}</span>;
}

function Fields({ data }: { data: Record<string, unknown> }) {
  return (
    <dl className="grid gap-x-4 gap-y-1 text-xs sm:grid-cols-[180px_1fr]">
      {Object.entries(data).map(([key, value]) => (
        <div key={key} className="contents">
          <dt className="font-semibold text-[#475569]">{humanize(key)}</dt>
          <dd className="text-[#0F172A]"><Value value={value} /></dd>
        </div>
      ))}
    </dl>
  );
}

export function AiWorkflowsPanel({ orgId }: { orgId: string }) {
  const [active, setActive] = useState<string>("");
  const [instruction, setInstruction] = useState("");
  const [amount, setAmount] = useState("");

  const run = useMutation({
    mutationFn: (key: string) => {
      setActive(key);
      return erpApi.runAiWorkflow(orgId, key);
    },
  });

  const draft = useMutation({
    mutationFn: () => erpApi.draftAiJournal(orgId, instruction.trim(), amount ? Number(amount) : undefined),
  });

  return (
    <div className="mx-auto w-full max-w-[1320px] space-y-4 px-6 pt-6 sm:px-10">
      <div>
        <h2 className="text-lg font-bold text-[#0F172A]">Live AI assistant</h2>
        <p className="text-xs text-[#64748B]">Reads your real ledger and returns suggestions only. Nothing is posted or changed until you act on it in the relevant screen.</p>
      </div>

      <div className="flex flex-wrap gap-2">
        {WORKFLOWS.map((workflow) => (
          <button key={workflow.key} type="button" disabled={run.isPending} onClick={() => run.mutate(workflow.key)} className="rounded-lg border border-[#E2E8F0] bg-white px-3 py-2 text-xs font-semibold text-[#334155] disabled:opacity-50">
            {run.isPending && active === workflow.key ? "Analyzing…" : workflow.label}
          </button>
        ))}
      </div>
      {run.error && <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 p-2 text-xs text-rose-700">{(run.error as Error).message || "The AI service could not complete this request."}</div>}
      {run.data && !run.isPending && (
        <div className="rounded-xl border border-[#E2E8F0] bg-[#F8FAFC] p-4">
          <p className="mb-2 text-xs font-bold uppercase tracking-wide text-[#64748B]">{WORKFLOWS.find((w) => w.key === active)?.label}</p>
          <Fields data={run.data as Record<string, unknown>} />
        </div>
      )}

      <div className="rounded-xl border border-[#E2E8F0] bg-[#F8FAFC] p-4">
        <p className="mb-2 text-xs font-bold uppercase tracking-wide text-[#64748B]">Draft a journal entry (proposal only)</p>
        <div className="grid gap-2 sm:grid-cols-[1fr_140px_auto]">
          <input aria-label="Journal instruction" placeholder="e.g. Record Rs 20,000 office rent paid by bank" maxLength={1000} value={instruction} onChange={(e) => setInstruction(e.target.value)} className="rounded-lg border border-[#E2E8F0] bg-white px-3 py-2 text-sm" />
          <input aria-label="Journal amount" type="number" min="0" placeholder="Amount" value={amount} onChange={(e) => setAmount(e.target.value)} className="rounded-lg border border-[#E2E8F0] bg-white px-3 py-2 text-sm" />
          <button type="button" disabled={!instruction.trim() || draft.isPending} onClick={() => draft.mutate()} className="rounded-lg bg-[#4F46E5] px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">{draft.isPending ? "Drafting…" : "Draft"}</button>
        </div>
        {draft.error && <div role="alert" className="mt-2 rounded-lg border border-rose-200 bg-rose-50 p-2 text-xs text-rose-700">{(draft.error as Error).message || "The AI service could not draft this entry."}</div>}
        {draft.data && !draft.isPending && (
          <div className="mt-3">
            <Fields data={draft.data as Record<string, unknown>} />
            <p className="mt-2 text-[11px] text-[#64748B]">This is a proposal. Review it, then record it from General Ledger if it is correct.</p>
          </div>
        )}
      </div>
    </div>
  );
}
