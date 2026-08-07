<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // token = an opaque UUID stored in a signed cookie, resolved server-side.
        // The old site kept this as PHP serialize()/unserialize() on a raw client cookie —
        // deliberately not replicating that, this is DB-backed against an opaque identifier only.
        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->index();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['token', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_items');
    }
};
