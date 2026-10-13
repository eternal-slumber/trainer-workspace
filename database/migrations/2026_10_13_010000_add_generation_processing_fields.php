<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_plan_generations', function (Blueprint $table): void {
            $table->string('context_hash', 64)->nullable();
            $table->text('response_payload')->nullable();
            $table->timestampTz('response_received_at')->nullable();
            $table->timestampTz('response_expires_at')->nullable();
            $table->timestampTz('request_started_at')->nullable();
            $table->timestampTz('cancel_requested_at')->nullable();
            $table->timestampTz('last_enqueued_at')->nullable();
            $table->timestampTz('finished_at')->nullable()->index();
            $table->string('error_code', 50)->nullable();
            $table->foreignId('training_plan_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('training_plan_generations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('training_plan_id');
            $table->dropColumn(['context_hash', 'response_payload', 'response_received_at', 'response_expires_at',
                'request_started_at', 'cancel_requested_at', 'last_enqueued_at', 'finished_at', 'error_code']);
        });
    }
};
