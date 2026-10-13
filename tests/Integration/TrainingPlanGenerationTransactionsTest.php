<?php

use App\Actions\TrainingPlans\ReserveTrainingPlanGeneration;
use App\Models\TrainingPlanGeneration;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Support/generation-reservation-fixtures.php';

require_once __DIR__.'/../Support/generation-transaction-case.php';

uses(GenerationTransactionTestCase::class);

test('rollback hides and removes Generation and Job even with queue after_commit enabled', function () {
    $user = User::factory()->create();
    $training = reservationTraining($user);
    $reader = reservationObserver();
    config(['queue.connections.training-plan-generations.after_commit' => true]);

    DB::beginTransaction();
    try {
        app(ReserveTrainingPlanGeneration::class)->execute($user, $training, (string) Str::uuid());
        expect(DB::table('training_plan_generations')->count())->toBe(1)->and(DB::table('jobs')->count())->toBe(1)
            ->and($reader->table('training_plan_generations')->count())->toBe(0)
            ->and($reader->table('jobs')->count())->toBe(0);
    } finally {
        DB::rollBack();
    }

    expect($reader->table('training_plan_generations')->count())->toBe(0)->and($reader->table('jobs')->count())->toBe(0);
});

test('commit makes Generation and Job visible together on an independent connection', function () {
    $user = User::factory()->create();
    $training = reservationTraining($user);
    $reader = reservationObserver();

    DB::transaction(function () use ($user, $training, $reader): void {
        app(ReserveTrainingPlanGeneration::class)->execute($user, $training, (string) Str::uuid());
        expect($reader->table('training_plan_generations')->count())->toBe(0)->and($reader->table('jobs')->count())->toBe(0);
    });

    expect($reader->table('training_plan_generations')->count())->toBe(1)->and($reader->table('jobs')->count())->toBe(1);
});

test('an enqueue failure rolls back the reservation and job and allows a clean retry', function () {
    $user = User::factory()->create();
    $training = reservationTraining($user);
    $key = (string) Str::uuid();
    $failed = false;
    Event::listen(JobQueued::class, function () use (&$failed): void {
        if (! $failed) {
            $failed = true;
            throw new RuntimeException('Simulated enqueue failure after INSERT.');
        }
    });

    expect(fn () => app(ReserveTrainingPlanGeneration::class)->execute($user, $training, $key))
        ->toThrow(RuntimeException::class);
    $reader = reservationObserver();
    expect($failed)->toBeTrue()->and($reader->table('training_plan_generations')->count())->toBe(0)
        ->and($reader->table('jobs')->count())->toBe(0);

    app(ReserveTrainingPlanGeneration::class)->execute($user, $training, $key);
    expect($reader->table('training_plan_generations')->count())->toBe(1)->and($reader->table('jobs')->count())->toBe(1);
});

test('failure after commit but before returning HTTP response does not lose the job', function () {
    $user = User::factory()->create();
    $training = reservationTraining($user);
    $key = (string) Str::uuid();
    $crashed = false;
    Event::listen(RequestHandled::class, function (RequestHandled $event) use (&$crashed): void {
        if (! $crashed && $event->request->route()?->getName() === 'scheduled-trainings.training-plan-generations.store') {
            $crashed = true;
            throw new RuntimeException('Simulated failure after commit.');
        }
    });

    $this->actingAs($user);
    $url = route('scheduled-trainings.training-plan-generations.store', $training);
    expect(fn () => $this->postJson($url, ['request_key' => $key]))->toThrow(RuntimeException::class);
    $reader = reservationObserver();
    $id = $reader->table('training_plan_generations')->sole()->id;
    expect($crashed)->toBeTrue()->and($reader->table('jobs')->count())->toBe(1);

    $this->postJson($url, ['request_key' => $key])->assertAccepted()->assertJsonPath('generation.id', $id);
    expect($reader->table('training_plan_generations')->count())->toBe(1)->and($reader->table('jobs')->count())->toBe(1);
});

test('concurrent reservations use PostgreSQL locks and uniqueness without duplicate jobs', function (string $case) {
    $user = User::factory()->create();
    $first = reservationTraining($user);
    $second = $case === 'same key different trainings' ? reservationTraining($user) : $first;
    $firstKey = (string) Str::uuid();
    $secondKey = $case === 'different keys same training' ? (string) Str::uuid() : $firstKey;
    $reader = reservationObserver();
    $names = ['reservation_'.Str::random(10), 'reservation_'.Str::random(10)];
    $workers = [];
    DB::beginTransaction();

    try {
        DB::table('scheduled_trainings')->whereIn('id', [$first->id, $second->id])->lockForUpdate()->get();
        foreach ([[$first, $firstKey], [$second, $secondKey]] as $index => [$training, $key]) {
            $process = new Process([
                PHP_BINARY, base_path('tests/Support/reserve-generation-worker.php'),
                (string) $user->id, (string) $training->id, $key, $names[$index],
            ], base_path(), reservationWorkerEnvironment());
            $process->setTimeout(20);
            $process->start();
            $workers[] = $process;
        }

        $deadline = microtime(true) + 10;
        do {
            $blocked = $reader->selectOne(
                "select count(*) as total from pg_stat_activity where application_name in (?, ?) and wait_event_type = 'Lock' and query like '%scheduled_trainings%for update%'",
                $names,
            );
            if ((int) $blocked->total === 2) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        expect((int) $blocked->total)->toBe(2);
        DB::commit();

        $results = [];
        foreach ($workers as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }

        if ($case === 'same key same training') {
            expect(array_column($results, 'status'))->toBe([202, 202])
                ->and($results[0]['id'])->toBe($results[1]['id']);
        } else {
            $statuses = array_column($results, 'status');
            sort($statuses);
            expect($statuses)->toBe([202, 422]);
        }
        expect(TrainingPlanGeneration::query()->count())->toBe(1)->and(DB::table('jobs')->count())->toBe(1);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($workers as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
    }
})->with(['same key same training', 'different keys same training', 'same key different trainings']);

function reservationObserver(): Connection
{
    config(['database.connections.reservation_observer' => config('database.connections.pgsql')]);

    return DB::connection('reservation_observer');
}

/** @return array<string, string> */
function reservationWorkerEnvironment(): array
{
    $config = config('database.connections.pgsql');

    return [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $config['database'],
        'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
        'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    ];
}
