<?php

use App\Jobs\GenerateTrainingPlanJob;
use App\Models\AgentMemory;
use App\Models\Exercise;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanGeneration;
use App\Models\User;
use App\Services\Agent\TrainingAgentService;
use App\Services\AI\AiClientException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../Support/generation-processing-fixtures.php';

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Generation processing requires PostgreSQL.');
    }
});

test('double POST and repeated jobs make one external call and one immutable publication', function () {
    [$user, $training, $exercise, $generation, $client] = processingFixture();
    $url = route('scheduled-trainings.training-plan-generations.store', $training);
    $this->actingAs($user)->postJson($url, ['request_key' => $generation->request_key])->assertAccepted();
    $this->postJson($url, ['request_key' => $generation->request_key])->assertAccepted();
    $level = DB::transactionLevel();
    $client->onCall = function () use ($level, $generation): void {
        expect(DB::transactionLevel())->toBe($level)
            ->and(app(TrainingAgentService::class)->processGeneration($generation->id))->toBeGreaterThan(0);
    };
    app()->call([new GenerateTrainingPlanJob($generation->id), 'handle']);
    $before = $generation->fresh()->getAttributes();
    $plan = TrainingPlan::query()->sole()->load('blocks.exercises');
    app()->call([new GenerateTrainingPlanJob($generation->id), 'handle']);
    expect($client->calls)->toBe(1)->and($generation->fresh()->getAttributes())->toBe($before)
        ->and($generation->fresh()->status)->toBe('succeeded')->and($generation->fresh()->response_payload)->toBeNull()
        ->and($plan->blocks->first()->exercises->first()->name)->toBe($exercise->name)
        ->and(TrainingPlan::query()->count())->toBe(1);
});

test('significant input changes reject a result before or after LLM', function (string $change, bool $during) {
    [$user, $training, $exercise, $generation, $client] = processingFixture();
    $mutate = function () use ($change, $user, $training, $exercise): void {
        match ($change) {
            'client' => $training->fresh()->update(['trainee_id' => reservationTraining($user)->trainee_id]),
            'duration' => $training->fresh()->update(['ends_at' => $training->ends_at->subMinutes(20)]),
            'restriction' => $training->trainee->update(['restrictions' => 'New restriction']),
            'memory' => (new AgentMemory)->forceFill(['user_id' => $user->id, 'trainee_id' => $training->trainee_id,
                'type' => 'restriction', 'content' => 'New memory', 'importance' => 9, 'is_active' => true])->save(),
            'exercise' => $exercise->update(['description' => 'Changed description']),
            'delete exercise' => $exercise->delete(),
            'catalogue membership' => Exercise::factory()->for($user)->create(),
            'history' => TrainingPlan::factory()->create(['user_id' => $user->id,
                'scheduled_training_id' => $user->scheduledTrainings()->create([
                    'trainee_id' => $training->trainee_id, 'starts_at' => now()->subDay(),
                    'ends_at' => now()->subDay()->addHour(), 'location' => 'Test room', 'status' => 'completed',
                ])->id, 'trainee_id' => $training->trainee_id,
                'status' => 'completed']),
            'settings' => config(['ai.model' => 'different-model']),
        };
    };
    if ($during) {
        $client->onCall = $mutate;
    } else {
        $mutate();
    }
    app(TrainingAgentService::class)->processGeneration($generation->id);
    expect($generation->fresh()->status)->toBe('stale')->and($client->calls)->toBe($during ? 1 : 0)
        ->and($training->trainingPlan()->exists())->toBeFalse();
})->with(['client', 'duration', 'restriction', 'memory', 'exercise', 'delete exercise', 'catalogue membership', 'history', 'settings'])
    ->with([false, true]);

