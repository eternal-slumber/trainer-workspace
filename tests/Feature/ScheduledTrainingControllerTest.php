<?php

use App\Http\Requests\UpdateScheduledTrainingRequest;
use App\Models\ScheduledTraining;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanBlock;
use App\Models\TrainingPlanExercise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

afterEach(function () {
    Date::setTestNow();
});

test('guests cannot access scheduled training pages', function () {
    $this->get(route('scheduled-trainings.index'))->assertRedirect(route('login'));
    $this->get(route('scheduled-trainings.create'))->assertRedirect(route('login'));
});

test('a user sees only their upcoming scheduled trainings', function () {
    Date::setTestNow('2026-07-06 12:00:00');

    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $trainee = $user->trainees()->create(scheduledTraineePayload());
    $otherTrainee = $otherUser->trainees()->create(scheduledTraineePayload());

    $scheduledTraining = $user->scheduledTrainings()->create(
        scheduledTrainingPayload([
            'trainee_id' => $trainee->id,
            'starts_at' => '2026-07-07 18:00:00+03:00',
            'ends_at' => '2026-07-07 19:00:00+03:00',
        ]),
    );
    $user->scheduledTrainings()->create(scheduledTrainingPayload([
        'trainee_id' => $trainee->id,
        'starts_at' => '2026-07-05 18:00:00+03:00',
        'ends_at' => '2026-07-05 19:00:00+03:00',
    ]));
    $otherUser->scheduledTrainings()->create(scheduledTrainingPayload([
        'trainee_id' => $otherTrainee->id,
        'starts_at' => '2026-07-07 17:00:00+03:00',
        'ends_at' => '2026-07-07 18:00:00+03:00',
    ]));

    $this->actingAs($user)
        ->get(route('scheduled-trainings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('scheduled-trainings/index')
            ->has('scheduledTrainings', 1)
            ->where('scheduledTrainings.0.id', $scheduledTraining->id)
            ->where('scheduledTrainings.0.subject_name', 'Алексей Смирнов'));
});

test('create page contains only the current users trainees and groups', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $user->trainees()->create(scheduledTraineePayload(['name' => 'Свой клиент']));
    $otherUser->trainees()->create(scheduledTraineePayload(['name' => 'Чужой клиент']));
    $user->trainingGroups()->create(scheduledTrainingGroupPayload(['name' => 'Своя группа']));
    $otherUser->trainingGroups()->create(scheduledTrainingGroupPayload(['name' => 'Чужая группа']));

    $this->actingAs($user)
        ->get(route('scheduled-trainings.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('scheduled-trainings/create')
            ->has('trainees', 1)
            ->where('trainees.0.name', 'Свой клиент')
            ->has('trainingGroups', 1)
            ->where('trainingGroups.0.name', 'Своя группа'));
});

test('a user can schedule a training for their trainee', function () {
    $user = User::factory()->create();
    $trainee = $user->trainees()->create(scheduledTraineePayload());

    $response = $this->actingAs($user)->post(
        route('scheduled-trainings.store'),
        scheduledTrainingPayload([
            'trainee_id' => $trainee->id,
            'color' => 'green',
            'notes' => 'Проверить технику приседаний',
        ]),
    );

    $scheduledTraining = ScheduledTraining::query()->sole();

    $response->assertRedirect(route('scheduled-trainings.show', $scheduledTraining));
    expect($scheduledTraining->user->is($user))->toBeTrue()
        ->and($scheduledTraining->trainee?->is($trainee))->toBeTrue()
        ->and($scheduledTraining->color)->toBe('green')
        ->and($scheduledTraining->notes)->toBe('Проверить технику приседаний');
});

test('a scheduled training color must belong to the supported palette', function () {
    $user = User::factory()->create();
    $trainee = $user->trainees()->create(scheduledTraineePayload());

    $this->actingAs($user)
        ->post(route('scheduled-trainings.store'), scheduledTrainingPayload([
            'trainee_id' => $trainee->id,
            'color' => 'transparent',
        ]))
        ->assertSessionHasErrors('color');
});

test('a user can schedule a training for their group', function () {
    $user = User::factory()->create();
    $trainingGroup = $user->trainingGroups()->create(scheduledTrainingGroupPayload());

    $response = $this->actingAs($user)->post(
        route('scheduled-trainings.store'),
        scheduledTrainingPayload(['training_group_id' => $trainingGroup->id]),
    );

    $scheduledTraining = ScheduledTraining::query()->sole();

    $response->assertRedirect(route('scheduled-trainings.show', $scheduledTraining));
    expect($scheduledTraining->trainingGroup?->is($trainingGroup))->toBeTrue();
});

test('a scheduled training requires exactly one trainee or training group', function () {
    $user = User::factory()->create();
    $trainee = $user->trainees()->create(scheduledTraineePayload());
    $trainingGroup = $user->trainingGroups()->create(scheduledTrainingGroupPayload());

    $this->actingAs($user)
        ->post(route('scheduled-trainings.store'), scheduledTrainingPayload())
        ->assertSessionHasErrors(['trainee_id', 'training_group_id']);

    $this->actingAs($user)
        ->post(route('scheduled-trainings.store'), scheduledTrainingPayload([
            'trainee_id' => $trainee->id,
            'training_group_id' => $trainingGroup->id,
        ]))
        ->assertSessionHasErrors(['trainee_id', 'training_group_id']);
});

test('a user cannot schedule a training for another users trainee or group', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $trainee = $otherUser->trainees()->create(scheduledTraineePayload());
    $trainingGroup = $otherUser->trainingGroups()->create(scheduledTrainingGroupPayload());

    $this->actingAs($user)
        ->post(
            route('scheduled-trainings.store'),
            scheduledTrainingPayload(['trainee_id' => $trainee->id]),
        )
        ->assertSessionHasErrors('trainee_id');

    $this->actingAs($user)
        ->post(
            route('scheduled-trainings.store'),
            scheduledTrainingPayload(['training_group_id' => $trainingGroup->id]),
        )
        ->assertSessionHasErrors('training_group_id');
});

