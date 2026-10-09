<?php

use App\Actions\TrainingPlans\SaveTrainingPlan;
use App\Models\Exercise;
use App\Models\ScheduledTraining;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('guests cannot access training plan pages', function () {
    $this->get(route('training-plans.index'))->assertRedirect(route('login'));
    $this->get(route('training-plans.create'))->assertRedirect(route('login'));
});

test('a user sees only their training plans', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $scheduledTraining = createPlanScheduledTraining($user);
    $otherScheduledTraining = createPlanScheduledTraining($otherUser);

    $trainingPlan = createPlanRecord($user, $scheduledTraining, 'Свой план');
    createPlanRecord($otherUser, $otherScheduledTraining, 'Чужой план');

    $this->actingAs($user)
        ->get(route('training-plans.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('training-plans/index')
            ->has('trainingPlans', 1)
            ->where('trainingPlans.0.id', $trainingPlan->id)
            ->where('trainingPlans.0.title', 'Свой план'));
});

test('create page contains available trainings and visible exercises only', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $scheduledTraining = createPlanScheduledTraining($user);
    createPlanScheduledTraining($otherUser);

    Exercise::factory()->system()->create(['name' => 'Системное']);
    Exercise::factory()->for($user)->create(['name' => 'Своё']);
    Exercise::factory()->for($otherUser)->create(['name' => 'Чужое']);

    $this->actingAs($user)
        ->get(route('training-plans.create', [
            'scheduled_training' => $scheduledTraining->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('training-plans/create')
            ->has('scheduledTrainings', 1)
            ->where('selectedScheduledTrainingId', $scheduledTraining->id)
            ->has('exercises', 2)
            ->where('exercises', fn ($exercises): bool => collect($exercises)
                ->pluck('name')
                ->sort()
                ->values()
                ->all() === ['Своё', 'Системное']));
});

test('a user can create a nested manual plan that inherits its subject', function () {
    $user = User::factory()->create();
    $scheduledTraining = createPlanScheduledTraining($user);
    $libraryExercise = Exercise::factory()->for($user)->create([
        'name' => 'Планка',
        'description' => 'Базовая планка',
    ]);

    $response = $this->actingAs($user)->post(
        route('training-plans.store'),
        trainingPlanPayload($scheduledTraining, $libraryExercise),
    );

    $trainingPlan = TrainingPlan::query()->with('blocks.exercises')->sole();

    $response->assertRedirect(route('training-plans.show', $trainingPlan));
    expect($trainingPlan->user->is($user))->toBeTrue()
        ->and($trainingPlan->scheduledTraining->is($scheduledTraining))->toBeTrue()
        ->and($trainingPlan->trainee_id)->toBe($scheduledTraining->trainee_id)
        ->and($trainingPlan->training_group_id)->toBeNull()
        ->and($trainingPlan->source)->toBe('manual')
        ->and($trainingPlan->status)->toBe('draft')
        ->and($trainingPlan->blocks)->toHaveCount(2)
        ->and($trainingPlan->blocks[0]->position)->toBe(1)
        ->and($trainingPlan->blocks[1]->position)->toBe(2)
        ->and($trainingPlan->blocks[0]->exercises[0]->exercise_id)->toBe($libraryExercise->id)
        ->and($trainingPlan->blocks[0]->exercises[0]->name)->toBe('Планка на локтях')
        ->and($trainingPlan->blocks[1]->exercises[0]->exercise_id)->toBeNull()
        ->and($trainingPlan->blocks[1]->exercises[0]->name)->toBe('Игра на реакцию');
});

test('only one plan can be created for a scheduled training', function () {
    $user = User::factory()->create();
    $scheduledTraining = createPlanScheduledTraining($user);
    createPlanRecord($user, $scheduledTraining);

    $this->actingAs($user)
        ->post(route('training-plans.store'), trainingPlanPayload($scheduledTraining))
        ->assertSessionHasErrors('scheduled_training_id');

    expect(TrainingPlan::query()->count())->toBe(1);
});

test('manual plan creation inherits the current subject instead of a stale model', function (
    string $replacementType,
) {
    $user = User::factory()->create();
    $scheduledTraining = createPlanScheduledTraining($user);
    $staleTraining = $scheduledTraining->fresh();
    $replacement = $replacementType === 'trainee'
        ? $user->trainees()->create([
            'name' => 'Другой клиент',
            'level' => 'Начинающий',
            'goal' => 'Развить силу',
        ])
        : $user->trainingGroups()->create([
            'name' => 'Другая группа',
            'sport_type' => 'ОФП',
            'age_range' => '18–30',
            'level' => 'Начинающий',
            'goal' => 'Развить силу',
        ]);
    $subject = [
        'trainee_id' => $replacementType === 'trainee' ? $replacement->id : null,
        'training_group_id' => $replacementType === 'training_group' ? $replacement->id : null,
    ];

    $this->actingAs($user)
        ->patch(route('scheduled-trainings.update', $scheduledTraining), $subject)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('scheduled-trainings.show', $scheduledTraining));

    $plan = app(SaveTrainingPlan::class)->create(
        $user,
        $staleTraining,
        trainingPlanPayload($staleTraining),
    );

    expect($plan->scheduled_training_id)->toBe($scheduledTraining->id)
        ->and($plan->only(['trainee_id', 'training_group_id']))->toBe($subject)
        ->and($scheduledTraining->fresh()->only(['trainee_id', 'training_group_id']))->toBe($subject)
        ->and(TrainingPlan::query()->count())->toBe(1);
})->with([
    'client changed before creation' => ['trainee'],
    'client replaced by group before creation' => ['training_group'],
]);

