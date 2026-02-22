<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ext_orchestrator_server_pools', function (Blueprint $table) {
            $table->unsignedBigInteger('total_storage_mb')->default(0)->after('total_slots');
            $table->unsignedBigInteger('total_traffic_gb')->default(0)->after('total_storage_mb');
        });

        Schema::table('ext_orchestrator_service_allocations', function (Blueprint $table) {
            $table->unsignedBigInteger('storage_mb')->default(0)->after('slots');
            $table->unsignedBigInteger('traffic_gb')->default(0)->after('storage_mb');
        });
    }

    public function down(): void
    {
        Schema::table('ext_orchestrator_service_allocations', function (Blueprint $table) {
            $table->dropColumn(['storage_mb', 'traffic_gb']);
        });

        Schema::table('ext_orchestrator_server_pools', function (Blueprint $table) {
            $table->dropColumn(['total_storage_mb', 'total_traffic_gb']);
        });
    }
};
