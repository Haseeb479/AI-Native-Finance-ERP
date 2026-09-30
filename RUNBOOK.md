# Enterprise Operations & Disaster Recovery Runbook

This document defines authoritative operational procedures for the **AI-Native Finance ERP** platform. It satisfies production requirements **P1-36** (Backup & Restore Verification), **P1-37** (Migration Safety), **P3-08** (Operations Runbook), and **P3-09** (Architectural Incident Handling).

---

## 1. Service Level Objectives (SLOs) & Targets

| Metric | Target | Verification Method |
|---|---|---|
| **Recovery Point Objective (RPO)** | **< 24 Hours** (Daily snapshot + WAL archiving) | Automated verification via `php artisan backup:verify --rpo=24` |
| **Recovery Time Objective (RTO)** | **< 60 Minutes** to full restored database state | Benchmarked restoration rate: 25 MB/sec minimum |
| **API Availability** | **99.95%** | Uptime monitoring on `/api/v1/health` and `/up` |
| **Database Double-Entry Invariance** | **100% Zero-Imbalance Tolerance** | Enforced by PostgreSQL trigger `trg_assert_balanced_journal` |

---

## 2. Backup & Disaster Recovery Procedures (P1-36)

### 2.1 Automated Snapshot Creation
Database backups are generated with SHA-256 cryptographic manifests:
```bash
php artisan backup:verify --create-snapshot --rpo=24
```
Output manifests are saved in `storage/app/backups/` and mirrored to cold S3/MinIO off-site buckets.

### 2.2 Integrity & RPO Verification
CI/CD schedules run verification hourly or prior to deployment:
```bash
php artisan backup:verify --rpo=24
```
Verification performs:
1. Manifest discovery and age verification against RPO target.
2. Cryptographic SHA-256 validation comparing actual archive bytes with manifest.
3. RTO estimate based on payload size and decompression benchmarking.

### 2.3 Database Restoration Procedure (Cold Recovery)
If total database loss occurs:
1. **Stop ingress traffic**:
   ```bash
   docker compose -f infra/docker/docker-compose.prod.yml stop api web nginx
   ```
2. **Retrieve latest verified snapshot**:
   ```bash
   LATEST_BACKUP=$(ls -t storage/app/backups/*.json | head -1)
   sha256sum "$LATEST_BACKUP" # verify against .manifest.json
   ```
3. **Restore PostgreSQL data**:
   ```bash
   docker compose -f infra/docker/docker-compose.prod.yml exec -T postgres psql -U finance_user -d finance_erp < "$LATEST_BACKUP"
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
