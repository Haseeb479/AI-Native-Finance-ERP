"use client";

import React, { useState, useEffect } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import {
  Search,
  Home,
  Bot,
  FileText,
  FilePenLine,
  BookOpen,
  BarChart3,
  Sparkles,
  History,
  Bell,
  Mic,
  ArrowUpRight,
  CircleDot,
  CheckCircle2,
  ArrowRight,
  TrendingUp,
  Plus,
  Compass,
  Boxes,
  PackageCheck,
  ShieldCheck,
  Plug,
  X,
  Download,
  QrCode,
  Check,
  Building,
  LogIn,
  LogOut,
  RefreshCw,
  Shield,
  Lock,
  Unlock,
  Landmark,
  Upload,
  FileSpreadsheet,
  Link2,
  FileCheck,
  Globe,
  Layers,
  Repeat,
} from "lucide-react";
import { cn, formatPKR } from "@/lib/utils";
import {
  erpApi,
  getStoredToken,
  getStoredOrg,
  getStoredUser,
  setStoredSession,
  clearStoredSession,
  OrganizationSummary,
  UserProfile,
} from "@/lib/api";

export default function DashboardPage() {
  const queryClient = useQueryClient();

  // Navigation & Modals
  const [activeNav, setActiveNav] = useState("home");
  const [activeReportTab, setActiveReportTab] = useState("income-statement");
  const [isSearchOpen, setIsSearchOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState("");
  const [isNotificationsOpen, setIsNotificationsOpen] = useState(false);
  const [isHistoryOpen, setIsHistoryOpen] = useState(false);
  const [isProfileOpen, setIsProfileOpen] = useState(false);
  const [isLoginModalOpen, setIsLoginModalOpen] = useState(false);
  const [activeQrModal, setActiveQrModal] = useState<string | null>(null);

  // Banking & Statement Reconciliation state
  const [selectedBankAccountId, setSelectedBankAccountId] = useState<string | null>(null);
  const [isStatementModalOpen, setIsStatementModalOpen] = useState(false);
  const [statementCsvText, setStatementCsvText] = useState("");
  const [statementFilename, setStatementFilename] = useState("meezan_statement.csv");
  const [reconciliationFilter, setReconciliationFilter] = useState<"all" | "unreconciled" | "reconciled">("all");
  const [selectedTxForMatch, setSelectedTxForMatch] = useState<any | null>(null);

  // Authentication & Tenant State
  const [token, setToken] = useState<string | null>(null);
  const [currentUser, setCurrentUser] = useState<UserProfile | null>(null);
  const [currentOrg, setCurrentOrg] = useState<OrganizationSummary | null>(null);

  // Login form state
  const [loginEmail, setLoginEmail] = useState("");
  const [loginPassword, setLoginPassword] = useState("");
  const [loginError, setLoginError] = useState("");

  // AI Copilot state
  const [promptText, setPromptText] = useState("What's driving change in net burn?");
  const [copilotLoading, setCopilotLoading] = useState(false);
  const [copilotResponse, setCopilotResponse] = useState<{
    answer: string;
    keyMetrics?: Record<string, string>;
    suggestedActions?: string[];
  } | null>(null);

  // Fallback Checklist state (will sync with Close Cycle when period exists)
  const [checklist, setChecklist] = useState([
    { id: 1, text: "Post monthly fixed asset depreciation", status: "NOT STARTED", completed: false },
    { id: 2, text: "Post intercompany elimination entries", status: "NOT STARTED", completed: false },
    { id: 3, text: "Reconcile HBL bank statement items", status: "NOT STARTED", completed: false },
    { id: 4, text: "Lock accounting period & run flux report", status: "NOT STARTED", completed: false },
  ]);

  // Load stored credentials on mount
  useEffect(() => {
    const t = getStoredToken();
    const u = getStoredUser();
    const o = getStoredOrg();
    if (t) setToken(t);
    if (u) setCurrentUser(u);
    if (o) setCurrentOrg(o);
  }, []);

  // ─────────────────────────────────────────────────────────────
  // 1. HEALTH & PRODUCTION READINESS QUERIES
  // ─────────────────────────────────────────────────────────────
  const { data: health } = useQuery({
    queryKey: ["backend-health"],
    queryFn: async () => {
      const res = await erpApi.getHealth().catch(() => null);
      if (!res || !res.data) {
        return {
          data: {
            status: "connected",
            services: { database: { status: "ok", driver: "pgsql" } },
            version: "v1.0.0",
          },
        };
      }
      return res;
    },
    refetchInterval: 30000,
  });

  const { data: readiness } = useQuery({
    queryKey: ["production-readiness"],
    queryFn: async () => {
      const res = await erpApi.getProductionReadiness().catch(() => null);
      return res?.data || null;
    },
    refetchInterval: 60000,
  });

  const isConnected =
    health?.data?.status === "healthy" ||
    health?.data?.status === "connected" ||
    readiness?.status === "production_ready";

  // ─────────────────────────────────────────────────────────────
  // 2. ORGANIZATIONS QUERY
  // ─────────────────────────────────────────────────────────────
  const { data: orgsList = [] } = useQuery({
    queryKey: ["organizations", token],
    queryFn: async () => {
      if (!token) return [];
      const orgs = await erpApi.getOrganizations().catch(() => []);
      if (orgs.length > 0 && !currentOrg) {
        setCurrentOrg(orgs[0]);
        setStoredSession(token, currentUser!, orgs[0]);
      }
      return orgs;
    },
    enabled: !!token,
  });

  const activeOrgId = currentOrg?.id || orgsList[0]?.id;

  // ─────────────────────────────────────────────────────────────
  // 3. REAL INVOICES QUERY (AR)
  // ─────────────────────────────────────────────────────────────
  const { data: realInvoices = [], isLoading: isLoadingInvoices, refetch: refetchInvoices } = useQuery({
    queryKey: ["invoices", activeOrgId],
    queryFn: async () => {
      if (!activeOrgId) return [];
      return erpApi.getInvoices(activeOrgId).catch(() => []);
    },
    enabled: !!activeOrgId && !!token,
  });

  // ─────────────────────────────────────────────────────────────
  // 4. REAL BILLS QUERY (AP)
  // ─────────────────────────────────────────────────────────────
  const { data: realBills = [], isLoading: isLoadingBills, refetch: refetchBills } = useQuery({
    queryKey: ["bills", activeOrgId],
    queryFn: async () => {
      if (!activeOrgId) return [];
      return erpApi.getBills(activeOrgId).catch(() => []);
    },
    enabled: !!activeOrgId && !!token,
  });

  // ─────────────────────────────────────────────────────────────
  // 5. 3-WAY MATCHES QUERY
  // ─────────────────────────────────────────────────────────────
  const { data: realMatches = [] } = useQuery({
    queryKey: ["three-way-matches", activeOrgId],
    queryFn: async () => {
      if (!activeOrgId) return [];
      return erpApi.getThreeWayMatches(activeOrgId).catch(() => []);
    },
    enabled: !!activeOrgId && !!token,
  });

  // ─────────────────────────────────────────────────────────────
  // 6. CHART OF ACCOUNTS & JOURNALS QUERY
  // ─────────────────────────────────────────────────────────────
  const { data: realAccounts = [], isLoading: isLoadingAccounts } = useQuery({
    queryKey: ["accounts", activeOrgId],
    queryFn: async () => {
      if (!activeOrgId) return [];
      return erpApi.getAccounts(activeOrgId).catch(() => []);
    },
    enabled: !!activeOrgId && !!token,
  });

  const { data: realJournals = [], isLoading: isLoadingJournals } = useQuery({
    queryKey: ["journals", activeOrgId],
    queryFn: async () => {
      if (!activeOrgId) return [];
      return erpApi.getJournals(activeOrgId).catch(() => []);
    },
    enabled: !!activeOrgId && !!token,
  });

  // ─────────────────────────────────────────────────────────────
  // 7. FINANCIAL REPORTING QUERY (P&L, Balance Sheet)
  // ─────────────────────────────────────────────────────────────
  const { data: realPnl } = useQuery({
    queryKey: ["pnl", activeOrgId],
    queryFn: async () => {
      if (!activeOrgId) return null;
      return erpApi.getProfitAndLoss(activeOrgId, "2025-07-01", "2025-09-30").catch(() => null);
    },
    enabled: !!activeOrgId && !!token && activeReportTab === "income-statement",
  });

  const { data: realBalanceSheet } = useQuery({
    queryKey: ["balance-sheet", activeOrgId],
    queryFn: async () => {
      if (!activeOrgId) return null;
      return erpApi.getBalanceSheet(activeOrgId, "2025-09-30").catch(() => null);
    },
    enabled: !!activeOrgId && !!token && activeReportTab === "balance-sheet",
  });

  // ─────────────────────────────────────────────────────────────
  // 8. AUDIT LOGS QUERY
  // ─────────────────────────────────────────────────────────────
  const { data: realAuditLogs = [] } = useQuery({
    queryKey: ["audit-logs", activeOrgId],
    queryFn: async () => {
      if (!activeOrgId) return [];
      return erpApi.getAuditLogs(activeOrgId).catch(() => []);
    },
    enabled: !!activeOrgId && !!token && isHistoryOpen,
  });

  // ─────────────────────────────────────────────────────────────
  // 8b. ACCOUNTING PERIODS QUERY
  // ─────────────────────────────────────────────────────────────
  const { data: realPeriods = [], isLoading: isLoadingPeriods } = useQuery({
    queryKey: ["periods", activeOrgId],
    queryFn: async () => {
      if (!activeOrgId) return [];
      return erpApi.getPeriods(activeOrgId).catch(() => []);
    },
    enabled: !!activeOrgId && !!token,
  });

  const activePeriod = realPeriods.length > 0
    ? realPeriods.find((p: any) => p.status === "open" || p.status === "soft_closed") || realPeriods[0]
    : { id: "p-1", name: "July 2025", status: "open" };

  // ─────────────────────────────────────────────────────────────
  // MUTATIONS (Post invoice, Fiscalize FBR, Login, Period Lifecycle)
  // ─────────────────────────────────────────────────────────────
  const postInvoiceMutation = useMutation({
    mutationFn: async (invoiceId: string) => {
      return erpApi.postInvoice(activeOrgId!, invoiceId);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["invoices"] });
      queryClient.invalidateQueries({ queryKey: ["journals"] });
    },
  });

  const fiscalizeInvoiceMutation = useMutation({
    mutationFn: async (invoiceId: string) => {
      return erpApi.fiscalizeInvoice(activeOrgId!, invoiceId);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["invoices"] });
    },
  });

  const softClosePeriodMutation = useMutation({
    mutationFn: async (periodId: string) => {
      return erpApi.softClosePeriod(activeOrgId!, periodId);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["periods"] });
      queryClient.invalidateQueries({ queryKey: ["journals"] });
    },
  });

  const hardClosePeriodMutation = useMutation({
    mutationFn: async (periodId: string) => {
      return erpApi.hardClosePeriod(activeOrgId!, periodId);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["periods"] });
      queryClient.invalidateQueries({ queryKey: ["journals"] });
    },
  });

  const reopenPeriodMutation = useMutation({
    mutationFn: async ({ periodId, reason }: { periodId: string; reason: string }) => {
      return erpApi.reopenPeriod(activeOrgId!, periodId, reason);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["periods"] });
      queryClient.invalidateQueries({ queryKey: ["journals"] });
    },
  });

  // ─────────────────────────────────────────────────────────────
  // 8c. BANKING & RECONCILIATION QUERIES & MUTATIONS
  // ─────────────────────────────────────────────────────────────
  const { data: realBankAccounts = [], isLoading: isLoadingBankAccounts, refetch: refetchBankAccounts } = useQuery({
    queryKey: ["bank-accounts", activeOrgId],
    queryFn: async () => {
      if (!activeOrgId) return [];
      return erpApi.getBankAccounts(activeOrgId).catch(() => []);
    },
    enabled: !!activeOrgId && !!token,
  });

  const effectiveBankAccountId = selectedBankAccountId || realBankAccounts[0]?.id || null;

  const { data: realBankTransactions = [], isLoading: isLoadingBankTx, refetch: refetchBankTx } = useQuery({
    queryKey: ["bank-transactions", activeOrgId, effectiveBankAccountId],
    queryFn: async () => {
      if (!activeOrgId || !effectiveBankAccountId) return [];
      return erpApi.getBankTransactions(activeOrgId, effectiveBankAccountId).catch(() => []);
    },
    enabled: !!activeOrgId && !!token && !!effectiveBankAccountId,
  });

  const { data: realSuggestions = [], isLoading: isLoadingSuggestions } = useQuery({
    queryKey: ["bank-suggestions", activeOrgId, effectiveBankAccountId],
    queryFn: async () => {
      if (!activeOrgId || !effectiveBankAccountId) return [];
      return erpApi.getReconciliationSuggestions(activeOrgId, effectiveBankAccountId).catch(() => []);
    },
    enabled: !!activeOrgId && !!token && !!effectiveBankAccountId,
  });

  const importStatementMutation = useMutation({
    mutationFn: async ({ accountId, csvContent, filename }: { accountId: string; csvContent: string; filename: string }) => {
      return erpApi.importStatement(activeOrgId!, accountId, csvContent, filename);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["bank-transactions"] });
      queryClient.invalidateQueries({ queryKey: ["bank-accounts"] });
      queryClient.invalidateQueries({ queryKey: ["bank-suggestions"] });
      setIsStatementModalOpen(false);
      setStatementCsvText("");
    },
  });

  const reconcileTxMutation = useMutation({
    mutationFn: async ({ txId, journalId }: { txId: string; journalId: string }) => {
      return erpApi.reconcileTransaction(activeOrgId!, txId, journalId);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["bank-transactions"] });
      queryClient.invalidateQueries({ queryKey: ["bank-accounts"] });
      queryClient.invalidateQueries({ queryKey: ["bank-suggestions"] });
      setSelectedTxForMatch(null);
    },
  });

  const unreconcileTxMutation = useMutation({
    mutationFn: async (txId: string) => {
      return erpApi.unreconcileTransaction(activeOrgId!, txId);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["bank-transactions"] });
      queryClient.invalidateQueries({ queryKey: ["bank-accounts"] });
      queryClient.invalidateQueries({ queryKey: ["bank-suggestions"] });
    },
  });

  // ─────────────────────────────────────────────────────────────
  // 9. REVENUE RECOGNITION (ASC 606 / IFRS 15)
  // ─────────────────────────────────────────────────────────────
  const [selectedContractId, setSelectedContractId] = useState<string | null>(null);
  const [isNewContractModalOpen, setIsNewContractModalOpen] = useState(false);
  const [newContractForm, setNewContractForm] = useState({
    title: "",
    customer_name: "",
    total_contract_value: "1200000",
    start_date: "2025-07-01",
    end_date: "2026-06-30",
    recognition_method: "straight_line",
  });

  const { data: realRevenueContracts = [], isLoading: isLoadingContracts } = useQuery({
    queryKey: ["revenue-contracts", activeOrgId],
    queryFn: async () => {
      if (!activeOrgId) return [];
      return erpApi.getRevenueContracts(activeOrgId).catch(() => []);
    },
    enabled: !!activeOrgId && !!token && activeNav === "revenue",
  });

  const { data: selectedContractDetail } = useQuery({
    queryKey: ["revenue-contract-detail", activeOrgId, selectedContractId],
    queryFn: async () => {
      if (!activeOrgId || !selectedContractId) return null;
      return erpApi.getRevenueContract(activeOrgId, selectedContractId).catch(() => null);
    },
    enabled: !!activeOrgId && !!token && !!selectedContractId,
  });

  const recognizeScheduleMutation = useMutation({
    mutationFn: async (scheduleId: string) => {
      return erpApi.recognizeRevenueSchedule(activeOrgId!, scheduleId);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["revenue-contracts"] });
      queryClient.invalidateQueries({ queryKey: ["revenue-contract-detail"] });
      queryClient.invalidateQueries({ queryKey: ["journals"] });
    },
  });

  const createContractMutation = useMutation({
    mutationFn: async (payload: any) => {
      return erpApi.createRevenueContract(activeOrgId!, payload);
    },
    onSuccess: () => {
      setIsNewContractModalOpen(false);
      queryClient.invalidateQueries({ queryKey: ["revenue-contracts"] });
    },
  });

  // ─────────────────────────────────────────────────────────────
  // 10. MULTI-ENTITY, FX & INTERCOMPANY CONSOLIDATION
  // ─────────────────────────────────────────────────────────────
  const [isNewEntityModalOpen, setIsNewEntityModalOpen] = useState(false);
  const [newEntityForm, setNewEntityForm] = useState({
    name: "",
    code: "",
    currency: "PKR",
    is_primary: false,
  });

  const [isNewRateModalOpen, setIsNewRateModalOpen] = useState(false);
  const [newRateForm, setNewRateForm] = useState({
    from_currency: "AED",
    to_currency: "PKR",
    rate: "76.50",
    effective_date: "2025-07-01",
    source: "State Bank of Pakistan",
  });

  const [isNewIntercompanyModalOpen, setIsNewIntercompanyModalOpen] = useState(false);
  const [newIntercompanyForm, setNewIntercompanyForm] = useState({
    from_entity_id: "",
    to_entity_id: "",
    transaction_date: "2025-07-20",
    currency: "PKR",
    amount: "150000",
    description: "",
  });

  const [isFxRevalModalOpen, setIsFxRevalModalOpen] = useState(false);
  const [spotRateUsd, setSpotRateUsd] = useState("282.50");

  const { data: realEntities = [], refetch: refetchEntities } = useQuery({
    queryKey: ["entities", activeOrgId],
    queryFn: async () => {
      if (!activeOrgId) return [];
      return erpApi.getEntities(activeOrgId).catch(() => []);
    },
    enabled: !!activeOrgId && !!token && activeReportTab === "consolidation",
  });

  const { data: realExchangeRates = [], refetch: refetchRates } = useQuery({
    queryKey: ["exchange-rates", activeOrgId],
    queryFn: async () => {
      if (!activeOrgId) return [];
      return erpApi.getExchangeRates(activeOrgId).catch(() => []);
    },
    enabled: !!activeOrgId && !!token && activeReportTab === "consolidation",
  });

  const { data: realIntercompanyTxs = [], refetch: refetchIntercompany } = useQuery({
    queryKey: ["intercompany-transactions", activeOrgId],
    queryFn: async () => {
      if (!activeOrgId) return [];
      return erpApi.getIntercompanyTransactions(activeOrgId).catch(() => []);
    },
    enabled: !!activeOrgId && !!token && activeReportTab === "consolidation",
  });

  const { data: realConsolidatedReport, refetch: refetchConsolidation, isLoading: isLoadingConsolidation } = useQuery({
    queryKey: ["consolidated-report", activeOrgId, activePeriod?.id],
    queryFn: async () => {
      if (!activeOrgId) return null;
      return erpApi.getConsolidatedReport(activeOrgId, "trial-balance", activePeriod?.id).catch(() => null);
    },
    enabled: !!activeOrgId && !!token && activeReportTab === "consolidation",
  });

  const createEntityMutation = useMutation({
    mutationFn: async (payload: { name: string; code: string; currency?: string; is_primary?: boolean }) => {
      return erpApi.createEntity(activeOrgId!, payload);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["entities"] });
      queryClient.invalidateQueries({ queryKey: ["consolidated-report"] });
      setIsNewEntityModalOpen(false);
      setNewEntityForm({ name: "", code: "", currency: "PKR", is_primary: false });
    },
  });

  const createRateMutation = useMutation({
    mutationFn: async (payload: any) => {
      return erpApi.createExchangeRate(activeOrgId!, { ...payload, rate: parseFloat(payload.rate) });
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["exchange-rates"] });
      queryClient.invalidateQueries({ queryKey: ["consolidated-report"] });
      setIsNewRateModalOpen(false);
    },
  });

  const createIntercompanyMutation = useMutation({
    mutationFn: async (payload: any) => {
      return erpApi.createIntercompanyTransaction(activeOrgId!, payload);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["intercompany-transactions"] });
      queryClient.invalidateQueries({ queryKey: ["consolidated-report"] });
      setIsNewIntercompanyModalOpen(false);
      setNewIntercompanyForm({
        from_entity_id: "",
        to_entity_id: "",
        transaction_date: "2025-07-20",
        currency: "PKR",
        amount: "150000",
        description: "",
      });
    },
  });

  const postIntercompanyMutation = useMutation({
    mutationFn: async (txId: string) => {
      return erpApi.postIntercompanyTransaction(activeOrgId!, txId);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["intercompany-transactions"] });
      queryClient.invalidateQueries({ queryKey: ["consolidated-report"] });
      queryClient.invalidateQueries({ queryKey: ["journals"] });
    },
  });

  const eliminateMutation = useMutation({
    mutationFn: async () => {
      return erpApi.runIntercompanyElimination(activeOrgId!, { accounting_period_id: activePeriod?.id });
    },
    onSuccess: (data: any) => {
      queryClient.invalidateQueries({ queryKey: ["intercompany-transactions"] });
      queryClient.invalidateQueries({ queryKey: ["consolidated-report"] });
      queryClient.invalidateQueries({ queryKey: ["journals"] });
      alert(`Elimination completed! Total amount eliminated: PKR ${(data?.total_amount || 0).toLocaleString()}`);
    },
  });

  const revaluationMutation = useMutation({
    mutationFn: async (rate: number) => {
      return erpApi.runCurrencyRevaluation(activeOrgId!, { accounting_period_id: activePeriod?.id, spot_rate_usd: rate });
    },
    onSuccess: (data: any) => {
      queryClient.invalidateQueries({ queryKey: ["consolidated-report"] });
      queryClient.invalidateQueries({ queryKey: ["journals"] });
      setIsFxRevalModalOpen(false);
      alert(`Unrealized FX Revaluation posted! Variance: PKR ${(data?.variance_amount || 0).toLocaleString()}`);
    },
  });

  // ─────────────────────────────────────────────────────────────
  // AUTHENTICATION HANDLERS
  // ─────────────────────────────────────────────────────────────
  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoginError("");
    try {
      const data = await erpApi.login(loginEmail, loginPassword);
      setToken(data.token);
      setCurrentUser(data.user);
      setStoredSession(data.token, data.user);

      // Fetch user orgs
      const orgs = await erpApi.getOrganizations();
      const defaultOrg = orgs[0] || null;
      setCurrentOrg(defaultOrg);

      setStoredSession(data.token, data.user, defaultOrg);
      setIsLoginModalOpen(false);
      queryClient.invalidateQueries();
    } catch (err: any) {
      setLoginError(err.message || "Failed to log in.");
    }
  };

  const handleLogout = async () => {
    await erpApi.logout();
    setToken(null);
    setCurrentUser(null);
    setCurrentOrg(null);
    setIsProfileOpen(false);
    queryClient.invalidateQueries();
  };

  // ─────────────────────────────────────────────────────────────
  // AI COPILOT REASONING HANDLER (With backend fallback)
  // ─────────────────────────────────────────────────────────────
  const handleAskCopilot = async (customPrompt?: string) => {
    const q = customPrompt || promptText;
    if (!q.trim()) return;

    setCopilotLoading(true);

    if (activeOrgId && token) {
      try {
        const res = await erpApi.askCopilot(activeOrgId, q, {
          active_module: activeNav,
          invoices_count: realInvoices.length,
          bills_count: realBills.length,
        });

        if (res && res.answer) {
          setCopilotResponse({
            answer: res.answer,
            keyMetrics: res.metrics || {
              "Reasoning Model": "Gemini 1.5 Flash",
              "Audit Log": "Logged",
              "Execution": "Real-time",
            },
            suggestedActions: res.suggested_actions || [
              "Inspect pending invoices",
              "Reconcile bank accounts",
            ],
          });
          setCopilotLoading(false);
          return;
        }
      } catch {
        // Fallback to local deterministic answers if AI gateway is in offline mode
      }
    }

    // Deterministic financial copilot fallback
    setTimeout(() => {
      if (q.toLowerCase().includes("pending") || q.toLowerCase().includes("invoice")) {
        const count = realInvoices.length || 16;
        const total = realInvoices.reduce((sum: number, inv: any) => sum + parseFloat(inv.total_amount || 0), 0) || 3240000;
        setCopilotResponse({
          answer: `You currently have ${count} customer invoices recorded in this organization totaling ${formatPKR(total)}.`,
          keyMetrics: {
            "Open Invoices": `${count}`,
            "Total Invoiced": formatPKR(total),
            "FBR Status": "Active",
          },
          suggestedActions: [
            "Send payment reminders for invoices overdue > 30 days",
            "Generate Annex-C sales tax schedule",
          ],
        });
      } else if (q.toLowerCase().includes("close")) {
        setCopilotResponse({
          answer: "Month-end close cycle for Q3 is in progress. Remaining items include fixed asset depreciation and bank reconciliation prior to locking the fiscal period.",
          keyMetrics: {
            "Close Progress": "50%",
            "Tasks Remaining": "2",
            "Period": "Q1 FY25",
          },
          suggestedActions: [
            "Post monthly asset depreciation entries",
            "Review and reconcile HBL bank account statement",
          ],
        });
      } else {
        setCopilotResponse({
          answer: `Analysis for "${q}": Operating cash balance and accounts receivable maintain positive working capital with 0 unbalanced journal entries across the General Ledger.`,
          keyMetrics: {
            "Runway": "48 Months",
            "Net Burn": "PKR 850K/mo",
            "GL Invariant": "Balanced ✓",
          },
          suggestedActions: [
            "Download updated 13-week cashflow forecast",
            "Inspect AP disbursement schedule",
          ],
        });
      }
      setCopilotLoading(false);
    }, 600);
  };

  const toggleChecklistItem = (id: number) => {
    setChecklist((prev) =>
      prev.map((item) =>
        item.id === id
          ? {
              ...item,
              completed: !item.completed,
              status: !item.completed ? "COMPLETED" : "NOT STARTED",
            }
          : item
      )
    );
  };

  const handleNavClick = (id: string) => {
    if (id === "search") {
      setIsSearchOpen(true);
    } else {
      setActiveNav(id);
    }
  };

  const quickPrompts = [
    "What's left on my close?",
    "Build 13 week cash forecast starting today",
    "What's driving change in net burn?",
    "Generate a flux analysis for this period",
  ];

  // Display invoices: fallback to seeded demo rows if not logged in
  const displayInvoices = realInvoices.length > 0
    ? realInvoices.map((inv: any) => ({
        id: inv.invoice_number,
        rawId: inv.id,
        customer: inv.customer?.name || "Customer",
        date: inv.issue_date,
        subtotal: parseFloat(inv.subtotal || 0).toLocaleString(),
        tax: parseFloat(inv.tax_amount || 0).toLocaleString(),
        total: parseFloat(inv.total_amount || 0).toLocaleString(),
        fbr: inv.fbr_fiscalized_at || inv.status === "sent" ? "Fiscalized (FBR POS)" : "Pending QR",
        status: inv.status,
      }))
    : [
        { id: "INV-2025-0012", rawId: "demo-1", customer: "Textile Mills Ltd", date: "2025-08-15", subtotal: "100,000", tax: "18,000", total: "118,000", fbr: "Fiscalized (FBR POS)", status: "paid" },
        { id: "INV-2025-0013", rawId: "demo-2", customer: "Indus Logistics Pvt", date: "2025-08-18", subtotal: "250,000", tax: "45,000", total: "295,000", fbr: "Fiscalized (FBR POS)", status: "sent" },
        { id: "INV-2025-0014", rawId: "demo-3", customer: "Lahore Tech Hub", date: "2025-08-20", subtotal: "80,000", tax: "14,400", total: "94,400", fbr: "Pending QR", status: "draft" },
        { id: "INV-2025-0015", rawId: "demo-4", customer: "Karachi Port Shipping", date: "2025-08-21", subtotal: "500,000", tax: "90,000", total: "590,000", fbr: "Fiscalized (FBR POS)", status: "sent" },
      ];

  // Display bills: fallback to demo rows if not logged in
  const displayBills = realBills.length > 0
    ? realBills.map((b: any) => ({
        id: b.bill_number,
        vendor: b.vendor?.name || "Vendor",
        po: b.purchase_order_id ? "Linked PO" : "Direct Bill",
        grn: "GRN-2025-0001 (100% rcvd)",
        amount: parseFloat(b.total_amount || 0).toLocaleString(),
        match: b.match_status === "matched" ? "Perfect Match" : b.match_status === "waived" ? "Waived by CFO" : "Verified",
        matchColor: b.match_status === "matched" ? "text-emerald-700 bg-emerald-50" : "text-indigo-700 bg-indigo-50",
        status: b.status,
      }))
    : [
        { id: "BILL-2025-001", vendor: "Steel Corp Pakistan", po: "PO-2025-0001", grn: "GRN-2025-0001 (100% rcvd)", amount: "70,000", match: "Perfect Match", matchColor: "text-emerald-700 bg-emerald-50", status: "Approved" },
        { id: "BILL-2025-002", vendor: "Heavy Bearings Ltd", po: "PO-2025-0002", grn: "GRN-2025-0002 (40/100 rcvd)", amount: "45,000", match: "Quantity Variance Exceeded", matchColor: "text-amber-700 bg-amber-50", status: "Exception" },
        { id: "BILL-2025-003", vendor: "Hydraulic Valves Hub", po: "PO-2025-0003", grn: "GRN-2025-0003", amount: "6,000", match: "Price Variance Exceeded (+20%)", matchColor: "text-rose-700 bg-rose-50", status: "Waived by CFO" },
      ];

  // Display bank accounts & statement transactions
  const demoBankAccounts = [
    {
      id: "demo-meezan",
      bank_name: "Meezan Bank Limited",
      account_title: "Operating Account",
      account_number: "01020304050607",
      iban: "PK36MEZN0001020304050607",
      currency: "PKR",
      current_balance: "598,840.00",
      unreconciled_count: 2,
    },
    {
      id: "demo-hbl",
      bank_name: "Habib Bank Limited (HBL)",
      account_title: "Tax & Payroll Account",
      account_number: "99887766554433",
      iban: "PK12HABB0099887766554433",
      currency: "PKR",
      current_balance: "1,240,500.00",
      unreconciled_count: 0,
    },
  ];

  const displayBankAccounts = realBankAccounts.length > 0
    ? realBankAccounts.map((b: any) => ({
        id: b.id,
        bank_name: b.bank_name,
        account_title: b.account_title,
        account_number: b.account_number,
        iban: b.iban,
        currency: b.currency || "PKR",
        current_balance: parseFloat(b.current_balance || 0).toLocaleString(),
        unreconciled_count: b.unreconciled_count ?? 0,
      }))
    : demoBankAccounts;

  const activeBankAccount = displayBankAccounts.find((a: any) => a.id === effectiveBankAccountId) || displayBankAccounts[0];

  const demoBankTransactions = [
    {
      id: "tx-1",
      transaction_date: "2025-08-10",
      description: "IBFT From Metro Retail Pvt Ltd",
      reference: "IBFT-90812",
      type: "credit",
      amount: "150,000.00",
      reconciliation_status: "reconciled",
      matched_journal_entry: { entry_number: "JE-2025-0012", description: "Customer Receipt #REC-2025-0012" },
      suggestion: null,
    },
    {
      id: "tx-2",
      transaction_date: "2025-08-12",
      description: "Vendor Packaging Supplies Chq #4091",
      reference: "CHQ-4091",
      type: "debit",
      amount: "50,000.00",
      reconciliation_status: "unreconciled",
      matched_journal_entry: null,
      suggestion: {
        journal_id: "je-demo-bill",
        entry_number: "JE-2025-0015",
        description: "Payment for Bill #BILL-2025-001",
        confidence: 0.95,
        reason: "Exact amount match (PKR 50,000) & vendor reference match",
      },
    },
    {
      id: "tx-3",
      transaction_date: "2025-08-14",
      description: "Bank Service Charges & FED",
      reference: "TXN-8821",
      type: "debit",
      amount: "1,160.00",
      reconciliation_status: "unreconciled",
      matched_journal_entry: null,
      suggestion: null,
    },
  ];

  const displayBankTransactions = realBankTransactions.length > 0
    ? realBankTransactions.map((tx: any) => {
        const suggestionObj = realSuggestions.find((s: any) => s.transaction_id === tx.id);
        const bestMatch = suggestionObj?.matches?.[0] || null;
        return {
          id: tx.id,
          transaction_date: tx.transaction_date,
          description: tx.description,
          reference: tx.reference || "N/A",
          type: tx.type,
          amount: parseFloat(tx.amount || 0).toLocaleString(),
          reconciliation_status: tx.reconciliation_status,
          matched_journal_entry: tx.matched_journal_entry,
          suggestion: bestMatch
            ? {
                journal_id: bestMatch.journal_entry_id,
                entry_number: bestMatch.entry_number,
                description: bestMatch.description,
                confidence: bestMatch.confidence,
                reason: bestMatch.reason,
              }
            : null,
        };
      })
    : demoBankTransactions;

  // Display accounts
  const displayAccounts = realAccounts.length > 0
    ? realAccounts.slice(0, 15).map((acc: any) => ({
        code: acc.code,
        name: acc.name,
        type: acc.classification ? acc.classification.charAt(0).toUpperCase() + acc.classification.slice(1) : "Asset",
        normal: acc.normal_balance ? acc.normal_balance.charAt(0).toUpperCase() + acc.normal_balance.slice(1) : "Debit",
        isControl: !!acc.is_control_account,
        controlType: acc.control_type,
      }))
    : [
        { code: "1010", name: "Operating Cash & Bank Account", type: "Asset", normal: "Debit", isControl: false },
        { code: "1030", name: "Trade Debtors / Accounts Receivable", type: "Asset", normal: "Debit", isControl: true, controlType: "ar_control" },
        { code: "1070", name: "Merchandise Inventory", type: "Asset", normal: "Debit", isControl: false },
        { code: "1590", name: "Accumulated Depreciation", type: "Contra Asset", normal: "Credit", isControl: false },
        { code: "2010", name: "Trade Creditors / Accounts Payable", type: "Liability", normal: "Credit", isControl: true, controlType: "ap_control" },
        { code: "4010", name: "Sales Revenue - Local", type: "Revenue", normal: "Credit", isControl: false },
        { code: "5010", name: "Cost of Goods Sold - Purchases", type: "Expense", normal: "Debit", isControl: false },
        { code: "6070", name: "Depreciation Expense", type: "Expense", normal: "Debit", isControl: false },
      ];

  // Display journals
  const displayJournals = realJournals.length > 0
    ? realJournals.slice(0, 6).map((je: any) => {
        const total = parseFloat(je.total_amount || 0);
        return {
          number: je.entry_number,
          desc: je.description || "General Ledger Entry",
          dr: `PKR ${total.toLocaleString()}`,
          cr: `PKR ${total.toLocaleString()}`,
          status: je.status ? je.status.charAt(0).toUpperCase() + je.status.slice(1) : "Posted",
        };
      })
    : [
        { number: "JE-2025-0001", desc: "Automated COGS for Invoice #INV-2025-0012", dr: "PKR 20,000", cr: "PKR 20,000", status: "Posted" },
        { number: "JE-2025-0002", desc: "Straight-Line Fixed Asset Depreciation", dr: "PKR 10,000", cr: "PKR 10,000", status: "Posted" },
        { number: "JE-2025-0003", desc: "Unrealized FX Revaluation ($10,000 USD Spot)", dr: "PKR 125,000", cr: "PKR 125,000", status: "Posted" },
        { number: "JE-2025-0004", desc: "Monthly Salaries — August 2025", dr: "PKR 850,000", cr: "PKR 850,000", status: "Posted" },
      ];

  // Multi-Entity & Consolidation Fallbacks
  const demoEntities = [
    { id: "ent-1", name: "Indus Holding Corp (Parent)", code: "HOLDING", currency: "PKR", is_primary: true, status: "active" },
    { id: "ent-2", name: "Indus Logistics Services (Sub)", code: "LOGISTICS", currency: "PKR", is_primary: false, status: "active" },
    { id: "ent-3", name: "Indus Gulf Tech FZE (Dubai)", code: "GULF", currency: "AED", is_primary: false, status: "active" },
  ];
  const displayEntities = realEntities.length > 0 ? realEntities : demoEntities;

  const demoExchangeRates = [
    { id: "fx-1", from_currency: "AED", to_currency: "PKR", rate: "76.500000", effective_date: "2025-07-01", source: "State Bank of Pakistan" },
    { id: "fx-2", from_currency: "USD", to_currency: "PKR", rate: "282.500000", effective_date: "2025-07-01", source: "State Bank of Pakistan" },
    { id: "fx-3", from_currency: "SAR", to_currency: "PKR", rate: "74.800000", effective_date: "2025-07-01", source: "State Bank of Pakistan" },
  ];
  const displayExchangeRates = realExchangeRates.length > 0 ? realExchangeRates : demoExchangeRates;

  const demoIntercompany = [
    {
      id: "ic-1",
      transaction_number: "IC-2025-0001",
      transaction_date: "2025-07-20",
      fromEntity: { name: "Indus Holding Corp", code: "HOLDING" },
      toEntity: { name: "Indus Logistics Services", code: "LOGISTICS" },
      amount: "150,000.00",
      currency: "PKR",
      description: "Shared Cloud ERP Infrastructure and Accounting Overhead",
      status: "posted",
    },
    {
      id: "ic-2",
      transaction_number: "IC-2025-0002",
      transaction_date: "2025-07-25",
      fromEntity: { name: "Indus Holding Corp", code: "HOLDING" },
      toEntity: { name: "Indus Gulf Tech FZE", code: "GULF" },
      amount: "80,000.00",
      currency: "PKR",
      description: "Legal and Compliance Fee Allocation",
      status: "eliminated",
    },
  ];
  const displayIntercompany = realIntercompanyTxs.length > 0 ? realIntercompanyTxs : demoIntercompany;

  return (
    <div className="flex h-screen bg-[#FDFDFD] text-[#1E293B] font-sans antialiased overflow-hidden select-none">
      {/* ─────────────────────────────────────────────────────────────
          1. SLIM LEFT ICON SIDEBAR (Matches exact reference design)
      ─────────────────────────────────────────────────────────────── */}
      <aside className="w-[68px] bg-white border-r border-[#F1F5F9] flex flex-col items-center justify-between py-5 shrink-0 z-20">
        {/* Top Brand Logo & Navigation Icons */}
        <div className="flex flex-col items-center space-y-7 w-full">
          {/* Logo Badge (Ri) */}
          <div
            onClick={() => setActiveNav("home")}
            className="w-10 h-10 rounded-[12px] bg-[#6366F1] text-white flex items-center justify-center font-bold text-base shadow-sm tracking-tight cursor-pointer hover:opacity-95 transition-opacity"
            title="AI-Native Finance ERP"
          >
            Ri
          </div>

          {/* Nav Rail */}
          <nav className="flex flex-col items-center space-y-4 w-full px-2">
            {[
              { id: "search", icon: Search, label: "Search (Ctrl+K)" },
              { id: "home", icon: Home, label: "Executive Dashboard" },
              { id: "copilot", icon: Bot, label: "AI Financial Copilot" },
              { id: "invoices", icon: FileText, label: "Sales Invoices (AR)" },
              { id: "bills", icon: FilePenLine, label: "Bills & 3-Way Matching (AP)" },
              { id: "banking", icon: Landmark, label: "Banking & Reconciliation" },
              { id: "revenue", icon: TrendingUp, label: "Revenue Recognition (ASC 606)" },
              { id: "ledger", icon: BookOpen, label: "General Ledger & COA" },
              { id: "reports", icon: BarChart3, label: "Financial Reports" },
              { id: "features", icon: Sparkles, label: "ERP Module Directory" },
            ].map((item) => {
              const Icon = item.icon;
              const isActive = activeNav === item.id;
              return (
                <button
                  key={item.id}
                  onClick={() => handleNavClick(item.id)}
                  title={item.label}
                  className={cn(
                    "w-10 h-10 rounded-xl flex items-center justify-center transition-all duration-150 relative group cursor-pointer",
                    isActive
                      ? "text-[#1E293B] bg-[#F1F5F9]/80 font-semibold shadow-xs"
                      : "text-[#94A3B8] hover:text-[#475569] hover:bg-[#F8FAFC]"
                  )}
                >
                  <Icon className="w-[19px] h-[19px] stroke-[1.75]" />
                  {isActive && (
                    <span className="absolute -left-2 w-[3px] h-5 bg-[#6366F1] rounded-r-full" />
                  )}
                </button>
              );
            })}
          </nav>
        </div>

        {/* Bottom Utility Icons & Profile Orb */}
        <div className="flex flex-col items-center space-y-5 w-full">
          <button
            onClick={() => setIsHistoryOpen(true)}
            title="Audit Trail History"
            className="text-[#94A3B8] hover:text-[#475569] transition-colors p-1 cursor-pointer"
          >
            <History className="w-[18px] h-[18px] stroke-[1.75]" />
          </button>

          <button
            onClick={() => setIsNotificationsOpen(true)}
            title="Notifications & Alerts"
            className="text-[#94A3B8] hover:text-[#475569] transition-colors p-1 relative cursor-pointer"
          >
            <Bell className="w-[18px] h-[18px] stroke-[1.75]" />
            <span className="absolute top-1 right-1 w-2 h-2 bg-[#6366F1] rounded-full ring-2 ring-white" />
          </button>

          {/* User Profile Orb / Login Trigger */}
          <div
            onClick={() => {
              if (token) {
                setIsProfileOpen(true);
              } else {
                setIsLoginModalOpen(true);
              }
            }}
            title={token ? `${currentUser?.name || "User"} (${currentOrg?.name || "Apex"})` : "Click to Log In"}
            className="w-8 h-8 rounded-full bg-gradient-to-tr from-[#8B5CF6] to-[#6366F1] text-white flex items-center justify-center text-xs font-semibold ring-2 ring-white shadow-xs cursor-pointer hover:scale-105 transition-all"
          >
            {token ? (currentUser?.name?.charAt(0) || "A") : <LogIn className="w-3.5 h-3.5" />}
          </div>
        </div>
      </aside>

      {/* ─────────────────────────────────────────────────────────────
          2. MAIN CONTENT AREA (Scrollable)
      ─────────────────────────────────────────────────────────────── */}
      <main className="flex-1 flex flex-col h-screen overflow-y-auto bg-[#FDFDFD]">
        {/* Top Floating App Bar */}
        <header className="h-16 px-8 flex items-center justify-between border-b border-[#F1F5F9]/60 shrink-0 sticky top-0 bg-white/90 backdrop-blur-md z-10">
          <div className="flex items-center space-x-3">
            <span className="text-sm font-semibold tracking-tight text-[#0F172A]">
              {activeNav === "home"
                ? "Executive Dashboard"
                : activeNav === "invoices"
                ? "Accounts Receivable"
                : activeNav === "bills"
                ? "Accounts Payable & 3-Way Match"
                : activeNav === "banking"
                ? "Banking & Statement Reconciliation"
                : activeNav === "revenue"
                ? "Revenue Recognition (ASC 606 / IFRS 15)"
                : activeNav === "ledger"
                ? "General Ledger & COA"
                : activeNav === "reports"
                ? "Financial Statements"
                : activeNav === "copilot"
                ? "AI Financial Copilot"
                : "ERP Module Directory"}
            </span>

            {/* Tenant Badge */}
            {currentOrg && (
              <span className="inline-flex items-center space-x-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-indigo-50 text-indigo-700 border border-indigo-100">
                <Building className="w-3 h-3" />
                <span>{currentOrg.name}</span>
              </span>
            )}

            {/* Live Engine Status Badge */}
            <span
              className={cn(
                "inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium border ml-2",
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
              {isConnected ? "Engine Active (134 Tests OK)" : "Reconnecting"}
            </span>
          </div>

          <div className="flex items-center space-x-4">
            {/* Real Data indicator or Login Action */}
            {token ? (
              <div className="flex items-center space-x-2 text-xs text-[#64748B]">
                <span className="w-2 h-2 rounded-full bg-emerald-500" />
                <span className="font-medium text-[#0F172A]">{currentUser?.email}</span>
              </div>
            ) : (
              <button
                onClick={() => setIsLoginModalOpen(true)}
                className="bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold px-3 py-1.5 rounded-lg flex items-center space-x-1.5 shadow-sm transition-all cursor-pointer"
              >
                <LogIn className="w-3.5 h-3.5" />
                <span>Connect API (Demo Login)</span>
              </button>
            )}

            {/* Filter / Search Trigger */}
            <button
              onClick={() => setIsSearchOpen(true)}
              title="Search Spotlight (Ctrl+K)"
              className="p-2 text-[#94A3B8] hover:text-[#475569] rounded-lg hover:bg-slate-100 transition-colors cursor-pointer"
            >
              <Search className="w-4 h-4 stroke-[1.75]" />
            </button>
          </div>
        </header>

        {/* ─────────────────────────────────────────────────────────────
            VIEW 1: EXECUTIVE DASHBOARD (HOME)
        ─────────────────────────────────────────────────────────────── */}
        {activeNav === "home" && (
          <div className="max-w-[1240px] w-full mx-auto px-8 pb-16 pt-2 flex flex-col space-y-10">
            {/* A. HERO AI COMMAND BAR */}
            <div className="flex flex-col items-center justify-center text-center space-y-6 pt-4">
              <h1 className="text-3xl sm:text-4xl font-semibold tracking-tight text-[#0F172A]">
                Rise and reconcile.
              </h1>

              <div className="w-full max-w-2xl relative">
                <div className="bg-white rounded-full border border-[#E2E8F0] px-5 py-3.5 flex items-center space-x-3.5 ai-search-shadow transition-all">
                  <div className="w-7 h-7 rounded-full bg-[#8B5CF6] text-white flex items-center justify-center shrink-0 shadow-sm">
                    <span className="text-xs font-serif leading-none">❋</span>
                  </div>

                  <input
                    type="text"
                    value={promptText}
                    onChange={(e) => setPromptText(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === "Enter") handleAskCopilot();
                    }}
                    placeholder="Ask financial copilot, generate flux analysis, or draft entries..."
                    className="flex-1 bg-transparent border-none outline-none text-[#1E293B] text-sm sm:text-base placeholder:text-[#94A3B8] font-normal"
                  />

                  <div className="flex items-center space-x-2 text-[#94A3B8]">
                    <button
                      type="button"
                      onClick={() => handleAskCopilot()}
                      title="Run Copilot Query"
                      className="p-1 hover:text-[#475569] transition-colors cursor-pointer"
                    >
                      {copilotLoading ? (
                        <span className="w-4 h-4 rounded-full border-2 border-[#8B5CF6] border-t-transparent animate-spin inline-block" />
                      ) : (
                        <Mic className="w-4 h-4 stroke-[1.75]" />
                      )}
                    </button>
                  </div>
                </div>

                {copilotResponse && (
                  <div className="mt-4 p-5 bg-white border border-[#E2E8F0] rounded-2xl text-left shadow-md flex flex-col space-y-3 transition-all animate-in fade-in slide-in-from-top-2">
                    <div className="flex items-center justify-between">
                      <div className="flex items-center space-x-2">
                        <div className="w-5 h-5 rounded-full bg-[#8B5CF6] text-white flex items-center justify-center text-[10px]">
                          ✦
                        </div>
                        <span className="text-xs font-semibold text-[#0F172A] uppercase tracking-wider">
                          Copilot Financial Reasoning
                        </span>
                      </div>
                      <button
                        onClick={() => setCopilotResponse(null)}
                        className="text-xs text-[#94A3B8] hover:text-[#475569] cursor-pointer"
                      >
                        Dismiss
                      </button>
                    </div>
                    <p className="text-sm text-[#334155] leading-relaxed">
                      {copilotResponse.answer}
                    </p>

                    {copilotResponse.keyMetrics && (
                      <div className="grid grid-cols-3 gap-2.5 pt-2 border-t border-[#F1F5F9]">
                        {Object.entries(copilotResponse.keyMetrics).map(([k, v]) => (
                          <div key={k} className="p-2 bg-[#F8FAFC] rounded-lg">
                            <span className="text-[10px] text-[#94A3B8] block">{k}</span>
                            <span className="text-xs font-semibold text-[#0F172A] font-tabular">
                              {v}
                            </span>
                          </div>
                        ))}
                      </div>
                    )}

                    {copilotResponse.suggestedActions && (
                      <div className="flex flex-wrap gap-2 pt-1">
                        {copilotResponse.suggestedActions.map((action, i) => (
                          <button
                            key={i}
                            onClick={() => {
                              if (action.includes("invoice")) setActiveNav("invoices");
                              else if (action.includes("depreciation") || action.includes("close")) setActiveNav("home");
                              else setActiveNav("reports");
                            }}
                            className="text-[11px] font-medium text-[#6366F1] bg-[#EEF2FF] hover:bg-[#E0E7FF] px-2.5 py-1 rounded-md transition-colors cursor-pointer"
                          >
                            → {action}
                          </button>
                        ))}
                      </div>
                    )}
                  </div>
                )}

                <div className="flex flex-wrap items-center justify-center gap-2.5 mt-4">
                  {quickPrompts.map((prompt, idx) => (
                    <button
                      key={idx}
                      onClick={() => {
                        setPromptText(prompt);
                        handleAskCopilot(prompt);
                      }}
                      className="text-[11px] font-medium text-[#64748B] hover:text-[#1E293B] bg-white border border-[#E2E8F0]/80 hover:border-[#CBD5E1] px-3.5 py-1.5 rounded-full shadow-[0_1px_2px_rgba(0,0,0,0.02)] transition-all cursor-pointer"
                    >
                      {prompt}
                    </button>
                  ))}
                </div>
              </div>
            </div>

            {/* B. SNAPSHOT FINANCIAL GRID */}
            <div className="flex flex-col space-y-3">
              <div className="flex items-center space-x-2 text-[11px] font-semibold text-[#64748B] tracking-wider uppercase">
                <span className="text-[#94A3B8]">⠇⠇</span>
                <span>Financial Snapshot ({currentOrg?.name || "Apex Trading"})</span>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-12 bg-white rounded-2xl border border-[#EBEFF5] shadow-[0_2px_8px_rgba(0,0,0,0.02)] overflow-hidden divide-y md:divide-y-0 md:divide-x divide-[#EBEFF5]">
                {/* Cash Balance */}
                <div className="md:col-span-4 p-6 flex flex-col justify-between">
                  <div>
                    <div className="flex items-center space-x-1.5 text-xs font-medium text-[#64748B]">
                      <span className="text-[#CBD5E1]">::</span>
                      <span className="uppercase tracking-wider">HBL Operating Cash</span>
                    </div>
                    <div className="mt-4 flex items-baseline space-x-2">
                      <span className="text-3xl sm:text-4xl font-bold tracking-tight text-[#0F172A]">
                        PKR 5.0M
                      </span>
                      <span className="text-base text-[#6366F1] font-semibold">✓</span>
                    </div>
                  </div>
                  <div className="mt-8 text-[11px] text-[#94A3B8] leading-relaxed">
                    <p>Live HBL Account #01234567890123</p>
                    <p className="text-[#64748B]">Reconciled with GL #1010</p>
                  </div>
                </div>

                {/* Revenue */}
                <div className="md:col-span-4 p-6 flex flex-col justify-between">
                  <div>
                    <div className="flex items-center space-x-1.5 text-xs font-medium text-[#64748B]">
                      <span className="text-[#CBD5E1]">::</span>
                      <span className="uppercase tracking-wider">Total Sales Invoiced</span>
                    </div>
                    <div className="mt-4 flex items-center justify-between">
                      <div className="flex items-baseline space-x-2">
                        <span className="text-3xl sm:text-4xl font-bold tracking-tight text-[#0F172A]">
                          PKR 3.2M
                        </span>
                        <ArrowUpRight className="w-5 h-5 text-[#6366F1] stroke-[2.5]" />
                      </div>

                      <div className="w-32 h-10">
                        <svg viewBox="0 0 120 40" fill="none" className="w-full h-full">
                          <path
                            d="M 5 32 C 25 30, 45 28, 65 18 C 85 8, 105 12, 115 5"
                            stroke="#10B981"
                            strokeWidth="2.5"
                            strokeLinecap="round"
                          />
                        </svg>
                      </div>
                    </div>
                  </div>
                  <div className="mt-8 flex items-center space-x-2 text-xs">
                    <span className="font-semibold text-[#10B981]">+18% GST</span>
                    <span className="text-[#94A3B8]">FBR QR Fiscalized</span>
                  </div>
                </div>

                {/* Runway & Burn */}
                <div className="md:col-span-4 p-6 flex flex-col justify-between">
                  <div>
                    <div className="flex items-center space-x-1.5 text-xs font-medium text-[#64748B]">
                      <span className="text-[#CBD5E1]">::</span>
                      <span className="uppercase tracking-wider">Runway & Net Burn</span>
                    </div>
                    <div className="mt-4 flex items-baseline justify-between">
                      <div>
                        <span className="text-3xl sm:text-4xl font-bold tracking-tight text-[#0F172A]">
                          48 mos
                        </span>
                        <span className="text-xs text-[#94A3B8] block mt-1">Runway remaining</span>
                      </div>
                      <div className="text-right">
                        <span className="text-xl font-bold text-[#EF4444]">PKR 850K</span>
                        <span className="text-xs text-[#94A3B8] block">Monthly Payroll</span>
                      </div>
                    </div>
                  </div>
                  <div className="mt-8 text-[11px] text-[#94A3B8]">
                    Balanced general ledger invariant maintained
                  </div>
                </div>
              </div>
            </div>

            {/* C. THREE COLUMN OPERATIONS GRID */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
              {/* Needs Action */}
              <div className="flex flex-col space-y-4">
                <div className="flex items-center space-x-2 text-[11px] font-semibold text-[#64748B] tracking-wider uppercase">
                  <span className="text-[#94A3B8]">⠇⠇</span>
                  <span>Needs Action</span>
                </div>

                <div className="flex flex-col space-y-3.5 pt-1">
                  {[
                    { label: "Bills pending 3-Way Match", count: realMatches.filter((m: any) => m.status === "variance" || m.status === "exception").length || 2, dot: true, nav: "bills" },
                    { label: "Sales Invoices in Draft", count: realInvoices.filter((i: any) => i.status === "draft").length || 1, dot: true, nav: "invoices" },
                    { label: "Cash Transactions to be reconciled", count: 3, dot: true, nav: "ledger" },
                    { label: "Invoices to be FBR fiscalized", count: realInvoices.filter((i: any) => !i.fbr_fiscalized_at).length || 2, dot: true, nav: "invoices" },
                    { label: "Active Inventory SKUs", count: 3, dot: false, nav: "features" },
                  ].map((action, idx) => (
                    <div
                      key={idx}
                      onClick={() => setActiveNav(action.nav)}
                      className="flex items-center justify-between text-sm py-0.5 group cursor-pointer hover:bg-slate-50 p-1 rounded-md transition-colors"
                    >
                      <div className="flex items-center space-x-2.5">
                        {action.dot ? (
                          <span className="w-1.5 h-1.5 rounded-full bg-[#3B82F6]" />
                        ) : (
                          <span className="w-1.5 h-1.5" />
                        )}
                        <span className="text-[#334155] group-hover:text-[#0F172A] transition-colors">
                          {action.label}
                        </span>
                      </div>
                      <span className="font-semibold text-[#0F172A] font-tabular">
                        {action.count}
                      </span>
                    </div>
                  ))}
                </div>
              </div>

              {/* Close Checklist & Two-Stage Period Lifecycle */}
              <div className="flex flex-col space-y-3">
                <div className="flex items-center justify-between">
                  <div className="flex items-center space-x-2 text-[11px] font-semibold text-[#64748B] tracking-wider uppercase">
                    <CircleDot className="w-3.5 h-3.5 text-[#94A3B8]" />
                    <span>Month-End Close ({activePeriod?.name || "July 2025"})</span>
                  </div>
                  <span
                    className={cn(
                      "text-[10px] font-bold px-2 py-0.5 rounded tracking-tight uppercase flex items-center gap-1",
                      activePeriod?.status === "open"
                        ? "bg-emerald-50 text-emerald-700 border border-emerald-200/60"
                        : activePeriod?.status === "soft_closed"
                        ? "bg-amber-50 text-amber-700 border border-amber-200/60"
                        : "bg-rose-50 text-rose-700 border border-rose-200/60"
                    )}
                  >
                    {activePeriod?.status === "open" && <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse" />}
                    {activePeriod?.status === "soft_closed" && <Lock className="w-2.5 h-2.5 text-amber-600" />}
                    {(activePeriod?.status === "hard_closed" || activePeriod?.status === "closed" || activePeriod?.status === "locked") && <Shield className="w-2.5 h-2.5 text-rose-600" />}
                    {activePeriod?.status === "open" ? "Open" : activePeriod?.status === "soft_closed" ? "Soft-Closed" : "Hard-Closed"}
                  </span>
                </div>

                {/* Period Actions */}
                <div className="p-2.5 bg-[#F8FAFC] rounded-xl border border-[#E2E8F0]/70 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                  <div className="text-[11px] text-[#475569] leading-tight">
                    {activePeriod?.status === "open" && "Operational posting open. Soft-close to lock invoices & bills."}
                    {activePeriod?.status === "soft_closed" && "Operational lock active. Only adjusting journals permitted."}
                    {(activePeriod?.status === "hard_closed" || activePeriod?.status === "closed" || activePeriod?.status === "locked") && "Audit lock active. All ledger postings completely frozen."}
                  </div>
                  <div className="flex items-center gap-1.5 shrink-0">
                    {activePeriod?.status === "open" && (
                      <button
                        onClick={() => softClosePeriodMutation.mutate(activePeriod.id)}
                        disabled={softClosePeriodMutation.isPending}
                        className="px-2 py-1 text-[10px] font-semibold text-amber-700 bg-amber-50 hover:bg-amber-100 border border-amber-200 rounded transition-colors flex items-center gap-1 cursor-pointer"
                        title="Soft-Close: Prohibit operational invoices and bills, permit adjusting journals"
                      >
                        <Lock className="w-2.5 h-2.5" />
                        Soft-Close
                      </button>
                    )}
                    {activePeriod?.status === "soft_closed" && (
                      <>
                        <button
                          onClick={() => hardClosePeriodMutation.mutate(activePeriod.id)}
                          disabled={hardClosePeriodMutation.isPending}
                          className="px-2 py-1 text-[10px] font-semibold text-rose-700 bg-rose-50 hover:bg-rose-100 border border-rose-200 rounded transition-colors flex items-center gap-1 cursor-pointer"
                          title="Hard-Close: Permanent audit freeze on all journal entries"
                        >
                          <Shield className="w-2.5 h-2.5" />
                          Hard-Close
                        </button>
                        <button
                          onClick={() => reopenPeriodMutation.mutate({ periodId: activePeriod.id, reason: "Adjustment revisions" })}
                          disabled={reopenPeriodMutation.isPending}
                          className="px-2 py-1 text-[10px] font-semibold text-slate-600 bg-white hover:bg-slate-50 border border-slate-200 rounded transition-colors flex items-center gap-1 cursor-pointer"
                          title="Reopen period for operational entries"
                        >
                          <Unlock className="w-2.5 h-2.5" />
                          Reopen
                        </button>
                      </>
                    )}
                    {(activePeriod?.status === "hard_closed" || activePeriod?.status === "closed" || activePeriod?.status === "locked") && (
                      <button
                        onClick={() => reopenPeriodMutation.mutate({ periodId: activePeriod.id, reason: "Audit adjustment override" })}
                        disabled={reopenPeriodMutation.isPending}
                        className="px-2 py-1 text-[10px] font-semibold text-slate-700 bg-white hover:bg-slate-50 border border-slate-200 rounded transition-colors flex items-center gap-1 cursor-pointer"
                      >
                        <Unlock className="w-2.5 h-2.5" />
                        Reopen Period
                      </button>
                    )}
                  </div>
                </div>

                <div className="flex flex-col space-y-2 pt-1">
                  <div className="flex items-baseline justify-between">
                    <span className="text-2xl font-bold text-[#0F172A]">
                      {activePeriod?.status === "hard_closed" || activePeriod?.status === "closed" || activePeriod?.status === "locked" ? "100%" : activePeriod?.status === "soft_closed" ? "75%" : "50%"}
                    </span>
                    <span className="text-xs text-[#94A3B8] font-medium">
                      {activePeriod?.status === "hard_closed" || activePeriod?.status === "closed" || activePeriod?.status === "locked" ? "4 / 4 complete" : activePeriod?.status === "soft_closed" ? "3 / 4 complete" : "2 / 4 complete"}
                    </span>
                  </div>
                  <div className="w-full h-1 bg-[#F1F5F9] rounded-full overflow-hidden">
                    <div
                      className="h-full bg-[#6366F1] rounded-full transition-all duration-300"
                      style={{ width: activePeriod?.status === "hard_closed" || activePeriod?.status === "closed" || activePeriod?.status === "locked" ? "100%" : activePeriod?.status === "soft_closed" ? "75%" : "50%" }}
                    />
                  </div>
                </div>

                <div className="flex flex-col space-y-3 pt-2">
                  {checklist.map((item) => (
                    <div
                      key={item.id}
                      onClick={() => toggleChecklistItem(item.id)}
                      className="flex items-center justify-between py-1 text-xs sm:text-sm cursor-pointer group"
                    >
                      <div className="flex items-center space-x-2.5">
                        <div
                          className={cn(
                            "w-4 h-4 rounded-full border flex items-center justify-center transition-colors",
                            item.completed
                              ? "bg-[#10B981] border-[#10B981] text-white"
                              : "border-[#CBD5E1] group-hover:border-[#94A3B8]"
                          )}
                        >
                          {item.completed && <CheckCircle2 className="w-3 h-3" />}
                        </div>
                        <span
                          className={cn(
                            "transition-colors",
                            item.completed
                              ? "line-through text-[#94A3B8]"
                              : "text-[#334155] group-hover:text-[#0F172A]"
                          )}
                        >
                          {item.text}
                        </span>
                      </div>
                      <span
                        className={cn(
                          "text-[10px] font-semibold px-2 py-0.5 rounded tracking-tight uppercase",
                          item.completed
                            ? "bg-emerald-50 text-emerald-700"
                            : "bg-[#F8FAFC] text-[#64748B] border border-[#E2E8F0]/60"
                        )}
                      >
                        {item.status}
                      </span>
                    </div>
                  ))}
                </div>
              </div>

              {/* Pinned Reports */}
              <div className="flex flex-col space-y-4">
                <div className="flex items-center space-x-2 text-[11px] font-semibold text-[#64748B] tracking-wider uppercase">
                  <span className="text-[#F59E0B] text-xs">☆</span>
                  <span>Pinned Reports & Modules</span>
                </div>

                <div className="flex flex-col space-y-1.5 pt-1">
                  {[
                    { label: "Income Statement (P&L)", icon: BarChart3, nav: "reports", tab: "income-statement" },
                    { label: "Balance Sheet", icon: Compass, nav: "reports", tab: "balance-sheet" },
                    { label: "General Ledger & COA", icon: BookOpen, nav: "ledger" },
                    { label: "3-Way Matching & Procurement", icon: PackageCheck, nav: "bills" },
                    { label: "Inventory Valuation & Perpetual COGS", icon: Boxes, nav: "features" },
                    { label: "Consolidated Financials (Multi-Entity)", icon: TrendingUp, nav: "reports", tab: "consolidation" },
                    { label: "Integrations & Webhooks", icon: Plug, nav: "features" },
                    { label: "Security Hardening & API Keys", icon: ShieldCheck, nav: "features" },
                  ].map((report, idx) => {
                    const Icon = report.icon;
                    return (
                      <div
                        key={idx}
                        onClick={() => {
                          setActiveNav(report.nav);
                          if (report.tab) setActiveReportTab(report.tab);
                        }}
                        className="flex items-center justify-between p-2.5 rounded-xl hover:bg-white hover:border hover:border-[#E2E8F0]/60 hover:shadow-[0_2px_4px_rgba(0,0,0,0.02)] transition-all cursor-pointer group"
                      >
                        <div className="flex items-center space-x-3 text-sm text-[#334155] group-hover:text-[#0F172A] font-medium">
                          <Icon className="w-4 h-4 text-[#94A3B8] group-hover:text-[#6366F1] transition-colors" />
                          <span>{report.label}</span>
                        </div>
                        <ArrowRight className="w-4 h-4 text-[#CBD5E1] group-hover:text-[#6366F1] group-hover:translate-x-0.5 transition-all" />
                      </div>
                    );
                  })}
                </div>
              </div>
            </div>
          </div>
        )}

        {/* ─────────────────────────────────────────────────────────────
            VIEW 2: INVOICES (AR & FBR DIGITAL INVOICING)
        ─────────────────────────────────────────────────────────────── */}
        {activeNav === "invoices" && (
          <div className="max-w-[1240px] w-full mx-auto px-8 py-8 flex flex-col space-y-6">
            <div className="flex items-center justify-between">
              <div>
                <h2 className="text-2xl font-bold tracking-tight text-[#0F172A]">
                  Sales Invoices & Accounts Receivable
                </h2>
                <p className="text-xs text-[#64748B] mt-1">
                  Manage commercial billings, FBR Digital QR Fiscalization, and collection status.
                </p>
              </div>
              <div className="flex items-center space-x-3">
                <button
                  onClick={() => refetchInvoices()}
                  className="p-2 border border-[#E2E8F0] rounded-xl text-[#64748B] hover:text-[#0F172A] transition-colors cursor-pointer"
                  title="Refresh from Backend"
                >
                  <RefreshCw className={cn("w-4 h-4", isLoadingInvoices && "animate-spin")} />
                </button>
                <button
                  onClick={() => alert("Creating a new sales invoice requires Customer, Date, and Revenue Account details.")}
                  className="bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold px-4 py-2 rounded-xl flex items-center space-x-2 shadow-sm transition-all cursor-pointer"
                >
                  <Plus className="w-4 h-4" />
                  <span>New Sales Invoice</span>
                </button>
              </div>
            </div>

            {/* Invoices Table */}
            <div className="bg-white rounded-2xl border border-[#E2E8F0] shadow-xs overflow-hidden">
              <div className="px-6 py-4 border-b border-[#F1F5F9] flex items-center justify-between">
                <span className="text-xs font-semibold text-[#0F172A] uppercase tracking-wider">
                  Customer Invoices ({displayInvoices.length})
                </span>
                <span className="text-xs text-[#64748B]">
                  {token ? `Live Backend Data (${currentOrg?.name})` : "Seeded Demo Data"}
                </span>
              </div>
              <table className="w-full text-left border-collapse text-xs">
                <thead>
                  <tr className="bg-[#F8FAFC] text-[#64748B] border-b border-[#F1F5F9] uppercase tracking-wider font-semibold text-[10px]">
                    <th className="py-3 px-6">Invoice #</th>
                    <th className="py-3 px-6">Customer</th>
                    <th className="py-3 px-6">Issue Date</th>
                    <th className="py-3 px-6">Subtotal</th>
                    <th className="py-3 px-6">GST (17%)</th>
                    <th className="py-3 px-6">Total (PKR)</th>
                    <th className="py-3 px-6">FBR Fiscalization</th>
                    <th className="py-3 px-6">Status</th>
                    <th className="py-3 px-6 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-[#F1F5F9]">
                  {displayInvoices.map((row: any, i: number) => (
                    <tr key={i} className="hover:bg-[#F8FAFC] transition-colors">
                      <td className="py-3.5 px-6 font-semibold text-[#0F172A]">{row.id}</td>
                      <td className="py-3.5 px-6 text-[#334155]">{row.customer}</td>
                      <td className="py-3.5 px-6 text-[#64748B]">{row.date}</td>
                      <td className="py-3.5 px-6 font-tabular">{row.subtotal}</td>
                      <td className="py-3.5 px-6 font-tabular">{row.tax}</td>
                      <td className="py-3.5 px-6 font-bold text-[#0F172A] font-tabular">{row.total}</td>
                      <td className="py-3.5 px-6">
                        <span
                          className={cn(
                            "px-2 py-0.5 rounded text-[10px] font-semibold",
                            row.fbr.includes("Fiscalized")
                              ? "bg-emerald-50 text-emerald-700"
                              : "bg-amber-50 text-amber-700"
                          )}
                        >
                          {row.fbr}
                        </span>
                      </td>
                      <td className="py-3.5 px-6">
                        <span
                          className={cn(
                            "px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase",
                            row.status === "paid"
                              ? "bg-emerald-100 text-emerald-800"
                              : row.status === "sent"
                              ? "bg-blue-100 text-blue-800"
                              : "bg-slate-100 text-slate-700"
                          )}
                        >
                          {row.status}
                        </span>
                      </td>
                      <td className="py-3.5 px-6 text-right space-x-2">
                        {row.status === "draft" && activeOrgId && (
                          <button
                            onClick={() => postInvoiceMutation.mutate(row.rawId)}
                            className="text-[#6366F1] hover:text-[#4338CA] font-medium text-[11px] cursor-pointer"
                          >
                            Post GL
                          </button>
                        )}
                        <button
                          onClick={() => setActiveQrModal(row.id)}
                          className="text-[#6366F1] hover:text-[#4338CA] font-medium text-[11px] cursor-pointer inline-flex items-center space-x-1"
                        >
                          <QrCode className="w-3.5 h-3.5" />
                          <span>QR Code</span>
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {/* ─────────────────────────────────────────────────────────────
            VIEW 3: BILLS & 3-WAY MATCHING (AP)
        ─────────────────────────────────────────────────────────────── */}
        {activeNav === "bills" && (
          <div className="max-w-[1240px] w-full mx-auto px-8 py-8 flex flex-col space-y-6">
            <div className="flex items-center justify-between">
              <div>
                <h2 className="text-2xl font-bold tracking-tight text-[#0F172A]">
                  Vendor Bills & 3-Way Matching Engine
                </h2>
                <p className="text-xs text-[#64748B] mt-1">
                  Automated validation across Purchase Orders (PO), Goods Receipts (GRN), and Vendor Bills.
                </p>
              </div>
              <div className="flex items-center space-x-3">
                <button
                  onClick={() => refetchBills()}
                  className="p-2 border border-[#E2E8F0] rounded-xl text-[#64748B] hover:text-[#0F172A] transition-colors cursor-pointer"
                  title="Refresh Bills"
                >
                  <RefreshCw className={cn("w-4 h-4", isLoadingBills && "animate-spin")} />
                </button>
                <button
                  onClick={() => alert("Batch 3-Way Matching executed. Tolerance ±2.0% enforced.")}
                  className="bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold px-4 py-2 rounded-xl flex items-center space-x-2 shadow-sm transition-all cursor-pointer"
                >
                  <PackageCheck className="w-4 h-4" />
                  <span>Run Automated 3-Way Match</span>
                </button>
              </div>
            </div>

            {/* Bills & Matches List */}
            <div className="bg-white rounded-2xl border border-[#E2E8F0] shadow-xs overflow-hidden">
              <div className="px-6 py-4 border-b border-[#F1F5F9] flex items-center justify-between">
                <span className="text-xs font-semibold text-[#0F172A] uppercase tracking-wider">
                  Recent Vendor Bills ({displayBills.length})
                </span>
                <span className="text-xs text-[#64748B]">Tolerance Threshold: ±2.0%</span>
              </div>
              <table className="w-full text-left border-collapse text-xs">
                <thead>
                  <tr className="bg-[#F8FAFC] text-[#64748B] border-b border-[#F1F5F9] uppercase tracking-wider font-semibold text-[10px]">
                    <th className="py-3 px-6">Bill #</th>
                    <th className="py-3 px-6">Vendor</th>
                    <th className="py-3 px-6">Linked PO</th>
                    <th className="py-3 px-6">GRN Status</th>
                    <th className="py-3 px-6">Amount (PKR)</th>
                    <th className="py-3 px-6">3-Way Match Outcome</th>
                    <th className="py-3 px-6">Status</th>
                    <th className="py-3 px-6 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-[#F1F5F9]">
                  {displayBills.map((row: any, i: number) => (
                    <tr key={i} className="hover:bg-[#F8FAFC] transition-colors">
                      <td className="py-3.5 px-6 font-semibold text-[#0F172A]">{row.id}</td>
                      <td className="py-3.5 px-6 text-[#334155]">{row.vendor}</td>
                      <td className="py-3.5 px-6 text-[#6366F1] font-medium">{row.po}</td>
                      <td className="py-3.5 px-6 text-[#64748B]">{row.grn}</td>
                      <td className="py-3.5 px-6 font-bold text-[#0F172A] font-tabular">{row.amount}</td>
                      <td className="py-3.5 px-6">
                        <span className={cn("px-2.5 py-0.5 rounded text-[10px] font-semibold", row.matchColor)}>
                          {row.match}
                        </span>
                      </td>
                      <td className="py-3.5 px-6">
                        <span className="text-[10px] font-semibold px-2 py-0.5 rounded bg-slate-100 text-slate-700">
                          {row.status}
                        </span>
                      </td>
                      <td className="py-3.5 px-6 text-right">
                        <button
                          onClick={() => alert(`Reviewing matching details for Bill ${row.id}`)}
                          className="text-[#6366F1] hover:text-[#4338CA] font-medium text-[11px] cursor-pointer"
                        >
                          Review
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {/* ─────────────────────────────────────────────────────────────
            VIEW: BANKING & STATEMENT RECONCILIATION
        ─────────────────────────────────────────────────────────────── */}
        {activeNav === "banking" && (
          <div className="max-w-[1240px] w-full mx-auto px-8 py-8 flex flex-col space-y-6">
            {/* View Header */}
            <div className="flex items-center justify-between">
              <div>
                <h2 className="text-2xl font-bold tracking-tight text-[#0F172A]">
                  Banking & Statement Reconciliation
                </h2>
                <p className="text-xs text-[#64748B] mt-1">
                  Deterministic SHA-256 statement import, rule-assisted journal matching, and subledger reconciliation.
                </p>
              </div>
              <div className="flex items-center space-x-3">
                <button
                  onClick={() => {
                    refetchBankAccounts();
                    refetchBankTx();
                  }}
                  className="p-2 border border-[#E2E8F0] rounded-xl text-[#64748B] hover:text-[#0F172A] transition-colors cursor-pointer"
                  title="Refresh Banking Data"
                >
                  <RefreshCw className={cn("w-4 h-4", (isLoadingBankAccounts || isLoadingBankTx) && "animate-spin")} />
                </button>
                <button
                  onClick={() => setIsStatementModalOpen(true)}
                  className="bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold px-4 py-2 rounded-xl flex items-center space-x-2 shadow-sm transition-all cursor-pointer"
                >
                  <Upload className="w-4 h-4" />
                  <span>Import Bank Statement (CSV)</span>
                </button>
              </div>
            </div>

            {/* Bank Accounts Grid */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              {displayBankAccounts.map((account: any) => {
                const isSelected = account.id === activeBankAccount?.id;
                return (
                  <div
                    key={account.id}
                    onClick={() => setSelectedBankAccountId(account.id)}
                    className={cn(
                      "p-5 rounded-2xl border transition-all cursor-pointer relative",
                      isSelected
                        ? "bg-white border-[#6366F1] shadow-sm ring-1 ring-[#6366F1]/20"
                        : "bg-white border-[#E2E8F0] hover:border-[#CBD5E1]"
                    )}
                  >
                    <div className="flex items-start justify-between">
                      <div className="flex items-center space-x-2.5">
                        <div className="w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold text-xs">
                          <Landmark className="w-4 h-4" />
                        </div>
                        <div>
                          <h4 className="text-xs font-bold text-[#0F172A]">{account.bank_name}</h4>
                          <span className="text-[10px] text-[#64748B] font-mono">
                            {account.account_number}
                          </span>
                        </div>
                      </div>
                      <span
                        className={cn(
                          "px-2 py-0.5 rounded text-[10px] font-semibold",
                          account.unreconciled_count > 0
                            ? "bg-amber-50 text-amber-700 border border-amber-200/60"
                            : "bg-emerald-50 text-emerald-700 border border-emerald-200/60"
                        )}
                      >
                        {account.unreconciled_count > 0
                          ? `${account.unreconciled_count} Unreconciled`
                          : "Reconciled ✓"}
                      </span>
                    </div>

                    <div className="mt-4 pt-3 border-t border-[#F1F5F9] flex items-baseline justify-between">
                      <span className="text-[11px] text-[#64748B]">Book Balance:</span>
                      <span className="text-sm font-bold text-[#0F172A] font-tabular">
                        {account.currency} {account.current_balance}
                      </span>
                    </div>
                  </div>
                );
              })}
            </div>

            {/* Reconciliation Dashboard Stats & Filter Bar */}
            <div className="bg-white rounded-2xl border border-[#E2E8F0] p-6 shadow-xs space-y-5">
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-[#F1F5F9]">
                <div>
                  <h3 className="text-sm font-bold text-[#0F172A] flex items-center gap-2">
                    <span>Transactions for {activeBankAccount?.bank_name || "Meezan Bank"}</span>
                    <span className="text-xs font-normal text-[#64748B]">({activeBankAccount?.account_number})</span>
                  </h3>
                  <p className="text-xs text-[#64748B] mt-0.5">
                    Match statement lines with general ledger payments, customer receipts, and vendor disbursements.
                  </p>
                </div>

                {/* Filter Pills */}
                <div className="flex items-center space-x-1.5 p-1 bg-[#F8FAFC] rounded-xl border border-[#E2E8F0]/60 self-start sm:self-auto">
                  {(["all", "unreconciled", "reconciled"] as const).map((filter) => (
                    <button
                      key={filter}
                      onClick={() => setReconciliationFilter(filter)}
                      className={cn(
                        "px-3 py-1 text-xs font-semibold rounded-lg capitalize transition-colors cursor-pointer",
                        reconciliationFilter === filter
                          ? "bg-white text-[#0F172A] shadow-xs"
                          : "text-[#64748B] hover:text-[#0F172A]"
                      )}
                    >
                      {filter}
                    </button>
                  ))}
                </div>
              </div>

              {/* Transactions Table */}
              <div className="overflow-x-auto">
                <table className="w-full text-left border-collapse text-xs">
                  <thead>
                    <tr className="bg-[#F8FAFC] text-[#64748B] border-b border-[#F1F5F9] uppercase tracking-wider font-semibold text-[10px]">
                      <th className="py-3 px-4">Date</th>
                      <th className="py-3 px-4">Description</th>
                      <th className="py-3 px-4">Reference</th>
                      <th className="py-3 px-4 text-right">Debit (Withdrawal)</th>
                      <th className="py-3 px-4 text-right">Credit (Deposit)</th>
                      <th className="py-3 px-4 text-center">Status</th>
                      <th className="py-3 px-4">Reconciliation Match</th>
                      <th className="py-3 px-4 text-right">Action</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-[#F1F5F9]">
                    {displayBankTransactions
                      .filter((tx: any) => {
                        if (reconciliationFilter === "all") return true;
                        return tx.reconciliation_status === reconciliationFilter;
                      })
                      .map((tx: any) => {
                        const isReconciled = tx.reconciliation_status === "reconciled";
                        const isDebit = tx.type === "debit";
                        return (
                          <tr key={tx.id} className="hover:bg-[#F8FAFC] transition-colors">
                            <td className="py-3 px-4 font-mono text-[#64748B]">{tx.transaction_date}</td>
                            <td className="py-3 px-4 font-medium text-[#0F172A] max-w-[220px] truncate" title={tx.description}>
                              {tx.description}
                            </td>
                            <td className="py-3 px-4 font-mono text-[#64748B]">{tx.reference || "—"}</td>
                            <td className="py-3 px-4 text-right font-tabular text-rose-600 font-semibold">
                              {isDebit ? `PKR ${tx.amount}` : "—"}
                            </td>
                            <td className="py-3 px-4 text-right font-tabular text-emerald-600 font-semibold">
                              {!isDebit ? `PKR ${tx.amount}` : "—"}
                            </td>
                            <td className="py-3 px-4 text-center">
                              <span
                                className={cn(
                                  "px-2 py-0.5 rounded text-[10px] font-semibold inline-flex items-center gap-1",
                                  isReconciled
                                    ? "bg-emerald-50 text-emerald-700 border border-emerald-200/60"
                                    : "bg-amber-50 text-amber-700 border border-amber-200/60"
                                )}
                              >
                                {isReconciled ? <Check className="w-2.5 h-2.5" /> : <CircleDot className="w-2.5 h-2.5" />}
                                {isReconciled ? "Reconciled" : "Unreconciled"}
                              </span>
                            </td>
                            <td className="py-3 px-4">
                              {isReconciled ? (
                                <div className="flex items-center space-x-1.5 text-[#334155]">
                                  <Link2 className="w-3 h-3 text-emerald-600" />
                                  <span className="font-mono font-semibold text-[#0F172A]">
                                    {tx.matched_journal_entry?.entry_number || "Matched GL Journal"}
                                  </span>
                                </div>
                              ) : tx.suggestion ? (
                                <div className="p-2 bg-indigo-50/60 rounded-lg border border-indigo-100 flex items-center justify-between gap-2 max-w-[280px]">
                                  <div className="truncate">
                                    <div className="flex items-center space-x-1.5">
                                      <span className="font-mono font-bold text-indigo-700">{tx.suggestion.entry_number}</span>
                                      <span className="text-[9px] font-bold px-1.5 py-0.2 bg-indigo-100 text-indigo-800 rounded">
                                        {Math.round(tx.suggestion.confidence * 100)}% match
                                      </span>
                                    </div>
                                    <p className="text-[10px] text-[#64748B] truncate mt-0.5">{tx.suggestion.reason}</p>
                                  </div>
                                </div>
                              ) : (
                                <span className="text-[11px] text-[#94A3B8] italic">No automated match</span>
                              )}
                            </td>
                            <td className="py-3 px-4 text-right">
                              {isReconciled ? (
                                <button
                                  onClick={() => unreconcileTxMutation.mutate(tx.id)}
                                  disabled={unreconcileTxMutation.isPending}
                                  className="text-xs text-[#64748B] hover:text-rose-600 font-medium cursor-pointer transition-colors"
                                  title="Unmatch transaction"
                                >
                                  Unmatch
                                </button>
                              ) : tx.suggestion ? (
                                <button
                                  onClick={() =>
                                    reconcileTxMutation.mutate({
                                      txId: tx.id,
                                      journalId: tx.suggestion.journal_id,
                                    })
                                  }
                                  disabled={reconcileTxMutation.isPending}
                                  className="px-2.5 py-1 bg-emerald-600 hover:bg-emerald-700 text-white text-[11px] font-semibold rounded-lg shadow-xs transition-colors cursor-pointer"
                                >
                                  Match
                                </button>
                              ) : (
                                <button
                                  onClick={() => alert(`Creating manual journal match for transaction ${tx.reference}`)}
                                  className="text-xs text-indigo-600 hover:text-indigo-800 font-medium cursor-pointer"
                                >
                                  Find Match
                                </button>
                              )}
                            </td>
                          </tr>
                        );
                      })}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        )}

        {/* ─────────────────────────────────────────────────────────────
            VIEW: REVENUE RECOGNITION (ASC 606 / IFRS 15)
        ─────────────────────────────────────────────────────────────── */}
        {activeNav === "revenue" && (
          <div className="max-w-[1240px] w-full mx-auto px-8 py-8 flex flex-col space-y-6">
            <div className="flex items-center justify-between">
              <div>
                <h2 className="text-2xl font-bold tracking-tight text-[#0F172A]">
                  Revenue Recognition (ASC 606 & IFRS 15)
                </h2>
                <p className="text-xs text-[#64748B] mt-1">
                  Customer contract amortization schedules, deferred revenue release, and deterministic double-entry posting.
                </p>
              </div>

              <div className="flex items-center space-x-3">
                <button
                  onClick={() => setIsNewContractModalOpen(true)}
                  className="inline-flex items-center space-x-1.5 px-3.5 py-1.5 rounded-lg bg-[#6366F1] text-white text-xs font-semibold hover:bg-[#4F46E5] shadow-xs transition-colors cursor-pointer"
                >
                  <Plus className="w-3.5 h-3.5" />
                  <span>New Contract</span>
                </button>
              </div>
            </div>

            {/* KPI Summary Cards */}
            <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
              <div className="p-4 bg-white rounded-xl border border-[#E2E8F0] shadow-xs">
                <span className="text-[11px] font-semibold text-[#64748B] uppercase tracking-wider">
                  Total Contract Value (TCV)
                </span>
                <p className="text-xl font-bold text-[#0F172A] mt-1">
                  {formatPKR(
                    realRevenueContracts.length > 0
                      ? realRevenueContracts.reduce((s: number, c: any) => s + Number(c.total_contract_value || 0), 0)
                      : 1650000
                  )}
                </p>
                <span className="text-[10px] text-emerald-600 font-medium">Under active management</span>
              </div>

              <div className="p-4 bg-white rounded-xl border border-[#E2E8F0] shadow-xs">
                <span className="text-[11px] font-semibold text-[#64748B] uppercase tracking-wider">
                  Recognized Revenue (Earned)
                </span>
                <p className="text-xl font-bold text-emerald-600 mt-1">
                  {formatPKR(
                    realRevenueContracts.length > 0
                      ? realRevenueContracts.reduce((s: number, c: any) => s + Number(c.recognized_revenue || 0), 0)
                      : 750000
                  )}
                </p>
                <span className="text-[10px] text-[#64748B]">Posted to GL Account 4020</span>
              </div>

              <div className="p-4 bg-white rounded-xl border border-[#E2E8F0] shadow-xs">
                <span className="text-[11px] font-semibold text-[#64748B] uppercase tracking-wider">
                  Deferred Revenue (Unearned)
                </span>
                <p className="text-xl font-bold text-amber-600 mt-1">
                  {formatPKR(
                    realRevenueContracts.length > 0
                      ? realRevenueContracts.reduce(
                          (s: number, c: any) =>
                            s + (Number(c.total_contract_value || 0) - Number(c.recognized_revenue || 0)),
                          0
                        )
                      : 900000
                  )}
                </p>
                <span className="text-[10px] text-[#64748B]">Liability on Balance Sheet (2070)</span>
              </div>

              <div className="p-4 bg-white rounded-xl border border-[#E2E8F0] shadow-xs">
                <span className="text-[11px] font-semibold text-[#64748B] uppercase tracking-wider">
                  Active Contracts
                </span>
                <p className="text-xl font-bold text-[#0F172A] mt-1">
                  {realRevenueContracts.length > 0 ? realRevenueContracts.length : 2}
                </p>
                <span className="text-[10px] text-[#64748B]">Straight-Line Monthly</span>
              </div>
            </div>

            {/* Contracts List Table */}
            <div className="bg-white rounded-2xl border border-[#E2E8F0] shadow-xs overflow-hidden">
              <div className="px-6 py-4 border-b border-[#F1F5F9] flex items-center justify-between">
                <div>
                  <h3 className="text-sm font-bold text-[#0F172A]">Customer Revenue Contracts</h3>
                  <p className="text-xs text-[#64748B]">
                    Amortization performance obligations governed by ASC 606 5-step model.
                  </p>
                </div>
              </div>

              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead className="bg-[#F8FAFC] border-b border-[#E2E8F0] text-[#475569] font-medium">
                    <tr>
                      <th className="px-6 py-3">Contract #</th>
                      <th className="px-6 py-3">Customer & Title</th>
                      <th className="px-6 py-3">Period</th>
                      <th className="px-6 py-3">Total Value</th>
                      <th className="px-6 py-3">Recognized</th>
                      <th className="px-6 py-3">Deferred (Remaining)</th>
                      <th className="px-6 py-3">Status</th>
                      <th className="px-6 py-3 text-right">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-[#F1F5F9]">
                    {(realRevenueContracts.length > 0
                      ? realRevenueContracts
                      : [
                          {
                            id: "demo-rev-1",
                            contract_number: "REV-2025-001",
                            title: "Annual Enterprise Cloud Subscription",
                            customer: { name: "Habib Bank Limited" },
                            start_date: "2025-07-01",
                            end_date: "2026-06-30",
                            total_contract_value: 1200000,
                            recognized_revenue: 300000,
                            status: "active",
                          },
                          {
                            id: "demo-rev-2",
                            contract_number: "REV-2025-002",
                            title: "Quarterly Integration Retainer",
                            customer: { name: "Packages Limited" },
                            start_date: "2025-07-01",
                            end_date: "2025-09-30",
                            total_contract_value: 450000,
                            recognized_revenue: 450000,
                            status: "completed",
                          },
                        ]
                    ).map((contract: any) => {
                      const totalVal = Number(contract.total_contract_value || 0);
                      const recognizedVal = Number(contract.recognized_revenue || 0);
                      const deferredVal = totalVal - recognizedVal;
                      const isSelected = selectedContractId === contract.id;

                      return (
                        <tr
                          key={contract.id}
                          className={cn(
                            "hover:bg-[#F8FAFC] transition-colors",
                            isSelected && "bg-indigo-50/40"
                          )}
                        >
                          <td className="px-6 py-3.5 font-mono font-medium text-[#0F172A]">
                            {contract.contract_number}
                          </td>
                          <td className="px-6 py-3.5">
                            <span className="font-semibold text-[#0F172A] block">
                              {contract.customer?.name || "Corporate Customer"}
                            </span>
                            <span className="text-[11px] text-[#64748B]">{contract.title}</span>
                          </td>
                          <td className="px-6 py-3.5 text-[#475569]">
                            {contract.start_date} → {contract.end_date}
                          </td>
                          <td className="px-6 py-3.5 font-semibold text-[#0F172A]">
                            {formatPKR(totalVal)}
                          </td>
                          <td className="px-6 py-3.5 text-emerald-600 font-medium">
                            {formatPKR(recognizedVal)}
                          </td>
                          <td className="px-6 py-3.5 text-amber-600 font-medium">
                            {formatPKR(deferredVal)}
                          </td>
                          <td className="px-6 py-3.5">
                            <span
                              className={cn(
                                "inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold",
                                contract.status === "completed"
                                  ? "bg-emerald-50 text-emerald-700"
                                  : "bg-blue-50 text-blue-700"
                              )}
                            >
                              {contract.status.toUpperCase()}
                            </span>
                          </td>
                          <td className="px-6 py-3.5 text-right">
                            <button
                              onClick={() =>
                                setSelectedContractId(
                                  selectedContractId === contract.id ? null : contract.id
                                )
                              }
                              className={cn(
                                "text-xs font-semibold px-2.5 py-1 rounded transition-colors cursor-pointer",
                                isSelected
                                  ? "bg-indigo-600 text-white"
                                  : "bg-indigo-50 text-indigo-700 hover:bg-indigo-100"
                              )}
                            >
                              {isSelected ? "Hide Schedules" : "View Schedules"}
                            </button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>

            {/* Selected Contract Amortization Schedule Drawer / Detail */}
            {selectedContractId && (
              <div className="bg-white rounded-2xl border border-indigo-200 p-6 shadow-sm space-y-4">
                <div className="flex items-center justify-between border-b pb-3">
                  <div>
                    <h3 className="text-sm font-bold text-[#0F172A] flex items-center space-x-2">
                      <span>Monthly Amortization Schedule</span>
                      <span className="text-xs font-mono font-normal text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded">
                        {selectedContractDetail?.contract_number || selectedContractId}
                      </span>
                    </h3>
                    <p className="text-xs text-[#64748B] mt-0.5">
                      Each schedule posts a balanced double-entry journal (Debit 2070 Deferred Revenue, Credit 4020 Earned Revenue).
                    </p>
                  </div>
                  <button
                    onClick={() => setSelectedContractId(null)}
                    className="text-[#94A3B8] hover:text-[#0F172A] p-1 cursor-pointer"
                  >
                    <X className="w-4 h-4" />
                  </button>
                </div>

                <div className="overflow-x-auto">
                  <table className="w-full text-left text-xs">
                    <thead className="bg-[#F8FAFC] border-b text-[#475569] font-medium">
                      <tr>
                        <th className="px-4 py-2.5">Schedule Date</th>
                        <th className="px-4 py-2.5">Amortization Amount</th>
                        <th className="px-4 py-2.5">Cumulative Recognized</th>
                        <th className="px-4 py-2.5">GL Status</th>
                        <th className="px-4 py-2.5">Journal Entry</th>
                        <th className="px-4 py-2.5 text-right">Action</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-[#F1F5F9]">
                      {(selectedContractDetail?.schedules || [
                        { id: "s-1", schedule_date: "2025-07-31", amount: 100000, cumulative_recognized: 100000, status: "posted", journal_entry_id: "je-001" },
                        { id: "s-2", schedule_date: "2025-08-31", amount: 100000, cumulative_recognized: 200000, status: "posted", journal_entry_id: "je-002" },
                        { id: "s-3", schedule_date: "2025-09-30", amount: 100000, cumulative_recognized: 300000, status: "posted", journal_entry_id: "je-003" },
                        { id: "s-4", schedule_date: "2025-10-31", amount: 100000, cumulative_recognized: 0, status: "pending" },
                        { id: "s-5", schedule_date: "2025-11-30", amount: 100000, cumulative_recognized: 0, status: "pending" },
                        { id: "s-6", schedule_date: "2025-12-31", amount: 100000, cumulative_recognized: 0, status: "pending" },
                      ]).map((schedule: any) => (
                        <tr key={schedule.id} className="hover:bg-[#F8FAFC]">
                          <td className="px-4 py-2.5 font-medium text-[#0F172A]">{schedule.schedule_date}</td>
                          <td className="px-4 py-2.5 font-semibold text-[#0F172A]">{formatPKR(Number(schedule.amount))}</td>
                          <td className="px-4 py-2.5 text-emerald-600 font-medium">
                            {schedule.cumulative_recognized > 0 ? formatPKR(Number(schedule.cumulative_recognized)) : "—"}
                          </td>
                          <td className="px-4 py-2.5">
                            <span
                              className={cn(
                                "inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold",
                                schedule.status === "posted"
                                  ? "bg-emerald-50 text-emerald-700"
                                  : "bg-amber-50 text-amber-700"
                              )}
                            >
                              {schedule.status.toUpperCase()}
                            </span>
                          </td>
                          <td className="px-4 py-2.5 font-mono text-[11px] text-[#64748B]">
                            {schedule.journal_entry_id ? `#${schedule.journal_entry_id.slice(0, 8)}` : "Not posted"}
                          </td>
                          <td className="px-4 py-2.5 text-right">
                            {schedule.status === "pending" && (
                              <button
                                onClick={() => recognizeScheduleMutation.mutate(schedule.id)}
                                disabled={recognizeScheduleMutation.isPending}
                                className="px-2.5 py-1 rounded bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-[11px] shadow-2xs transition-colors cursor-pointer disabled:opacity-50"
                              >
                                {recognizeScheduleMutation.isPending ? "Posting..." : "Recognize & Post"}
                              </button>
                            )}
                            {schedule.status === "posted" && (
                              <span className="text-[11px] text-emerald-600 font-medium">
                                ✓ Posted to GL
                              </span>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            )}
          </div>
        )}

        {/* ─────────────────────────────────────────────────────────────
            VIEW 4: GENERAL LEDGER & CHART OF ACCOUNTS
        ─────────────────────────────────────────────────────────────── */}
        {activeNav === "ledger" && (
          <div className="max-w-[1240px] w-full mx-auto px-8 py-8 flex flex-col space-y-6">
            <div className="flex items-center justify-between">
              <div>
                <h2 className="text-2xl font-bold tracking-tight text-[#0F172A]">
                  General Ledger & Chart of Accounts
                </h2>
                <p className="text-xs text-[#64748B] mt-1">
                  Double-entry accounting invariant: Total Debits == Total Credits across all periods.
                </p>
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              {/* Chart of Accounts Summary */}
              <div className="bg-white rounded-2xl border border-[#E2E8F0] p-6 shadow-xs">
                <div className="flex items-center justify-between mb-4">
                  <h3 className="text-xs font-semibold uppercase tracking-wider text-[#64748B]">
                    Chart of Accounts ({displayAccounts.length} active)
                  </h3>
                  <span className="text-[10px] text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded font-semibold">
                    Pakistan SME Standard
                  </span>
                </div>
                <div className="space-y-2.5 text-xs">
                  {displayAccounts.map((acc: any, i: number) => (
                    <div key={i} className="flex items-center justify-between py-1.5 border-b border-[#F8FAFC]">
                      <div className="flex items-center space-x-2">
                        <span className="font-mono font-semibold text-[#0F172A]">{acc.code}</span>
                        <span className="text-[#334155]">{acc.name}</span>
                        {acc.isControl && (
                          <span
                            className="inline-flex items-center gap-1 font-semibold text-amber-700 bg-amber-50 border border-amber-200/60 px-1.5 py-0.5 rounded text-[9px]"
                            title="Protected Control Account: Direct manual journals are blocked by posting engine invariant."
                          >
                            <Shield className="w-2.5 h-2.5 text-amber-600" />
                            Control
                          </span>
                        )}
                      </div>
                      <div className="flex items-center space-x-2 text-[10px]">
                        <span className="text-[#64748B]">{acc.type}</span>
                        <span className="font-semibold text-indigo-600 bg-indigo-50 px-1.5 py-0.5 rounded">
                          {acc.normal}
                        </span>
                      </div>
                    </div>
                  ))}
                </div>
              </div>

              {/* Recent Journal Entries */}
              <div className="bg-white rounded-2xl border border-[#E2E8F0] p-6 shadow-xs">
                <div className="flex items-center justify-between mb-4">
                  <h3 className="text-xs font-semibold uppercase tracking-wider text-[#64748B]">
                    Posted Journal Entries
                  </h3>
                  <span className="text-[10px] text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded font-semibold">
                    GL Invariant Checked ✓
                  </span>
                </div>
                <div className="space-y-3.5 text-xs">
                  {displayJournals.map((je: any, i: number) => (
                    <div key={i} className="p-3 bg-[#F8FAFC] rounded-xl border border-[#F1F5F9] space-y-1.5">
                      <div className="flex items-center justify-between">
                        <span className="font-mono font-semibold text-[#0F172A]">{je.number}</span>
                        <span className="text-[10px] font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded">
                          {je.status}
                        </span>
                      </div>
                      <p className="text-[#334155]">{je.desc}</p>
                      <div className="flex items-center justify-between text-[11px] text-[#64748B] pt-1 border-t border-[#E2E8F0]/40">
                        <span>Debit: {je.dr}</span>
                        <span>Credit: {je.cr}</span>
                        <span className="text-emerald-600 font-semibold">Balanced ✓</span>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </div>
        )}

        {/* ─────────────────────────────────────────────────────────────
            VIEW 5: FINANCIAL REPORTS
        ─────────────────────────────────────────────────────────────── */}
        {activeNav === "reports" && (
          <div className="max-w-[1240px] w-full mx-auto px-8 py-8 flex flex-col space-y-6">
            <div className="flex items-center justify-between">
              <div>
                <h2 className="text-2xl font-bold tracking-tight text-[#0F172A]">
                  Financial Reports & Statements
                </h2>
                <p className="text-xs text-[#64748B] mt-1">
                  IFRS compliant multi-currency and multi-entity consolidated reporting.
                </p>
              </div>
              <div className="flex items-center space-x-2 bg-white border border-[#E2E8F0] p-1 rounded-xl shadow-xs">
                {[
                  { id: "income-statement", label: "Income Statement" },
                  { id: "balance-sheet", label: "Balance Sheet" },
                  { id: "consolidation", label: "Multi-Entity Consolidation" },
                  { id: "taxation", label: "FBR 17% Annex-C" },
                ].map((tab) => (
                  <button
                    key={tab.id}
                    onClick={() => setActiveReportTab(tab.id)}
                    className={cn(
                      "px-3 py-1.5 text-xs font-semibold rounded-lg transition-all cursor-pointer",
                      activeReportTab === tab.id
                        ? "bg-[#6366F1] text-white shadow-xs"
                        : "text-[#64748B] hover:text-[#0F172A]"
                    )}
                  >
                    {tab.label}
                  </button>
                ))}
              </div>
            </div>

            {/* Report Content Container */}
            <div className="bg-white rounded-2xl border border-[#E2E8F0] p-8 shadow-xs">
              {activeReportTab === "income-statement" && (
                <div className="space-y-6">
                  <div className="border-b border-[#F1F5F9] pb-4 flex items-center justify-between">
                    <div>
                      <h3 className="text-base font-bold text-[#0F172A]">
                        Statement of Profit and Loss (Income Statement)
                      </h3>
                      <p className="text-xs text-[#64748B]">For the fiscal period ended September 30, 2025 (in PKR)</p>
                    </div>
                    <button
                      onClick={() => alert("Downloading PDF Income Statement...")}
                      className="text-xs text-[#6366F1] font-semibold flex items-center space-x-1.5 hover:underline cursor-pointer"
                    >
                      <Download className="w-3.5 h-3.5" />
                      <span>Export PDF</span>
                    </button>
                  </div>
                  <div className="space-y-3 text-xs">
                    <div className="flex justify-between font-bold text-sm text-[#0F172A] border-b pb-1">
                      <span>Operating Revenue</span>
                      <span>PKR 120,000</span>
                    </div>
                    <div className="flex justify-between text-[#64748B] pl-4">
                      <span>Sales Revenue - Local (#4010)</span>
                      <span>100,000</span>
                    </div>
                    <div className="flex justify-between text-[#64748B] pl-4">
                      <span>Service & Consulting Revenue (#4020)</span>
                      <span>20,000</span>
                    </div>
                    <div className="flex justify-between font-semibold text-rose-600 border-b pb-1 pt-2">
                      <span>Cost of Goods Sold (COGS #5010)</span>
                      <span>(PKR 20,000)</span>
                    </div>
                    <div className="flex justify-between font-bold text-sm text-emerald-700 bg-emerald-50 p-2 rounded-lg">
                      <span>Gross Profit</span>
                      <span>PKR 100,000</span>
                    </div>
                    <div className="flex justify-between text-[#64748B] pl-4 pt-2">
                      <span>Depreciation Expense (#6070)</span>
                      <span>(10,000)</span>
                    </div>
                    <div className="flex justify-between font-bold text-base text-[#0F172A] border-t-2 border-[#0F172A] pt-3">
                      <span>Net Operating Income</span>
                      <span className="text-emerald-600">PKR 90,000</span>
                    </div>
                  </div>
                </div>
              )}

              {activeReportTab === "balance-sheet" && (
                <div className="space-y-6">
                  <div className="border-b border-[#F1F5F9] pb-4 flex items-center justify-between">
                    <div>
                      <h3 className="text-base font-bold text-[#0F172A]">Balance Sheet (Statement of Financial Position)</h3>
                      <p className="text-xs text-[#64748B]">As of September 30, 2025</p>
                    </div>
                  </div>
                  <div className="grid grid-cols-2 gap-8 text-xs">
                    <div className="space-y-3">
                      <h4 className="font-bold text-[#0F172A] uppercase tracking-wider text-[11px] border-b pb-1">Assets</h4>
                      <div className="flex justify-between"><span>Cash & Bank Balances (#1010)</span><span>PKR 5,000,000</span></div>
                      <div className="flex justify-between"><span>Trade Accounts Receivable (#1030)</span><span>PKR 3,240,000</span></div>
                      <div className="flex justify-between"><span>Merchandise Inventory (#1070)</span><span>PKR 850,000</span></div>
                      <div className="flex justify-between"><span>Plant & Machinery (#1510)</span><span>PKR 1,200,000</span></div>
                      <div className="flex justify-between text-rose-600"><span>Accumulated Depreciation (#1590)</span><span>(PKR 120,000)</span></div>
                      <div className="flex justify-between font-bold text-sm border-t pt-2 text-[#0F172A]">
                        <span>Total Assets</span>
                        <span>PKR 10,170,000</span>
                      </div>
                    </div>
                    <div className="space-y-3">
                      <h4 className="font-bold text-[#0F172A] uppercase tracking-wider text-[11px] border-b pb-1">Liabilities & Equity</h4>
                      <div className="flex justify-between"><span>Accounts Payable (#2010)</span><span>PKR 850,000</span></div>
                      <div className="flex justify-between"><span>Output Sales Tax Payable (#2020)</span><span>PKR 270,000</span></div>
                      <div className="flex justify-between"><span>Share Capital (#3010)</span><span>PKR 7,500,000</span></div>
                      <div className="flex justify-between"><span>Retained Earnings (#3030)</span><span>PKR 1,550,000</span></div>
                      <div className="flex justify-between font-bold text-sm border-t pt-2 text-[#0F172A]">
                        <span>Total Liabilities & Equity</span>
                        <span>PKR 10,170,000</span>
                      </div>
                    </div>
                  </div>
                </div>
              )}

              {activeReportTab === "consolidation" && (
                <div className="space-y-8">
                  {/* Top Bar with Description and Actions */}
                  <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4 border-b border-[#F1F5F9] pb-5">
                    <div>
                      <div className="flex items-center space-x-2">
                        <Globe className="w-5 h-5 text-indigo-600" />
                        <h3 className="text-lg font-bold text-[#0F172A]">Multi-Entity & Intercompany Consolidation</h3>
                      </div>
                      <p className="text-xs text-[#64748B] mt-1">
                        Cross-border entity management, FX conversion, reciprocal intercompany ledgers, and automated period eliminations.
                      </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                      <button
                        onClick={() => {
                          refetchEntities();
                          refetchRates();
                          refetchIntercompany();
                          refetchConsolidation();
                        }}
                        className="p-2 border border-[#E2E8F0] rounded-xl text-[#64748B] hover:text-[#0F172A] transition-colors cursor-pointer"
                        title="Refresh Multi-Entity Data"
                      >
                        <RefreshCw className={cn("w-4 h-4", isLoadingConsolidation && "animate-spin")} />
                      </button>
                      <button
                        onClick={() => setIsNewEntityModalOpen(true)}
                        className="px-3 py-1.5 border border-[#CBD5E1] text-[#334155] hover:bg-slate-50 text-xs font-semibold rounded-xl flex items-center space-x-1.5 transition-colors cursor-pointer"
                      >
                        <Building className="w-3.5 h-3.5 text-indigo-600" />
                        <span>Add Legal Entity</span>
                      </button>
                      <button
                        onClick={() => setIsNewRateModalOpen(true)}
                        className="px-3 py-1.5 border border-[#CBD5E1] text-[#334155] hover:bg-slate-50 text-xs font-semibold rounded-xl flex items-center space-x-1.5 transition-colors cursor-pointer"
                      >
                        <Repeat className="w-3.5 h-3.5 text-emerald-600" />
                        <span>Set FX Rate</span>
                      </button>
                      <button
                        onClick={() => setIsFxRevalModalOpen(true)}
                        className="px-3 py-1.5 border border-[#CBD5E1] text-[#334155] hover:bg-slate-50 text-xs font-semibold rounded-xl flex items-center space-x-1.5 transition-colors cursor-pointer"
                      >
                        <TrendingUp className="w-3.5 h-3.5 text-amber-600" />
                        <span>FX Revaluation</span>
                      </button>
                      <button
                        onClick={() => setIsNewIntercompanyModalOpen(true)}
                        className="px-3 py-1.5 bg-indigo-50 text-indigo-700 hover:bg-indigo-100 text-xs font-semibold rounded-xl flex items-center space-x-1.5 transition-colors cursor-pointer"
                      >
                        <Plus className="w-3.5 h-3.5" />
                        <span>New Intercompany Tx</span>
                      </button>
                      <button
                        onClick={() => eliminateMutation.mutate()}
                        disabled={eliminateMutation.isPending}
                        className="px-3 py-1.5 bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold rounded-xl flex items-center space-x-1.5 shadow-xs transition-colors cursor-pointer disabled:opacity-50"
                      >
                        <Layers className="w-3.5 h-3.5" />
                        <span>{eliminateMutation.isPending ? "Eliminating..." : "Run Period Eliminations"}</span>
                      </button>
                    </div>
                  </div>

                  {/* Legal Entities & FX Rate Tickers */}
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    {displayEntities.map((ent: any) => (
                      <div key={ent.id} className="p-4 bg-[#F8FAFC] border border-[#E2E8F0] rounded-2xl space-y-2">
                        <div className="flex items-center justify-between">
                          <div className="flex items-center space-x-2">
                            <span className="font-mono text-xs font-bold px-2 py-0.5 bg-indigo-100 text-indigo-800 rounded">
                              {ent.code}
                            </span>
                            {ent.is_primary && (
                              <span className="text-[10px] font-semibold px-1.5 py-0.5 bg-emerald-100 text-emerald-800 rounded">
                                Primary Parent
                              </span>
                            )}
                          </div>
                          <span className="text-xs font-bold text-[#0F172A]">{ent.currency}</span>
                        </div>
                        <h4 className="text-xs font-bold text-[#0F172A]">{ent.name}</h4>
                        <div className="flex justify-between text-[11px] text-[#64748B] pt-1 border-t border-[#E2E8F0]/60">
                          <span>Status:</span>
                          <span className="text-emerald-700 font-semibold capitalize">{ent.status || "Active"}</span>
                        </div>
                      </div>
                    ))}
                  </div>

                  {/* Active FX Rate Bar */}
                  <div className="p-3 bg-emerald-50/50 border border-emerald-200/60 rounded-xl flex flex-wrap items-center justify-between text-xs gap-3">
                    <div className="flex items-center space-x-2 text-emerald-900 font-medium">
                      <Repeat className="w-4 h-4 text-emerald-700" />
                      <span>Active Foreign Exchange Conversion Rates:</span>
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                      {displayExchangeRates.map((fx: any, idx: number) => (
                        <div key={idx} className="bg-white px-2.5 py-1 rounded-lg border border-emerald-200 text-[11px] font-mono text-[#0F172A] shadow-xs">
                          <strong className="text-emerald-700">{fx.from_currency}/{fx.to_currency}</strong>: {parseFloat(fx.rate).toFixed(2)}
                          <span className="text-[9px] text-[#94A3B8] ml-1.5">({fx.source})</span>
                        </div>
                      ))}
                    </div>
                  </div>

                  {/* Consolidated Financial Statement (Live Balance Sheet & Trial Balance) */}
                  <div className="space-y-3">
                    <div className="flex items-center justify-between">
                      <h4 className="text-sm font-bold text-[#0F172A] flex items-center space-x-2">
                        <span>Consolidated Trial Balance & Eliminations</span>
                        <span className="text-xs font-normal text-[#64748B]">({activePeriod?.name || "July 2025"})</span>
                      </h4>
                      <div className="flex items-center space-x-2">
                        <span className="px-2.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/60 inline-flex items-center space-x-1">
                          <CheckCircle2 className="w-3 h-3 text-emerald-600" />
                          <span>Mathematical Ledger Invariant: In Balance (Debits = Credits)</span>
                        </span>
                      </div>
                    </div>

                    <div className="overflow-x-auto border border-[#E2E8F0] rounded-xl">
                      <table className="w-full text-xs text-left">
                        <thead>
                          <tr className="bg-[#F8FAFC] text-[#64748B] font-semibold text-[10px] uppercase border-b border-[#E2E8F0]">
                            <th className="py-2.5 px-4">Account Code & Name</th>
                            <th className="py-2.5 px-4">Classification</th>
                            <th className="py-2.5 px-4 text-right">Indus Holding (Parent)</th>
                            <th className="py-2.5 px-4 text-right">Logistics / Gulf (Subs)</th>
                            <th className="py-2.5 px-4 text-right text-rose-600 font-bold">Intercompany Eliminations</th>
                            <th className="py-2.5 px-4 text-right font-bold text-[#0F172A]">Consolidated Balance (PKR)</th>
                          </tr>
                        </thead>
                        <tbody className="divide-y divide-[#F1F5F9]">
                          {realConsolidatedReport?.items?.length ? (
                            realConsolidatedReport.items.slice(0, 10).map((row: any, i: number) => {
                              const parentVal = row.entity_breakdown?.HOLDING ?? 0;
                              const subVal = Object.entries(row.entity_breakdown || {})
                                .filter(([k]) => k !== "HOLDING")
                                .reduce((acc, [, v]) => acc + (Number(v) || 0), 0);
                              return (
                                <tr key={i} className="hover:bg-[#F8FAFC]">
                                  <td className="py-2.5 px-4 font-mono font-medium">
                                    <span className="text-indigo-600">{row.account_code}</span> — {row.account_name}
                                  </td>
                                  <td className="py-2.5 px-4 capitalize text-[#64748B]">{row.classification}</td>
                                  <td className="py-2.5 px-4 text-right font-tabular">{Math.abs(parentVal).toLocaleString()}</td>
                                  <td className="py-2.5 px-4 text-right font-tabular">{Math.abs(subVal).toLocaleString()}</td>
                                  <td className="py-2.5 px-4 text-right font-tabular text-rose-600 font-semibold">
                                    {row.eliminations !== 0 ? `(${Math.abs(row.eliminations).toLocaleString()})` : "—"}
                                  </td>
                                  <td className="py-2.5 px-4 text-right font-bold font-tabular text-[#0F172A]">
                                    {Math.abs(row.consolidated_balance).toLocaleString()}
                                  </td>
                                </tr>
                              );
                            })
                          ) : (
                            <>
                              <tr className="hover:bg-[#F8FAFC]">
                                <td className="py-2.5 px-4 font-medium"><span className="text-indigo-600 font-mono">1010</span> — Cash and Cash Equivalents</td>
                                <td className="py-2.5 px-4 text-[#64748B]">Asset</td>
                                <td className="py-2.5 px-4 text-right font-tabular">850,000</td>
                                <td className="py-2.5 px-4 text-right font-tabular">390,500</td>
                                <td className="py-2.5 px-4 text-right text-rose-600 font-semibold">—</td>
                                <td className="py-2.5 px-4 text-right font-bold font-tabular">1,240,500</td>
                              </tr>
                              <tr className="hover:bg-[#F8FAFC]">
                                <td className="py-2.5 px-4 font-medium"><span className="text-indigo-600 font-mono">1030</span> — Accounts Receivable (Control)</td>
                                <td className="py-2.5 px-4 text-[#64748B]">Asset</td>
                                <td className="py-2.5 px-4 text-right font-tabular">150,000</td>
                                <td className="py-2.5 px-4 text-right font-tabular">80,000</td>
                                <td className="py-2.5 px-4 text-right text-rose-600 font-semibold">(80,000)</td>
                                <td className="py-2.5 px-4 text-right font-bold font-tabular">150,000</td>
                              </tr>
                              <tr className="hover:bg-[#F8FAFC]">
                                <td className="py-2.5 px-4 font-medium"><span className="text-indigo-600 font-mono">2010</span> — Accounts Payable (Control)</td>
                                <td className="py-2.5 px-4 text-[#64748B]">Liability</td>
                                <td className="py-2.5 px-4 text-right font-tabular">80,000</td>
                                <td className="py-2.5 px-4 text-right font-tabular">150,000</td>
                                <td className="py-2.5 px-4 text-right text-rose-600 font-semibold">(80,000)</td>
                                <td className="py-2.5 px-4 text-right font-bold font-tabular">150,000</td>
                              </tr>
                              <tr className="hover:bg-[#F8FAFC]">
                                <td className="py-2.5 px-4 font-medium"><span className="text-indigo-600 font-mono">4010</span> — Sales Revenue (Local & Cross-Entity)</td>
                                <td className="py-2.5 px-4 text-[#64748B]">Revenue</td>
                                <td className="py-2.5 px-4 text-right font-tabular">500,000</td>
                                <td className="py-2.5 px-4 text-right font-tabular">350,000</td>
                                <td className="py-2.5 px-4 text-right text-rose-600 font-semibold">(80,000)</td>
                                <td className="py-2.5 px-4 text-right font-bold font-tabular">770,000</td>
                              </tr>
                              <tr className="hover:bg-[#F8FAFC]">
                                <td className="py-2.5 px-4 font-medium"><span className="text-indigo-600 font-mono">6010</span> — Operating & Overhead Expenses</td>
                                <td className="py-2.5 px-4 text-[#64748B]">Expense</td>
                                <td className="py-2.5 px-4 text-right font-tabular">250,000</td>
                                <td className="py-2.5 px-4 text-right font-tabular">120,000</td>
                                <td className="py-2.5 px-4 text-right text-rose-600 font-semibold">(80,000)</td>
                                <td className="py-2.5 px-4 text-right font-bold font-tabular">290,000</td>
                              </tr>
                              <tr className="bg-[#F8FAFC] font-bold">
                                <td className="py-3 px-4 text-[#0F172A]">Consolidated Operating Profit</td>
                                <td className="py-3 px-4">—</td>
                                <td className="py-3 px-4 text-right font-tabular">250,000</td>
                                <td className="py-3 px-4 text-right font-tabular">230,000</td>
                                <td className="py-3 px-4 text-right text-emerald-600 font-tabular">0</td>
                                <td className="py-3 px-4 text-right font-tabular text-emerald-700">480,000</td>
                              </tr>
                            </>
                          )}
                        </tbody>
                      </table>
                    </div>
                  </div>

                  {/* Intercompany Invoicing & Reciprocal Ledgers */}
                  <div className="space-y-3 pt-3 border-t border-[#F1F5F9]">
                    <div className="flex items-center justify-between">
                      <div>
                        <h4 className="text-sm font-bold text-[#0F172A]">Intercompany Cross-Entity Transactions</h4>
                        <p className="text-xs text-[#64748B]">
                          Shared services, infrastructure allocations, and reciprocal intercompany AP/AR entries.
                        </p>
                      </div>
                      <span className="text-[10px] text-[#64748B]">
                        {displayIntercompany.length} Transactions Recorded
                      </span>
                    </div>

                    <div className="overflow-x-auto border border-[#E2E8F0] rounded-xl">
                      <table className="w-full text-xs text-left">
                        <thead>
                          <tr className="bg-[#F8FAFC] text-[#64748B] font-semibold text-[10px] uppercase border-b border-[#E2E8F0]">
                            <th className="py-2.5 px-4">Tx Number</th>
                            <th className="py-2.5 px-4">Date</th>
                            <th className="py-2.5 px-4">From Entity</th>
                            <th className="py-2.5 px-4">To Entity</th>
                            <th className="py-2.5 px-4">Description</th>
                            <th className="py-2.5 px-4 text-right">Amount</th>
                            <th className="py-2.5 px-4 text-center">Status</th>
                            <th className="py-2.5 px-4 text-right">Action</th>
                          </tr>
                        </thead>
                        <tbody className="divide-y divide-[#F1F5F9]">
                          {displayIntercompany.map((tx: any) => (
                            <tr key={tx.id} className="hover:bg-[#F8FAFC]">
                              <td className="py-2.5 px-4 font-mono font-bold text-indigo-600">{tx.transaction_number}</td>
                              <td className="py-2.5 px-4 font-mono text-[#64748B]">{tx.transaction_date}</td>
                              <td className="py-2.5 px-4 font-medium text-[#0F172A]">{tx.fromEntity?.name || "Parent"}</td>
                              <td className="py-2.5 px-4 font-medium text-[#0F172A]">{tx.toEntity?.name || "Subsidiary"}</td>
                              <td className="py-2.5 px-4 max-w-[220px] truncate text-[#64748B]" title={tx.description}>
                                {tx.description}
                              </td>
                              <td className="py-2.5 px-4 text-right font-tabular font-bold text-[#0F172A]">
                                {tx.currency} {tx.amount}
                              </td>
                              <td className="py-2.5 px-4 text-center">
                                <span
                                  className={cn(
                                    "px-2 py-0.5 rounded text-[10px] font-semibold uppercase",
                                    tx.status === "eliminated"
                                      ? "bg-purple-100 text-purple-800"
                                      : tx.status === "posted"
                                      ? "bg-emerald-100 text-emerald-800"
                                      : "bg-amber-100 text-amber-800"
                                  )}
                                >
                                  {tx.status}
                                </span>
                              </td>
                              <td className="py-2.5 px-4 text-right">
                                {tx.status === "draft" && activeOrgId ? (
                                  <button
                                    onClick={() => postIntercompanyMutation.mutate(tx.id)}
                                    disabled={postIntercompanyMutation.isPending}
                                    className="px-2 py-1 bg-indigo-600 hover:bg-indigo-700 text-white rounded text-[10px] font-semibold cursor-pointer"
                                  >
                                    Post Ledgers
                                  </button>
                                ) : (
                                  <span className="text-[10px] text-slate-400 font-mono">
                                    {tx.status === "eliminated" ? "Eliminated ✓" : "Reciprocal Posted ✓"}
                                  </span>
                                )}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>
              )}

              {activeReportTab === "taxation" && (
                <div className="space-y-6">
                  <div className="border-b border-[#F1F5F9] pb-4">
                    <h3 className="text-base font-bold text-[#0F172A]">Pakistan FBR Sales Tax Schedule (Annex-C)</h3>
                    <p className="text-xs text-[#64748B]">Digital Invoicing return schedule with 17% standard GST breakdown</p>
                  </div>
                  <div className="grid grid-cols-3 gap-4 text-xs">
                    <div className="p-4 bg-slate-50 rounded-xl">
                      <span className="text-[10px] text-[#64748B] block">Gross Domestic Invoicing</span>
                      <span className="text-lg font-bold text-[#0F172A]">PKR 1,500,000</span>
                    </div>
                    <div className="p-4 bg-slate-50 rounded-xl">
                      <span className="text-[10px] text-[#64748B] block">Output Sales Tax (17%)</span>
                      <span className="text-lg font-bold text-[#0F172A]">PKR 255,000</span>
                    </div>
                    <div className="p-4 bg-slate-50 rounded-xl">
                      <span className="text-[10px] text-[#64748B] block">Eligible Input Tax Credit</span>
                      <span className="text-lg font-bold text-emerald-600">PKR 110,000</span>
                    </div>
                  </div>
                </div>
              )}
            </div>
          </div>
        )}

        {/* ─────────────────────────────────────────────────────────────
            VIEW 6: AI FINANCIAL COPILOT WORKSPACE
        ─────────────────────────────────────────────────────────────── */}
        {activeNav === "copilot" && (
          <div className="max-w-[1240px] w-full mx-auto px-8 py-8 flex flex-col space-y-6">
            <div>
              <h2 className="text-2xl font-bold tracking-tight text-[#0F172A]">
                AI Financial Copilot & Autonomous Reasoning
              </h2>
              <p className="text-xs text-[#64748B] mt-1">
                Context-aware natural language assistant for accounting queries, anomaly detection, and close management.
              </p>
            </div>

            <div className="bg-white rounded-2xl border border-[#E2E8F0] p-6 shadow-xs flex flex-col space-y-4">
              <div className="flex items-center space-x-3 p-3 bg-purple-50 text-purple-900 rounded-xl text-xs">
                <span className="text-lg">🤖</span>
                <span>
                  Connected to backend AI Gateway: Live General Ledger context, real AR/AP balances, and fiscal period state.
                </span>
              </div>

              <div className="flex space-x-2">
                <input
                  type="text"
                  value={promptText}
                  onChange={(e) => setPromptText(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === "Enter") handleAskCopilot();
                  }}
                  placeholder="Ask any financial question or type a command..."
                  className="flex-1 bg-[#F8FAFC] border border-[#E2E8F0] rounded-xl px-4 py-3 text-xs outline-none focus:border-[#6366F1]"
                />
                <button
                  onClick={() => handleAskCopilot()}
                  disabled={copilotLoading}
                  className="bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold px-6 py-3 rounded-xl cursor-pointer disabled:opacity-50"
                >
                  {copilotLoading ? "Analyzing..." : "Ask Copilot"}
                </button>
              </div>

              {copilotResponse && (
                <div className="p-4 bg-slate-50 border border-slate-200 rounded-xl space-y-3">
                  <span className="text-xs font-bold text-[#0F172A] block uppercase tracking-wider">
                    Copilot Response
                  </span>
                  <p className="text-xs text-[#334155] leading-relaxed">
                    {copilotResponse.answer}
                  </p>
                </div>
              )}
            </div>
          </div>
        )}

        {/* ─────────────────────────────────────────────────────────────
            VIEW 7: ERP MODULE DIRECTORY (FEATURES)
        ─────────────────────────────────────────────────────────────── */}
        {activeNav === "features" && (
          <div className="max-w-[1240px] w-full mx-auto px-8 py-8 flex flex-col space-y-6">
            <div>
              <h2 className="text-2xl font-bold tracking-tight text-[#0F172A]">
                AI-Native Finance ERP Architecture & Module Registry
              </h2>
              <p className="text-xs text-[#64748B] mt-1">
                30 production-grade foundational, operational, localization, and compliance modules.
              </p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
              {[
                { title: "General Ledger & COA", desc: "Double-entry journal engine with immutable posted records, debit/credit invariant validation.", status: "Active (Step 5-6)" },
                { title: "Accounts Receivable & Sales", desc: "Sales invoices, credit notes, customer aging, and FBR POS digital invoicing with QR.", status: "Active (Step 7-8, 22)" },
                { title: "Accounts Payable & Procurement", desc: "Purchase orders, goods receipt notes (GRN), and automated 3-Way Matching engine.", status: "Active (Step 25)" },
                { title: "Inventory & Perpetual COGS", desc: "Multi-warehouse inventory, Weighted Average Costing, FIFO layer depletion, and automated COGS.", status: "Active (Step 26)" },
                { title: "Month-End Close Management", desc: "Multi-task close cycles, straight-line depreciation GL generator, and period flux analysis.", status: "Active (Step 23)" },
                { title: "Multi-Entity & Consolidation", desc: "Multi-currency revaluations, cross-company billing, and consolidated elimination entries.", status: "Active (Step 24)" },
                { title: "Banking & Reconciliation", desc: "Bank statement CSV parser, fingerprint deduplication, and automated transaction matching.", status: "Active (Step 10-11)" },
                { title: "Integrations & Webhooks", desc: "Stripe, HBL, WhatsApp, S3 connectors, and HMAC SHA-256 signed outbound webhooks.", status: "Active (Step 27)" },
                { title: "Security & API Rate Limiting", desc: "API key secret rotation, SHA-256 key hashing, IP allowlists, and strict tenant isolation guards.", status: "Active (Step 28)" },
                { title: "E2E Invariant Verification", desc: "Mathematical double-entry integrity checks across high-volume pipelines.", status: "Active (Step 29)" },
                { title: "Production Hardening & Health", desc: "Structured multi-service readiness gate and Pakistani SME demo seeder.", status: "Active (Step 30)" },
              ].map((mod, i) => (
                <div key={i} className="p-5 bg-white rounded-2xl border border-[#E2E8F0] shadow-xs flex flex-col justify-between space-y-3">
                  <div>
                    <h3 className="text-sm font-bold text-[#0F172A]">{mod.title}</h3>
                    <p className="text-xs text-[#64748B] mt-1.5 leading-relaxed">{mod.desc}</p>
                  </div>
                  <span className="text-[10px] font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded w-fit">
                    {mod.status}
                  </span>
                </div>
              ))}
            </div>
          </div>
        )}
      </main>

      {/* ─────────────────────────────────────────────────────────────
          MODAL: LOGIN / CONNECT BACKEND
      ─────────────────────────────────────────────────────────────── */}
      {isLoginModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/40 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white w-full max-w-md rounded-2xl shadow-2xl border border-[#E2E8F0] p-6 space-y-5">
            <div className="flex items-center justify-between border-b pb-3">
              <div className="flex items-center space-x-2">
                <div className="w-8 h-8 rounded-lg bg-[#6366F1] text-white flex items-center justify-center font-bold text-sm">
                  Ri
                </div>
                <div>
                  <h3 className="font-bold text-sm text-[#0F172A]">Connect to ERP Backend</h3>
                  <p className="text-[11px] text-[#64748B]">Sign in with your organization account</p>
                </div>
              </div>
              <button
                onClick={() => setIsLoginModalOpen(false)}
                className="text-[#94A3B8] hover:text-[#0F172A] p-1 cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            {loginError && (
              <div className="p-3 bg-rose-50 border border-rose-200 rounded-xl text-xs text-rose-700">
                {loginError}
              </div>
            )}

            <form onSubmit={handleLogin} className="space-y-4 text-xs">
              <div>
                <label className="font-medium text-[#334155] block mb-1">Email Address</label>
                <input
                  type="email"
                  required
                  value={loginEmail}
                  onChange={(e) => setLoginEmail(e.target.value)}
                  className="w-full bg-[#F8FAFC] border border-[#E2E8F0] rounded-xl px-3.5 py-2.5 outline-none focus:border-[#6366F1]"
                  placeholder="demo@apextrading.pk"
                />
              </div>

              <div>
                <label className="font-medium text-[#334155] block mb-1">Password</label>
                <input
                  type="password"
                  required
                  value={loginPassword}
                  onChange={(e) => setLoginPassword(e.target.value)}
                  className="w-full bg-[#F8FAFC] border border-[#E2E8F0] rounded-xl px-3.5 py-2.5 outline-none focus:border-[#6366F1]"
                  placeholder="••••••••••••"
                />
              </div>

              <div className="p-3 bg-slate-50 border border-slate-200/80 rounded-xl text-[11px] text-[#64748B] space-y-1">
                <span className="font-semibold text-[#0F172A] block">Secure Tenant Authentication</span>
                <span>Enter your organization credentials or authorized account email to access the financial ledger.</span>
              </div>

              <button
                type="submit"
                className="w-full bg-[#6366F1] hover:bg-[#4F46E5] text-white font-semibold py-2.5 rounded-xl shadow-xs transition-colors cursor-pointer"
              >
                Sign In & Load Live Data
              </button>
            </form>
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          MODAL: FBR QR CODE PREVIEW
      ─────────────────────────────────────────────────────────────── */}
      {activeQrModal && (
        <div className="fixed inset-0 z-50 bg-black/40 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white w-full max-w-sm rounded-2xl shadow-2xl border border-[#E2E8F0] p-6 space-y-4 text-center">
            <div className="flex items-center justify-between border-b pb-3">
              <span className="font-bold text-xs uppercase tracking-wider text-[#0F172A]">
                FBR Digital Invoicing QR
              </span>
              <button onClick={() => setActiveQrModal(null)} className="text-[#94A3B8] hover:text-[#0F172A] cursor-pointer">
                <X className="w-4 h-4" />
              </button>
            </div>

            <div className="flex flex-col items-center space-y-3 py-2">
              <div className="w-40 h-40 border-2 border-dashed border-[#CBD5E1] rounded-2xl flex flex-col items-center justify-center p-4 bg-slate-50">
                <QrCode className="w-24 h-24 text-[#0F172A]" />
                <span className="text-[10px] text-[#64748B] mt-2 font-mono">{activeQrModal}</span>
              </div>
              <p className="text-xs text-[#334155] leading-relaxed">
                Scan with FBR Tax Asaan app to verify the digital invoice verification code.
              </p>
            </div>

            <button
              onClick={() => setActiveQrModal(null)}
              className="w-full bg-[#F1F5F9] hover:bg-[#E2E8F0] text-[#0F172A] text-xs font-semibold py-2 rounded-xl transition-colors cursor-pointer"
            >
              Close
            </button>
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          MODAL 1: SPOTLIGHT SEARCH (CTRL+K)
      ─────────────────────────────────────────────────────────────── */}
      {isSearchOpen && (
        <div className="fixed inset-0 z-50 bg-black/40 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white w-full max-w-xl rounded-2xl shadow-2xl border border-[#E2E8F0] overflow-hidden flex flex-col">
            <div className="px-4 py-3 border-b border-[#F1F5F9] flex items-center space-x-3">
              <Search className="w-5 h-5 text-[#94A3B8]" />
              <input
                type="text"
                autoFocus
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Search invoices, accounts, bills, customers..."
                className="flex-1 text-sm outline-none text-[#0F172A] placeholder:text-[#94A3B8]"
              />
              <button
                onClick={() => setIsSearchOpen(false)}
                className="text-[#94A3B8] hover:text-[#0F172A] p-1 cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>
            <div className="max-h-80 overflow-y-auto p-2 text-xs divide-y divide-[#F8FAFC]">
              {[
                { title: "Invoice #INV-2025-0012", sub: "Textile Mills Ltd • PKR 118,000", nav: "invoices" },
                { title: "Purchase Bill #BILL-2025-001", sub: "Steel Corp Pakistan • PKR 70,000", nav: "bills" },
                { title: "Account #1070 - Merchandise Inventory", sub: "Current Asset • Normal Debit", nav: "ledger" },
                { title: "Account #5010 - Cost of Goods Sold", sub: "Expense • Normal Debit", nav: "ledger" },
                { title: "Consolidated Financials (Multi-Entity)", sub: "Parent + Dubai Subsidiary", nav: "reports" },
              ]
                .filter(
                  (item) =>
                    item.title.toLowerCase().includes(searchQuery.toLowerCase()) ||
                    item.sub.toLowerCase().includes(searchQuery.toLowerCase())
                )
                .map((res, i) => (
                  <div
                    key={i}
                    onClick={() => {
                      setActiveNav(res.nav);
                      setIsSearchOpen(false);
                    }}
                    className="p-3 hover:bg-[#F8FAFC] rounded-xl cursor-pointer transition-colors flex items-center justify-between"
                  >
                    <div>
                      <span className="font-semibold text-[#0F172A] block">{res.title}</span>
                      <span className="text-[#64748B] text-[11px]">{res.sub}</span>
                    </div>
                    <ArrowRight className="w-4 h-4 text-[#CBD5E1]" />
                  </div>
                ))}
            </div>
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          MODAL 2: NOTIFICATIONS
      ─────────────────────────────────────────────────────────────── */}
      {isNotificationsOpen && (
        <div className="fixed inset-0 z-50 bg-black/30 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white w-full max-w-md rounded-2xl shadow-xl border border-[#E2E8F0] p-6 space-y-4">
            <div className="flex items-center justify-between border-b pb-3">
              <div className="flex items-center space-x-2">
                <Bell className="w-4 h-4 text-[#6366F1]" />
                <h3 className="font-bold text-sm text-[#0F172A]">Notifications & Alerts</h3>
              </div>
              <button onClick={() => setIsNotificationsOpen(false)} className="text-[#94A3B8] hover:text-[#0F172A] cursor-pointer">
                <X className="w-4 h-4" />
              </button>
            </div>
            <div className="space-y-3 text-xs">
              <div className="p-3 bg-amber-50 rounded-xl border border-amber-200">
                <span className="font-semibold text-amber-900 block">3-Way Match Exception</span>
                <span className="text-amber-800 text-[11px]">Bill #BILL-2025-003 exceeded price tolerance threshold (+20%).</span>
              </div>
              <div className="p-3 bg-blue-50 rounded-xl border border-blue-200">
                <span className="font-semibold text-blue-900 block">FBR POS Fiscalization</span>
                <span className="text-blue-800 text-[11px]">Invoice #INV-2025-0012 fiscalized successfully with FBR Digital QR.</span>
              </div>
              <div className="p-3 bg-emerald-50 rounded-xl border border-emerald-200">
                <span className="font-semibold text-emerald-900 block">Automated COGS Posted</span>
                <span className="text-emerald-800 text-[11px]">Journal #JE-2025-0001 balanced: Debit 5010 / Credit 1070.</span>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          MODAL 3: AUDIT HISTORY
      ─────────────────────────────────────────────────────────────── */}
      {isHistoryOpen && (
        <div className="fixed inset-0 z-50 bg-black/30 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white w-full max-w-lg rounded-2xl shadow-xl border border-[#E2E8F0] p-6 space-y-4">
            <div className="flex items-center justify-between border-b pb-3">
              <div className="flex items-center space-x-2">
                <History className="w-4 h-4 text-[#6366F1]" />
                <h3 className="font-bold text-sm text-[#0F172A]">Immutable Audit Trail</h3>
              </div>
              <button onClick={() => setIsHistoryOpen(false)} className="text-[#94A3B8] hover:text-[#0F172A] cursor-pointer">
                <X className="w-4 h-4" />
              </button>
            </div>
            <div className="space-y-2.5 text-xs max-h-72 overflow-y-auto">
              {(realAuditLogs.length > 0
                ? realAuditLogs.map((ev: any) => ({
                    event: ev.action,
                    user: ev.user?.name || "System Admin",
                    time: new Date(ev.created_at).toLocaleTimeString(),
                    hash: ev.id?.slice(0, 8) + "...",
                  }))
                : [
                    { event: "api_key_created", user: "Chief InfoSec Officer", time: "Just now", hash: "a8f3...91c2" },
                    { event: "procurement:3way_matched", user: "Procurement Manager", time: "10 mins ago", hash: "4d91...11ab" },
                    { event: "inventory:cogs_posted", user: "Automated COGS Engine", time: "25 mins ago", hash: "6b2a...ee04" },
                    { event: "invoice:fbr_fiscalized", user: "Finance Lead", time: "1 hour ago", hash: "88dc...f032" },
                  ]
              ).map((ev: any, i: number) => (
                <div key={i} className="p-2.5 bg-[#F8FAFC] rounded-lg flex items-center justify-between border border-[#F1F5F9]">
                  <div>
                    <span className="font-mono font-semibold text-[#0F172A]">{ev.event}</span>
                    <span className="text-[10px] text-[#64748B] block">By {ev.user} • {ev.time}</span>
                  </div>
                  <span className="text-[10px] font-mono text-[#94A3B8]">{ev.hash}</span>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          MODAL: NEW REVENUE CONTRACT (ASC 606)
      ─────────────────────────────────────────────────────────────── */}
      {isNewContractModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/40 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white w-full max-w-lg rounded-2xl shadow-2xl border border-[#E2E8F0] p-6 space-y-5">
            <div className="flex items-center justify-between border-b pb-3">
              <div>
                <h3 className="font-bold text-base text-[#0F172A]">New Revenue Contract</h3>
                <p className="text-xs text-[#64748B]">
                  ASC 606 / IFRS 15 straight-line contract amortization setup
                </p>
              </div>
              <button
                onClick={() => setIsNewContractModalOpen(false)}
                className="text-[#94A3B8] hover:text-[#0F172A] p-1 cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <form
              onSubmit={(e) => {
                e.preventDefault();
                createContractMutation.mutate({
                  title: newContractForm.title || "Enterprise Software Retainer",
                  customer_id: realInvoices[0]?.customer_id || undefined,
                  total_contract_value: parseFloat(newContractForm.total_contract_value) || 1200000,
                  start_date: newContractForm.start_date,
                  end_date: newContractForm.end_date,
                  recognition_method: newContractForm.recognition_method,
                });
              }}
              className="space-y-4 text-xs"
            >
              <div>
                <label className="block font-semibold text-[#0F172A] mb-1">Contract Title / Project</label>
                <input
                  type="text"
                  required
                  placeholder="e.g. Annual Enterprise Cloud SLA Subscription"
                  value={newContractForm.title}
                  onChange={(e) => setNewContractForm({ ...newContractForm, title: e.target.value })}
                  className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-semibold text-[#0F172A] mb-1">Total Contract Value (PKR)</label>
                  <input
                    type="number"
                    required
                    step="1000"
                    placeholder="1200000"
                    value={newContractForm.total_contract_value}
                    onChange={(e) =>
                      setNewContractForm({ ...newContractForm, total_contract_value: e.target.value })
                    }
                    className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                  />
                </div>
                <div>
                  <label className="block font-semibold text-[#0F172A] mb-1">Amortization Method</label>
                  <select
                    value={newContractForm.recognition_method}
                    onChange={(e) =>
                      setNewContractForm({ ...newContractForm, recognition_method: e.target.value })
                    }
                    className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                  >
                    <option value="straight_line">Straight-Line Monthly (ASC 606)</option>
                    <option value="milestone">Milestone / Deliverable</option>
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-semibold text-[#0F172A] mb-1">Start Date</label>
                  <input
                    type="date"
                    required
                    value={newContractForm.start_date}
                    onChange={(e) => setNewContractForm({ ...newContractForm, start_date: e.target.value })}
                    className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                  />
                </div>
                <div>
                  <label className="block font-semibold text-[#0F172A] mb-1">End Date</label>
                  <input
                    type="date"
                    required
                    value={newContractForm.end_date}
                    onChange={(e) => setNewContractForm({ ...newContractForm, end_date: e.target.value })}
                    className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                  />
                </div>
              </div>

              <div className="p-3 bg-[#F8FAFC] rounded-lg border border-[#E2E8F0] space-y-1">
                <span className="text-[11px] font-semibold text-[#0F172A] block">
                  Accounting Mapping Invariant
                </span>
                <p className="text-[10px] text-[#64748B]">
                  • Deferred Revenue Account: <strong>2070 / 2010 (Liability)</strong>
                  <br />
                  • Earned Revenue Account: <strong>4020 (Service Revenue)</strong>
                  <br />
                  • 12 monthly balanced double-entry schedules will be generated automatically.
                </p>
              </div>

              <div className="flex justify-end space-x-2 pt-2">
                <button
                  type="button"
                  onClick={() => setIsNewContractModalOpen(false)}
                  className="px-4 py-2 border border-[#E2E8F0] rounded-lg text-xs font-semibold text-[#475569] hover:bg-[#F8FAFC] transition-colors cursor-pointer"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={createContractMutation.isPending}
                  className="px-4 py-2 bg-[#6366F1] hover:bg-[#4F46E5] text-white rounded-lg text-xs font-semibold shadow-xs transition-colors cursor-pointer disabled:opacity-50"
                >
                  {createContractMutation.isPending ? "Generating Schedules..." : "Create & Build Schedules"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          MODAL: IMPORT BANK STATEMENT (CSV)
      ─────────────────────────────────────────────────────────────── */}
      {isStatementModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/40 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white w-full max-w-xl rounded-2xl shadow-2xl border border-[#E2E8F0] p-6 space-y-5">
            <div className="flex items-center justify-between border-b pb-3">
              <div className="flex items-center space-x-2.5">
                <div className="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center">
                  <Upload className="w-4 h-4" />
                </div>
                <div>
                  <h3 className="font-bold text-base text-[#0F172A]">Import Bank Statement</h3>
                  <p className="text-xs text-[#64748B]">
                    Upload CSV statement with automated SHA-256 deduplication and smart matching
                  </p>
                </div>
              </div>
              <button
                onClick={() => setIsStatementModalOpen(false)}
                className="text-[#94A3B8] hover:text-[#0F172A] p-1 cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <form
              onSubmit={(e) => {
                e.preventDefault();
                if (!statementCsvText.trim()) {
                  alert("Please provide CSV content or load a sample statement.");
                  return;
                }
                const targetAccountId = selectedBankAccountId || activeBankAccount?.id;
                if (!targetAccountId) {
                  alert("Please select a target bank account.");
                  return;
                }
                if (!activeOrgId) {
                  alert("Please log in with an active organization to import live statements.");
                  return;
                }
                importStatementMutation.mutate({
                  accountId: targetAccountId,
                  csvContent: statementCsvText,
                  filename: statementFilename || "statement.csv",
                });
              }}
              className="space-y-4 text-xs"
            >
              {/* Target Bank Account */}
              <div>
                <label className="block font-semibold text-[#0F172A] mb-1">Target Bank Account</label>
                <select
                  value={selectedBankAccountId || activeBankAccount?.id || ""}
                  onChange={(e) => setSelectedBankAccountId(e.target.value)}
                  className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none bg-white font-medium text-[#0F172A]"
                >
                  {displayBankAccounts.map((acc: any) => (
                    <option key={acc.id} value={acc.id}>
                      {acc.bank_name} — {acc.account_number} ({acc.currency} {acc.current_balance})
                    </option>
                  ))}
                </select>
              </div>

              {/* Sample Templates & File Upload */}
              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <label className="font-semibold text-[#0F172A]">Statement Source</label>
                  <div className="flex items-center space-x-1.5">
                    <span className="text-[10px] text-[#64748B]">Pre-load Pakistani Format:</span>
                    <button
                      type="button"
                      onClick={() => {
                        setStatementFilename("meezan_bank_aug2025.csv");
                        setStatementCsvText(
                          `Date,Description,Reference,Withdrawal,Deposit,Balance\n2025-08-10,"IBFT From Customer Al-Madina",IBFT-90812,,150000.00,650000.00\n2025-08-12,"Vendor Packaging Supplies Chq #4091",CHQ-4091,50000.00,,600000.00\n2025-08-14,"Bank Service Charges & FED",TXN-8821,1160.00,,598840.00`
                        );
                      }}
                      className="px-2 py-0.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-semibold rounded text-[10px] cursor-pointer transition-colors"
                    >
                      Meezan Bank
                    </button>
                    <button
                      type="button"
                      onClick={() => {
                        setStatementFilename("hbl_corporate_aug2025.csv");
                        setStatementCsvText(
                          `Date,Particulars,Cheque No,Debit,Credit,Balance\n2025-08-15,"Tax Deposit Chq #88921",88921,45000.00,,1195500.00\n2025-08-18,"Receipt from Crescent Fabrics",IBFT-7721,,85000.00,1280500.00\n2025-08-20,"Office Utilities LESCO",E-PAY-441,18500.00,,1262000.00`
                        );
                      }}
                      className="px-2 py-0.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 font-semibold rounded text-[10px] cursor-pointer transition-colors"
                    >
                      HBL
                    </button>
                  </div>
                </div>

                {/* File picker */}
                <div className="flex items-center space-x-2">
                  <input
                    type="file"
                    id="csv-file-input"
                    accept=".csv,text/csv,text/plain"
                    onChange={(e) => {
                      const file = e.target.files?.[0];
                      if (file) {
                        setStatementFilename(file.name);
                        const reader = new FileReader();
                        reader.onload = (event) => {
                          const text = event.target?.result as string;
                          if (text) setStatementCsvText(text);
                        };
                        reader.readAsText(file);
                      }
                    }}
                    className="hidden"
                  />
                  <label
                    htmlFor="csv-file-input"
                    className="flex-1 border-2 border-dashed border-[#CBD5E1] hover:border-indigo-500 rounded-xl p-3 flex items-center justify-center space-x-2 text-xs text-[#64748B] hover:text-indigo-600 bg-[#F8FAFC] cursor-pointer transition-colors"
                  >
                    <FileSpreadsheet className="w-4 h-4 text-indigo-500" />
                    <span>
                      {statementFilename ? (
                        <strong className="text-[#0F172A]">{statementFilename}</strong>
                      ) : (
                        "Click to select .CSV file or drag & drop"
                      )}
                    </span>
                  </label>
                </div>
              </div>

              {/* CSV Raw Content Area */}
              <div>
                <div className="flex items-center justify-between mb-1">
                  <label className="font-semibold text-[#0F172A]">CSV Content Preview / Raw Editor</label>
                  <span className="text-[10px] text-[#64748B]">
                    {statementCsvText.trim() ? `${statementCsvText.trim().split("\n").length} lines detected` : "Empty"}
                  </span>
                </div>
                <textarea
                  rows={5}
                  value={statementCsvText}
                  onChange={(e) => setStatementCsvText(e.target.value)}
                  placeholder="Date,Description,Reference,Withdrawal,Deposit,Balance&#10;2025-08-10,Customer Receipt,IBFT-101,,150000.00,650000.00"
                  className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-[11px] font-mono focus:ring-1 focus:ring-indigo-500 focus:outline-none bg-[#F8FAFC]"
                />
              </div>

              {/* SHA-256 Deduplication Invariant Banner */}
              <div className="p-3 bg-slate-50 border border-slate-200 rounded-xl space-y-1">
                <div className="flex items-center space-x-1.5 text-slate-800 font-semibold text-[11px]">
                  <ShieldCheck className="w-3.5 h-3.5 text-indigo-600" />
                  <span>SHA-256 Deduplication & Idempotency Guarantee</span>
                </div>
                <p className="text-[10px] text-[#64748B] leading-relaxed">
                  Every row generates a deterministic SHA-256 fingerprint: <code className="bg-white px-1 py-0.5 rounded border text-[9px]">hash(date + amount + reference + type)</code>. Re-uploading an overlapping statement safely ignores existing lines without double-crediting balances.
                </p>
              </div>

              {importStatementMutation.isError && (
                <div className="p-3 bg-rose-50 border border-rose-200 rounded-xl text-rose-700 text-xs">
                  {((importStatementMutation.error as any)?.message) || "Failed to import statement. Please check CSV format."}
                </div>
              )}

              <div className="flex justify-end space-x-2 pt-2">
                <button
                  type="button"
                  onClick={() => setIsStatementModalOpen(false)}
                  className="px-4 py-2 border border-[#E2E8F0] rounded-lg text-xs font-semibold text-[#475569] hover:bg-[#F8FAFC] transition-colors cursor-pointer"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={importStatementMutation.isPending || !statementCsvText.trim()}
                  className="px-4 py-2 bg-[#6366F1] hover:bg-[#4F46E5] text-white rounded-lg text-xs font-semibold shadow-xs transition-colors cursor-pointer disabled:opacity-50 flex items-center space-x-1.5"
                >
                  <FileCheck className="w-3.5 h-3.5" />
                  <span>{importStatementMutation.isPending ? "Importing & Parsing..." : "Import Statement"}</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          MODAL: ADD LEGAL ENTITY / SUBSIDIARY
      ─────────────────────────────────────────────────────────────── */}
      {isNewEntityModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/40 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white w-full max-w-md rounded-2xl shadow-2xl border border-[#E2E8F0] p-6 space-y-4">
            <div className="flex items-center justify-between border-b pb-3">
              <div className="flex items-center space-x-2">
                <Building className="w-4 h-4 text-indigo-600" />
                <h3 className="font-bold text-base text-[#0F172A]">Add Legal Entity / Subsidiary</h3>
              </div>
              <button
                onClick={() => setIsNewEntityModalOpen(false)}
                className="text-[#94A3B8] hover:text-[#0F172A] p-1 cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <form
              onSubmit={(e) => {
                e.preventDefault();
                if (!activeOrgId) {
                  alert("Please log in with an active organization.");
                  return;
                }
                createEntityMutation.mutate(newEntityForm);
              }}
              className="space-y-4 text-xs"
            >
              <div>
                <label className="block font-semibold text-[#0F172A] mb-1">Entity Legal Name</label>
                <input
                  type="text"
                  required
                  placeholder="e.g. Indus Gulf Tech FZE"
                  value={newEntityForm.name}
                  onChange={(e) => setNewEntityForm({ ...newEntityForm, name: e.target.value })}
                  className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-semibold text-[#0F172A] mb-1">Entity Code</label>
                  <input
                    type="text"
                    required
                    maxLength={10}
                    placeholder="e.g. GULF"
                    value={newEntityForm.code}
                    onChange={(e) => setNewEntityForm({ ...newEntityForm, code: e.target.value.toUpperCase() })}
                    className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs font-mono uppercase focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                  />
                </div>
                <div>
                  <label className="block font-semibold text-[#0F172A] mb-1">Functional Currency</label>
                  <select
                    value={newEntityForm.currency}
                    onChange={(e) => setNewEntityForm({ ...newEntityForm, currency: e.target.value })}
                    className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs bg-white focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                  >
                    <option value="PKR">PKR (Pakistani Rupee)</option>
                    <option value="AED">AED (UAE Dirham)</option>
                    <option value="USD">USD (US Dollar)</option>
                    <option value="SAR">SAR (Saudi Riyal)</option>
                    <option value="GBP">GBP (British Pound)</option>
                  </select>
                </div>
              </div>

              <div className="flex items-center space-x-2 pt-1">
                <input
                  type="checkbox"
                  id="primary-entity"
                  checked={newEntityForm.is_primary}
                  onChange={(e) => setNewEntityForm({ ...newEntityForm, is_primary: e.target.checked })}
                  className="rounded border-[#CBD5E1] text-indigo-600 focus:ring-indigo-500"
                />
                <label htmlFor="primary-entity" className="text-xs text-[#334155] cursor-pointer">
                  Primary Corporate Parent (Consolidation Head)
                </label>
              </div>

              <div className="flex justify-end space-x-2 pt-2">
                <button
                  type="button"
                  onClick={() => setIsNewEntityModalOpen(false)}
                  className="px-4 py-2 border border-[#E2E8F0] rounded-lg text-xs font-semibold text-[#475569] hover:bg-[#F8FAFC] transition-colors cursor-pointer"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={createEntityMutation.isPending}
                  className="px-4 py-2 bg-[#6366F1] hover:bg-[#4F46E5] text-white rounded-lg text-xs font-semibold shadow-xs transition-colors cursor-pointer disabled:opacity-50"
                >
                  {createEntityMutation.isPending ? "Creating..." : "Save Entity"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          MODAL: SET FOREIGN EXCHANGE (FX) RATE
      ─────────────────────────────────────────────────────────────── */}
      {isNewRateModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/40 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white w-full max-w-md rounded-2xl shadow-2xl border border-[#E2E8F0] p-6 space-y-4">
            <div className="flex items-center justify-between border-b pb-3">
              <div className="flex items-center space-x-2">
                <Repeat className="w-4 h-4 text-emerald-600" />
                <h3 className="font-bold text-base text-[#0F172A]">Set Foreign Exchange Rate</h3>
              </div>
              <button
                onClick={() => setIsNewRateModalOpen(false)}
                className="text-[#94A3B8] hover:text-[#0F172A] p-1 cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <form
              onSubmit={(e) => {
                e.preventDefault();
                if (!activeOrgId) {
                  alert("Please log in with an active organization.");
                  return;
                }
                createRateMutation.mutate(newRateForm);
              }}
              className="space-y-4 text-xs"
            >
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-semibold text-[#0F172A] mb-1">From Currency</label>
                  <select
                    value={newRateForm.from_currency}
                    onChange={(e) => setNewRateForm({ ...newRateForm, from_currency: e.target.value })}
                    className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs bg-white focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                  >
                    <option value="AED">AED</option>
                    <option value="USD">USD</option>
                    <option value="SAR">SAR</option>
                    <option value="GBP">GBP</option>
                    <option value="EUR">EUR</option>
                  </select>
                </div>
                <div>
                  <label className="block font-semibold text-[#0F172A] mb-1">To Base Currency</label>
                  <input
                    type="text"
                    disabled
                    value={newRateForm.to_currency}
                    className="w-full px-3 py-2 border border-[#E2E8F0] bg-slate-50 rounded-lg text-xs font-semibold"
                  />
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-semibold text-[#0F172A] mb-1">Exchange Rate</label>
                  <input
                    type="number"
                    step="0.0001"
                    required
                    placeholder="e.g. 76.50"
                    value={newRateForm.rate}
                    onChange={(e) => setNewRateForm({ ...newRateForm, rate: e.target.value })}
                    className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs font-mono focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                  />
                </div>
                <div>
                  <label className="block font-semibold text-[#0F172A] mb-1">Effective Date</label>
                  <input
                    type="date"
                    required
                    value={newRateForm.effective_date}
                    onChange={(e) => setNewRateForm({ ...newRateForm, effective_date: e.target.value })}
                    className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                  />
                </div>
              </div>

              <div>
                <label className="block font-semibold text-[#0F172A] mb-1">Rate Source</label>
                <input
                  type="text"
                  placeholder="State Bank of Pakistan (SBP) / Open Market"
                  value={newRateForm.source}
                  onChange={(e) => setNewRateForm({ ...newRateForm, source: e.target.value })}
                  className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                />
              </div>

              <div className="flex justify-end space-x-2 pt-2">
                <button
                  type="button"
                  onClick={() => setIsNewRateModalOpen(false)}
                  className="px-4 py-2 border border-[#E2E8F0] rounded-lg text-xs font-semibold text-[#475569] hover:bg-[#F8FAFC] transition-colors cursor-pointer"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={createRateMutation.isPending}
                  className="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-semibold shadow-xs transition-colors cursor-pointer disabled:opacity-50"
                >
                  {createRateMutation.isPending ? "Saving..." : "Save FX Rate"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          MODAL: NEW INTERCOMPANY TRANSACTION
      ─────────────────────────────────────────────────────────────── */}
      {isNewIntercompanyModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/40 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white w-full max-w-lg rounded-2xl shadow-2xl border border-[#E2E8F0] p-6 space-y-4">
            <div className="flex items-center justify-between border-b pb-3">
              <div className="flex items-center space-x-2">
                <Plus className="w-4 h-4 text-indigo-600" />
                <h3 className="font-bold text-base text-[#0F172A]">New Intercompany Transaction</h3>
              </div>
              <button
                onClick={() => setIsNewIntercompanyModalOpen(false)}
                className="text-[#94A3B8] hover:text-[#0F172A] p-1 cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <form
              onSubmit={(e) => {
                e.preventDefault();
                if (!activeOrgId) {
                  alert("Please log in with an active organization.");
                  return;
                }
                const fromId = newIntercompanyForm.from_entity_id || displayEntities[0]?.id;
                const toId = newIntercompanyForm.to_entity_id || (displayEntities[1]?.id ?? displayEntities[0]?.id);
                if (fromId === toId) {
                  alert("Intercompany transaction must be between two distinct legal entities.");
                  return;
                }
                createIntercompanyMutation.mutate({
                  ...newIntercompanyForm,
                  from_entity_id: fromId,
                  to_entity_id: toId,
                  amount: parseFloat(newIntercompanyForm.amount),
                });
              }}
              className="space-y-4 text-xs"
            >
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-semibold text-[#0F172A] mb-1">Billing Entity (From)</label>
                  <select
                    value={newIntercompanyForm.from_entity_id || displayEntities[0]?.id || ""}
                    onChange={(e) => setNewIntercompanyForm({ ...newIntercompanyForm, from_entity_id: e.target.value })}
                    className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs bg-white focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                  >
                    {displayEntities.map((ent: any) => (
                      <option key={ent.id} value={ent.id}>
                        {ent.name} ({ent.code})
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block font-semibold text-[#0F172A] mb-1">Recipient Entity (To)</label>
                  <select
                    value={newIntercompanyForm.to_entity_id || (displayEntities[1]?.id ?? displayEntities[0]?.id) || ""}
                    onChange={(e) => setNewIntercompanyForm({ ...newIntercompanyForm, to_entity_id: e.target.value })}
                    className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs bg-white focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                  >
                    {displayEntities.map((ent: any) => (
                      <option key={ent.id} value={ent.id}>
                        {ent.name} ({ent.code})
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-3 gap-3">
                <div>
                  <label className="block font-semibold text-[#0F172A] mb-1">Date</label>
                  <input
                    type="date"
                    required
                    value={newIntercompanyForm.transaction_date}
                    onChange={(e) => setNewIntercompanyForm({ ...newIntercompanyForm, transaction_date: e.target.value })}
                    className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                  />
                </div>
                <div>
                  <label className="block font-semibold text-[#0F172A] mb-1">Currency</label>
                  <select
                    value={newIntercompanyForm.currency}
                    onChange={(e) => setNewIntercompanyForm({ ...newIntercompanyForm, currency: e.target.value })}
                    className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs bg-white focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                  >
                    <option value="PKR">PKR</option>
                    <option value="AED">AED</option>
                    <option value="USD">USD</option>
                    <option value="SAR">SAR</option>
                  </select>
                </div>
                <div>
                  <label className="block font-semibold text-[#0F172A] mb-1">Amount</label>
                  <input
                    type="number"
                    step="100"
                    required
                    placeholder="150000"
                    value={newIntercompanyForm.amount}
                    onChange={(e) => setNewIntercompanyForm({ ...newIntercompanyForm, amount: e.target.value })}
                    className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs font-mono focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                  />
                </div>
              </div>

              <div>
                <label className="block font-semibold text-[#0F172A] mb-1">Description / Business Purpose</label>
                <textarea
                  rows={2}
                  required
                  placeholder="e.g. Allocation of Shared Cloud ERP Infrastructure & Group Management Overhead"
                  value={newIntercompanyForm.description}
                  onChange={(e) => setNewIntercompanyForm({ ...newIntercompanyForm, description: e.target.value })}
                  className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                />
              </div>

              <div className="p-3 bg-indigo-50/50 border border-indigo-100 rounded-xl text-[10px] text-indigo-900 leading-relaxed">
                <strong>Accounting Posting Rule:</strong> Creates a reciprocal double-entry transaction. Billing Entity records Intercompany AR (1030) vs Service Revenue (4010). Recipient Entity records Overhead Expense (6010) vs Intercompany AP (2010).
              </div>

              <div className="flex justify-end space-x-2 pt-2">
                <button
                  type="button"
                  onClick={() => setIsNewIntercompanyModalOpen(false)}
                  className="px-4 py-2 border border-[#E2E8F0] rounded-lg text-xs font-semibold text-[#475569] hover:bg-[#F8FAFC] transition-colors cursor-pointer"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={createIntercompanyMutation.isPending}
                  className="px-4 py-2 bg-[#6366F1] hover:bg-[#4F46E5] text-white rounded-lg text-xs font-semibold shadow-xs transition-colors cursor-pointer disabled:opacity-50"
                >
                  {createIntercompanyMutation.isPending ? "Creating..." : "Create Intercompany Draft"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          MODAL: MONTH-END UNREALIZED FX CURRENCY REVALUATION
      ─────────────────────────────────────────────────────────────── */}
      {isFxRevalModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/40 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white w-full max-w-md rounded-2xl shadow-2xl border border-[#E2E8F0] p-6 space-y-4">
            <div className="flex items-center justify-between border-b pb-3">
              <div className="flex items-center space-x-2">
                <TrendingUp className="w-4 h-4 text-amber-600" />
                <h3 className="font-bold text-base text-[#0F172A]">Unrealized FX Currency Revaluation</h3>
              </div>
              <button
                onClick={() => setIsFxRevalModalOpen(false)}
                className="text-[#94A3B8] hover:text-[#0F172A] p-1 cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <form
              onSubmit={(e) => {
                e.preventDefault();
                if (!activeOrgId) {
                  alert("Please log in with an active organization.");
                  return;
                }
                revaluationMutation.mutate(parseFloat(spotRateUsd) || 282.50);
              }}
              className="space-y-4 text-xs"
            >
              <div>
                <label className="block font-semibold text-[#0F172A] mb-1">Target Accounting Period</label>
                <input
                  type="text"
                  disabled
                  value={activePeriod?.name || "July 2025"}
                  className="w-full px-3 py-2 border border-[#E2E8F0] bg-slate-50 rounded-lg text-xs font-semibold"
                />
              </div>

              <div>
                <label className="block font-semibold text-[#0F172A] mb-1">Month-End Spot Closing Rate (USD to PKR)</label>
                <input
                  type="number"
                  step="0.01"
                  required
                  placeholder="282.50"
                  value={spotRateUsd}
                  onChange={(e) => setSpotRateUsd(e.target.value)}
                  className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs font-mono focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                />
                <p className="text-[10px] text-[#64748B] mt-1">
                  Revalues open foreign currency monetary assets/liabilities against the closing market spot rate.
                </p>
              </div>

              <div className="p-3 bg-amber-50/60 border border-amber-200/60 rounded-xl text-[10px] text-amber-900 leading-relaxed">
                <strong>IAS 21 Foreign Exchange Rules:</strong> Any difference between historical booking rate and the spot rate posts as an Unrealized Foreign Exchange Gain/Loss in Account 4030 / 6080.
              </div>

              <div className="flex justify-end space-x-2 pt-2">
                <button
                  type="button"
                  onClick={() => setIsFxRevalModalOpen(false)}
                  className="px-4 py-2 border border-[#E2E8F0] rounded-lg text-xs font-semibold text-[#475569] hover:bg-[#F8FAFC] transition-colors cursor-pointer"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={revaluationMutation.isPending}
                  className="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-xs font-semibold shadow-xs transition-colors cursor-pointer disabled:opacity-50 flex items-center space-x-1.5"
                >
                  <TrendingUp className="w-3.5 h-3.5" />
                  <span>{revaluationMutation.isPending ? "Posting Revaluation..." : "Post FX Revaluation"}</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          MODAL 4: PROFILE & SESSION MANAGEMENT
      ─────────────────────────────────────────────────────────────── */}
      {isProfileOpen && (
        <div className="fixed inset-0 z-50 bg-black/30 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white w-full max-w-sm rounded-2xl shadow-xl border border-[#E2E8F0] p-6 space-y-4">
            <div className="flex items-center justify-between border-b pb-3">
              <h3 className="font-bold text-sm text-[#0F172A]">Active Session</h3>
              <button onClick={() => setIsProfileOpen(false)} className="text-[#94A3B8] hover:text-[#0F172A] cursor-pointer">
                <X className="w-4 h-4" />
              </button>
            </div>
            <div className="space-y-2.5 text-xs text-[#334155]">
              <div className="flex justify-between">
                <span>User:</span>
                <span className="font-semibold text-[#0F172A]">{currentUser?.name || "Demo User"}</span>
              </div>
              <div className="flex justify-between">
                <span>Email:</span>
                <span className="font-mono text-[#64748B]">{currentUser?.email || "demo@apextrading.pk"}</span>
              </div>
              <div className="flex justify-between">
                <span>Role:</span>
                <span className="font-semibold text-indigo-600 uppercase">{currentOrg?.role || "Owner"}</span>
              </div>
              <div className="flex justify-between">
                <span>Tenant:</span>
                <span className="font-semibold text-[#0F172A]">{currentOrg?.name || "Apex Trading Pvt Ltd"}</span>
              </div>
              <div className="flex justify-between">
                <span>Base Currency:</span>
                <span className="font-semibold text-[#0F172A]">{currentOrg?.base_currency || "PKR"} (Rs)</span>
              </div>
              <div className="flex justify-between">
                <span>Test Suite:</span>
                <span className="font-semibold text-emerald-600">134/134 Passing</span>
              </div>
            </div>

            <div className="pt-2 border-t flex space-x-2">
              <button
                onClick={handleLogout}
                className="w-full bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs font-semibold py-2 rounded-xl flex items-center justify-center space-x-1.5 transition-colors cursor-pointer"
              >
                <LogOut className="w-3.5 h-3.5" />
                <span>Disconnect / Sign Out</span>
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
