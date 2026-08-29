# Abaad AI Command Center

This directory is the shared planning and execution-control layer for Abaad SaaS.

## Roles

### ChatGPT — Product/Architecture Lead
ChatGPT owns:
- product planning
- architecture guidance
- prioritization
- technical decisions
- implementation briefs
- review checklists
- risk analysis
- acceptance criteria
- maintaining the files in this directory when requested by the project owner

ChatGPT should not assume that a task is implemented merely because it was planned. Implementation status must be verified from the repository.

### Claude Code — Executor
Claude Code is primarily responsible for implementation.
It should:
1. Read `CLAUDE.md` in the repository root.
2. Read the relevant files in this directory before starting a task.
3. Implement only the approved/current task scope.
4. Preserve existing architecture unless a change is explicitly requested.
5. Run appropriate tests and checks.
6. Report what changed, tests run, failures, risks, and any deviations.

## Working model

The project owner discusses goals and decisions with ChatGPT.
ChatGPT converts them into structured plans and implementation instructions here.
Claude Code executes those instructions in the codebase.
ChatGPT can then review the repository changes and plan the next step.

## Core files

- `MASTER_PLAN.md` — product direction, priorities, architecture intentions.
- `ACTIVE_TASK.md` — the single current implementation brief for Claude Code.
- `DECISIONS.md` — important product and engineering decisions.
- `EXECUTION_PROTOCOL.md` — rules Claude Code must follow when implementing work from this command center.
- `BACKLOG.md` — future work not yet approved for implementation.

## Source of truth

For implementation instructions, `ACTIVE_TASK.md` is the current source of truth.
If there is a conflict between older planning notes and `ACTIVE_TASK.md`, the active task wins unless the project owner says otherwise.

Never store passwords, API keys, production credentials, private customer data, or other secrets in this directory.