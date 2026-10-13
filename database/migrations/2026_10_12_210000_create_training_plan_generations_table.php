<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_plan_generations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheduled_training_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->uuid('request_key');
            $table->string('status')->default('queued');
            $table->timestampTz('next_attempt_at')->nullable();
            $table->uuid('run_token')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampsTz();
            $table->unique(['user_id', 'request_key']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['user_id', 'created_at']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE training_plan_generations
                ADD CONSTRAINT training_plan_generations_status_check
                    CHECK (status IN ('queued', 'running', 'uncertain', 'succeeded', 'stale', 'cancelled', 'failed')),
                ADD CONSTRAINT training_plan_generations_attempt_count_check
                    CHECK (attempt_count >= 0),
                ADD CONSTRAINT training_plan_generations_running_check
                    CHECK (status <> 'running' OR (run_token IS NOT NULL AND lease_expires_at IS NOT NULL AND attempt_count > 0))
                SQL);
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX training_plan_generations_active_training_unique
                ON training_plan_generations (scheduled_training_id)
                WHERE status IN ('queued', 'running', 'uncertain')
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('training_plan_generations');
    }
};
