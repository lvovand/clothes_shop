<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Человек, которому открыт доступ в мини-приложение бота.
 *
 * Никнейм хранится нормализованным (без @, в нижнем регистре) — Telegram отдаёт
 * его в том виде, в каком владелец его завёл, а в админку вписывают как попало.
 */
class TelegramAdmin extends Model
{
    protected $fillable = ['username', 'name', 'telegram_id', 'can_edit', 'is_active', 'last_seen_at'];

    protected $casts = [
        'can_edit' => 'boolean',
        'is_active' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    public static function normalizeUsername(?string $username): string
    {
        return mb_strtolower(ltrim(trim((string) $username), '@'));
    }

    public function setUsernameAttribute($value): void
    {
        $this->attributes['username'] = self::normalizeUsername($value);
    }

    /**
     * Поиск допущенного по данным Telegram. Сначала по числовому id (он не
     * меняется и заполняется при первом входе), затем по никнейму — на первый
     * вход, когда id ещё не известен.
     */
    public static function findFor(?int $telegramId, ?string $username): ?self
    {
        $query = static::query()->where('is_active', true);

        if ($telegramId) {
            $found = (clone $query)->where('telegram_id', $telegramId)->first();
            if ($found) {
                return $found;
            }
        }

        $normalized = self::normalizeUsername($username);

        return $normalized === '' ? null : (clone $query)->where('username', $normalized)->first();
    }
}
