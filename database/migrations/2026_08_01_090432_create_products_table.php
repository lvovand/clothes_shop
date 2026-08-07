<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_new')->default(false);
            $table->string('status')->default('published'); // draft | published
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            // real per-product shipping dimensions, distinct from the old site's
            // uniform placeholder values (700x40x30x3 on every product there)
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->decimal('length_cm', 6, 2)->nullable();
            $table->decimal('width_cm', 6, 2)->nullable();
            $table->decimal('height_cm', 6, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
