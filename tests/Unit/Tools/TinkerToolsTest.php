<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/CraftStub.php';

use twoRivers\craft\Mcp\Tests\Fixtures\TestMutex;
use twoRivers\craft\Mcp\tools\TinkerTools;

/**
 * Minimal Craft::$app stub: mutex + projectConfig for MutexGuard, and a
 * general config carrying devMode for the tinker gate.
 */
function tinkerStubApp(bool $devMode): object {
    $general = new class ($devMode) {
        public function __construct(public bool $devMode) {
        }
    };

    return new class (new TestMutex(), $general) {
        public function __construct(
            private readonly object $mutex,
            private readonly object $general,
        ) {
        }

        public function getMutex(): object {
            return $this->mutex;
        }

        public function getConfig(): object {
            return new class ($this->general) {
                public function __construct(private readonly object $general) {
                }

                public function getGeneral(): object {
                    return $this->general;
                }
            };
        }

        public function getProjectConfig(): object {
            return new class () {
                public function isLocked(): bool {
                    return false;
                }
            };
        }
    };
}

beforeEach(function () {
    $this->originalApp = Craft::$app;
    Craft::$app = tinkerStubApp(true);
});

afterEach(function () {
    Craft::$app = $this->originalApp;
});

describe('TinkerTools devMode gate', function () {
    it('refuses to execute when devMode is off', function () {
        Craft::$app = tinkerStubApp(false);

        $result = (new TinkerTools())->tinker("echo 'sentinel-ran';");

        expect($result->text)->toContain('SecurityError')
            ->and($result->text)->toContain('devMode')
            // the echo must never have run — only the input echo-back survives
            ->and(substr_count($result->text, 'sentinel-ran'))->toBe(1);
    });

    it('executes when devMode is on', function () {
        $result = (new TinkerTools())->tinker("echo 'sentinel-ran';");

        expect(substr_count($result->text, 'sentinel-ran'))->toBe(2)
            ->and($result->text)->not->toContain('SecurityError');
    });
});

describe('TinkerTools blocked patterns', function () {
    it('blocks shell exec calls', function () {
        $result = (new TinkerTools())->tinker("exec('ls');");

        expect($result->text)->toContain('SecurityError');
    });

    it('blocks unbounded output buffer teardown loops', function (string $code) {
        $result = (new TinkerTools())->tinker($code);

        expect($result->text)->toContain('SecurityError');
    })->with([
        'spaced' => 'while (ob_get_level() > 0) { ob_end_clean(); }',
        'compact' => 'while(ob_get_level()){ob_end_clean();}',
        'negated comparison' => 'while (ob_get_level() !== 0) { ob_end_clean(); }',
    ]);
});

describe('TinkerTools output buffer handling', function () {
    it('captures echoed output', function () {
        $result = (new TinkerTools())->tinker("echo 'hello-stdout'; return 1;");

        expect($result->text)->toContain('hello-stdout');
    });

    it('preserves outer buffers when user code closes the capture buffer', function () {
        ob_start();
        $level = ob_get_level();

        $result = (new TinkerTools())->tinker("ob_end_clean(); return 'done';");

        $intact = ob_get_level() === $level;
        if ($intact) {
            ob_end_clean();
        }

        expect($intact)->toBeTrue()
            ->and($result->text)->toContain('done');
    });

    it('keeps the original error when user code closes the capture buffer and throws', function () {
        ob_start();
        $level = ob_get_level();

        $result = (new TinkerTools())->tinker("ob_end_clean(); throw new RuntimeException('original-error');");

        $intact = ob_get_level() === $level;
        if ($intact) {
            ob_end_clean();
        }

        expect($intact)->toBeTrue()
            ->and($result->text)->toContain('original-error');
    });

    it('closes extra buffers opened by user code', function () {
        $level = ob_get_level();

        $result = (new TinkerTools())->tinker("ob_start(); echo 'inner'; return 'done';");

        expect(ob_get_level())->toBe($level)
            ->and($result->text)->toContain('done')
            ->and($result->text)->toContain('inner');
    });
});
