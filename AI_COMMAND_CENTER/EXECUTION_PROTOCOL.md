# Execution Protocol

## Claude Code operating rules

When executing a task prepared through the AI Command Center:

1. Read `AI_COMMAND_CENTER/ACTIVE_TASK.md` completely.
2. Inspect the actual existing implementation before editing.
3. Do not blindly follow stale file paths, class names, or assumptions in a brief; reconcile them with the current repository.
4. Stay within scope. If a prerequisite or architectural conflict is discovered, stop and report it rather than silently expanding scope.
5. Preserve tenant isolation, authorization, financial correctness, inventory integrity, and backward compatibility unless the task explicitly changes them.
6. Do not delete or bypass tests merely to make CI pass.
7. Add or update tests for changed behavior.
8. Run the most relevant automated checks available in the repository.
9. Do not introduce secrets or production credentials.
10. Avoid unrelated refactors in the same task.

## Completion report

After implementation, report:

- Summary
- Files changed
- Database/migration impact
- API impact
- UI impact
- Tests added/updated
- Commands/checks run and results
- Known limitations
- Deviations from the task brief
- Follow-up recommendations

## Planning boundary

Claude Code may identify problems and suggest improvements, but should not independently turn suggestions or backlog items into implementation work unless explicitly authorized by the project owner.