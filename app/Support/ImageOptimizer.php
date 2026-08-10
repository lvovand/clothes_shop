<?php

namespace App\Support;

/**
 * Уменьшение и пережатие загруженных фотографий.
 *
 * Оригиналы из фотоаппарата весят до 10 МБ при том, что витрина показывает
 * картинку максимум в 426px, а страница товара — в ~800px. Здесь одно место,
 * где решается, каким файл ляжет в хранилище: им пользуется и наблюдатель
 * загрузок из админки, и разовая команда app:optimize-images.
 */
class ImageOptimizer
{
    public function __construct(
        private int $max = 1600,
        private int $quality = 85,
    ) {
    }

    /**
     * Обработать файл в storage/app/public. Возвращает относительный путь —
     * он меняется, когда PNG без прозрачности переводится в JPEG. null —
     * файл не изображение, не читается или трогать его незачем.
     */
    public function optimize(string $relativePath, bool $allowRename = true): ?string
    {
        // Развёрнутый в память PNG 4000×4000 занимает ~64 МБ, и вместе с копией
        // под ресайз не влезает в обычные 128 МБ — файл просто «не читается».
        ini_set('memory_limit', '512M');

        $file = storage_path('app/public/' . ltrim($relativePath, '/'));
        if (! is_file($file)) {
            return null;
        }

        $size = filesize($file);
        $info = @getimagesize($file);
        if (! $info) {
            return null;
        }

        [$w, $h, $type] = $info;
        if (! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            return null;
        }

        $isPng = $type === IMAGETYPE_PNG;
        $fits = max($w, $h) <= $this->max;

        // Маленький JPEG трогать незачем; PNG пересматриваем всегда — даже
        // небольшой он обычно вдвое тяжелее равного ему JPEG.
        if ($fits && $size <= 400 * 1024 && ! $isPng) {
            return null;
        }

        $img = $isPng ? @imagecreatefrompng($file) : @imagecreatefromjpeg($file);
        if (! $img) {
            return null;
        }

        $alpha = $isPng && $this->hasAlpha($img, $w, $h);

        if (! $fits) {
            $scale = $this->max / max($w, $h);
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            if ($alpha) {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
            } else {
                imagefilledrectangle($dst, 0, 0, $nw, $nh, imagecolorallocate($dst, 255, 255, 255));
            }
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            $img = $dst;
        }

        $toJpeg = $isPng && ! $alpha && $allowRename;
        $target = $toJpeg ? preg_replace('/\.png$/i', '.jpg', $file) : $file;

        $tmp = $file . '.tmp';
        $ok = ($isPng && ! $toJpeg)
            ? imagepng($img, $tmp, 9)
            : imagejpeg($img, $tmp, $this->quality);
        imagedestroy($img);

        if (! $ok) {
            @unlink($tmp);

            return null;
        }

        // Пережатие не всегда выигрывает (файл уже оптимизирован) — тогда
        // оставляем как было.
        if (filesize($tmp) >= $size && ! $toJpeg) {
            @unlink($tmp);

            return null;
        }

        rename($tmp, $target);
        @chmod($target, 0644);

        if ($toJpeg) {
            @unlink($file);

            return preg_replace('/\.png$/i', '.jpg', $relativePath);
        }

        return $relativePath;
    }

    /**
     * Есть ли в PNG реально прозрачные пиксели. Полный обход у 4000px картинки
     * стоит секунды, поэтому идём с шагом — для фотографии этого достаточно.
     */
    private function hasAlpha(\GdImage $img, int $w, int $h): bool
    {
        $stepX = max(1, (int) ($w / 200));
        $stepY = max(1, (int) ($h / 200));

        for ($x = 0; $x < $w; $x += $stepX) {
            for ($y = 0; $y < $h; $y += $stepY) {
                if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
