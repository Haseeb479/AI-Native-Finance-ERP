import { NextRequest, NextResponse } from "next/server";
import { backendApiUrl, isOpsHostAllowed, OPS_COOKIE, opsHostDeniedResponse } from "@/lib/ops-auth";

export const runtime = "nodejs";

const uuidPattern = /^[0-9a-f-]{36}$/i;
const numericIdPattern = /^\d+$/;

function isAllowedPath(path: string[], method: string): boolean {
  if (path.length === 1 && path[0] === "organizations" && method === "GET") return true;
  if (path.length === 2 && path[0] === "organizations" && uuidPattern.test(path[1]) && method === "GET") return true;
  if (path.length === 1 && path[0] === "demo-requests" && method === "GET") return true;
  if (path.length === 2 && path[0] === "demo-requests" && numericIdPattern.test(path[1]) && method === "PATCH") return true;
  if (path.length === 1 && path[0] === "support-cases" && ["GET", "POST"].includes(method)) return true;
  if (path.length === 2 && path[0] === "support-cases" && uuidPattern.test(path[1]) && method === "PATCH") return true;
  if (path.length === 1 && path[0] === "audit-events" && method === "GET") return true;
  if (path.length === 1 && path[0] === "staff" && ["GET", "POST"].includes(method)) return true;
  if (path.length === 2 && path[0] === "staff" && uuidPattern.test(path[1]) && method === "PATCH") return true;
  if (path.length === 1 && path[0] === "staff-invitations" && ["GET", "POST"].includes(method)) return true;
  if (path.length === 2 && path[0] === "staff-invitations" && uuidPattern.test(path[1]) && method === "DELETE") return true;
  if (path.length === 1 && path[0] === "staff-mfa-complete" && method === "POST") return true;
  return path.length === 1 && path[0] === "service-readiness" && method === "GET";
}

async function proxyControlCenterRequest(
  request: NextRequest,
  context: { params: Promise<{ path: string[] }> },
) {
  if (!isOpsHostAllowed(request.headers.get("host"))) return opsHostDeniedResponse();
  const { path } = await context.params;
  const method = request.method;
  if (path.length < 1 || path.length > 2 || !isAllowedPath(path, method)) {
    return NextResponse.json({ error: "Not found." }, { status: 404 });
  }

  const token = request.cookies.get(OPS_COOKIE)?.value;
  if (!token) {
    return NextResponse.json({ error: "Finova Operations authentication required." }, { status: 401 });
  }

  let body: string | undefined;
  if (method !== "GET") {
    const contentLength = Number(request.headers.get("content-length") || 0);
    if (contentLength > 20_000) {
      return NextResponse.json({ error: "Request body is too large." }, { status: 413 });
    }
    body = await request.text();
  }

  try {
    const upstream = await fetch(
      `${backendApiUrl}/internal/control-center/${path.map(encodeURIComponent).join("/")}${request.nextUrl.search}`,
      {
        method,
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: "application/json",
          ...(body ? { "Content-Type": "application/json" } : {}),
        },
        ...(body ? { body } : {}),
        cache: "no-store",
      },
    );
    const responseBody = await upstream.json().catch(() => ({ error: "Control Center returned an unreadable response." }));

    return NextResponse.json(responseBody, {
      status: upstream.status,
      headers: { "Cache-Control": "no-store" },
    });
  } catch {
    return NextResponse.json({ error: "Finova Operations API is unavailable." }, { status: 502 });
  }
}

export const GET = proxyControlCenterRequest;
export const POST = proxyControlCenterRequest;
export const PATCH = proxyControlCenterRequest;
export const DELETE = proxyControlCenterRequest;
