<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_session_exercises', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('block_position');
            $table->unsignedSmallInteger('position');
            $table->jsonb('snapshot');
            $table->string('status')->default('pending');
            $table->decimal('actual_weight', 8, 2)->nullable();
            $table->string('actual_repetitions', 100)->nullable();
            $table->text('comment')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['training_session_id', 'block_position', 'position'],
                'training_session_exercises_position_unique',
            );
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE training_session_exercises
                ADD CONSTRAINT training_session_exercises_status_check
                    CHECK (status IN ('pending', 'completed', 'skipped')),
                ADD CONSTRAINT training_session_exercises_position_check
                    CHECK (block_position > 0 AND position > 0),
                ADD CONSTRAINT training_session_exercises_weight_check
                    CHECK (actual_weight BETWEEN 0 AND 999999.99)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('training_session_exercises');
    }
};
