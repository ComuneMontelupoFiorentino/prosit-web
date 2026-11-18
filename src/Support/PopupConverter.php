<?php
declare(strict_types=1);

namespace Prosit\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Popup QGIS (maptip): classificazione e conversione al linguaggio comune delle schede.
 *
 * Schema "record-card" (le vecchie pagine HTML complete con <style>):
 *   article.record-card > header (h1, small, badge) + .record-body
 *   .section-title + .field-row/.field-label/.field-value, <details>/<summary>
 * diventa:
 *   div.p-card > div.p-head (p-title, p-sub, label) + div.p-body
 *   details.p-section (summary) + .p-field/.p-label/.p-value
 *
 * Le espressioni QGIS [% ... %] vengono messe da parte prima di leggere l'HTML (contengono
 * virgolette anche dentro gli attributi) e rimesse identiche alla fine: la conversione non
 * tocca mai le espressioni.
 */
final class PopupConverter
{
    public const NUOVO = 'nuovo';
    public const DA_CONVERTIRE = 'record-card';
    public const TABELLA = 'tabelle/titoli';
    public const SCHEDA = 'scheda esterna';
    public const VUOTO = 'vuoto';
    public const ALTRO = 'altro';

    public static function classify(string $html): string
    {
        $t = trim($html);
        if ($t === '') {
            return self::VUOTO;
        }
        if (str_contains($t, 'p-card')) {
            return self::NUOVO;
        }
        if (preg_match('/class\s*=\s*["\'][^"\']*\brecord-card\b/i', $t)) {
            return self::DA_CONVERTIRE;
        }
        if (stripos($t, 'p-embed') !== false || stripos($t, '<iframe') !== false) {
            return self::SCHEDA;
        }
        return self::ALTRO;
    }

    /** Converte un popup "record-card"; null se lo schema non e' riconosciuto. */
    public static function convert(string $html): ?string
    {
        // 1. espressioni QGIS da parte
        $expr = [];
        $src = preg_replace_callback('/\[%.*?%\]/s', function ($m) use (&$expr) {
            $expr[] = $m[0];
            return 'PXQ' . (count($expr) - 1) . 'QXP';
        }, $html);

        // 2. via l'involucro di pagina: la scheda vive dentro il popup di Lizmap
        $src = preg_replace('/<!DOCTYPE[^>]*>/i', '', $src);
        $src = preg_replace('#<head\b.*?</head>#is', '', $src);
        $src = preg_replace('#</?(html|body|main)\b[^>]*>#i', '', $src);
        $src = preg_replace('#<(style|script)\b.*?</\1>#is', '', $src);
        $src = preg_replace('#<(link|meta)\b[^>]*>#i', '', $src);

        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?><div id="pxroot">' . $src . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new DOMXPath($doc);
        $root = $doc->getElementById('pxroot') ?? $xp->query('//div[@id="pxroot"]')->item(0);
        if (!$root) {
            return null;
        }
        $card = self::byClass($xp, 'record-card', $root);
        if (!$card) {
            return null;
        }

        $new = $doc->createElement('div');
        $new->setAttribute('class', 'p-card');

        // 3. testata
        $header = null;
        foreach ($card->childNodes as $c) {
            if ($c instanceof DOMElement && ($c->tagName === 'header' || self::hasClass($c, 'record-header'))) {
                $header = $c;
                break;
            }
        }
        if ($header) {
            $head = $doc->createElement('div');
            $head->setAttribute('class', 'p-head');
            $h1 = null;
            $small = null;
            foreach ($header->childNodes as $c) {
                if ($c instanceof DOMElement && !$h1 && preg_match('/^h[1-6]$/', $c->tagName)) {
                    $h1 = $c;
                } elseif ($c instanceof DOMElement && !$small && $c->tagName === 'small') {
                    $small = $c;
                }
            }
            $title = $doc->createElement('span');
            $title->setAttribute('class', 'p-title');
            if ($h1) {
                self::moveChildren($h1, $title);
            } else {
                // testata di solo testo: <header>NUMERI CIVICI</header>
                foreach (iterator_to_array($header->childNodes) as $c) {
                    if (!($c instanceof DOMElement)) {
                        $title->appendChild($c);
                    }
                }
            }
            $head->appendChild($title);
            if ($small) {
                $sub = $doc->createElement('span');
                $sub->setAttribute('class', 'p-sub');
                self::moveChildren($small, $sub);
                $head->appendChild($sub);
            }
            foreach (iterator_to_array($header->childNodes) as $c) {
                if ($c === $h1 || $c === $small || !($c instanceof DOMElement)) {
                    continue;
                }
                $head->appendChild($c);       // badge e altro: restano nella testata
            }
            $new->appendChild($head);
        }

        // 4. corpo: sezioni
        $bodySrc = self::byClass($xp, 'record-body', $card) ?? $card;
        $body = $doc->createElement('div');
        $body->setAttribute('class', 'p-body');
        self::buildBody($doc, $bodySrc, $body, $header);
        $new->appendChild($body);

        // 5. classi
        self::renameClasses($xp, $new);

        // 6. testo finale, espressioni rimesse
        $out = "<div class=\"p-card\">\n" . self::serialize($doc, $new) . "</div>\n";
        $out = preg_replace_callback('/PXQ(\d+)QXP/', fn ($m) => $expr[(int) $m[1]] ?? $m[0], $out);
        return $out;
    }

