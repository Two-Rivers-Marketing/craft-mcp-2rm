<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\support;

use Craft;
use ReflectionObject;
use Throwable;

/**
 * Clears every known in-process Freeform read-cache after a structural form
 * write (create_form / update_form), so a subsequent get_form / list_forms in
 * the SAME long-running MCP session reflects the mutation instead of a stale
 * pre-mutation read (issue #30).
 *
 * WHY THIS EXISTS
 * ---------------
 * Freeform's read path memoizes form/field data across THREE different
 * shapes of in-process cache, none of which Craft's own cache layer
 * (clear_caches) touches — it is all plain PHP object state:
 *
 *  - FormsService::$cache memoizes getFormById()/getFormByHandle()/
 *    getAllForms() results (issue #26's flushFormCache()) in a
 *    Solspace\Freeform\Library\Cache\Memo instance with a public clear().
 *  - FieldProvider::$cache memoizes a form's field ROWS in getRows($formId),
 *    keyed by the form's real (stable) database id, also a Memo.
 *  - LayoutsService — reachable via Freeform::getInstance()->formLayouts —
 *    is a DIFFERENT shape: it has no Memo at all. It keeps its OWN plain
 *    private arrays: $pages[$formId], $rows[$formId], $layouts[$formId]
 *    (all keyed by the form's stable DB id) and $formLayouts[spl_object_id]
 *    (keyed by Form object identity). Form::getLayout() calls
 *    Freeform::getInstance()->formLayouts->getLayout($form) directly — it
 *    does NOT go through FieldProvider — so clearing FormsService and
 *    FieldProvider alone leaves getLayout() re-assembling pages/rows from
 *    these frozen arrays forever, even for a freshly loaded Form entity.
 *    Confirmed live: reflection-emptying pages/rows/layouts/formLayouts on
 *    Freeform::getInstance()->formLayouts is what actually makes a
 *    same-session getFormById($id)->getLayout()->getFields() reflect an
 *    update_form add/reorder; clearing FormsService + FieldProvider WITHOUT
 *    this left it stale (issue #30, reopened).
 *
 * All three services are registered as genuine Yii container singletons
 * (Freeform::initContainerItems()), so — unlike LayoutPersistence (see
 * FreeformLayoutCacheReset) — the live instance is reachable directly:
 * FormsService via Freeform::getInstance()->forms, FieldProvider via
 * \Craft::$container->get(FieldProvider::class), LayoutsService via
 * Freeform::getInstance()->formLayouts. This gives two cache-clearing
 * taxonomies within one static reset():
 *   1. container-singleton-with-Memo (FormsService, FieldProvider) — a
 *      single reflection-get + Memo::clear() per service.
 *   2. container-singleton-with-plain-array-memo keyed by stable id
 *      (LayoutsService) — no Memo object to call clear() on; the memo IS
 *      the property, so it must be reflection-emptied to `[]` directly.
 * (A third taxonomy, event-bound-per-instance, covers LayoutPersistence —
 * see FreeformLayoutCacheReset — which is not a container singleton at all.)
 *
 * Guarded and best-effort throughout: if Freeform renames a class, drops a
 * targeted property, or changes the Memo API, resetting silently no-ops and
 * never fails the persisted write. All Freeform identifiers are plain strings
 * (never imported) so this class loads safely when the plugin is absent.
 *
 * Intended call site: FreeformScaffoldTools::triggerPersist(), the single
 * path shared by create_form and update_form (and any future Freeform
 * structural-write tool, e.g. issue #31's delete_form), so every write stays
 * consistent without callers having to remember to flush anything
 * themselves.
 *
 * @author 2RM
 */
final class FreeformFormCacheReset {
    /** Freeform plugin class, for FormsService::class::getInstance()->forms. */
    public const FREEFORM_CLASS = 'Solspace\\Freeform\\Freeform';

    /** Freeform's field/row provider (string FQCN — never imported). */
    public const FIELD_PROVIDER_CLASS = 'Solspace\\Freeform\\Bundles\\Fields\\FieldProvider';

    /**
     * Freeform's layout-assembly service (string FQCN — never imported).
     * Reached via Freeform::getInstance()->formLayouts, NOT the container
     * directly, mirroring resolveFormsService()/resolveFieldProvider().
     */
    public const LAYOUTS_SERVICE_CLASS = 'Solspace\\Freeform\\Services\\Form\\LayoutsService';

