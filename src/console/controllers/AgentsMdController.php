<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use InvalidArgumentException;
use Override;
use twoRivers\craft\Mcp\installer\ProjectRootResolver;
use twoRivers\craft\Mcp\support\AgentsMdBlock;
use yii\console\ExitCode;

/**
 * Writes the craft-mcp conventions pointer block into the project's AGENTS.md.
 *
 * Explicit command only: there is no install hook, because plugin installs
 * through project config would also run it on servers.
 *
 * @author 2RM
 */
class AgentsMdController extends Controller {
    /**
     * @var string|null Path to the AGENTS.md file. Defaults to AGENTS.md in the project root.
     */
    public ?string $file = null;

    /**
     * @inheritdoc
     */
    #[Override]
    public function options($actionID): array {
        $options = parent::options($actionID);
        $options[] = 'file';

        return $options;
    }

    /**
     * Write or refresh the craft-mcp conventions block in AGENTS.md.
     *
     * The block sits between `<!-- craft-mcp:conventions:start -->` and
     * `<!-- craft-mcp:conventions:end -->`. Re-running replaces it in place and
     * never duplicates it. The file is created when missing.
     *
     * @return int Exit code
     */
    public function actionIndex(): int {
        $path = $this->file ?? $this->defaultPath();
        $existing = is_file($path) ? file_get_contents($path) : '';

        if ($existing === false) {
            $this->stderr("Could not read {$path}" . PHP_EOL, Console::FG_RED);

            return ExitCode::IOERR;
        }

        try {
            $updated = AgentsMdBlock::apply($existing);
        } catch (InvalidArgumentException $e) {
            $this->stderr($e->getMessage() . PHP_EOL, Console::FG_RED);

            return ExitCode::DATAERR;
        }

        if ($updated === $existing) {
            $this->stdout("{$path} is already up to date." . PHP_EOL);

            return ExitCode::OK;
        }

        if (file_put_contents($path, $updated) === false) {
            $this->stderr("Could not write {$path}" . PHP_EOL, Console::FG_RED);

            return ExitCode::IOERR;
        }

        $this->stdout("Updated {$path}" . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }

    private function defaultPath(): string {
        $craftRoot = (string) Craft::getAlias('@root');

        return new ProjectRootResolver($craftRoot)->resolve() . DIRECTORY_SEPARATOR . 'AGENTS.md';
    }
}
