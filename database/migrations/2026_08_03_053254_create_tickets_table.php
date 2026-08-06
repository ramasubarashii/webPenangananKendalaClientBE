<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_id')->unique();
            $table->string('title');
            $table->text('description');
            $table->enum('category', ['Jaringan', 'Hardware', 'Software', 'Akun', 'Lainnya'])->nullable();
            $table->enum('priority', ['low', 'medium', 'high', 'belum_ditentukan'])->default('belum_ditentukan');
            $table->enum('status', ['open', 'escalated_to_pm', 'in_progress', 'escalated_to_owner', 'resolved', 'closed', 'rejected', 'assigned'])->default('open');
            $table->text('internal_notes')->nullable();
            $table->string('assigned_to_role')->nullable();
            $table->string('attachment_path')->nullable();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
