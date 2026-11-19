<?php
// lib/gnews_decoder.php
//
// Risolve i link "news.google.com/rss/articles/CBMi..." nel loro URL
// reale (quello dell'editore). Google non fa più un redirect HTTP
// semplice: bisogna leggere una firma/timestamp dalla pagina
// dell'articolo e poi chiamare l'endpoint interno "batchexecute" di
// Google News. Metodo non ufficiale, basato su tecniche pubblicamente
// documentate (usate ad es. da vari decoder open source per Google
// News) — Google potrebbe cambiarlo in futuro senza preavviso.

// Attiva/disattiva il logging diagnostico dettagliato (status HTTP,
// lunghezza risposta, primi 300 caratteri) per OGNI richiesta fatta da
// gnews_curl(). Serve solo per debug temporaneo (es. capire perché
// fetch_og_image() non trova l'immagine su una fonte specifica): una
// volta risolto il problema, rimetti questa costante a false, altrimenti
// il log cresce inutilmente ad ogni batch.
if (!defined('DEBUG_HTTP_FETCH')) {
    define('DEBUG_HTTP_FETCH', true);
}

function gnews_curl($url, $ua, array $extra_opts = []) {
    // Header "da browser vero": curl di suo manda pochissimo (solo
    // User-Agent), e alcuni siti WordPress con plugin di sicurezza
    // trattano le richieste senza Accept/Accept-Language come sospette
    // (bot) restituendo una pagina diversa da quella vera, senza dare
    // nessun errore HTTP esplicito — da qui l'aggiunta di questi header.
    $default_headers = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'Accept-Language: it-IT,it;q=0.9,en-US;q=0.8,en;q=0.7',
        'Connection: keep-alive',
    ];

    // curl_setopt_array($ch, [...] + $extra_opts) fa vincere silenziosamente
    // la chiave di sinistra in caso di duplicati: se qualche chiamante
    // (es. gnews_decode_url, che passa il proprio CURLOPT_HTTPHEADER con
    // il content-type per la POST a batchexecute) passasse CURLOPT_HTTPHEADER
    // in $extra_opts, verrebbe scartato del tutto in favore dei default
    // sotto, invece di aggiungersi. Per questo qui uniamo esplicitamente i
    // due array di header PRIMA di passarli a curl_setopt_array(), così il
    // chiamante può aggiungere i propri header senza perdere quelli di base.
    if (isset($extra_opts[CURLOPT_HTTPHEADER])) {
        $extra_opts[CURLOPT_HTTPHEADER] = array_merge($default_headers, $extra_opts[CURLOPT_HTTPHEADER]);
    } else {
        $extra_opts[CURLOPT_HTTPHEADER] = $default_headers;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, $extra_opts + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body          = curl_exec($ch);
    $err           = curl_error($ch);
    $http_code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if (DEBUG_HTTP_FETCH) {
        $snippet = ($body !== false)
            ? substr(preg_replace('/\s+/', ' ', $body), 0, 300)
            : '(nessun body)';
        error_log(sprintf(
            "[gnews_curl DEBUG] url=%s http_code=%s bytes=%s effective_url=%s curl_err=%s snippet=%s",
            $url,
            $http_code,
            $body !== false ? strlen($body) : 'false',
            $effective_url,
            $err !== '' ? $err : '(nessuno)',
            $snippet
        ));
    }

    if ($body === false) {
        error_log("gnews_curl error for $url: $err");
        return null;
    }
    return $body;
}

// Estrae l'ID base64 dal link (.../articles/CBMi... oppure .../read/CBMi...)
function gnews_get_base64_id($url) {
    $parts = parse_url($url);
    if (empty($parts['host']) || strpos($parts['host'], 'news.google.com') === false) {
        return null;
    }
    if (empty($parts['path'])) {
        return null;
    }
    $segments = explode('/', trim($parts['path'], '/'));
    if (count($segments) < 2) {
        return null;
    }
    $last = end($segments);
    $prev = prev($segments);
    if (in_array($prev, ['articles', 'read'], true)) {
        return $last;
    }
    return null;
}

// Scarica la pagina dell'articolo su news.google.com e legge
// data-n-a-sg (firma) e data-n-a-ts (timestamp) dal tag <c-wiz><div ...>
function gnews_get_decoding_params($base64_str, $ua) {
    $url = "https://news.google.com/rss/articles/{$base64_str}";
    $html = gnews_curl($url, $ua);
    if (!$html) {
        return null;
    }

    // L'ordine degli attributi può variare, quindi proviamo entrambi.
    if (preg_match('/data-n-a-sg="([^"]+)"[^>]*data-n-a-ts="([^"]+)"/s', $html, $m)) {
        return ['sig' => $m[1], 'ts' => $m[2]];
    }
    if (preg_match('/data-n-a-ts="([^"]+)"[^>]*data-n-a-sg="([^"]+)"/s', $html, $m)) {
        return ['sig' => $m[2], 'ts' => $m[1]];
    }
    return null;
}

// Chiama l'endpoint interno batchexecute per farsi restituire l'URL reale
function gnews_decode_url($base64_str, $sig, $ts, $ua) {
    $inner = json_encode([
        "garturlreq",
        [
            ["X", "X", ["X", "X"], null, null, 1, 1, "IT:it", null, 1, null, null, null, null, null, 0, 1],
            "X", "X", 1, [1, 1, 1], 1, 1, null, 0, 0, null, 0,
        ],
        $base64_str,
        (int) $ts,
        $sig,
    ]);

    $payload = json_encode([[["Fbv4je", $inner]]]);
    $postfields = 'f.req=' . rawurlencode($payload);

    $resp = gnews_curl(
        "https://news.google.com/_/DotsSplashUi/data/batchexecute",
        $ua,
        [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => $postfields,
            CURLOPT_HTTPHEADER => ['content-type: application/x-www-form-urlencoded;charset=UTF-8'],
        ]
    );
    if (!$resp) {
        return null;
    }

    // Rimuove il prefisso anti-hijacking ")]}'"
    $resp = preg_replace('/^\)\]\}\'\s*/', '', $resp);

    // La risposta è una serie di blocchi "<lunghezza>\n<json>\n...".
    // Cerchiamo direttamente il blocco che contiene "Fbv4je" e la sua
    // stringa JSON annidata (è una stringa JSON dentro la stringa JSON).
    if (!preg_match('/\[\s*"wrb\.fr"\s*,\s*"Fbv4je"\s*,\s*(".*?")\s*,\s*null/s', $resp, $m)) {
        return null;
    }

    $inner_json_str = json_decode($m[1]);
    if ($inner_json_str === null) {
        return null;
    }

    $decoded = json_decode($inner_json_str, true);
    if (is_array($decoded) && isset($decoded[1]) && filter_var($decoded[1], FILTER_VALIDATE_URL)) {
        return $decoded[1];
    }
    return null;
}

// Funzione principale: da link Google News a URL reale dell'editore.
// Ritorna null se qualsiasi passaggio fallisce (non blocca lo script).
function gnews_resolve($gnews_link, $ua) {
    $base64_str = gnews_get_base64_id($gnews_link);
    if (!$base64_str) {
        return null;
    }
    $params = gnews_get_decoding_params($base64_str, $ua);
    if (!$params) {
        return null;
    }
    return gnews_decode_url($base64_str, $params['sig'], $params['ts'], $ua);
}
