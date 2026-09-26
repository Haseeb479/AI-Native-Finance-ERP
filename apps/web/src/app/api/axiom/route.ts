import { NextRequest, NextResponse } from "next/server";

export const runtime = "nodejs";

interface AxiomRequest {
  query: string;
  financial_context?: Record<string, any>;
  api_key?: string;
  model?: string;
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

    // Determine Groq API key (from header, request body, or environment variable)
    const headerKey = req.headers.get("x-groq-api-key");
    const groqApiKey =
      headerKey ||
      body.api_key ||
      process.env.GROQ_API_KEY ||
      process.env.NEXT_PUBLIC_GROQ_API_KEY ||
      "";

    const requestedModel = body.model || process.env.GROQ_MODEL || "openai/gpt-oss-120b";
    const candidateModels = Array.from(new Set([requestedModel, "openai/gpt-oss-120b", "openai/gpt-oss-20b", "llama-3.3-70b-versatile"]));

    // If no Groq API key is configured yet, return a clean status instructing the user
    if (!groqApiKey || groqApiKey.trim() === "") {
      return NextResponse.json(
        {
          configured: false,
          error: "GROQ_API_KEY_REQUIRED",
          message:
            "Groq API Key is not yet configured. Please insert your Groq API key in apps/web/.env.local or enter it in the Axiom AI settings.",
          sample_response: {
            answer: `[Axiom AI Ready for Groq Key] You asked: "${query}". Once you provide your Groq API key, Axiom AI will process this query using ultra-fast Groq LPU inference with full General Ledger, Pakistan FBR tax rules, and ASC 606 context.`,
            metrics: {
              "LLM Engine": "Groq LPU (Ready)",
              "Target Model": requestedModel,
              "Status": "Awaiting API Key",
            },
            suggested_actions: [
              "Add GROQ_API_KEY in apps/web/.env.local",
              "Paste Groq key into Axiom AI settings modal",
            ],
          },
        },
        { status: 200 }
      );
    }

    // Prepare system instructions with financial ERP context
    const financialContext = body.financial_context || {};
    const systemPrompt = `You are Axiom AI, the native financial intelligence and accounting copilot for Finova ERP.
You are an expert chartered accountant (CPA/FCA) and financial controller with deep expertise in:
- Double-entry bookkeeping (Debits must strictly equal Credits)
- Perpetual General Ledger & Chart of Accounts (Assets 1000s, Liabilities 2000s, Equity 3000s, Revenue 4000s, Expenses 5000s)
- ASC 606 & IFRS 15 Revenue Recognition (Performance Obligations, Amortization schedules, Deferred Revenue)
- Multi-Entity Consolidation & IAS 21 (Reciprocal Intercompany Eliminations, Unrealized FX Revaluations)
- Bank Statement Reconciliation (MT940/OFX/CSV, SHA-256 fingerprinting, matching rules)
- Accounts Payable 3-Way Matching (POs, Goods Received Notes, Vendor Invoices)
- Pakistan Tax Laws & FBR Compliance (Sales Tax Act 1990, Section 153 Withholding Tax, Annex-C, Provincial Authorities: SRB, PRA, KPRA, BRA)

LIVE ORGANIZATION CONTEXT:
${JSON.stringify(financialContext, null, 2)}

INSTRUCTIONS:
1. Provide a direct, professional, authoritative financial answer.
2. If the user asks for numbers or ledger impact, show balanced Debit/Credit accounts with exact codes where applicable.
3. Be concise and crisp.
4. Output your response as a valid JSON object with the following structure:
{
  "answer": "Clear narrative explanation and financial reasoning",
  "metrics": {
    "Metric Name 1": "value",
    "Metric Name 2": "value",
    "Metric Name 3": "value"
  },
  "suggested_actions": [
    "Actionable step 1",
    "Actionable step 2"
  ]
}
Do not wrap your response in markdown code blocks if possible; return pure JSON.`;

    let activeModel = requestedModel;
    let groqData: any = null;
    let latencyMs = 0;
    let lastError = "";

    // Try candidate models in order until one succeeds
    for (const m of candidateModels) {
      activeModel = m;
      const groqPayload = {
        model: m,
        messages: [
          { role: "system", content: systemPrompt },
          { role: "user", content: query },
        ],
        temperature: 0.2,
        max_tokens: 1024,
        response_format: { type: "json_object" },
      };

      const startTime = Date.now();
      try {
        const groqRes = await fetch("https://api.groq.com/openai/v1/chat/completions", {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Authorization: `Bearer ${groqApiKey.trim()}`,
          },
          body: JSON.stringify(groqPayload),
        });

        latencyMs = Date.now() - startTime;

        if (groqRes.ok) {
          groqData = await groqRes.json();
          break;
        } else {
          const errText = await groqRes.text().catch(() => "");
          lastError = errText;
        }
      } catch (fetchErr: any) {
        lastError = fetchErr.message;
      }
    }

    if (!groqData) {
      return NextResponse.json(
        {
          configured: true,
          error: "GROQ_API_ERROR",
          message: lastError || "Groq API request failed.",
        },
        { status: 502 }
      );
    }

    const rawContent = groqData?.choices?.[0]?.message?.content || "{}";

    let parsedResponse: any = {};
    try {
      parsedResponse = JSON.parse(rawContent);
    } catch {
      parsedResponse = {
        answer: rawContent,
        metrics: {
          "Model": activeModel,
          "Latency": `${latencyMs}ms`,
        },
        suggested_actions: ["Inspect Ledger", "Review Reconciliation"],
      };
    }

    return NextResponse.json({
      configured: true,
      success: true,
      answer: parsedResponse.answer || rawContent,
      metrics: {
        ...(parsedResponse.metrics || {}),
        "LLM Provider": "Groq LPU",
        "Model": activeModel,
        "Inference Speed": `${latencyMs}ms`,
      },
      suggested_actions: parsedResponse.suggested_actions || [
        "Audit posted journal entries",
        "View updated financial statements",
      ],
      usage: groqData.usage || null,
      latency_ms: latencyMs,
    });
  } catch (error: any) {
    return NextResponse.json(
      {
        configured: false,
        error: "INTERNAL_ERROR",
        message: error.message || "Failed to process Axiom AI request.",
      },
      { status: 500 }
    );
  }
}

export async function GET() {
  const isConfigured = Boolean(
    process.env.GROQ_API_KEY && process.env.GROQ_API_KEY.trim() !== ""
  );
  return NextResponse.json({
    name: "Axiom AI Engine",
    provider: "Groq",
    model: process.env.GROQ_MODEL || "llama-3.3-70b-versatile",
    configured: isConfigured,
    status: isConfigured ? "ready" : "awaiting_key",
  });
}
