<?php

use App\Actions\TrainingPlans\ReserveTrainingPlanGeneration;
use App\Models\Exercise;
use App\Models\ScheduledTraining;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanBlock;
use App\Models\TrainingPlanExercise;
use App\Models\TrainingPlanGeneration;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

require_once __DIR__.'/generation-reservation-fixtures.php';

/** @return array{User, ScheduledTraining, TrainingPlan|null} */
function sessionStartFixture(?string $planStatus = 'draft', string $subjectType = 'trainee'): array
{
    $user = User::factory()->create();
    $training = reservationTraining($user, $subjectType);
    $training->update(['notes' => 'Контролировать самочувствие']);

    if ($planStatus === null) {
        return [$user, $training, null];
    }

    $attributes = sessionStartPlanAttributes($planStatus);
    $plan = TrainingPlan::factory()->for($user)->for($training, 'scheduledTraining')->create([
        ...Arr::except($attributes, ['blocks']),
        'trainee_id' => $training->trainee_id,
        'training_group_id' => $training->training_group_id,
    ]);

    foreach (array_reverse($attributes['blocks'], true) as $blockIndex => $blockAttributes) {
        $block = TrainingPlanBlock::factory()->for($plan)->create([
            ...Arr::except($blockAttributes, ['exercises']), 'position' => $blockIndex + 1,
        ]);
        foreach (array_reverse($blockAttributes['exercises'], true) as $exerciseIndex => $exerciseAttributes) {
            TrainingPlanExercise::factory()->for($block)->create([
                ...$exerciseAttributes, 'position' => $exerciseIndex + 1,
            ]);
        }
    }

    return [$user, $training, $plan];
}

function sessionStartPlanAttributes(string $status = 'draft'): array
{
    return [
        'title' => 'Силовая тренировка', 'goal' => 'Развить силу',
        'total_duration_minutes' => 60, 'status' => $status, 'notes' => 'Без боли',
        'blocks' => [
            ['name' => 'Разминка', 'duration_minutes' => 10, 'notes' => null, 'exercises' => [
                ['name' => 'Ходьба', 'description' => 'Спокойный темп', 'duration_minutes' => 5,
                    'sets' => null, 'repetitions' => null, 'rest_seconds' => null, 'notes' => null],
            ]],
            ['name' => 'Основная часть', 'duration_minutes' => 40, 'notes' => 'Контролировать технику', 'exercises' => [
                ['name' => 'Приседания', 'description' => 'Спина прямая', 'duration_minutes' => 15,
                    'sets' => 3, 'repetitions' => '8–12', 'rest_seconds' => 60, 'notes' => 'Без боли'],
                ['name' => 'Планка', 'description' => null, 'duration_minutes' => null,
                    'sets' => 2, 'repetitions' => '30 секунд', 'rest_seconds' => 30, 'notes' => null],
            ]],
            ['name' => 'Заминка', 'duration_minutes' => 10, 'notes' => 'Свободный блок', 'exercises' => []],
        ],
    ];
}

function sessionStartCachedGeneration(User $user, ScheduledTraining $training): TrainingPlanGeneration
{
    $exercise = Exercise::query()->where('user_id', $user->id)->sole();
    $generation = app(ReserveTrainingPlanGeneration::class)->execute($user, $training, (string) Str::uuid());
    $generation->forceFill([
        'attempt_count' => 1, 'request_started_at' => now(),
        'response_received_at' => now(), 'response_expires_at' => now()->addDay(),
        'response_payload' => json_encode([
            'title' => 'Сохранённый AI-план', 'goal' => 'Развить силу', 'total_duration_minutes' => 60,
            'ai_reasoning' => null, 'warnings' => [],
            'blocks' => [['name' => 'Основная часть', 'duration_minutes' => 60, 'position' => 1,
                'exercises' => [['exercise_id' => $exercise->id, 'position' => 1, 'sets' => 3]]]],
        ], JSON_THROW_ON_ERROR),
    ])->save();

    return $generation;
}
