<?php

declare(strict_types=1);

use twoRivers\craft\Mcp\support\FreeformFormCacheReset;

// Boot-free: exercises the reflection-based Memo-clear logic against plain
// stubs shaped like Freeform's FormsService / FieldProvider (a private
// `cache` property holding an object with a public clear()). No Craft
// application or Freeform plugin required.

function makeMemoStub(): object {
    return new class () {
        public bool $cleared = false;

        public function clear(): void {
            $this->cleared = true;
        }
    };
}

function makeServiceStub(): object {
    return new class () {
        public object $cache;

        public function __construct() {
            $this->cache = makeMemoStub();
        }
    };
}

describe('FreeformFormCacheReset::clearMemo', function () {
    it('calls clear() on the service\'s cache property', function () {
        $service = makeServiceStub();

        FreeformFormCacheReset::clearMemo($service);

        expect($service->cache->cleared)->toBeTrue();
    });

    it('no-ops without throwing for a null service', function () {
        FreeformFormCacheReset::clearMemo(null);
    })->throwsNoExceptions();

    it('no-ops without throwing when the service has no cache property', function () {
        $bare = new class () {
        };

        FreeformFormCacheReset::clearMemo($bare);
    })->throwsNoExceptions();

    it('no-ops without throwing when cache is not an object', function () {
        $service = new class () {
            public string $cache = 'not-an-object';
        };

        FreeformFormCacheReset::clearMemo($service);
    })->throwsNoExceptions();

    it('no-ops without throwing when cache has no clear() method', function () {
        $service = new class () {
            public object $cache;

            public function __construct() {
                $this->cache = new stdClass();
            }
        };

        FreeformFormCacheReset::clearMemo($service);

        expect($service->cache)->toBeInstanceOf(stdClass::class);
    });

    it('clears a private cache property too', function () {
        $service = new class () {
            private object $cache;

            public function __construct() {
                $this->cache = makeMemoStub();
            }

            public function memo(): object {
                return $this->cache;
            }
        };

        FreeformFormCacheReset::clearMemo($service);

        expect($service->memo()->cleared)->toBeTrue();
    });
});

describe('FreeformFormCacheReset::reset', function () {
    it('no-ops without throwing when Freeform is not installed', function () {
        // In the boot-free test environment neither
        // Solspace\Freeform\Freeform nor FieldProvider exist, so reset()
        // must resolve nothing and never throw — mirrors every other
        // Freeform support class's "loads safely when the plugin is absent"
        // guarantee.
        FreeformFormCacheReset::reset();
    })->throwsNoExceptions();
});
