# Freeform integration — API shape & gotchas (Freeform 5.15)

How the `FreeformTools` / `FreeformSerializer` classes talk to Freeform, and the non-obvious things that broke when the duck-typed code first met a real Freeform 5.15 install (2026-07-14 live-QA). All of this was written blind (no Freeform in the dev env) and corrected against the mbd install.

## Verified Freeform 5.15 API surface

- **Form:** `Freeform::getInstance()->forms->getFormById($id)` / `->getFormByHandle($h)` / `->getAllForms()` → `Solspace\Freeform\Form\Types\Regular`.
- **Field layout:** `$form->getLayout()` → `FormLayout`; `->getFields()` → `FieldCollection` (iterable of field objects, e.g. `Solspace\Freeform\Fields\Implementations\TextField`).
- **Field object accessors:** `getHandle()`, `getLabel()`, `getType()`, `isRequired()`. The `$handle`/`$label`/`$required` **properties are non-public** — you must use the getters.
- **Submission:** `Solspace\Freeform\Elements\Submission` — a real Craft element. `Submission::find()->formId($id)->status(null)->count()/->all()` works like any element query.
- **Submission field values:** `$submission->{$handle}` (magic getter) returns the **field object**, whose value is `->getValue()` (also `getValueAsString()`). `$submission->getFieldValue($handle)` does **not** return the value — it throws `Invalid field handle`.

## Bugs found & fixed (2026-07-14)

All four were invisible in the dev env and only surfaced against real Freeform:

1. **Field `handle`/`label` came back null** — `FreeformSerializer::readProp()` checked `property_exists()` and read the property directly, but Freeform's field props are non-public, so it never reached the getter. **Fix:** `readProp` now reads a declared property directly only when it is *public* (reflection check), otherwise falls through to `get<Name>()`.
2. **Field `required` always false** — `readProp('required')` looked for `getRequired()`, which doesn't exist; the accessor is `isRequired()`. **Fix:** added an `is<Name>()` fallback to `readProp`.
3. **Submission field values empty (`get_submission` → `fields: []`, `export` missing columns)** — `submissionFieldValue()` called `getFieldValue()`, which throws; the `catch` returned null and never tried the real path. **Fix:** read `$submission->{$handle}->getValue()`.
4. **`list_forms` submissionCount null** — the subtlest. Yii's `ElementQuery::count()` returns a **string** (e.g. `"15"`). `submissionCount()` is typed `: ?int` in a file with `declare(strict_types=1)`, so returning the string threw a `TypeError` that the method's own `catch (Throwable)` swallowed to null. Invisible in tinker/psysh because that runs in coercive mode. **Fix:** `(int)` cast on the count.

Bugs 1–3 are in `src/support/FreeformSerializer.php`; bug 4 is in `src/tools/FreeformTools.php`.

### General lesson: strict_types + Yii `count()`

Any method returning a Yii/Craft query `count()` directly into an `int`/`?int` return type under `strict_types=1` will `TypeError`. Always `(int)`-cast. Audited 2026-07-14: all other `count()` sites in `src/` are already cast or feed arrays; `Serializer.php:101` and `AssetTools.php:75` return counts as JSON strings (cosmetic, not crashes).

### General lesson: psysh/tinker is coercive

The `tinker` tool (psysh) does **not** apply `strict_types`, so strict-type bugs and other mode-dependent behavior won't reproduce there. Confirm fixes to typed code via the Pest suite (fresh strict process) or a live SIGHUP-reloaded server, not only tinker.

## Verified Freeform 5.15 form-creation API (write side)

Reverse-engineered from `Solspace\Freeform\Services\FormGenerationService::generate()`
(the AI form generator) — the cleanest programmatic, non-AI creation path in the
plugin. There is **no** `FormsService::create()`/`save()`; forms are persisted
by **triggering the control panel's persistence events** with a plain payload.
This is what the `create_form` tool (`FreeformScaffoldTools`) uses.

**Creation flow:**

1. Build a payload `\stdClass` of the shape `{ form, layout }`:
   - `form`: `{ uid, type: Regular::class, settings: { general: { name, handle, type, formattingTemplate:'', storeData:true, sites:[...siteIds], description:'' } } }`
   - `layout`: `{ pages: [{uid, layoutUid, order, label, buttons}], layouts: [{uid}], rows: [{uid, layoutUid, order}], fields: [{uid, rowUid, typeClass, order, properties}] }`
   - The tree is **flat, joined by UIDs**: each field references its `rowUid`, each row/page references its `layoutUid`. `StringHelper::UUID()` for every uid.