    /**
     * Popup "a tabelle" (schema piu' vecchio): titoli h1-h6 con <font>, tabelle etichetta/valore,
     * <details>, paragrafi "Etichetta: valore". Restituisce [html, espressioni tolte] o null.
     * Le espressioni tolte sono solo quelle che nascondevano una riga vuota
     * (style="display: [% if("x" is not null,'block','none') %]"): lo fa ora il foglio di stile.
     */
    public static function convertTables(string $html): ?array
    {
        $expr = [];
        $src = preg_replace_callback('/\[%.*?%\]/s', function ($m) use (&$expr) {
            $expr[] = $m[0];
            return 'PXQ' . (count($expr) - 1) . 'QXP';
        }, $html);
        $src = preg_replace('/<!DOCTYPE[^>]*>/i', '', $src);
        $src = preg_replace('#<(style|script)\b.*?</\1>#is', '', $src);
        $src = preg_replace('#<(link|meta|script)\b[^>]*/?>#i', '', $src);
        $src = preg_replace('#</?(html|head|body|main|font|center)\b[^>]*>#i', '', $src);
        $src = preg_replace('#</img>#i', '', $src);

        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?><div id="pxroot">' . $src . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new DOMXPath($doc);
        $root = $xp->query('//div[@id="pxroot"]')->item(0);
        if (!$root) {
            return null;
        }
        $dropped = [];
        $card = $doc->createElement('div');
        $card->setAttribute('class', 'p-card');
        $head = null;
        $body = $doc->createElement('div');
        $body->setAttribute('class', 'p-body');
        $state = ['title' => false, 'current' => null];

        $walk = function (DOMNode $container, DOMElement $target, bool $inSection) use (&$walk, $doc, &$head, $card, &$state, &$dropped, $expr) {
            foreach (iterator_to_array($container->childNodes) as $n) {
                if ($n->nodeType === XML_COMMENT_NODE) {
                    continue;
                }
                if ($n->nodeType === XML_TEXT_NODE) {
                    if (trim($n->textContent) !== '') {
                        $p = $doc->createElement('p');
                        $p->appendChild($doc->createTextNode(trim($n->textContent)));
                        ($inSection ? $target : ($state['current'] ?? $target))->appendChild($p);
                    }
                    continue;
                }
                if (!($n instanceof DOMElement)) {
                    continue;
                }
                $tag = strtolower($n->tagName);
                $dest = $inSection ? $target : ($state['current'] ?? $target);
                if (in_array($tag, ['br', 'hr'], true)) {
                    continue;
                }
                if (in_array($tag, ['div', 'section', 'article'], true) && !$n->hasAttribute('class')) {
                    $walk($n, $target, $inSection);
                    continue;
                }
                if (preg_match('/^h[1-6]$/', $tag)) {
                    $text = self::inlineHtml($doc, $n);
                    if (!$state['title'] && !$inSection) {
                        $state['title'] = true;
                        $head = $doc->createElement('div');
                        $head->setAttribute('class', 'p-head');
                        $t = $doc->createElement('span');
                        $t->setAttribute('class', 'p-title');
                        self::appendHtml($doc, $t, $text);
                        $head->appendChild($t);
                        continue;
                    }
                    if ($inSection) {
                        $h = $doc->createElement('div');
                        $h->setAttribute('class', 'p-h');
                        self::appendHtml($doc, $h, $text);
                        $target->appendChild($h);
                        continue;
                    }
                    $d = $doc->createElement('details');
                    $d->setAttribute('class', 'p-section');
                    $d->setAttribute('open', 'open');
                    $sum = $doc->createElement('summary');
                    self::appendHtml($doc, $sum, $text);
                    $d->appendChild($sum);
                    $target->appendChild($d);
                    $state['current'] = $d;
                    continue;
                }
                if ($tag === 'details') {
                    $d = $doc->createElement('details');
                    $d->setAttribute('class', 'p-section');
                    if ($n->hasAttribute('open')) {
                        $d->setAttribute('open', 'open');
                    }
                    $sum = $doc->createElement('summary');
                    $s = $xp_s = null;
                    foreach ($n->childNodes as $c) {
                        if ($c instanceof DOMElement && strtolower($c->tagName) === 'summary') {
                            $s = $c;
                            break;
                        }
                    }
                    self::appendHtml($doc, $sum, $s ? self::inlineHtml($doc, $s) : 'Dettagli');
                    $d->appendChild($sum);
                    if ($s) {
                        $n->removeChild($s);
                    }
                    $walk($n, $d, true);
                    $only = true;
                    foreach ($d->childNodes as $c) {
                        if ($c instanceof DOMElement && $c->tagName !== 'summary' && !self::hasClass($c, 'p-field')) {
                            $only = false;
                        }
                    }
                    if ($only) {
                        $d->setAttribute('class', 'p-section p-auto');
                    }
                    $target->appendChild($d);
                    $state['current'] = null;
                    continue;
                }
                if ($tag === 'table' || $tag === 'tbody' || $tag === 'thead') {
                    foreach (iterator_to_array($n->childNodes) as $tr) {
                        if (!($tr instanceof DOMElement)) {
                            continue;
                        }
                        $tt = strtolower($tr->tagName);
                        if ($tt === 'tbody' || $tt === 'thead') {
                            $walk($tr, $dest, true);
                            continue;
                        }
                        if ($tt !== 'tr') {
                            $walk(self::wrap($doc, $tr), $dest, true);
                            continue;
                        }
                        $cells = [];
                        foreach ($tr->childNodes as $td) {
                            if ($td instanceof DOMElement && in_array(strtolower($td->tagName), ['td', 'th'], true)) {
                                $cells[] = $td;
                            }
                        }
                        // stile della riga (spesso display: [% ... %] per mostrarla o no): si conserva,
                        // tranne il caso "nascondi se vuoto", che ora fa da solo il foglio di stile
                        $style = trim($tr->getAttribute('style'));
                        if (preg_match('/^display:\s*PXQ(\d+)QXP\s*;?$/', $style, $mm)
                            && preg_match("/^\\[%\\s*if\\s*\\(\\s*\"[^\"]+\"\\s+is\\s+not\\s+null\\s*,\\s*'block'\\s*,\\s*'none'\\s*\\)\\s*%\\]$/is", $expr[(int) $mm[1]] ?? '')) {
                            $dropped[] = (int) $mm[1];
                            $style = '';
                        }
                        if (count($cells) === 2) {
                            $f = self::field($doc, self::inlineHtml($doc, $cells[0], true), self::inlineHtml($doc, $cells[1]));
                            if ($style !== '') {
                                $f->setAttribute('style', $style);
                            }
                            $dest->appendChild($f);
                        } else {
                            foreach ($cells as $td) {
                                $h = trim(self::inlineHtml($doc, $td));
                                if ($h !== '') {
                                    $p = $doc->createElement('p');
                                    if ($style !== '') {
                                        $p->setAttribute('style', $style);
                                    }
                                    self::appendHtml($doc, $p, $h);
                                    $dest->appendChild($p);
                                }
                            }
                        }
                    }
                    continue;
                }
                if ($tag === 'p') {
                    // "Etichetta: valore" -> campo
                    $h = trim(self::inlineHtml($doc, $n));
                    if (preg_match('#^(?:<b>|<strong>)?\s*([^<:]{1,60}?)\s*:\s*(?:</b>|</strong>)?\s*(.*\S)\s*$#s', $h, $mm) && str_contains($mm[2], 'PXQ')) {
                        $dest->appendChild(self::field($doc, $mm[1], $mm[2]));
                        continue;
                    }
                    if ($h === '') {
                        continue;
                    }
                }
                $dest->appendChild($n);
            }
        };
        $walk($root, $body, false);
        if ($head) {
            $card->appendChild($head);
        }
        $card->appendChild($body);
        // colori e caratteri scritti a mano dentro titoli, sommari ed etichette: li decide il tema
        // (resta solo display, che puo' dipendere da un'espressione)
        $xp2 = new DOMXPath($doc);
        foreach ($xp2->query('.//*[@style]', $card) as $el) {
            $st = $el->getAttribute('style');
            if (stripos($st, 'display') === false) {
                $el->removeAttribute('style');
            } elseif (preg_match('/display:\s*[^;]+;?/i', $st, $dm)) {
                $el->setAttribute('style', trim($dm[0]));
            }
        }
        foreach ($xp2->query('.//*[@align or @color or @face or @size]', $card) as $el) {
            foreach (['align', 'color', 'face', 'size'] as $a) {
                $el->removeAttribute($a);
            }
        }

        $out = "<div class=\"p-card\">\n" . self::serialize($doc, $card) . "</div>\n";
        $out = preg_replace_callback('/PXQ(\d+)QXP/', fn ($m) => $expr[(int) $m[1]] ?? $m[0], $out);
        return [$out, array_map(fn ($i) => $expr[$i], $dropped)];
    }

