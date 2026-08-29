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