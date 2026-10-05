import { NextResponse } from "next/server";

export const OPS_COOKIE = "finova_ops_token";
// Must cover both /api/ops/* (auth) and /api/control-center/* (data proxy).
export const OPS_COOKIE_PATH = "/api";
const LEGACY_OPS_COOKIE_PATH = "/api/ops";
export const OPS_SETUP_COOKIE = "finova_ops_setup_token";
export const OPS_SETUP_COOKIE_PATH = "/api/ops/mfa-setup";
export const OPS_SESSION_SECONDS = 60 * 60 * 8;

export const backendApiUrl =
  process.env.INTERNAL_API_URL ||
  process.env.NEXT_PUBLIC_API_URL ||
  "http://127.0.0.1:8000/api/v1";

function normalizeHost(hostHeader: string | null): string {
  return (hostHeader || "").split(":")[0].toLowerCase();
}

export function isDedicatedOpsHost(hostHeader: string | null): boolean {
  const host = normalizeHost(hostHeader);
  if (!host || ["localhost", "127.0.0.1"].includes(host)) return false;

  const configuredHosts = (process.env.OPS_ALLOWED_HOSTS || "")
    .split(",")
    .map((value) => value.trim().toLowerCase())
    .filter(Boolean);

  return configuredHosts.includes(host);
}

export function isOpsHostAllowed(hostHeader: string | null): boolean {
  const host = normalizeHost(hostHeader);
  const configuredHosts = (process.env.OPS_ALLOWED_HOSTS || "")
    .split(",")
    .map((value) => value.trim().toLowerCase())
    .filter(Boolean);
  if (configuredHosts.length > 0) return configuredHosts.includes(host);
  return process.env.NODE_ENV !== "production" && ["localhost", "127.0.0.1"].includes(host);
}

export function opsHostDeniedResponse(): NextResponse {
  return NextResponse.json({ error: "Finova Operations must be accessed from its dedicated staff portal." }, { status: 404 });
}

export type OpsStaffSession = {
  user: { id: string; name: string; email: string };
  role: string;
  mfa_enabled: boolean;
};

export async function verifyOpsStaffToken(token: string): Promise<{
  response: Response;
  session: OpsStaffSession | null;
}> {
  const response = await fetch(`${backendApiUrl}/internal/control-center/session`, {
    headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
    cache: "no-store",
  });
  const payload = await response.json().catch(() => null);
  if (!response.ok || !payload?.data?.user || !payload?.data?.role) {
    return { response, session: null };
  }
  return { response, session: payload.data as OpsStaffSession };
}

export async function preflightOpsStaffToken(token: string): Promise<{
  response: Response;
  session: OpsStaffSession | null;
}> {
  const response = await fetch(`${backendApiUrl}/internal/control-center/staff-preflight`, {
    headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
    cache: "no-store",
  });
  const payload = await response.json().catch(() => null);
  if (!response.ok || !payload?.data?.user || !payload?.data?.role) {
    return { response, session: null };
  }
  return { response, session: payload.data as OpsStaffSession };
}

export async function revokeOpsToken(token: string): Promise<void> {
  const response = await fetch(`${backendApiUrl}/auth/logout`, {
    method: "POST",
    headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
    cache: "no-store",
  });
  if (!response.ok) {
    console.error("Failed to revoke rejected Finova Operations token.", {
      status: response.status,
    });
  }
}

export function setOpsCookie(response: NextResponse, token: string): void {
  response.cookies.set(OPS_COOKIE, "", {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "strict",
    path: LEGACY_OPS_COOKIE_PATH,
    maxAge: 0,
  });
  response.cookies.set(OPS_COOKIE, token, {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "strict",
    path: OPS_COOKIE_PATH,
    maxAge: OPS_SESSION_SECONDS,
  });
}

export function setOpsSetupCookie(response: NextResponse, token: string): void {
  response.cookies.set(OPS_SETUP_COOKIE, token, {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "strict",
    path: OPS_SETUP_COOKIE_PATH,
    maxAge: 15 * 60,
  });
}

export function clearOpsSetupCookie(response: NextResponse): void {
  response.cookies.set(OPS_SETUP_COOKIE, "", {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "strict",
    path: OPS_SETUP_COOKIE_PATH,
    maxAge: 0,
  });
}

export function clearOpsCookie(response: NextResponse): void {
  response.cookies.set(OPS_COOKIE, "", {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "strict",
    path: LEGACY_OPS_COOKIE_PATH,
    maxAge: 0,
  });
  response.cookies.set(OPS_COOKIE, "", {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "strict",
    path: OPS_COOKIE_PATH,
    maxAge: 0,
  });
}
