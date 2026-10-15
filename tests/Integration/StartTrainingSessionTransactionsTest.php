<?php

use App\Actions\TrainingPlans\CompleteTrainingPlanAction;
use App\Actions\TrainingPlans\ReserveTrainingPlanGeneration;
use App\Actions\TrainingPlans\SaveTrainingPlan;
use App\Actions\TrainingSessions\StartTrainingSessionAction;
use App\Models\Exercise;
use App\Models\ScheduledTraining;
use App\Models\TrainingPlanGeneration;
use App\Models\TrainingSession;
use App\Models\TrainingSessionExercise;
use App\Models\User;
use App\Services\Agent\TrainingAgentService;
use App\Services\AI\AiClientInterface;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Support/generation-transaction-case.php';
require_once __DIR__.'/../Support/training-session-fixtures.php';

uses(GenerationTransactionTestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->mock(AiClientInterface::class)->shouldNotReceive('chat');
});

test('session and exercise snapshots become visible together only after commit', function () {
    [$user, $training] = sessionStartFixture();
    $observer = sessionStartObserver();

    DB::transaction(function () use ($user, $training, $observer): void {
        app(StartTrainingSessionAction::class)->execute($user, $training);
        expect(DB::table('training_sessions')->count())->toBe(1)
            ->and(DB::table('training_session_exercises')->count())->toBe(3)
            ->and($observer->table('training_sessions')->count())->toBe(0)
            ->and($observer->table('training_session_exercises')->count())->toBe(0);
    });

    expect($observer->table('training_sessions')->count())->toBe(1)
        ->and($observer->table('training_session_exercises')->count())->toBe(3);
});

test('a failed exercise insert leaves no partial session on an independent connection', function () {
    [$user, $training] = sessionStartFixture();
    $observer = sessionStartObserver();
    $insertions = 0;
    TrainingSessionExercise::created(function () use (&$insertions, $observer): void {
        if (++$insertions === 2) {
            expect($observer->table('training_sessions')->count())->toBe(0)
                ->and($observer->table('training_session_exercises')->count())->toBe(0);
            throw new RuntimeException('Rollback session creation.');
        }
    });

    expect(fn () => app(StartTrainingSessionAction::class)->execute($user, $training))
        ->toThrow(RuntimeException::class, 'Rollback session creation.')
        ->and($observer->table('training_sessions')->count())->toBe(0)
        ->and($observer->table('training_session_exercises')->count())->toBe(0);
});

test('concurrent starts wait on PostgreSQL and return the same unchanged session', function (?string $planStatus) {
    [$user, $training] = sessionStartFixture($planStatus);
    [$session, $second] = sessionStartRace($user, $training, 'start', fn () => app(StartTrainingSessionAction::class)->execute($user, $training)->fresh());

    expect($second)->toBe(['status' => 200, 'id' => $session->id])
        ->and(TrainingSession::query()->count())->toBe(1)
        ->and($session->fresh()->getAttributes())->toBe($session->getAttributes())
        ->and($session->exercises()->count())->toBe($planStatus === null ? 0 : 3);
})->with(['draft', null]);

test('start snapshots a manual plan committed by a competing writer', function () {
    [$user, $training] = sessionStartFixture(null);
    [$plan, $start] = sessionStartRace($user, $training, 'start', fn () => app(SaveTrainingPlan::class)->create($user, $training, sessionStartPlanAttributes()));
    $session = TrainingSession::query()->sole();

    expect($start['status'])->toBe(200)
        ->and($session->training_plan_id)->toBe($plan->id)
        ->and($session->snapshot['plan']['title'])->toBe($plan->title)
        ->and($session->exercises()->count())->toBe(3);
});

test('characterization manual creation still needs a guard when start commits first', function () {
    [$user, $training] = sessionStartFixture(null);
    [$session, $manual] = sessionStartRace($user, $training, 'manual', fn () => app(StartTrainingSessionAction::class)->execute($user, $training));

    expect($manual['status'])->toBe(200)
        ->and($training->trainingPlan()->sole()->id)->toBe($manual['id'])
        ->and($session->fresh()->training_plan_id)->toBeNull()
        ->and($session->fresh()->snapshot['plan'])->toBeNull();
});

test('start rejects an AI reservation committed by a competing writer', function () {
    [$user, $training] = sessionStartFixture(null);
    [$generation, $start] = sessionStartRace($user, $training, 'start', fn () => app(ReserveTrainingPlanGeneration::class)->execute($user, $training, (string) Str::uuid()));

    expect($start['status'])->toBe(422)
        ->and($start['errors'])->toBe(['training_plan_generation'])
        ->and($generation->fresh()->status)->toBe('queued')
        ->and(TrainingSession::query()->count())->toBe(0);
});

test('characterization AI reservation still needs a guard when start commits first', function () {
    [$user, $training] = sessionStartFixture(null);
    [$session, $reserve] = sessionStartRace($user, $training, 'reserve', fn () => app(StartTrainingSessionAction::class)->execute($user, $training));

    expect($reserve['status'])->toBe(200)
        ->and(TrainingPlanGeneration::query()->sole()->status)->toBe('queued')
        ->and($session->fresh()->snapshot['plan'])->toBeNull();
});

