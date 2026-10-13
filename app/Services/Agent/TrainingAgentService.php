<?php

namespace App\Services\Agent;

use App\Models\ScheduledTraining;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanBlock;
use App\Models\TrainingPlanGeneration;
use App\Models\User;
use App\Services\Agent\DTO\ValidatedTrainingPlan;
use App\Services\Agent\DTO\ValidatedTrainingPlanBlock;
use App\Services\AI\AiClientException;
use App\Services\AI\AiClientInterface;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class TrainingAgentService
{
    public function __construct(
        private TrainingContextBuilder $contextBuilder,
        private AiClientInterface $aiClient,
        private TrainingPlanValidator $planValidator,
    ) {}

    public function processGeneration(string $id): int
    {
        $claim = $this->locked($id, function (TrainingPlanGeneration $generation, ?ScheduledTraining $training, ?TrainingPlan $plan): array|int|null {
            if ($generation->status === 'running' && $generation->lease_expires_at?->lte(now())) {
                $this->recoverLocked($generation);
            }
            if ($generation->status === 'running') {
                return max(1, (int) ceil(now()->diffInSeconds($generation->lease_expires_at, false)) + 1);
            }
            if ($generation->status !== 'queued') {
                return null;
            }
            if ($generation->next_attempt_at?->isFuture()) {
                return max(1, (int) ceil(now()->diffInSeconds($generation->next_attempt_at, false)));
            }
            if (! $this->eligible($generation, $training, $plan)) {
                return null;
            }
            if ($generation->response_expires_at?->isPast()) {
                $this->finish($generation, 'failed', 'response_expired');

                return null;
            }
            if ($generation->request_started_at !== null && ! $this->hasResponse($generation)) {
                $this->finish($generation, 'uncertain', 'outcome_unknown');

                return null;
            }
            if (! $this->hasResponse($generation) && $generation->attempt_count >= 3) {
                $this->finish($generation, 'failed', 'attempts_exhausted');

                return null;
            }
            try {
                $input = $this->contextBuilder->generationInput($generation->user, $training);
            } catch (ValidationException) {
                $this->finish($generation, 'failed', 'configuration_error');

                return null;
            }
            if ($generation->context_hash !== $input['hash']) {
                $this->finish($generation, 'stale', 'context_changed');

                return null;
            }
            $token = (string) Str::uuid();
            $generation->forceFill(['status' => 'running', 'run_token' => $token,
                'lease_expires_at' => now()->addSeconds(TrainingPlanGeneration::LEASE_SECONDS),
                'attempt_count' => $generation->attempt_count + ($this->hasResponse($generation) ? 0 : 1),
                'next_attempt_at' => null, 'error_code' => null])->save();

            return ['token' => $token, 'input' => $input, 'cached' => $this->hasResponse($generation)];
        });
        if (! is_array($claim)) {
            return is_int($claim) ? $claim : 0;
        }
        $token = $claim['token'];
        try {
            if (! $claim['cached']) {
                $started = $this->locked($id, function (TrainingPlanGeneration $generation, ?ScheduledTraining $training, ?TrainingPlan $plan) use ($token): bool {
                    if (! $this->ownsLease($generation, $token) || ! $this->eligible($generation, $training, $plan)) {
                        return false;
                    }
                    if ($generation->context_hash !== $this->contextBuilder->generationInput($generation->user, $training)['hash']) {
                        $this->finish($generation, 'stale', 'context_changed');

                        return false;
                    }
                    $generation->forceFill(['request_started_at' => now()])->save();

                    return true;
                });
                if (! $started) {
                    return 0;
                }
                $response = $this->aiClient->chat($claim['input']['messages'], $claim['input']['options']);
                $validated = $this->planValidator->validate($response->content, $claim['input']['context']);
                $this->locked($id, function (TrainingPlanGeneration $generation) use ($token, $validated): void {
                    if ($this->ownsLease($generation, $token)) {
                        $generation->forceFill(['response_payload' => $validated->toJson(),
                            'response_received_at' => now(), 'response_expires_at' => now()->addDay()])->save();
                    }
                });
            }
            $this->publish($id, $token);
        } catch (InvalidTrainingPlanResponseException) {
            $this->failure($id, $token, 'invalid_response');
        } catch (DecryptException) {
            $this->failure($id, $token, 'cache_unreadable');
        } catch (AiClientException $exception) {
            $this->locked($id, function (TrainingPlanGeneration $generation) use ($token, $exception): void {
                if (! $this->ownsLease($generation, $token)) {
                    return;
                }
                if ($exception->safeToRetry && ! $exception->outcomeUnknown && $generation->attempt_count < 3) {
                    $delay = $generation->attempt_count === 1 ? 30 : 120;
                    $delay = max($delay, $exception->retryAfter);
                    $generation->forceFill(['status' => 'queued', 'run_token' => null, 'lease_expires_at' => null,
                        'request_started_at' => null, 'next_attempt_at' => now()->addSeconds($delay), 'error_code' => 'retry_scheduled'])->save();
                    $generation->enqueue($delay);
                } else {
                    $this->finish($generation, $exception->outcomeUnknown ? 'uncertain' : 'failed',
                        $exception->outcomeUnknown ? 'outcome_unknown' : 'provider_rejected');
                }
            });
        } catch (QueryException $exception) {
            if (str_starts_with((string) ($exception->errorInfo[0] ?? ''), '23')) {
                $this->failure($id, $token, 'context_changed', 'stale');
            } elseif (str_starts_with((string) ($exception->errorInfo[0] ?? ''), '22')) {
                $this->failure($id, $token, 'invalid_response');
            } else {
                $this->recoverAfterError($id, $token);
            }
        } catch (Throwable) {
            $this->recoverAfterError($id, $token);
        }

        return 0;
    }

    private function publish(string $id, string $token): void
    {
        $this->locked($id, function (TrainingPlanGeneration $generation, ?ScheduledTraining $training, ?TrainingPlan $plan) use ($token): void {
            if (! $this->ownsLease($generation, $token) || ! $this->eligible($generation, $training, $plan)) {
                return;
            }
            $input = $this->contextBuilder->generationInput($generation->user, $training);
            // shortcut: fingerprint cannot fence independent context writers; add shared writer locks if strict publication snapshots are required.
            if ($generation->context_hash !== $input['hash']) {
                $this->finish($generation, 'stale', 'context_changed');

                return;
            }
            if (! $this->hasResponse($generation) || $generation->response_expires_at?->isPast()) {
                $this->finish($generation, 'failed', 'response_expired');

                return;
            }
            $validated = $this->planValidator->validate($generation->response_payload, $input['context']);
            $saved = $this->storePlan($generation->user, $training, $validated);
            $generation->forceFill(['training_plan_id' => $saved->id]);
            $this->finish($generation, 'succeeded');
        });
    }

    private function locked(string $id, Closure $operation): mixed
    {
        return DB::connection('pgsql')->transaction(function () use ($id, $operation): mixed {
            $pointer = TrainingPlanGeneration::on('pgsql')->find($id);
            if ($pointer === null) {
                return null;
            }
            $training = ScheduledTraining::on('pgsql')->where('user_id', $pointer->user_id)
                ->lockForUpdate()->find($pointer->scheduled_training_id);
            $plan = $training?->trainingPlan()->lockForUpdate()->first();
            $generation = TrainingPlanGeneration::on('pgsql')->lockForUpdate()->find($id);

            return $generation === null ? null : $operation($generation, $training, $plan);
        }, attempts: 3);
    }

    private function eligible(TrainingPlanGeneration $generation, ?ScheduledTraining $training, ?TrainingPlan $plan): bool
    {
        $subject = $training === null ? null : ($training->trainee_id !== null
            ? $training->trainee()->sharedLock()->first() : $training->trainingGroup()->sharedLock()->first());
        if ($training === null || $training->status !== 'planned' || $plan !== null
            || $generation->cancel_requested_at !== null || $subject === null
            || $subject->user_id !== $generation->user_id || $subject->getAttribute('archived_at') !== null) {
            $this->finish($generation, 'cancelled', $plan !== null ? 'plan_exists' : 'training_unavailable');

            return false;
        }

        return true;
    }

    private function hasResponse(TrainingPlanGeneration $generation): bool
    {
        return $generation->getRawOriginal('response_payload') !== null;
    }

    private function ownsLease(TrainingPlanGeneration $generation, string $token): bool
    {
        return $generation->status === 'running' && $generation->run_token === $token && $generation->lease_expires_at?->isFuture();
    }

    private function finish(TrainingPlanGeneration $generation, string $status, ?string $code = null): void
    {
        $generation->forceFill(['status' => $status, 'error_code' => $code, 'run_token' => null,
            'lease_expires_at' => null, 'next_attempt_at' => null, 'response_payload' => null,
            'response_expires_at' => null, 'finished_at' => $status === 'uncertain' ? null : now()])->save();
    }

    private function failure(string $id, string $token, string $code, string $status = 'failed'): void
    {
        $this->locked($id, function (TrainingPlanGeneration $generation) use ($token, $code, $status): void {
            if ($this->ownsLease($generation, $token)) {
                $this->finish($generation, $status, $code);
            }
        });
    }

    private function recoverAfterError(string $id, string $token): void
    {
        $this->locked($id, function (TrainingPlanGeneration $generation) use ($token): void {
            if (! $this->ownsLease($generation, $token)) {
                return;
            }
            if ($this->hasResponse($generation) || $generation->request_started_at === null) {
                $generation->forceFill(['status' => 'queued', 'run_token' => null, 'lease_expires_at' => null,
                    'next_attempt_at' => now()->addSeconds(30), 'error_code' => 'retry_scheduled'])->save();
                $generation->enqueue(30);
            } else {
                $this->finish($generation, 'uncertain', 'outcome_unknown');
            }
        });
    }

    private function recoverLocked(TrainingPlanGeneration $generation): void
    {
        if (! $this->hasResponse($generation) && $generation->request_started_at !== null) {
            $this->finish($generation, 'uncertain', 'outcome_unknown');
        } else {
            $generation->forceFill(['status' => 'queued', 'run_token' => null, 'lease_expires_at' => null,
                'next_attempt_at' => now()])->save();
        }
    }

    public function recoverGenerations(): int
    {
        $count = 0;
        TrainingPlanGeneration::on('pgsql')->where(function ($query): void {
            $query->where('status', 'running')->where('lease_expires_at', '<=', now())
                ->orWhere(function ($queued): void {
                    $queued->where('status', 'queued')->where('next_attempt_at', '<=', now())
                        ->where(function ($age): void {
                            $age->whereNull('last_enqueued_at')->orWhere('last_enqueued_at', '<', now()->subMinute());
                        });
                });
        })->pluck('id')->each(function (string $id) use (&$count): void {
            $this->locked($id, function (TrainingPlanGeneration $generation) use (&$count): void {
                if ($generation->status === 'running' && $generation->lease_expires_at?->lte(now())) {
                    $this->recoverLocked($generation);
                }
                if ($generation->status === 'queued' && ! $generation->next_attempt_at?->isFuture()
                    && ($generation->last_enqueued_at === null || $generation->last_enqueued_at->lt(now()->subMinute()))) {
                    $generation->enqueue();
                    $count++;
                }
            });
        });

        return $count;
    }

    public function cancelGeneration(string $id, bool $acknowledgeUnknown): void
    {
        $this->locked($id, function (TrainingPlanGeneration $generation) use ($acknowledgeUnknown): void {
            if ($generation->status === 'uncertain' && ! $acknowledgeUnknown) {
                throw ValidationException::withMessages(['acknowledge_unknown' => 'Подтвердите: повторный запуск может вызвать дополнительные расходы у провайдера.']);
            }
            if ($generation->status === 'running') {
                $generation->forceFill(['cancel_requested_at' => now()])->save();
            } elseif (in_array($generation->status, ['queued', 'uncertain'], true)) {
                $this->finish($generation, 'cancelled', 'cancelled_by_user');
            }
        });
    }

    private function storePlan(
        User $user,
        ScheduledTraining $scheduledTraining,
        ValidatedTrainingPlan $validatedPlan,
    ): TrainingPlan {
        $trainingPlan = new TrainingPlan;
        $trainingPlan->forceFill([
            'scheduled_training_id' => $scheduledTraining->id,
            'trainee_id' => $scheduledTraining->trainee_id,
            'training_group_id' => $scheduledTraining->training_group_id,
            'title' => $validatedPlan->title,
            'goal' => $validatedPlan->goal,
            'total_duration_minutes' => $validatedPlan->totalDurationMinutes,
            'status' => 'draft',
            'source' => 'ai',
            'notes' => null,
            'warnings' => $validatedPlan->warnings,
            'ai_reasoning' => $validatedPlan->aiReasoning,
        ]);

        $user->trainingPlans()->save($trainingPlan);

        foreach ($validatedPlan->blocks as $validatedBlock) {
            $this->storeBlock($trainingPlan, $validatedBlock);
        }

        return $trainingPlan;
    }

    private function storeBlock(
        TrainingPlan $trainingPlan,
        ValidatedTrainingPlanBlock $validatedBlock,
    ): void {
        $block = $trainingPlan->blocks()->create([
            'name' => $validatedBlock->name,
            'duration_minutes' => $validatedBlock->durationMinutes,
            'position' => $validatedBlock->position,
            'notes' => $validatedBlock->notes,
        ]);

        $this->storeExercises($block, $validatedBlock);
    }

    private function storeExercises(
        TrainingPlanBlock $block,
        ValidatedTrainingPlanBlock $validatedBlock,
    ): void {
        foreach ($validatedBlock->exercises as $validatedExercise) {
            $block->exercises()->create([
                'exercise_id' => $validatedExercise->exerciseId,
                'name' => $validatedExercise->name,
                'description' => $validatedExercise->description,
                'duration_minutes' => $validatedExercise->durationMinutes,
                'sets' => $validatedExercise->sets,
                'repetitions' => $validatedExercise->repetitions,
                'rest_seconds' => $validatedExercise->restSeconds,
                'position' => $validatedExercise->position,
                'notes' => $validatedExercise->notes,
            ]);
        }
    }
}
