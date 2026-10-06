"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { erpApi } from "@/lib/api";

const btn = "rounded-lg border border-[#E2E8F0] bg-white px-3 py-1.5 text-xs font-semibold text-[#334155] disabled:opacity-50";
const primary = "rounded-lg bg-[#4F46E5] px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50";

export function LiveCloseView({ orgId }: { orgId: string }) {
  const queryClient = useQueryClient();
  const [periodId, setPeriodId] = useState("");

  const periods = useQuery({ queryKey: ["periods", orgId], queryFn: () => erpApi.getPeriods(orgId) });
  const list: any[] = periods.data ?? [];
  const selectedId = periodId || list.find((p) => p.status === "open")?.id || list[0]?.id || "";
  const selected = list.find((p) => p.id === selectedId);

  const cycle = useQuery({
    queryKey: ["close-cycle", orgId, selectedId],
    queryFn: () => erpApi.getCloseCycle(orgId, selectedId),
    enabled: !!selectedId,
  });

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ["close-cycle", orgId] });
    queryClient.invalidateQueries({ queryKey: ["periods"] });
  };

  const toggle = useMutation({
    mutationFn: (task: any) => erpApi.toggleCloseTask(orgId, selectedId, task.id, !task.is_completed),
    onSuccess: refresh,
  });
  const softClose = useMutation({ mutationFn: () => erpApi.softClosePeriod(orgId, selectedId), onSuccess: refresh });
  const hardClose = useMutation({ mutationFn: () => erpApi.hardClosePeriod(orgId, selectedId), onSuccess: refresh });

  const tasks: any[] = cycle.data?.tasks ?? [];
  const done = tasks.filter((t) => t.is_completed).length;
  const percent = tasks.length ? Math.round((done / tasks.length) * 100) : 0;
  const error = (toggle.error || softClose.error || hardClose.error || cycle.error) as Error | null;

  return (
    <div className="mx-auto w-full max-w-[1100px] space-y-5 px-6 py-8 sm:px-10">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 className="text-2xl font-bold tracking-tight text-[#0F172A]">Month-End Close</h2>
          <p className="mt-1 text-xs text-[#64748B]">Live close checklist for your organization. Tasks are saved per period.</p>
        </div>
        <label className="text-xs font-semibold text-[#334155]">
          Period{" "}
          <select aria-label="Period" value={selectedId} onChange={(e) => setPeriodId(e.target.value)} className="ml-2 rounded-lg border border-[#E2E8F0] bg-white px-3 py-2 text-sm">
            {list.map((p) => (
              <option key={p.id} value={p.id}>{p.name} ({p.status})</option>
            ))}
          </select>
        </label>
      </div>

      <div className="rounded-xl border border-[#E2E8F0] bg-white p-4">
        <div className="flex items-center justify-between text-xs font-semibold text-[#334155]">
          <span>{done} of {tasks.length} tasks complete</span>
          <span>{percent}%</span>
        </div>
        <div className="mt-2 h-2 rounded-full bg-[#F1F5F9]"><div className="h-2 rounded-full bg-[#4F46E5]" style={{ width: `${percent}%` }} /></div>
      </div>

      {error && <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">{error.message || "Request failed."}</div>}
      {cycle.isLoading && <p className="text-xs text-[#64748B]">Loading close tasks…</p>}

      <ul className="divide-y divide-[#F1F5F9] rounded-xl border border-[#E2E8F0] bg-white">
        {tasks.map((task) => (
          <li key={task.id} className="flex items-start gap-3 p-4">
            <input
              type="checkbox"
              aria-label={task.title}
              checked={!!task.is_completed}
              disabled={toggle.isPending || selected?.status === "closed"}
              onChange={() => toggle.mutate(task)}
              className="mt-1 h-4 w-4"
            />
            <div className="flex-1">
              <p className={`text-sm font-semibold ${task.is_completed ? "text-[#94A3B8] line-through" : "text-[#0F172A]"}`}>{task.title}</p>
              <p className="text-xs text-[#64748B]">{task.description}</p>
            </div>
            <span className="rounded-full bg-[#F1F5F9] px-2 py-0.5 text-[10px] font-semibold uppercase text-[#475569]">{String(task.category || "").replace("_", " ")}</span>
          </li>
        ))}
      </ul>

      <div className="flex flex-wrap items-center gap-2">
        <button type="button" className={btn} disabled={!selected || selected.status !== "open" || softClose.isPending} onClick={() => softClose.mutate()}>
          {softClose.isPending ? "Soft closing…" : "Soft close period"}
        </button>
        <button type="button" className={primary} disabled={!selected || selected.status === "closed" || percent < 100 || hardClose.isPending} onClick={() => window.confirm("Hard close locks this period. Continue?") && hardClose.mutate()}>
          {hardClose.isPending ? "Closing…" : "Hard close period"}
        </button>
        {percent < 100 && <span className="text-xs text-[#64748B]">Complete all tasks to enable hard close.</span>}
      </div>
    </div>
  );
}
