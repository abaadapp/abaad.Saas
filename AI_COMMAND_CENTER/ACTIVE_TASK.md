# Active Task

Status: IN REVIEW — AWAITING OWNER APPROVAL
Task ID: CUSTOMER-AUDIT-01
Notion Task: DEV-6 — 01 — تيست وتشييك وإصلاح: لوحة التحكم
Last updated: 2026-09-01

## Source of truth

This file and the matching Notion task must remain synchronized.

The previously recorded `CUSTOMER-CORE-001 — Products + Inventory / AUDIT ONLY` is no longer the active command. It is superseded by the section-by-section customer audit/fix sequence tracked in Notion.

## Current task — Dashboard

Scope: **Customer Admin Dashboard only**.

Mode: **AUDIT + FIX**.

The dashboard audit/fix has been implemented and merged through PR #9.

- PR: `#9 — Audit 01: fix dashboard branch-scope drift`
- Merge SHA: `540ea7c8815e9bb8340a380bd99371855d470359`
- Branch used: `review/customer-audit-01-dashboard`
- SaaS / Super Admin: out of scope

### Approval gate

Do **not** start the next section until the project owner explicitly approves this task.

Current state: implementation is merged, but owner approval is still pending.

## Next task — Customers

Task: `02 — تيست وتشييك وإصلاح: العملاء`
Notion Task ID: `DEV-7`
Planned branch: `review/customer-audit-02-customers`
Status: `BACKLOG — BLOCKED BY CUSTOMER-AUDIT-01 APPROVAL`
Mode when authorized: **AUDIT + FIX**.

When the current dashboard task is explicitly approved:

1. Mark the dashboard task approved/done in Notion.
2. Move the Customers task from Backlog to In Progress.
3. Update this file so Customers becomes the active task.
4. Audit the full Customers section and fix root causes with the smallest safe changes.
5. Add/update regression tests.
6. Do not touch SaaS / Super Admin.

### Customers scope when authorized

Inspect and validate, where implemented:
- customer CRUD
- search/filtering
- addresses and related customer data
- validation and duplicate prevention
- permissions/authorization
- tenant isolation
- deletion/restoration behavior
- sales and POS integration
- relevant automated tests

Fix confirmed root causes and add regression coverage. Avoid broad refactors or unrelated features.

## Execution rule

If Notion and this file disagree, **stop execution and repair the synchronization before touching product code**.

No new section may start merely because the previous code was merged. Explicit owner approval remains the gate between customer-system sections.