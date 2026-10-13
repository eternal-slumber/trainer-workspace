<?php

namespace App\Models;

use App\Jobs\GenerateTrainingPlanJob;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use LogicException;

/**
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $lease_expires_at
 * @property CarbonImmutable|null $response_received_at
 * @property CarbonImmutable|null $response_expires_at
 * @property CarbonImmutable|null $request_started_at
 * @property CarbonImmutable|null $cancel_requested_at
 * @property CarbonImmutable|null $last_enqueued_at
 * @property CarbonImmutable|null $finished_at
 */
class TrainingPlanGeneration extends Model
{
    use HasUuids;

    /** @var list<string> */
    public const STATUSES = ['queued', 'running', 'uncertain', 'succeeded', 'stale', 'cancelled', 'failed'];

    /** @var list<string> */
    public const ACTIVE_STATUSES = ['queued', 'running', 'uncertain'];

    public const LEASE_SECONDS = 360;

    public function enqueue(int $delay = 0): void
    {
        $queue = Queue::connection('training-plan-generations');
        if (! $queue instanceof DatabaseQueue || $queue->getDatabase() !== $this->getConnection()
            || $this->getConnection()->transactionLevel() === 0) {
            throw new LogicException('Generation и Job должны записываться в одной PostgreSQL-транзакции.');
        }
        Bus::dispatch((new GenerateTrainingPlanJob($this->id))->onConnection('training-plan-generations')
            ->onQueue('training-plan-generations')->delay($delay)->beforeCommit());
        $this->forceFill(['last_enqueued_at' => now()])->save();
    }

    /** @return array<string, mixed> */
    public function receipt(): array
    {
        return $this->only(['id', 'scheduled_training_id', 'request_key', 'status', 'error_code', 'training_plan_id', 'cancel_requested_at', 'created_at']);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ScheduledTraining, $this> */
    public function scheduledTraining(): BelongsTo
    {
        return $this->belongsTo(ScheduledTraining::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'scheduled_training_id' => 'integer',
            'attempt_count' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'response_payload' => 'encrypted',
            'response_received_at' => 'immutable_datetime',
            'response_expires_at' => 'immutable_datetime',
            'request_started_at' => 'immutable_datetime',
            'cancel_requested_at' => 'immutable_datetime',
            'last_enqueued_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
