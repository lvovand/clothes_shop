<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Приём картинок, вставленных прямо в текст страницы визуальным редактором.
 * Отвечает в формате, которого ждёт TinyMCE: {"location": "<адрес картинки>"}.
 */
class EditorUploadController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'image', 'max:8192'],
        ]);

        $path = $request->file('file')->store('pages/inline', 'public');

        return response()->json(['location' => asset('storage/'.$path)]);
    }
}
