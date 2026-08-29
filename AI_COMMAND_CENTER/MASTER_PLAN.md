# Abaad SaaS — Master Plan

Status: Living document
Owner: Project Owner
Planning lead: ChatGPT
Execution: Claude Code

## Product objective

Build Abaad into a reliable, production-grade SaaS platform for business/store management, with strong operational workflows, accounting/tax correctness, clear UX, multi-tenant safety, and maintainable engineering.

## Planning principles

1. Correctness before feature count.
2. Protect tenant isolation and financial data integrity.
3. Avoid destructive rewrites unless the benefit is explicit and justified.
4. Complete core business primitives before adding superficial breadth.
5. Every important workflow needs validation, permissions, auditability where appropriate, and tests.
6. Prefer incremental, reviewable implementation tasks.
7. Repository reality beats documentation: inspect current code before designing changes.

## Current planning priorities

The exact ordering must be confirmed against the latest code before implementation, but known product-completeness areas to evaluate include:

- sales returns and refund workflow
- credit-note / VAT-safe return behavior
- invoice void/cancellation workflow
- branch-to-branch inventory transfers
- inventory correctness and traceability
- permissions and audit trail coverage
- reporting completeness
- operational UX and error handling

These are planning candidates, not automatic implementation authorization.

## Architecture policy

Before proposing major architecture changes, inspect:
- current framework and dependency versions
- domain/service layer
- tenancy model
- database schema and migrations
- order/invoice/payment/inventory flows
- frontend architecture
- tests
- deployment constraints

Any major migration or rewrite requires a written decision in `DECISIONS.md` before execution.