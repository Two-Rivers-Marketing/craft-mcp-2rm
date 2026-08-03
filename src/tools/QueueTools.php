<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\tools;

use Craft;
use craft\queue\Queue as CraftQueue;
use craft\queue\QueueInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\support\Response;
use twoRivers\craft\Mcp\support\SafeExecution;

/**
 * Queue visibility MCP tools for Craft CMS.
 *
 * Bulk imports queue asset transforms and index jobs; these tools report
 * whether those jobs finished or failed, and retry the failures.
 *
 * @author 2RM
 */
class QueueTools {
    /**
     * Status names for the integer codes returned by
     * craft\queue\Queue::getJobInfo(). STATUS_DONE only appears for jobs that
     * have already left the queue, so it is not a valid filter value.
     */
    private const STATUS_NAMES = [
        CraftQueue::STATUS_WAITING => 'waiting',
        CraftQueue::STATUS_RESERVED => 'reserved',
        CraftQueue::STATUS_DONE => 'done',
        CraftQueue::STATUS_FAILED => 'failed',
    ];

    /**
     * Accepted values for the status filter. "delayed" is not a stored status —
     * it is derived from a waiting job whose delay has not elapsed.
     */
    private const FILTERABLE_STATUSES = ['waiting', 'delayed', 'reserved', 'failed'];

    /**
     * List queue jobs.
     */
    #[McpTool(
        name: 'list_queue_jobs',
        description: 'List Craft queue jobs with their status, progress and error message. status filters to one of waiting, delayed, reserved, failed ("delayed" means waiting with a delay still to elapse). Reads the full queue so that per-status totals are accurate, then returns at most limit jobs.',
    )]
    #[McpToolMeta(category: ToolCategory::SYSTEM)]
    public function listQueueJobs(
        ?string $status = null,
        int $limit = 25,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($status, $limit): array {
            if ($status !== null && !in_array($status, self::FILTERABLE_STATUSES, true)) {
                throw new ToolCallException(sprintf(
                    'Invalid status "%s". Expected one of: %s',
                    $status,
                    implode(', ', self::FILTERABLE_STATUSES),
                ));
            }

            $jobs = $this->allJobs($this->queue());

            $filtered = $status === null
                ? $jobs
                : array_values(array_filter($jobs, static fn (array $job): bool => $job['status'] === $status));

            return Response::list('jobs', array_slice($filtered, 0, $limit), [
                'total' => count($filtered),
                'limit' => $limit,
                'status' => $status,
                'totals' => $this->totalsByStatus($jobs),
            ]);
        });
    }

    /**
     * Retry failed queue jobs.
     */
    #[McpTool(
        name: 'retry_failed_jobs',
        description: 'Retry failed Craft queue jobs. Pass jobId to retry a single failed job; omit it to retry every failed job. dryRun reports the jobs that would be retried without touching the queue. Retried jobs have their attempt count and error reset and go back to waiting.',
    )]
    #[McpToolMeta(category: ToolCategory::SYSTEM, dangerous: true)]
    public function retryFailedJobs(
        ?int $jobId = null,
        bool $dryRun = false,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($jobId, $dryRun): array {
            $queue = $this->queue();
            $failed = array_values(array_filter(
                $this->allJobs($queue),
                static fn (array $job): bool => $job['status'] === 'failed',
            ));

            if ($jobId === null) {
                return $this->retryEveryFailedJob($queue, $failed, $dryRun);
            }

            return $this->retrySingleJob($queue, $failed, (string) $jobId, $dryRun);
        });
    }

    /**
     * Retry all failed jobs in one pass.
     *
     * @param list<array<string, mixed>> $failed
     */
    private function retryEveryFailedJob(QueueInterface $queue, array $failed, bool $dryRun): array {
        if ($dryRun) {
            return Response::success(['dryRun' => true, 'retried' => 0, 'jobs' => $failed]);
        }

        $queue->retryAll();

        return Response::success(['dryRun' => false, 'retried' => count($failed), 'jobs' => $failed]);
    }

    /**
     * Retry one failed job by ID.
     *
     * @param list<array<string, mixed>> $failed
     */
    private function retrySingleJob(QueueInterface $queue, array $failed, string $jobId, bool $dryRun): array {
        $job = $this->findJob($failed, $jobId);

        if ($job === null) {
            throw new ToolCallException("Job \"{$jobId}\" is not among the queue's failed jobs — nothing to retry.");
        }

        if ($dryRun) {
            return Response::success(['dryRun' => true, 'retried' => 0, 'jobs' => [$job]]);
        }

        $queue->retry($jobId);

        return Response::success(['dryRun' => false, 'retried' => 1, 'jobs' => [$job]]);
    }

    /**
     * Find a job by ID within an already-serialized list.
     *
     * @param list<array<string, mixed>> $jobs
     * @return array<string, mixed>|null
     */
    private function findJob(array $jobs, string $jobId): ?array {
        foreach ($jobs as $job) {
            if ((string) $job['id'] === $jobId) {
                return $job;
            }
        }

        return null;
    }

    /**
     * Fetch and serialize every job currently in the queue.
     *
     * @return list<array<string, mixed>>
     */
    private function allJobs(QueueInterface $queue): array {
        /** @var list<array<string, mixed>> $info */
        $info = $queue->getJobInfo();

        return array_map($this->serializeJob(...), $info);
    }

    /**
     * Count jobs per status, including statuses with no jobs.
     *
     * @param list<array<string, mixed>> $jobs
     * @return array<string, int>
     */
    private function totalsByStatus(array $jobs): array {
        $totals = array_fill_keys(self::FILTERABLE_STATUSES, 0);

        foreach ($jobs as $job) {
            $name = (string) $job['status'];
            $totals[$name] = ($totals[$name] ?? 0) + 1;
        }

        return $totals;
    }

    /**
     * Serialize one getJobInfo() row.
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function serializeJob(array $job): array {
        $delay = (int) ($job['delay'] ?? 0);

        return [
            'id' => (string) $job['id'],
            'description' => $job['description'] ?? null,
            'status' => $this->statusName((int) $job['status'], $delay),
            'delay' => $delay,
            'progress' => (int) ($job['progress'] ?? 0),
            'progressLabel' => $job['progressLabel'] ?? null,
            'error' => ($job['error'] ?? '') ?: null,
        ];
    }

    /**
     * Map a status code to a name, deriving "delayed" from a pending delay.
     */
    private function statusName(int $status, int $delay): string {
        if ($status === CraftQueue::STATUS_WAITING && $delay > 0) {
            return 'delayed';
        }

        return self::STATUS_NAMES[$status] ?? 'unknown';
    }

    /**
     * Resolve the configured queue, requiring Craft's queue API.
     *
     * Craft::$app->getQueue() is typed as yii\queue\Queue. A custom driver can
     * only be inspected or retried if it also implements
     * craft\queue\QueueInterface (Craft's default database queue does).
     */
    private function queue(): QueueInterface {
        $queue = Craft::$app->getQueue();

        if (!$queue instanceof QueueInterface) {
            throw new ToolCallException(sprintf(
                'The configured queue driver (%s) does not implement %s, so its jobs cannot be listed or retried. Craft\'s default database queue does.',
                $queue::class,
                QueueInterface::class,
            ));
        }

        return $queue;
    }
}
