# Active Task

Status: ACTIVE — AUDIT ONLY
Task ID: RELEASE-AUDIT-001
Last updated: 2026-08-30

## Objective

Perform a full launch-readiness audit of the existing Abaad system without redesigning or restructuring it.

The system must be checked across:
1. Platform / SaaS management
2. Customer Admin system
3. POS
4. Supporting routes, controllers, services/domain logic, permissions, tenancy, database behavior, jobs/integrations and tests

## Important

This task is AUDIT ONLY.
Do not make product-code changes as part of this task unless the project owner explicitly authorizes a fix batch later.

Read `AI_COMMAND_CENTER/RELEASE_FINISHING_PLAN.md` before starting.

## What to inspect

Build an inventory of all production pages and workflows from the repository, then trace every meaningful interaction through the full stack.

For each page/workflow verify:
- navigation works
- every visible primary button/action is wired
- form submit paths exist and validate correctly
- routes point to real handlers
- handlers call valid business logic
- database/state changes are correct
- response and frontend state are consistent
- loading/success/error states are usable
- permissions are enforced server-side
- tenant isolation is preserved
- financial/inventory/tax side-effects remain correct
- relevant tests exist and genuinely cover the behavior

## Required focus areas

### Platform / SaaS
Businesses, subscriptions, platform users, settings, reports, activity, dashboard, onboarding/provisioning/demo flows used in production.

### Customer Admin
Dashboard, branches, customers, devices, employees/roles/permissions, products, orders, purchases, suppliers, inventory, expenses, finance, payroll, preparation, marketing, reports/settings and every other production module discovered during the audit.

### POS
Device setup, cashier selection, shift open/close, product/cart flow, customer selection, sale creation, checkout, payment methods, receipts/invoices, orders/history, order details, settings, stock effects, failure/retry behavior.

## Classification

Each finding must be one of:
- PASS
- BUG
- UNWIRED
- INCOMPLETE
- UX
- BLOCKER
- DEFER

Severity:
- P0 launch blocker
- P1 core operational issue
- P2 secondary/management issue
- P3 polish

## Deliverable

Create/update `AI_COMMAND_CENTER/RELEASE_AUDIT.md` with:

1. Executive launch-readiness summary.
2. System/module inventory.
3. Audit coverage matrix.
4. Findings grouped by P0/P1/P2/P3.
5. Exact file paths/routes/controllers involved for every issue.
6. Recommended minimal fix for each issue.
7. Test coverage gaps.
8. Production/deployment/configuration risks visible from the repo.
9. A proposed sequence of small fix batches.
10. A final section called `UNVERIFIED_RUNTIME ITEMS` for anything that cannot be proven from static repository inspection and requires manual/browser/runtime testing.

Do not hide uncertainty. Static code inspection must not be described as proof that a browser interaction works in production.

## Definition of done

The audit is complete only when all discovered production modules under Platform, Admin and POS have been inventoried and reviewed enough to either PASS them or attach a finding/unverified-runtime item.

Do not begin fixes automatically after completing the audit. Wait for the next approved task.