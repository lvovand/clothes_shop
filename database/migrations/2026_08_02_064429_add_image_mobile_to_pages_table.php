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
        // Some page templates (e.g. "loyalty-card") show a full-width photo hero with
        // separate desktop/mobile source photos, same as the homepage hero slides.
        Schema::table('pages', function (Blueprint $table) {
            $table->string('image_mobile')->nullable()->after('image');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('image_mobile');
        });
    }
};
