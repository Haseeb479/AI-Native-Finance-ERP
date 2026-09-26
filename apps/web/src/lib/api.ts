/**
 * Client-side API abstraction for the AI-Native Finance ERP backend.
 * Provides authentication, token handling, tenant organization context,
 * and typed wrappers for all ERP modules.
 */

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL || "http://localhost:8000/api/v1";

export interface UserProfile {
  id: string;
  name: string;
  email: string;
  role?: string;
}

export interface OrganizationSummary {
  id: string;
  name: string;
  legal_name?: string;
  base_currency: string;
  ntn?: string;
  strn?: string;
  role: string;
  is_default: boolean;
}

export interface AuthSession {
  token: string;
  user: UserProfile;
  organization?: OrganizationSummary;
}

// Storage helpers
const TOKEN_KEY = "erp_auth_token";
const ORG_KEY = "erp_current_org";
const USER_KEY = "erp_user_profile";

export function getStoredToken(): string | null {
  if (typeof window === "undefined") return null;
  return localStorage.getItem(TOKEN_KEY);
}

export function getStoredOrg(): OrganizationSummary | null {
  if (typeof window === "undefined") return null;
  const raw = localStorage.getItem(ORG_KEY);
  if (!raw) return null;
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

export function getStoredUser(): UserProfile | null {
  if (typeof window === "undefined") return null;
  const raw = localStorage.getItem(USER_KEY);
  if (!raw) return null;
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

export function setStoredSession(token: string, user: UserProfile, org?: OrganizationSummary) {
  if (typeof window === "undefined") return;
  localStorage.setItem(TOKEN_KEY, token);
  localStorage.setItem(USER_KEY, JSON.stringify(user));
  if (org) {
    localStorage.setItem(ORG_KEY, JSON.stringify(org));
  }
}

export function clearStoredSession() {
  if (typeof window === "undefined") return;
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(ORG_KEY);
  localStorage.removeItem(USER_KEY);
}

// Low-level fetch wrapper with auth header injection
export async function apiFetch<T = any>(
  endpoint: string,
  options: RequestInit = {}
): Promise<{ data: T; meta?: any; errors?: any[] }> {
  const token = getStoredToken();
  const org = getStoredOrg();

  const headers: Record<string, string> = {
    "Content-Type": "application/json",
    Accept: "application/json",
    ...(options.headers as Record<string, string>),
  };

  if (token) {
    headers["Authorization"] = `Bearer ${token}`;
  }

  if (org?.id) {
    headers["X-Organization-Id"] = org.id;
  }

  const url = endpoint.startsWith("http") ? endpoint : `${API_BASE_URL}${endpoint}`;

  const res = await fetch(url, {
    ...options,
    headers,
  });

  const json = await res.json().catch(() => null);

  if (!res.ok) {
    if (res.status >= 500) {
      throw new Error("ERP server unavailable. Start PostgreSQL and the Laravel API, then try again.");
    }
    const errorMsg = json?.errors?.[0]?.message || json?.errors?.[0] || res.statusText || "Request failed";
    throw new Error(errorMsg);
  }

  return json;
}

// ─────────────────────────────────────────────────────────────
// Typed API Endpoints
// ─────────────────────────────────────────────────────────────

export const erpApi = {
  // 1. Health & Readiness
  getHealth: async () => {
    return apiFetch("/health");
  },
  getProductionReadiness: async () => {
    return apiFetch("/health/production-readiness");
  },

  // 2. Auth & Session
  login: async (email: string, password: string) => {
    const res = await apiFetch("/auth/login", {
      method: "POST",
      body: JSON.stringify({ email, password, device_name: "web_dashboard" }),
    });
    return res.data;
  },

  getMe: async () => {
    const res = await apiFetch("/auth/me");
    return res.data;
  },

  logout: async () => {
    try {
      await apiFetch("/auth/logout", { method: "POST" });
    } finally {
      clearStoredSession();
    }
  },

  // 3. Organizations
  getOrganizations: async () => {
    const res = await apiFetch<{ organizations: OrganizationSummary[] }>("/organizations");
    return res.data?.organizations || [];
  },

  // 4. Sales Invoices
  getInvoices: async (orgId: string, params?: { status?: string }) => {
    const query = new URLSearchParams(params as any).toString();
    const res = await apiFetch<any[]>(`/organizations/${orgId}/invoices${query ? `?${query}` : ""}`);
    return res.data || [];
  },

  createInvoice: async (orgId: string, invoiceData: any) => {
    const res = await apiFetch(`/organizations/${orgId}/invoices`, {
      method: "POST",
      body: JSON.stringify(invoiceData),
    });
    return res.data;
  },

  postInvoice: async (orgId: string, invoiceId: string) => {
    const res = await apiFetch(`/organizations/${orgId}/invoices/${invoiceId}/post`, {
      method: "POST",
    });
    return res.data;
  },

  fiscalizeInvoice: async (orgId: string, invoiceId: string) => {
    const res = await apiFetch(`/organizations/${orgId}/invoices/${invoiceId}/fbr-fiscalize`, {
      method: "POST",
    });
    return res.data;
  },

  submitInvoiceForApproval: async (orgId: string, invoiceId: string) => {
    const res = await apiFetch(`/organizations/${orgId}/invoices/${invoiceId}/submit`, {
      method: "POST",
    });
    return res.data;
  },

  approveInvoice: async (orgId: string, invoiceId: string) => {
    const res = await apiFetch(`/organizations/${orgId}/invoices/${invoiceId}/approve`, {
      method: "POST",
    });
    return res.data;
  },

  rejectInvoice: async (orgId: string, invoiceId: string, reason: string) => {
    const res = await apiFetch(`/organizations/${orgId}/invoices/${invoiceId}/reject`, {
      method: "POST",
      body: JSON.stringify({ reason }),
    });
    return res.data;
  },

  // 5. Vendor Bills & 3-Way Matching
  getBills: async (orgId: string) => {
    const res = await apiFetch<any[]>(`/organizations/${orgId}/bills`);
    return res.data || [];
  },

  submitBillForApproval: async (orgId: string, billId: string) => {
    const res = await apiFetch(`/organizations/${orgId}/bills/${billId}/submit`, {
      method: "POST",
    });
    return res.data;
  },

  approveBill: async (orgId: string, billId: string) => {
    const res = await apiFetch(`/organizations/${orgId}/bills/${billId}/approve`, {
      method: "POST",
    });
    return res.data;
  },

  rejectBill: async (orgId: string, billId: string, reason: string) => {
    const res = await apiFetch(`/organizations/${orgId}/bills/${billId}/reject`, {
      method: "POST",
      body: JSON.stringify({ reason }),
    });
    return res.data;
  },

  getThreeWayMatches: async (orgId: string) => {
    const res = await apiFetch<any[]>(`/organizations/${orgId}/procurement/3way-matches`);
    return res.data || [];
  },

  waiveThreeWayMatch: async (orgId: string, matchId: string, reason: string) => {
    const res = await apiFetch(`/organizations/${orgId}/procurement/3way-match/${matchId}/waive`, {
      method: "POST",
      body: JSON.stringify({ reason }),
    });
    return res.data;
  },

  // 6. Chart of Accounts & General Ledger
  getAccounts: async (orgId: string) => {
    const res = await apiFetch<any[]>(`/organizations/${orgId}/accounts`);
    return res.data || [];
  },

  getJournals: async (orgId: string) => {
    const res = await apiFetch<any[]>(`/organizations/${orgId}/journals`);
    return res.data || [];
  },

  // 7. Financial Reports
  getTrialBalance: async (orgId: string, asOf?: string) => {
    const q = asOf ? `?as_of=${asOf}` : "";
    const res = await apiFetch(`/organizations/${orgId}/reports/trial-balance${q}`);
    return res.data;
  },

  getProfitAndLoss: async (orgId: string, from: string, to: string) => {
    const res = await apiFetch(`/organizations/${orgId}/reports/profit-and-loss?from=${from}&to=${to}`);
    return res.data;
  },

  getBalanceSheet: async (orgId: string, asOf?: string) => {
    const q = asOf ? `?as_of=${asOf}` : "";
    const res = await apiFetch(`/organizations/${orgId}/reports/balance-sheet${q}`);
    return res.data;
  },

  getGeneralLedgerReport: async (orgId: string, from: string, to: string, accountId?: string) => {
    const q = accountId ? `&account_id=${accountId}` : "";
    const res = await apiFetch(`/organizations/${orgId}/reports/general-ledger?from=${from}&to=${to}${q}`);
    return res.data;
  },

  getArAgingReport: async (orgId: string, asOf?: string) => {
    const q = asOf ? `?as_of=${asOf}` : "";
    const res = await apiFetch(`/organizations/${orgId}/reports/ar-aging${q}`);
    return res.data;
  },

  getApAgingReport: async (orgId: string, asOf?: string) => {
    const q = asOf ? `?as_of=${asOf}` : "";
    const res = await apiFetch(`/organizations/${orgId}/reports/ap-aging${q}`);
    return res.data;
  },

  // 8. AI Copilot
  askCopilot: async (orgId: string, query: string, context?: any) => {
    const res = await apiFetch(`/organizations/${orgId}/ai/copilot/qa`, {
      method: "POST",
      body: JSON.stringify({ query, financial_context: context || {} }),
    });
    return res.data;
  },

  // 9. Close Management & Audit Trail
  getCloseCycle: async (orgId: string, periodId: string) => {
    const res = await apiFetch(`/organizations/${orgId}/close-cycles/${periodId}`);
    return res.data;
  },

  getPeriods: async (orgId: string) => {
    const res = await apiFetch<any[]>(`/organizations/${orgId}/periods`);
    return res.data || [];
  },

  softClosePeriod: async (orgId: string, periodId: string) => {
    const res = await apiFetch(`/organizations/${orgId}/periods/${periodId}/soft-close`, {
      method: "POST",
    });
    return res.data;
  },

  hardClosePeriod: async (orgId: string, periodId: string) => {
    const res = await apiFetch(`/organizations/${orgId}/periods/${periodId}/hard-close`, {
      method: "POST",
    });
    return res.data;
  },

  reopenPeriod: async (orgId: string, periodId: string, reason: string) => {
    const res = await apiFetch(`/organizations/${orgId}/periods/${periodId}/reopen`, {
      method: "POST",
      body: JSON.stringify({ reason }),
    });
    return res.data;
  },

  getFluxAnalysis: async (orgId: string, periodId: string, priorPeriodId?: string) => {
    const q = priorPeriodId ? `?prior_period_id=${priorPeriodId}` : "";
    const res = await apiFetch(`/organizations/${orgId}/close-cycles/${periodId}/flux-analysis${q}`);
    return res.data;
  },

  createJournal: async (orgId: string, data: any) => {
    const res = await apiFetch(`/organizations/${orgId}/journals`, {
      method: "POST",
      body: JSON.stringify(data),
    });
    return res.data;
  },

  getAuditLogs: async (orgId: string) => {
    const res = await apiFetch<any[]>(`/organizations/${orgId}/audit-logs`);
    return res.data || [];
  },

  verifyAuditTrail: async (orgId: string) => {
    const res = await apiFetch(`/organizations/${orgId}/audit-logs/verify`, {
      method: "POST",
    });
    return res.data;
  },

  // 9b. Banking & Bank Statements (Reconciliation Engine)
  getBankAccounts: async (orgId: string) => {
    const res = await apiFetch<any[]>(`/organizations/${orgId}/bank-accounts`);
    return res.data || [];
  },

  createBankAccount: async (orgId: string, data: any) => {
    const res = await apiFetch(`/organizations/${orgId}/bank-accounts`, {
      method: "POST",
      body: JSON.stringify(data),
    });
    return res.data;
  },

  importStatement: async (orgId: string, bankAccountId: string, csvContent: string, filename?: string) => {
    const res = await apiFetch(`/organizations/${orgId}/bank-accounts/${bankAccountId}/import-statement`, {
      method: "POST",
      body: JSON.stringify({ csv_content: csvContent, filename: filename || "statement.csv" }),
    });
    return res.data;
  },

  getBankTransactions: async (orgId: string, bankAccountId: string, status?: string) => {
    const q = status ? `?status=${status}` : "";
    const res = await apiFetch<any[]>(`/organizations/${orgId}/bank-accounts/${bankAccountId}/transactions${q}`);
    return res.data || [];
  },

  getReconciliationSuggestions: async (orgId: string, bankAccountId: string) => {
    const res = await apiFetch<any[]>(`/organizations/${orgId}/bank-accounts/${bankAccountId}/suggestions`);
    return res.data || [];
  },

  reconcileTransaction: async (orgId: string, transactionId: string, journalEntryId: string) => {
    const res = await apiFetch(`/organizations/${orgId}/bank-transactions/${transactionId}/reconcile`, {
      method: "POST",
      body: JSON.stringify({ journal_entry_id: journalEntryId }),
    });
    return res.data;
  },

  unreconcileTransaction: async (orgId: string, transactionId: string) => {
    const res = await apiFetch(`/organizations/${orgId}/bank-transactions/${transactionId}/unreconcile`, {
      method: "POST",
    });
    return res.data;
  },

  // 10. Revenue Recognition (ASC 606 / IFRS 15)
  getRevenueContracts: async (orgId: string) => {
    const res = await apiFetch<any[]>(`/organizations/${orgId}/revenue-contracts`);
    return res.data || [];
  },

  getRevenueContract: async (orgId: string, contractId: string) => {
    const res = await apiFetch(`/organizations/${orgId}/revenue-contracts/${contractId}`);
    return res.data;
  },

  createRevenueContract: async (orgId: string, data: any) => {
    const res = await apiFetch(`/organizations/${orgId}/revenue-contracts`, {
      method: "POST",
      body: JSON.stringify(data),
    });
    return res.data;
  },

  recognizeRevenueSchedule: async (orgId: string, scheduleId: string) => {
    const res = await apiFetch(`/organizations/${orgId}/revenue-schedules/${scheduleId}/recognize`, {
      method: "POST",
    });
    return res.data;
  },

  amendRevenueContract: async (orgId: string, contractId: string, data: any) => {
    const res = await apiFetch(`/organizations/${orgId}/revenue-contracts/${contractId}/amend`, {
      method: "POST",
      body: JSON.stringify(data),
    });
    return res.data;
  },

  // 11. Multi-Entity, FX & Intercompany Consolidation
  getEntities: async (orgId: string) => {
    const res = await apiFetch<any[]>(`/organizations/${orgId}/entities`);
    return res.data || [];
  },

  createEntity: async (orgId: string, data: { name: string; code: string; currency?: string; is_primary?: boolean }) => {
    const res = await apiFetch(`/organizations/${orgId}/entities`, {
      method: "POST",
      body: JSON.stringify(data),
    });
    return res.data;
  },

  getExchangeRates: async (orgId: string) => {
    const res = await apiFetch<any[]>(`/organizations/${orgId}/exchange-rates`);
    return res.data || [];
  },

  createExchangeRate: async (orgId: string, data: { from_currency: string; to_currency: string; rate: number; effective_date: string; source?: string }) => {
    const res = await apiFetch(`/organizations/${orgId}/exchange-rates`, {
      method: "POST",
      body: JSON.stringify(data),
    });
    return res.data;
  },

  runCurrencyRevaluation: async (orgId: string, data: { accounting_period_id: string; spot_rate_usd?: number }) => {
    const res = await apiFetch(`/organizations/${orgId}/currency-revaluation`, {
      method: "POST",
      body: JSON.stringify(data),
    });
    return res.data;
  },

  getIntercompanyTransactions: async (orgId: string) => {
    const res = await apiFetch<any[]>(`/organizations/${orgId}/intercompany-transactions`);
    return res.data || [];
  },

  createIntercompanyTransaction: async (orgId: string, data: { from_entity_id: string; to_entity_id: string; transaction_date: string; currency?: string; amount: number; description: string; exchange_rate?: number }) => {
    const res = await apiFetch(`/organizations/${orgId}/intercompany-transactions`, {
      method: "POST",
      body: JSON.stringify(data),
    });
    return res.data;
  },

  postIntercompanyTransaction: async (orgId: string, id: string) => {
    const res = await apiFetch(`/organizations/${orgId}/intercompany-transactions/${id}/post`, {
      method: "POST",
    });
    return res.data;
  },

  runIntercompanyElimination: async (orgId: string, data: { accounting_period_id: string }) => {
    const res = await apiFetch(`/organizations/${orgId}/consolidation/eliminate`, {
      method: "POST",
      body: JSON.stringify(data),
    });
    return res.data;
  },

  getConsolidatedReport: async (orgId: string, reportType: string = "trial-balance", periodId?: string) => {
    const url = periodId
      ? `/organizations/${orgId}/consolidation/reports/${reportType}?accounting_period_id=${periodId}`
      : `/organizations/${orgId}/consolidation/reports/${reportType}`;
    const res = await apiFetch(url);
    return res.data;
  },
};
