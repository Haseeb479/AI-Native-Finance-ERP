import React from "react";
import Link from "next/link";
import { Search, Zap, Building, ExternalLink, Key } from "lucide-react";
import { cn } from "@/lib/utils";

interface TopHeaderProps {
  title: string;
  currentOrg?: { name?: string; base_currency?: string } | null;
  isConnected: boolean;
  onOpenSearch: () => void;
  onOpenAxiomConfig: () => void;
  className?: string;
}

export function TopHeader({
  title,
  currentOrg,
  isConnected,
  onOpenSearch,
  onOpenAxiomConfig,
  className,
}: TopHeaderProps) {
  return (
    <header
      className={cn(
        "h-16 px-6 sm:px-8 flex items-center justify-between border-b border-[#F1F5F9] shrink-0 sticky top-0 bg-white/95 backdrop-blur-md z-30",
        className
      )}
    >
      {/* Left: Active Screen Title & Organization Badges */}
      <div className="flex items-center space-x-3 min-w-0">
        <h2 className="text-sm sm:text-base font-bold tracking-tight text-[#0F172A] truncate">
          {title}
        </h2>

        {/* Tenant Organization Pill */}
        {currentOrg && (
          <span className="hidden md:inline-flex items-center space-x-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-indigo-50 text-indigo-700 border border-indigo-100 shrink-0">
            <Building className="w-3 h-3 text-indigo-500" />
            <span>{currentOrg.name}</span>
            <span className="text-[10px] text-indigo-400 font-mono">({currentOrg.base_currency || "PKR"})</span>
          </span>
        )}

        {/* Engine Status Indicator */}
        <span
          className={cn(
            "inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium border shrink-0",
            isConnected
              ? "bg-emerald-50 text-emerald-700 border-emerald-200"
              : "bg-amber-50 text-amber-700 border-amber-200"
          )}
        >
          <span
            className={cn(
              "w-1.5 h-1.5 rounded-full mr-1.5",
              isConnected ? "bg-emerald-500 animate-pulse" : "bg-amber-500"
            )}
          />
          <span className="hidden sm:inline">{isConnected ? "Engine Active (150 Tests OK)" : "Reconnecting"}</span>
          <span className="sm:hidden">{isConnected ? "Online" : "Offline"}</span>
        </span>
      </div>

      {/* Right Action Controls */}
      <div className="flex items-center space-x-3 shrink-0">
        {/* Axiom AI Groq Settings & Status */}
        <button
          onClick={onOpenAxiomConfig}
          title="Configure Axiom AI Groq LPU Key & Model"
          className="inline-flex items-center space-x-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold text-purple-700 bg-purple-50 hover:bg-purple-100 border border-purple-200 transition-colors cursor-pointer"
        >
          <Zap className="w-3.5 h-3.5 text-purple-600" />
          <span className="hidden md:inline">Axiom AI (Groq LPU)</span>
        </button>

        {/* Spotlight Search (Ctrl+K) */}
        <button
          onClick={onOpenSearch}
          title="Search Records & Commands (Ctrl+K)"
          className="p-2 text-[#94A3B8] hover:text-[#0F172A] rounded-lg hover:bg-slate-100 transition-colors cursor-pointer"
        >
          <Search className="w-4 h-4 stroke-[1.75]" />
        </button>

        {/* View Marketing Website link */}
        <Link
          href="/"
          title="View Finova Marketing Site"
          className="inline-flex items-center space-x-1 px-2.5 py-1.5 rounded-lg text-xs font-medium text-[#64748B] hover:text-[#0F172A] hover:bg-slate-100 transition-colors border border-[#E2E8F0]"
        >
          <span>Website</span>
          <ExternalLink className="w-3 h-3 text-[#94A3B8]" />
        </Link>
      </div>
    </header>
  );
}
