<?php

declare(strict_types=1);

namespace Tinvar\Testing\Fakes;

use PHPUnit\Framework\Assert;
use Tinvar\Jobs\Envelope;
use Tinvar\Jobs\Job;
use Tinvar\Jobs\Payload;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Orm;
use Tinvar\Queue\ArrayQueue;
use Tinvar\Queue\BatchRecord;
use Tinvar\Queue\BatchStep;
use Tinvar\Queue\Coordination;
use Tinvar\Queue\PendingJob;
use Tinvar\Queue\Queue;
use Tinvar\Queue\ReservedJob;
use Tinvar\Testing\Testing;

/**
 * The queue fake: the Dispatcher pushes here, and a test asks what was
 * pushed -- which job, on which queue, sealed with which tenant, panel and
 * user -- or hands the queue to a Worker to run what is on it.
 *
 * It opens each payload as the job runner would, with the application's
 * Envelope signer, so a job that would be refused on a worker -- sealed by
 * another key, or naming no job -- fails the test that pushed it. It builds
 * the job again from its payload, its models read back by id through the
 * Orm it was given or the current test's application's, for a check typed
 * by the job's class.
 *
 * Everything else is an ArrayQueue's, so reservations, retries and failures
 * behave as a real queue's -- and unique jobs' locks, rate-limited queues'
 * windows and batches (Coordination) as a real queue keeps them: a unique
 * job dispatched twice is pushed once, and a Worker run over the fake
 * pushes a chain's next job and a batch's callbacks here, to be asserted.
 */
final class QueueFake implements Queue, Coordination
{
    private readonly ArrayQueue $queue;

    /** @var list<array{job: PendingJob, envelope: Envelope}> */
    private array $pushed = [];

    public function __construct(
        private readonly \Tinvar\Support\Signer $signer,
        private readonly ?Orm $orm = null,
    ) {
        $this->queue = new ArrayQueue();
    }

    public function push(PendingJob $job): void
    {
        $this->pushed[] = ['job' => $job, 'envelope' => Envelope::open($job->payload, $this->signer)];
        $this->queue->push($job);
    }

    /**
     * The envelopes pushed for $class, or every one, in order.
     *
     * @param class-string<Job>|null $class
     * @return list<Envelope>
     */
    public function pushed(?string $class = null, ?string $queue = null): array
    {
        $out = [];
        foreach ($this->pushed as $entry) {
            if (($class === null || $entry['envelope']->class === $class) && ($queue === null || $entry['job']->queue === $queue)) {
                $out[] = $entry['envelope'];
            }
        }

        return $out;
    }

    /**
     * A $class job was pushed -- on $queue, given one; one $check returns
     * true for, given one, which is handed the job rebuilt from its payload
     * and its envelope.
     *
     * @param class-string<Job> $class
     * @param (\Closure(Job, Envelope): bool)|null $check
     */
    public function assertPushed(string $class, ?\Closure $check = null, ?string $queue = null): self
    {
        $pushed = $this->pushed($class, $queue);
        Assert::assertNotEmpty($pushed, "No {$class} was pushed" . ($queue === null ? '' : " on '{$queue}'") . '.' . $this->summary());

        if ($check !== null) {
            $matched = array_filter($pushed, fn (Envelope $envelope): bool => $check($this->job($envelope), $envelope) === true);
            Assert::assertNotEmpty($matched, "No {$class} pushed matched the closure.");
        }

        return $this;
    }

    /** @param class-string<Job> $class */
    public function assertNotPushed(string $class): self
    {
        Assert::assertSame([], $this->pushed($class), "A {$class} was pushed.");

        return $this;
    }

    /** @param class-string<Job>|null $class */
    public function assertPushedCount(int $count, ?string $class = null): self
    {
        $pushed = $this->pushed($class);
        Assert::assertCount($count, $pushed, \count($pushed) . ' jobs' . ($class === null ? '' : " of {$class}") . " were pushed, not {$count}." . $this->summary());

        return $this;
    }

    public function assertNothingPushed(): self
    {
        return $this->assertPushedCount(0);
    }

    /**
     * The job an envelope seals, built again from its payload as a worker
     * builds it: its models read back by id, in the tenant it was sealed
     * with.
     */
    public function job(Envelope $envelope): Job
    {
        $orm = $this->orm ?? Testing::client()->app->kernel()->orm;

        $job = $orm->actAs($envelope->tenant, static fn (): object => Payload::construct($envelope->class, $envelope->arguments, $orm));
        \assert($job instanceof Job);

        return $job;
    }

    public function reserve(array $queues, int $now, int $reserveFor): ?ReservedJob
    {
        return $this->queue->reserve($queues, $now, $reserveFor);
    }

    public function extend(ReservedJob $job, int $until): bool
    {
        return $this->queue->extend($job, $until);
    }

    public function delete(ReservedJob $job): bool
    {
        return $this->queue->delete($job);
    }

    public function release(ReservedJob $job, int $availableAt): bool
    {
        return $this->queue->release($job, $availableAt);
    }

    public function requeue(ReservedJob $job, int $availableAt, ?string $payload = null): bool
    {
        return $this->queue->requeue($job, $availableAt, $payload);
    }

    public function fail(ReservedJob $job, string $error, int $failedAt): bool
    {
        return $this->queue->fail($job, $error, $failedAt);
    }

    public function enlistsIn(Connection $connection): bool
    {
        return false;
    }

    public function size(string $queue): int
    {
        return $this->queue->size($queue);
    }

    public function failed(): array
    {
        return $this->queue->failed();
    }

    public function retry(string $id, int $now): bool
    {
        return $this->queue->retry($id, $now);
    }

    public function lock(string $key, string $job, int $until, int $now): ?string
    {
        return $this->queue->lock($key, $job, $until, $now);
    }

    public function unlock(string $key, string $job): bool
    {
        return $this->queue->unlock($key, $job);
    }

    public function slot(string $queue, int $runs, int $per, int $now): bool
    {
        return $this->queue->slot($queue, $runs, $per, $now);
    }

    public function unslot(string $queue, int $per, int $now): void
    {
        $this->queue->unslot($queue, $per, $now);
    }

    public function addBatch(BatchRecord $batch): void
    {
        $this->queue->addBatch($batch);
    }

    public function batch(string $id): ?BatchRecord
    {
        return $this->queue->batch($id);
    }

    public function batchJobDone(string $id, string $job, int $now): ?BatchStep
    {
        return $this->queue->batchJobDone($id, $job, $now);
    }

    public function batchJobFailed(string $id, string $job, int $now): ?BatchStep
    {
        return $this->queue->batchJobFailed($id, $job, $now);
    }

    public function prune(int $now, int $finishedBefore): int
    {
        return $this->queue->prune($now, $finishedBefore);
    }

    public function atomically(\Closure $work): mixed
    {
        return $this->queue->atomically($work);
    }

    private function summary(): string
    {
        if ($this->pushed === []) {
            return ' Nothing was pushed.';
        }

        return ' Pushed: ' . implode(', ', array_map(static fn (array $entry): string => "{$entry['envelope']->class} on '{$entry['job']->queue}'", $this->pushed)) . '.';
    }
}
