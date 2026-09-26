import React from "react";
import { AlertCircle, Clock, ArrowRight, CheckCircle2, RefreshCw, Landmark, FileText } from "lucide-react";
import { cn } from "@/lib/utils";

export interface AttentionItem {
  id: string;
  type: "unmatched_txn" | "pending_approval" | "close_blocker" | "sync_failure" | "fbr_alert";
  title: string;
  description: string;
  amount?: string;
  urgency: "high" | "medium" | "low";
  timestamp?: string;
  actionLabel: string;
  onAction: () => void;
}

interface AttentionStreamProps {
  items: AttentionItem[];
  className?: string;
}

export function AttentionStream({ items, className }: AttentionStreamProps) {
  if (items.length === 0) {
    return (
      <div className="p-6 bg-emerald-50/50 border border-emerald-200/60 rounded-2xl flex items-center space-x-3 text-xs text-emerald-900">
        <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0" />
        <div>
          <span className="font-semibold block">Attention Stream Clear</span>
          <span className="text-emerald-700/90 text-[11px]">
            Zero pending approvals, unmatched bank transactions, or integration blockers requiring immediate review.
          </span>
        </div>
      </div>
    );
  }

  const typeIcons = {
    unmatched_txn: <Landmark className="w-4 h-4 text-amber-600" />,
    pending_approval: <Clock className="w-4 h-4 text-purple-600" />,
    close_blocker: <AlertCircle className="w-4 h-4 text-rose-600" />,
    sync_failure: <RefreshCw className="w-4 h-4 text-rose-600" />,
    fbr_alert: <FileText className="w-4 h-4 text-indigo-600" />,
  };

  const urgencyBorders = {
    high: "border-l-4 border-l-rose-500",
    medium: "border-l-4 border-l-amber-500",
    low: "border-l-4 border-l-indigo-500",
  };

  return (
    <div className={cn("space-y-2.5", className)}>
      {items.map((item) => (
        <div
          key={item.id}
          className={cn(
            "p-3.5 bg-white border border-[#E2E8F0] rounded-xl shadow-[0_1px_3px_rgba(0,0,0,0.02)] flex items-center justify-between gap-4 hover:border-[#CBD5E1] transition-all",
            urgencyBorders[item.urgency]
          )}
        >
          <div className="flex items-start space-x-3 flex-1 min-w-0">
            <div className="w-8 h-8 rounded-lg bg-slate-50 border border-slate-200/80 flex items-center justify-center shrink-0 mt-0.5">
              {typeIcons[item.type]}
            </div>

            <div className="space-y-0.5 min-w-0 flex-1">
              <div className="flex items-center space-x-2">
                <span className="font-semibold text-xs text-[#0F172A] truncate">{item.title}</span>
                {item.amount && (
                  <span className="text-[11px] font-bold text-[#0F172A] font-tabular bg-slate-100 px-1.5 py-0.2 rounded">
                    {item.amount}
                  </span>
                )}
              </div>
              <p className="text-[11px] text-[#64748B] line-clamp-1 leading-relaxed">{item.description}</p>
            </div>
          </div>

          <button
            onClick={item.onAction}
            className="shrink-0 text-xs font-semibold text-[#4F46E5] hover:text-[#4338CA] bg-indigo-50/70 hover:bg-indigo-100/70 px-3 py-1.5 rounded-lg transition-colors flex items-center space-x-1 cursor-pointer"
          >
            <span>{item.actionLabel}</span>
            <ArrowRight className="w-3 h-3" />
          </button>
        </div>
      ))}
    </div>
  );
}
