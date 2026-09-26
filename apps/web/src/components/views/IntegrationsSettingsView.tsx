"use client";

import React, { useState } from "react";
import {
  Plug,
  CheckCircle2,
  AlertTriangle,
  RefreshCw,
  Search,
  ExternalLink,
  Key,
  ShieldCheck,
  Building,
  DollarSign,
  Landmark,
  FileText,
  Mail,
  SlidersHorizontal,
  ChevronRight,
  Plus,
  Trash2,
  Copy,
  Lock,
  Radio,
  FileCheck,
  Cpu,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { FilterBar } from "../ui/FilterBar";
import { SlideOverDrawer } from "../ui/SlideOverDrawer";
import { Badge } from "../ui/Badge";

export interface IntegrationItem {
  id: string;
  name: string;
  category: "banking" | "tax" | "payments" | "ocr" | "notifications";
  description: string;
  status: "connected" | "needs_config" | "disconnected";
  iconType: string;
  lastSync?: string;
  docsUrl?: string;
  credentials?: {
    clientId?: string;
    environment?: "sandbox" | "production";
    webhookUrl?: string;
  };
}

interface IntegrationsSettingsViewProps {
  currentOrgName?: string;
  onRefresh?: () => void;
  className?: string;
}

export function IntegrationsSettingsView({
  currentOrgName = "Indus Technologies Ltd.",
  onRefresh,
  className,
}: IntegrationsSettingsViewProps) {
  const [activeTab, setActiveTab] = useState<"catalog" | "keys" | "tenant">("catalog");
  const [selectedCategory, setSelectedCategory] = useState("all");
  const [searchQuery, setSearchQuery] = useState("");
  const [selectedIntegration, setSelectedIntegration] = useState<IntegrationItem | null>(null);

  // API Keys state
  const [apiKeys, setApiKeys] = useState([
    {
      id: "key-01",
      name: "Production Backend Ingestion Key",
      prefix: "fnv_live_79a2...",
      scope: "Full Access (Read/Write/Post)",
      created_at: "2025-07-01",
      last_used: "12 minutes ago",
    },
    {
      id: "key-02",
      name: "Stripe Webhook Verification Secret",
      prefix: "whsec_991b...",
      scope: "Webhooks Only",
      created_at: "2025-07-15",
      last_used: "2 hours ago",
    },
  ]);

  // Integrations Catalog
  const integrations: IntegrationItem[] = [
    {
      id: "int-fbr",
      name: "FBR PRAL Digital e-Invoicing",
      category: "tax",
      description: "Direct real-time fiscalization with Pakistan Federal Board of Revenue under SRO 1525(I)/2023.",
      status: "connected",
      iconType: "fbr",
      lastSync: "3 minutes ago",
      credentials: {
        clientId: "PRAL-PROD-INDUS-991204",
        environment: "production",
      },
    },
    {
      id: "int-hbl",
      name: "HBL Corporate Open Banking API",
      category: "banking",
      description: "Automated daily MT940 / CAMT.053 bank statement feeds and 1-click cash transaction matching.",
      status: "connected",
      iconType: "bank",
      lastSync: "Today at 09:00 AM",
      credentials: {
        clientId: "HBL-CORP-429912",
        environment: "production",
      },
    },
    {
      id: "int-meezan",
      name: "Meezan Bank Roshan Digital & Foreign Currency",
      category: "banking",
      description: "Automated USD treasury account sync, SBP interbank FX conversion tracking, and reconciliation.",
      status: "connected",
      iconType: "bank",
      lastSync: "Yesterday at 17:30",
    },
    {
      id: "int-stripe",
      name: "Stripe Global Subscriptions & Card Payments",
      category: "payments",
      description: "Automatic AR customer receipt generation, settlement fee split, and merchant payout reconciliation.",
      status: "connected",
      iconType: "stripe",
      lastSync: "Realtime Webhook",
    },
    {
      id: "int-ocr",
      name: "Axiom Intelligent Document OCR & Textract",
      category: "ocr",
      description: "Deep learning extraction of line items, vendor NTN, withholding tax, and PO references from PDF bills.",
      status: "connected",
      iconType: "ocr",
      lastSync: "Always Active",
    },
    {
      id: "int-slack",
      name: "Slack Finance Alerts & Approval Bot",
      category: "notifications",
      description: "Interactive approval buttons for CFO bill sign-offs and month-end close anomaly alerts.",
      status: "needs_config",
      iconType: "slack",
    },
    {
      id: "int-resend",
      name: "Resend / SMTP Transactional Invoicing",
      category: "notifications",
      description: "FBR QR-coded invoice PDFs dispatched directly to customer AP contacts with delivery tracking.",
      status: "connected",
      iconType: "mail",
    },
  ];

  const filteredIntegrations = integrations.filter((item) => {
    const matchesSearch =
      item.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
      item.description.toLowerCase().includes(searchQuery.toLowerCase());
    const matchesCat = selectedCategory === "all" || item.category === selectedCategory;
    return matchesSearch && matchesCat;
  });

  return (
    <div className={cn("max-w-[1320px] w-full mx-auto px-6 sm:px-10 py-6 space-y-6", className)}>
      {/* ─────────────────────────────────────────────────────────────
          TOP HEADER
      ─────────────────────────────────────────────────────────────── */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-[#F1F5F9] pb-4">
        <div>
          <div className="flex items-center space-x-2">
            <h2 className="text-xl font-bold tracking-tight text-[#0F172A]">
              Integrations, API Keys & Tenant Settings
            </h2>
            <span className="px-2 py-0.5 rounded text-[11px] font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200/60 font-mono">
              Phase 7
            </span>
          </div>
          <p className="text-xs text-[#64748B] mt-0.5">
            Manage live banking feeds, tax compliance APIs, payment gateways, and tenant security governance.
          </p>
        </div>

        <div className="flex items-center space-x-2">
          <button
            onClick={onRefresh}
            className="p-2 border border-[#E2E8F0] rounded-xl text-[#64748B] hover:text-[#0F172A] hover:bg-slate-50 transition-colors cursor-pointer"
            title="Refresh Integrations"
          >
            <RefreshCw className="w-4 h-4" />
          </button>
        </div>
      </div>

      {/* Sub-tab Switcher */}
      <div className="flex items-center space-x-1 bg-slate-100/80 p-1 rounded-xl border border-slate-200/60">
        {[
          { id: "catalog", label: "Connected Integrations Catalog", icon: Plug },
          { id: "keys", label: "Developer API Keys & Webhooks", icon: Key },
          { id: "tenant", label: "Organization & Accounting Policies", icon: Building },
        ].map((tab) => {
          const Icon = tab.icon;
          const isActive = activeTab === tab.id;
          return (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id as any)}
              className={cn(
                "flex items-center space-x-2 px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer",
                isActive
                  ? "bg-white text-[#0F172A] shadow-xs"
                  : "text-[#64748B] hover:text-[#0F172A]"
              )}
            >
              <Icon className="w-3.5 h-3.5" />
              <span>{tab.label}</span>
            </button>
          );
        })}
      </div>

      {/* ─────────────────────────────────────────────────────────────
          TAB 1: INTEGRATIONS CATALOG
      ─────────────────────────────────────────────────────────────── */}
      {activeTab === "catalog" && (
        <div className="space-y-4">
          {/* Filter Bar */}
          <FilterBar
            searchQuery={searchQuery}
            onSearchChange={setSearchQuery}
            searchPlaceholder="Search integrations by service or protocol..."
            statusFilter={selectedCategory}
            onStatusChange={setSelectedCategory}
            statusOptions={[
              { label: "All Integrations", value: "all" },
              { label: "Banking & Treasury", value: "banking" },
              { label: "Tax & Compliance", value: "tax" },
              { label: "Payment Processors", value: "payments" },
              { label: "OCR & Machine Vision", value: "ocr" },
              { label: "Alerts & Notifications", value: "notifications" },
            ]}
          />

          {/* Cards Grid */}
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {filteredIntegrations.map((item) => (
              <div
                key={item.id}
                onClick={() => setSelectedIntegration(item)}
                className="p-5 bg-white rounded-2xl border border-[#E2E8F0] shadow-xs hover:border-indigo-300 transition-all cursor-pointer flex flex-col justify-between space-y-4 group"
              >
                <div className="space-y-3">
                  <div className="flex items-center justify-between">
                    <div className="w-10 h-10 rounded-xl bg-slate-50 border border-slate-200 flex items-center justify-center text-[#0F172A]">
                      {item.category === "banking" && <Landmark className="w-5 h-5 text-indigo-600" />}
                      {item.category === "tax" && <ShieldCheck className="w-5 h-5 text-emerald-600" />}
                      {item.category === "payments" && <DollarSign className="w-5 h-5 text-purple-600" />}
                      {item.category === "ocr" && <Cpu className="w-5 h-5 text-amber-600" />}
                      {item.category === "notifications" && <Mail className="w-5 h-5 text-blue-600" />}
                    </div>

                    <Badge
                      variant={
                        item.status === "connected"
                          ? "success"
                          : item.status === "needs_config"
                          ? "warning"
                          : "neutral"
                      }
                      className="text-[10px]"
                    >
                      {item.status === "connected"
                        ? "Active & Synced"
                        : item.status === "needs_config"
                        ? "Action Needed"
                        : "Disconnected"}
                    </Badge>
                  </div>

                  <div>
                    <h3 className="text-sm font-bold text-[#0F172A] group-hover:text-indigo-600 transition-colors">
                      {item.name}
                    </h3>
                    <p className="text-xs text-[#64748B] mt-1 leading-relaxed">{item.description}</p>
                  </div>
                </div>

                <div className="pt-3 border-t border-[#F1F5F9] flex items-center justify-between text-[11px] text-[#64748B]">
                  <span>Last Sync: {item.lastSync || "Not configured"}</span>
                  <span className="font-semibold text-indigo-600 flex items-center group-hover:translate-x-0.5 transition-transform">
                    Configure <ChevronRight className="w-3.5 h-3.5 ml-0.5" />
                  </span>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          TAB 2: DEVELOPER API KEYS & WEBHOOKS
      ─────────────────────────────────────────────────────────────── */}
      {activeTab === "keys" && (
        <div className="space-y-6">
          <div className="bg-white rounded-2xl border border-[#E2E8F0] shadow-xs p-6 space-y-5">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-[#F1F5F9] pb-4">
              <div>
                <h3 className="text-sm font-bold text-[#0F172A]">Developer API Keys</h3>
                <p className="text-xs text-[#64748B]">
                  Cryptographically signed bearer tokens for server-to-server accounting automation.
                </p>
              </div>
              <button
                onClick={() => alert("Creating a new API Key with scoped double-entry permissions...")}
                className="px-3.5 py-1.5 bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold rounded-xl flex items-center space-x-1.5 shadow-xs transition-colors cursor-pointer"
              >
                <Plus className="w-3.5 h-3.5" />
                <span>Create New API Key</span>
              </button>
            </div>

            <div className="space-y-3">
              {apiKeys.map((k) => (
                <div
                  key={k.id}
                  className="p-4 bg-slate-50/70 rounded-xl border border-slate-200/80 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs"
                >
                  <div className="space-y-1">
                    <div className="flex items-center space-x-2">
                      <span className="font-bold text-[#0F172A]">{k.name}</span>
                      <Badge variant="outline" className="text-[10px] font-mono">
                        {k.scope}
                      </Badge>
                    </div>
                    <p className="font-mono text-[11px] text-[#64748B]">Token: {k.prefix}</p>
                  </div>

                  <div className="flex items-center space-x-3 text-[11px] text-[#64748B]">
                    <span>Last used: {k.last_used}</span>
                    <button
                      onClick={() => alert(`Copied token ${k.prefix} to clipboard.`)}
                      className="p-1.5 hover:bg-slate-200 rounded-lg transition-colors cursor-pointer"
                      title="Copy Key"
                    >
                      <Copy className="w-3.5 h-3.5 text-[#64748B]" />
                    </button>
                    <button
                      onClick={() => alert(`Revoked key ${k.name}.`)}
                      className="p-1.5 hover:bg-rose-50 text-rose-600 rounded-lg transition-colors cursor-pointer"
                      title="Revoke Key"
                    >
                      <Trash2 className="w-3.5 h-3.5" />
                    </button>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          TAB 3: TENANT & ACCOUNTING POLICIES
      ─────────────────────────────────────────────────────────────── */}
      {activeTab === "tenant" && (
        <div className="space-y-6">
          <div className="bg-white rounded-2xl border border-[#E2E8F0] shadow-xs p-6 space-y-6">
            <div className="border-b border-[#F1F5F9] pb-4">
              <h3 className="text-sm font-bold text-[#0F172A]">Organization & Financial Governance Policies</h3>
              <p className="text-xs text-[#64748B]">
                Core accounting invariants, functional currency, and approval thresholds.
              </p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6 text-xs text-[#334155]">
              <div className="p-4 bg-slate-50 rounded-xl border border-[#E2E8F0] space-y-3">
                <h4 className="font-bold text-[#0F172A]">Primary Functional Currency</h4>
                <p className="text-[11px] text-[#64748B]">
                  Base currency for general ledger balance sheet and consolidated reporting.
                </p>
                <div className="p-2.5 bg-white rounded-lg border font-mono font-bold text-sm text-indigo-700">
                  PKR — Pakistani Rupee
                </div>
              </div>

              <div className="p-4 bg-slate-50 rounded-xl border border-[#E2E8F0] space-y-3">
                <h4 className="font-bold text-[#0F172A]">Fiscal Year End</h4>
                <p className="text-[11px] text-[#64748B]">
                  Statutory year end according to Pakistan Companies Act and FBR requirements.
                </p>
                <div className="p-2.5 bg-white rounded-lg border font-medium text-sm text-[#0F172A]">
                  June 30 (Fiscal Year July 1 - June 30)
                </div>
              </div>

              <div className="p-4 bg-slate-50 rounded-xl border border-[#E2E8F0] space-y-3">
                <h4 className="font-bold text-[#0F172A]">Dual-Authorization (Two-Person Rule) Gate</h4>
                <p className="text-[11px] text-[#64748B]">
                  Disbursements and bills exceeding this threshold require mandatory Tier 3 CFO sign-off.
                </p>
                <div className="p-2.5 bg-white rounded-lg border font-mono font-bold text-sm text-[#0F172A]">
                  PKR 500,000
                </div>
              </div>

              <div className="p-4 bg-slate-50 rounded-xl border border-[#E2E8F0] space-y-3">
                <h4 className="font-bold text-[#0F172A]">Strict Double-Entry Invariant Enforcement</h4>
                <p className="text-[11px] text-[#64748B]">
                  Prevents unbalanced journal mutations at the database transaction layer.
                </p>
                <div className="flex items-center space-x-2 text-emerald-700 font-bold">
                  <CheckCircle2 className="w-4 h-4 text-emerald-600" />
                  <span>Always Enforced (Zero Exceptions)</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          SLIDEOVER DRAWER: CONFIGURE INTEGRATION
      ─────────────────────────────────────────────────────────────── */}
      <SlideOverDrawer
        isOpen={Boolean(selectedIntegration)}
        onClose={() => setSelectedIntegration(null)}
        title={selectedIntegration?.name || "Configure Integration"}
        subtitle={`Category: ${selectedIntegration?.category?.toUpperCase()} • Status: ${selectedIntegration?.status}`}
        badge={
          <Badge variant={selectedIntegration?.status === "connected" ? "success" : "neutral"}>
            {selectedIntegration?.status === "connected" ? "Connected" : "Setup Required"}
          </Badge>
        }
      >
        {selectedIntegration && (
          <div className="space-y-6 text-xs text-[#334155]">
            <p className="text-xs text-[#64748B] leading-relaxed">{selectedIntegration.description}</p>

            <div className="p-4 rounded-xl bg-slate-50 border border-[#E2E8F0] space-y-3">
              <h4 className="font-bold text-[#0F172A] text-xs">Environment & Endpoint</h4>
              <div className="flex items-center space-x-3">
                <label className="flex items-center space-x-1.5 cursor-pointer">
                  <input type="radio" name="env" defaultChecked={selectedIntegration.credentials?.environment === "production"} />
                  <span>Production Live</span>
                </label>
                <label className="flex items-center space-x-1.5 cursor-pointer">
                  <input type="radio" name="env" defaultChecked={selectedIntegration.credentials?.environment === "sandbox"} />
                  <span>Sandbox Testing</span>
                </label>
              </div>
            </div>

            <div className="space-y-3">
              <div>
                <label className="block text-[11px] font-semibold text-[#64748B] mb-1">
                  Client ID / Merchant Key
                </label>
                <input
                  type="text"
                  defaultValue={selectedIntegration.credentials?.clientId || "PRAL-SANDBOX-AUTH-KEY-88192"}
                  className="w-full bg-slate-50 border border-[#E2E8F0] rounded-xl px-3 py-2 text-xs font-mono outline-none focus:border-indigo-600"
                />
              </div>

              <div>
                <label className="block text-[11px] font-semibold text-[#64748B] mb-1">
                  API Secret Token / Digital Signature RSA Private Key
                </label>
                <input
                  type="password"
                  defaultValue="••••••••••••••••••••••••••••••••"
                  className="w-full bg-slate-50 border border-[#E2E8F0] rounded-xl px-3 py-2 text-xs font-mono outline-none focus:border-indigo-600"
                />
              </div>
            </div>

            <div className="pt-4 border-t border-[#F1F5F9] flex items-center justify-between">
              <button
                onClick={() => alert(`Connection test successful! Ping returned 200 OK (latency: 48ms).`)}
                className="px-3.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-[#0F172A] text-xs font-semibold rounded-xl transition-colors cursor-pointer"
              >
                Test Connection Ping
              </button>

              <button
                onClick={() => {
                  alert(`Integration credentials updated and verified successfully.`);
                  setSelectedIntegration(null);
                }}
                className="px-4 py-1.5 bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold rounded-xl shadow-xs transition-colors cursor-pointer"
              >
                Save Settings
              </button>
            </div>
          </div>
        )}
      </SlideOverDrawer>
    </div>
  );
}
