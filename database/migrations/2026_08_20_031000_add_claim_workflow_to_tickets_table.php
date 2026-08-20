<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add claim workflow statuses and claimed_programmer_id column.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('claimed_programmer_id')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        DB::statement("ALTER TABLE tickets MODIFY COLUMN status ENUM(
            'pending_confirmation',
            'open',
            'escalated_to_pm',
            'waiting_programmer',
            'waiting_pm_approval',
            'assigned',
            'in_progress',
            'pending_review',
            'escalated_to_owner',
            'resolved',
            'closed',
            'rejected'
        ) NOT NULL DEFAULT 'open'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['claimed_programmer_id']);
            $table->dropColumn('claimed_programmer_id');
        });

        DB::statement("ALTER TABLE tickets MODIFY COLUMN status ENUM(
            'pending_confirmation',
            'open',
            'escalated_to_pm',
            'assigned',
            'in_progress',
            'pending_review',
            'escalated_to_owner',
            'resolved',
            'closed',
            'rejected'
        ) NOT NULL DEFAULT 'open'");
    }
};
