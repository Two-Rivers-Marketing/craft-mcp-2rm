<?php

declare(strict_types=1);

use Mcp\Exception\ToolCallException;
use twoRivers\craft\Mcp\support\FreeformStaleFormCache;

// Boot-free: reproduces Freeform's crash signature with a plain \ErrorException
// (the class Craft/Yii raises for "Undefined array key"; its constructor lets
// us set the originating filename/line, so we mimic the real throwable that
// SubmissionQuery.php emits). No Craft app or Freeform plugin required.

const SUBMISSION_QUERY_FILE = '/app/vendor/solspace/craft-freeform/packages/plugin/src/Elements/Db/SubmissionQuery.php';

function staleFormCacheError(): ErrorException {
    return new ErrorException('Undefined array key 42', 0, E_WARNING, SUBMISSION_QUERY_FILE, 311);
}

describe('FreeformStaleFormCache::isStaleFormCacheError', function () {
    it('detects the undefined-array-key crash from SubmissionQuery by file', function () {
        expect(FreeformStaleFormCache::isStaleFormCacheError(staleFormCacheError()))->toBeTrue();
    });

    it('is case-insensitive on the file path', function () {
        $e = new ErrorException(
            'Undefined array key 7',
            0,
            E_WARNING,
            '/app/vendor/Solspace/Craft-Freeform/.../SubmissionQuery.php',
            311,
        );

        expect(FreeformStaleFormCache::isStaleFormCacheError($e))->toBeTrue();
    });

    it('ignores an undefined-array-key error from an unrelated file', function () {
        $e = new ErrorException('Undefined array key 42', 0, E_WARNING, '/app/vendor/foo/Bar.php', 10);

        expect(FreeformStaleFormCache::isStaleFormCacheError($e))->toBeFalse();
    });

    it('ignores a SubmissionQuery error with a different message', function () {
        $e = new ErrorException('Call to a member function on null', 0, E_WARNING, SUBMISSION_QUERY_FILE, 311);

        expect(FreeformStaleFormCache::isStaleFormCacheError($e))->toBeFalse();
    });

    it('detects the crash when wrapped as a previous exception', function () {
        $wrapped = new RuntimeException('boom while reading submission', 0, staleFormCacheError());

        expect(FreeformStaleFormCache::isStaleFormCacheError($wrapped))->toBeTrue();
    });

    it('returns false for an ordinary exception', function () {
        expect(FreeformStaleFormCache::isStaleFormCacheError(new RuntimeException('nope')))->toBeFalse();
    });
});

describe('FreeformStaleFormCache::guard', function () {
    it('returns the operation result when nothing throws', function () {
        $result = FreeformStaleFormCache::guard(static fn (): string => 'ok');

        expect($result)->toBe('ok');
    });

    it('translates the stale crash into an actionable ToolCallException', function () {
        $error = staleFormCacheError();

        $call = static fn () => FreeformStaleFormCache::guard(static function () use ($error): void {
            throw $error;
        });

        expect($call)->toThrow(ToolCallException::class, FreeformStaleFormCache::RELOAD_MESSAGE);
    });

    it('preserves the original crash as the previous exception', function () {
        $error = staleFormCacheError();

        try {
            FreeformStaleFormCache::guard(static function () use ($error): void {
                throw $error;
            });
        } catch (ToolCallException $e) {
            expect($e->getPrevious())->toBe($error);

            return;
        }

        throw new RuntimeException('guard did not throw');
    });

    it('rethrows an unrelated throwable unchanged', function () {
        $original = new RuntimeException('unrelated');

        $call = static fn () => FreeformStaleFormCache::guard(static function () use ($original): void {
            throw $original;
        });

        expect($call)->toThrow($original);
    });
});
