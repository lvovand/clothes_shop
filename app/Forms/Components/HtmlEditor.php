<?php

namespace App\Forms\Components;

use Filament\Forms\Components\Field;

/**
 * Визуальный редактор текста страниц (TinyMCE, лежит локально в public/vendor/tinymce).
 *
 * Зачем своё поле, а не штатный Filament RichEditor: тот работает на Trix, который
 * при сохранении выбрасывает незнакомые ему теги и классы — то есть первой же правкой
 * снёс бы перенесённую с эталона вёрстку (аккордеон карты лояльности, классы темы).
 * Здесь редактор настроен ничего не вычищать (см. blade-шаблон поля).
 */
class HtmlEditor extends Field
{
    protected string $view = 'forms.components.html-editor';

    /** Высота полотна в пикселях, ниже которой редактор не сжимается. */
    protected int $minHeight = 500;

    public function minHeight(int $pixels): static
    {
        $this->minHeight = $pixels;

        return $this;
    }

    public function getMinHeight(): int
    {
        return $this->minHeight;
    }
}
