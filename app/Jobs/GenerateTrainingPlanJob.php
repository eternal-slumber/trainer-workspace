<?php

namespace App\Jobs;

use App\Services\Agent\TrainingAgentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateTrainingPlanJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 330;

    public int $tries = 20;

    public bool $failOnTimeout = true;

    public function __construct(public string $generationId) {}

    public function handle(TrainingAgentService $service): void
    {
        try {
            $delay = $service->processGeneration($this->generationId);
            if ($delay > 0) {
                $this->release($delay);
            }
        } catch (Throwable) {
            // Database outage: preserve delivery; the lease recovery also runs independently.
            $this->release(60);
        }
    }

    public function failed(?Throwable $exception): void
    {
        // Recovery never repeats a potentially accepted external request.
        try {
            app(TrainingAgentService::class)->recoverGenerations();
        } catch (Throwable) {
            // The independent scheduler retries recovery when PostgreSQL is available.
        }
    }
}
