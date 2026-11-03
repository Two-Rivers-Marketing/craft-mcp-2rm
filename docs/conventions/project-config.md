---
type: Convention
title: Project Config and Schema Scripts
description: Flushing project config from console scripts, never hand-editing YAML, Neo sortOrder collisions and DB-vs-YAML order drift, and block type creation constraints.
tags: [project-config, neo, schema, console-scripts, yaml, sortOrder]
generated: { by: human:justinl, at: 2026-09-17T00:00:00Z }
---

# Project Config and Schema Scripts

## Console scripts don't flush project config

Creating fields, entry types, or block types via the Craft API in a console script (or tinker) does NOT write project-config YAML — the writer normally fires on `EVENT_AFTER_REQUEST`, which a bare CLI script never reaches. The DB tables update immediately and the CP shows the change, but `config/project/` stays untouched and `project-config/diff` reports clean for the wrong reason. Always end the script with `Craft::$app->getProjectConfig()->saveModifiedConfigData();`, then run `php craft project-config/write` to regenerate YAML from the DB store. Element saves (entries, assets) are unaffected — only schema-level saves need this. Confirm with `php craft project-config/diff` before committing.

## Neo block types can't be created from YAML alone

Writing a new block type YAML file by hand and running `project-config/apply` fails with `Undefined array key "sortOrder"` in Neo's `handleChangedBlockType`. Neo expects the block type to already exist in its own DB tables before it can reconcile a YAML change against them. Create the block type via the MCP `create_block_type` tool or the Craft API first — that writes both the DB rows and the project-config YAML. Never hand-edit the YAML afterwards; make further adjustments through the Craft API, the Neo API or craft-mcp. The review gate: on a fresh checkout, `project-config/apply` is a no-op and `project-config/diff` is clean.

## Neo sortOrder collisions

Neo writes block types AND groups into one flat array (`neo.orders.<field>` in project config, backed by `spicyweb/craft-neo`'s `BlockTypes` service) keyed by `sortOrder - 1`. A group and a block type sharing a sortOrder value silently overwrite each other in that array. Symptoms: group headings vanish from the CP, blocks appear filed under the wrong group, or a fresh `project-config/apply` throws a sortOrder error that looks unrelated to the actual cause. After creating block types, check the field's orders YAML (`application/config/project/neo/orders/*.yaml`) for duplicate sortOrder values across groups and block types together, not just within one or the other, and renumber if any collide.

## Neo order drift between DB and YAML

In-process Neo edits (through `tinker` or the Neo API) update the stored project config and YAML but not `neoblocktypes.sortOrder` — Neo's `handleChangedOrders` only rewrites the DB on an external `apply`. `project-config/diff` stays clean, but the CP menu groups by the DB position, and a later Neo-API save of a block type writes its order slot from the stale `sortOrder`, which duplicates or drops entries in the order list and fails the next deploy.

After any in-process Neo schema edit, compare the DB to the order YAML:

```sql
SELECT bt.handle, IFNULL(g.name,'NULL') grp, bt.sortOrder FROM neoblocktypes bt
LEFT JOIN neoblocktypegroups g ON g.id = bt.groupId ORDER BY bt.sortOrder;
SELECT name, sortOrder FROM neoblocktypegroups ORDER BY sortOrder;
```

Each `sortOrder` must equal the 1-based line number of that block type or group in `config/project/neo/orders/<fieldUid>.yaml` (`grep -n '^- ' …`), and the file must list every block type and group exactly once. On mismatch, back up the database and run `php craft project-config/apply --force`, which re-syncs `sortOrder` from the YAML. Then re-run the comparison and confirm the positions match. If the DB order doesn't move, fix it through Neo's API. Make no other schema save until the order agrees. See CBS-19 in `content-builder-standards.md`.

## When to use project-config/apply vs project-config/write

`project-config/apply` reads YAML and applies it to the DB — the deploy direction. `project-config/write` reads the DB and writes YAML — the development direction. After a console script modifies schema, run `write` to capture what the script did. After pulling YAML changes from git, run `apply` to bring the local DB in line. Don't mix them up: running `apply` right after a `write` is a no-op (DB and YAML already agree), but running `write` after someone else's `apply` overwrites their incoming YAML changes with your local DB state, silently losing their work.
