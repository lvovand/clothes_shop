<?php

namespace App\Support;

/**
 * Приведение текстов, приехавших из WordPress, к тому HTML, который отдавал донор.
 *
 * В WP поля Carbon Fields хранят «сырой» текст: абзацы разделены пустой строкой,
 * блочные теги проставлены руками, а в HTML их превращает wpautop() на выводе.
 * Здесь его порт — иначе списки и абзацы в описаниях товара выглядят не так, как
 * на эталоне (проверено побайтово на выводе донора).
 */
class LegacyHtml
{
    private const BLOCKS = '(?:table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|form|map|area|blockquote|address|style|p|h[1-6]|hr|fieldset|legend|section|article|aside|hgroup|header|footer|nav|figure|figcaption|details|menu|summary)';

    /**
     * Порт wpautop() из wp-includes/formatting.php (без обработки <pre> и шорткодов —
     * в контенте этого каталога их нет).
     */
    public static function autop(string $text, bool $br = true): string
    {
        if (trim($text) === '') {
            return '';
        }

        $blocks = self::BLOCKS;
        $text .= "\n";

        $text = preg_replace('|<br\s*/?>\s*<br\s*/?>|', "\n\n", $text);
        $text = preg_replace('!(<'.$blocks.'[\s/>])!', "\n\n$1", $text);
        $text = preg_replace('!(</'.$blocks.'>)!', "$1\n\n", $text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/\n\n+/", "\n\n", $text);

        $out = '';
        foreach (preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY) as $paragraph) {
            $out .= '<p>'.trim($paragraph, "\n")."</p>\n";
        }
        $text = $out;

        $text = preg_replace('|<p>\s*</p>|', '', $text);
        $text = preg_replace('!<p>([^<]+)</(div|address|form)>!', '<p>$1</p></$2>', $text);
        $text = preg_replace('!<p>\s*(</?'.$blocks.'[^>]*>)\s*</p>!', '$1', $text);
        $text = preg_replace('|<p>(<li.+?)</p>|', '$1', $text);
        $text = preg_replace('!<p><blockquote([^>]*)>!i', '<blockquote$1><p>', $text);
        $text = str_replace('</blockquote></p>', '</p></blockquote>', $text);
        $text = preg_replace('!<p>\s*(</?'.$blocks.'[^>]*>)!', '$1', $text);
        $text = preg_replace('!(</?'.$blocks.'[^>]*>)\s*</p>!', '$1', $text);

        if ($br) {
            $text = preg_replace('|(?<!<br />)\s*\n|', "<br />\n", $text);
        }

        $text = preg_replace('!(</?'.$blocks.'[^>]*>)\s*<br />!', '$1', $text);
        $text = preg_replace('!<br />(\s*</?(?:p|li|div|dl|dd|dt|th|pre|td|ul|ol)[^>]*>)!', '$1', $text);

        return preg_replace("|\n</p>$|", '</p>', $text);
    }

    /**
     * Восстанавливает исходный текст из того, что попало в БД первым импортом:
     * он прогонял поле через nl2br(e(...)), из-за чего теги описаний выводились
     * на странице как текст. Настоящие <br /> в исходнике были бы экранированы,
     * поэтому все неэкранированные <br /> перед переводом строки — от nl2br.
     */
    public static function undoEscaping(string $stored): string
    {
        $raw = preg_replace('~<br />(\r\n|\r|\n)~', '$1', $stored);

        return html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
