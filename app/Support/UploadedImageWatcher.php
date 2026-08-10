<?php

namespace App\Support;

use App\Models\Category;
use App\Models\HomepageSlide;
use App\Models\LookbookPhoto;
use App\Models\Page;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Model;

/**
 * Сжимает фотографию сразу после загрузки из админки и готовит из неё
 * уменьшенные копии для srcset (см. ImageOptimizer и ImageVariants), чтобы в
 * хранилище не попадали оригиналы по 10 МБ, а витрина не грузила картинку в
 * 1600px там, где показывает 426px. Работает по факту записи модели, а не
 * внутри формы Filament: полей загрузки много, а модель — одна точка, мимо
 * которой файл до сайта не доедет.
 *
 * Иконки сайта (favicon, значок бегущей строки) сюда намеренно не входят —
 * их нельзя ни уменьшать, ни переводить в JPEG.
 */
class UploadedImageWatcher
{
    /** Модель => поля, в которых лежит относительный путь к файлу хранилища. */
    private const FIELDS = [
        ProductImage::class => ['path', 'thumb_path'],
        Category::class => ['image', 'thumb_path'],
        Page::class => ['image', 'image_mobile'],
        HomepageSlide::class => ['image'],
        LookbookPhoto::class => ['image'],
    ];

    public static function register(): void
    {
        foreach (self::FIELDS as $model => $fields) {
            $model::saved(fn (Model $record) => self::handle($record, $fields));
            $model::deleted(fn (Model $record) => self::forget($record, $fields));
        }
    }

    private static function handle(Model $record, array $fields): void
    {
        $updates = [];

        foreach ($fields as $field) {
            $path = $record->{$field};
            if (! $record->wasRecentlyCreated && ! $record->wasChanged($field)) {
                continue;
            }

            // Файл заменили или убрали — копии прежнего больше никому не нужны.
            $previous = $record->wasChanged($field) ? $record->getOriginal($field) : null;
            if ($previous && $previous !== $path) {
                app(ImageVariants::class)->purge($previous);
            }

            if (! $path) {
                continue;
            }

            $new = app(ImageOptimizer::class)->optimize($path);
            if ($new && $new !== $path) {
                $updates[$field] = $new;
            }

            app(ImageVariants::class)->generate($new ?: $path);
        }

        if ($updates) {
            // saveQuietly, иначе обновление пути снова поднимет saved.
            $record->forceFill($updates)->saveQuietly();
        }
    }

    /** Удалили картинку — удаляем и её копии, иначе thumbs растёт вечно. */
    private static function forget(Model $record, array $fields): void
    {
        foreach ($fields as $field) {
            if ($record->{$field}) {
                app(ImageVariants::class)->purge($record->{$field});
            }
        }
    }
}
