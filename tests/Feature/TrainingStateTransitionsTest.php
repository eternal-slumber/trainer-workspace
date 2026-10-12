<?php

use App\Actions\TrainingPlans\CompleteTrainingPlanAction;
use App\Http\Requests\StoreTrainingPlanRequest;
use App\Http\Requests\UpdateScheduledTrainingRequest;
use App\Http\Requests\UpdateTrainingPlanRequest;
use App\Models\ScheduledTraining;
use App\Models\TrainingNote;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanBlock;
use App\Models\TrainingPlanExercise;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Training state regressions require PostgreSQL.');
    }
});

test('a completed plan prevents reopening or cancelling its scheduled training', function (string $status) {
    [$user, $training, $plan] = stateTransitionFixture();
    $this->actingAs($user)->post(route('training-plans.complete', $plan))->assertRedirect();
    $before = stateTransitionSnapshot($plan);

    $this->patchJson(route('scheduled-trainings.update', $training), [
        'status' => $status,
        'location' => 'Изменение не должно сохраниться',
    ])->assertUnprocessable()->assertJsonValidationErrors('status');

    expect(stateTransitionSnapshot($plan))->toBe($before);
})->with(['planned', 'cancelled']);

test('scheduled training completion cannot bypass its unfinished plan', function (string $status) {
    [$user, $training, $plan] = stateTransitionFixture($status);
    $before = stateTransitionSnapshot($plan);

    $this->actingAs($user)->patchJson(route('scheduled-trainings.update', $training), [
        'status' => 'completed',
    ])->assertUnprocessable()->assertJsonValidationErrors('status');

    expect(stateTransitionSnapshot($plan))->toBe($before);
})->with(['draft', 'approved']);

test('a cancelled scheduled training cannot be completed through its plan', function (string $status) {
    [$user, $training, $plan] = stateTransitionFixture($status);
    $this->actingAs($user)->patchJson(route('scheduled-trainings.update', $training), [
        'status' => 'cancelled',
    ])->assertRedirect();
    $before = stateTransitionSnapshot($plan);

    $this->postJson(route('training-plans.complete', $plan))
        ->assertUnprocessable()->assertJsonValidationErrors('status');

    expect(stateTransitionSnapshot($plan))->toBe($before);
})->with(['draft', 'approved']);

test('technical plan states cannot be marked as completed', function (string $status) {
    [$user, $training, $plan] = stateTransitionFixture($status);
    $before = stateTransitionSnapshot($plan);

    $this->actingAs($user)->postJson(route('training-plans.complete', $plan))
        ->assertUnprocessable()->assertJsonValidationErrors('status');

    expect(stateTransitionSnapshot($plan))->toBe($before);
})->with(['generating', 'failed']);

test('a completed plan cannot have its contents replaced even with completed status', function () {
    [$user, $training, $plan] = stateTransitionFixture();
    $this->actingAs($user)->post(route('training-plans.complete', $plan))->assertRedirect();
    stateTransitionNote($plan);
    $before = stateTransitionSnapshot($plan);

    $this->patchJson(route('training-plans.update', $plan), stateTransitionPayload('completed'))
        ->assertUnprocessable()->assertJsonValidationErrors('status');

    expect(stateTransitionSnapshot($plan))->toBe($before);
});

