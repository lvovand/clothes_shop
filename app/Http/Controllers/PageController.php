<?php

namespace App\Http\Controllers;

use App\Models\Page;

class PageController extends Controller
{
    public function show(string $slug)
    {
        $page = Page::where('slug', $slug)->where('is_active', true)->firstOrFail();

        $view = match ($page->template) {
            'about' => 'pages.about',
            'loyalty-card' => 'pages.loyalty-card',
            default => 'page',
        };

        return view($view, [
            'page' => $page,
            'title' => $page->meta_title ?: $page->title,
            'metaDescription' => $page->meta_description,
        ]);
    }
}
