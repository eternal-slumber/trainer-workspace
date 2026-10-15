<?php

use App\Actions\TrainingSessions\StartTrainingSessionAction;
use App\Models\TrainingSession;
use App\Models\TrainingSessionExercise;
use App\Models\User;
use App\Services\AI\AiClientInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/training-session-fixtures.php';

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Starting training sessions requires PostgreSQL.');
    }
    Http::preventStrayRequests();
    $this->mock(AiClientInterface::class)->shouldNotReceive('chat');
});

test('start snapshots draft and approved plans without changing their source', function (string $status, string $type) {
    [$user, $training, $plan] = sessionStartFixture($status, $type);
    $planBefore = $plan->fresh(['blocks.exercises'])->toArray();
    $trainingBefore = $training->fresh()->getAttributes();
    $this->freezeTime();

    $session = app(StartTrainingSessionAction::class)->execute($user, $training)->fresh();

    expect($session->user_id)->toBe($user->id)
        ->and($session->scheduled_training_id)->toBe($training->id)
        ->and($session->training_plan_id)->toBe($plan->id)
        ->and($session->trainee_id)->toBe($training->trainee_id)
        ->and($session->training_group_id)->toBe($training->training_group_id)
        ->and($session->status)->toBe('in_progress')
        ->and($session->started_at->equalTo(now()->startOfSecond()))->toBeTrue()
        ->and($session->completed_at)->toBeNull()
        ->and($session->snapshot['subject'])->toEqual([
            'type' => $type === 'trainee' ? 'trainee' : 'training_group',
            'id' => ($training->trainee ?? $training->trainingGroup)->id,
            'name' => ($training->trainee ?? $training->trainingGroup)->name,
        ])
        ->and($session->snapshot['scheduled_training'])->toEqual([
            'starts_at' => $training->starts_at->toIso8601String(),
            'ends_at' => $training->ends_at->toIso8601String(),
            'location' => $training->location, 'notes' => $training->notes,
        ])
        ->and($session->snapshot['plan'])->toEqual([
            ...$plan->only(['title', 'goal', 'total_duration_minutes', 'status', 'source', 'notes']),
            'blocks' => $plan->blocks->map(fn ($block) => $block->only(['name', 'duration_minutes', 'position', 'notes']))->all(),
        ])
        ->and(array_column($session->snapshot['plan']['blocks'], 'position'))->toBe([1, 2, 3])
        ->and(array_column($session->snapshot['plan']['blocks'], 'name'))->toBe(['Разминка', 'Основная часть', 'Заминка'])
        ->and($session->exercises->map(fn ($exercise) => [$exercise->block_position, $exercise->position])->all())
        ->toBe([[1, 1], [2, 1], [2, 2]])
        ->and($plan->fresh(['blocks.exercises'])->toArray())->toBe($planBefore)
        ->and($training->fresh()->getAttributes())->toBe($trainingBefore);

    $sourceExercises = $plan->blocks->flatMap->exercises->values();
    foreach ($session->exercises as $index => $exercise) {
        expect($exercise->snapshot)->toEqual($sourceExercises[$index]->only([
            'exercise_id', 'name', 'description', 'duration_minutes', 'sets', 'repetitions', 'rest_seconds', 'notes',
        ]))->and($exercise->status)->toBe('pending')
            ->and($exercise->actual_weight)->toBeNull()
            ->and($exercise->actual_repetitions)->toBeNull()
            ->and($exercise->comment)->toBeNull();
    }
})->with(['draft', 'approved'])->with(['trainee', 'group']);

test('start supports previously scheduled archived recipients with or without a plan', function (?string $status, string $type) {
    [$user, $training] = sessionStartFixture($status, $type);
    ($training->trainee ?? $training->trainingGroup)->archive();

    $session = app(StartTrainingSessionAction::class)->execute($user, $training);

    expect($session->exists)->toBeTrue()
        ->and($session->snapshot['subject']['name'])->toBe(($training->trainee ?? $training->trainingGroup)->name);
})->with(['draft', null])->with(['trainee', 'group']);