test('an old form submitted after completion is already rejected on a fresh request', function () {
    [$user, $training, $plan] = stateTransitionFixture();
    $this->actingAs($user)->get(route('training-plans.edit', $plan))->assertOk();
    $payload = stateTransitionPayload('draft');
    $this->post(route('training-plans.complete', $plan))->assertRedirect();
    $before = stateTransitionSnapshot($plan);

    $this->patchJson(route('training-plans.update', $plan), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('status');

    expect(stateTransitionSnapshot($plan))->toBe($before);
});

test('plan edits validated before an intervening completion preserve the completed snapshot', function (
    string $initialStatus,
    string $submittedStatus,
) {
    [$user, $training, $plan] = stateTransitionFixture($initialStatus);
    $completed = null;

    // Finish a second HTTP request after PATCH validation, before SaveTrainingPlan::update.
    app()->afterResolving(UpdateTrainingPlanRequest::class, function () use ($plan, &$completed): void {
        $this->postJson(route('training-plans.complete', $plan))->assertRedirect();
        stateTransitionNote($plan);
        $completed = stateTransitionSnapshot($plan);
    });

    $response = $this->actingAs($user)->patchJson(
        route('training-plans.update', $plan),
        stateTransitionPayload($submittedStatus),
    );

    expect($completed)->not->toBeNull()
        ->and(stateTransitionSnapshot($plan))->toBe($completed);
    $response->assertUnprocessable()->assertJsonValidationErrors('status');
})->with([
    'unchanged draft status still replaces completed contents' => ['draft', 'draft'],
    'approved to draft reopens the completed plan' => ['approved', 'draft'],
    'draft to approved reopens the completed plan' => ['draft', 'approved'],
]);

test('a cancellation validated before completion cannot overwrite the completed schedule', function () {
    [$user, $training, $plan] = stateTransitionFixture();
    $completed = null;

    app()->afterResolving(UpdateScheduledTrainingRequest::class, function () use ($plan, &$completed): void {
        $this->postJson(route('training-plans.complete', $plan))->assertRedirect();
        $completed = stateTransitionSnapshot($plan);
    });

    $response = $this->actingAs($user)->patchJson(route('scheduled-trainings.update', $training), [
        'status' => 'cancelled',
    ]);

    expect($completed)->not->toBeNull()
        ->and(stateTransitionSnapshot($plan))->toBe($completed);
    $response->assertUnprocessable()->assertJsonValidationErrors('status');
});

test('completion rechecks a cancellation made after its route model was loaded', function () {
    [$user, $training, $plan] = stateTransitionFixture();
    $cancelled = null;
    $intervened = false;

    app()->afterResolving(CompleteTrainingPlanAction::class, function () use (
        $training,
        $plan,
        &$cancelled,
        &$intervened,
    ): void {
        if ($intervened) {
            return;
        }

        $intervened = true;
        $this->patchJson(route('scheduled-trainings.update', $training), [
            'status' => 'cancelled',
        ])->assertRedirect();
        $cancelled = stateTransitionSnapshot($plan);
    });

    $response = $this->actingAs($user)->postJson(route('training-plans.complete', $plan));

    expect($intervened)->toBeTrue()
        ->and(stateTransitionSnapshot($plan))->toBe($cancelled);
    $response->assertUnprocessable()->assertJsonValidationErrors('status');
});

test('repeated completion is a successful no-op including timestamps and history', function () {
    [$user, $training, $plan] = stateTransitionFixture();
    $this->freezeTime();
    $this->actingAs($user)->post(route('training-plans.complete', $plan))->assertRedirect();
    stateTransitionNote($plan);
    $before = stateTransitionSnapshot($plan);
    $this->travel(5)->minutes();
    $updates = 0;
    TrainingPlan::updated(function () use (&$updates): void {
        $updates++;
    });
    ScheduledTraining::updated(function () use (&$updates): void {
        $updates++;
    });

    $this->post(route('training-plans.complete', $plan))->assertRedirect();

    expect(stateTransitionSnapshot($plan))->toBe($before)
        ->and($updates)->toBe(0);
});

test('two overlapping completion requests leave one consistent completed pair', function () {
    [$user, $training, $plan] = stateTransitionFixture();
    $intervened = false;

    app()->afterResolving(CompleteTrainingPlanAction::class, function () use ($plan, &$intervened): void {
        if ($intervened) {
            return;
        }

        $intervened = true;
        $this->postJson(route('training-plans.complete', $plan))->assertRedirect();
    });

    $blockIds = $plan->blocks()->pluck('id')->all();
    $this->actingAs($user)->postJson(route('training-plans.complete', $plan))->assertRedirect();

    expect($intervened)->toBeTrue()
        ->and($plan->fresh()->status)->toBe('completed')
        ->and($training->fresh()->status)->toBe('completed')
        ->and($plan->blocks()->pluck('id')->all())->toBe($blockIds);
});

test('normal completion still supports draft and approved plans', function (string $status) {
    [$user, $training, $plan] = stateTransitionFixture($status);

    $this->actingAs($user)->post(route('training-plans.complete', $plan))->assertRedirect();

    expect($plan->fresh()->status)->toBe('completed')
        ->and($training->fresh()->status)->toBe('completed');
})->with(['draft', 'approved']);

test('non-status schedule edits remain available with a completed plan', function () {
    [$user, $training, $plan] = stateTransitionFixture();
    $this->actingAs($user)->post(route('training-plans.complete', $plan))->assertRedirect();
    $planBefore = $plan->fresh()->getAttributes();

    $this->patchJson(route('scheduled-trainings.update', $training), [
        'location' => 'Другой зал',
        'color' => 'green',
    ])->assertRedirect();

    expect($training->fresh()->only(['status', 'location', 'color']))->toBe([
        'status' => 'completed', 'location' => 'Другой зал', 'color' => 'green',
    ])->and($plan->fresh()->getAttributes())->toBe($planBefore);
});

test('a new draft plan cannot be attached to an already completed schedule', function () {
    [$user, $training, $plan] = stateTransitionFixture();
    $plan->delete();
    $this->actingAs($user)->patchJson(route('scheduled-trainings.update', $training), [
        'status' => 'completed',
    ])->assertRedirect();
    $before = $training->fresh()->getAttributes();

    $this->get(route('training-plans.create'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('scheduledTrainings', 0));

    $this->postJson(route('training-plans.store'), [
        ...stateTransitionPayload('draft'),
        'scheduled_training_id' => $training->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('scheduled_training_id');

    expect($training->fresh()->getAttributes())->toBe($before)
        ->and($training->trainingPlan()->exists())->toBeFalse();
});

test('inconsistent legacy pairs are rejected without silently repairing history', function (
    string $trainingStatus,
    string $planStatus,
) {
    [$user, $training, $plan] = stateTransitionFixture($planStatus);
    $training->update(['status' => $trainingStatus]);
    stateTransitionNote($plan);
    $before = stateTransitionSnapshot($plan);

    $this->actingAs($user)->postJson(route('training-plans.complete', $plan))
        ->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->patchJson(route('scheduled-trainings.update', $training), [
        'status' => $planStatus === 'completed' ? 'completed' : 'planned',
    ])->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->patchJson(route('training-plans.update', $plan), stateTransitionPayload('draft'))
        ->assertUnprocessable()->assertJsonValidationErrors('status');

    expect(stateTransitionSnapshot($plan))->toBe($before);
})->with([
    ['planned', 'completed'], ['cancelled', 'completed'],
    ['completed', 'draft'], ['completed', 'approved'],
    ['completed', 'generating'], ['completed', 'failed'],
]);

test('administratively completed trainings without plans cannot be reopened by PATCH', function (string $status) {
    [$user, $training, $plan] = stateTransitionFixture();
    $plan->delete();
    $this->actingAs($user)->patchJson(route('scheduled-trainings.update', $training), [
        'status' => 'completed',
    ])->assertRedirect();
    $before = $training->fresh()->getAttributes();

    $this->patchJson(route('scheduled-trainings.update', $training), [
        'status' => $status, 'notes' => 'Не должно сохраниться',
    ])->assertUnprocessable()->assertJsonValidationErrors('status');

    expect($training->fresh()->getAttributes())->toBe($before)
        ->and(TrainingPlan::query()->count())->toBe(0);
})->with(['planned', 'cancelled']);

test('partial schedule edits preserve a completion made after their validation', function () {
    [$user, $training, $plan] = stateTransitionFixture();
    $this->freezeTime();
    $completed = null;
    app()->afterResolving(UpdateScheduledTrainingRequest::class, function () use ($plan, &$completed): void {
        $this->postJson(route('training-plans.complete', $plan))->assertRedirect();
        $completed = stateTransitionSnapshot($plan);
    });

    $this->actingAs($user)->patchJson(route('scheduled-trainings.update', $training), [
        'location' => 'Другой зал',
    ])->assertRedirect();

    expect($completed)->not->toBeNull();
    $completed['training']['location'] = 'Другой зал';
    expect(stateTransitionSnapshot($plan))->toBe($completed);
});

test('a completed plan keeps its training note independently editable', function () {
    [$user, $training, $plan] = stateTransitionFixture();
    $this->actingAs($user)->post(route('training-plans.complete', $plan))->assertRedirect();
    stateTransitionNote($plan);
    $before = stateTransitionSnapshot($plan);
    $note = $plan->trainingNote()->sole();

    $this->patchJson(route('training-notes.update', $note), [
        'intensity' => 'high', 'result' => 'good', 'tags' => ['прогресс'], 'note' => 'Уточнённый результат',
    ])->assertRedirect();

    $after = stateTransitionSnapshot($plan);
    expect($note->fresh()->note)->toBe('Уточнённый результат');
    unset($before['note'], $after['note']);
    expect($after)->toBe($before);
});

test('draft and approved plans remain editable for cancelled trainings', function (string $status) {
    [$user, $training, $plan] = stateTransitionFixture($status);
    $this->actingAs($user)->patchJson(route('scheduled-trainings.update', $training), [
        'status' => 'cancelled',
    ])->assertRedirect();
    $trainingBefore = $training->fresh()->getAttributes();

    $this->patchJson(route('training-plans.update', $plan), stateTransitionPayload($status))
        ->assertRedirect();

    expect($plan->fresh()->status)->toBe($status)
        ->and($plan->fresh()->title)->toBe('Устаревшая редакция')
        ->and($training->fresh()->getAttributes())->toBe($trainingBefore);
})->with(['draft', 'approved']);

test('plan updates apply an explicitly submitted status to the freshly locked plan', function () {
    [$user, $training, $plan] = stateTransitionFixture();
    $intervened = false;
    app()->afterResolving(UpdateTrainingPlanRequest::class, function () use ($plan, &$intervened): void {
        if ($intervened) {
            return;
        }

        $intervened = true;
        $this->patchJson(route('training-plans.update', $plan), stateTransitionPayload('approved'))
            ->assertRedirect();
    });

    $this->actingAs($user)->patchJson(route('training-plans.update', $plan), stateTransitionPayload('draft'))
        ->assertRedirect();

    expect($intervened)->toBeTrue()
        ->and($plan->fresh()->status)->toBe('draft')
        ->and($training->fresh()->status)->toBe('planned');
});

test('manual creation rechecks administrative completion after request validation', function () {
    [$user, $training, $plan] = stateTransitionFixture();
    $plan->delete();
    $completed = null;
    app()->afterResolving(StoreTrainingPlanRequest::class, function () use ($training, &$completed): void {
        $this->patchJson(route('scheduled-trainings.update', $training), ['status' => 'completed'])
            ->assertRedirect();
        $completed = $training->fresh()->getAttributes();
    });

    $this->actingAs($user)->postJson(route('training-plans.store'), [
        ...stateTransitionPayload('draft'), 'scheduled_training_id' => $training->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('scheduled_training_id');

    expect($completed)->not->toBeNull()
        ->and($training->fresh()->getAttributes())->toBe($completed)
        ->and(TrainingPlan::query()->count())->toBe(0);
});

test('a manual plan created after validation produces a controlled conflict instead of a database error', function () {
    [$user, $training, $plan] = stateTransitionFixture();
    $plan->delete();
    $intervened = false;
    $created = null;
    $payload = [...stateTransitionPayload('draft'), 'scheduled_training_id' => $training->id];
    app()->afterResolving(StoreTrainingPlanRequest::class, function () use ($payload, &$intervened, &$created): void {
        if ($intervened) {
            return;
        }

        $intervened = true;
        $this->postJson(route('training-plans.store'), $payload)->assertRedirect();
        $created = stateTransitionSnapshot(TrainingPlan::query()->sole());
    });

    $this->actingAs($user)->postJson(route('training-plans.store'), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('scheduled_training_id');

    expect($intervened)->toBeTrue()
        ->and(TrainingPlan::query()->count())->toBe(1)
        ->and(stateTransitionSnapshot(TrainingPlan::query()->sole()))->toBe($created);
});

test('completed plans open their read-only page instead of the edit form', function () {
    [$user, $training, $plan] = stateTransitionFixture();
    $this->actingAs($user)->post(route('training-plans.complete', $plan))->assertRedirect();

    $this->get(route('training-plans.edit', $plan))->assertRedirect(route('training-plans.show', $plan));
    $this->get(route('training-plans.show', $plan))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('trainingPlan.status', 'completed')
        ->where('trainingPlan.scheduled_training.status', 'completed'));
});

/** @return array{User, ScheduledTraining, TrainingPlan} */
function stateTransitionFixture(string $status = 'draft'): array
{
    $user = User::factory()->create();
    $trainee = $user->trainees()->create([
        'name' => 'Клиент для проверки состояний', 'level' => 'Средний', 'goal' => 'Координация',
    ]);
    $training = $user->scheduledTrainings()->create([
        'trainee_id' => $trainee->id,
        'starts_at' => '2026-07-08T15:00:00Z',
        'ends_at' => '2026-07-08T16:00:00Z',
        'location' => 'Зал №1',
        'status' => 'planned',
    ]);
    $plan = TrainingPlan::factory()->create([
        'user_id' => $user->id, 'scheduled_training_id' => $training->id,
        'trainee_id' => $trainee->id, 'status' => $status,
    ]);
    $block = TrainingPlanBlock::factory()->for($plan)->create();
    TrainingPlanExercise::factory()->for($block)->create();

    return [$user, $training, $plan];
}

/** @return array<string, mixed> */
function stateTransitionPayload(string $status): array
{
    return [
        'title' => 'Устаревшая редакция',
        'goal' => 'Другая цель',
        'total_duration_minutes' => 60,
        'status' => $status,
        'notes' => 'Устаревшие заметки',
        'blocks' => [[
            'name' => 'Замена завершённого блока', 'duration_minutes' => 60,
            'exercises' => [['name' => 'Замена завершённого упражнения', 'sets' => 3]],
        ]],
    ];
}

function stateTransitionNote(TrainingPlan $plan): void
{
    TrainingNote::factory()->create([
        'user_id' => $plan->user_id, 'training_plan_id' => $plan->id,
        'trainee_id' => $plan->trainee_id, 'training_group_id' => $plan->training_group_id,
    ]);
}

/** @return array<string, mixed> */
function stateTransitionSnapshot(TrainingPlan $plan): array
{
    $fresh = $plan->fresh();

    return [
        'plan' => $fresh->getAttributes(),
        'training' => $fresh->scheduledTraining->getAttributes(),
        'blocks' => $fresh->blocks->map(fn (TrainingPlanBlock $block): array => [
            'attributes' => $block->getAttributes(),
            'exercises' => $block->exercises
                ->map(fn (TrainingPlanExercise $exercise): array => $exercise->getAttributes())->all(),
        ])->all(),
        'note' => $fresh->trainingNote?->getAttributes(),
    ];
}
