"use client";

import { FormEvent, useEffect, useState } from "react";
import { ShieldCheck, UserPlus, UsersRound } from "lucide-react";
import { opsApi } from "@/lib/ops-api";

type StaffMember = {
  id: string; user_id: string; name: string; email: string; role: string;
  status: "active" | "pending_mfa" | "revoked"; created_at: string | null; is_bootstrap: boolean;
};
type StaffInvitation = {
  id: string; email: string; role: string; status: "pending" | "expired";
  expires_at: string; created_at: string;
};
type StaffDraft = { role: string; status: "active" | "pending_mfa" | "revoked" };
const roles = ["ops_admin", "ops_manager", "ops_sales", "ops_support", "ops_readonly"];
const roleLabels: Record<string, string> = {
  ops_admin: "Operations administrator",
  ops_manager: "Operations manager",
  ops_sales: "Sales",
  ops_support: "Customer support",
  ops_readonly: "Read only",
};

export default function OperationsTeamPage() {
  const [staff, setStaff] = useState<StaffMember[]>([]);
  const [invitations, setInvitations] = useState<StaffInvitation[]>([]);
  const [drafts, setDrafts] = useState<Record<string, StaffDraft>>({});
  const [inviteEmail, setInviteEmail] = useState("");
  const [inviteRole, setInviteRole] = useState("ops_readonly");
  const [email, setEmail] = useState("");
  const [role, setRole] = useState("ops_readonly");
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState("");
  const [loadFailed, setLoadFailed] = useState(false);

  useEffect(() => {
    let cancelled = false;
    Promise.all([
      opsApi<{ staff: StaffMember[] }>("staff"),
      opsApi<{ invitations: StaffInvitation[] }>("staff-invitations"),
    ])
      .then(([staffData, invitationData]) => {
        if (!cancelled) {
          setStaff(staffData.staff);
          setInvitations(invitationData.invitations);
        }
      })
      .catch((reason: Error) => { if (!cancelled) { setError(reason.message); setLoadFailed(true); } })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, []);

  async function inviteStaff(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    setNotice("");
    setBusy("invite");
    try {
      const data = await opsApi<{ invitation: StaffInvitation; message: string }>("staff-invitations", {
        method: "POST",
        body: JSON.stringify({ email: inviteEmail, role: inviteRole }),
      });
      setInvitations((previous) => [data.invitation, ...previous.filter((item) => item.id !== data.invitation.id)]);
      setInviteEmail("");
      setInviteRole("ops_readonly");
      setNotice(`Invitation sent to ${data.invitation.email}. The link expires in 48 hours; the invitee must set up MFA before access is enabled.`);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Could not send the staff invitation.");
    } finally {
      setBusy("");
    }
  }

  async function revokeInvitation(invitation: StaffInvitation) {
    setError("");
    setNotice("");
    setBusy(invitation.id);
    try {
      await opsApi<{ revoked: boolean }>(`staff-invitations/${invitation.id}`, { method: "DELETE" });
      setInvitations((previous) => previous.filter((item) => item.id !== invitation.id));
      setNotice(`The invitation for ${invitation.email} was revoked.`);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Could not revoke the invitation.");
    } finally {
      setBusy("");
    }
  }

  async function resendInvitation(invitation: StaffInvitation) {
    setError("");
    setNotice("");
    setBusy(invitation.id);
    try {
      const data = await opsApi<{ invitation: StaffInvitation }>("staff-invitations", {
        method: "POST",
        body: JSON.stringify({ email: invitation.email, role: invitation.role }),
      });
      setInvitations((previous) => previous.map((item) => item.id === invitation.id ? data.invitation : item));
      setNotice(`A fresh invitation link was sent to ${invitation.email}. Any previous link is now invalid.`);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Could not resend the staff invitation.");
    } finally {
      setBusy("");
    }
  }

  async function addStaff(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    setNotice("");
    setBusy("add");
    try {
      const data = await opsApi<{ staff: StaffMember }>("staff", {
        method: "POST",
        body: JSON.stringify({ email, role }),
      });
      setStaff((previous) => [...previous.filter((item) => item.id !== data.staff.id), data.staff]);
      setEmail("");
      setRole("ops_readonly");
      setNotice(data.staff.status === "pending_mfa"
        ? "Staff access provisioned in a restricted state. The team member must complete MFA setup before using Operations."
        : "Staff access provisioned. The account is ready to use Operations.");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Could not provision staff access.");
    } finally {
      setBusy("");
    }
  }

  async function saveStaff(member: StaffMember) {
    const draft = drafts[member.id];
    if (!draft) return;
    setError("");
    setNotice("");
    setBusy(member.id);
    try {
      const data = await opsApi<{ staff: StaffMember }>(`staff/${member.id}`, {
        method: "PATCH",
        body: JSON.stringify(draft.status === "pending_mfa" ? { role: draft.role } : draft),
      });
      setStaff((previous) => previous.map((item) => item.id === member.id ? data.staff : item));
      setDrafts((previous) => { const next = { ...previous }; delete next[member.id]; return next; });
      setNotice(`Access updated for ${member.email}.`);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Could not update staff access.");
    } finally {
      setBusy("");
    }
  }

  return (
    <div className="space-y-6">
      <header><p className="text-xs font-bold uppercase tracking-[0.17em] text-emerald-800">Security administration</p><h1 className="mt-2 text-3xl font-semibold tracking-tight">Team access</h1><p className="mt-2 text-sm text-slate-500">Provision named Finova staff accounts and apply least-privilege roles. Every change is audited.</p></header>
      {error && <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">{error}</p>}
      {notice && <p role="status" className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{notice}</p>}
      {!loadFailed && <section className="grid gap-4 xl:grid-cols-[0.9fr_1.1fr]">
        <div className="space-y-4">
          <form onSubmit={inviteStaff} className="rounded-2xl border border-[#DCE8DE] bg-white p-5">
            <div className="flex items-center gap-2"><UserPlus className="text-emerald-800" size={18} /><h2 className="font-semibold">Invite new team member</h2></div>
            <p className="mt-2 text-xs leading-5 text-slate-500">Send a one-time, 48-hour link to create a Finova staff account. Public staff registration remains disabled; every invite requires password setup and MFA enrollment.</p>
            <label className="mt-5 block text-xs font-semibold text-slate-600">Work email<input required type="email" maxLength={254} value={inviteEmail} onChange={(event) => setInviteEmail(event.target.value)} className="mt-1.5 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm font-normal outline-none focus:border-emerald-700" placeholder="operator@finova.com" /></label>
            <label className="mt-4 block text-xs font-semibold text-slate-600">Operations role<select value={inviteRole} onChange={(event) => setInviteRole(event.target.value)} className="mt-1.5 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-normal">{roles.map((item) => <option key={item} value={item}>{roleLabels[item]}</option>)}</select></label>
            <button disabled={busy !== ""} className="mt-5 inline-flex items-center gap-2 rounded-xl bg-[#174C38] px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"><UserPlus size={15} />{busy === "invite" ? "Sending invite…" : "Send staff invitation"}</button>
          </form>
          <form onSubmit={addStaff} className="rounded-2xl border border-[#E1E9E2] bg-white p-5">
            <div className="flex items-center gap-2"><UsersRound className="text-emerald-800" size={18} /><h2 className="font-semibold">Grant access to an existing account</h2></div>
            <p className="mt-2 text-xs leading-5 text-slate-500">For verified accounts that already exist in Finova. Accounts without MFA enter a restricted enrollment state.</p>
            <label className="mt-4 block text-xs font-semibold text-slate-600">Existing staff email<input required type="email" maxLength={254} value={email} onChange={(event) => setEmail(event.target.value)} className="mt-1.5 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm font-normal outline-none focus:border-emerald-700" placeholder="operator@finova.com" /></label>
            <label className="mt-4 block text-xs font-semibold text-slate-600">Operations role<select value={role} onChange={(event) => setRole(event.target.value)} className="mt-1.5 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-normal">{roles.map((item) => <option key={item} value={item}>{roleLabels[item]}</option>)}</select></label>
            <button disabled={busy !== ""} className="mt-4 inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 disabled:opacity-50">{busy === "add" ? "Provisioning…" : "Grant Operations access"}</button>
          </form>
        </div>
        <aside className="rounded-2xl border border-emerald-100 bg-[#EDF5EF] p-5">
          <div className="flex items-center gap-2 text-[#174C38]"><ShieldCheck size={18} /><h2 className="font-semibold">Access safeguards</h2></div>
          <ul className="mt-4 space-y-2.5 text-xs leading-5 text-slate-700">
            <li>• Customer accounts are not staff unless an Operations administrator explicitly provisions them.</li>
            <li>• MFA is mandatory before a staff session can reach any operations API.</li>
            <li>• Bootstrap administrators configured by infrastructure are visible but cannot be edited here.</li>
            <li>• At least one active Operations administrator must remain.</li>
            <li>• Revoking access blocks future requests; an active session is rejected on its next server check.</li>
          </ul>
        </aside>
      </section>}
      <section className="overflow-hidden rounded-2xl border border-[#E1E9E2] bg-white">
        <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4"><div><h2 className="font-semibold">Pending invitations</h2><p className="mt-1 text-xs text-slate-500">Invite links expire after 48 hours and can be revoked before acceptance.</p></div><span className="text-xs text-slate-500">{invitations.length} invitations</span></div>
        <div className="overflow-x-auto"><table className="w-full min-w-[760px] text-left text-sm">
          <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Work email</th><th className="px-5 py-3">Role</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Expires</th><th className="px-5 py-3">Action</th></tr></thead>
          <tbody className="divide-y divide-slate-100">
            {invitations.map((invitation) => <tr key={invitation.id}>
              <td className="px-5 py-4 font-medium">{invitation.email}</td>
              <td className="px-5 py-4 text-xs">{roleLabels[invitation.role] || invitation.role}</td>
              <td className="px-5 py-4"><span className={`rounded-full px-2.5 py-1 text-xs font-semibold capitalize ${invitation.status === "pending" ? "bg-emerald-50 text-emerald-800" : "bg-amber-50 text-amber-800"}`}>{invitation.status}</span></td>
              <td className="px-5 py-4 text-xs text-slate-500">{new Date(invitation.expires_at).toLocaleString()}</td>
              <td className="space-x-2 whitespace-nowrap px-5 py-4">
                <button disabled={busy === invitation.id} onClick={() => void resendInvitation(invitation)} className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50">{busy === invitation.id ? "Sending…" : "Resend"}</button>
                <button disabled={busy === invitation.id} onClick={() => void revokeInvitation(invitation)} className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-50">{busy === invitation.id ? "Working…" : "Revoke"}</button>
              </td>
            </tr>)}
            {!loading && invitations.length === 0 && <tr><td colSpan={5} className="px-5 py-10 text-center text-sm text-slate-500">No pending staff invitations.</td></tr>}
            {loading && <tr><td colSpan={5} className="px-5 py-10 text-center text-sm text-slate-500">Loading invitations…</td></tr>}
          </tbody>
        </table></div>
      </section>
      <section className="overflow-hidden rounded-2xl border border-[#E1E9E2] bg-white">
        <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4"><div><h2 className="font-semibold">Provisioned team</h2><p className="mt-1 text-xs text-slate-500">Roles are enforced server-side; hiding a page alone is not an authorization control.</p></div><span className="text-xs text-slate-500">{staff.length} accounts</span></div>
        <div className="overflow-x-auto"><table className="w-full min-w-[850px] text-left text-sm">
          <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Team member</th><th className="px-5 py-3">Role</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Provisioned</th><th className="px-5 py-3">Action</th></tr></thead>
          <tbody className="divide-y divide-slate-100">
            {staff.map((member) => {
              const draft = drafts[member.id] || { role: member.role, status: member.status };
              return <tr key={member.id}>
                <td className="px-5 py-4"><div className="font-semibold">{member.name}</div><div className="mt-1 text-xs text-slate-500">{member.email}{member.is_bootstrap && <span className="ml-2 rounded-full bg-blue-50 px-2 py-0.5 text-[9px] font-bold uppercase text-blue-800">Bootstrap</span>}</div></td>
                <td className="px-5 py-4"><select aria-label={`Role for ${member.email}`} disabled={member.is_bootstrap} value={draft.role} onChange={(event) => setDrafts((previous) => ({ ...previous, [member.id]: { ...draft, role: event.target.value } }))} className="rounded-lg border border-slate-200 bg-white px-2.5 py-2 text-xs disabled:bg-slate-50 disabled:text-slate-500">{roles.map((item) => <option key={item} value={item}>{roleLabels[item]}</option>)}</select></td>
                <td className="px-5 py-4"><select aria-label={`Access status for ${member.email}`} disabled={member.is_bootstrap || member.status === "pending_mfa"} value={draft.status} onChange={(event) => setDrafts((previous) => ({ ...previous, [member.id]: { ...draft, status: event.target.value as StaffDraft["status"] } }))} className="rounded-lg border border-slate-200 bg-white px-2.5 py-2 text-xs capitalize disabled:bg-slate-50 disabled:text-slate-500"><option value="active">Active</option><option value="pending_mfa">Pending MFA</option><option value="revoked">Revoked</option></select></td>
                <td className="px-5 py-4 text-xs text-slate-500">{member.created_at ? new Date(member.created_at).toLocaleDateString() : "By deployment config"}</td>
                <td className="px-5 py-4">{member.is_bootstrap ? <span className="text-xs text-slate-400">Managed by infrastructure</span> : <button disabled={busy === member.id || (draft.role === member.role && draft.status === member.status)} onClick={() => void saveStaff(member)} className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40">{busy === member.id ? "Saving…" : "Save access"}</button>}</td>
              </tr>;
            })}
            {!loading && staff.length === 0 && <tr><td colSpan={5} className="px-5 py-14 text-center text-sm text-slate-500"><UsersRound className="mx-auto mb-3 h-6 w-6 text-slate-300" />No staff accounts are provisioned.</td></tr>}
            {loading && <tr><td colSpan={5} className="px-5 py-12 text-center text-sm text-slate-500">Loading staff access…</td></tr>}
          </tbody>
        </table></div>
      </section>
    </div>
  );
}
