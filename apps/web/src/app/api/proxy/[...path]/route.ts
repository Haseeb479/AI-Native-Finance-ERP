import { NextRequest, NextResponse } from "next/server";

export const runtime = "nodejs";

const BACKEND_URL =
  process.env.INTERNAL_API_URL ||
  process.env.NEXT_PUBLIC_API_URL ||
  "http://127.0.0.1:8000/api/v1";

async function handleProxy(req: NextRequest, { params }: { params: Promise<{ path: string[] }> }) {
  try {
    const resolvedParams = await params;
    const path = resolvedParams.path?.join("/") || "";
    const url = new URL(req.url);
    const search = url.search;

    const targetUrl = `${BACKEND_URL}/${path}${search}`;

    const token =
      req.cookies.get("erp_auth_token")?.value ||
      req.headers.get("Authorization")?.replace(/^Bearer\s+/i, "");

    const orgId =
      req.cookies.get("erp_current_org_id")?.value ||
      req.headers.get("X-Organization-Id");

    const headers: Record<string, string> = {
      Accept: "application/json",
    };

    const contentType = req.headers.get("Content-Type");
    if (contentType) {
      headers["Content-Type"] = contentType;
    }

    if (token) {
      headers["Authorization"] = `Bearer ${token}`;
    }

    if (orgId) {
      headers["X-Organization-Id"] = orgId;
    }

    const idempotencyKey = req.headers.get("Idempotency-Key");
    if (idempotencyKey) {
      headers["Idempotency-Key"] = idempotencyKey;
    }

    const init: RequestInit = {
      method: req.method,
      headers,
    };

    if (["POST", "PUT", "PATCH"].includes(req.method)) {
      init.body = await req.text();
    }

    const response = await fetch(targetUrl, init);
    const data = await response.text();

    return new NextResponse(data, {
      status: response.status,
      headers: {
        "Content-Type": response.headers.get("Content-Type") || "application/json",
      },
    });
  } catch (error: any) {
    return NextResponse.json(
      { error: error?.message || "Proxy request error." },
      { status: 502 }
    );
  }
}

export const GET = handleProxy;
export const POST = handleProxy;
export const PUT = handleProxy;
export const PATCH = handleProxy;
export const DELETE = handleProxy;
