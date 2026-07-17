# Fix Plan — 2026-07-16-1238

Discovered issues that need attention but are NOT the current task.

## Freeform submission orphan on form delete — no MCP surface today (from #29)
Issue #29 item 3 notes that deleting a form via `Freeform::forms->deleteById($formId)`
for a session-created form trips the same stale SubmissionQuery static map and
can leave an ORPHANED row in `craft_freeform_submissions` (element row removed,
freeform_submissions row not). There is currently NO delete_form MCP tool
(FreeformScaffoldTools exposes only create_form + update_form), so this is not
reachable through the server today. If a delete_form tool is ever added, it must
(a) guard the delete against the stale-static crash (reuse
`FreeformStaleFormCache::guard`), and (b) verify/clean up any orphaned
`craft_freeform_submissions` rows after the cascade — do not ship delete_form
without both.
