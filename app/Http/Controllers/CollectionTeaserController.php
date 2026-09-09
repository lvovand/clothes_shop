<?php

namespace App\Http\Controllers;

use App\Models\Category;

/**
 * Страница ожидания коллекции: заголовок, баннер, обратный отсчёт до старта и
 * текст под ним. Живёт по своему адресу («Запуск коллекции» в карточке
 * категории) — отдельно от ссылки с промокодом, которую раздают блогерам.
 *
 * Отдельного маршрута нет: адрес произвольный, поэтому страница подхватывается
 * из PageController перед поиском обычной страницы.
 */
class CollectionTeaserController extends Controller
{
    public function show(Category $category)
    {
        abort_unless($category->is_active, 404);

        // Время пришло (или запуск отменили) — адрес не ломаем, а ведём в раздел:
        // ссылку успели раздать в сторис и рассылках.
        if (! $category->awaitsLaunch()) {
            return redirect()->to($category->url());
        }

        return view('collection-teaser', [
            'category' => $category,
            'title' => $category->teaser_title ?: $category->name,
            'metaDescription' => $category->meta_description,
            'metaRobots' => 'noindex, nofollow',
        ]);
    }
}
