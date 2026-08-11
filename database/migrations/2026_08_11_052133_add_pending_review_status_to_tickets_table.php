<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add 'pending_review' to the tickets status enum.
     * This status means: Programmer has finished and submitted work,
     * waiting for PM approval before moving to 'resolved'.
     */
    public function up(): void
    {
        // MySQL requires ALTER TABLE to modify enum columns
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

    /**
     * Reverse the migration — remove 'pending_review' from enum.
     * Note: existing rows with 'pending_review' will need to be updated first.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE tickets MODIFY COLUMN status ENUM(
            'open',
            'escalated_to_pm',
            'assigned',
            'in_progress',
            'escalated_to_owner',
            'resolved',
            'closed',
            'rejected'
        ) NOT NULL DEFAULT 'open'");
    }
};
