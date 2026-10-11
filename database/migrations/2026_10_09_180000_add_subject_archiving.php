<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['trainees', 'training_groups'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->timestampTz('archived_at')->nullable();
            });
        }

        $this->changeSubjectForeignKeys(false);
    }

    public function down(): void
    {
        $this->changeSubjectForeignKeys(true);

        foreach (['trainees', 'training_groups'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('archived_at');
            });
        }
    }

    private function changeSubjectForeignKeys(bool $restoreOriginal): void
    {
        foreach ([
            'scheduled_trainings' => 'cascade',
            'training_plans' => 'set null',
            'training_notes' => 'set null',
            'agent_memories' => 'cascade',
        ] as $tableName => $originalAction) {
            $action = $restoreOriginal ? $originalAction : 'no action';

            Schema::table($tableName, function (Blueprint $table) use ($action): void {
                foreach (['trainee_id' => 'trainees', 'training_group_id' => 'training_groups'] as $column => $parent) {
                    $table->dropForeign([$column]);
                    $table->foreign($column)->references('id')->on($parent)->onDelete($action);
                }
            });
        }
    }
};
