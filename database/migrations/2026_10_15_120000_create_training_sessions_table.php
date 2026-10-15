<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('scheduled_training_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('training_plan_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->foreignId('trainee_id')->nullable()->index()->constrained()->noActionOnDelete();
            $table->foreignId('training_group_id')->nullable()->index()->constrained()->noActionOnDelete();
            $table->string('status')->default('in_progress');
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->jsonb('snapshot');
            $table->text('comment')->nullable();
            $table->timestampsTz();
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE training_sessions
                ADD CONSTRAINT training_sessions_subject_check
                    CHECK (
                        (trainee_id IS NOT NULL AND training_group_id IS NULL)
                        OR (trainee_id IS NULL AND training_group_id IS NOT NULL)
                    ),
                ADD CONSTRAINT training_sessions_status_check
                    CHECK (status IN ('in_progress', 'completed')),
                ADD CONSTRAINT training_sessions_completion_check
                    CHECK (
                        (status = 'in_progress' AND completed_at IS NULL)
                        OR (status = 'completed' AND completed_at IS NOT NULL)
                    ),
                ADD CONSTRAINT training_sessions_time_check
                    CHECK (completed_at >= started_at)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('training_sessions');
    }
};
