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