test('start snapshots the complete AI plan when publication commits first', function () {
    [$user, $training] = sessionStartFixture(null);
    Exercise::factory()->for($user)->create();
    $generation = sessionStartCachedGeneration($user, $training);
    [, $start] = sessionStartRace($user, $training, 'start', fn () => app(TrainingAgentService::class)->processGeneration($generation->id));
    $plan = $training->trainingPlan()->sole();
    $session = TrainingSession::query()->sole();

    expect($start['status'])->toBe(200)
        ->and($generation->fresh()->status)->toBe('succeeded')
        ->and($session->training_plan_id)->toBe($plan->id)
        ->and($session->snapshot['plan']['title'])->toBe($plan->title)
        ->and($session->exercises()->count())->toBe(1);
});

test('characterization reservation and publication after start still need session guards', function () {
    [$user, $training] = sessionStartFixture(null);
    Exercise::factory()->for($user)->create();
    [$session, $publish] = sessionStartRace($user, $training, 'reserve-and-publish', fn () => app(StartTrainingSessionAction::class)->execute($user, $training));

    expect($publish['status'])->toBe(200)
        ->and(TrainingPlanGeneration::query()->sole()->status)->toBe('succeeded')
        ->and($training->trainingPlan()->exists())->toBeTrue()
        ->and($session->fresh()->training_plan_id)->toBeNull()
        ->and($session->fresh()->snapshot['plan'])->toBeNull();
});

test('start rejects a cancellation committed by a competing HTTP request', function () {
    [$user, $training] = sessionStartFixture();
    [, $start] = sessionStartRace($user, $training, 'start', function () use ($user, $training): void {
        $this->actingAs($user)->patchJson(route('scheduled-trainings.update', $training), ['status' => 'cancelled'])->assertRedirect();
    });

    expect($start['status'])->toBe(422)
        ->and($training->fresh()->status)->toBe('cancelled')
        ->and(TrainingSession::query()->count())->toBe(0);
});

test('characterization cancellation still needs a guard when start commits first', function () {
    [$user, $training] = sessionStartFixture();
    [$session, $cancel] = sessionStartRace($user, $training, 'cancel', fn () => app(StartTrainingSessionAction::class)->execute($user, $training));

    expect($cancel['status'])->toBe(302)
        ->and($training->fresh()->status)->toBe('cancelled')
        ->and($session->fresh()->status)->toBe('in_progress');
});

test('start rejects completion committed by a competing writer with or without a plan', function (?string $status) {
    [$user, $training, $plan] = sessionStartFixture($status);
    [, $start] = sessionStartRace($user, $training, 'start', function () use ($user, $training, $plan): void {
        if ($plan !== null) {
            app(CompleteTrainingPlanAction::class)->execute($user, $plan);
        } else {
            $this->actingAs($user)->patchJson(route('scheduled-trainings.update', $training), ['status' => 'completed'])->assertRedirect();
        }
    });

    expect($start['status'])->toBe(422)
        ->and($training->fresh()->status)->toBe('completed')
        ->and(TrainingSession::query()->count())->toBe(0);
})->with(['draft', null]);

test('characterization legacy completion still needs a guard when start commits first', function (?string $status) {
    [$user, $training] = sessionStartFixture($status);
    [$session, $completion] = sessionStartRace($user, $training, $status === null ? 'complete-without-plan' : 'complete', fn () => app(StartTrainingSessionAction::class)->execute($user, $training));

    expect($completion['status'])->toBe($status === null ? 302 : 200)
        ->and($training->fresh()->status)->toBe('completed')
        ->and($session->fresh()->status)->toBe('in_progress');
})->with(['draft', null]);

function sessionStartObserver(): Connection
{
    config(['database.connections.session_start_observer' => config('database.connections.pgsql')]);

    return DB::connection('session_start_observer');
}

function sessionStartRace(User $user, ScheduledTraining $training, string $operation, Closure $first): array
{
    $config = config('database.connections.pgsql');
    $name = 'session_start_'.Str::random(10);
    $worker = new Process([
        PHP_BINARY, base_path('tests/Support/start-training-session-worker.php'),
        (string) $user->id, (string) $training->id, $operation, $name,
    ], base_path(), [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $config['database'],
        'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
        'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    ]);
    $worker->setTimeout(20);
    $observer = sessionStartObserver();
    DB::beginTransaction();

    try {
        $result = $first();
        $worker->start();
        $deadline = microtime(true) + 10;
        do {
            $blocked = $observer->selectOne(
                "select count(*) as total from pg_stat_activity where application_name = ? and wait_event_type = 'Lock' and query like '%scheduled_trainings%for update%'",
                [$name],
            );
            if ((int) $blocked->total === 1) {
                break;
            }
            if (! $worker->isRunning()) {
                throw new RuntimeException('Worker did not wait for the schedule lock: '.$worker->getOutput().$worker->getErrorOutput());
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        expect((int) $blocked->total)->toBe(1);
        DB::commit();
        $worker->wait();
        expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());

        return [$result, json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR)];
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($worker->isRunning()) {
            $worker->stop();
        }
    }
}
