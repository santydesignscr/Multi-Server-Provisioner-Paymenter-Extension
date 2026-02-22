<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ext_orchestrator_server_pools', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('orchestrator_server_id');
            $table->unsignedBigInteger('target_server_id');
            $table->unsignedInteger('total_slots')->default(0);
            $table->boolean('maintenance')->default(false);
            $table->timestamps();

            $table->unique(['orchestrator_server_id', 'target_server_id'], 'ext_orchestrator_unique_pool_pair');
            $table->index(['orchestrator_server_id', 'maintenance'], 'ext_orchestrator_server_maintenance_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_orchestrator_server_pools');
    }
};
