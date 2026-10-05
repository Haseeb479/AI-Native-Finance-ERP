export async function opsApi<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(`/api/control-center/${path}`, {
    cache: "no-store",
    ...init,
    headers: {
      Accept: "application/json",
      ...(init?.body ? { "Content-Type": "application/json" } : {}),
      ...init?.headers,
    },
  });
  const body = await response.json().catch(() => null);
  if (!response.ok) {
    const message = body?.errors?.[0]?.message || body?.errors?.[0] || body?.error || `Operations request failed (${response.status}).`;
    throw new Error(message);
  }
  return body.data as T;
}
