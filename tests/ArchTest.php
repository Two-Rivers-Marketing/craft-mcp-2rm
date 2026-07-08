<?php

declare(strict_types=1);

arch('source files have strict types')
    ->expect('twoRivers\craft\Mcp')
    ->toUseStrictTypes();

arch('support classes are final')
    ->expect('twoRivers\craft\Mcp\support')
    ->toBeFinal();

arch('tools do not use echo or print')
    ->expect('twoRivers\craft\Mcp\tools')
    ->not->toUse(['echo', 'print', 'print_r', 'var_dump', 'dd']);

arch('no debugging functions in source')
    ->expect('twoRivers\craft\Mcp')
    ->not->toUse(['dd', 'dump', 'var_dump', 'print_r']);

arch('events extend yii base Event')
    ->expect('twoRivers\craft\Mcp\events')
    ->toExtend('yii\base\Event');

arch('models extend craft base Model')
    ->expect('twoRivers\craft\Mcp\models')
    ->toExtend('craft\base\Model');

arch('tool classes use strict types')
    ->expect('twoRivers\craft\Mcp\tools')
    ->toUseStrictTypes();
