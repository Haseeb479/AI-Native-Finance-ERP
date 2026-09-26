import React from "react";
import { X, ShieldCheck, Clock, FileText, CheckCircle2 } from "lucide-react";
import { cn } from "@/lib/utils";

interface SlideOverDrawerProps {
  isOpen: boolean;
  onClose: () => void;
  title: string;
  subtitle?: string;
  badge?: React.ReactNode;
  children: React.ReactNode;
  footer?: React.ReactNode;
  width?: "md" | "lg" | "xl";
}

export function SlideOverDrawer({
  isOpen,
  onClose,
  title,
  subtitle,
  badge,
  children,
  footer,
  width = "lg",
}: SlideOverDrawerProps) {
  if (!isOpen) return null;

  const widthClasses = {
    md: "max-w-md",
    lg: "max-w-xl",
    xl: "max-w-2xl",
  };

  return (
    <div className="fixed inset-0 z-50 overflow-hidden">
      {/* Backdrop */}
      <div
        onClick={onClose}
        className="fixed inset-0 bg-black/40 backdrop-blur-xs transition-opacity animate-in fade-in"
      />

      <div className="fixed inset-y-0 right-0 flex max-w-full pl-10">
        <div
          className={cn(
            "w-screen bg-white shadow-2xl border-l border-[#E2E8F0] flex flex-col transform transition-transform animate-in slide-in-from-right duration-200",
            widthClasses[width]
          )}
        >
          {/* Header */}
          <div className="p-6 border-b border-[#F1F5F9] bg-[#FAFAFC] flex items-start justify-between">
            <div className="space-y-1">
              <div className="flex items-center space-x-2.5">
                <h3 className="text-base font-bold text-[#0F172A] tracking-tight">{title}</h3>
                {badge}
              </div>
              {subtitle && <p className="text-xs text-[#64748B]">{subtitle}</p>}
            </div>

            <button
              onClick={onClose}
              className="text-[#94A3B8] hover:text-[#0F172A] p-1.5 rounded-lg hover:bg-slate-200/60 transition-colors cursor-pointer"
            >
              <X className="w-4 h-4" />
            </button>
          </div>

          {/* Drawer Body */}
          <div className="flex-1 overflow-y-auto p-6 space-y-6 text-xs text-[#0F172A]">
            {children}
          </div>

          {/* Drawer Footer (Actions, Approval, Close) */}
          {footer && (
            <div className="p-4 border-t border-[#F1F5F9] bg-white flex items-center justify-between">
              {footer}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
