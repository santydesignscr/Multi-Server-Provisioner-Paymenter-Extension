<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ext_orchestrator_service_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('orchestrator_server_pool_id');
            $table->unsignedBigInteger('service_id')->unique();
            $table->unsignedBigInteger('target_server_id');
            $table->unsignedInteger('slots')->default(1);
            $table->timestamps();

            $table->index('orchestrator_server_pool_id', 'ext_orchestrator_alloc_pool_idx');
            $table->index('target_server_id', 'ext_orchestrator_alloc_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_orchestrator_service_allocations');
    }
};
