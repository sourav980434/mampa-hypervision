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
        Schema::create('published_applications', function (Blueprint $table) {
            $table->id();
            $table->string('vm_uuid')->index();
            $table->string('name');
            $table->string('public_url');
            $table->integer('internal_port');
            $table->enum('protocol', ['http', 'https'])->default('http');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('published_applications');
    }
};