test('a user cannot create a plan for another users training or exercise', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $scheduledTraining = createPlanScheduledTraining($user);
    $otherScheduledTraining = createPlanScheduledTraining($otherUser);
    $otherExercise = Exercise::factory()->for($otherUser)->create();

    $this->actingAs($user)
        ->post(route('training-plans.store'), trainingPlanPayload($otherScheduledTraining))
        ->assertSessionHasErrors('scheduled_training_id');

    $this->actingAs($user)
        ->post(
            route('training-plans.store'),
            trainingPlanPayload($scheduledTraining, $otherExercise),
        )
        ->assertSessionHasErrors('blocks.0.exercises.0.exercise_id');

    expect(TrainingPlan::query()->count())->toBe(0);
});

test('a user can update structure and approve their plan', function () {
    $user = User::factory()->create();
    $scheduledTraining = createPlanScheduledTraining($user);
    $libraryExercise = Exercise::factory()->for($user)->create();

    $this->actingAs($user)->post(
        route('training-plans.store'),
        trainingPlanPayload($scheduledTraining, $libraryExercise),
    );

    $trainingPlan = TrainingPlan::query()->sole();
    $oldBlockIds = $trainingPlan->blocks()->pluck('id');

    $this->actingAs($user)
        ->patch(route('training-plans.update', $trainingPlan), [
            ...trainingPlanPayload($scheduledTraining),
            'title' => 'Утверждённый план',
            'status' => 'approved',
            'blocks' => [[
                'name' => 'Основная часть',
                'duration_minutes' => 45,
                'notes' => null,
                'exercises' => [[
                    'exercise_id' => null,
                    'name' => 'Челночный бег',
                    'description' => 'Пять коротких отрезков',
                    'duration_minutes' => 15,
                    'sets' => 5,
                    'repetitions' => '1',
                    'rest_seconds' => 60,
                    'notes' => null,
                ]],
            ]],
        ])
        ->assertRedirect(route('training-plans.show', $trainingPlan));

    $trainingPlan->refresh()->load('blocks.exercises');

    expect($trainingPlan->title)->toBe('Утверждённый план')
        ->and($trainingPlan->status)->toBe('approved')
        ->and($trainingPlan->blocks)->toHaveCount(1)
        ->and($trainingPlan->blocks[0]->name)->toBe('Основная часть')
        ->and($trainingPlan->blocks[0]->exercises[0]->name)->toBe('Челночный бег');

    $oldBlockIds->each(fn (int $blockId) => $this->assertDatabaseMissing(
        'training_plan_blocks',
        ['id' => $blockId],
    ));
});

test('exercise snapshots do not change with the library', function () {
    $user = User::factory()->create();
    $scheduledTraining = createPlanScheduledTraining($user);
    $libraryExercise = Exercise::factory()->for($user)->create(['name' => 'Планка']);

    $this->actingAs($user)->post(
        route('training-plans.store'),
        trainingPlanPayload($scheduledTraining, $libraryExercise),
    );

    $libraryExercise->update(['name' => 'Новое название в базе']);

    expect(TrainingPlan::query()->sole()->blocks()->first()
        ?->exercises()->first()?->name)->toBe('Планка на локтях');
});

