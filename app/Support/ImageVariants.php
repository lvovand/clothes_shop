<?php

namespace App\Support;

/**
 * Уменьшенные копии («превью») загруженных фотографий.
 *
 * ImageOptimizer приводит сам файл к разумному максимуму (1600px), но витрина
 * показывает карточку каталога в 426px, а на мобильном — в ~190px, то есть
 * каждая картинка каталога тяжелее нужного в разы. Здесь из одного файла
 * готовятся копии по ширинам WIDTHS в JPEG и WebP, а шаблоны отдают их
 * браузеру через srcset — тот сам берёт подходящую под экран и плотность.
 *
 * Копии лежат отдельным деревом: products/foo.jpg → thumbs/640/products/foo.jpg
 * (+ .webp). Отдельная папка, а не суффикс рядом с оригиналом, чтобы копии не
 * попадали ни в глаза владельцу в файловом менеджере, ни в обход
 * app:optimize-images (он ходит по products/lookbook/content/pages).
 */
class ImageVariants
{
    /**
     * Ширины копий. Подобраны под реальную вёрстку: карточка каталога 426px при
     * контейнере 1320px (32.3%), 311px на средних экранах (23.6%), 48vw на
     * мобильном; страница товара ~800px; слайд главной — во всю ширину.
     */
    public const WIDTHS = [400, 640, 960, 1280, 1600];

    /** Куда складываем копии внутри storage/app/public. */
    private const DIR = 'thumbs';

    public function __construct(
        private int $quality = 82,
    ) {
    }

    /**
     * Сделать копии для файла. Копии шире оригинала не создаются (апскейл
     * ничего не даёт, только вес). Возвращает число созданных файлов.
     */
    public function generate(string $relativePath, bool $force = false): int
    {
        // Развёрнутая в память картинка 1600×1600 — это ~10 МБ, но исходник
        // может быть и крупнее, если оптимизатор его не тронул.
        ini_set('memory_limit', '512M');

        $source = $this->absolute($relativePath);
        if (! is_file($source)) {
            return 0;
        }

        $info = @getimagesize($source);
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            return 0;
        }

        [$w, $h, $type] = $info;
        $made = 0;
        $img = null;

        foreach (self::WIDTHS as $width) {
            if ($width >= $w) {
                continue;
            }

            $targets = $this->targetsFor($relativePath, $width);
            $needed = array_filter(
                $targets,
                fn (string $file) => $force || ! is_file($file) || filemtime($file) < filemtime($source)
            );

            if (! $needed) {
                continue;
            }

            $img ??= $type === IMAGETYPE_PNG ? @imagecreatefrompng($source) : @imagecreatefromjpeg($source);
            if (! $img) {
                return $made;
            }

            $height = max(1, (int) round($h * $width / $w));
            $dst = imagecreatetruecolor($width, $height);
            // Копии всегда без прозрачности: и JPEG, и WebP отдаём на белом —
            // прозрачные PNG в каталоге и так лежат на белом фоне карточки.
            imagefilledrectangle($dst, 0, 0, $width, $height, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $width, $height, $w, $h);

            foreach ($needed as $format => $file) {
                $this->ensureDir($file);
                $ok = $format === 'webp'
                    ? imagewebp($dst, $file, $this->quality)
                    : imagejpeg($dst, $file, $this->quality);

                if ($ok) {
                    @chmod($file, 0644);
                    $made++;
                }
            }

            imagedestroy($dst);
        }

        if ($img) {
            imagedestroy($img);
        }

        return $made;
    }

    /** Удалить все копии файла — при замене или удалении картинки. */
    public function purge(string $relativePath): void
    {
        foreach (self::WIDTHS as $width) {
            foreach ($this->targetsFor($relativePath, $width) as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * srcset нужного формата: только из реально существующих копий, иначе
     * браузер получил бы 404 и картинка не показалась бы вовсе.
     */
    public static function srcset(string $relativePath, string $format = 'jpg'): string
    {
        $parts = [];

        foreach (self::WIDTHS as $width) {
            $file = self::variantPath($relativePath, $width, $format);
            if (is_file(storage_path('app/public/' . $file))) {
                $parts[] = asset('storage/' . $file) . ' ' . $width . 'w';
            }
        }

        return implode(', ', $parts);
    }

    /**
     * Адрес копии не уже указанной ширины; если такой копии нет — оригинал.
     * Для мест, где srcset неприменим: фоновые картинки (background: url) и
     * маленькие миниатюры галереи.
     */
    public static function url(string $relativePath, int $width): string
    {
        foreach (self::WIDTHS as $candidate) {
            if ($candidate < $width) {
                continue;
            }

            $file = self::variantPath($relativePath, $candidate, 'jpg');
            if (is_file(storage_path('app/public/' . $file))) {
                return asset('storage/' . $file);
            }
        }

        return asset('storage/' . $relativePath);
    }

    /** Относительный путь копии внутри storage/app/public. */
    public static function variantPath(string $relativePath, int $width, string $format): string
    {
        $clean = ltrim($relativePath, '/');
        $base = preg_replace('/\.(jpe?g|png|webp)$/i', '', $clean);

        return self::DIR . '/' . $width . '/' . $base . '.' . $format;
    }

    /** ['jpg' => абсолютный путь, 'webp' => …] — webp только если GD его умеет. */
    private function targetsFor(string $relativePath, int $width): array
    {
        $targets = ['jpg' => $this->absolute(self::variantPath($relativePath, $width, 'jpg'))];

        if (function_exists('imagewebp')) {
            $targets['webp'] = $this->absolute(self::variantPath($relativePath, $width, 'webp'));
        }

        return $targets;
    }

    private function absolute(string $relativePath): string
    {
        return storage_path('app/public/' . ltrim($relativePath, '/'));
    }

    private function ensureDir(string $file): void
    {
        $dir = dirname($file);
        if (! is_dir($dir)) {
            // 0755, иначе фронтовый nginx (www-data) не прочитает файлы —
            // ровно на этом сайт когда-то «открывался только с VPN».
            mkdir($dir, 0755, true);
        }
    }
}