test('start without a plan creates a session without exercise results', function (string $type) {
    [$user, $training] = sessionStartFixture(null, $type);
    $session = app(StartTrainingSessionAction::class)->execute($user, $training);

    expect($session->training_plan_id)->toBeNull()
        ->and($session->snapshot['plan'])->toBeNull()
        ->and($session->exercises()->count())->toBe(0)
        ->and($training->fresh()->status)->toBe('planned');
})->with(['trainee', 'group']);

test('repeat start preserves all persisted data even after source states change', function (string $status) {
    [$user, $training, $plan] = sessionStartFixture();
    $this->freezeTime();
    $action = app(StartTrainingSessionAction::class);
    $session = $action->execute($user, $training);
    $session->update([
        'status' => $status, 'completed_at' => $status === 'completed' ? now() : null,
        'comment' => 'Исходный комментарий',
    ]);
    $session->exercises()->firstOrFail()->update([
        'status' => 'completed', 'actual_weight' => '25.50', 'actual_repetitions' => '10/10/8', 'comment' => 'Факт',
    ]);
    $before = $session->fresh(['exercises'])->toArray();
    $training->update(['status' => 'cancelled', 'location' => 'Другое место']);
    $plan->update(['status' => 'failed', 'title' => 'Другая редакция']);
    reservationRecord($user, $training);
    $this->travel(5)->minutes();

    $repeated = $action->execute($user, $training);

    expect($repeated->id)->toBe($session->id)
        ->and($repeated->fresh(['exercises'])->toArray())->toBe($before)
        ->and(TrainingSession::query()->count())->toBe(1);
})->with(TrainingSession::STATUSES);

test('repeat start retains its snapshot after deleting the original plan', function () {
    [$user, $training, $plan] = sessionStartFixture();
    $session = app(StartTrainingSessionAction::class)->execute($user, $training);
    $before = $session->fresh(['exercises'])->toArray();
    $plan->delete();
    $before['training_plan_id'] = null;

    expect(app(StartTrainingSessionAction::class)->execute($user, $training)->load('exercises')->toArray())->toBe($before);
});

test('start reloads the plan and schedule instead of using cached relations', function () {
    [$user, $training, $plan] = sessionStartFixture();
    $training->load('trainingPlan.blocks.exercises');
    DB::table('training_plans')->where('id', $plan->id)->update(['title' => 'Свежий план']);
    DB::table('scheduled_trainings')->where('id', $training->id)->update(['location' => 'Свежая локация']);

    $session = app(StartTrainingSessionAction::class)->execute($user, $training);

    expect($session->snapshot['plan']['title'])->toBe('Свежий план')
        ->and($session->snapshot['scheduled_training']['location'])->toBe('Свежая локация');
});

test('start rejects cancelled and completed schedules using fresh state', function (string $status) {
    [$user, $training] = sessionStartFixture();
    DB::table('scheduled_trainings')->where('id', $training->id)->update(['status' => $status]);

    expect(fn () => app(StartTrainingSessionAction::class)->execute($user, $training))->toThrow(ValidationException::class)
        ->and(TrainingSession::query()->count())->toBe(0);
})->with(['cancelled', 'completed']);

test('start rejects completed generating and failed plans', function (string $status) {
    [$user, $training] = sessionStartFixture($status);

    expect(fn () => app(StartTrainingSessionAction::class)->execute($user, $training))->toThrow(ValidationException::class)
        ->and(TrainingSession::query()->count())->toBe(0);
})->with(['completed', 'generating', 'failed']);