2. `$event = new Solspace\Freeform\Events\Forms\PersistFormEvent($payload);`
3. `Event::trigger(FormsController::class, FormsController::EVENT_CREATE_FORM, $event);` — `FormPersistence` (priority 200) creates the `FormRecord`, sets `$event->setForm()`.
4. `Event::trigger(FormsController::class, FormsController::EVENT_UPSERT_FORM, $event);` — `LayoutPersistence` writes pages/rows/fields; `Sites`/`Notification`/`Integration`/`Translation` persistence bundles also listen but **early-return when their payload section is absent**, so a minimal `{form, layout}` payload is safe.
5. `if ($event->hasErrors()) { … $event->getResponseData()['errors'] }` then `$event->getForm()`.

`FormsController::EVENT_CREATE_FORM = 'create-form'`, `EVENT_UPSERT_FORM = 'upsert-form'`
(`controllers/api/FormsController.php`). Resolve via `constant()` rather than
hardcoding to survive value changes.

**Fields are created inline** — they are NOT pre-existing library fields. A field
is just a `typeClass` (`Solspace\Freeform\Fields\Implementations\*Field`) plus a
`properties` object. `LayoutPersistence::getValidatedMetadata()` iterates the field
type's **editable properties** (from `PropertyProvider::getEditableProperties($typeClass)`,
container-resolvable) and reads `$payload.properties.{handle} ?? null`, running each
property's validators. So the payload must supply **every** editable property or
validators fire: `label` and `handle` both carry a `Required` validator. The tool
mirrors Freeform's own `mergeFieldProperties()`: seed each property with its default
(`$property->value`), then override `label`/`handle`/`required` (+ `optionConfiguration`
for dropdowns: `{source:'custom', useCustomValues:true, options:[{label,value,optgroup:false}]}`).

