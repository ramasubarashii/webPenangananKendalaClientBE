<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    DB::statement("ALTER TABLE users MODIFY role ENUM('service_desk','project_manager','programmer','owner','client') NOT NULL DEFAULT 'client';");
    echo "ALTERED\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
