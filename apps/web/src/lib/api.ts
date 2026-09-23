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

  // 5. Vendor Bills & 3-Way Matching
  getBills: async (orgId: string) => {
    const res = await apiFetch<any[]>(`/organizations/${orgId}/bills`);
    return res.data || [];
  },

  getThreeWayMatches: async (orgId: string) => {
    const res = await apiFetch<any[]>(`/organizations/${orgId}/procurement/3way-matches`);
    return res.data || [];
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

  getAuditLogs: async (orgId: string) => {
    const res = await apiFetch<any[]>(`/organizations/${orgId}/audit-logs`);
    return res.data || [];
  },
};
