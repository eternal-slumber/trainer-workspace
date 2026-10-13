<?php

namespace App\Console\Commands;

use App\Jobs\GenerateTrainingPlanJob;
use App\Models\TrainingPlanGeneration;
use App\Services\Agent\TrainingAgentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecoverTrainingPlanGenerations extends Command
{
    protected $signature = 'training-plan-generations:recover';

    protected $description = 'Recover generation leases and remove expired private responses and old metadata';

    public function handle(TrainingAgentService $service): int
    {
        $count = $service->recoverGenerations();
        TrainingPlanGeneration::on('pgsql')->whereNotNull('response_payload')->where('response_expires_at', '<=', now())
            ->update(['response_payload' => null]);
        TrainingPlanGeneration::on('pgsql')->whereNotIn('status', TrainingPlanGeneration::ACTIVE_STATUSES)
            ->where('finished_at', '<', now()->subDays(90))->delete();
        DB::connection('pgsql')->table('failed_jobs')->where('failed_at', '<', now()->subDays(30))
            ->whereRaw("payload::jsonb->>'displayName' = ?", [GenerateTrainingPlanJob::class])->delete();
        $this->info("Recovered deliveries: {$count}");

        return self::SUCCESS;
    }
}