test('a user can view edit and update their scheduled training', function () {
    $user = User::factory()->create();
    $trainee = $user->trainees()->create(scheduledTraineePayload());
    $scheduledTraining = $user->scheduledTrainings()->create(
        scheduledTrainingPayload(['trainee_id' => $trainee->id]),
    );

    $this->actingAs($user)
        ->get(route('scheduled-trainings.show', $scheduledTraining))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('scheduled-trainings/show')
            ->where('scheduledTraining.id', $scheduledTraining->id));

    $this->actingAs($user)
        ->get(route('scheduled-trainings.edit', $scheduledTraining))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('scheduled-trainings/edit')
            ->where('scheduledTraining.id', $scheduledTraining->id)
            ->has('trainees', 1)
            ->has('trainingGroups', 0));

    $this->actingAs($user)
        ->patch(route('scheduled-trainings.update', $scheduledTraining), [
            'starts_at' => '2026-07-07 20:00:00+03:00',
            'ends_at' => '2026-07-07 21:30:00+03:00',
            'location' => 'Зал №3',
            'status' => 'completed',
            'color' => 'purple',
            'notes' => 'Тренировка завершена',
        ])
        ->assertRedirect(route('scheduled-trainings.show', $scheduledTraining));

    $scheduledTraining->refresh();

    expect($scheduledTraining->location)->toBe('Зал №3')
        ->and($scheduledTraining->status)->toBe('completed')
        ->and($scheduledTraining->color)->toBe('purple')
        ->and($scheduledTraining->notes)->toBe('Тренировка завершена');
});

