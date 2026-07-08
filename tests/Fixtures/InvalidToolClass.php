<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\Tests\Fixtures;

/**
 * An invalid tool class - no McpTool attributes.
 */
class InvalidToolClass {
    public function doSomething(): array {
        return ['success' => true];
    }
}
