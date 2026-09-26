import React from "react";
import { Search, Filter, RefreshCw, X } from "lucide-react";
import { cn } from "@/lib/utils";

export interface FilterOption {
  label: string;
  value: string;
}

interface FilterBarProps {
  searchQuery?: string;
  onSearchChange?: (val: string) => void;
  searchPlaceholder?: string;
  statusFilter?: string;
  onStatusChange?: (val: string) => void;
  statusOptions?: FilterOption[];
  entityFilter?: string;
  onEntityChange?: (val: string) => void;
  entityOptions?: FilterOption[];
  onRefresh?: () => void;
  isRefreshing?: boolean;
  count?: number;
  countLabel?: string;
  children?: React.ReactNode;
  className?: string;
}

export function FilterBar({
  searchQuery,
  onSearchChange,
  searchPlaceholder = "Filter records...",
  statusFilter,
  onStatusChange,
  statusOptions,
  entityFilter,
  onEntityChange,
  entityOptions,
  onRefresh,
  isRefreshing,
  count,
  countLabel = "records",
  children,
  className,
}: FilterBarProps) {
  return (
    <div
      className={cn(
        "flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 p-3 bg-white border border-[#E2E8F0] rounded-xl shadow-[0_1px_3px_rgba(0,0,0,0.02)] mb-3",
        className
      )}
    >
      <div className="flex flex-1 items-center flex-wrap gap-2.5">
        {/* Search input */}
        {onSearchChange !== undefined && (
          <div className="relative min-w-[220px] max-w-sm flex-1">
            <Search className="w-3.5 h-3.5 text-[#94A3B8] absolute left-3 top-2.5 pointer-events-none" />
            <input
              type="text"
              value={searchQuery || ""}
              onChange={(e) => onSearchChange(e.target.value)}
              placeholder={searchPlaceholder}
              className="w-full bg-[#F8FAFC] border border-[#E2E8F0] rounded-lg pl-8 pr-7 py-1.5 text-xs text-[#0F172A] placeholder:text-[#94A3B8] focus:bg-white focus:border-[#6366F1] focus:ring-1 focus:ring-[#6366F1] outline-none transition-all font-sans"
            />
            {searchQuery && (
              <button
                onClick={() => onSearchChange("")}
                className="absolute right-2.5 top-2.5 text-[#94A3B8] hover:text-[#0F172A] cursor-pointer"
              >
                <X className="w-3.5 h-3.5" />
              </button>
            )}
          </div>
        )}

        {/* Status Pill / Dropdown Filter */}
        {statusOptions && onStatusChange && (
          <div className="flex items-center space-x-1.5">
            <span className="text-[11px] font-semibold text-[#64748B] flex items-center space-x-1">
              <Filter className="w-3 h-3 text-[#94A3B8]" />
              <span>Status:</span>
            </span>
            <select
              value={statusFilter || "all"}
              onChange={(e) => onStatusChange(e.target.value)}
              className="bg-[#F8FAFC] border border-[#E2E8F0] rounded-lg px-2.5 py-1 text-xs text-[#0F172A] font-medium outline-none focus:border-[#6366F1] cursor-pointer"
            >
              {statusOptions.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </select>
          </div>
        )}

        {/* Entity Filter */}
        {entityOptions && onEntityChange && (
          <div className="flex items-center space-x-1.5">
            <span className="text-[11px] font-semibold text-[#64748B]">Entity:</span>
            <select
              value={entityFilter || "all"}
              onChange={(e) => onEntityChange(e.target.value)}
              className="bg-[#F8FAFC] border border-[#E2E8F0] rounded-lg px-2.5 py-1 text-xs text-[#0F172A] font-medium outline-none focus:border-[#6366F1] cursor-pointer"
            >
              {entityOptions.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </select>
          </div>
        )}

        {/* Refresh button */}
        {onRefresh && (
          <button
            onClick={onRefresh}
            disabled={isRefreshing}
            title="Refresh Data"
            className="p-1.5 text-[#94A3B8] hover:text-[#0F172A] rounded-lg hover:bg-[#F1F5F9] transition-colors cursor-pointer disabled:opacity-50"
          >
            <RefreshCw className={cn("w-3.5 h-3.5", isRefreshing && "animate-spin text-[#6366F1]")} />
          </button>
        )}
      </div>

      <div className="flex items-center space-x-3 justify-end">
        {count !== undefined && (
          <span className="text-[11px] font-medium text-[#64748B] font-tabular">
            {count} {countLabel}
          </span>
        )}
        {children}
      </div>
    </div>
  );
}
