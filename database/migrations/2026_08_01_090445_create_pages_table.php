<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every static/content page (About, Delivery&Return, Offer&Privacy, Loyalty Card,
        // All-conditions, Gift Card terms) becomes admin-editable data here instead of
        // being hardcoded across separate PHP templates like on the old site.
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->longText('body')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->string('template')->nullable(); // null = generic content page; else a named Blade layout (e.g. 'gift-card')
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
