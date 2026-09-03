# Abaad — ChatGPT → GitHub → Claude Code Control Center

## Purpose

The user controls development conversationally through ChatGPT. GitHub Issues are the canonical task records, Notion is the synchronized visual dashboard, and Claude Code is the implementation agent.

## Control flow

1. User gives ChatGPT an instruction.
2. ChatGPT creates or updates the canonical GitHub Issue.
3. Notion mirrors the canonical issue data through the GitHub→Notion sync.
4. When the user explicitly asks to start Claude, ChatGPT adds the `claude:ready` label to that issue.
5. `.github/workflows/claude-code-agent.yml` removes the trigger label and changes the Issue Status to `In Progress`.
6. Claude reads the complete issue, implements only that scope, tests it, pushes a branch, and creates/updates a PR.
7. Claude updates the issue with branch, PR URL, tests and implementation summary.
8. On success the workflow changes Issue Status to `In Review`. On failure it changes Issue Status to `Blocked`.
9. Notion receives the updated canonical GitHub data.
10. Merge is never automatic. Final merge requires human review/approval.

## Execution trigger and status

Only one Claude label is used:

- `claude:ready` — one-time execution trigger.

The task lifecycle is stored in the canonical Issue `Status` field, not in runtime labels:

`Ready → In Progress → In Review`

If automated execution fails:

`In Progress → Blocked`

After review, statuses such as `Changes Requested`, `Ready to Merge`, `Merged`, and `Done` can be used as appropriate.

## ChatGPT operating rules

When the user asks to add a task:
- Create/update the GitHub Issue first.
- Include Type, Status, Owner, Priority, Area / Files, Branch, GitHub PR, Depends On, Prompt and Notes.
- Verify the corresponding Notion mirror.
- Do not start Claude unless the user asks to execute/start the task or clearly authorizes immediate execution.

When the user asks Claude to start a task:
- Resolve the exact GitHub Issue.
- Verify it has a complete actionable Prompt and a non-conflicting scope.
- Add `claude:ready` once.
- Do not merge the result automatically.

When the user asks for status:
- Read GitHub first (Issue, workflow/PR/branch state as applicable).
- Treat GitHub as canonical.
- Use Notion as the management/dashboard mirror.

## Security

Required GitHub Actions secret for Claude Code:

- `CLAUDE_CODE_OAUTH_TOKEN`

Notion synchronization additionally requires:

- `NOTION_TOKEN`

Never place either secret in an issue, Notion field, repository file, workflow input, commit, PR, or persistent chat content.