test('archive cancellation replan and manual plans cannot be overwritten', function (string $change) {
    [$user, $training, $exercise, $generation, $client] = processingFixture();
    $manual = null;
    $client->onCall = function () use ($change, $user, $training, &$manual): void {
        if ($change === 'archive') {
            $training->trainee->archive();
        } elseif ($change === 'manual') {
            $manual = TrainingPlan::factory()->create(['user_id' => $user->id, 'scheduled_training_id' => $training->id,
                'trainee_id' => $training->trainee_id])->fresh()->getAttributes();
        } else {
            $this->actingAs($user)->patchJson(route('scheduled-trainings.update', $training), ['status' => 'cancelled'])->assertRedirect();
            if ($change === 'replan') {
                $this->patchJson(route('scheduled-trainings.update', $training), ['status' => 'planned'])->assertRedirect();
            }
        }
    };
    app(TrainingAgentService::class)->processGeneration($generation->id);
    expect($generation->fresh()->status)->toBe('cancelled')->and($client->calls)->toBe(1);
    if ($manual !== null) {
        expect(TrainingPlan::query()->sole()->getAttributes())->toBe($manual);
    } else {
        expect(TrainingPlan::query()->count())->toBe(0);
    }
})->with(['archive', 'cancel', 'replan', 'manual']);

test('irrelevant changes and array key order preserve the fingerprint', function () {
    [$user, $training, $exercise, $generation, $client] = processingFixture();
    $client->onCall = function () use ($user, $training): void {
        $user->update(['name' => 'Renamed trainer']);
        $training->fresh()->update(['color' => 'red']);
        config(['ai.openrouter.response_format' => ['type' => 'json_object']]);
    };
    app(TrainingAgentService::class)->processGeneration($generation->id);
    expect($generation->fresh()->status)->toBe('succeeded');
});

test('unknown outcomes keep the slot and never repeat the provider request automatically', function () {
    [$user, $training, $exercise, $generation, $client] = processingFixture();
    $client->exception = new AiClientException('Timeout after sending');
    app(TrainingAgentService::class)->processGeneration($generation->id);
    app(TrainingAgentService::class)->recoverGenerations();
    app(TrainingAgentService::class)->processGeneration($generation->id);
    expect($generation->fresh()->status)->toBe('uncertain')->and($client->calls)->toBe(1);
    $this->actingAs($user)->postJson(route('training-plan-generations.cancel', $generation))->assertUnprocessable();
    $this->postJson(route('training-plan-generations.cancel', $generation), ['acknowledge_unknown' => true])->assertOk();
    $this->postJson(route('scheduled-trainings.training-plan-generations.store', $training), ['request_key' => (string) Str::uuid()])->assertAccepted();
});

test('duplicate delivery with a live lease releases the real database job instead of losing it', function () {
    [$user, $training, $exercise, $generation, $client] = processingFixture();
    $generation->forceFill(['status' => 'running', 'attempt_count' => 1, 'run_token' => (string) Str::uuid(),
        'lease_expires_at' => now()->addMinutes(5)])->save();
    $message = Queue::connection('training-plan-generations')->pop('training-plan-generations');
    $job = (new GenerateTrainingPlanJob($generation->id))->setJob($message);
    app()->call([$job, 'handle']);
    expect($message->isReleased())->toBeTrue()->and(DB::table('jobs')->count())->toBe(1)
        ->and($client->calls)->toBe(0)->and($generation->fresh()->status)->toBe('running');
});

test('safe failures retry with bounded backoff', function () {
    [$user, $training, $exercise, $generation, $client] = processingFixture();
    $client->exception = new AiClientException('Not accepted', outcomeUnknown: false, safeToRetry: true);
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        app(TrainingAgentService::class)->processGeneration($generation->id);
        expect($client->calls)->toBe($attempt);
        if ($attempt < 3) {
            expect($generation->fresh()->status)->toBe('queued');
            $this->travel(121)->seconds();
        }
    }
    expect($generation->fresh()->status)->toBe('failed');
});

