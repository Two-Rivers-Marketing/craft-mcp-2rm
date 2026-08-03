<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\tools\QueueTools;

describe('QueueTools list_queue_jobs', function () {
    it('exposes list_queue_jobs with the McpTool attribute', function () {
        $attributes = (new ReflectionMethod(QueueTools::class, 'listQueueJobs'))->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('list_queue_jobs')
            ->and($instance->description)->toContain('waiting, delayed, reserved, failed')
            ->and($instance->description)->toContain('limit');
    });

    it('is not dangerous and sits in the system category', function () {
        $attributes = (new ReflectionMethod(QueueTools::class, 'listQueueJobs'))->getAttributes(McpToolMeta::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->category)->toBe(ToolCategory::SYSTEM)
            ->and($instance->dangerous)->toBeFalse();
    });

    it('takes optional status, limit and context', function () {
        $parameters = (new ReflectionMethod(QueueTools::class, 'listQueueJobs'))->getParameters();

        expect($parameters)->toHaveCount(3);

        expect($parameters[0]->getName())->toBe('status')
            ->and($parameters[0]->isOptional())->toBeTrue()
            ->and($parameters[0]->getType()?->getName())->toBe('string')
            ->and($parameters[0]->getType()?->allowsNull())->toBeTrue()
            ->and($parameters[0]->getDefaultValue())->toBeNull();

        expect($parameters[1]->getName())->toBe('limit')
            ->and($parameters[1]->isOptional())->toBeTrue()
            ->and($parameters[1]->getType()?->getName())->toBe('int')
            ->and($parameters[1]->getDefaultValue())->toBe(25);

        expect($parameters[2]->getName())->toBe('context')
            ->and($parameters[2]->isOptional())->toBeTrue()
            ->and($parameters[2]->getType()?->allowsNull())->toBeTrue();
    });

    it('returns array', function () {
        expect((new ReflectionMethod(QueueTools::class, 'listQueueJobs'))->getReturnType()?->getName())->toBe('array');
    });
});

describe('QueueTools retry_failed_jobs', function () {
    it('exposes retry_failed_jobs with the McpTool attribute', function () {
        $attributes = (new ReflectionMethod(QueueTools::class, 'retryFailedJobs'))->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('retry_failed_jobs')
            ->and($instance->description)->toContain('jobId')
            ->and($instance->description)->toContain('dryRun');
    });

    it('is marked dangerous in the system category', function () {
        $attributes = (new ReflectionMethod(QueueTools::class, 'retryFailedJobs'))->getAttributes(McpToolMeta::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->category)->toBe(ToolCategory::SYSTEM)
            ->and($instance->dangerous)->toBeTrue();
    });

    it('takes optional jobId, dryRun and context', function () {
        $parameters = (new ReflectionMethod(QueueTools::class, 'retryFailedJobs'))->getParameters();

        expect($parameters)->toHaveCount(3);

        expect($parameters[0]->getName())->toBe('jobId')
            ->and($parameters[0]->isOptional())->toBeTrue()
            ->and($parameters[0]->getType()?->getName())->toBe('int')
            ->and($parameters[0]->getType()?->allowsNull())->toBeTrue()
            ->and($parameters[0]->getDefaultValue())->toBeNull();

        expect($parameters[1]->getName())->toBe('dryRun')
            ->and($parameters[1]->isOptional())->toBeTrue()
            ->and($parameters[1]->getType()?->getName())->toBe('bool')
            ->and($parameters[1]->getDefaultValue())->toBeFalse();

        expect($parameters[2]->getName())->toBe('context')
            ->and($parameters[2]->isOptional())->toBeTrue()
            ->and($parameters[2]->getType()?->allowsNull())->toBeTrue();
    });

    it('returns array', function () {
        expect((new ReflectionMethod(QueueTools::class, 'retryFailedJobs'))->getReturnType()?->getName())->toBe('array');
    });
});

describe('QueueTools tool count', function () {
    it('has exactly 2 public methods with the McpTool attribute', function () {
        $methods = (new ReflectionClass(QueueTools::class))->getMethods(ReflectionMethod::IS_PUBLIC);

        $toolMethods = array_filter(
            $methods,
            static fn (ReflectionMethod $method): bool => $method->getAttributes(McpTool::class) !== [],
        );

        expect($toolMethods)->toHaveCount(2);
    });

    it('keeps its queue and serialization helpers private so discovery ignores them', function () {
        $reflection = new ReflectionClass(QueueTools::class);

        expect($reflection->getMethod('queue')->isPrivate())->toBeTrue()
            ->and($reflection->getMethod('allJobs')->isPrivate())->toBeTrue()
            ->and($reflection->getMethod('serializeJob')->isPrivate())->toBeTrue()
            ->and($reflection->getMethod('statusName')->isPrivate())->toBeTrue();
    });
});
