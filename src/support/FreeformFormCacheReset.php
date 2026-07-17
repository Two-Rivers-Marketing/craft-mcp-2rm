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
 * Two of Freeform's own services memoize form/field data in a private
 * Solspace\Freeform\Library\Cache\Memo instance — a class built to live for a
 * single web request:
 *
 *  - FormsService::$cache memoizes getFormById()/getFormByHandle()/
 *    getAllForms() results (issue #26's flushFormCache()).
 *  - FieldProvider::$cache memoizes a form's field ROWS in getRows($formId),
 *    keyed by the form's real (stable) database id — not by Form object
 *    identity. Form::getLayout()->getFields() resolves through
 *    LayoutsService -> FieldProvider::getFields($form) -> getRows($formId),
 *    so once a form's rows are memoized here, EVERY later get_form for that
 *    form id returns the same field list forever, even for a freshly loaded
 *    Form entity (issue #30). clear_caches (Craft's own cache layer) never
 *    touches this — it is plain PHP object state.
 *
 * Both services are registered as genuine Yii container singletons
 * (Freeform::initContainerItems()), so — unlike LayoutPersistence (see
 * FreeformLayoutCacheReset) — the live instance is reachable directly:
 * FormsService via Freeform::getInstance()->forms, FieldProvider via
 * \Craft::$container->get(FieldProvider::class). Both expose their memo as a
 * private `cache` property holding a Memo with a public clear() method, so a
 * single reflection-get + clear() per service is enough — no need to reach
 * into the Yii event registry.
 *
 * Guarded and best-effort throughout: if Freeform renames a class, drops the
 * `cache` property, or changes the Memo API, resetting silently no-ops and
 * never fails the persisted write. All Freeform identifiers are plain strings
 * (never imported) so this class loads safely when the plugin is absent.
 *
 * Intended call site: FreeformScaffoldTools::triggerPersist(), the single
 * path shared by create_form and update_form (and any future Freeform
 * structural-write tool), so every write stays consistent without callers
 * having to remember to flush anything themselves.
 *
 * @author 2RM
 */
final class FreeformFormCacheReset {
    /** Freeform plugin class, for FormsService::class::getInstance()->forms. */
    public const FREEFORM_CLASS = 'Solspace\\Freeform\\Freeform';

    /** Freeform's field/row provider (string FQCN — never imported). */
    public const FIELD_PROVIDER_CLASS = 'Solspace\\Freeform\\Bundles\\Fields\\FieldProvider';

    /** Property name of the Cache\Memo on both target services. */
    private const CACHE_PROPERTY = 'cache';

    /**
     * Resolve and clear the memo on every known Freeform read-cache service.
     * Never throws.
     */
    public static function reset(): void {
        try {
            self::clearMemo(self::resolveFormsService());
            self::clearMemo(self::resolveFieldProvider());
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
}
