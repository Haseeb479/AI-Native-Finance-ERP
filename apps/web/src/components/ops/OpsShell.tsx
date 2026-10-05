"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { createContext, useContext, useEffect, useState } from "react";
import {
  Activity,
  Building2,
  Headset,
  LayoutDashboard,
  LogOut,
  Mail,
  ScrollText,
  UsersRound,
  ShieldCheck,
} from "lucide-react";

type StaffSession = {
  user: { name: string; email: string };
  role: string;
};

const OpsStaffContext = createContext<StaffSession | null>(null);

export function useOpsStaff() {
  return useContext(OpsStaffContext);
}

const navigation = [
  { href: "/ops", label: "Overview", icon: LayoutDashboard, roles: null },
  { href: "/ops/companies", label: "Companies", icon: Building2, roles: null },
  { href: "/ops/demo-requests", label: "Demo pipeline", icon: Mail, roles: ["ops_admin", "ops_manager", "ops_sales"] },
  { href: "/ops/support-cases", label: "Support & access", icon: Headset, roles: ["ops_admin", "ops_manager", "ops_support"] },
  { href: "/ops/system", label: "Service health", icon: Activity, roles: ["ops_admin", "ops_manager"] },
  { href: "/ops/audit", label: "Staff audit", icon: ScrollText, roles: ["ops_admin", "ops_manager"] },
  { href: "/ops/team", label: "Team access", icon: UsersRound, roles: ["ops_admin"] },
];

export function OpsShell({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();
  const [session, setSession] = useState<StaffSession | null>(null);
  const [checking, setChecking] = useState(true);
  const [sessionError, setSessionError] = useState("");
  const [signingOut, setSigningOut] = useState(false);

  useEffect(() => {
    let disposed = false;
    fetch("/api/ops/auth/session", { cache: "no-store" })
      .then(async (response) => {
        const body = await response.json().catch(() => null);
        if (response.status === 401) {
          router.replace("/ops/login");
          return;
        }
        if (!response.ok) throw new Error(body?.error || "Unable to verify staff access.");
        if (!disposed) setSession(body.data);
      })
      .catch((error: Error) => {
        if (!disposed) setSessionError(error.message);
      })
      .finally(() => {
        if (!disposed) setChecking(false);
      });
    return () => {
      disposed = true;
    };
  }, [router]);

  async function signOut() {
    setSigningOut(true);
    try {
      const response = await fetch("/api/ops/auth/logout", { method: "POST" });
      const body = await response.json().catch(() => null);
      if (!response.ok) throw new Error(body?.error || "Could not revoke staff session.");
      router.replace("/ops/login");
    } catch (error) {
      setSessionError(error instanceof Error ? error.message : "Could not revoke staff session.");
      setSigningOut(false);
    }
  }

  if (checking) {
    return <main className="grid min-h-screen place-items-center bg-[#F5F7F5] text-sm text-slate-600">Verifying Finova staff access…</main>;
  }
  if (!session) {
    return (
      <main className="grid min-h-screen place-items-center bg-[#F5F7F5] p-6">
        <section className="max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <ShieldCheck className="mb-3 h-6 w-6 text-emerald-700" />
          <h1 className="text-lg font-semibold text-slate-900">Operations session unavailable</h1>
          <p role="alert" className="mt-2 text-sm leading-6 text-slate-600">{sessionError || "Your staff session could not be verified."}</p>
          <button onClick={() => window.location.reload()} className="mt-4 rounded-lg bg-[#174C38] px-4 py-2 text-sm font-semibold text-white">Retry</button>
        </section>
      </main>
    );
  }

  const visibleLinks = navigation.filter((item) => !item.roles || item.roles.includes(session.role));
  return (
    <div className="min-h-screen bg-[#F5F7F5] text-[#14251C] lg:flex">
      <aside className="flex w-full flex-col border-b border-[#243D32] bg-[#10251C] text-white lg:fixed lg:inset-y-0 lg:w-[258px] lg:border-b-0 lg:border-r">
        <div className="flex items-center gap-3 border-b border-white/10 px-6 py-5">
          <div className="grid h-10 w-10 place-items-center rounded-xl bg-emerald-400/15 text-emerald-300"><ShieldCheck size={21} /></div>
          <div>
            <div className="text-sm font-bold tracking-wide">FINOVA</div>
            <div className="text-[10px] font-semibold uppercase tracking-[0.2em] text-emerald-200/70">Operations</div>
          </div>
        </div>
        <div className="px-5 pt-6 text-[10px] font-bold uppercase tracking-[0.18em] text-white/40">Team workspace</div>
        <nav aria-label="Finova Operations" className="flex gap-1 overflow-x-auto px-3 py-3 lg:flex-col">
          {visibleLinks.map(({ href, label, icon: Icon }) => {
            const active = href === "/ops" ? pathname === href : pathname.startsWith(href);
            return (
              <Link key={href} href={href} aria-current={active ? "page" : undefined}
                className={`flex shrink-0 items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors ${active ? "bg-emerald-400/15 text-emerald-200" : "text-white/65 hover:bg-white/5 hover:text-white"}`}>
                <Icon size={17} /><span>{label}</span>
              </Link>
            );
          })}
        </nav>
        <div className="mt-auto hidden border-t border-white/10 p-4 lg:block">
          <div className="truncate text-sm font-semibold">{session.user.name}</div>
          <div className="mt-0.5 truncate text-xs text-white/50">{session.user.email}</div>
          <div className="mt-2 inline-flex rounded-full bg-white/10 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-emerald-200">{session.role.replaceAll("_", " ")}</div>
          <button onClick={signOut} disabled={signingOut} className="mt-4 flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-xs font-medium text-white/60 hover:bg-white/5 hover:text-white disabled:opacity-50">
            <LogOut size={15} />{signingOut ? "Signing out…" : "Sign out securely"}
          </button>
        </div>
      </aside>
      <div className="min-w-0 flex-1 lg:ml-[258px]">
        <header className="sticky top-0 z-10 flex h-[62px] items-center justify-between border-b border-[#E2EAE4] bg-white/95 px-5 backdrop-blur sm:px-8">
          <div className="text-xs font-semibold text-slate-500">FINOVA <span className="px-1 text-slate-300">/</span> INTERNAL OPERATIONS</div>
          <div className="flex items-center gap-3">
            <span className="hidden rounded-full border border-emerald-100 bg-emerald-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-emerald-800 sm:inline-flex">Staff only</span>
            <span className="grid h-8 w-8 place-items-center rounded-full bg-[#E4EFE7] text-xs font-bold text-[#174C38]">{session.user.name.split(/\s+/).map((part) => part[0]).slice(0, 2).join("").toUpperCase()}</span>
            <button onClick={signOut} disabled={signingOut} aria-label="Sign out" className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 lg:hidden"><LogOut size={17} /></button>
          </div>
        </header>
        {sessionError && <div role="alert" className="mx-5 mt-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 sm:mx-8">{sessionError}</div>}
        <OpsStaffContext.Provider value={session}>
          <main className="mx-auto max-w-[1440px] p-5 sm:p-8">{children}</main>
        </OpsStaffContext.Provider>
      </div>
    </div>
  );
}