test('a scheduled training subject can change without a plan', function (
    string $originalType,
    string $replacementType,
) {
    $user = User::factory()->create();
    $scheduledTraining = createSubjectScheduledTraining($user, $originalType);
    $replacementTraining = createSubjectScheduledTraining($user, $replacementType);

    $this->actingAs($user)
        ->patch(route('scheduled-trainings.update', $scheduledTraining), [
            'trainee_id' => $replacementTraining->trainee_id,
            'training_group_id' => $replacementTraining->training_group_id,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('scheduled-trainings.show', $scheduledTraining));

    $scheduledTraining->refresh();

    expect($scheduledTraining->trainee_id)->toBe($replacementTraining->trainee_id)
        ->and($scheduledTraining->training_group_id)->toBe($replacementTraining->training_group_id)
        ->and($scheduledTraining->trainingPlan()->exists())->toBeFalse();
})->with([
    'client to client' => ['trainee', 'trainee'],
    'client to group' => ['trainee', 'training_group'],
    'group to client' => ['training_group', 'trainee'],
    'group to group' => ['training_group', 'training_group'],
]);

test('a plan prevents subject changes without modifying any saved data', function (
    string $originalType,
    string $replacementType,
    string $planStatus,
    string $planSource,
) {
    $user = User::factory()->create();
    $scheduledTraining = createSubjectScheduledTraining($user, $originalType);
    $replacementTraining = createSubjectScheduledTraining($user, $replacementType);
    $plan = createScheduledSubjectPlan($scheduledTraining, $planStatus, $planSource);
    $trainingBefore = $scheduledTraining->fresh()->getAttributes();
    $planBefore = $plan->fresh(['blocks.exercises'])->toArray();
    $changedFields = $originalType === $replacementType
        ? [$originalType === 'trainee' ? 'trainee_id' : 'training_group_id']
        : ['trainee_id', 'training_group_id'];

    $this->actingAs($user)
        ->from(route('scheduled-trainings.edit', $scheduledTraining))
        ->patch(route('scheduled-trainings.update', $scheduledTraining), [
            'trainee_id' => $replacementTraining->trainee_id,
            'training_group_id' => $replacementTraining->training_group_id,
            'location' => 'Локация не должна сохраниться',
            'notes' => 'Заметка не должна сохраниться',
        ])
        ->assertRedirect(route('scheduled-trainings.edit', $scheduledTraining))
        ->assertSessionHasErrors($changedFields);

    expect($scheduledTraining->fresh()->getAttributes())->toBe($trainingBefore)
        ->and($plan->fresh(['blocks.exercises'])->toArray())->toBe($planBefore)
        ->and(TrainingPlan::query()->count())->toBe(1);
})->with([
    'manual draft client to client' => ['trainee', 'trainee', 'draft', 'manual'],
    'AI draft client to group' => ['trainee', 'training_group', 'draft', 'ai'],
    'group to client' => ['training_group', 'trainee', 'draft', 'manual'],
    'group to group' => ['training_group', 'training_group', 'draft', 'manual'],
    'approved plan' => ['trainee', 'trainee', 'approved', 'manual'],
    'completed plan' => ['trainee', 'trainee', 'completed', 'manual'],
    'generating plan' => ['trainee', 'trainee', 'generating', 'ai'],
    'failed plan' => ['trainee', 'trainee', 'failed', 'ai'],
]);

test('other scheduled training fields remain editable with a plan', function (
    string $subjectType,
    bool $includeSubject,
) {
    $user = User::factory()->create();
    $scheduledTraining = createSubjectScheduledTraining($user, $subjectType);
    $plan = createScheduledSubjectPlan($scheduledTraining);
    $planBefore = $plan->fresh(['blocks.exercises'])->toArray();
    $changes = [
        'starts_at' => '2026-07-09T18:00:00Z',
        'ends_at' => '2026-07-09T19:30:00Z',
        'location' => 'Новый зал',
        'status' => 'cancelled',
        'color' => 'purple',
        'notes' => 'Новая заметка',
    ];

    if ($includeSubject) {
        $changes['trainee_id'] = $scheduledTraining->trainee_id === null
            ? null : (string) $scheduledTraining->trainee_id;
        $changes['training_group_id'] = $scheduledTraining->training_group_id === null
            ? null : (string) $scheduledTraining->training_group_id;
    }

    $this->actingAs($user)
        ->patch(route('scheduled-trainings.update', $scheduledTraining), $changes)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('scheduled-trainings.show', $scheduledTraining));

    $scheduledTraining->refresh();

    expect($scheduledTraining->starts_at->toIso8601String())->toBe('2026-07-09T18:00:00+00:00')
        ->and($scheduledTraining->ends_at->toIso8601String())->toBe('2026-07-09T19:30:00+00:00')
        ->and($scheduledTraining->only(['location', 'status', 'color', 'notes']))
        ->toBe(array_intersect_key($changes, array_flip(['location', 'status', 'color', 'notes'])))
        ->and($scheduledTraining->trainee_id)->toBe($plan->trainee_id)
        ->and($scheduledTraining->training_group_id)->toBe($plan->training_group_id)
        ->and($plan->fresh(['blocks.exercises'])->toArray())->toBe($planBefore);
})->with([
    'client with string identifier' => ['trainee', true],
    'group with string identifier' => ['training_group', true],
    'client without subject fields' => ['trainee', false],
    'group without subject fields' => ['training_group', false],
]);

