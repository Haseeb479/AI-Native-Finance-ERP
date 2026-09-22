# AI-Native Finance ERP

A Pakistan-first AI-Native Finance & ERP SaaS platform combining double-entry general ledger accounting, AP/AR, invoicing, bank reconciliation, expense OCR, and financial reporting with an AI interaction and workflow automation layer.

---

## Core Product Principle

> **Accounting correctness beats AI cleverness.**
> AI can propose, explain, classify, reconcile, draft, and execute approved workflows — but accounting state must always be controlled by deterministic business rules, permissions, audit logs, and the immutable posting engine.

---

## System Architecture

```text
                         ┌─────────────────────┐
                         │     Web Client      │
                         │ React / Next.js     │
                         └──────────┬──────────┘
                                    │
                         ┌──────────▼──────────┐
                         │     API Layer       │
                         │ Laravel / REST      │
                         └──────────┬──────────┘
                                    │
         ┌──────────────────────────┼──────────────────────────┐
         │                          │                          │
 ┌───────▼────────┐       ┌─────────▼────────┐       ┌─────────▼────────┐
 │ Accounting Core│       │ Workflow Engine  │       │ Integration Hub  │
 │ GL / AP / AR   │       │ Approvals/Tasks  │       │ FBR/Banks/etc.   │
 └───────┬────────┘       └─────────┬────────┘       └─────────┬────────┘
         │                          │                          │
         └──────────────────────────┼──────────────────────────┘
                                    │
                         ┌──────────▼──────────┐
                         │    PostgreSQL       │
                         │ Source of truth     │
                         └──────────┬──────────┘
                                    │
               ┌────────────────────┼────────────────────┐
               │                    │                    │
       ┌───────▼──────┐     ┌───────▼──────┐     ┌───────▼──────┐
       │ Redis/Queue  │     │ Object Store │     │ AI Service   │
       │ Jobs/Cache   │     │ S3/MinIO     │     │ Python/FastAPI│
       └──────────────┘     └──────────────┘     └──────────────┘
```

---

## Directory Structure

```text
AI-Native Finance ERP/
│
├── apps/
│   ├── web/                     # Next.js (TypeScript, Tailwind, React Query)
│   ├── api/                     # Laravel REST API (PHP 8.3+, Modular Monolith)
│   └── ai/                      # Python FastAPI (Agent Tools, OCR, LLM Adapters)
│
├── packages/
│   ├── ui/                      # Shared UI components & design tokens
│   ├── types/                   # Cross-service shared TypeScript schemas
│   ├── config/                  # Shared lint/tooling configurations
│   └── accounting-rules/        # Shared accounting business invariants
│
├── docs/                        # Primary specifications & requirements
│   ├── PRD.md                   # Product Requirements Document
│   ├── ARCHITECTURE.md          # System & Software Architecture
│   ├── RULES.md                 # Accounting & Engineering Invariants
│   ├── DESIGN.md                # UI/UX System & Design Tokens
│   ├── TASKS.md                 # Master Implementation Roadmap
│   └── MEMORY.md                # Persistent Working Context
│
├── database/
│   ├── migrations/              # Database migration schemas
│   ├── seeders/                 # Pakistan SME default COA & fixtures
│   └── schemas/                 # Data model & ERD diagrams
│
├── infra/
│   ├── docker/                  # Dockerfiles for services
│   ├── nginx/                   # Reverse proxy configurations
│   └── deployment/              # Staging & production deployment scripts
│
├── tests/
│   ├── integration/             # Cross-module API tests
│   ├── e2e/                     # End-to-end user workflow tests
│   ├── security/                # RBAC & tenant isolation test suite
│   └── ai-evals/                # Grounding & accuracy evaluations
│
├── scripts/                     # Local setup & database seeding utilities
│
├── .github/
│   └── workflows/               # CI/CD automation pipelines
│
├── .env.example                 # Environment template
├── .gitignore                   # Git ignore specifications
└── docker-compose.yml           # Local infrastructure services
```

---

## Core Accounting Invariants

1. **Balanced Entries**: Every posted journal entry must satisfy $\sum \text{Debit} = \sum \text{Credit}$.
2. **Immutability**: Posted journal entries cannot be edited or deleted. Corrections must use reversal entries.
3. **Deterministic Posting**: Only the core posting engine can mutate financial balances. AI is strictly prohibited from direct database mutation.
4. **Tenant Isolation**: Every record must belong to an `organization_id` and `entity_id`. Scoping is strictly enforced server-side.
5. **No Floating-Point Arithmetic**: All monetary quantities use exact decimal/integer representations.
6. **Closed Periods**: Posted periods cannot receive transactions without an explicit, audited reopening workflow.

---

## Local Development Quickstart

### Prerequisites
- Docker & Docker Compose
- Node.js LTS (v20+ or v24+)
- PHP 8.3+ & Composer
- Python 3.11+

### Step 1: Clone and Configure Environment
```bash
cp .env.example .env
```

### Step 2: Start Infrastructure (PostgreSQL & Redis)
```bash
docker-compose up -d
```

---

## Documentation

Full architectural specifications and development guidelines are maintained in the [`docs/`](file:///c:/Users/Seeb/AI-Native%20Finance%20ERP/docs) directory:
- [PRD.md](file:///c:/Users/Seeb/AI-Native%20Finance%20ERP/docs/PRD.md)
- [ARCHITECTURE.md](file:///c:/Users/Seeb/AI-Native%20Finance%20ERP/docs/ARCHITECTURE.md)
- [RULES.md](file:///c:/Users/Seeb/AI-Native%20Finance%20ERP/docs/RULES.md)
- [DESIGN.md](file:///c:/Users/Seeb/AI-Native%20Finance%20ERP/docs/DESIGN.md)
- [TASKS.md](file:///c:/Users/Seeb/AI-Native%20Finance%20ERP/docs/TASKS.md)
- [MEMORY.md](file:///c:/Users/Seeb/AI-Native%20Finance%20ERP/docs/MEMORY.md)
