<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Support\ImageOptimizer;
use Illuminate\Support\Facades\DB;

/**
 * Разовое сжатие уже загруженных фотографий.
 *
 * В хранилище лежали оригиналы прямо из фотоаппарата (до 10 МБ, до 4000px),
 * хотя витрина показывает их максимум в 426px, а страница товара — в ~800px.
 * Команда уменьшает длинную сторону до --max и пережимает в JPEG качества
 * --quality. PNG без прозрачности переводятся в JPEG (у фотографии PNG весит
 * в 10 раз больше) — файл при этом переименовывается, поэтому команда сама
 * правит путь во всех таблицах, где он может встречаться.
 *
 * Идемпотентна: файл, который уже влезает в --max и весит немного, пропускается.
 */
class OptimizeImages extends Command
{
    protected $signature = 'app:optimize-images
        {--max=1600 : Максимальная длина большей стороны, px}
        {--quality=85 : Качество JPEG}
        {--dir=* : Папки внутри storage/app/public (по умолчанию все)}
        {--dry-run : Только показать, что будет сделано}';

    protected $description = 'Уменьшить и пережать загруженные фотографии';

    /** Где в базе могут лежать пути к файлам хранилища. */
    private const PATH_COLUMNS = [
        ['product_images', 'path'],
        ['product_images', 'thumb_path'],
        ['categories', 'image'],
        ['categories', 'thumb_path'],
        ['lookbook_photos', 'image'],
        ['pages', 'image'],
        ['pages', 'image_mobile'],
        ['homepage_slides', 'image'],
        ['homepage_slides', 'image_desktop'],
        ['homepage_slides', 'image_mobile'],
    ];

    public function handle(): int
    {
        $max = (int) $this->option('max');
        $quality = (int) $this->option('quality');
        $dry = (bool) $this->option('dry-run');
        $dirs = $this->option('dir') ?: ['products', 'lookbook', 'content', 'pages'];

        $optimizer = new ImageOptimizer($max, $quality);
        $root = storage_path('app/public');
        $before = $after = $touched = $converted = 0;

        foreach ($dirs as $dir) {
            foreach (glob($root . '/' . $dir . '/*') as $file) {
                if (! is_file($file)) {
                    continue;
                }

                $relative = $this->relative($file, $root);
                $size = filesize($file);

                if ($dry) {
                    $info = @getimagesize($file);
                    if ($info) {
                        $this->line(sprintf('%s %dx%d %s', $relative, $info[0], $info[1], $this->mb($size)));
                    }

                    continue;
                }

                // Переименовываем в JPEG только те файлы, чей путь мы умеем
                // поправить. Незнакомый файл (вставленный куда-то руками)
                // остаётся PNG — иначе на сайте появится битая картинка.
                $new = $optimizer->optimize($relative, $this->pathIsKnown($relative));
                if (! $new) {
                    continue;
                }

                if ($new !== $relative) {
                    $this->updatePaths($relative, $new);
                    $converted++;
                }

                $before += $size;
                $after += filesize($root . '/' . $new);
                $touched++;

                if ($touched % 25 === 0) {
                    $this->line("обработано: {$touched}");
                }
            }
        }

        if ($dry) {
            $this->info('это был пробный прогон, файлы не изменены');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'файлов: %d (в JPEG переведено %d), было %s, стало %s',
            $touched, $converted, $this->mb($before), $this->mb($after)
        ));

        return self::SUCCESS;
    }

    /** Встречается ли путь в базе — в колонках, настройках или тексте страниц. */
    private function pathIsKnown(string $path): bool
    {
        foreach (self::PATH_COLUMNS as [$table, $column]) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($table)
                || ! \Illuminate\Support\Facades\Schema::hasColumn($table, $column)) {
                continue;
            }

            if (DB::table($table)->where($column, $path)->exists()) {
                return true;
            }
        }

        return DB::table('site_settings')->where('value', $path)->exists()
            || DB::table('pages')->where('body', 'like', '%' . $path . '%')->exists();
    }

    private function updatePaths(string $old, string $new): void
    {
        DB::table('pages')
            ->where('body', 'like', '%' . $old . '%')
            ->update(['body' => DB::raw("REPLACE(body, " . DB::getPdo()->quote($old) . ", " . DB::getPdo()->quote($new) . ")")]);

        foreach (self::PATH_COLUMNS as [$table, $column]) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($table)
                || ! \Illuminate\Support\Facades\Schema::hasColumn($table, $column)) {
                continue;
            }

            DB::table($table)->where($column, $old)->update([$column => $new]);
        }

        DB::table('site_settings')->where('value', $old)->update(['value' => $new]);
    }

    private function relative(string $path, string $root): string
    {
        return ltrim(str_replace($root, '', $path), '/');
    }

    private function mb(int $bytes): string
    {
        return round($bytes / 1048576, 1) . ' МБ';
    }
}
