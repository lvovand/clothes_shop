<?php
/**
 * Локальный импорт выгруженных с прода контентных данных в sqlite,
 * чтобы сверять вёрстку на реальном каталоге. Временный файл разработки.
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$data = json_decode(file_get_contents('/home/al/www/ropaworld.ru/reference/prod-data.json'), true);

Schema::disableForeignKeyConstraints();
foreach ($data as $table => $rows) {
    if (!Schema::hasTable($table)) {
        echo "нет таблицы: $table\n";
        continue;
    }
    DB::table($table)->delete();
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table($table)->insert($chunk);
    }
    echo $table . ': ' . count($rows) . "\n";
}
Schema::enableForeignKeyConstraints();
