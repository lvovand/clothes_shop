<?php

namespace App\Console\Commands;

use App\Models\Page;
use Illuminate\Console\Command;

class ImportDonorPages extends Command
{
    protected $signature = 'app:import-donor-pages {--dry-run : только показать, что изменится}';

    protected $description = 'Заливает в текстовые страницы контент в разметке эталона (из database/data/donor-pages.json)';

    /**
     * Тексты страниц «Доставка и возврат», «Оферта», «Общие условия» лежали у нас
     * пересказом в три абзаца, а должны быть теми же, что у эталона, и в его же
     * разметке (.ft-block, .ft-block-boldTitle). JSON готовит
     * reference/tools/donor_pages.py: он берёт разметку донора дословно и меняет
     * бренд, домен и контакты на наши. Команда идемпотентна.
     */
    public function handle(): int
    {
        $path = database_path('data/donor-pages.json');
        if (! is_file($path)) {
            $this->error('нет файла '.$path);

            return self::FAILURE;
        }

        $pages = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $dry = (bool) $this->option('dry-run');

        foreach ($pages as $slug => $data) {
            $page = Page::where('slug', $slug)->first();
            if (! $page) {
                $this->warn("страница {$slug} не найдена — пропущена");

                continue;
            }

            $changes = [
                'title' => $data['title'],
                'breadcrumb_title' => $data['breadcrumb_title'] ?? null,
                'subtitle' => $data['subtitle'],
                'body' => $data['body'],
                'template' => $data['template'],
            ];

            $same = collect($changes)->every(fn ($value, $key) => $page->{$key} === $value);
            if ($same) {
                $this->line("{$slug}: уже актуально");

                continue;
            }

            $this->line(sprintf('%s: %d → %d символов%s', $slug, mb_strlen((string) $page->body),
                mb_strlen($data['body']), $dry ? ' (dry-run)' : ''));

            if (! $dry) {
                $page->update($changes);
            }
        }

        return self::SUCCESS;
    }
}
