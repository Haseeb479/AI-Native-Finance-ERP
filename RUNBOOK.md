# Enterprise Operations & Disaster Recovery Runbook

This document defines authoritative operational procedures for the **AI-Native Finance ERP** platform. It satisfies production requirements **P1-36** (Backup & Restore Verification), **P1-37** (Migration Safety), **P3-08** (Operations Runbook), and **P3-09** (Architectural Incident Handling).

---

## 1. Service Level Objectives (SLOs) & Targets

The production Compose Nginx listener serves HTTP only. Terminate TLS at a trusted
load balancer or ingress before traffic reaches it; do not publish the HTTP port
directly to the public internet.

| Metric | Target | Verification Method |
|---|---|---|
| **Recovery Point Objective (RPO)** | **<= 24 Hours** (daily scheduled PostgreSQL snapshot) | Automated verification via `php artisan backup:verify --rpo=24` |
| **Recovery Time Objective (RTO)** | **< 60 Minutes** to full restored database state | Measured by `php artisan backup:verify --restore-drill` |
| **API Availability** | **99.95%** | Uptime monitoring on `/api/v1/health` and `/up` |
| **Database Double-Entry Invariance** | **100% Zero-Imbalance Tolerance** | Enforced by PostgreSQL trigger `trg_assert_balanced_journal` |

---

## 2. Backup & Disaster Recovery Procedures (P1-36)

### 2.0 Authentication email delivery
Production password-reset and email-verification links require `APP_URL` to be
the public API URL, `FRONTEND_URL` to be the public web URL, and `MAIL_MAILER`
to be a configured real transport (for example SMTP, SES, or Postmark).
`MAIL_FROM_ADDRESS` must be a verified sender. Production readiness reports a
configuration failure when a non-delivering mailer or invalid URL/sender is
configured. Readiness also fails unless `APP_ENV=production`,
`APP_DEBUG=false`, and the test suite has a verified passing execution report.
For SMTP, set a reachable host, valid port, username, and password; the readiness
check fails closed if authenticated SMTP settings are incomplete.
Verify delivery using the password-reset and verification flows before opening
registration to users.

### 2.0.1 Finova Control Center staff access
The Control Center is separate from customer workspaces and exposes company
operations metadata only. Production requires `CONTROL_CENTER_STAFF_EMAILS` as
a comma-separated allowlist of existing staff login email addresses. Access is
denied when the allowlist is empty; customer tenant roles do not grant staff
access. Provision this value through the production secret/configuration
manager, review membership regularly, and follow [the Control Center
operations guide](./docs/control-center.md). Staff directory and detail
requests are recorded in the append-only staff audit trail.

### 2.0.2 Google sign-in and demo inquiries
To enable Google sign-in, configure the same Google OAuth Web client ID as
`GOOGLE_CLIENT_ID` for the API and `NEXT_PUBLIC_GOOGLE_CLIENT_ID` for the web
build, and allowlist the production web origin in Google Cloud. If either value
is absent or mismatched, Google sign-in will fail closed; email/password login
remains available. Google cannot bypass a user's enabled MFA.

Public walkthrough requests are rate-limited and stored for staff follow-up.
They do not book a meeting or send a calendar invite. Provisioned staff review
them in `/control-center/demo-requests`; access to this inbox is audited.

### 2.1 Automated Snapshot Creation
PostgreSQL backups are generated as `pg_dump` custom-format archives, hashed
with SHA-256, and copied to the separately administered S3-compatible
`BACKUP_REMOTE_DISK` with server-side AES-256 encryption:
```bash
php artisan backup:verify --create-snapshot --rpo=24
```
The scheduler runs this command daily at 01:00. The remote backup bucket must
be outside the database host and MinIO deployment failure domain; configure
versioning, retention, and access controls independently. Production startup
requires backup-storage credentials and a restore-drill database role.

### 2.2 Integrity & RPO Verification
CI/CD schedules run verification hourly or prior to deployment:
```bash
php artisan backup:verify --rpo=24
```
Verification performs:
1. Manifest discovery and age verification against the RPO target.
2. SHA-256 validation of both the local archive and its remote copy.
3. A restore drill creates a uniquely named temporary database, restores the
   archive, checks required tables, posted journal balances, and tenant
   consistency, then removes the temporary database. The restore credentials
   must have permission to create and drop databases.

### 2.3 Database Restoration Procedure (Cold Recovery)
If total database loss occurs:
1. **Stop ingress traffic**:
   ```bash
   docker compose -f infra/docker/docker-compose.prod.yml stop api web nginx
   ```
