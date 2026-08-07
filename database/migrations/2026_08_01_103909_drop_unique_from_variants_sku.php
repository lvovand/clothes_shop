<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real legacy variation SKUs are not guaranteed unique (confirmed from live
        // data) — a hard unique constraint here blocks otherwise-valid imports.
        // SKU duplication is a data-quality issue to clean up later, not something
        // that should prevent the variant from existing.
        Schema::table('variants', function (Blueprint $table) {
            $table->dropUnique(['sku']);
        });
    }

    public function down(): void
    {
        Schema::table('variants', function (Blueprint $table) {
            $table->unique('sku');
        });
    }
};