test('a subject change with a plan returns a clear JSON validation error', function () {
    $user = User::factory()->create();
    $scheduledTraining = createSubjectScheduledTraining($user);
    $replacementTraining = createSubjectScheduledTraining($user);
    createScheduledSubjectPlan($scheduledTraining);

    $this->actingAs($user)
        ->patchJson(route('scheduled-trainings.update', $scheduledTraining), [
            'trainee_id' => $replacementTraining->trainee_id,
            'training_group_id' => null,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('trainee_id')
        ->assertJsonPath(
            'errors.trainee_id.0',
            'Нельзя изменить клиента или группу: для этой тренировки уже создан план.',
        );
});

test('a plan created after request validation still prevents a subject change', function () {
    $user = User::factory()->create();
    $scheduledTraining = createSubjectScheduledTraining($user);
    $replacementTraining = createSubjectScheduledTraining($user);
    $trainingBefore = $scheduledTraining->fresh()->getAttributes();

    // Insert the plan between FormRequest validation and the controller write.
    app()->afterResolving(UpdateScheduledTrainingRequest::class, function () use ($scheduledTraining): void {
        createScheduledSubjectPlan($scheduledTraining);
    });

    $this->actingAs($user)
        ->patchJson(route('scheduled-trainings.update', $scheduledTraining), [
            'trainee_id' => $replacementTraining->trainee_id,
            'training_group_id' => null,
            'location' => 'Локация не должна сохраниться',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('trainee_id');

    expect($scheduledTraining->fresh()->getAttributes())->toBe($trainingBefore)
        ->and($scheduledTraining->trainingPlan()->sole()->trainee_id)->toBe($scheduledTraining->trainee_id)
        ->and(TrainingPlan::query()->count())->toBe(1);
});

test('a partial update preserves a subject changed after request validation', function () {
    $user = User::factory()->create();
    $scheduledTraining = createSubjectScheduledTraining($user);
    $replacementTraining = createSubjectScheduledTraining($user);

    // The route-bound model is stale when this intervening change creates a plan.
    app()->afterResolving(UpdateScheduledTrainingRequest::class, function () use (
        $scheduledTraining,
        $replacementTraining,
    ): void {
        $freshTraining = $scheduledTraining->fresh();
        $freshTraining->update(['trainee_id' => $replacementTraining->trainee_id]);
        createScheduledSubjectPlan($freshTraining);
    });

    $this->actingAs($user)
        ->patch(route('scheduled-trainings.update', $scheduledTraining), [
            'location' => 'Новый зал',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('scheduled-trainings.show', $scheduledTraining));

    $scheduledTraining->refresh();

    expect($scheduledTraining->location)->toBe('Новый зал')
        ->and($scheduledTraining->trainee_id)->toBe($replacementTraining->trainee_id)
        ->and($scheduledTraining->training_group_id)->toBeNull()
        ->and($scheduledTraining->trainingPlan()->sole()->trainee_id)->toBe($replacementTraining->trainee_id);
});

test('partial patches preserve fields saved by an intervening request', function (bool $hasPlan) {
    $user = User::factory()->create();
    $scheduledTraining = createSubjectScheduledTraining($user);

    if ($hasPlan) {
        createScheduledSubjectPlan($scheduledTraining);
    }

    $intervened = false;

    // Finish another PATCH after the first request validates, but before it locks the row.
    app()->afterResolving(UpdateScheduledTrainingRequest::class, function () use (
        $scheduledTraining,
        &$intervened,
    ): void {
        if ($intervened) {
            return;
        }

        $intervened = true;
        $this->patchJson(route('scheduled-trainings.update', $scheduledTraining), [
            'notes' => 'Заметка из другого запроса',
            'color' => 'green',
        ])->assertRedirect(route('scheduled-trainings.show', $scheduledTraining));
    });

    $this->actingAs($user)
        ->patchJson(route('scheduled-trainings.update', $scheduledTraining), [
            'location' => 'Новый зал',
        ])
        ->assertRedirect(route('scheduled-trainings.show', $scheduledTraining));

    expect($intervened)->toBeTrue()
        ->and($scheduledTraining->fresh()->only(['location', 'notes', 'color']))->toBe([
            'location' => 'Новый зал',
            'notes' => 'Заметка из другого запроса',
            'color' => 'green',
        ]);
})->with([
    'without a plan' => [false],
    'with a plan' => [true],
]);

test('a partial patch can explicitly clear notes without overwriting another change', function () {
    $user = User::factory()->create();
    $scheduledTraining = createSubjectScheduledTraining($user);
    $scheduledTraining->update(['notes' => 'Старая заметка']);
    $intervened = false;

    app()->afterResolving(UpdateScheduledTrainingRequest::class, function () use (
        $scheduledTraining,
        &$intervened,
    ): void {
        if ($intervened) {
            return;
        }

        $intervened = true;
        $this->patchJson(route('scheduled-trainings.update', $scheduledTraining), [
            'color' => 'green',
        ])->assertRedirect(route('scheduled-trainings.show', $scheduledTraining));
    });

    $this->actingAs($user)
        ->patchJson(route('scheduled-trainings.update', $scheduledTraining), ['notes' => null])
        ->assertRedirect(route('scheduled-trainings.show', $scheduledTraining));

    expect($scheduledTraining->fresh()->notes)->toBeNull()
        ->and($scheduledTraining->fresh()->color)->toBe('green');
});

test('partial date patches are validated against the current locked dates', function () {
    $user = User::factory()->create();
    $scheduledTraining = createSubjectScheduledTraining($user);
    $scheduledTraining->update([
        'starts_at' => '2026-07-09T18:00:00Z',
        'ends_at' => '2026-07-09T19:00:00Z',
    ]);
    $intervened = false;

    app()->afterResolving(UpdateScheduledTrainingRequest::class, function () use (
        $scheduledTraining,
        &$intervened,
    ): void {
        if ($intervened) {
            return;
        }

        $intervened = true;
        $this->patchJson(route('scheduled-trainings.update', $scheduledTraining), [
            'starts_at' => '2026-07-09T18:45:00Z',
        ])->assertRedirect(route('scheduled-trainings.show', $scheduledTraining));
    });

    $this->actingAs($user)
        ->patchJson(route('scheduled-trainings.update', $scheduledTraining), [
            'ends_at' => '2026-07-09T18:30:00Z',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ends_at');

    $scheduledTraining->refresh();

    expect($scheduledTraining->starts_at->toIso8601String())->toBe('2026-07-09T18:45:00+00:00')
        ->and($scheduledTraining->ends_at->toIso8601String())->toBe('2026-07-09T19:00:00+00:00');
});

test('a partial subject patch cannot clear an unsubmitted current group', function () {
    $user = User::factory()->create();
    $scheduledTraining = createSubjectScheduledTraining($user);
    $replacementTraining = createSubjectScheduledTraining($user);
    $groupTraining = createSubjectScheduledTraining($user, 'training_group');
    $intervened = false;

    app()->afterResolving(UpdateScheduledTrainingRequest::class, function () use (
        $scheduledTraining,
        $groupTraining,
        &$intervened,
    ): void {
        if ($intervened) {
            return;
        }

        $intervened = true;
        $this->patchJson(route('scheduled-trainings.update', $scheduledTraining), [
            'trainee_id' => null,
            'training_group_id' => $groupTraining->training_group_id,
        ])->assertRedirect(route('scheduled-trainings.show', $scheduledTraining));
    });

    $this->actingAs($user)
        ->patchJson(route('scheduled-trainings.update', $scheduledTraining), [
            'trainee_id' => $replacementTraining->trainee_id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['trainee_id', 'training_group_id']);

    expect($scheduledTraining->fresh()->trainee_id)->toBeNull()
        ->and($scheduledTraining->fresh()->training_group_id)->toBe($groupTraining->training_group_id)
        ->and($scheduledTraining->trainingPlan()->exists())->toBeFalse();
});

test('a training created for today appears on dashboard', function () {
    Date::setTestNow('2026-07-06 12:00:00');

    $user = User::factory()->create();
    $trainingGroup = $user->trainingGroups()->create(scheduledTrainingGroupPayload());

    $this->actingAs($user)->post(
        route('scheduled-trainings.store'),
        scheduledTrainingPayload([
            'training_group_id' => $trainingGroup->id,
            'starts_at' => '2026-07-06 18:00:00+03:00',
            'ends_at' => '2026-07-06 19:00:00+03:00',
        ]),
    );

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->has('scheduledTrainings', 1)
            ->where('scheduledTrainings.0.subject_name', 'Группа U12'));
});

test('a user sees 404 for another users scheduled training', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $trainee = $otherUser->trainees()->create(scheduledTraineePayload());
    $scheduledTraining = $otherUser->scheduledTrainings()->create(
        scheduledTrainingPayload(['trainee_id' => $trainee->id]),
    );

    $this->actingAs($user)
        ->get(route('scheduled-trainings.show', $scheduledTraining))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('scheduled-trainings.edit', $scheduledTraining))
        ->assertNotFound();

    $this->actingAs($user)
        ->patch(route('scheduled-trainings.update', $scheduledTraining), [
            'location' => 'Взлом',
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->delete(route('scheduled-trainings.destroy', $scheduledTraining))
        ->assertNotFound();

    $this->assertModelExists($scheduledTraining);
});

test('a user can delete their scheduled training', function () {
    $user = User::factory()->create();
    $trainee = $user->trainees()->create(scheduledTraineePayload());
    $scheduledTraining = $user->scheduledTrainings()->create(
        scheduledTrainingPayload(['trainee_id' => $trainee->id]),
    );

    $this->actingAs($user)
        ->delete(route('scheduled-trainings.destroy', $scheduledTraining))
        ->assertRedirect(route('scheduled-trainings.index'));

    $this->assertModelMissing($scheduledTraining);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function scheduledTrainingPayload(array $overrides = []): array
{
    return array_merge([
        'trainee_id' => null,
        'training_group_id' => null,
        'starts_at' => '2026-07-07 18:00:00+03:00',
        'ends_at' => '2026-07-07 19:00:00+03:00',
        'location' => 'Зал №2',
        'status' => 'planned',
        'color' => 'blue',
        'notes' => null,
    ], $overrides);
}

function createSubjectScheduledTraining(User $user, string $subjectType = 'trainee'): ScheduledTraining
{
    $subject = $subjectType === 'trainee'
        ? $user->trainees()->create(scheduledTraineePayload())
        : $user->trainingGroups()->create(scheduledTrainingGroupPayload());
    $subjectColumn = $subjectType === 'trainee' ? 'trainee_id' : 'training_group_id';

    return $user->scheduledTrainings()->create(scheduledTrainingPayload([
        $subjectColumn => $subject->id,
    ]));
}

function createScheduledSubjectPlan(
    ScheduledTraining $scheduledTraining,
    string $status = 'draft',
    string $source = 'manual',
): TrainingPlan {
    $plan = TrainingPlan::factory()->create([
        'user_id' => $scheduledTraining->user_id,
        'scheduled_training_id' => $scheduledTraining->id,
        'trainee_id' => $scheduledTraining->trainee_id,
        'training_group_id' => $scheduledTraining->training_group_id,
        'status' => $status,
        'source' => $source,
    ]);
    $block = TrainingPlanBlock::factory()->for($plan)->create();
    TrainingPlanExercise::factory()->for($block)->create();

    return $plan;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function scheduledTraineePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Алексей Смирнов',
        'age' => 28,
        'level' => 'Начинающий',
        'goal' => 'Улучшить общую физическую форму',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function scheduledTrainingGroupPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Группа U12',
        'sport_type' => 'Функциональный тренинг',
        'age_range' => '10–12 лет',
        'level' => 'Средний',
        'goal' => 'Развить выносливость',
    ], $overrides);
}