2. **Retrieve the latest archive and its manifest** from the remote backup bucket
   using approved storage tooling, and copy both into the API container's shared
   `storage/app/backups/` directory:
   ```bash
   LATEST_BACKUP=/path/to/retrieved/backup-YYYYMMDD-HHMMSS.dump
   docker cp "$LATEST_BACKUP" finance_prod_api:/var/www/html/storage/app/backups/
   docker cp "$LATEST_BACKUP.manifest.json" finance_prod_api:/var/www/html/storage/app/backups/
   docker compose -f infra/docker/docker-compose.prod.yml exec api \
     php artisan backup:verify --restore-drill --rpo=24
   ```
   If the archive is older than 24 hours, record the RPO breach and set `--rpo`
   to its actual age; do not report the original RPO as met.
3. **Restore PostgreSQL data** to the recovered database:
   ```bash
   docker compose -f infra/docker/docker-compose.prod.yml exec -T postgres \
     pg_restore --exit-on-error --no-owner --no-privileges \
     -U finance_user -d finance_erp < "$LATEST_BACKUP"
   ```
4. **Run post-restore integrity and reconciliation checks**:
   ```bash
   docker compose -f infra/docker/docker-compose.prod.yml exec -T api php artisan reconcile:subledgers --all
   docker compose -f infra/docker/docker-compose.prod.yml exec -T api php artisan test --filter=SubledgerReconciliationTest
   ```
5. **Resume services**:
   ```bash
   docker compose -f infra/docker/docker-compose.prod.yml up -d
   ```

---

## 3. Migration Safety & Zero-Downtime Rollback Plan (P1-37)

All database migrations must obey the following zero-data-loss rules:
1. **Always wrap in transactions**: `$withinTransaction = true;` (except PostgreSQL concurrent index creation).
2. **Never drop columns destructively in one release**:
   - Release N: Add new column, backfill asynchronously.
   - Release N+1: Deprecate old column.
   - Release N+2: Drop old column in separate maintenance migration.
3. **Pre-Migration Dry Run**:
   ```bash
   php artisan migrate --pretend
   ```
4. **Safe Rollback**:
   Every migration must implement an atomic `down()` handler tested under CI.
   ```bash
   php artisan migrate:rollback --step=1
   ```

---

## 4. Queue Recovery & Idempotent Worker Processing (P1-22, P2-09)

The platform enforces universal database-backed idempotency (`idempotency_keys` table):
1. **Dead Letter Queue (DLQ) Inspection**:
   ```bash
   php artisan queue:failed
   ```
2. **Safe Replay**:
   Because all mutation services (`InvoiceService`, `BillService`, `RevenueRecognitionService`, `InventoryService`) verify idempotency keys and acquire row locks (`lockForUpdate`), replaying failed jobs does not duplicate ledger journals or invoices:
   ```bash
   php artisan queue:retry all
   ```
3. **Flush Poison Messages**:
   ```bash
   php artisan queue:forget <id>
   ```

---

## 5. Security Incident & Tenant Isolation Escalation (P0-01 - P1-30)

### 5.1 Cross-Tenant Access Violation
If any query or log contains `tenant_isolation_violation`:
1. Search access logs by correlation ID:
   ```bash
   grep "<X-Correlation-ID>" /var/log/finance-erp/api.log
   ```
2. Invalidate compromised user session and API tokens immediately:
   ```bash
   php artisan tinker --execute="Laravel\Sanctum\PersonalAccessToken::where('tokenable_id', \$userId)->delete();"
   ```

### 5.2 AI Microservice Abuse or Injection Flag
When `test_guardrails` or AI Gateway logs alert on prompt injection or unapproved tool calls:
- The request is intercepted by `apps/ai/src/guardrails/security.py`.
- Execution is terminated prior to calling any backend capability tool.
- Audit event is logged with `status: rejected` and `audit_event: AI_GATEWAY_INJECTION_BLOCKED`.

## Local database backups

A Windows scheduled task (Finova Daily Postgres Backup, 02:00 daily) runs `scripts\backup-local-postgres.ps1`. It dumps PostgreSQL, restores the dump into a throwaway database to prove it is usable, and deletes automatic backups older than 14 days. Dumps are written to `apps/api/storage/app/backups/` (git-ignored). Run it manually any time with `powershell -ExecutionPolicy Bypass -File scripts\backup-local-postgres.ps1`.

