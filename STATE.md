# STATE — craft-mcp-2rm

**Updated:** 2026-07-15
**Status:** Active — live-QA of the fork's write surface against mbd

## Current status

2RM fork of `stimmt/craft-mcp` (MCP server for Craft, Neo content-builder + Freeform tools). Mid live-QA pass against the mbd install (Craft 5.10.5, Neo 5.5.10, Freeform 5.15.16). Items 1–3 done; 4–5 pending. Remaining backlog is filed as GitHub issues (#18–#26) armed for the nightshift AFK agent.

## Recent accomplishments

- Live-QA'd + fixed 3 tool surfaces (all committed to `main`, live-verified):
  - Freeform reads (`6996896`), Neo tree writes (`0fbdb9c`), Neo scaffolding (`6435b83`).
- Stood up project wiki (`docs/wiki/`) + `CLAUDE.md`.
- Filed backlog as issues #18–#26 (user stories, success criteria, model labels).
- Overhauled the nightshift skill (model delegation, PHP support, worker/orchestrator split) and proved model-label routing on #13/#14.

## Next steps

- [ ] Commit nightshift SKILL.md changes (personal tooling repo).
- [ ] QA + merge branch `nightshift/2026-07-15-0353` (#13/#14 fixes).
- [ ] Push `main` (commits ahead of origin, unpushed).
- [ ] Run the armed issue queue; arm #20 after #19 lands.
- [ ] #18 (get_form notifications/connections) — highest-value remaining Freeform gap.
- [ ] Items 4 (upload_asset/GCS) + 5 (Neo childBlocks/positioning) live-QA — need live mbd+MCP.

## Open questions

- Migrate fork to a versioned dependency (Packagist/VCS tag) vs the current symlink path repo?
- Does the AFK/nightshift worktree environment have the live mbd MCP connection required by the live-QA issues (#18/#19/#21/#22/#26)?
