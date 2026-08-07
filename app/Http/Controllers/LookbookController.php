<?php

namespace App\Http\Controllers;

use App\Models\LookbookCollection;

class LookbookController extends Controller
{
    public function index()
    {
        $collections = LookbookCollection::active()->with('photos')->orderBy('sort_order')->get();

        return view('lookbook', [
            'collections' => $collections,
            'title' => 'Lookbook',
        ]);
    }
}
