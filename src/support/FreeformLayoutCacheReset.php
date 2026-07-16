<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\support;

use ReflectionObject;
use ReflectionProperty;
use Throwable;
use yii\base\Event;

/**
 * Busts the per-request memo on Freeform's LayoutPersistence bundle before a
 * create_form / update_form save.
 *
 * WHY THIS EXISTS
 * ---------------
 * Freeform persists a form's layout in LayoutPersistence::handleLayoutSave(),
 * bound to FormsController::EVENT_UPSERT_FORM. Inside it, getRecordId() maps a
 * row's uid -> row id and memoizes the whole formId->uid map in a private
 * $cache the FIRST time it is queried, keyed by [recordType][formId]. It is
 * designed to live for a single web request: each request gets a fresh bundle
 * instance, so the memo is always current.
 *
 * The MCP server is long-running. LayoutPersistence is instantiated once at
 * Freeform boot (BundleLoader) and its constructor binds handleLayoutSave to
 * the event, so that ONE instance — with its $cache and $rowContents — handles
 * every create_form / update_form call for the server's lifetime. After the
 * first save of a form populates $cache[FormRowRecord][formId], a later
 * update_form that ADDS a field creates the new freeform_forms_rows record
 * fine (saveRows re-queries), but saveFields then resolves the new row's id
 * through the STALE $cache, which predates the new row -> getRecordId returns
 * null -> the field is written with rowId = null -> it is orphaned and never
 * renders (issue #28). Stale $rowContents can likewise make cleanupOrphans
 * delete a still-valid row from an earlier call.
 *
 * The container cannot hand back the offending instance ($container->get()
 * yields a fresh, unbound object), so the only handle on the live instance is
 * the Yii event registry it registered itself in. This resets every bound
 * LayoutPersistence instance's memo to empty right before the save, so
 * getRecordId re-queries the DB and finds the just-created rows — the same
 * fresh-instance behaviour Freeform assumes in a normal request.
 *
 * Same family of fix as FreeformScaffoldTools::flushFormCache() (FormsService
 * Memo). Fully guarded and best-effort: if Freeform renames the class, the
 * event, or the properties, the reset silently no-ops and never fails the
 * save. All Freeform identifiers are plain strings (never imported) so this
 * class loads safely when the plugin is absent.
 *
 * @author 2RM
 */
final class FreeformLayoutCacheReset {
    /** Freeform layout-persistence bundle (string FQCN — never imported). */
    public const LAYOUT_PERSISTENCE_CLASS = 'Solspace\\Freeform\\Bundles\\Persistence\\LayoutPersistence';

    /** Per-request memo properties on that bundle to clear. */
    private const PROPERTIES = ['cache', 'rowContents'];

    /**
     * Reset the memo on every handler bound to $eventName on $controllerClass
     * that is an instance of $handlerClass.
     */
    public static function reset(
        string $controllerClass,
        string $eventName,
        string $handlerClass = self::LAYOUT_PERSISTENCE_CLASS,
    ): void {
        try {
            $handlers = self::boundHandlers($controllerClass, $eventName, $handlerClass);
            array_walk($handlers, self::clearInstance(...));
        } catch (Throwable) {
            // Best-effort: never let cache-busting fail the persisted save.
        }
    }

    /**
     * @return array<int, object>
     */
    private static function boundHandlers(string $controllerClass, string $eventName, string $handlerClass): array {
        $registry = self::eventRegistry();
        $handlers = $registry[$eventName][$controllerClass] ?? [];

        if (!is_array($handlers)) {
            return [];
        }

        $instances = array_map(
            static fn (mixed $handler): ?object => self::handlerInstance($handler, $handlerClass),
            $handlers,
        );

        return array_values(array_filter($instances, static fn (?object $o): bool => $o !== null));
    }

    /**
     * A Yii event handler is stored as [$callback, $data]; a class-method
     * callback is [$instance, 'method']. Return the instance only when it is
     * of the target class.
     */
    private static function handlerInstance(mixed $handler, string $handlerClass): ?object {
        $callback = is_array($handler) ? ($handler[0] ?? null) : null;
        $object = is_array($callback) ? ($callback[0] ?? null) : null;

        if (!is_object($object)) {
            return null;
        }

        if (!is_a($object, $handlerClass)) {
            return null;
        }

        return $object;
    }

    /**
     * @return array<string, mixed>
     */
    private static function eventRegistry(): array {
        $property = new ReflectionProperty(Event::class, '_events');
        $value = $property->getValue();

        return is_array($value) ? $value : [];
    }

    private static function clearInstance(object $instance): void {
        $reflection = new ReflectionObject($instance);

        foreach (self::PROPERTIES as $name) {
            self::clearProperty($reflection, $instance, $name);
        }
    }

    private static function clearProperty(ReflectionObject $reflection, object $instance, string $name): void {
        if (!$reflection->hasProperty($name)) {
            return;
        }

        $property = $reflection->getProperty($name);
        $property->setValue($instance, []);
    }
}