    /** Property name of the Cache\Memo on FormsService / FieldProvider. */
    private const CACHE_PROPERTY = 'cache';

    /**
     * Plain-array memo properties on LayoutsService (verified against the
     * installed 5.15.16 vendor source: private array $pages, $layouts,
     * $rows, $formLayouts — none of them a Memo object).
     */
    private const LAYOUTS_SERVICE_ARRAY_PROPERTIES = ['pages', 'layouts', 'rows', 'formLayouts'];

    /**
     * Resolve and clear the memo on every known Freeform read-cache service.
     * Never throws.
     */
    public static function reset(): void {
        try {
            self::clearMemo(self::resolveFormsService());
            self::clearMemo(self::resolveFieldProvider());
            self::clearArrayProps(self::resolveLayoutsService(), self::LAYOUTS_SERVICE_ARRAY_PROPERTIES);
        } catch (Throwable) {
            // Best-effort: never let cache-busting fail the persisted save.
        }
    }

    /**
     * Clear the given service's memoized Cache\Memo, if it has one shaped as
     * expected. Public and side-effect-isolated so it can be exercised
     * directly against plain stubs without booting Craft or Freeform.
     */
    public static function clearMemo(?object $service): void {
        if (!is_object($service)) {
            return;
        }

        $reflection = new ReflectionObject($service);
        if (!$reflection->hasProperty(self::CACHE_PROPERTY)) {
            return;
        }

        $cache = $reflection->getProperty(self::CACHE_PROPERTY)->getValue($service);
        if (!is_object($cache) || !method_exists($cache, 'clear')) {
            return;
        }

        $cache->clear();
    }

    /**
     * Reset every named property on $service to an empty array, for services
     * (like LayoutsService) that memoize directly in plain private arrays
     * rather than behind a Cache\Memo object. Public and side-effect-isolated
     * so it can be exercised directly against plain stubs without booting
     * Craft or Freeform. Missing properties or non-array current values are
     * skipped rather than failing the whole call.
     *
     * @param array<int, string> $properties
     */
    public static function clearArrayProps(?object $service, array $properties): void {
        if (!is_object($service)) {
            return;
        }

        $reflection = new ReflectionObject($service);

        foreach ($properties as $name) {
            self::clearArrayProp($reflection, $service, $name);
        }
    }

    private static function clearArrayProp(ReflectionObject $reflection, object $service, string $name): void {
        if (!$reflection->hasProperty($name)) {
            return;
        }

        $property = $reflection->getProperty($name);
        $property->setValue($service, []);
    }

    /**
     * Freeform's forms service, resolved the same way the rest of the
     * Freeform tools do (Freeform::getInstance()->forms).
     */
    private static function resolveFormsService(): ?object {
        $freeform = self::FREEFORM_CLASS;
        if (!class_exists($freeform)) {
            return null;
        }

        $instance = $freeform::getInstance();
        if (!is_object($instance)) {
            return null;
        }

        $forms = $instance->forms ?? null;

        return is_object($forms) ? $forms : null;
    }

    /**
     * Freeform's field provider, a genuine Yii container singleton — unlike
     * LayoutPersistence, \Craft::$container->get() DOES return the live,
     * long-running instance here (confirmed via read-only tinker inspection
     * of the running install: two container->get() calls returned the exact
     * same object).
     */
    private static function resolveFieldProvider(): ?object {
        $class = self::FIELD_PROVIDER_CLASS;
        if (!class_exists($class)) {
            return null;
        }

        return Craft::$container->get($class);
    }

    /**
     * Freeform's layout-assembly service. Confirmed the same instance the
     * container returns is reachable via Freeform::getInstance()->formLayouts
     * (registered as 'formLayouts' => LayoutsService::class in
     * Freeform::initContainerItems()), so resolved the same way as
     * resolveFormsService() rather than a direct container->get() call.
     */
    private static function resolveLayoutsService(): ?object {
        $freeform = self::FREEFORM_CLASS;
        if (!class_exists($freeform) || !class_exists(self::LAYOUTS_SERVICE_CLASS)) {
            return null;
        }

        $instance = $freeform::getInstance();
        if (!is_object($instance)) {
            return null;
        }

        $formLayouts = $instance->formLayouts ?? null;
        if (!is_object($formLayouts) || !is_a($formLayouts, self::LAYOUTS_SERVICE_CLASS)) {
            return null;
        }

        return $formLayouts;
    }
}
