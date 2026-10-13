<?php

use App\Actions\TrainingPlans\ReserveTrainingPlanGeneration;
use App\Http\Requests\ReserveTrainingPlanGenerationRequest;
use App\Jobs\GenerateTrainingPlanJob;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanGeneration;
use App\Models\User;
use App\Services\AI\AiClientInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/generation-reservation-fixtures.php';

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Generation reservation requires PostgreSQL.');
    }
    $this->mock(AiClientInterface::class)->shouldNotReceive('chat');
});

test('POST reserves a generation and a minimal database job without calling LLM', function (string $subject) {
    $user = User::factory()->create();
    $training = reservationTraining($user, $subject);
    $key = (string) Str::uuid();

    $this->actingAs($user)->postJson(route('scheduled-trainings.training-plan-generations.store', $training), [
        'request_key' => $key, 'status' => 'succeeded', 'notes' => 'Must not be stored',
    ])->assertAccepted()->assertJsonPath('generation.status', 'queued')
        ->assertJsonPath('generation.request_key', $key);

    $generation = TrainingPlanGeneration::query()->sole();
    $job = DB::table('jobs')->sole();
    $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);
    $command = unserialize($payload['data']['command'], ['allowed_classes' => [GenerateTrainingPlanJob::class]]);

    expect($generation->user->is($user))->toBeTrue()
        ->and($generation->scheduledTraining->is($training))->toBeTrue()
        ->and($command)->toBeInstanceOf(GenerateTrainingPlanJob::class)
        ->and($command->generationId)->toBe($generation->id)
        ->and($command->afterCommit)->toBeFalse()
        ->and($job->queue)->toBe('training-plan-generations')
        ->and($job->payload)->not->toContain('Must not be stored')
        ->and(TrainingPlan::query()->count())->toBe(0);
})->with(['trainee', 'group']);

test('the same request key returns the same operation without writes even after eligibility changes', function () {
    $user = User::factory()->create();
    $training = reservationTraining($user);
    $key = (string) Str::uuid();
    $url = route('scheduled-trainings.training-plan-generations.store', $training);
    $this->actingAs($user)->postJson($url, ['request_key' => $key])->assertAccepted();
    $generation = TrainingPlanGeneration::query()->sole();
    $before = $generation->getAttributes();
    $training->update(['status' => 'cancelled']);

    $this->postJson($url, ['request_key' => strtoupper($key)])->assertAccepted()
        ->assertJsonPath('generation.id', $generation->id);

    expect($generation->fresh()->getAttributes())->toBe($before)
        ->and(DB::table('jobs')->count())->toBe(1);
});

