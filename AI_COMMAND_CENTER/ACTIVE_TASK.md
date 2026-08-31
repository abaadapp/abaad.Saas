# Active Task

Status: IN PROGRESS — PARTIAL COVERAGE
Task ID: CUSTOMER-AUDIT-02
Notion Task: DEV-7 — 02 — تيست وتشييك وإصلاح: العملاء
Last updated: 2026-09-01

## Source of truth

This file and the matching Notion task must remain synchronized.

## Current task — Customers

Scope: **Customer Admin Customers section**, including relevant sales/POS integration points.

Mode: **AUDIT + FIX**.

Execution has already occurred on `main` through Claude Code.

- Commit: `41569920240568cd495cb3d4cd87e593877704d5`
- Tag: `v6.19`
- Tag message: `قسم العملاء: تدقيق وإصلاح`
- Current remote branch containing the commit: `main`
- SaaS / Super Admin: out of scope

### Covered in v6.19

- customer CRUD
- addresses and related customer data surfaced in the customer profile/edit flow
- authorization, including permanent-delete permission hardening
- tenant isolation across audited customer routes
- deletion/restoration behavior
- partial input validation: phone, soft-deleted customer handling, name length
- export filtering consistency
- soft-deleted-customer import protection
- atomic customer import
- conditional loyalty-points redemption

### Remaining before task 02 can be closed

1. **Sales / POS / loyalty integration**
   - audit customer matching in `storeCustomer`: ID, then phone, then name
   - verify behavior when deleting a customer with open invoices/orders
   - test customer search behavior inside POS

2. **Search runtime coverage**
   - English customer names
   - phone numbers in different formats
   - `%` and other edge-case search input

3. **Remaining validation coverage**
   - email
   - tax number
   - prove by regression test that a `branch_id` belonging to another business is rejected

## Approval / sequence note

Task 01 — Dashboard is still **not owner-approved**. Task 02 nevertheless executed in reality and reached v6.19. This file records that factual state without inventing approval for task 01.

Do not mark task 02 done and do not start task 03 until the three remaining areas above are completed and the owner approves the result.

## Execution rule

If Notion and this file disagree, stop and repair synchronization before additional product-code work.
