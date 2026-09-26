"use client";

import React from "react";
import Link from "next/link";
import {
  Home,
  Sparkles,
  Search,
  FileText,
  FilePenLine,
  Landmark,
  TrendingUp,
  BookOpen,
  CheckCircle2,
  BarChart3,
  Boxes,
  History,
  Bell,
  LogIn,
  LogOut,
  ChevronRight,
  ShieldCheck,
} from "lucide-react";
import { cn } from "@/lib/utils";

interface SidebarNavigationProps {
  activeNav: string;
  onSelectNav: (id: string) => void;
  currentUser?: { name?: string; email?: string } | null;
  currentOrg?: { name?: string } | null;
  onOpenProfile: () => void;
  onLogout: () => void;
  className?: string;
}

export function SidebarNavigation({
  activeNav,
  onSelectNav,
  currentUser,
  currentOrg,
  onOpenProfile,
  onLogout,
  className,
}: SidebarNavigationProps) {
  const navItems = [
    { id: "search", icon: Search, label: "Search Spotlight (Ctrl+K)" },
    { id: "launchpad", icon: Home, label: "Executive Launchpad" },
    { id: "ai_command", icon: Sparkles, label: "Axiom AI Command Center" },
    { id: "invoices", icon: FileText, label: "Accounts Receivable (Invoices)" },
    { id: "bills", icon: FilePenLine, label: "Accounts Payable (Bills & 3-Way Match)" },
    { id: "approvals", icon: ShieldCheck, label: "Workflow & Multi-Layer Approvals" },
    { id: "banking", icon: Landmark, label: "Banking & Cash Reconciliation" },
    { id: "revenue", icon: TrendingUp, label: "Revenue Recognition (ASC 606)" },
    { id: "ledger", icon: BookOpen, label: "General Ledger & COA" },
    { id: "close", icon: CheckCircle2, label: "Month-End Close Checklist" },
    { id: "reports", icon: BarChart3, label: "Financial Reports & Statements" },
    { id: "features", icon: Boxes, label: "ERP Module Directory" },
  ];

  const isItemActive = (id: string) => {
    if (id === "launchpad") return activeNav === "launchpad" || activeNav === "home";
    if (id === "ai_command") return activeNav === "ai_command" || activeNav === "command" || activeNav === "copilot" || activeNav === "ai_assistant" || activeNav === "ai_agents" || activeNav === "ai_flows";
    if (id === "invoices") return activeNav === "invoices" || activeNav === "customers" || activeNav === "contracts" || activeNav === "ar_aging";
    if (id === "bills") return activeNav === "bills" || activeNav === "vendors" || activeNav === "prepaids" || activeNav === "accruals";
    if (id === "approvals") return activeNav === "approvals" || activeNav === "workflow" || activeNav === "exceptions" || activeNav === "close_approvals";
    if (id === "banking") return activeNav === "banking" || activeNav === "bank_accounts" || activeNav === "transactions" || activeNav === "reconciliation" || activeNav === "matching_rules";
    if (id === "ledger") return activeNav === "ledger" || activeNav === "journals" || activeNav === "accounts" || activeNav === "periods";
    if (id === "close") return activeNav === "close" || activeNav === "close_checklist" || activeNav === "close_recons" || activeNav === "close_flux" || activeNav === "close_approvals";
    if (id === "reports") return activeNav.startsWith("reports") || activeNav === "entities" || activeNav === "consolidation";
    return activeNav === id;
  };

  return (
    <aside
      className={cn(
        "w-[68px] bg-white border-r border-[#F1F5F9] flex flex-col items-center justify-between py-4 shrink-0 z-30 select-none shadow-[1px_0_4px_rgba(0,0,0,0.02)]",
        className
      )}
    >
      {/* Top Brand Logo & Primary Navigation Rail */}
      <div className="flex flex-col items-center space-y-6 w-full">
        {/* Brand Logo (Fi) */}
        <Link
          href="/"
          title="Finova ERP - Home"
          className="w-10 h-10 rounded-[12px] bg-[#6366F1] text-white flex items-center justify-center font-bold text-base shadow-sm tracking-tight hover:opacity-90 transition-opacity cursor-pointer"
        >
          Fi
        </Link>

        {/* Navigation Icon List */}
        <nav className="flex flex-col items-center space-y-2 w-full px-2">
          {navItems.map((item) => {
            const Icon = item.icon;
            const active = isItemActive(item.id);

            return (
              <div key={item.id} className="relative group flex items-center justify-center w-full">
                <button
                  onClick={() => onSelectNav(item.id)}
                  aria-label={item.label}
                  className={cn(
                    "w-10 h-10 rounded-xl flex items-center justify-center transition-all duration-150 relative cursor-pointer",
                    active
                      ? "text-[#0F172A] bg-[#F1F5F9] font-semibold shadow-xs"
                      : "text-[#94A3B8] hover:text-[#0F172A] hover:bg-[#F8FAFC]"
                  )}
                >
                  <Icon className="w-[19px] h-[19px] stroke-[1.75]" />
                  {active && (
                    <span className="absolute -left-2 w-[3px] h-5 bg-[#6366F1] rounded-r-full" />
                  )}
                </button>

                {/* Clean Tooltip Hover */}
                <div className="absolute left-[58px] px-2.5 py-1 bg-[#0F172A] text-white text-[11px] font-medium rounded-md whitespace-nowrap opacity-0 pointer-events-none group-hover:opacity-100 transition-opacity shadow-lg z-50">
                  {item.label}
                </div>
              </div>
            );
          })}
        </nav>
      </div>

      {/* Bottom Utility Icons & Profile Orb */}
      <div className="flex flex-col items-center space-y-4 w-full px-2">
        {/* Audit Trail Shortcut */}
        <div className="relative group flex items-center justify-center w-full">
          <button
            onClick={() => onSelectNav("audit")}
            title="Immutable Audit Trail (SHA-256)"
            className={cn(
              "w-10 h-10 rounded-xl flex items-center justify-center text-[#94A3B8] hover:text-[#0F172A] hover:bg-[#F8FAFC] transition-colors cursor-pointer",
              (activeNav === "audit" || activeNav === "security" || activeNav === "audit_logs") && "text-[#0F172A] bg-[#F1F5F9]"
            )}
          >
            <History className="w-[18px] h-[18px] stroke-[1.75]" />
          </button>
          <div className="absolute left-[58px] px-2.5 py-1 bg-[#0F172A] text-white text-[11px] font-medium rounded-md whitespace-nowrap opacity-0 pointer-events-none group-hover:opacity-100 transition-opacity shadow-lg z-50">
            Immutable Audit Trail
          </div>
        </div>

        {/* User Profile Orb */}
        <div className="relative group flex items-center justify-center w-full pt-1">
          <button
            onClick={onOpenProfile}
            title={currentUser ? `${currentUser.name || "User"} (${currentOrg?.name || "Apex"})` : "Click to Sign In"}
            className="w-9 h-9 rounded-full bg-gradient-to-tr from-[#8B5CF6] to-[#6366F1] text-white flex items-center justify-center text-xs font-bold ring-2 ring-white shadow-xs cursor-pointer hover:scale-105 transition-all"
          >
            {currentUser?.name ? currentUser.name.charAt(0).toUpperCase() : "S"}
          </button>
          <div className="absolute left-[58px] px-2.5 py-1 bg-[#0F172A] text-white text-[11px] font-medium rounded-md whitespace-nowrap opacity-0 pointer-events-none group-hover:opacity-100 transition-opacity shadow-lg z-50">
            {currentUser?.name || "User Profile & Session"}
          </div>
        </div>
      </div>
    </aside>
  );
}