test('a plan is visible on its scheduled training page', function () {
    $user = User::factory()->create();
    $scheduledTraining = createPlanScheduledTraining($user);
    $trainingPlan = createPlanRecord($user, $scheduledTraining, 'План U12');

    $this->actingAs($user)
        ->get(route('scheduled-trainings.show', $scheduledTraining))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('scheduledTraining.training_plan.id', $trainingPlan->id)
            ->where('scheduledTraining.training_plan.title', 'План U12'));
});

test('another users plan is inaccessible', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $scheduledTraining = createPlanScheduledTraining($otherUser);
    $trainingPlan = createPlanRecord($otherUser, $scheduledTraining);

    $this->actingAs($user)
        ->get(route('training-plans.show', $trainingPlan))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('training-plans.edit', $trainingPlan))
        ->assertNotFound();

    $this->actingAs($user)
        ->patch(route('training-plans.update', $trainingPlan), trainingPlanPayload($scheduledTraining))
        ->assertForbidden();

    $this->actingAs($user)
        ->delete(route('training-plans.destroy', $trainingPlan))
        ->assertNotFound();
});

test('a user can delete their plan', function () {
    $user = User::factory()->create();
    $scheduledTraining = createPlanScheduledTraining($user);
    $trainingPlan = createPlanRecord($user, $scheduledTraining);

    $this->actingAs($user)
        ->delete(route('training-plans.destroy', $trainingPlan))
        ->assertRedirect(route('training-plans.index'));

    $this->assertModelMissing($trainingPlan);
});

test('a user can complete their plan and scheduled training together', function () {
    $user = User::factory()->create();
    $scheduledTraining = createPlanScheduledTraining($user);
    $trainingPlan = createPlanRecord($user, $scheduledTraining);
    $trainingPlan->update(['status' => 'approved']);

    $this->actingAs($user)
        ->post(route('training-plans.complete', $trainingPlan))
        ->assertRedirect(route('training-plans.show', $trainingPlan));

    expect($trainingPlan->fresh()?->status)->toBe('completed')
        ->and($scheduledTraining->fresh()?->status)->toBe('completed');
});

test('completed status cannot bypass the completion action', function () {
    $user = User::factory()->create();
    $scheduledTraining = createPlanScheduledTraining($user);
    $trainingPlan = createPlanRecord($user, $scheduledTraining);

    $this->actingAs($user)
        ->patch(route('training-plans.update', $trainingPlan), [
            ...trainingPlanPayload($scheduledTraining),
            'status' => 'completed',
        ])
        ->assertSessionHasErrors('status');

    expect($trainingPlan->fresh()?->status)->toBe('draft')
        ->and($scheduledTraining->fresh()?->status)->toBe('planned');
});

test('a user cannot complete another users plan', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $scheduledTraining = createPlanScheduledTraining($otherUser);
    $trainingPlan = createPlanRecord($otherUser, $scheduledTraining);

    $this->actingAs($user)
        ->post(route('training-plans.complete', $trainingPlan))
        ->assertNotFound();

    expect($trainingPlan->fresh()?->status)->toBe('draft')
        ->and($scheduledTraining->fresh()?->status)->toBe('planned');
});

test('a trainee card shows completed training history ordered by schedule', function () {
    $user = User::factory()->create();
    $trainee = $user->trainees()->create([
        'name' => 'История клиента',
        'level' => 'Средний',
        'goal' => 'Прогресс',
    ]);

    $olderTraining = createHistoryScheduledTraining($user, [
        'trainee_id' => $trainee->id,
        'starts_at' => '2026-07-01 18:00:00+03:00',
        'ends_at' => '2026-07-01 19:00:00+03:00',
    ]);
    $newerTraining = createHistoryScheduledTraining($user, [
        'trainee_id' => $trainee->id,
        'starts_at' => '2026-07-08 18:00:00+03:00',
        'ends_at' => '2026-07-08 19:00:00+03:00',
    ]);
    $draftTraining = createHistoryScheduledTraining($user, [
        'trainee_id' => $trainee->id,
        'starts_at' => '2026-07-10 18:00:00+03:00',
        'ends_at' => '2026-07-10 19:00:00+03:00',
    ]);

    createPlanRecord($user, $olderTraining, 'Старая тренировка')->update(['status' => 'completed']);
    createPlanRecord($user, $newerTraining, 'Новая тренировка')->update(['status' => 'completed']);
    createPlanRecord($user, $draftTraining, 'Черновик');

    $this->actingAs($user)
        ->get(route('trainees.show', $trainee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('trainingHistory', 2)
            ->where('trainingHistory.0.title', 'Новая тренировка')
            ->where('trainingHistory.1.title', 'Старая тренировка'));
});

test('a training group card shows its completed training history', function () {
    $user = User::factory()->create();
    $trainingGroup = $user->trainingGroups()->create([
        'name' => 'Группа U12',
        'sport_type' => 'ОФП',
        'age_range' => '10–12',
        'level' => 'Средний',
        'goal' => 'Координация',
    ]);
    $scheduledTraining = createHistoryScheduledTraining($user, [
        'training_group_id' => $trainingGroup->id,
    ]);
    $trainingPlan = createPlanRecord($user, $scheduledTraining, 'Групповая история');
    $trainingPlan->update(['status' => 'completed']);

    $this->actingAs($user)
        ->get(route('training-groups.show', $trainingGroup))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('trainingHistory', 1)
            ->where('trainingHistory.0.id', $trainingPlan->id)
            ->where('trainingHistory.0.status', 'completed'));
});

