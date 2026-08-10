<?php

namespace App\Support;

use App\Models\Category;
use App\Models\HomepageSlide;
use App\Models\LookbookPhoto;
use App\Models\Page;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Model;

/**
 * Сжимает фотографию сразу после загрузки из админки, чтобы в хранилище не
 * попадали оригиналы по 10 МБ (см. ImageOptimizer). Работает по факту записи
 * модели, а не внутри формы Filament: полей загрузки много, а модель — одна
 * точка, мимо которой файл до сайта не доедет.
 *
 * Иконки сайта (favicon, значок бегущей строки) сюда намеренно не входят —
 * их нельзя ни уменьшать, ни переводить в JPEG.
 */
class UploadedImageWatcher
{
    /** Модель => поля, в которых лежит относительный путь к файлу хранилища. */
    private const FIELDS = [
        ProductImage::class => ['path'],
        Category::class => ['image'],
        Page::class => ['image', 'image_mobile'],
        HomepageSlide::class => ['image'],
        LookbookPhoto::class => ['image'],
    ];

    public static function register(): void
    {
        foreach (self::FIELDS as $model => $fields) {
            $model::saved(fn (Model $record) => self::handle($record, $fields));
        }
    }

    private static function handle(Model $record, array $fields): void
    {
        $updates = [];

        foreach ($fields as $field) {
            $path = $record->{$field};
            if (! $path || (! $record->wasRecentlyCreated && ! $record->wasChanged($field))) {
                continue;
            }

            $new = app(ImageOptimizer::class)->optimize($path);
            if ($new && $new !== $path) {
                $updates[$field] = $new;
            }
        }

        if ($updates) {
            // saveQuietly, иначе обновление пути снова поднимет saved.
            $record->forceFill($updates)->saveQuietly();
        }
    }
}
