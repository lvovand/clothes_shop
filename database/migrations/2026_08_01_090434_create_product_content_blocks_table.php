<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // structured admin-editable content blocks per product
        // (old site had exactly 2 fixed blocks: "описание и уход", "параметры изделия" —
        // modeled as rows, not fixed columns, so a third block can be added later without a migration)
        Schema::create('product_content_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('key'); // e.g. 'description_care', 'fit'
            $table->string('title');
            $table->longText('body');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_content_blocks');
    }
};
