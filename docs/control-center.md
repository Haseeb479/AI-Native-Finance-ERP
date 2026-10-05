# Finova Operations Portal

Finova staff currently access the local Operations portal at
`http://localhost:3000/ops/login`. It is a separate path from the customer workspace; there
is no staff-mode switch, shared login page, or public staff registration. In this local-only
setup, Operations is reachable only from the machine running the application. For a future
hosted deployment, use a dedicated staff hostname behind TLS and configure its exact name
in the web and API runtimes. The application rejects Operations requests from hosts that
are not explicitly allowed:

```dotenv
OPS_ALLOWED_HOSTS=localhost
OPS_PORTAL_URL=http://localhost:3000
```

For a hosted staff portal, set both values to the dedicated HTTPS hostname. Do not add a
customer-facing hostname. In local development `localhost` and `127.0.0.1` are accepted if
`OPS_ALLOWED_HOSTS` is unset. HTTP invitation links are allowed for loopback addresses only;
production hosted invitation links must use HTTPS.

## Staff authentication and MFA

New teammates accept a time-limited email invitation, choose a password, then sign in at
the dedicated staff portal. First sign-in without MFA opens restricted enrollment, which
displays a one-time authenticator setup key and recovery codes. Enrollment must be
completed before any operations API can be used. Existing MFA-enabled staff complete an
authenticator challenge at sign-in. Staff who forget their password can start the password
recovery flow from the Operations sign-in screen and return to the dedicated portal after
resetting it.

The web app stores the staff API token only in the `finova_ops_token` HttpOnly,
SameSite=Strict cookie, scoped to `/api/ops`; its token is never returned to browser
JavaScript. The ordinary customer token cookie is not accepted by the staff proxy. Staff
sessions last at most eight hours in the browser; explicitly sign out to revoke the API
token immediately.

Configure a bootstrap staff allowlist and role map in the API environment. At least one
bootstrap staff member should have the `ops_admin` role; this is the recovery administrator
for the in-portal team manager. The role map must be JSON whose keys are the exact staff
email addresses:

```dotenv
CONTROL_CENTER_STAFF_EMAILS=ops-admin@example.com,sales@example.com,support@example.com
CONTROL_CENTER_STAFF_ROLES='{"ops-admin@example.com":"ops_admin","sales@example.com":"ops_sales","support@example.com":"ops_support"}'
```

Supported roles are:

| Role | Portal capabilities |
| --- | --- |
| `ops_admin` | Company directory, demo pipeline, support/access queue, service readiness, staff access management and audit log |
| `ops_manager` | Company directory, demo pipeline, support/access queue, service readiness and audit log |
| `ops_sales` | Company directory and demo-request follow-up |
| `ops_support` | Company directory and support/access cases |
| `ops_readonly` | Company directory and overview without prospect, support-case, or diagnostic detail |

Bootstrap role grants apply only to addresses in `CONTROL_CENTER_STAFF_EMAILS`. An
allowlisted address omitted from the role map is restricted to `ops_readonly`. A malformed
role map grants no elevated role. Changes to either server-side setting require the API
configuration cache to be refreshed/redeployed.

After the bootstrap administrator signs in, they can manage staff in **Team access**:

* **Invite a new teammate:** send a one-time email link that expires after 48 hours. The
  recipient creates a password through the staff portal; accepting the invitation verifies
  the email and creates a `pending_mfa` staff identity. Public staff registration stays off.
* **Grant an existing account access:** select an already verified account; an account
  without MFA remains restricted until it enrolls.
* **Manage access:** assign or change one of the roles below, revoke staff access, or revoke
  an invitation before it is accepted. Bootstrap accounts remain visible but read-only.

Invite acceptance is single-use and rechecks that the inviting administrator still has
active, MFA-protected administrator access. The invite token is stored hashed. The API
prevents revoking the last active administrator. Only operators with deployment-secret
access can change bootstrap accounts.

## Available staff workflows

* **Company directory and onboarding follow-up:** review lifecycle, account owner, member
  count, subscription summary, and whether the primary entity/branch exist. The underlying
  organization record remains read-only.
* **Demo pipeline:** authorized sales/operations staff view demo inquiries and set the
  status to `new`, `contacted`, `scheduled`, or `closed`. This records operational
  follow-up status only; it does not send email or book a meeting.
* **Support and access:** authorized support/operations staff create and track internal
  cases for access, billing, onboarding, technical, or other issues. This is an internal
  case tracker, not yet a customer-facing ticket-submission channel or notification system.
* **Team access:** Operations administrators invite new teammates or grant access to
  already-existing verified identities, assign roles, and revoke staff access/invitations.
  New invitees must enroll MFA before operations access activates. Bootstrap identities
  remain infrastructure-managed.
* **Staff audit trail:** administrators/managers can review append-only access/action events,
  including invite acceptance, allowed/denied outcomes, resource IDs, and role/status changes.
* **Service readiness:** managers/admins can run the existing production-readiness
  diagnostics. Failed checks remain failures; the portal never reports a fabricated
  healthy state.
* **Subscription overview:** the company detail page displays existing plan/status/period
  data, read-only. Plan, billing, entitlement, organization-status, and user-access
  mutations are intentionally not exposed until their billing-provider and approval
  workflows are implemented.

The portal does not expose ledgers, invoices, bills, bank transactions, journals, or
customer documents, and has no impersonation capability. Customer access issues are
tracked as cases; this implementation does not grant staff access to customer financial
records to resolve them.

## Audit trail

Each staff API access or action is checked against the bootstrap allowlist or provisioned
staff directory, MFA requirement, and role. Allowed and denied decisions are written to
`staff_audit_events`. Demo status changes, support-case creation/status changes, and staff
role/access changes pass through the staff-only API and are audited. Audit records are
append-only at the application/database layer; protect and back up this table as security
audit data.

## Public demo requests

Prospective customers submit demo inquiries at `/request-demo`. The public flow confirms
receipt only; it does not claim to reserve a time or send an invitation. Sales staff must
contact the prospect using the details submitted.

## Deployment checklist

1. Create the `ops.finova.com` DNS record and TLS-enabled reverse-proxy virtual host. Route
   that hostname to the Next.js web service while preserving the original `Host` header.
2. Set `OPS_ALLOWED_HOSTS=ops.finova.com` in the web runtime. Do not add `app.finova.com`.
3. Configure `CONTROL_CENTER_STAFF_EMAILS` and `CONTROL_CENTER_STAFF_ROLES` in the API
   runtime for bootstrap access. Configure `OPS_PORTAL_URL` to the dedicated staff URL and
   ensure its hostname is included in `OPS_ALLOWED_HOSTS` on both API and web runtimes.
   Configure an operational mail provider for staff invitations.
4. Back up the target database, then run migrations, including staff support cases, the
   staff directory and invitations, and audit metadata. Verify API access and the
   append-only audit table on the intended local database.
5. Provision at least one MFA-enabled bootstrap `ops_admin`, sign in at
   `http://localhost:3000/ops/login`, send an invitation through Team access, and verify
   the complete email → password creation → staff sign-in → MFA enrollment workflow.
   Confirm invited members stay restricted until their own MFA setup succeeds.
6. Verify customer-domain requests to `/ops` and `/api/ops` return not found, customer
   accounts are denied, non-MFA staff are restricted to setup, and each role can only
   access its permitted pages/actions.
7. Verify allowed and denied events are written to `staff_audit_events`; test sign-out
   revokes the staff API token.

The earlier `/control-center` paths redirect to the operations routes for compatibility;
they are subject to the same staff-host gate and separate staff cookie.
