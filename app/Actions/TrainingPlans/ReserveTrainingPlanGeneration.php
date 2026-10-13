<?php

namespace App\Actions\TrainingPlans;

use App\Models\ScheduledTraining;
use App\Models\TrainingPlanGeneration;
use App\Models\User;
use App\Services\Agent\TrainingContextBuilder;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class ReserveTrainingPlanGeneration
{
    public function __construct(private TrainingContextBuilder $contextBuilder) {}

    public function execute(User $user, ScheduledTraining $scheduledTraining, string $requestKey): TrainingPlanGeneration
    {
        Gate::forUser($user)->authorize('generateTrainingPlan', $scheduledTraining);
        $connection = $scheduledTraining->getConnection();
        $queue = Queue::connection('training-plan-generations');

        if ($connection->getDriverName() !== 'pgsql'
            || ! $queue instanceof DatabaseQueue
            || $queue->getDatabase() !== $connection) {
            throw new LogicException('Generation и Job должны использовать одно PostgreSQL-соединение.');
        }

        return $connection->transaction(function () use ($user, $scheduledTraining, $requestKey): TrainingPlanGeneration {
            $training = $scheduledTraining->newQuery()->where('user_id', $user->id)
                ->lockForUpdate()->findOrFail($scheduledTraining->id);
            $plan = $training->trainingPlan()->lockForUpdate()->first(['id']);
            $generations = (new TrainingPlanGeneration)->setConnection($training->getConnectionName())->newQuery();
            $existing = (clone $generations)->where('user_id', $user->id)->where('request_key', $requestKey)
                ->lockForUpdate()->first();

            if ($existing !== null) {
                return $this->sameRequest($existing, $training);
            }

            if ($training->status !== 'planned' || $plan !== null) {
                throw ValidationException::withMessages([
                    'scheduled_training_id' => 'Генерация доступна только для запланированного занятия без плана.',
                ]);
            }

            $subject = $training->trainee_id !== null ? $training->trainee : $training->trainingGroup;

            if ($subject === null || $subject->user_id !== $user->id || $subject->getAttribute('archived_at') !== null) {
                throw ValidationException::withMessages([
                    'scheduled_training_id' => 'Нельзя запустить генерацию для недоступного или архивного клиента либо группы.',
                ]);
            }

            $active = (clone $generations)->where('scheduled_training_id', $training->id)
                ->whereIn('status', TrainingPlanGeneration::ACTIVE_STATUSES)->lockForUpdate()->first();

            if ($active !== null) {
                throw ValidationException::withMessages([
                    'scheduled_training_id' => 'Для этого занятия уже зарезервирована активная генерация. Повторите запрос с её request_key или дождитесь завершения.',
                ]);
            }

            $id = (string) Str::uuid();
            $now = now();
            $input = $this->contextBuilder->generationInput($user, $training);
            $inserted = $generations->insertOrIgnore([
                'id' => $id, 'user_id' => $user->id, 'scheduled_training_id' => $training->id,
                'request_key' => $requestKey, 'status' => 'queued', 'next_attempt_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
                'context_hash' => $input['hash'],
            ]);

            if ($inserted === 0) {
                $existing = (clone $generations)->where('user_id', $user->id)->where('request_key', $requestKey)
                    ->lockForUpdate()->firstOrFail();

                return $this->sameRequest($existing, $training);
            }

            $generation = $generations->findOrFail($id);
            $generation->enqueue();

            return $generation;
        }, attempts: 3);
    }

    private function sameRequest(TrainingPlanGeneration $generation, ScheduledTraining $training): TrainingPlanGeneration
    {
        if ($generation->scheduled_training_id !== $training->id) {
            throw ValidationException::withMessages([
                'request_key' => 'Этот request_key уже использован для другого занятия.',
            ]);
        }

        return $generation;
    }
}
