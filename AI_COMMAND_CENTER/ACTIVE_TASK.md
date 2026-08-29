# Active Task

Status: ACTIVE — AUDIT ONLY
Task ID: CUSTOMER-CORE-001
Last updated: 2026-08-30

## Objective

Audit and finish the first core area of the customer system: **Products + Inventory**.

This is a launch-finishing task, not a redesign, rewrite, or architecture project.

## Current release strategy

We will finish the customer-facing operational system section by section before touching SaaS/Platform management.

**Platform / SaaS is explicitly out of scope until the customer Admin + POS system is approved.**

Do not work on pricing, plans, subscriptions, SaaS administration, or cosmetic redesign in this task.

## Phase 1 scope — Products + Inventory

Inspect the complete existing implementation related to products and inventory across:
- Customer Admin pages
- POS dependencies/integration points
- routes
- controllers
- requests/validation
- models
- services/domain/support classes
- database schema/migrations
- permissions
- tenant/branch isolation
- tests

### Products
Verify all existing product capabilities discovered in the repository, including where applicable:
- product listing/search/filtering
- create product
- edit product
- view/details
- delete/archive/disable behavior
- categories/types/variants/options if implemented
- SKU/barcode behavior
- pricing and tax fields only for functional correctness (do not redesign pricing strategy)
- cost fields
- inventory tracking flags
- branch/product availability
- images/media if production functionality exists
- validation and duplicate prevention
- permissions
- POS visibility and product loading

### Inventory
Verify all existing inventory capabilities discovered in the repository, including where applicable:
- stock overview
- stock quantities by branch/location
- stock addition/receiving
- adjustments
- stock counts
- inventory movements/history
- purchase-related stock effects
- sale/POS stock deductions
- order edits/cancellations effects where currently supported
- low/out-of-stock behavior
- negative-stock rules
- supplier/purchase dependencies needed for inventory correctness
- permissions
- tenant and branch isolation

## Audit method

For every meaningful page/action:
1. Identify the UI action/button/form.
2. Trace it to its route.
3. Trace route to controller/handler.
4. Trace handler to domain/service/model/database behavior.
5. Verify validation and authorization.
6. Verify tenant/branch isolation.
7. Verify downstream stock/product effects.
8. Locate relevant automated tests.
9. Classify the result.

Classification:
- PASS
- BUG
- UNWIRED
- INCOMPLETE
- UX
- BLOCKER
- DEFER

Severity:
- P0 = prevents safe launch/use of Products or Inventory
- P1 = core operational defect
- P2 = important secondary issue
- P3 = polish only

## Important constraints

- AUDIT FIRST. Do not modify product code yet.
- Do not restructure existing architecture.
- Do not perform broad refactors.
- Do not add unrelated features.
- Prefer the smallest safe correction when fixes are later authorized.
- Repository reality beats assumptions/documentation.
- Do not audit Platform/SaaS in this phase.

## Deliverable

Create/update `AI_COMMAND_CENTER/SECTION_AUDITS/PRODUCTS_INVENTORY.md` containing:

1. Products capability inventory.
2. Inventory capability inventory.
3. Page/action coverage matrix.
4. Full-stack wiring status for each action.
5. POS integration points.
6. P0/P1/P2/P3 findings.
7. Exact affected files/routes/controllers/services for each finding.
8. Minimal recommended fix for each finding.
9. Existing automated-test coverage and missing tests.
10. `UNVERIFIED RUNTIME ITEMS` requiring browser/runtime/manual validation.
11. Final readiness verdict for Products.
12. Final readiness verdict for Inventory.
13. Proposed small implementation batches, ordered by launch importance.

## Definition of done

This phase is complete when Products and Inventory can each be given one of:
- READY
- READY AFTER LISTED FIXES
- NOT READY

Do not start implementing fixes automatically after the audit. Wait for the project owner's next approval.