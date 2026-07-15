# Asset integration — `upload_asset` against GCS (live-QA item 4, 2026-07-15)

`upload_asset` (`src/tools/AssetTools.php`) verified live against mbd's GCS-backed
`media` volume (`craft\googlecloud\Fs`, google-cloud 2.2.2, Craft 5.10.5). **Verification-only
pass — no code changes.** All three success criteria from issue #21 held with no
adapter-specific breakage.

## What was tested

Test image: a hand-built 4x4 RGB PNG (raw PNG bytes via `zlib`/`struct` in Python — no
PIL/Pillow on the host). Placed at `storage/runtime/mcp-qa-tmp/qa-test.png` on the host,
which is bind-mounted into the DDEV container at `/var/www/html/...` (DDEV default mount
+ this project's `docroot: web`), so the MCP tool's `path` argument (server-visible path)
was `/var/www/html/storage/runtime/mcp-qa-tmp/qa-test.png`.

1. **Upload to volume root.** `upload_asset(path, volume: "media")` → asset created
   (id 3629), `url` = `https://storage.googleapis.com/2rm-hosted-assets/mbd/qa-test.png`,
   `kind: "image"`, `width/height: 4/4`. Confirmed live: `curl` 200 on the public URL, and
   the GCS object existed per `$fs->fileExists()` before cleanup.
2. **Upload to new nested subfolder** (`folder: "qa/2026"`, neither `qa` nor `qa/2026`
   existed). `resolveTargetFolder()` → `ensureFolderByFullPathAndVolume($folder, $volumeModel, false)`
   created **both** levels correctly — `list_asset_folders` showed a new `qa` folder (id 18)
   with a nested `2026` folder (id 19) as its child, and the asset (id 3630) landed with
   `folderId: 19` and `url` ending `.../qa/2026/qa-test.png`. GCS is prefix-only (no real
   directories) but Craft's own folder bookkeeping (the `assetfolders` table) handled this
   transparently — the `false` argument (don't create a physical placeholder object per
   level) is fine on this adapter; no empty "directory marker" objects were needed for the
   nested path to resolve or for later uploads/deletes to find it.
3. **Filename collision.** Re-uploading the same source file to the volume root a second
   time (`avoidFilenameConflicts = true` in `buildAsset()`) produced a de-duplicated name
   (`qa-test_2026-07-15-170606_nthz.png`) rather than an overwrite or a save error. Both
   the original and the de-duplicated object existed independently on GCS afterward.

## One gotcha worth flagging (not a defect, a QA-process note)

Public GCS object URLs (`storage.googleapis.com/...`) can be **edge-cached**: after
hard-deleting the test assets via `Craft::$app->getElements()->deleteElement($asset, true)`
+ `Craft::$app->getAssets()->deleteFoldersByIds()`, `curl` against two of the three public
URLs still returned `200` immediately after deletion. The authoritative check is the FS
adapter itself, not the public URL cache:

```php
$fs = Craft::$app->getVolumes()->getVolumeByHandle('media')->getFs();
$fs->fileExists('qa-test.png'); // false — actually gone
```

All three objects confirmed gone via `fileExists()` immediately post-delete, despite the
stale `200`s. If a future tool needs to assert "this object is really gone" right after a
delete, don't trust the public URL — check the FS adapter or allow for CDN propagation lag.

## Cleanup performed

All 3 test assets (ids 3629, 3630, 3631) hard-deleted via `Craft::$app->getElements()->deleteElement($asset, true)`.
Both scaffold folders (`qa` id 18, `qa/2026` id 19) deleted via `deleteFoldersByIds()`.
Post-cleanup `list_asset_folders(volume: "media")` shows the original 7 folders only, and
`fileExists()` confirmed all 3 GCS objects gone. Local temp dir
(`storage/runtime/mcp-qa-tmp/`) removed from the host.

## Cross-references

- [neo-integration.md](neo-integration.md) — sibling live-QA writeup
- [../plans/qa-feature-backlog.md](../plans/qa-feature-backlog.md)
- [../overview/project.md](../overview/project.md) — QA priority order
