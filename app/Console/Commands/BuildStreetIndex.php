<?php

namespace App\Console\Commands;

use App\Services\Address\AddressSuggest;
use Illuminate\Console\Command;

/**
 * Собрать список улиц города заранее, чтобы первый же покупатель получил полные
 * подсказки. Обычно этого делать не нужно: индекс строится сам после первого
 * ввода адреса в этом городе и живёт 30 дней.
 */
class BuildStreetIndex extends Command
{
    protected $signature = 'app:build-street-index {city* : Названия городов, как они пишутся в адресе}';

    protected $description = 'Собрать индекс улиц города для подсказок адреса';

    public function handle(AddressSuggest $suggest): int
    {
        foreach ($this->argument('city') as $city) {
            $this->info("Собираю улицы: {$city}…");

            $streets = $suggest->buildIndex($city);

            if ($streets === []) {
                $this->error("  не получилось (см. laravel.log) или сборка уже идёт");

                continue;
            }

            $this->line('  улиц: '.count($streets));
        }

        return self::SUCCESS;
    }
}
