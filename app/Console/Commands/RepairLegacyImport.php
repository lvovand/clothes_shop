<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductContentBlock;
use App\Models\ProductImage;
use App\Models\Variant;
use App\Support\LegacyHtml;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Разовая починка данных, приехавших первым запуском app:import-legacy-catalog.
 * Обе ошибки видны на странице товара, поэтому чинятся вместе:
 *   1) описания хранились экранированными (nl2br(e(...))) — теги списков выводились
 *      как текст;
 *   2) обложка товара попадала в галерею вторым файлом с тем же путём — фото
 *      дублировалось в слайдере.
 * Сам импортёр исправлен, эта команда приводит в порядок уже загруженное.
 */
#[Signature('app:repair-legacy-import {--dry-run : только показать, что изменится}')]
#[Description('Fix escaped content-block HTML and duplicate product images left by the first legacy import')]
class RepairLegacyImport extends Command
{
    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $this->repairContentBlocks($dry);
        $this->newLine();
        $this->repairDuplicateImages($dry);

        if ($dry) {
            $this->newLine();
            $this->warn('Ничего не записано: снимите --dry-run.');
        }

        return self::SUCCESS;
    }

    private function repairContentBlocks(bool $dry): void
    {
        // Чиним только то, где нет ни одного живого блочного тега. У испорченного
        // импорта их нет по построению (все стали &lt;ul&gt; и подобным), а и у
        // починенного текста, и у набранного в админке через RichEditor блочный тег
        // есть всегда. Заодно это делает команду идемпотентной: второй запуск ничего
        // не находит и не портит уже приведённое в порядок.
        $blocks = ProductContentBlock::get()
            ->reject(fn (ProductContentBlock $block) => preg_match(
                '~<(?:p|ul|ol|h[1-6]|div|blockquote|table)[\s/>]~i', $block->body
            ) === 1);

        $this->info("Блоки описаний к починке: {$blocks->count()}");

        foreach ($blocks as $block) {
            $fixed = LegacyHtml::autop(LegacyHtml::undoEscaping($block->body));

            if ($fixed === $block->body) {
                continue;
            }

            $this->line("  #{$block->id} {$block->title} (товар {$block->product_id})");

            if (! $dry) {
                $block->update(['body' => $fixed]);
            }
        }
    }

    private function repairDuplicateImages(bool $dry): void
    {
        $products = Product::with('images', 'variants')->get();
        $removed = 0;

        foreach ($products as $product) {
            foreach ($product->images->groupBy('path') as $path => $images) {
                if ($images->count() < 2) {
                    continue;
                }

                // Оставляем самую раннюю строку: у неё меньший sort_order, значит она
                // и стоит первой в слайдере — порядок остальных кадров не сдвинется.
                $keep = $images->sortBy([['sort_order', 'asc'], ['id', 'asc']])->first();
                $drop = $images->reject(fn (ProductImage $img) => $img->is($keep));

                $this->line("  {$product->slug}: {$path} — оставляем #{$keep->id}, удаляем #".$drop->pluck('id')->join(', #'));

                if ($dry) {
                    $removed += $drop->count();

                    continue;
                }

                Variant::whereIn('image_id', $drop->pluck('id'))->update(['image_id' => $keep->id]);
                $removed += ProductImage::whereIn('id', $drop->pluck('id'))->delete();
            }
        }

        $this->info("Дублей картинок: {$removed}");
    }
}
