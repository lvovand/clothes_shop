<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // replaces the old Carbon Fields "main-slider" repeater — admin-manageable list
        // instead of raw pipe-delimited postmeta rows.
        Schema::create('homepage_slides', function (Blueprint $table) {
            $table->id();
            $table->string('image_desktop')->nullable();
            $table->string('image_mobile')->nullable();
            $table->string('link_url')->nullable();
            $table->string('link_text')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homepage_slides');
    }
};