test('expired leases recover only definitely unsent requests', function (bool $sent) {
    [$user, $training, $exercise, $generation, $client] = processingFixture();
    $generation->forceFill(['status' => 'running', 'attempt_count' => 1, 'run_token' => (string) Str::uuid(),
        'lease_expires_at' => now()->subSecond(), 'request_started_at' => $sent ? now()->subMinute() : null,
        'last_enqueued_at' => now()->subMinutes(10)])->save();
    app(TrainingAgentService::class)->recoverGenerations();
    app(TrainingAgentService::class)->processGeneration($generation->id);
    expect($generation->fresh()->status)->toBe($sent ? 'uncertain' : 'succeeded')
        ->and($client->calls)->toBe($sent ? 0 : 1);
})->with([false, true]);

test('a late worker cannot checkpoint or publish after losing its lease', function () {
    [$user, $training, $exercise, $generation, $client] = processingFixture();
    $client->onCall = function () use ($generation): void {
        $generation->fresh()->forceFill(['lease_expires_at' => now()->subSecond()])->save();
        app(TrainingAgentService::class)->recoverGenerations();
    };
    app(TrainingAgentService::class)->processGeneration($generation->id);
    expect($generation->fresh()->status)->toBe('uncertain')->and($generation->fresh()->response_payload)->toBeNull()
        ->and(TrainingPlan::query()->count())->toBe(0);
});

test('cached responses recover without a second call after a publication crash', function () {
    [$user, $training, $exercise, $generation, $client] = processingFixture();
    $crashed = false;
    TrainingPlanGeneration::updating(function (TrainingPlanGeneration $row) use (&$crashed): void {
        if ($row->status === 'succeeded' && ! $crashed) {
            $crashed = true;
            throw new RuntimeException('Publication crash');
        }
    });
    app(TrainingAgentService::class)->processGeneration($generation->id);
    expect(TrainingPlan::query()->count())->toBe(0);
    expect($generation->fresh()->status)->toBe('queued')
        ->and(DB::table('training_plan_generations')->where('id', $generation->id)->value('response_payload'))->not->toContain('Private reasoning');
    $this->travel(31)->seconds();
    app(TrainingAgentService::class)->processGeneration($generation->id);
    expect($generation->fresh()->status)->toBe('succeeded')->and($client->calls)->toBe(1)
        ->and(TrainingPlan::query()->count())->toBe(1);
});

test('status endpoints protect ownership and never expose private recovery fields', function () {
    [$user, $training, $exercise, $generation] = processingFixture();
    $this->actingAs($user)->getJson(route('training-plan-generations.show', $generation))->assertOk()
        ->assertJsonMissingPath('generation.context_hash')->assertJsonMissingPath('generation.run_token')
        ->assertJsonMissingPath('generation.response_payload');
    $this->actingAs(User::factory()->create())->getJson(route('training-plan-generations.show', $generation))->assertNotFound();
    $this->postJson(route('training-plan-generations.cancel', $generation))->assertNotFound();
    $this->actingAs($user)->postJson(route('scheduled-trainings.generate-training-plan', $training))->assertGone();
});

test('cleanup bounds encrypted response retention and preserves unresolved unknown operations', function () {
    [$user, $training, $exercise, $generation] = processingFixture();
    $generation->forceFill(['status' => 'uncertain', 'request_started_at' => now()->subDays(2),
        'response_payload' => 'Expired private response', 'response_expires_at' => now()->subSecond()])->save();
    $this->artisan('training-plan-generations:recover')->assertSuccessful();
    expect($generation->fresh()->status)->toBe('uncertain')->and($generation->fresh()->response_payload)->toBeNull();
    $generation->forceFill(['status' => 'failed', 'finished_at' => now()->subDays(91)])->save();
    $this->artisan('training-plan-generations:recover')->assertSuccessful();
    $this->assertModelMissing($generation);
});

test('the page receives a stable operation key and minimal polling status', function () {
    [$user, $training, $exercise, $generation] = processingFixture();
    $this->actingAs($user)->get(route('scheduled-trainings.show', $training))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('generation.id', $generation->id)->where('generation.status', 'queued')
            ->where('generationRequestKey', $generation->request_key)->missing('generation.response_payload'));
});
