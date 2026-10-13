<?php

use App\Models\TrainingPlan;
use App\Services\Agent\TrainingAgentService;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Support/generation-transaction-case.php';
require_once __DIR__.'/../Support/generation-processing-fixtures.php';

uses(GenerationTransactionTestCase::class);

test('independent workers make one external call and the duplicate delivery retains its delay', function () {
    [$user, $training, $exercise, $generation] = processingFixture();
    $directory = sys_get_temp_dir().'/generation-workers-'.Str::uuid();
    mkdir($directory);
    $first = processingWorker($generation->id, 'hold', $directory);
    try {
        waitForProcessingMarker($directory, $first);
        $second = processingWorker($generation->id, 'normal', $directory);
        $second->wait();
        expect($second->isSuccessful())->toBeTrue($second->getErrorOutput());
        $result = json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($result['delay'])->toBeGreaterThan(0);
        file_put_contents($directory.'/release', 'release');
        $first->wait();
        expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
            ->and(file($directory.'/calls'))->toHaveCount(1)
            ->and($generation->fresh()->status)->toBe('succeeded')->and(TrainingPlan::query()->count())->toBe(1);
    } finally {
        $first->stop();
        cleanupProcessingDirectory($directory);
    }
});

test('a real process crash is recovered according to the durable request phase', function (string $phase) {
    [$user, $training, $exercise, $generation, $client] = processingFixture();
    $directory = sys_get_temp_dir().'/generation-crash-'.Str::uuid();
    mkdir($directory);
    $worker = processingWorker($generation->id, $phase, $directory);
    try {
        $worker->wait();
        expect($worker->getExitCode())->toBe(match ($phase) {
            'crash-before-send' => 71, 'crash-after-send' => 72, 'crash-after-checkpoint' => 73,
        });
        expect($generation->fresh()->status)->toBe('running');
        $generation->fresh()->forceFill(['lease_expires_at' => now()->subSecond(), 'last_enqueued_at' => now()->subMinutes(10)])->save();
        app(TrainingAgentService::class)->recoverGenerations();
        app(TrainingAgentService::class)->processGeneration($generation->id);
        expect($generation->fresh()->status)->toBe($phase === 'crash-after-send' ? 'uncertain' : 'succeeded')
            ->and($client->calls)->toBe($phase === 'crash-before-send' ? 1 : 0)
            ->and(TrainingPlan::query()->count())->toBe($phase === 'crash-after-send' ? 0 : 1);
    } finally {
        $worker->stop();
        cleanupProcessingDirectory($directory);
    }
})->with(['crash-before-send', 'crash-after-send', 'crash-after-checkpoint']);

test('an independent late worker cannot publish after recovery revoked its token', function () {
    [$user, $training, $exercise, $generation] = processingFixture();
    $directory = sys_get_temp_dir().'/generation-late-'.Str::uuid();
    mkdir($directory);
    $worker = processingWorker($generation->id, 'hold', $directory);
    try {
        waitForProcessingMarker($directory, $worker);
        $generation->fresh()->forceFill(['lease_expires_at' => now()->subSecond()])->save();
        app(TrainingAgentService::class)->recoverGenerations();
        file_put_contents($directory.'/release', 'release');
        $worker->wait();
        expect($worker->isSuccessful())->toBeTrue()->and($generation->fresh()->status)->toBe('uncertain')
            ->and($generation->fresh()->response_payload)->toBeNull()->and(TrainingPlan::query()->count())->toBe(0);
    } finally {
        $worker->stop();
        cleanupProcessingDirectory($directory);
    }
});

function processingWorker(string $id, string $mode, string $directory): Process
{
    $config = config('database.connections.pgsql');
    $process = new Process([PHP_BINARY, base_path('tests/Support/process-generation-worker.php'), $id, $mode, $directory], base_path(), [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $config['database'],
        'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => $config['username'],
        'DB_PASSWORD' => $config['password'], 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    ]);
    $process->setTimeout(20);
    $process->start();

    return $process;
}

function waitForProcessingMarker(string $directory, Process $worker): void
{
    $deadline = microtime(true) + 10;
    while (! is_file($directory.'/ready')) {
        if (! $worker->isRunning() || microtime(true) > $deadline) {
            throw new RuntimeException('Worker did not reach LLM: '.$worker->getOutput().$worker->getErrorOutput());
        }
        usleep(10000);
    }
}

function cleanupProcessingDirectory(string $directory): void
{
    foreach (glob($directory.'/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
