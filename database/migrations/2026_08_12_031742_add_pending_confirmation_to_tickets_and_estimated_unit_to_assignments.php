<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations:
     * 1. Add 'pending_confirmation' to tickets.status enum
     * 2. Add 'estimated_unit' column to ticket_assignments
     */
    public function up(): void
    {
        // 1. Add pending_confirmation to tickets status enum
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

        // 2. Add estimated_unit to ticket_assignments (default 'hours' = backward compatible)
        Schema::table('ticket_assignments', function (Blueprint $table) {
            $table->enum('estimated_unit', ['hours', 'days'])->default('hours')->after('estimated_hours');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverse estimated_unit
        Schema::table('ticket_assignments', function (Blueprint $table) {
            $table->dropColumn('estimated_unit');
        });

        // Remove pending_confirmation from enum
        DB::statement("ALTER TABLE tickets MODIFY COLUMN status ENUM(
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
