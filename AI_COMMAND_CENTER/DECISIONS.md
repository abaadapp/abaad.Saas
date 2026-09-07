# Decision Log

This file records durable product and engineering decisions so future work does not repeatedly reopen settled questions without new evidence.

## Format

### DEC-XXX — Title
- Date:
- Status: Proposed / Approved / Superseded
- Context:
- Decision:
- Why:
- Consequences:
- Revisit when:

---

### DEC-001 — AI collaboration model
- Date: 2026-08-30
- Status: Approved
- Context: Abaad uses both ChatGPT and Claude Code during product development.
- Decision: ChatGPT acts as planning/advisory/architecture lead through `AI_COMMAND_CENTER`; Claude Code acts primarily as implementation executor.
- Why: Separate strategic planning and implementation while keeping decisions and instructions versioned with the codebase.
- Consequences: Approved implementation work should be captured in `ACTIVE_TASK.md`; Claude should read the command-center instructions before execution.
- Revisit when: The development workflow or tooling changes materially.
### DEC-002 — Frontend behaviour is tested in a browser, not read from source
- Date: 2026-09-08
- Status: Approved
- Context: The suite is PHPUnit only. It reaches what the server sends and stops there. Behaviour that exists only in the browser — keyboard navigation, whether a dialog stays open, whether an empty value is rendered as "no attachment" and therefore offered a destructive replace button — was guarded by PHP tests that read the `.tsx` file and asserted a substring (`e.key === 'ArrowDown'`). Those guards prevent deletion but prove nothing about behaviour, and at least one mutation survived under them (the catalog window's counter drifting from its list).
- Decision: Add Vitest + jsdom + Testing Library. Frontend tests live in `tests/js/`, run with `npm test`, and are part of the release pipeline alongside `php artisan test`, `tsc --noEmit`, `pint --test` and `npm run build`. Source-reading guards are removed wherever a real test replaces them.
- Why: Two guards saying the same thing drift; the weaker one gives false confidence. A user-visible defect shipped in v6.141 (a receipt the viewer may not read was rendered as "no receipt", exposing an upload button that overwrites the stored file) was invisible to every PHP test and would have been caught by one render.
- Consequences: `vitest.config.ts` is separate from `vite.config.js`, so the production build carries none of the test toolchain. Components that need rendering are exported from their page module (`ProductDialog`, `ReceiptCell`); extracting a cohesive piece for testability is acceptable, contorting a screen to satisfy the runner is not. Missing jsdom APIs (`scrollIntoView`) are stubbed in `tests/js/setup.ts` rather than removed from the screen. `npm test` runs in CI's `frontend` job alongside `tsc --noEmit` and the build, and by hand before every release like the rest of the pipeline. (An earlier draft of this entry said the repository had no CI. It does — `.github/workflows/ci.yml`, running the suite on SQLite and on PostgreSQL plus a frontend job.)
- Revisit when: Full-page rendering is needed often enough that mocking `AdminLayout` becomes worth a shared harness.

### DEC-003 — An expected unique-key collision must be caught inside a savepoint
- Date: 2026-09-08
- Status: Approved
- Context: Four places write a row and expect a unique index to reject it sometimes, catch the violation and carry on: a duplicate WhatsApp event, a branch-stock row created twice, a document token two tills request at once, and an order number another cashier reached first. That is correct on SQLite and MySQL. It is invalid on PostgreSQL: the first failing statement aborts the whole transaction, and every statement after it is rejected with `25P02` until rollback — so catching the exception saves nothing, the transaction is already dead. Production runs PostgreSQL. Changing an order's status while a duplicate WhatsApp message was recorded aborted the status change itself, not just the message; and `PosController::createNumbered`'s five-attempt retry loop was inert, because attempts 2–5 ran inside the transaction attempt 1 had killed.
- Decision: All four call sites go through `App\Support\Contention` (`attempt()` returns null on collision, `retry()` re-tries), which runs each write via `DB::transaction($write, 1)`. Nested inside an open transaction that emits a `SAVEPOINT` and rolls back only to it; outside one it is an ordinary transaction. A test greps `app/` and `routes/` for `catch (UniqueConstraintViolationException` outside `Contention` itself and fails on any new occurrence.
- Why: This was the entire cause of CI's PostgreSQL job failing — 19 of its 20 failures — and it was a live production defect, not a test artifact. It was invisible locally because the development engine is SQLite.
- Consequences: A local PostgreSQL 16 (`brew install postgresql@16`) is now the way to reproduce engine-specific failures; iterating through CI at ~11 minutes a round is not. The twentieth failure was separate: `SilentDataLossTest` asserted the order of a `pluck` with no `orderBy`, which PostgreSQL does not guarantee — assume no order unless one is requested.
- Revisit when: A fifth call site genuinely needs different collision handling, or the development engine changes.

### DEC-004 — A document's history is derived from its documents, not stored beside them
- Date: 2026-09-08
- Status: Approved
- Context: The purchase-order detail page (the last unbuilt item of the purchase-order brief, §31) shows a workflow timeline: created, each goods-receipt note submitted/approved/rejected, each supplier invoice raised/approved/rejected/cancelled, with the person who signed each. The obvious implementation is an `events` table written at every transition.
- Decision: No new column and no events table. Every entry is built at read time from timestamps that already exist — `created_at`, `approved_at`, `rejected_at` — plus `approval_status` to tell a cancellation from a rejection (both are written to the same `rejected_at`/`rejection_reason` pair). An event with no timestamp is dropped rather than given a guessed date.
- Why: A second record of what happened drifts from the documents themselves, and the document is the truth. It also costs nothing to backfill: notes migrated from before the approval workflow already carry their stamps.
- Consequences: The page cannot show transitions that were never stamped — "sent to the supplier" has no column, so it is not claimed. Building it exposed that `approved_at`/`rejected_at` were not cast on `GoodsReceiptNote` or `SupplierInvoice`: `optional($n->approved_at)->format(...)` was being called on a string, and `optional()` returns null on a non-object without throwing, so the goods-receipt screen had shown "approved" with no approval date since it was written, silently. The casts are added and pinned by a test.
- Revisit when: A transition that must be shown has no timestamp of its own — then give that transition a column, not the page an events table.