    private static function field(DOMDocument $doc, string $label, string $value): DOMElement
    {
        $f = $doc->createElement('div');
        $f->setAttribute('class', 'p-field');
        $l = $doc->createElement('span');
        $l->setAttribute('class', 'p-label');
        self::appendHtml($doc, $l, rtrim(trim($label), " :\u{a0}"));
        $v = $doc->createElement('span');
        $v->setAttribute('class', 'p-value');
        self::appendHtml($doc, $v, trim($value));
        $f->appendChild($l);
        $f->appendChild($v);
        return $f;
    }

    /** HTML interno di un elemento, senza <b>/<strong> che avvolgono tutto (e senza tag se $text). */
    private static function inlineHtml(DOMDocument $doc, DOMElement $el, bool $text = false): string
    {
        $h = '';
        foreach ($el->childNodes as $c) {
            $h .= $doc->saveHTML($c);
        }
        $h = trim($h);
        if ($text) {
            return trim(preg_replace('/\s+/', ' ', strip_tags($h)));
        }
        while (preg_match('#^<(b|strong)>(.*)</\1>$#s', $h, $m) && !preg_match('#</?(b|strong)>#', $m[2])) {
            $h = trim($m[2]);
        }
        return $h;
    }

    private static function appendHtml(DOMDocument $doc, DOMElement $to, string $html): void
    {
        if ($html === '') {
            return;
        }
        $tmp = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $tmp->loadHTML('<?xml encoding="UTF-8"?><div id="pxfrag">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $frag = (new DOMXPath($tmp))->query('//div[@id="pxfrag"]')->item(0);
        if (!$frag) {
            $to->appendChild($doc->createTextNode($html));
            return;
        }
        foreach ($frag->childNodes as $c) {
            $to->appendChild($doc->importNode($c, true));
        }
    }

    private static function wrap(DOMDocument $doc, DOMElement $el): DOMElement
    {
        $d = $doc->createElement('div');
        $d->appendChild($el->cloneNode(true));
        return $d;
    }

    private static function buildBody(DOMDocument $doc, DOMNode $src, DOMElement $body, ?DOMNode $skip): void
    {
        $current = null;            // sezione aperta da un .section-title
        $created = [];
        foreach (self::flatten($src, $skip) as $n) {
            if ($n instanceof DOMElement && self::hasClass($n, 'section-title')) {
                $current = $doc->createElement('details');
                $current->setAttribute('class', 'p-section');
                $current->setAttribute('open', 'open');
                $sum = $doc->createElement('summary');
                self::moveChildren($n, $sum);
                $current->appendChild($sum);
                $body->appendChild($current);
                $created[] = $current;
                continue;
            }
            if ($n instanceof DOMElement && $n->tagName === 'details') {
                $current = null;
                $onlyFields = true;
                foreach ($n->childNodes as $c) {
                    if ($c instanceof DOMElement && $c->tagName !== 'summary' && !self::hasClass($c, 'field-row')) {
                        $onlyFields = false;
                    }
                }
                $n->setAttribute('class', trim(($n->getAttribute('class') ?: '') . ' p-section' . ($onlyFields ? ' p-auto' : '')));
                $body->appendChild($n);
                continue;
            }
            if ($n->nodeType === XML_COMMENT_NODE) {
                continue;               // i commenti dello schema vecchio non servono
            }
            if ($n->nodeType === XML_TEXT_NODE && trim($n->textContent) === '') {
                continue;
            }
            ($current ?? $body)->appendChild($n);
        }
        // sezioni fatte solo di campi: si nascondono quando sono tutti vuoti
        foreach ($created as $d) {
            $onlyFields = true;
            foreach ($d->childNodes as $c) {
                if ($c instanceof DOMElement && $c->tagName !== 'summary' && !self::hasClass($c, 'field-row')) {
                    $onlyFields = false;
                }
            }
            if ($onlyFields) {
                $d->setAttribute('class', 'p-section p-auto');
            }
        }
    }

    /** Figli in ordine, aprendo i contenitori senza significato (<section>, <div> senza classe). */
    private static function flatten(DOMNode $src, ?DOMNode $skip): array
    {
        $out = [];
        foreach (iterator_to_array($src->childNodes) as $c) {
            if ($c === $skip) {
                continue;
            }
            if ($c instanceof DOMElement && ($c->tagName === 'section' || $c->tagName === 'article')) {
                array_push($out, ...self::flatten($c, $skip));
                continue;
            }
            $out[] = $c;
        }
        return $out;
    }

    private static function renameClasses(DOMXPath $xp, DOMElement $scope): void
    {
        $map = [
            'field-row' => 'p-field', 'field-label' => 'p-label', 'field-value' => 'p-value',
            'bg-success' => 'label-success', 'bg-warning' => 'label-warning', 'bg-danger' => 'label-important',
            'bg-info' => 'label-info', 'bg-primary' => 'label-info', 'bg-secondary' => '',
        ];
        foreach ($xp->query('.//*[@class]', $scope) as $el) {
            $cls = preg_split('/\s+/', trim($el->getAttribute('class')));
            $isBadge = in_array('badge', $cls, true);
            $out = [];
            foreach ($cls as $c) {
                if ($isBadge && $c === 'badge') {
                    $out[] = 'label';
                    continue;
                }
                $out[] = array_key_exists($c, $map) ? $map[$c] : $c;
            }
            $el->setAttribute('class', trim(implode(' ', array_unique(array_filter($out, 'strlen')))));
            // valore: niente spazi ai bordi, cosi' un campo vuoto viene riconosciuto (e la riga si nasconde)
            if (in_array('p-value', $out, true)) {
                foreach ([$el->firstChild, $el->lastChild] as $t) {
                    if ($t && $t->nodeType === XML_TEXT_NODE) {
                        $t->nodeValue = $t === $el->firstChild ? ltrim($t->nodeValue) : rtrim($t->nodeValue);
                    }
                }
            }
            // il colore del titolo lo decide il tema
            if ($el->tagName !== 'span' && preg_match('/^\s*color:\s*white;?\s*$/i', $el->getAttribute('style'))) {
                $el->removeAttribute('style');
            }
        }
    }

    private static function serialize(DOMDocument $doc, DOMElement $card): string
    {
        $out = '';
        foreach ($card->childNodes as $part) {             // p-head, p-body
            $out .= '  ' . self::open($part) . "\n";
            foreach ($part->childNodes as $c) {
                if ($c->nodeType === XML_TEXT_NODE && trim($c->textContent) === '') {
                    continue;
                }
                if ($c instanceof DOMElement && $c->tagName === 'details') {
                    $out .= "\n    " . self::open($c) . "\n";
                    foreach ($c->childNodes as $cc) {
                        if ($cc->nodeType === XML_TEXT_NODE && trim($cc->textContent) === '') {
                            continue;
                        }
                        $out .= '      ' . trim((string) $doc->saveHTML($cc)) . "\n";
                    }
                    $out .= "    </details>\n";
                } else {
                    $out .= '    ' . trim((string) $doc->saveHTML($c)) . "\n";
                }
            }
            $out .= "  </div>\n";
        }
        return $out;
    }

    private static function open(DOMNode $el): string
    {
        $html = (string) $el->ownerDocument->saveHTML($el);
        return substr($html, 0, strpos($html, '>') + 1);
    }

    private static function byClass(DOMXPath $xp, string $class, DOMNode $ctx): ?DOMElement
    {
        $n = $xp->query('.//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]', $ctx)->item(0);
        return $n instanceof DOMElement ? $n : null;
    }

    private static function hasClass(DOMElement $el, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', trim($el->getAttribute('class'))), true);
    }

    private static function moveChildren(DOMNode $from, DOMNode $to): void
    {
        foreach (iterator_to_array($from->childNodes) as $c) {
            $to->appendChild($c);
        }
        // spazi ai bordi del titolo/sottotitolo
        if ($to->firstChild && $to->firstChild->nodeType === XML_TEXT_NODE) {
            $to->firstChild->nodeValue = ltrim($to->firstChild->nodeValue);
        }
        if ($to->lastChild && $to->lastChild->nodeType === XML_TEXT_NODE) {
            $to->lastChild->nodeValue = rtrim($to->lastChild->nodeValue);
        }
    }
}
