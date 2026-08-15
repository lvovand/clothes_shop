<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Произвольный код на витрине: счётчики, метрики, чаты, пиксели.
 *
 * Такие вставки почти всегда приходят готовым куском HTML со своими <script>
 * и <noscript>, и у каждого сервиса своё требование, куда его положить, —
 * поэтому код хранится как есть, а место вставки выбирается отдельным полем.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('code_snippets', function (Blueprint $table) {
            $table->id();
            $table->string('title')->comment('Название блока для админки, на сайт не выводится');
            $table->string('position')->default('head')->comment('head | body_start | body_end');
            $table->text('code');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['position', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('code_snippets');
    }
};
