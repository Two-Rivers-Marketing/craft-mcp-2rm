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

## Known-still-broken

- **`get_form` `notifications` / `connections` / `spamSettings` all return null.** The `FreeformSerializer::sectionAttributes()` keyword-dump approach doesn't hit Freeform 5's real structure (notifications/integrations live in dedicated services, not flat form attributes). This defeats the tool's stated "why didn't this submission create an entry" purpose. Not yet fixed — see [plans/qa-feature-backlog.md](../plans/qa-feature-backlog.md).

## Cross-references

- [plans/create-form-tool.md](../plans/create-form-tool.md) — the missing write side (form creation)
- [plans/qa-feature-backlog.md](../plans/qa-feature-backlog.md)
- [decisions/deferred/2026-07-14-freeform-write-tools.md](../decisions/deferred/2026-07-14-freeform-write-tools.md)
