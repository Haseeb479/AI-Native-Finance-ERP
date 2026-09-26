import React from "react";
import { cn } from "@/lib/utils";
import { FileText } from "lucide-react";

export interface Column<T> {
  key: string;
  header: string;
  align?: "left" | "center" | "right";
  width?: string;
  render?: (item: T, index: number) => React.ReactNode;
}

interface DataTableProps<T> {
  columns: Column<T>[];
  data: T[];
  isLoading?: boolean;
  emptyMessage?: string;
  emptySubtext?: string;
  onRowClick?: (item: T) => void;
  rowKey?: (item: T) => string;
  className?: string;
}

export function DataTable<T extends Record<string, any>>({
  columns,
  data,
  isLoading = false,
  emptyMessage = "No records found",
  emptySubtext = "Create a new record or adjust filters to view data.",
  onRowClick,
  rowKey = (item) => item.id || Math.random().toString(),
  className,
}: DataTableProps<T>) {
  return (
    <div
      className={cn(
        "bg-white border border-[#E2E8F0] rounded-xl overflow-hidden shadow-[0_1px_3px_rgba(0,0,0,0.02)]",
        className
      )}
    >
      <div className="overflow-x-auto">
        <table className="w-full text-left border-collapse">
          <thead>
            <tr className="border-b border-[#E2E8F0] bg-[#F8FAFC]">
              {columns.map((col) => (
                <th
                  key={col.key}
                  style={col.width ? { width: col.width } : undefined}
                  className={cn(
                    "px-4 py-3 text-[11px] font-semibold text-[#475569] uppercase tracking-wider",
                    col.align === "right" && "text-right",
                    col.align === "center" && "text-center"
                  )}
                >
                  {col.header}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-[#F1F5F9] text-xs text-[#0F172A]">
            {isLoading ? (
              // Table skeletons for clean loading state
              Array.from({ length: 5 }).map((_, idx) => (
                <tr key={idx} className="animate-pulse">
                  {columns.map((col, cIdx) => (
                    <td key={cIdx} className="px-4 py-3.5">
                      <div
                        className={cn(
                          "h-3.5 bg-slate-200/70 rounded",
                          cIdx === 0 ? "w-28" : cIdx === columns.length - 1 ? "w-16" : "w-20"
                        )}
                      />
                    </td>
                  ))}
                </tr>
              ))
            ) : data.length === 0 ? (
              <tr>
                <td colSpan={columns.length} className="px-6 py-14 text-center">
                  <div className="flex flex-col items-center justify-center space-y-2">
                    <div className="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center text-slate-400">
                      <FileText className="w-5 h-5 stroke-[1.5]" />
                    </div>
                    <span className="font-semibold text-sm text-[#0F172A]">{emptyMessage}</span>
                    <p className="text-xs text-[#64748B] max-w-sm">{emptySubtext}</p>
                  </div>
                </td>
              </tr>
            ) : (
              data.map((item, idx) => (
                <tr
                  key={rowKey(item)}
                  onClick={() => onRowClick && onRowClick(item)}
                  className={cn(
                    "transition-colors",
                    onRowClick ? "hover:bg-[#F8FAFC] cursor-pointer" : "hover:bg-[#FAFAFA]"
                  )}
                >
                  {columns.map((col) => (
                    <td
                      key={col.key}
                      className={cn(
                        "px-4 py-3",
                        col.align === "right" && "text-right font-tabular",
                        col.align === "center" && "text-center"
                      )}
                    >
                      {col.render ? col.render(item, idx) : item[col.key] ?? "—"}
                    </td>
                  ))}
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
