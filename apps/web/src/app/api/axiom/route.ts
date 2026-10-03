import { NextRequest, NextResponse } from "next/server";

export const runtime = "nodejs";

const BACKEND_URL =
  process.env.INTERNAL_API_URL ||
  process.env.NEXT_PUBLIC_API_URL ||
  "http://127.0.0.1:8000/api/v1";

interface AxiomRequest {
  query: string;
  financial_context?: Record<string, any>;
  organization_id?: string;
}

export async function POST(req: NextRequest) {
  try {
    const body: AxiomRequest = await req.json().catch(() => ({ query: "" }));
    const query = body.query?.trim();

    if (!query) {
      return NextResponse.json(
        { error: "Query is required." },
        { status: 400 }
      );
    }

    // Resolve auth token from cookies or Authorization header
    const token =
      req.cookies.get("erp_auth_token")?.value ||
      req.headers.get("Authorization")?.replace(/^Bearer\s+/i, "");

    // Resolve organization ID from request body, cookies, or headers
    const orgId =
      body.organization_id ||
      req.cookies.get("erp_current_org_id")?.value ||
      req.headers.get("X-Organization-Id");

    if (!token) {
      return NextResponse.json(
        {
          configured: true,
          error: "UNAUTHENTICATED",
          message: "Authentication required to access authoritative financial AI intelligence.",
        },
        { status: 401 }
      );
    }

    if (!orgId) {
      return NextResponse.json(
        {
          configured: true,
          error: "ORGANIZATION_REQUIRED",
          message: "Please select an organization before querying the financial copilot.",
        },
        { status: 400 }
      );
    }

    const correlationId =
      req.headers.get("X-Correlation-ID") ||
      req.headers.get("X-Request-ID") ||
      (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function"
        ? crypto.randomUUID()
        : `proxy-ai-${Date.now()}`);

    // Call authoritative Laravel AI Copilot endpoint:
    // Flow: Next.js -> Laravel -> FastAPI (with internal signed service token) -> LLM Provider
    const startTime = Date.now();
    const targetUrl = `${BACKEND_URL}/organizations/${orgId}/ai/copilot/qa`;

    const backendRes = await fetch(targetUrl, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        Authorization: `Bearer ${token}`,
        "X-Organization-Id": orgId,
        "X-Correlation-ID": correlationId,
      },
      body: JSON.stringify({
        query,
        financial_context: body.financial_context || {},
      }),
    });

    const latencyMs = Date.now() - startTime;
    const backendData = await backendRes.json().catch(() => null);

    if (!backendRes.ok) {
      const errMessage =
        backendData?.errors?.[0]?.message ||
        backendData?.message ||
        `Backend AI gateway returned HTTP ${backendRes.status}`;
      return NextResponse.json(
        {
          configured: true,
          error: "BACKEND_AI_ERROR",
          message: errMessage,
        },
        { status: backendRes.status }
      );
    }

    const aiData = backendData?.data || {};

    return NextResponse.json({
      configured: true,
      success: true,
      answer: aiData.answer || "No response generated.",
      metrics: {
        ...(aiData.key_metrics || {}),
        "Architecture": "Next.js -> Laravel -> FastAPI",
        "Groundedness": `${Math.round((aiData.groundedness_score ?? 1.0) * 100)}%`,
        "Confidence": `${Math.round((aiData.confidence ?? 0.95) * 100)}%`,
        "Latency": `${latencyMs}ms`,
      },
      suggested_actions: aiData.suggested_actions || [
        "Audit posted journal entries",
        "View updated financial statements",
      ],
      evidence: aiData.evidence || [],
      latency_ms: latencyMs,
    });
  } catch (error: any) {
    return NextResponse.json(
      {
        configured: false,
        error: "INTERNAL_ERROR",
        message: error.message || "Failed to process AI request.",
      },
      { status: 500 }
    );
  }
}

export async function GET() {
  return NextResponse.json({
    name: "Finova AI Copilot",
    provider: "FastAPI Internal Microservice (via Laravel Gateway)",
    architecture: "Next.js -> Laravel -> FastAPI -> LLM",
    configured: true,
    status: "ready",
  });
}
