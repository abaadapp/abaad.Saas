# Abaad Planning Backlog

Items here are candidates for investigation/planning. They are NOT implementation instructions.

## Product completeness

- Evaluate proper sales-return workflow.
- Evaluate VAT-safe credit-note handling.
- Evaluate invoice void/cancellation semantics.
- Evaluate branch inventory transfers.
- Audit inventory movement traceability.
- Audit permissions around sensitive financial and stock operations.
- Audit reporting gaps and reconciliation workflows.
- Plan Saud WhatsApp invoice delivery: keep Saud's number active in WhatsApp Business while enabling official API-based invoice sending from Abaad only (no chat inbox), including PDF/link delivery, delivery status tracking, secure credentials, and webhook handling.

## Engineering

- Map current domain architecture and critical write paths.
- Maintain a current system capability map.
- Identify high-risk areas lacking tests.
- Review performance bottlenecks based on evidence, not assumptions.
- Review deployment/rollback and observability readiness.

## UX

- Review the complete POS workflow.
- Review error/empty/loading states.
- Review Arabic/RTL consistency where applicable.
- Review operational flows for minimal clicks without sacrificing safety.

Priorities should be selected by the project owner with ChatGPT after inspecting current repository state.