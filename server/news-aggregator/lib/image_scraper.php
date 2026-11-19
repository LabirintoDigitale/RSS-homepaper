<?php
// lib/image_scraper.php

// Scarica al massimo $max_bytes byte di HTML, fermandosi comunque non
// appena trova la chiusura di </head> — i meta tag og:image/twitter:image
// che ci servono stanno sempre lì, non serve mai il resto della pagina.
//
// Perché non riusiamo semplicemente gnews_curl(): quella funzione ha un
// CURLOPT_TIMEOUT fisso di 10s pensato per le chiamate leggere verso le
// API di Google News, non per scaricare pagine articolo intere. Temi
// WordPress "pesanti" (es. il tema Newspaper di TagDiv usato da
// Bellunopress: sidebar, articoli correlati, tracking... pagina che può
// superare gli 800KB) rischiano di far scadere il timeout PRIMA che
// curl arrivi in fondo, anche se i tag che cerchiamo sono già arrivati
// nei primi KB — risultato: fetch_og_image() fallisce in silenzio e
// l'articolo resta senza immagine. Fermandoci a </head> (o a $max_bytes)
// evitiamo del tutto il problema, indipendentemente da quanto è
// grande/lenta la pagina completa.
function _scrape_html_head($url, $ua, $max_bytes = 300000, $timeout = 15) {
    $buffer = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: it-IT,it;q=0.9,en-US;q=0.8,en;q=0.7',
            'Connection: keep-alive',
        ],
        // CURLOPT_WRITEFUNCTION viene chiamata da curl ad ogni chunk
        // ricevuto: se il callback restituisce un numero di byte MINORE
        // di quelli passati, curl interpreta il download come interrotto
        // e si ferma subito, invece di aspettare l'intera risposta.
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buffer, $max_bytes) {
            $buffer .= $chunk;
            if (stripos($buffer, '</head>') !== false || strlen($buffer) >= $max_bytes) {
                return 0;
            }
            return strlen($chunk);
        },
    ]);
    curl_exec($ch);
    // curl_error() qui segnalerebbe quasi sempre "Failed writing body",
    // perché l'interruzione anticipata è voluta: non è un vero errore,
    // quindi non lo controlliamo — usiamo semplicemente $buffer.
    if (defined('DEBUG_HTTP_FETCH') && DEBUG_HTTP_FETCH) {
        error_log(sprintf(
            "[fetch_og_image DEBUG] url=%s bytes=%s has_head_close=%s",
            $url,
            strlen($buffer),
            (stripos($buffer, '</head>') !== false) ? 'yes' : 'no'
        ));
    }
    curl_close($ch);
    return $buffer;
}

// Legge il valore di un attributo (property=, name=, content=...) dentro un
// singolo tag <meta ...>, gestendo TUTTI e tre i modi validi in HTML5 di
// scrivere un valore: tra doppi apici, tra apici singoli, o senza apici
// (valido quando il valore non contiene spazi — molti plugin di
// minificazione HTML nella cache di WordPress tolgono gli apici superflui
// per risparmiare byte, es. <meta property=og:image content=https://...>).
// Senza gestire anche il caso "senza apici" si perdono silenziosamente
// tutte le immagini dei siti che minificano l'HTML in questo modo.
function _meta_attr_value($tag, $attr) {
    if (!preg_match('/\b' . preg_quote($attr, '/') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $m)) {
        return null;
    }
    foreach ([1, 2, 3] as $i) {
        if (isset($m[$i]) && $m[$i] !== '') {
            return $m[$i];
        }
    }
    return null;
}

// Cerca, fra tutti i tag <meta> dell'HTML dato, il primo il cui attributo
// $attr_name (di solito 'property' per og:*, 'name' per twitter:*) vale
// $target, e ne restituisce il 'content'.
function _find_meta_content($html, $target, $attr_name = 'property') {
    if (!preg_match_all('/<meta\s[^>]*>/i', $html, $tags)) {
        return '';
    }
    foreach ($tags[0] as $tag) {
        $val = _meta_attr_value($tag, $attr_name);
        if ($val !== null && strcasecmp($val, $target) === 0) {
            $content = _meta_attr_value($tag, 'content');
            if ($content !== null && $content !== '') {
                return $content;
            }
        }
    }
    return '';
}

function fetch_og_image($url, $ua) {
    if (!$url) {
        return '';
    }
    $html = _scrape_html_head($url, $ua);
    if (!$html) {
        if (function_exists('debug_log')) {
            debug_log("    fetch_og_image: _scrape_html_head ha restituito vuoto per $url");
        }
        return '';
    }

    $image = _find_meta_content($html, 'og:image', 'property');
    if ($image !== '') {
        if (function_exists('debug_log')) {
            debug_log("    fetch_og_image: match su og:image -> $image");
        }
        return html_entity_decode($image);
    }

    // fallback: twitter:image
    $image = _find_meta_content($html, 'twitter:image', 'name');
    if ($image !== '') {
        if (function_exists('debug_log')) {
            debug_log("    fetch_og_image: nessun og:image, match su twitter:image -> $image");
        }
        return html_entity_decode($image);
    }

    if (function_exists('debug_log')) {
        // Nessun match: logghiamo se il tag 'og:image' compare comunque da
        // qualche parte nell'HTML scaricato (magari con una sintassi/ordine
        // di attributi che le regex sopra non intercettano) e un pezzetto
        // di head, per capire ad occhio cosa il server ha davvero risposto.
        $has_literal = (stripos($html, 'og:image') !== false) ? 'sì (ma nessun tag <meta> valido lo contiene: markup insolito?)' : 'no, il tag non compare affatto in quello che abbiamo scaricato';
        $head_end = stripos($html, '</head>');
        $snippet = $head_end !== false ? substr($html, 0, min($head_end, 1500)) : substr($html, 0, 1500);
        debug_log("    fetch_og_image: NESSUNA immagine trovata per $url. 'og:image' presente nell'HTML scaricato: $has_literal");
        debug_log("    fetch_og_image: primi caratteri dell'head scaricato: " . preg_replace('/\s+/', ' ', $snippet));
    }

    return '';
}
