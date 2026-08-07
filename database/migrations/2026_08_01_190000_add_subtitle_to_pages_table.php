<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Used by pages with a distinct two-column layout (e.g. "About") for the
        // small stylised headline shown next to the photo, separate from the main title.
        Schema::table('pages', function (Blueprint $table) {
            $table->string('subtitle')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('subtitle');
        });
    }
};
