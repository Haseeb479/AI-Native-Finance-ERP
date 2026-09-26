import React from "react";
import { cn } from "@/lib/utils";

export type BadgeVariant =
  | "posted"
  | "draft"
  | "pending_approval"
  | "approved"
  | "rejected"
  | "reconciled"
  | "unreconciled"
  | "active"
  | "locked"
  | "error"
  | "warning"
  | "neutral"
  | "success"
  | "danger"
  | "outline";

interface BadgeProps {
  variant?: BadgeVariant;
  children: React.ReactNode;
  className?: string;
  dot?: boolean;
}

export function Badge({ variant = "neutral", children, className, dot = true }: BadgeProps) {
  const variantStyles: Record<BadgeVariant, { bg: string; text: string; border: string; dotColor: string }> = {
    posted: {
      bg: "bg-emerald-50",
      text: "text-emerald-700",
      border: "border-emerald-200",
      dotColor: "bg-emerald-500",
    },
    approved: {
      bg: "bg-emerald-50",
      text: "text-emerald-700",
      border: "border-emerald-200",
      dotColor: "bg-emerald-500",
    },
    draft: {
      bg: "bg-slate-50",
      text: "text-slate-600",
      border: "border-slate-200",
      dotColor: "bg-slate-400",
    },
    pending_approval: {
      bg: "bg-amber-50",
      text: "text-amber-800",
      border: "border-amber-200",
      dotColor: "bg-amber-500",
    },
    rejected: {
      bg: "bg-rose-50",
      text: "text-rose-700",
      border: "border-rose-200",
      dotColor: "bg-rose-500",
    },
    reconciled: {
      bg: "bg-indigo-50",
      text: "text-indigo-700",
      border: "border-indigo-200",
      dotColor: "bg-indigo-500",
    },
    unreconciled: {
      bg: "bg-amber-50/70",
      text: "text-amber-800",
      border: "border-amber-200",
      dotColor: "bg-amber-500",
    },
    active: {
      bg: "bg-emerald-50",
      text: "text-emerald-700",
      border: "border-emerald-200",
      dotColor: "bg-emerald-500 animate-pulse",
    },
    locked: {
      bg: "bg-slate-100",
      text: "text-slate-700",
      border: "border-slate-300",
      dotColor: "bg-slate-500",
    },
    error: {
      bg: "bg-rose-50",
      text: "text-rose-700",
      border: "border-rose-200",
      dotColor: "bg-rose-500",
    },
    warning: {
      bg: "bg-amber-50",
      text: "text-amber-800",
      border: "border-amber-200",
      dotColor: "bg-amber-500",
    },
    neutral: {
      bg: "bg-slate-50",
      text: "text-slate-600",
      border: "border-slate-200",
      dotColor: "bg-slate-400",
    },
    success: {
      bg: "bg-emerald-50",
      text: "text-emerald-700",
      border: "border-emerald-200",
      dotColor: "bg-emerald-500",
    },
    danger: {
      bg: "bg-rose-50",
      text: "text-rose-700",
      border: "border-rose-200",
      dotColor: "bg-rose-500",
    },
    outline: {
      bg: "bg-transparent",
      text: "text-slate-700",
      border: "border-slate-300",
      dotColor: "bg-slate-400",
    },
  };

  const current = variantStyles[variant] || variantStyles.neutral;

  return (
    <span
      className={cn(
        "inline-flex items-center space-x-1.5 px-2 py-0.5 rounded-full text-[11px] font-medium border",
        current.bg,
        current.text,
        current.border,
        className
      )}
    >
      {dot && <span className={cn("w-1.5 h-1.5 rounded-full shrink-0", current.dotColor)} />}
      <span>{children}</span>
    </span>
  );
}
