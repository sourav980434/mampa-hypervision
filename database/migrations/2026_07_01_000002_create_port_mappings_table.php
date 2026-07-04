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
        Schema::create('port_mappings', function (Blueprint $table) {
            $table->id();
            $table->integer('public_port')->unique();
            $table->string('internal_ip');
            $table->integer('internal_port');
            $table->enum('protocol', ['tcp', 'udp'])->default('tcp');
            $table->string('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('port_mappings');
    }
};