test('a different key cannot allocate another active generation', function (string $status) {
    $user = User::factory()->create();
    $training = reservationTraining($user);
    $existing = reservationRecord($user, $training, $status);
    $before = $existing->fresh()->getAttributes();

    $this->actingAs($user)->postJson(route('scheduled-trainings.training-plan-generations.store', $training), [
        'request_key' => (string) Str::uuid(),
    ])->assertUnprocessable()->assertJsonValidationErrors('scheduled_training_id');

    expect($existing->fresh()->getAttributes())->toBe($before)
        ->and(TrainingPlanGeneration::query()->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with(TrainingPlanGeneration::ACTIVE_STATUSES);

test('terminal operations allow a new key but keep their own key idempotent', function (string $status) {
    $user = User::factory()->create();
    $training = reservationTraining($user);
    $existing = reservationRecord($user, $training, $status);
    $url = route('scheduled-trainings.training-plan-generations.store', $training);

    $this->actingAs($user)->postJson($url, ['request_key' => $existing->request_key])->assertAccepted()
        ->assertJsonPath('generation.id', $existing->id);
    $this->postJson($url, ['request_key' => (string) Str::uuid()])->assertAccepted();

    expect(TrainingPlanGeneration::query()->count())->toBe(2)
        ->and(DB::table('jobs')->count())->toBe(1);
})->with(['succeeded', 'stale', 'cancelled', 'failed']);

test('a request key cannot be reused for another training of the same user', function () {
    $user = User::factory()->create();
    $first = reservationTraining($user);
    $second = reservationTraining($user);
    $generation = reservationRecord($user, $first);

    $this->actingAs($user)->postJson(route('scheduled-trainings.training-plan-generations.store', $second), [
        'request_key' => $generation->request_key,
    ])->assertUnprocessable()->assertJsonValidationErrors('request_key');

    expect(TrainingPlanGeneration::query()->count())->toBe(1)->and(DB::table('jobs')->count())->toBe(0);
});

test('users can independently use the same request key', function () {
    $key = (string) Str::uuid();
    foreach ([User::factory()->create(), User::factory()->create()] as $user) {
        $training = reservationTraining($user);
        $this->actingAs($user)->postJson(route('scheduled-trainings.training-plan-generations.store', $training), [
            'request_key' => $key,
        ])->assertAccepted();
    }
    expect(TrainingPlanGeneration::query()->count())->toBe(2)->and(DB::table('jobs')->count())->toBe(2);
});

test('new reservations reject cancelled completed archived and planned-with-plan trainings', function (string $case) {
    $user = User::factory()->create();
    $training = reservationTraining($user, $case === 'archived group' ? 'group' : 'trainee');
    if (in_array($case, ['cancelled', 'completed'], true)) {
        $training->update(['status' => $case]);
    } elseif (str_starts_with($case, 'archived')) {
        ($training->trainee ?? $training->trainingGroup)->archive();
    } else {
        TrainingPlan::factory()->create([
            'user_id' => $user->id, 'scheduled_training_id' => $training->id, 'trainee_id' => $training->trainee_id,
        ]);
    }

    $before = $training->fresh()->getAttributes();
    $this->actingAs($user)->postJson(route('scheduled-trainings.training-plan-generations.store', $training), [
        'request_key' => (string) Str::uuid(),
    ])->assertUnprocessable()->assertJsonValidationErrors('scheduled_training_id');

    expect($training->fresh()->getAttributes())->toBe($before)
        ->and(TrainingPlanGeneration::query()->count())->toBe(0)->and(DB::table('jobs')->count())->toBe(0);
})->with(['cancelled', 'completed', 'archived trainee', 'archived group', 'existing plan']);

test('guests and foreign owners cannot reserve a generation', function () {
    $owner = User::factory()->create();
    $training = reservationTraining($owner);
    $url = route('scheduled-trainings.training-plan-generations.store', $training);
    $payload = ['request_key' => (string) Str::uuid()];

    $this->postJson($url, $payload)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson($url, $payload)->assertNotFound();
    expect(TrainingPlanGeneration::query()->count())->toBe(0)->and(DB::table('jobs')->count())->toBe(0);
});

test('reservation rechecks eligibility after FormRequest validation', function (string $change) {
    $user = User::factory()->create();
    $training = reservationTraining($user);
    app()->afterResolving(ReserveTrainingPlanGenerationRequest::class, function () use ($change, $user, $training): void {
        if ($change === 'archive') {
            $training->trainee->archive();
        } elseif ($change === 'plan') {
            TrainingPlan::factory()->create([
                'user_id' => $user->id, 'scheduled_training_id' => $training->id, 'trainee_id' => $training->trainee_id,
            ]);
        } else {
            $training->fresh()->update(['status' => $change]);
        }
    });

    $this->actingAs($user)->postJson(route('scheduled-trainings.training-plan-generations.store', $training), [
        'request_key' => (string) Str::uuid(),
    ])->assertUnprocessable()->assertJsonValidationErrors('scheduled_training_id');

    expect(TrainingPlanGeneration::query()->count())->toBe(0)->and(DB::table('jobs')->count())->toBe(0);
})->with(['cancelled', 'completed', 'archive', 'plan']);

test('legacy queued reservations without a snapshot are rejected without calling LLM', function () {
    $user = User::factory()->create();
    $generation = reservationRecord($user, reservationTraining($user));
    $before = $generation->fresh()->getAttributes();

    app()->call([new GenerateTrainingPlanJob($generation->id), 'handle']);
    expect($generation->fresh()->status)->toBe('stale');
});

test('request keys are required UUIDs', function (mixed $key) {
    $user = User::factory()->create();
    $training = reservationTraining($user);
    $this->actingAs($user)->postJson(route('scheduled-trainings.training-plan-generations.store', $training), [
        'request_key' => $key,
    ])->assertUnprocessable()->assertJsonValidationErrors('request_key');
    expect(TrainingPlanGeneration::query()->count())->toBe(0)->and(DB::table('jobs')->count())->toBe(0);
})->with([null, '', 'not-a-uuid', 123]);

test('PostgreSQL enforces active uniqueness and generation state constraints', function (string $case) {
    $user = User::factory()->create();
    $training = reservationTraining($user);
    $generation = reservationRecord($user, $training);

    expect(fn () => DB::transaction(function () use ($case, $generation, $user, $training): void {
        if ($case === 'active uniqueness') {
            reservationRecord($user, $training, 'uncertain');
        } elseif ($case === 'request uniqueness') {
            $other = reservationTraining($user);
            DB::table('training_plan_generations')->insert([
                'id' => (string) Str::uuid(), 'user_id' => $user->id, 'scheduled_training_id' => $other->id,
                'request_key' => $generation->request_key, 'status' => 'queued',
            ]);
        } else {
            DB::table('training_plan_generations')->where('id', $generation->id)->update(match ($case) {
                'invalid status' => ['status' => 'retry_wait'],
                'negative attempts' => ['attempt_count' => -1],
                'missing running lease' => ['status' => 'running'],
            });
        }
    }))->toThrow(QueryException::class);
})->with(['active uniqueness', 'request uniqueness', 'invalid status', 'negative attempts', 'missing running lease']);

test('deleting a training retains its generation without SET NULL CHECK conflicts', function () {
    $user = User::factory()->create();
    $training = reservationTraining($user);
    $generation = reservationRecord($user, $training, 'running');
    $training->delete();
    expect($generation->fresh()->scheduled_training_id)->toBeNull()
        ->and($generation->fresh()->status)->toBe('running');
});

test('reservation rejects a separate PostgreSQL queue connection before writing', function () {
    config(['database.connections.generation_other' => config('database.connections.pgsql')]);
    config(['queue.connections.training-plan-generations.connection' => 'generation_other']);
    $user = User::factory()->create();
    $training = reservationTraining($user);

    expect(fn () => app(ReserveTrainingPlanGeneration::class)->execute($user, $training, (string) Str::uuid()))
        ->toThrow(LogicException::class);
    expect(TrainingPlanGeneration::query()->count())->toBe(0)->and(DB::table('jobs')->count())->toBe(0);
});
