import React from "react";
import { AlertCircle, RefreshCw } from "lucide-react";
import { cn } from "@/lib/utils";

export interface FinancialErrorStateProps {
  title?: string;
  message?: string;
  onRetry?: () => void;
  className?: string;
}

export function FinancialErrorState({
  title = "Failed to load financial records",
  message = "An error occurred while communicating with the accounting engine. Data is not displayed to prevent reporting discrepancies.",
  onRetry,
  className,
}: FinancialErrorStateProps) {
  return (
    <div
      className={cn(
        "rounded-2xl border border-rose-200/80 bg-rose-50/60 p-8 text-center",
        className
      )}
    >
      <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-rose-100 text-rose-600 mb-3">
        <AlertCircle className="h-6 w-6 stroke-[1.75]" />
      </div>
      <h3 className="text-sm font-bold text-rose-950 mb-1">{title}</h3>
      <p className="text-xs text-rose-800/80 max-w-md mx-auto mb-4">{message}</p>
      {onRetry && (
        <button
          type="button"
          onClick={onRetry}
          className="inline-flex items-center space-x-1.5 px-3.5 py-1.5 rounded-lg bg-rose-600 text-white text-xs font-semibold hover:bg-rose-700 transition-colors shadow-xs cursor-pointer"
        >
          <RefreshCw className="w-3.5 h-3.5" />
          <span>Retry Connection</span>
        </button>
      )}
    </div>
  );
}
