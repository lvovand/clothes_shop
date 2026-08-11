<?php

namespace App\Console\Commands;

use App\Models\ProductContentBlock;
use Illuminate\Console\Command;

/**
 * Переводит «списки», набранные символом • внутри абзаца, в настоящие <ul><li>.
 *
 * У эталона в спойлерах товара списки размечены именно тегами, и тема их
 * специально стилизует (.spoiler-body ul: disc, отступ 20px, интервал 6px
 * между пунктами). Импортированные описания вместо этого хранят пункты
 * строками «• текст», разделёнными <br> внутри одного <p> — выглядит это
 * плоско и от эталона отличается.
 *
 * Команда идемпотентна: после перевода символов-маркеров в тексте не остаётся,
 * поэтому повторный запуск ничего не находит.
 */
class BulletsToLists extends Command
{
    protected $signature = 'app:bullets-to-lists {--dry-run : Показать изменения, но не сохранять}';

    protected $description = 'Строки «• текст» в описаниях товаров превращает в список <ul><li>';

    /** Символы, которыми в импортированных текстах набраны маркеры списка. */
    private const BULLETS = ['•', '●', '‣', '◦', '·'];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $changed = 0;

        foreach (ProductContentBlock::orderBy('id')->get() as $block) {
            $result = $this->convert((string) $block->body);

            if ($result === (string) $block->body) {
                continue;
            }

            $changed++;
            $this->line('');
            $this->info("#{$block->id} — {$block->title} (товар {$block->product_id})");
            $this->line('  было:  '.$this->preview((string) $block->body));
            $this->line('  стало: '.$this->preview($result));

            if (! $dry) {
                $block->forceFill(['body' => $result])->saveQuietly();
            }
        }

        $this->line('');
        $this->info($dry
            ? "Изменилось бы блоков: $changed (запуск без --dry-run применит правку)"
            : "Изменено блоков: $changed");

        return self::SUCCESS;
    }

    /**
     * Разбирает текст на абзацы и внутри каждого собирает идущие подряд
     * строки-маркеры в отдельный список. Список выносится наружу абзаца:
     * <ul> внутри <p> недопустим, браузер всё равно закрыл бы абзац перед ним,
     * и разметка разъехалась бы.
     */
    public function convert(string $html): string
    {
        if (! $this->hasBullets($html)) {
            return $html;
        }

        $html = preg_replace('~<br\s*/?>~i', '<br>', $html);

        // Абзацы обрабатываем по одному, всё, что между ними (готовые <ul>,
        // заголовки, картинки), переносим в результат как есть.
        $out = preg_replace_callback(
            '~<p\b[^>]*>(.*?)</p>~is',
            fn (array $m) => $this->convertParagraph($m[1]),
            $html
        );

        // Текст без единого абзаца (голые строки с <br>) — тот же разбор,
        // только оборачивать в <p> нечего.
        if (! preg_match('~<p\b~i', $html)) {
            $out = $this->convertParagraph($html);
        }

        return $out;
    }

    private function convertParagraph(string $inner): string
    {
        $lines = preg_split('~<br>~i', $inner);
        $result = '';
        $paragraph = [];
        $items = [];

        $flushParagraph = function () use (&$paragraph, &$result) {
            // Отбивку в конце абзаца (пустые строки перед списком) убираем:
            // расстояние до списка задаёт его собственный отступ.
            while ($paragraph !== [] && $this->isBlank(end($paragraph))) {
                array_pop($paragraph);
            }

            if ($paragraph !== []) {
                $result .= '<p>'.implode('<br>', $paragraph).'</p>';
                $paragraph = [];
            }
        };

        $flushList = function () use (&$items, &$result) {
            if ($items !== []) {
                $result .= '<ul>'.implode('', array_map(fn ($i) => "<li>$i</li>", $items)).'</ul>';
                $items = [];
            }
        };

        // Пустая строка после списка становится пустым абзацем: у темы после
        // <ul> своего отступа нет, и у эталона зазор до следующего текста даёт
        // ровно такой <p>&nbsp;</p>.
        $blankAfterList = false;

        foreach ($lines as $line) {
            $item = $this->stripBullet($line);

            if ($item !== null) {
                $flushParagraph();
                $blankAfterList = false;
                $items[] = $item;

                continue;
            }

            if ($this->isBlank($line)) {
                // Отбивку перед списком выбрасываем — там отступ уже есть.
                if ($items !== []) {
                    $blankAfterList = true;

                    continue;
                }
                if ($paragraph === []) {
                    continue;
                }
            }

            $flushList();

            if ($blankAfterList) {
                $result .= '<p>&nbsp;</p>';
                $blankAfterList = false;
            }

            $paragraph[] = trim($line);
        }

        $flushList();
        $flushParagraph();

        return $result;
    }

    /** Строка-пункт: после маркера остаётся её содержимое, иначе null. */
    private function stripBullet(string $line): ?string
    {
        $trimmed = trim(html_entity_decode(strip_tags($line), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $trimmed = trim($trimmed, " \t\n\r\0\x0B\u{00A0}");

        $bullet = null;
        foreach (self::BULLETS as $candidate) {
            if (str_starts_with($trimmed, $candidate)) {
                $bullet = $candidate;
                break;
            }
        }

        if ($bullet === null) {
            return null;
        }

        // Убираем маркер из исходной строки, сохраняя её собственную разметку
        // (<strong>, <span> и прочее из редактора).
        $stripped = preg_replace(
            '~^(\s|&nbsp;|\x{00A0})*'.preg_quote($bullet, '~').'(\s|&nbsp;|\x{00A0})*~u',
            '',
            trim($line),
            1
        );

        return trim((string) $stripped) ?: null;
    }

    private function isBlank(string $line): bool
    {
        $text = trim(html_entity_decode(strip_tags($line), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return trim($text, " \t\n\r\0\x0B\u{00A0}") === '';
    }

    private function hasBullets(string $html): bool
    {
        foreach (self::BULLETS as $bullet) {
            if (str_contains($html, $bullet)) {
                return true;
            }
        }

        return false;
    }

    private function preview(string $html): string
    {
        return mb_substr(preg_replace('~\s+~', ' ', $html), 0, 260);
    }
}
