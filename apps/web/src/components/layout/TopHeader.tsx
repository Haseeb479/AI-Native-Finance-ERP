import React from "react";
import { Search, Zap, Building } from "lucide-react";
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
        "finova-dashboard-header h-16 px-6 sm:px-8 flex items-center justify-between border-b border-[#E7EEE8] shrink-0 sticky top-0 bg-white/95 backdrop-blur-md z-30",
        className
      )}
    >
      {/* Left: Active Screen Title & Organization Badges */}
      <div className="flex items-center space-x-3 min-w-0">
        <h2         className="text-sm sm:text-base font-semibold tracking-tight text-[#17251E] truncate">
          {title}
        </h2>

        {/* Tenant Organization Pill */}
        {currentOrg && (
          <span className="hidden md:inline-flex items-center space-x-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-[#EDF5EF] text-[#286344] border border-[#DDEBDF] shrink-0">
            <Building className="w-3 h-3 text-[#37805B]" />
            <span>{currentOrg.name}</span>
            <span className="text-[10px] text-[#6E8C78] font-mono">({currentOrg.base_currency || "PKR"})</span>
          </span>
        )}

        {/* Engine Status Indicator */}
        <span
          className={cn(
            "inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium border shrink-0",
            isConnected
              ? "bg-[#EFF7F0] text-[#286344] border-[#D6EAD9]"
              : "bg-amber-50 text-amber-700 border-amber-200"
          )}
        >
          <span
            className={cn(
              "w-1.5 h-1.5 rounded-full mr-1.5",
              isConnected ? "bg-[#4A9A68] animate-pulse" : "bg-amber-500"
            )}
          />
          <span className="hidden sm:inline">{isConnected ? "Connected" : "Reconnecting"}</span>
          <span className="sm:hidden">{isConnected ? "Online" : "Offline"}</span>
        </span>
      </div>

      {/* Right Action Controls */}
      <div className="flex items-center space-x-3 shrink-0">
        {/* Axiom AI Status & Architecture */}
        <button
          onClick={onOpenAxiomConfig}
          title="Axiom AI Engine Architecture & Status"
          className="inline-flex items-center space-x-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold text-[#286344] bg-[#EFF7F0] hover:bg-[#E4F0E6] border border-[#D6EAD9] transition-colors cursor-pointer"
        >
          <Zap className="w-3.5 h-3.5 text-[#37805B]" />
          <span className="hidden md:inline">Axiom AI</span>
        </button>

        {/* Spotlight Search (Ctrl+K) */}
        <button
          onClick={onOpenSearch}
          title="Search Records & Commands (Ctrl+K)"
          className="p-2 text-[#839188] hover:text-[#174A34] rounded-lg hover:bg-[#F1F6F1] transition-colors cursor-pointer"
        >
          <Search className="w-4 h-4 stroke-[1.75]" />
        </button>
      </div>
    </header>
  );
}
