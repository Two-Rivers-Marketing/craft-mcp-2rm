<?php

declare(strict_types=1);

use twoRivers\craft\Mcp\support\FreeformLayoutCacheReset;
use yii\base\Event;

// Boot-free: exercises the Yii event-registry reflection logic against real
// yii\base\Event using anonymous stubs that stand in for Freeform's
// LayoutPersistence bundle (matched by the get_class() name passed to reset()).
// No Craft application or Freeform plugin required.

function makeLayoutStub(): object {
    return new class () {
        /** @var array<string, mixed> */
        public array $cache = ['Solspace\\Freeform\\Records\\Form\\FormRowRecord' => ['uid-1' => 42]];

        /** @var array<string, mixed> */
        public array $rowContents = ['stale-uid' => []];

        public function handleLayoutSave(): void {
        }
    };
}

describe('FreeformLayoutCacheReset::reset', function () {
    it('clears cache and rowContents on a bound handler of the target class', function () {
        $stub = makeLayoutStub();
        $ctrl = 'Test\\Ctrl\\A';
        $eventName = 'test-upsert-a';
        Event::on($ctrl, $eventName, [$stub, 'handleLayoutSave']);

        try {
            FreeformLayoutCacheReset::reset($ctrl, $eventName, get_class($stub));

            expect($stub->cache)->toBe([])
                ->and($stub->rowContents)->toBe([]);
        } finally {
            Event::off($ctrl, $eventName);
        }
    });

    it('resets every bound instance of the target class', function () {
        $a = makeLayoutStub();
        $b = makeLayoutStub();
        $ctrl = 'Test\\Ctrl\\Multi';
        $eventName = 'test-upsert-multi';
        Event::on($ctrl, $eventName, [$a, 'handleLayoutSave']);
        Event::on($ctrl, $eventName, [$b, 'handleLayoutSave']);

        try {
            FreeformLayoutCacheReset::reset($ctrl, $eventName, get_class($a));

            expect($a->cache)->toBe([])
                ->and($b->cache)->toBe([]);
        } finally {
            Event::off($ctrl, $eventName);
        }
    });

    it('leaves handlers of other classes untouched', function () {
        $other = new class () {
            /** @var array<string, mixed> */
            public array $cache = ['keep' => 1];

            public function handleLayoutSave(): void {
            }
        };
        $ctrl = 'Test\\Ctrl\\Other';
        $eventName = 'test-upsert-other';
        Event::on($ctrl, $eventName, [$other, 'handleLayoutSave']);

        try {
            // Target a different (Freeform) class name — no match, no change.
            FreeformLayoutCacheReset::reset($ctrl, $eventName, FreeformLayoutCacheReset::LAYOUT_PERSISTENCE_CLASS);

            expect($other->cache)->toBe(['keep' => 1]);
        } finally {
            Event::off($ctrl, $eventName);
        }
    });

    it('no-ops without throwing when no handler is bound to the event', function () {
        FreeformLayoutCacheReset::reset('Test\\Ctrl\\None', 'test-upsert-none', makeLayoutStub()::class);
    })->throwsNoExceptions();

    it('ignores a matching handler that lacks the memo properties', function () {
        $bare = new class () {
            public function handleLayoutSave(): void {
            }
        };
        $ctrl = 'Test\\Ctrl\\Bare';
        $eventName = 'test-upsert-bare';
        Event::on($ctrl, $eventName, [$bare, 'handleLayoutSave']);

        try {
            FreeformLayoutCacheReset::reset($ctrl, $eventName, get_class($bare));
        } finally {
            Event::off($ctrl, $eventName);
        }
    })->throwsNoExceptions();
});
