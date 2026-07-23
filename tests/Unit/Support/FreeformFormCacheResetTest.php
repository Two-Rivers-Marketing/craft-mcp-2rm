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

describe('FreeformFormCacheReset::clearArrayProps', function () {
    // LayoutsService (issue #30, reopened) has no Memo object at all — its
    // memoized state IS a set of private plain arrays (pages/rows/layouts
    // keyed by stable form id, formLayouts keyed by Form object identity).
    // clearArrayProps reflection-empties each named property directly,
    // rather than calling ->clear() on some nested cache object.
    function makeLayoutsServiceStub(): object {
        return new class () {
            private array $pages = [1 => ['id' => 1]];

            private array $layouts = [1 => ['id' => 2]];

            private array $rows = [1 => ['id' => 3]];

            private array $formLayouts = [123 => 'some-form-layout'];

            /** @return array<string, array<mixed>> */
            public function snapshot(): array {
                return [
                    'pages' => $this->pages,
                    'layouts' => $this->layouts,
                    'rows' => $this->rows,
                    'formLayouts' => $this->formLayouts,
                ];
            }
        };
    }

    it('empties every named private array property', function () {
        $service = makeLayoutsServiceStub();

        FreeformFormCacheReset::clearArrayProps($service, ['pages', 'layouts', 'rows', 'formLayouts']);

        expect($service->snapshot())->toBe([
            'pages' => [],
            'layouts' => [],
            'rows' => [],
            'formLayouts' => [],
        ]);
    });

    it('no-ops without throwing for a null service', function () {
        FreeformFormCacheReset::clearArrayProps(null, ['pages']);
    })->throwsNoExceptions();

    it('skips properties the service does not have, without throwing', function () {
        $bare = new class () {
        };

        FreeformFormCacheReset::clearArrayProps($bare, ['pages', 'rows']);
    })->throwsNoExceptions();

    it('leaves properties not in the list untouched', function () {
        $service = makeLayoutsServiceStub();

        FreeformFormCacheReset::clearArrayProps($service, ['pages']);

        expect($service->snapshot()['pages'])->toBe([])
            ->and($service->snapshot()['rows'])->toBe([1 => ['id' => 3]]);
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
