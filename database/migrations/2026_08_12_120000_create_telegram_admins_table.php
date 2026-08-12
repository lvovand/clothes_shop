<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кому открыт доступ в мини-приложение бота (просмотр заказов и смена статусов).
 *
 * Ключ доступа — никнейм Telegram: его владелец видит в переписке и может
 * вписать сам. Числовой telegram_id заполняется при первом входе — он у
 * человека не меняется, в отличие от никнейма, поэтому пускать по нему надёжнее.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_admins', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique()->comment('Никнейм в Telegram, без @, в нижнем регистре');
            $table->string('name')->nullable()->comment('Кто это — подпись для админки');
            $table->unsignedBigInteger('telegram_id')->nullable()->unique();
            $table->boolean('can_edit')->default(true)->comment('Может менять статусы, а не только смотреть');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_admins');
    }
};
