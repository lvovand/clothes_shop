<?php

namespace App\Console\Commands;

use App\Support\ImageVariants;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Разовая подготовка уменьшенных копий для уже загруженных фотографий.
 *
 * Новые картинки получают копии сами при загрузке из админки
 * (UploadedImageWatcher), а этой командой добираются те, что лежали в базе до
 * появления srcset. Идемпотентна: готовая копия пересоздаётся только если
 * оригинал новее её или задан --force.
 */
class GenerateThumbnails extends Command
{
    protected $signature = 'app:generate-thumbs
        {--quality=82 : Качество JPEG и WebP}
        {--force : Пересоздать копии, даже если они уже есть}
        {--purge : Удалить всё дерево копий и выйти}';

    protected $description = 'Сделать уменьшенные копии фотографий для srcset';

    /** Где в базе лежат пути к картинкам, которые показывает витрина. */
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
        if ($this->option('purge')) {
            return $this->purgeAll();
        }

        $variants = new ImageVariants((int) $this->option('quality'));
        $force = (bool) $this->option('force');

        $paths = $this->paths();
        $this->info('картинок в базе: ' . count($paths));

        $made = $done = 0;

        foreach ($paths as $path) {
            $made += $variants->generate($path, $force);
            $done++;

            if ($done % 25 === 0) {
                $this->line("обработано: {$done}/" . count($paths));
            }
        }

        $this->info(sprintf(
            'готово: %d картинок, создано копий: %d, вес дерева копий: %s',
            $done, $made, $this->treeSize()
        ));

        return self::SUCCESS;
    }

    /** Уникальные пути из всех колонок, где может лежать картинка. */
    private function paths(): array
    {
        $paths = [];

        foreach (self::PATH_COLUMNS as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            foreach (DB::table($table)->whereNotNull($column)->pluck($column) as $path) {
                if ($path !== '') {
                    $paths[$path] = true;
                }
            }
        }

        return array_keys($paths);
    }

    private function purgeAll(): int
    {
        $root = storage_path('app/public/thumbs');
        if (is_dir($root)) {
            $this->line('удаляю ' . $root . ' (' . $this->treeSize() . ')');
            exec('rm -rf ' . escapeshellarg($root));
        }

        $this->info('дерево копий удалено');

        return self::SUCCESS;
    }

    private function treeSize(): string
    {
        $root = storage_path('app/public/thumbs');
        if (! is_dir($root)) {
            return '0 МБ';
        }

        $bytes = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $bytes += $file->getSize();
        }

        return round($bytes / 1048576, 1) . ' МБ';
    }
}
