/**
 * Axiom AI Client Service for Finova ERP
 * Connects to the Groq-powered Next.js API route (/api/axiom).
 */

const GROQ_STORAGE_KEY = "finova_groq_api_key";

export function getStoredGroqKey(): string {
  if (typeof window === "undefined") return "";
  return localStorage.getItem(GROQ_STORAGE_KEY) || "";
}

export function setStoredGroqKey(key: string): void {
  if (typeof window === "undefined") return;
  if (!key || key.trim() === "") {
    localStorage.removeItem(GROQ_STORAGE_KEY);
  } else {
    localStorage.setItem(GROQ_STORAGE_KEY, key.trim());
  }
}

export interface AxiomResponse {
  configured: boolean;
  success?: boolean;
  answer: string;
  metrics?: Record<string, string>;
  suggested_actions?: string[];
  latency_ms?: number;
  error?: string;
  message?: string;
}

export async function askAxiomAI(
  query: string,
  financialContext: Record<string, any> = {}
): Promise<AxiomResponse> {
  const localKey = getStoredGroqKey();

  const headers: Record<string, string> = {
    "Content-Type": "application/json",
  };

  if (localKey) {
    headers["x-groq-api-key"] = localKey;
  }

  try {
    const res = await fetch("/api/axiom", {
      method: "POST",
      headers,
      body: JSON.stringify({
        query,
        financial_context: financialContext,
      }),
    });

    const data = await res.json().catch(() => null);

    if (!data) {
      throw new Error("Invalid response received from Axiom AI.");
    }

    if (!res.ok && data.error !== "GROQ_API_KEY_REQUIRED") {
      throw new Error(data.message || data.error || "Axiom AI failed to respond.");
    }

    if (data.configured === false && data.sample_response) {
      return {
        configured: false,
        answer: data.sample_response.answer,
        metrics: data.sample_response.metrics,
        suggested_actions: data.sample_response.suggested_actions,
        message: data.message,
      };
    }

    return {
      configured: data.configured ?? true,
      success: true,
      answer: data.answer || "No response generated.",
      metrics: data.metrics || {},
      suggested_actions: data.suggested_actions || [],
      latency_ms: data.latency_ms,
    };
  } catch (err: any) {
    return {
      configured: false,
      error: "REQUEST_FAILED",
      answer: `Axiom AI Connection Error: ${err.message || "Failed to reach Axiom AI service."}`,
      metrics: {
        "Status": "Connection Failed",
        "Engine": "Groq LPU",
      },
      suggested_actions: [
        "Verify your internet connection",
        "Check Groq API key in Axiom AI settings",
      ],
    };
  }
}

export async function checkAxiomStatus(): Promise<{
  configured: boolean;
  provider: string;
  model: string;
  status: string;
}> {
  try {
    const res = await fetch("/api/axiom");
    return await res.json();
  } catch {
    return {
      configured: false,
      provider: "Groq",
      model: "llama-3.3-70b-versatile",
      status: "unreachable",
    };
  }
}
