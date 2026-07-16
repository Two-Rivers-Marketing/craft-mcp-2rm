<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\support;

use Mcp\Exception\ToolCallException;
use Throwable;

/**
 * Detects and translates Freeform's stale in-process form-map crash on
 * submission reads/counts/deletes in the long-running MCP server.
 *
 * WHY THIS EXISTS
 * ---------------
 * Freeform's SubmissionQuery::beforePrepare() (vendor/solspace/craft-freeform/
 * .../Elements/Db/SubmissionQuery.php) memoizes a formId->Form map in
 * METHOD-LOCAL `static` variables ($forms, $formHandleToIdMap,
 * $formIdToHandleMap). They are populated ONCE, on the first submission query
 * of the process, and never rebuilt (the `null === $formHandleToIdMap` guard).
 * It is written for a single web request, where each request is a fresh
 * process.
 *
 * The MCP server is long-running. A form created via create_form AFTER the
 * first submission query is absent from those frozen maps. When a submission
 * query then resolves that form's id (from the DB or a formId() criteria),
 * `$forms[$formId]` is an undefined key -> PHP E_WARNING -> Craft/Yii converts
 * it to a `yii\base\ErrorException` "Undefined array key <formId>" thrown from
 * SubmissionQuery.php. This hits get_submission, list_submissions (by handle),
 * export_submissions, delete_submission (via getSubmissionById), and the
 * list_forms submissionCount() path.
 *
 * WHY NOT RESET IT (as #28 did for LayoutPersistence)
 * ---------------------------------------------------
 * The #28 fix resets a private CLASS property via reflection. That is
 * impossible here: these are function-local `static` variables inside a method
 * body, not class properties, so ReflectionProperty cannot reach them and
 * nothing in userland can clear them. The maps genuinely live until the
 * process restarts. So the only robust remedy in-process is to DETECT the crash
 * signature and turn the opaque "Undefined array key" into a clear, actionable
 * "reload the server (SIGHUP)" signal instead of letting it surface raw or be
 * swallowed into a silent null.
 *
 * All Freeform identifiers are matched as plain strings (never imported), so
 * this class loads safely when the plugin is absent.
 *
 * @author 2RM
 */
final class FreeformStaleFormCache {
    /**
     * Sentinel returned by count paths (e.g. list_forms submissionCount) that
     * cannot fail the whole call: signals a reload is required rather than a
     * genuine zero/unknown count.
     */
    public const RELOAD_SIGNAL = 'reload_required';

    /** Actionable message surfaced to the user for a crash-prone read/delete. */
    public const RELOAD_MESSAGE = 'MCP reload required (SIGHUP): this Freeform form was created after the MCP server started. Freeform caches its form map in a process-lifetime static that cannot be refreshed at runtime, so this form\'s submissions are unreadable until the server is reloaded. Restart / SIGHUP the MCP server, then retry.';

    /**
     * Run a submission-query operation, translating the stale form-map crash
     * into a clear ToolCallException. Any other error is rethrown untouched so
     * the normal SafeExecution formatting still applies.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     *
     * @throws ToolCallException on the stale-cache signature
     * @throws Throwable         any other error, unchanged
     */
    public static function guard(callable $operation): mixed {
        try {
            return $operation();
        } catch (Throwable $e) {
            if (self::isStaleFormCacheError($e)) {
                throw new ToolCallException(self::RELOAD_MESSAGE, 0, $e);
            }

            throw $e;
        }
    }

    /**
     * True when the throwable (or any in its cause chain) is the
     * "Undefined array key" crash originating in Freeform's SubmissionQuery.
     */
    public static function isStaleFormCacheError(Throwable $e): bool {
        foreach (self::chain($e) as $throwable) {
            if (self::matchesSignature($throwable)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, Throwable>
     */
    private static function chain(Throwable $e): array {
        $chain = [];
        $current = $e;

        while ($current instanceof Throwable) {
            $chain[] = $current;
            $current = $current->getPrevious();
        }

        return $chain;
    }

    private static function matchesSignature(Throwable $e): bool {
        if (!str_contains($e->getMessage(), 'Undefined array key')) {
            return false;
        }

        return self::originatesInSubmissionQuery($e);
    }

    private static function originatesInSubmissionQuery(Throwable $e): bool {
        if (self::isSubmissionQueryFile($e->getFile())) {
            return true;
        }

        return self::traceHasSubmissionQuery($e);
    }

    private static function isSubmissionQueryFile(string $file): bool {
        $normalized = strtolower($file);

        return str_contains($normalized, 'submissionquery') && str_contains($normalized, 'freeform');
    }

    private static function traceHasSubmissionQuery(Throwable $e): bool {
        foreach ($e->getTrace() as $frame) {
            $class = $frame['class'] ?? '';

            if (self::isSubmissionQueryClass($class)) {
                return true;
            }
        }

        return false;
    }

    private static function isSubmissionQueryClass(string $class): bool {
        $normalized = strtolower($class);

        return str_contains($normalized, 'submissionquery') && str_contains($normalized, 'freeform');
    }
}
