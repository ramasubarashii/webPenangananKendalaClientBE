<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add walk-in/non-user reporter fields to tickets table.
     * These fields are only populated when Service Desk creates a ticket
     * on behalf of a client who is not registered in the system.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Name of the external/non-system reporter (walk-in client)
            $table->string('reporter_name')->nullable()->after('user_id');

            // Contact info of the reporter (phone/WA/email)
            $table->string('reporter_contact')->nullable()->after('reporter_name');

            // How the reporter contacted Service Desk
            $table->enum('contact_method', [
                'whatsapp',
                'telepon',
                'email',
                'walk_in',
                'lainnya',
            ])->nullable()->after('reporter_contact');

            // Free-text notes when contact_method = 'lainnya'
            $table->string('contact_method_notes')->nullable()->after('contact_method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn([
                'reporter_name',
                'reporter_contact',
                'contact_method',
                'contact_method_notes',
            ]);
        });
    }
};