test('start rejects plan ownership or recipient mismatches', function (string $mismatch) {
    [$user, $training, $plan] = sessionStartFixture();
    $other = reservationTraining(User::factory()->create(), $mismatch === 'group' ? 'group' : 'trainee');
    $plan->forceFill(match ($mismatch) {
        'owner' => ['user_id' => $other->user_id],
        'trainee' => ['trainee_id' => $other->trainee_id],
        'group' => ['trainee_id' => null, 'training_group_id' => $other->training_group_id],
    })->save();

    expect(fn () => app(StartTrainingSessionAction::class)->execute($user, $training))->toThrow(ValidationException::class)
        ->and(TrainingSession::query()->count())->toBe(0);
})->with(['owner', 'trainee', 'group']);

test('start refuses another trainers schedule', function () {
    [$owner, $training] = sessionStartFixture();

    expect(fn () => app(StartTrainingSessionAction::class)->execute(User::factory()->create(), $training))
        ->toThrow(AuthorizationException::class)
        ->and(TrainingSession::query()->count())->toBe(0);
});

test('start rechecks ownership after loading a stale schedule', function () {
    [$user, $training] = sessionStartFixture();
    DB::table('scheduled_trainings')->where('id', $training->id)->update(['user_id' => User::factory()->create()->id]);

    expect(fn () => app(StartTrainingSessionAction::class)->execute($user, $training))->toThrow(ModelNotFoundException::class)
        ->and(TrainingSession::query()->count())->toBe(0);
});

test('start rejects every active generation state including cancellation still in flight', function (string $status, bool $cancelRequested) {
    [$user, $training] = sessionStartFixture(null);
    reservationRecord($user, $training, $status)->forceFill(['cancel_requested_at' => $cancelRequested ? now() : null])->save();

    expect(fn () => app(StartTrainingSessionAction::class)->execute($user, $training))->toThrow(ValidationException::class)
        ->and(TrainingSession::query()->count())->toBe(0);
})->with(['queued', 'running', 'uncertain'])->with([false, true]);

test('start refuses a recipient belonging to another trainer', function () {
    [$user, $training] = sessionStartFixture(null);
    $other = reservationTraining(User::factory()->create());
    $training->update(['trainee_id' => $other->trainee_id]);

    expect(fn () => app(StartTrainingSessionAction::class)->execute($user, $training))->toThrow(ModelNotFoundException::class)
        ->and(TrainingSession::query()->count())->toBe(0);
});

test('repeat start does not expose a session assigned to a different owner', function () {
    [$user, $training] = sessionStartFixture();
    $session = app(StartTrainingSessionAction::class)->execute($user, $training);
    $session->forceFill(['user_id' => User::factory()->create()->id])->save();

    expect(fn () => app(StartTrainingSessionAction::class)->execute($user, $training))->toThrow(ValidationException::class);
});

test('terminal generation records do not prevent start', function (string $status) {
    [$user, $training] = sessionStartFixture(null);
    reservationRecord($user, $training, $status);

    expect(app(StartTrainingSessionAction::class)->execute($user, $training)->exists)->toBeTrue();
})->with(['succeeded', 'failed', 'stale', 'cancelled']);

test('an error after exercise insertion rolls back the whole session and permits a clean retry', function () {
    [$user, $training, $plan] = sessionStartFixture();
    $before = $plan->fresh(['blocks.exercises'])->toArray();
    $insertions = 0;
    TrainingSessionExercise::created(function () use (&$insertions): void {
        if (++$insertions === 2) {
            throw new RuntimeException('Simulated exercise insertion failure.');
        }
    });

    expect(fn () => app(StartTrainingSessionAction::class)->execute($user, $training))
        ->toThrow(RuntimeException::class, 'Simulated exercise insertion failure.')
        ->and(TrainingSession::query()->count())->toBe(0)
        ->and(TrainingSessionExercise::query()->count())->toBe(0)
        ->and($plan->fresh(['blocks.exercises'])->toArray())->toBe($before);

    expect(app(StartTrainingSessionAction::class)->execute($user, $training)->exercises()->count())->toBe(3);
});
