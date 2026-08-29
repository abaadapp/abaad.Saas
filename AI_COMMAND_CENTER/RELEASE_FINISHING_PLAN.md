# Abaad Release Finishing Plan

Status: ACTIVE — audit and finishing phase
Last updated: 2026-08-30

## Goal

Finish the current product and ship it. This phase is NOT a rewrite and NOT a broad architecture project.

The goal is to verify that the existing SaaS platform, customer admin system, and POS are complete enough for launch, with every visible interaction connected to working backend behavior and acceptable error handling.

## Product surfaces

1. Platform / SaaS management (`resources/js/Pages/Platform`)
2. Customer admin system (`resources/js/Pages/Admin`)
3. POS (`resources/js/Pages/Pos`)
4. Shared authentication, profile, layouts, components, APIs/routes, jobs and integrations that support these surfaces

## Hard constraints

- No framework rewrite.
- No large refactor just for cleanliness.
- No speculative features during finishing.
- Do not rename or reorganize working modules unless required to fix a launch blocker.
- Prefer the smallest safe fix.
- Protect tenant isolation, inventory correctness, financial records, permissions and tax data.

## Audit method

For every page and major workflow, verify this chain:

UI page -> visible action/button/form -> route/API -> controller/action -> service/domain logic -> database/state change -> response -> UI feedback -> authorization -> tests.

Every interactive item must be classified as:

- PASS — works as intended.
- BUG — implemented but incorrect/broken.
- UNWIRED — UI exists but action is missing/not connected.
- INCOMPLETE — flow exists but important branch/edge case is missing.
- UX — functional but launch-quality polish/error feedback is missing.
- BLOCKER — unsafe for production or prevents a core workflow.
- DEFER — non-essential improvement that should not delay launch.

## Priority order

### P0 — Launch blockers
- login/access/tenant isolation
- tenant creation and activation
- subscription/business access needed to operate
- POS device setup and cashier/shift access
- sale creation and checkout
- payment persistence
- receipt/invoice generation
- inventory deduction and stock integrity
- critical order state handling
- permissions preventing unauthorized actions
- fatal errors, broken routes, broken buttons

### P1 — Core operational finishing
- customers
- products/categories/pricing/tax
- orders and order details
- shifts and cash handling
- purchases and suppliers
- inventory adjustments/counts/movements
- branches
- employees and roles
- expenses/finance required for normal use
- settings that directly affect operations
- reporting used for daily operations

### P2 — SaaS control panel finishing
- businesses
- subscriptions
- platform users
- platform settings
- reports
- activity/audit views
- demo/provisioning flows if used in sales/onboarding

### P3 — Polish
- empty states
- loading states
- validation messages
- destructive confirmation
- success/error feedback
- disabled states
- responsive/layout problems
- Arabic/English wording consistency where applicable
- stale links and navigation inconsistencies

## Definition of a checked screen

A screen is not marked complete because it renders. It is complete only when:

1. Every visible primary action has been traced.
2. Forms validate invalid input and complete valid input.
3. Server errors do not leave misleading UI state.
4. Authorization is correct.
5. Tenant-scoped data cannot leak across businesses.
6. Destructive actions are deliberate and recoverable where business rules require it.
7. Resulting data is correct in dependent modules (inventory, finance, orders, reports, etc.).
8. Existing automated tests are identified; missing high-risk coverage is flagged.

## Execution policy

During the audit, do not fix unrelated code opportunistically.

For each finding record:
- ID
- surface/module
- page/workflow
- exact issue
- severity P0/P1/P2/P3
- code paths involved
- expected behavior
- recommended minimal fix
- regression risk
- tests required
- status

Fixes should be issued to Claude Code in small batches through `ACTIVE_TASK.md`, beginning with P0 findings.

## Release gate

Abaad is launch-ready when:

- zero known P0 issues remain;
- all core sale/payment/receipt/inventory flows pass;
- platform and customer authentication/authorization pass;
- no known cross-tenant data exposure exists;
- primary navigation contains no dead production links;
- all primary buttons/forms in launch scope are connected;
- required production configuration/deployment checks are documented;
- automated test suite passes after final fixes;
- remaining P2/P3 items are explicitly accepted as post-launch work.

## Current instruction

We are in finishing mode. New feature ideas should go to BACKLOG unless they are required to make an existing promised workflow actually usable.