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

## Known-still-broken

- **`get_form` `notifications` / `connections` / `spamSettings` all return null.** The `FreeformSerializer::sectionAttributes()` keyword-dump approach doesn't hit Freeform 5's real structure (notifications/integrations live in dedicated services, not flat form attributes). This defeats the tool's stated "why didn't this submission create an entry" purpose. Not yet fixed — see [plans/qa-feature-backlog.md](../plans/qa-feature-backlog.md).

## Cross-references

- [plans/create-form-tool.md](../plans/create-form-tool.md) — the missing write side (form creation)
- [plans/qa-feature-backlog.md](../plans/qa-feature-backlog.md)
- [decisions/deferred/2026-07-14-freeform-write-tools.md](../decisions/deferred/2026-07-14-freeform-write-tools.md)