**GOTCHA — form creation requires a user identity.** `FormPersistence::handleFormCreate()`
does `$record->createdByUserId = \Craft::$app->getUser()->getIdentity()->id;` with no
null guard. In the long-running MCP server process there is **no logged-in user**
(`getUser()->getIdentity()` is `null`), so a bare trigger fatals on `null->id`. The
tool sets an admin identity (`User::find()->admin(true)->status(null)->one()`) for the
save and restores it in a `finally`. Verify this works under the real SIGHUP-reloaded
server (see live-verification handoff on issue #19).

**GOTCHA — `count()` in Express edition check.** `FormPersistence` guards Express edition
with `FormRecord::find()->count()`; mbd is `pro`, so untested there. Not our code.

**GOTCHA — stale form cache in the long-running server (#26).** `FormsService` is a
Craft component singleton holding a private `Memo $cache` (`Solspace\Freeform\Library\
Cache\Memo`) meant to live one web request. In the persistent MCP server it survives
across tool calls, and the create path never clears it. Two stale reads followed
`create_form`: `assertHandleAvailable()`'s pre-save `getFormByHandle(newHandle)` caches
a `null` under that handle (Memo caches nulls via `array_key_exists`), so a follow-up
`get_form` by handle returned "not found"; and any earlier `list_forms` froze the
`all-forms` list, so `list_forms` omitted the new form — both until a SIGHUP restart.
Reproduced live across separate tool calls (the `all-forms` entry from one tinker call
was still cached in the next). Fix: `FreeformScaffoldTools::flushFormCache()` clears the
Memo after persist via reflection (`FormsService` exposes no public clear), fully guarded
and echo-only. Mirrors Neo's `flushBlockTypeCaches()` — see
[neo-integration.md](neo-integration.md#long-running-server-cache-hazards-audit-26).

## `update_form` — editing an existing form (verified 2026-07-15, issue #20)

Reverse-engineered read-only against live Freeform 5.15.16 (mbd form id 3,
"Contact Form", 7 fields / 6 rows / 1 page) — the same events as `create_form`,
but with an existing form id, plus a UID-preservation contract that isn't
needed on create. All of this was traced via `tinker` (reading Freeform's own
source and querying its tables directly) and `run_query`; no writes were made.

**Update vs. create entry point.** `FormsController::post($id)` — the same CP
endpoint create_form's docs above describe — branches on whether `$id` is
null: `new PersistFormEvent($payload, $id)`, then trigger `EVENT_UPDATE_FORM`
(not `EVENT_CREATE_FORM`) followed by the same `EVENT_UPSERT_FORM`.
`FormPersistence::handleFormUpdate()` loads the `FormRecord` by
`$event->getFormId()` (there is no `handleFormCreate`-style `payload->uid`
lookup on update — the uid in the payload's `form` object is ignored).

**Submission data lives in a per-form content table, keyed by field id, not
handle or uid.** Each form has its own table
`freeform_submissions_<handle>_<formId>` with one column per storable field,
named `<handle>_<fieldId>` (verified: form 3's table has `email_10`,
`project_state_13`, etc. — field id 10's column is literally named with `10`
in it). `FormContentTable` (an `EVENT_UPSERT_FORM` listener, priority 305,
after `LayoutPersistence`'s 300) reads `$event->getFieldRecords()` — the
fields `LayoutPersistence` successfully saved this request — and calls
`ContentManager::performDatabaseColumnAlterations()`, which for each **current**
field id: renames its column if the handle changed (same id → no data loss),
adds a column if the id is new, and **drops the column** for any id no longer
present. This happens automatically once the same two events `create_form`
already triggers fire — no extra step needed on our side.

**UID reuse is what keeps a field's id (and column) the same across a save.**
`LayoutPersistence::handleLayoutSave()` (also on `EVENT_UPSERT_FORM`) diffs the
payload's `pages`/`layouts`/`rows`/`fields` arrays against the form's current
records **by `uid`**, per record type independently
(`getStarterPack()`/`getRecords()`, all scoped `where(['formId' => ...])`):
a uid present in both is updated in place (`$record = $existingRecords[$uid]`,
same underlying DB id — so `FormFieldRecord.id` and hence the content column
survive); a uid present in the payload but not the DB is inserted as new; a
uid in the DB but **absent from the payload** is deleted
(`$existingRecords[$staleUid]->delete()`). This applies independently to
pages, layouts, rows, and fields — so **any existing row not included in the
payload's `rows` array is deleted, even if some field still (incorrectly)
points at it**, meaning every row referenced by any field you're keeping must
also be included in `rows`.

Confirmed live (`SELECT id, formId, rowId, order, type, uid FROM
freeform_forms_fields WHERE formId = 3`): fields can share a row (id 12 and 13
both `rowId = 14`, orders 0/1 — a CP-built side-by-side layout) — reusing a
kept field's exact `rowUid` (not assigning it a fresh solo row) preserves that
grouping automatically without any extra bookkeeping.

**Practical consequence for `update_form`:** add = new uid (empty new column).
Remove = uid omitted (column dropped, submission data for that field lost —
intentional). Reorder = same uid, new `order`/row placement (zero content
impact — order isn't part of the content table at all).

**Fields outside the v1 type subset must never be silently "removed" by
omission.** A CP-built form can have field types `create_form`/`update_form`
don't support (file upload, signature, group, table, rating, etc. — see the
full `FieldInterface::TYPE_*` list). Since `update_form`'s `fields` input is a
*complete* desired list, a caller who doesn't know about (or isn't trying to
touch) such a field would otherwise have it silently deleted. `update_form`
resolves this by never matching or removing a field whose stored `type`
doesn't reverse-map to a v1 keyword (`FreeformFormPlan::resolveExistingType()`)
— those fields are always carried through unchanged in the payload. If one of
them shares a row with a field the caller *is* editing, the tool refuses the
whole update rather than guess how to split or preserve that row.

**Form-level settings must be echoed back verbatim.**
`FormPersistence::getValidatedMetadata()` iterates **every** settings
namespace (`SettingsProvider::getSettingNamespaces()` — verified live: only
`general` and `behavior` exist in 5.15.16) and for any property missing from
`payload->settings->{namespace}->{property}` falls back to that property's
**type default**, not the form's current value. `create_form` only ever sends
`general` because there's nothing to lose on a brand-new form; `update_form`
must instead read `craft_freeform_forms.metadata` (confirmed shape:
`{"behavior": {...11 properties...}, "general": {...13 properties...}}`),
`json_decode()` it **without** the assoc flag (so `->` property access works
for `getValidatedMetadata()`'s reads — JSON arrays still decode to plain PHP
arrays regardless, only `{}` objects need the stdClass form), and pass the
whole decoded object through as `payload.form.settings` untouched. Skipping
this would silently reset `behavior` (success message, redirect URL, spam
duplicate-check window, etc.) to defaults on every `update_form` call.

**Page settings (buttons, label) are similarly echoed back unchanged** by
reading `freeform_forms_pages.metadata` (shape `{"buttons": {...}}`) directly,
since `update_form` v1 never edits page-level settings.

## Known-still-broken

- **`get_form` `notifications` / `connections` / `spamSettings` all return null.** The `FreeformSerializer::sectionAttributes()` keyword-dump approach doesn't hit Freeform 5's real structure (notifications/integrations live in dedicated services, not flat form attributes). This defeats the tool's stated "why didn't this submission create an entry" purpose. Not yet fixed — see [plans/qa-feature-backlog.md](../plans/qa-feature-backlog.md).

## Cross-references

- [plans/create-form-tool.md](../plans/create-form-tool.md) — the missing write side (form creation)
- [plans/qa-feature-backlog.md](../plans/qa-feature-backlog.md)
- [decisions/deferred/2026-07-14-freeform-write-tools.md](../decisions/deferred/2026-07-14-freeform-write-tools.md)
