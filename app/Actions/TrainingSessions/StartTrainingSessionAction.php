<?php

namespace App\Actions\TrainingSessions;

use App\Models\ScheduledTraining;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanGeneration;
use App\Models\TrainingSession;
use App\Models\TrainingSessionExercise;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class StartTrainingSessionAction
{
    // shortcut: keep start internal until schedule, plan and generation writers protect active sessions.
    public function execute(User $user, ScheduledTraining $scheduledTraining): TrainingSession
    {
        Gate::forUser($user)->authorize('update', $scheduledTraining);

        return DB::transaction(function () use ($user, $scheduledTraining): TrainingSession {
            $training = $user->scheduledTrainings()->lockForUpdate()->findOrFail($scheduledTraining->id);
            $plan = $training->trainingPlan()->lockForUpdate()->first();
            $session = $training->trainingSession()->lockForUpdate()->first();

            if ($session !== null) {
                if ($session->user_id !== $user->id) {
                    throw ValidationException::withMessages([
                        'training_session' => 'Владелец сессии не соответствует владельцу занятия.',
                    ]);
                }

                return $session;
            }

            if ($training->status !== 'planned') {
                throw ValidationException::withMessages([
                    'status' => 'Начать можно только запланированное занятие.',
                ]);
            }

            if ($plan !== null && ($plan->user_id !== $user->id
                || $plan->trainee_id !== $training->trainee_id
                || $plan->training_group_id !== $training->training_group_id
                || ! in_array($plan->status, TrainingPlan::EDITABLE_STATUSES, true))) {
                throw ValidationException::withMessages([
                    'training_plan' => 'План не соответствует занятию или недоступен для проведения. Проверьте получателя и статус плана.',
                ]);
            }

            if (TrainingPlanGeneration::query()->where('scheduled_training_id', $training->id)
                ->whereIn('status', TrainingPlanGeneration::ACTIVE_STATUSES)->exists()) {
                throw ValidationException::withMessages([
                    'training_plan_generation' => 'Дождитесь завершения или отмените активную генерацию плана перед началом занятия.',
                ]);
            }

            $isTrainee = $training->trainee_id !== null;
            $subject = ($isTrainee ? $training->trainee() : $training->trainingGroup())
                ->where('user_id', $user->id)->firstOrFail();
            $plan?->load('blocks.exercises');
            $snapshot = [
                'subject' => ['type' => $isTrainee ? 'trainee' : 'training_group', ...$subject->only(['id', 'name'])],
                'scheduled_training' => [
                    'starts_at' => $training->starts_at->toIso8601String(),
                    'ends_at' => $training->ends_at->toIso8601String(),
                    'location' => $training->location,
                    'notes' => $training->notes,
                ],
                'plan' => $plan === null ? null : [
                    ...$plan->only(['title', 'goal', 'total_duration_minutes', 'status', 'source', 'notes']),
                    'blocks' => $plan->blocks->map(fn ($block): array => $block->only([
                        'name', 'duration_minutes', 'position', 'notes',
                    ]))->all(),
                ],
            ];

            $session = new TrainingSession(['started_at' => now()]);
            $session->forceFill([
                'user_id' => $user->id,
                'scheduled_training_id' => $training->id,
                'training_plan_id' => $plan?->id,
                'trainee_id' => $training->trainee_id,
                'training_group_id' => $training->training_group_id,
                'snapshot' => $snapshot,
            ])->save();

            foreach ($plan->blocks ?? [] as $block) {
                foreach ($block->exercises as $exercise) {
                    $session->exercises()->save((new TrainingSessionExercise)->forceFill([
                        'block_position' => $block->position,
                        'position' => $exercise->position,
                        'snapshot' => $exercise->only([
                            'exercise_id', 'name', 'description', 'duration_minutes',
                            'sets', 'repetitions', 'rest_seconds', 'notes',
                        ]),
                    ]));
                }
            }

            return $session;
        }, attempts: 3);
    }
}
