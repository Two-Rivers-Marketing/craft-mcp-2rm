<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\models;

use craft\base\Model;
use Override;

/**
 * MCP Plugin Settings.
 *
 * A simple value object - config loading is handled by the Mcp class.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class Settings extends Model {
    public bool $enabled = true;

    /** @var string[] */
    public array $disabledTools = [];

    /** @var string[] */
    public array $disabledPrompts = [];

    /** @var string[] */
    public array $disabledResources = [];

    public bool $enableDangerousTools = true;

    /** @var string[] */
    public array $allowedIps = [];

    public string $logLevel = 'error';

    /**
     * Handle of the site's primary Neo content-builder field.
     * Used as the default field for describe_content_builder / get_block_type.
     */
    public string $builderFieldHandle = 'contentBuilder';

    /**
     * Absolute path to a custom body_blocks template stub used by the
     * create_block_type tool. Null uses the built-in 2RM body-block stub.
     * The stub file may embed __BLOCK_HANDLE__, __FIELD_HINTS__ and
     * __CHILDREN_LOOP__ tokens (see support\BlockTypeStub).
     */
    public ?string $templateStubPath = null;

    /**
     * @return array<int, array<int|string, mixed>>
     */
    #[Override]
    public function defineRules(): array {
        return [
            [['enabled', 'enableDangerousTools'], 'boolean'],
            [['disabledTools', 'disabledPrompts', 'disabledResources', 'allowedIps'], 'each', 'rule' => ['string']],
            [['logLevel'], 'in', 'range' => ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency']],
            [['builderFieldHandle'], 'string'],
            [['templateStubPath'], 'string'],
        ];
    }
}
