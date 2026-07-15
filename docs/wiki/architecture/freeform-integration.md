# Freeform integration — API shape & gotchas (Freeform 5.15)

How the `FreeformTools` / `FreeformSerializer` classes talk to Freeform, and the non-obvious things that broke when the duck-typed code first met a real Freeform 5.15 install (2026-07-14 live-QA). All of this was written blind (no Freeform in the dev env) and corrected against the mbd install.

## Verified Freeform 5.15 API surface

- **Form:** `Freeform::getInstance()->forms->getFormById($id)` / `->getFormByHandle($h)` / `->getAllForms()` → `Solspace\Freeform\Form\Types\Regular`.
- **Field layout:** `$form->getLayout()` → `FormLayout`; `->getFields()` → `FieldCollection` (iterable of field objects, e.g. `Solspace\Freeform\Fields\Implementations\TextField`).
- **Field object accessors:** `getHandle()`, `getLabel()`, `getType()`, `isRequired()`. The `$handle`/`$label`/`$required` **properties are non-public** — you must use the getters.
- **Submission:** `Solspace\Freeform\Elements\Submission` — a real Craft element. `Submission::find()->formId($id)->status(null)->count()/->all()` works like any element query.
- **Submission field values:** `$submission->{$handle}` (magic getter) returns the **field object**, whose value is `->getValue()` (also `getValueAsString()`). `$submission->getFieldValue($handle)` does **not** return the value — it throws `Invalid field handle`.
- **Notifications:** `Freeform::getInstance()->notifications->getAllFormNotifications()` → all form-scoped `NotificationTemplateRecord`s **across every form** (it takes no args; the query is `where(['not', ['formId' => null]])`). Filter by `->formId` to scope to one form. Record attributes (Yii ActiveRecord magic props): `id`, `handle`, `name`, `formId`, `subject`, `fromName`, `fromEmail`, `replyToEmail`, `cc`, `bcc`, `bodyHtml`, …
- **Element connections & spam are "integrations".** `Freeform::getInstance()->integrations->getForForm($form, $type)` → the form's integrations of a type **category**. Categories (from `getAllIntegrationTypes()[*]->type`): `elements`, `single`, `captchas`, `spam-blocking`, `email-marketing`, `crm`, `ai`, `webhooks`, `payment-gateways`, `other`. **Element connections** (submission → created Craft entry/element, the "why didn't this create an entry" mechanism) are type **`elements`**. Spam protection is types **`captchas`** + **`spam-blocking`**. An unknown/empty type returns `[]` (no throw).
- **Integration object accessors:** `getId()`, `getHandle()`, `getName()`, `isEnabled()`, and `getTypeDefinition()` → a `Solspace\Freeform\Attributes\Integration\Type` whose public `->type` holds the category string.

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

## get_form settings sections (fixed 2026-07-15)

`get_form`'s `notifications` / `connections` / `spamSettings` used to return `null` — the old `FreeformSerializer::sectionAttributes()` keyword-dump over flat form attributes didn't match Freeform 5's structure (these live in dedicated services, not on the form). Now resolved against the real API above:

- `FreeformTools::getForm()` fetches the three collections (notifications filtered by formId; `elements` integrations for connections; `captchas`+`spam-blocking` for spam) and passes the raw objects to `FreeformSerializer::form()`.
- `FreeformSerializer::form()` shapes them via `notification()` / `integration()` item serializers, staying a pure duck-typed reader (no Freeform dependency, unit-testable with stubs). Each service read degrades to `[]` on failure rather than throwing.
- Each section is now a **list** (empty = nothing configured), never `null` from a failed lookup. An empty `connections` list means the form creates no Craft element — the answer to "why didn't this submission create an entry".
- The dead `sectionAttributes()`/`matchesAnyKeyword()`/`attributesOf()` helpers were removed.

Live-verified against mbd `contactForm` (id 3): `connections: []`, `spamSettings: [{handle: recaptcha, type: captchas, enabled: true}]`, `notifications: []`.

## Cross-references

- [plans/create-form-tool.md](../plans/create-form-tool.md) — the missing write side (form creation)
- [plans/qa-feature-backlog.md](../plans/qa-feature-backlog.md)
- [decisions/deferred/2026-07-14-freeform-write-tools.md](../decisions/deferred/2026-07-14-freeform-write-tools.md)
