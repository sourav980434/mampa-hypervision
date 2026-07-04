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
        Schema::create('rdp_vnc_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('vm_uuid')->index();
            $table->enum('type', ['rdp', 'vnc']);
            $table->integer('public_port')->unique();
            $table->integer('internal_port');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rdp_vnc_mappings');
    }
};
