/**
 * Axiom AI Client Service for Finova ERP
 * Connects to the authoritative backend via Next.js server route (/api/axiom).
 * Architecture: Next.js -> Laravel API -> FastAPI AI Microservice -> LLM Provider.
 */

export function getStoredGroqKey(): string {
  return "";
}

export function setStoredGroqKey(_key: string): void {
  if (typeof window !== "undefined") {
    try {
      localStorage.removeItem("finova_groq_api_key");
    } catch {
      // Ignore
    }
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
  financialContext: Record<string, any> = {},
  organizationId?: string
): Promise<AxiomResponse> {
  const headers: Record<string, string> = {
    "Content-Type": "application/json",
  };

  try {
    const res = await fetch("/api/axiom", {
      method: "POST",
      headers,
      body: JSON.stringify({
        query,
        financial_context: financialContext,
        organization_id: organizationId,
      }),
    });

    const data = await res.json().catch(() => null);

    if (!data) {
      throw new Error("Invalid response received from AI service.");
    }

    if (!res.ok) {
      throw new Error(data.message || data.error || "AI service failed to respond.");
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
      answer: `AI Gateway Connection Error: ${err.message || "Failed to reach AI service."}`,
      metrics: {
        "Status": "Connection Failed",
        "Engine": "FastAPI AI Microservice",
      },
      suggested_actions: [
        "Verify network connectivity to backend",
        "Ensure enterprise session is authenticated",
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
      provider: "FastAPI AI Service",
      model: "authoritative-backend",
      status: "unreachable",
    };
  }
}