function createPlanScheduledTraining(User $user): ScheduledTraining
{
    $trainee = $user->trainees()->create([
        'name' => 'Алексей Смирнов',
        'age' => 12,
        'level' => 'Средний',
        'goal' => 'Развитие координации',
    ]);

    return $user->scheduledTrainings()->create([
        'trainee_id' => $trainee->id,
        'starts_at' => '2026-07-08 18:00:00+03:00',
        'ends_at' => '2026-07-08 19:00:00+03:00',
        'location' => 'Зал №1',
        'status' => 'planned',
        'color' => 'blue',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function createHistoryScheduledTraining(User $user, array $overrides): ScheduledTraining
{
    return $user->scheduledTrainings()->create(array_merge([
        'trainee_id' => null,
        'training_group_id' => null,
        'starts_at' => '2026-07-08 18:00:00+03:00',
        'ends_at' => '2026-07-08 19:00:00+03:00',
        'location' => 'Зал №1',
        'status' => 'planned',
        'color' => 'blue',
    ], $overrides));
}

function createPlanRecord(
    User $user,
    ScheduledTraining $scheduledTraining,
    string $title = 'План тренировки',
): TrainingPlan {
    $trainingPlan = TrainingPlan::factory()->create([
        'user_id' => $user->id,
        'scheduled_training_id' => $scheduledTraining->id,
        'trainee_id' => $scheduledTraining->trainee_id,
        'training_group_id' => $scheduledTraining->training_group_id,
        'title' => $title,
    ]);

    TrainingPlanBlock::factory()->for($trainingPlan)->create();

    return $trainingPlan;
}

/**
 * @return array<string, mixed>
 */
function trainingPlanPayload(
    ScheduledTraining $scheduledTraining,
    ?Exercise $libraryExercise = null,
): array {
    return [
        'scheduled_training_id' => $scheduledTraining->id,
        'title' => 'План на координацию',
        'goal' => 'Развить координацию и скорость реакции',
        'total_duration_minutes' => 60,
        'status' => 'draft',
        'notes' => 'Контролировать технику',
        'blocks' => [
            [
                'name' => 'Разминка',
                'duration_minutes' => 15,
                'notes' => null,
                'exercises' => [[
                    'exercise_id' => $libraryExercise?->id,
                    'name' => $libraryExercise ? 'Планка на локтях' : 'Суставная разминка',
                    'description' => $libraryExercise ? 'Три подхода по 30 секунд' : 'Разогреть суставы',
                    'duration_minutes' => 10,
                    'sets' => $libraryExercise ? 3 : null,
                    'repetitions' => $libraryExercise ? '30 секунд' : null,
                    'rest_seconds' => 30,
                    'notes' => null,
                ]],
            ],
            [
                'name' => 'Основная часть',
                'duration_minutes' => 45,
                'notes' => 'Средняя интенсивность',
                'exercises' => [[
                    'exercise_id' => null,
                    'name' => 'Игра на реакцию',
                    'description' => 'Работа в парах по сигналу тренера',
                    'duration_minutes' => 15,
                    'sets' => null,
                    'repetitions' => null,
                    'rest_seconds' => null,
                    'notes' => 'Менять ведущего каждые две минуты',
                ]],
            ],
        ],
    ];
}
