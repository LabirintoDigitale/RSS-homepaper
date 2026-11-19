<?php
// rebuild_cache.php (versione incrementale a batch)
//
// Invece di scaricare/decodificare/scrapare TUTTI gli articoli di TUTTE
// le fonti in un'unica chiamata lunga, questo script processa solo un
// piccolo lotto (BATCH_SIZE articoli, default 2) ad ogni chiamata, e si
// ricorda il punto in cui era arrivato in cache/state.json.
//
// cache/news.json (quello letto da feed.php e da Home Assistant) viene
// riscritto SOLO quando un intero ciclo è completo (tutte le fonti,
// tutti gli articoli): Home Assistant continua sempre a vedere un
// elenco completo e coerente, mai a metà.
//
// Lancialo spesso (es. ogni 1-2 minuti), da CLI o via URL:
//
//  A) da riga di comando:
//     */2 * * * * php /percorso/news-aggregator/rebuild_cache.php >> /percorso/news-aggregator/cache/rebuild.log 2>&1
//
//  B) via HTTP (router/NAS/cron esterno che sa solo chiamare un URL):
//     */2 * * * * curl -s "http://192.168.1.249/news-aggregator/rebuild_cache.php?token=IL_TUO_TOKEN&batch=2" > /dev/null 2>&1
//
// Puoi cambiare quanti articoli processare per chiamata con ?batch=N
// (via URL) — da CLI usa invece la costante DEFAULT_BATCH_SIZE qui sotto.

define('DEFAULT_BATCH_SIZE', 2);

// Usato solo quando si forza il refresh di UNA fonte specifica (force_source):
// vogliamo processarla per intero in un solo colpo. Da quando il tetto
// max_items per-fonte è stato rimosso (si scaricano tutti gli item del
// feed), non sappiamo più in anticipo quanti item avrà — usiamo quindi un
// tetto fisso abbastanza ampio da coprire la stragrande maggioranza dei
// feed RSS/Atom reali in circolazione.
define('FORCE_SOURCE_BATCH_SIZE', 200);

// Quando una notizia Google News scelta come "principale" non ha
// un'immagine recuperabile, proviamo a recuperarla da al massimo questo
// numero di fonti correlate (stesso cluster di notizia, testate diverse)
// prima di arrenderci e lasciare l'articolo senza immagine. Ogni tentativo
// costa una risoluzione di redirect + uno scraping og:image, quindi un
// valore alto rallenta il batch — 3 è un buon compromesso.
define('MAX_RELATED_IMAGE_TRIES', 3);

// Build di QUESTO file: la aggiorno io ad ogni modifica che ti mando (non va
// toccata a mano, e non dipende da settings.php). Serve solo a verificare al
// volo, dalla risposta JSON, se il file effettivamente in esecuzione sul
// server è quello nuovo o una versione precedente rimasta in giro.
define('SCRIPT_BUILD', '2026-11-19-01');

$settings = require __DIR__ . '/settings.php';
define('API_TOKEN', $settings['api_token']);
define('APP_VERSION', $settings['cron_version'] ?? 'unknown');

set_time_limit(0);
ignore_user_abort(true);
error_reporting(E_ALL & ~E_DEPRECATED);

$is_web = (php_sapi_name() !== 'cli');

if ($is_web) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-App-Version: ' . APP_VERSION . ' (build ' . SCRIPT_BUILD . ')');
    if (!isset($_GET['token']) || !hash_equals(API_TOKEN, $_GET['token'])) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden', 'version' => APP_VERSION, 'build' => SCRIPT_BUILD]);
        exit;
    }
}

function respond($data, $is_web) {
    $data['version'] = APP_VERSION;
    $data['build'] = SCRIPT_BUILD;
    if (DEBUG_ENABLED) {
        $data['debug_log'] = 'cache/debug.log';
    }
    if ($is_web) {
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    } else {
        echo "[v" . APP_VERSION . " build " . SCRIPT_BUILD . "] " . ($data['status'] ?? json_encode($data)) . "\n";
    }
    exit;
}

$batch_size = $is_web && isset($_GET['batch'])
    ? max(1, (int) $_GET['batch'])
    : DEFAULT_BATCH_SIZE;

// ?debug=1 -> scrive un log dettagliato, passo per passo, in cache/debug.log
// (sovrascritto ad ogni run con debug=1, per non farlo crescere all'infinito).
// Utile per capire ESATTAMENTE cosa succede su una fonte specifica: cosa ha
// restituito il fetch del feed, quale ramo di codice ha scelto per
// l'immagine (enclosure/media/thumb/scraping), e l'esito di ogni scraping
// og:image (byte scaricati, se ha trovato </head>, se un match regex ha
// avuto successo) — molto più preciso del solo error_log() di sistema, che
// mischia tutte le fonti insieme e non mostra i dettagli del match regex.
define('DEBUG_ENABLED', $is_web && (($_GET['debug'] ?? '') === '1' || strtolower((string) ($_GET['debug'] ?? '')) === 'true'));

function debug_log(string $line): void {
    if (!DEBUG_ENABLED) {
        return;
    }
    @file_put_contents(DEBUG_LOG_FILE, '[' . date('H:i:s') . '] ' . $line . "\n", FILE_APPEND);
}

// ?force_source=NOME -> invece di rispettare il cursore normale del ciclo
// (state.json), salta subito alla fonte con questo 'name' in config.php,
// ne riscarica il feed da zero (ignora eventuali articoli già bufferizzati
// per quella fonte in questo ciclo) e la processa per intero in un'unica
// chiamata. Non tocca le altre fonti né il resto del buffer del ciclo in
// corso: serve solo a "forzare" e testare subito UNA fonte, senza aspettare
// che il cursore normale ci arrivi da solo (che con batch=2 può richiedere
// molti minuti). Combinalo con debug=1 per vedere subito cosa succede.
$force_source = $is_web ? trim((string) ($_GET['force_source'] ?? '')) : '';

define('BASE_DIR', __DIR__);
define('CACHE_FILE', BASE_DIR . '/cache/news.json');
define('STATE_FILE', BASE_DIR . '/cache/state.json');
define('LOCK_FILE', BASE_DIR . '/cache/rebuild.lock');
define('DEBUG_LOG_FILE', BASE_DIR . '/cache/debug.log');
define('ERROR_LOG_FILE', BASE_DIR . '/cache/errors.log');
// Tetto dimensione per errors.log: a differenza di debug.log (sovrascritto
// ad ogni run con debug=1), errors.log si ACCUMULA nel tempo — a ogni run
// normale del cron, in silenzio — proprio perché deve sopravvivere anche
// alle chiamate senza debug=1. Senza un tetto, una fonte che fallisce in
// modo sistematico potrebbe farlo crescere indefinitamente.
define('ERROR_LOG_MAX_BYTES', 2 * 1024 * 1024); // 2 MB

// Log SEMPRE ATTIVO (a differenza di debug_log(), che scrive solo con
// ?debug=1) per gli errori catturati durante l'elaborazione di un
// articolo: così anche un crash avvenuto durante una normale chiamata di
// cron (il cui output va su /dev/null, quindi altrimenti invisibile)
// lascia una traccia consultabile in cache/errors.log. Si ACCODA (non
// sovrascrive) run dopo run, con un tetto dimensionale: se il file supera
// ERROR_LOG_MAX_BYTES, lo tronchiamo tenendo solo la metà più recente
// invece di lasciarlo crescere all'infinito.
function log_item_error(string $sourceName, string $title, string $link, string $errMsg): void {
    $line = sprintf(
        "[%s] fonte=%s articolo=\"%s\" link=%s\n  errore: %s\n",
        date('Y-m-d H:i:s'),
        $sourceName,
        $title,
        $link,
        $errMsg
    );
    if (file_exists(ERROR_LOG_FILE) && filesize(ERROR_LOG_FILE) > ERROR_LOG_MAX_BYTES) {
        $existing = (string) file_get_contents(ERROR_LOG_FILE);
        $existing = substr($existing, (int) (strlen($existing) / 2));
        // Riparte dal prossimo confine di voce, non a metà di una riga.
        $cut = strpos($existing, "\n[");
        if ($cut !== false) {
            $existing = substr($existing, $cut + 1);
        }
        @file_put_contents(ERROR_LOG_FILE, $existing . "...[log troncato, cresciuto oltre " . ERROR_LOG_MAX_BYTES . " byte]...\n");
    }
    @file_put_contents(ERROR_LOG_FILE, $line, FILE_APPEND);
}

// Rete di sicurezza per errori che il try/catch NON può intercettare:
// try/catch cattura solo Throwable (eccezioni ed Error "normali"), ma un
// vero fatal error di PHP (funzione indefinita, sintassi rotta in un file
// incluso, esaurimento memoria...) termina il processo direttamente, senza
// passare da nessun catch. register_shutdown_function() gira comunque,
// SEMPRE, anche in quel caso: error_get_last() ci dice cos'è successo, e
// lo scriviamo in errors.log invece di lasciare che il crash sparisca nel
// nulla di /dev/null (l'output del cron).
function global_fatal_shutdown_handler(): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $line = sprintf(
            "[%s] *** CRASH GLOBALE (non catturabile da try/catch) ***\n  %s in %s:%d\n",
            date('Y-m-d H:i:s'),
            $err['message'],
            $err['file'],
            $err['line']
        );
        @file_put_contents(ERROR_LOG_FILE, $line, FILE_APPEND);
    }
}
register_shutdown_function('global_fatal_shutdown_handler');

// Scrive SUBITO in cache.json gli articoli appena raccolti per UNA fonte,
// sostituendo solo le sue vecchie voci e lasciando invariate quelle di
// tutte le altre fonti (non ancora ripassate in questo ciclo).
//
// Perché serve: prima, cache.json veniva riscritto SOLO a fine ciclo
// completo (quando TUTTE le fonti erano state ripassate). Con batch=2 e un
// numero di fonti consistente, un ciclo completo può richiedere molti
// minuti; da quando il tetto max_items per-fonte è stato rimosso, una
// singola fonte può avere 50+ articoli reali, e un ciclo completo su tutte
// le fonti può arrivare a richiedere ore. Risultato: il popup delle
// statistiche (aggiornato batch per batch) mostrava già "42/50" per una
// fonte, ma la card continuava a mostrarne solo 15 (i vecchi, dell'ultimo
// ciclo completato) perché cache.json non veniva ancora toccato.
// Ora, appena una fonte esaurisce i suoi articoli in QUESTO ciclo, i suoi
// risultati vengono scritti subito: la card li vede al prossimo refresh,
// senza dover aspettare che anche le altre fonti finiscano il loro giro.
function flush_source_to_cache(string $sourceName, array $newArticles): void {
    $existing = [];
    if (file_exists(CACHE_FILE)) {
        $decoded = json_decode((string) file_get_contents(CACHE_FILE), true);
        if (is_array($decoded) && isset($decoded['articles']) && is_array($decoded['articles'])) {
            $existing = $decoded['articles'];
        }
    }
    // Tiene tutte le voci di ALTRE fonti così come sono; sostituisce solo
    // quelle della fonte appena completata con i risultati freschi di
    // questo ciclo.
    $kept = array_values(array_filter(
        $existing,
        fn($a) => ($a['source'] ?? '') !== $sourceName
    ));
    $merged = array_merge($kept, $newArticles);

    $payload = json_encode(
        ['articles' => $merged, 'updated' => date('c'), 'version' => APP_VERSION],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($payload === false) {
        // Non blocchiamo il ciclo per un encode fallito qui: il flush di
        // fine ciclo completo (più avanti) ha già la sua gestione esplicita
        // dell'errore e resta la rete di sicurezza finale.
        debug_log("flush_source_to_cache('$sourceName'): json_encode fallito, salto questo flush parziale.");
        return;
    }
    @file_put_contents(CACHE_FILE, $payload);
}
// Scritto da block_paths.php quando l'utente, dalla card, tiene premuto su
// una notizia e sceglie "Blocca". Contiene un array di pattern (semplici
// sottostringhe, es. "/articoli/"): ogni link di feed che le contiene viene
// scartato PRIMA di essere arricchito (niente scraping og:image sprecato,
// niente traduzione). Se il file non esiste, nessun filtro è attivo.
define('BLOCKED_PATHS_FILE', BASE_DIR . '/cache/blocked_paths.json');

require BASE_DIR . '/lib/gnews_decoder.php';
require BASE_DIR . '/lib/image_scraper.php';
require BASE_DIR . '/lib/topics.php';
// Traduzione: cache permanente, interruttore dopo un 429, tetti di richieste.
// Se il file manca il cron funziona comunque (senza tradurre) e lo scrive in errors.log.
if (is_file(BASE_DIR . '/lib/translate_cache.php')) {
    require BASE_DIR . '/lib/translate_cache.php';
} else {
    function tc_translate(string $text, string $ua, array $opts = []): array {
        return ['translated' => $text, 'detected_lang' => null, 'source' => 'missing_module',
                'error' => 'lib/translate_cache.php mancante: copialo nella cartella lib/ accanto a gnews_decoder.php', 'logworthy' => true];
    }
    function tc_run_stats(): array { return []; }
}

/**
 * Legge cache/blocked_paths.json (scritto da block_paths.php) e restituisce
 * il semplice elenco di pattern-stringa da escludere. Fail-safe: qualunque
 * problema (file assente, JSON corrotto) restituisce un array vuoto invece
 * di far fallire l'intero rebuild.
 */
function load_blocked_patterns(): array {
    if (!file_exists(BLOCKED_PATHS_FILE)) {
        return [];
    }
    $data = json_decode((string) file_get_contents(BLOCKED_PATHS_FILE), true);
    if (!is_array($data)) {
        return [];
    }
    $patterns = [];
    foreach ($data as $entry) {
        $p = trim((string) ($entry['pattern'] ?? ''));
        if ($p !== '') {
            $patterns[] = $p;
        }
    }
    return $patterns;
}

/** true se $link contiene almeno uno dei pattern bloccati (sottostringa semplice). */
function is_link_blocked(string $link, array $patterns): bool {
    foreach ($patterns as $p) {
        if ($p !== '' && stripos($link, $p) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Le fonti "google_news" restituiscono spesso una <description> che è in
 * realtà una piccola lista HTML di copertura dello stesso fatto da più
 * testate, tipo:
 *
 *   <ol><li><a href="...">Titolo 1</a>&nbsp;&nbsp;<font>Testata 1</font></li>
 *       <li><a href="...">Titolo 2</a>&nbsp;&nbsp;<font>Testata 2</font></li>
 *   ...</ol>
 *
 * Se la processiamo con un semplice strip_tags() perdiamo la struttura e
 * tutto finisce incollato in un unico blocco illeggibile. Qui invece
 * intercettiamo i singoli <li> PRIMA di buttare via i tag, e restituiamo:
 *   - 'description': il titolo/testo del primo elemento (quello "principale")
 *   - 'related':      array di ['title' => ..., 'source' => ..., 'link' => ...]
 *                      per OGNI voce della lista (compresa la prima), così la
 *                      card (o qualunque altro consumer) può decidere in
 *                      seguito se e come mostrare le altre testate — il
 *                      'link' di ciascuna serve anche a questo script per
 *                      andare a recuperare un'immagine dalle fonti correlate
 *                      quando quella principale non ne ha una.
 *
 * Se la description non ha questa struttura (fonte non-Google, o Google
 * News senza cluster), si comporta come prima: semplice strip_tags.
 */
// Immagine di copertina dentro l'HTML di un feed (<summary>, <description>, <content:encoded>).
// Molti feed non hanno <enclosure> né media:, ma mettono la copertina come <img> all'inizio del riassunto
// (l'Atom di spaziogames: <img class="webfeedsFeaturedVisual" ...>). Preferisce l'immagine "featured";
// scarta icone, pixel di tracciamento, emoji, avatar e SVG; legge anche le immagini caricate in ritardo (data-src).
// Ritorna un URL http(s) assoluto oppure ''.
function feed_html_image(string $html, string $base = ''): string {
    if ($html === '' || stripos($html, '<img') === false) return '';
    if (!preg_match_all('/<img\b[^>]*>/i', $html, $tags)) return '';
    $attr = function (string $tag, string $name): string {
        if (!preg_match('/(?<![\w-])' . preg_quote($name, '/') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $a)) return '';
        foreach ([1, 2, 3] as $i) if (isset($a[$i]) && $a[$i] !== '') return trim(html_entity_decode($a[$i], ENT_QUOTES | ENT_HTML5));
        return '';
    };
    $bad = ['pixel', 'tracker', 'tracking', 'beacon', 'emoji', 'gravatar', 'avatar', 'spacer', 'blank.gif', '1x1', 'doubleclick', 'feedburner', '/ads/', 'smilies'];
    $first = '';
    foreach ($tags[0] as $tag) {
        $src = '';
        foreach (['src', 'data-src', 'data-lazy-src', 'data-original'] as $n) {
            $v = $attr($tag, $n);
            if ($v !== '' && stripos($v, 'data:') !== 0) { $src = $v; break; }
        }
        if ($src === '') continue;
        $w = $attr($tag, 'width'); $h = $attr($tag, 'height');
        if (($w !== '' && ctype_digit($w) && (int) $w <= 64) || ($h !== '' && ctype_digit($h) && (int) $h <= 64)) continue;   // icona / pixel
        $low = strtolower($src);
        foreach ($bad as $b) if (strpos($low, $b) !== false) continue 2;
        if (preg_match('/\.svg(\?|$)/', $low)) continue;
        // assoluto
        if (strpos($src, '//') === 0) $src = 'https:' . $src;
        elseif (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $src) && !preg_match('~^https?://~i', $src)) continue;    // javascript:, data:, mailto:...: mai
        elseif (!preg_match('~^https?://~i', $src)) {
            $b = parse_url($base);
            if (!$b || empty($b['host']) || !isset($b['scheme'])) continue;
            $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
            if ($src[0] === '/') $src = $origin . $src;
            else { $dir = isset($b['path']) ? substr($b['path'], 0, (int) strrpos($b['path'], '/') + 1) : '/'; $src = $origin . ($dir !== '' ? $dir : '/') . $src; }
        }
        if (!preg_match('~^https?://[^\s]+$~i', $src) || strlen($src) > 2000) continue;
        if (preg_match('/class\s*=\s*["\'][^"\']*(featured|wp-post-image)/i', $tag)) return $src;     // la copertina dichiarata come tale
        if ($first === '') $first = $src;
    }
    return $first;
}

function parse_gnews_description(string $desc_html): array {
    $clean = function (string $s): string {
        // decodifica entità, rimuove eventuali tag residui, normalizza gli
        // spazi (incluso il non-breaking space \xC2\xA0 che Google usa come
        // separatore tra titolo e testata) e i trattini/pallini iniziali.
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5);
        $s = strip_tags($s);
        $s = str_replace("\xC2\xA0", ' ', $s); // &nbsp; -> spazio normale
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = trim($s, " \t\n\r\0\x0B-–—•");
        return trim($s);
    };

    $related = [];

    if (stripos($desc_html, '<li') !== false) {
        if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $desc_html, $li_matches)) {
            foreach ($li_matches[1] as $li) {
                $title = '';
                $link = '';
                if (preg_match('/<a[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is', $li, $a_match)) {
                    $link = html_entity_decode($a_match[1], ENT_QUOTES | ENT_HTML5);
                    $title = $clean($a_match[2]);
                    // resto della riga (dopo il link) = nome testata
                    $rest = preg_replace('/<a[^>]*>.*?<\/a>/is', '', $li);
                    $source = $clean($rest);
                } else {
                    // nessun link dentro il <li>: teniamo tutto come titolo
                    $title = $clean($li);
                    $source = '';
                }
                if ($title !== '') {
                    $related[] = ['title' => $title, 'source' => $source, 'link' => $link];
                }
            }
        }
    }

    if (!empty($related)) {
        return [
            'description' => $related[0]['title'],
            'related'     => $related,
        ];
    }

    // Fallback: descrizione "normale", nessun cluster multi-testata
    return [
        'description' => $clean($desc_html),
        'related'     => [],
    ];
}

/**
 * Traduce un testo in italiano rilevandone la lingua (servizio non ufficiale
 * di Google Translate, client=gtx: può cambiare o bloccarsi senza preavviso).
 *
 * La logica vera sta in lib/translate_cache.php:
 *   - cache permanente: ogni testo si traduce UNA volta sola. Prima, ad ogni
 *     ciclo si ritraducevano tutti gli articoli da capo: migliaia di richieste
 *     al giorno dallo stesso indirizzo, finché il servizio ha risposto 429
 *     (troppe richieste) a quasi ogni chiamata, per giorni;
 *   - i titoli evidentemente italiani non chiedono nulla al servizio (prima
 *     serviva una chiamata per ogni articolo solo per scoprire la lingua);
 *   - dopo un 429/403 si smette di chiamare per 15 minuti, poi 30, 60... fino
 *     a 3 ore; più un tetto di richieste all'ora e per esecuzione;
 *   - dove tradurre si sceglie nell'editor della card (cache/translate_settings.json):
 *     Google (quello di sempre) oppure un'AI locale Ollama, senza limiti di
 *     richieste ma con un tetto di tempo per esecuzione (TC_OLLAMA_RUN_BUDGET).
 *
 * Fail-safe come prima: qualunque problema restituisce il testo originale con
 * lingua null, così il chiamante non mostra una traduzione che non c'è.
 *
 * Ritorna: ['translated' => testo tradotto, 'detected_lang' => es. 'en' | 'it']
 *      oppure ['translated' => testo originale, 'detected_lang' => null].
 */
function gtranslate_auto_to_it(string $text, string $ua): array {
    static $logged = false;
    $r = tc_translate($text, $ua, ['retry_transient' => false]);
    if ($r['error'] !== null && function_exists('debug_log')) {
        debug_log("    traduzione non eseguita ({$r['source']}): {$r['error']}");
    }
    // In errors.log solo i VERI errori del servizio (e una volta sola per esecuzione):
    // le pause dell'interruttore e i tetti sono volute e non vanno segnalate ad ogni giro.
    if ($r['logworthy'] && !$logged) {
        $logged = true;
        log_item_error('(traduzione)', mb_substr(trim($text), 0, 60, 'UTF-8'), '', $r['error']);
    }
    return ['translated' => $r['translated'], 'detected_lang' => $r['detected_lang']];
}

// Contatori delle traduzioni di questa esecuzione, per la risposta JSON
// (null se non è successo nulla). Dice a colpo d'occhio quante chiamate al
// servizio sono state fatte e quante risparmiate: cache, italiano riconosciuto
// in locale, pausa dell'interruttore, tetti.
function translation_stats_for_response(): ?array {
    $s = tc_run_stats();
    if (!$s) return null;
    $tot = ($s['service'] ?? 0) + ($s['ollama'] ?? 0) + ($s['cache'] ?? 0) + ($s['local_it'] ?? 0) + ($s['cooldown'] ?? 0) + ($s['budget'] ?? 0) + ($s['errors'] ?? 0);
    if ($tot === 0) return null;
    if (empty($s['first_error'])) unset($s['first_error']);
    return $s;
}

// Come si presenta lo script ai siti. Un browser vero scrive la versione per intero ("151.0.0.0") e i firewall anti-bot
// controllano sia il formato sia che la versione non sia vecchia: "Chrome/124.0" (2024, e scritto in modo non standard)
// veniva rifiutato con HTTP 403 da alcuni siti (visto con spaziogames.it). Da aggiornare ogni tanto: 151 = luglio 2026.
$UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36';

if (!is_dir(BASE_DIR . '/cache')) {
    @mkdir(BASE_DIR . '/cache', 0775, true);
}

if (!is_writable(BASE_DIR . '/cache')) {
    respond([
        'error' => 'La cartella cache/ non è scrivibile da PHP. Verifica proprietario/permessi (es. chmod 775 cache, o chown verso l\'utente del webserver).',
        'cache_dir' => BASE_DIR . '/cache',
    ], $is_web);
}

// Evita run sovrapposti. Usiamo flock() invece di un controllo mtime perché
// quest'ultimo è soggetto a race condition (TOCTOU): due richieste quasi
// simultanee possono entrambe superare il controllo "il lock non c'è / è
// vecchio" prima che una delle due riesca a scrivere il file, finendo per
// eseguire in parallelo e corrompersi a vicenda lo stato (state.json). Con
// flock(LOCK_EX | LOCK_NB) l'acquisizione è atomica a livello di kernel: un
// solo processo alla volta può proseguire, gli altri escono subito.
$lock_fp = fopen(LOCK_FILE, 'c');
if (!$lock_fp || !flock($lock_fp, LOCK_EX | LOCK_NB)) {
    respond(['status' => 'Batch precedente ancora in corso, salto questo giro.'], $is_web);
}
// Il lock resta acquisito per tutta la durata dello script: si rilascia da
// solo alla chiusura del processo (fclose implicito a fine script, o alla
// exit() dentro respond()), quindi non serve più @unlink(LOCK_FILE) nei
// punti di uscita — anzi, cancellare il file mentre un altro processo lo sta
// aspettando in flock() può causare comportamenti indefiniti su alcuni
// filesystem. Lo lasciamo in giro: il contenuto non conta, conta solo il
// lock che ci gira sopra.

// Pulizia delle traduzioni: a ogni giro si controlla una delle 256 cartelle di
// cache/translations (scelta a caso) e si tolgono le voci non usate da più di
// TC_ENTRY_TTL (21 giorni). Con un giro ogni 2 minuti ogni cartella viene
// controllata in media 2-3 volte al giorno, invece di una volta ogni 1-2 mesi
// della sola pulizia "a caso" fatta da tc_cache_put(). Costa pochi millisecondi.
if (function_exists('tc_cleanup_bucket')) {
    tc_cleanup_bucket(sprintf('%02x', mt_rand(0, 255)));
}

$sources = require BASE_DIR . '/config.php';

$forced_index = null;
if ($force_source !== '') {
    foreach ($sources as $idx => $s) {
        if (strcasecmp($s['name'], $force_source) === 0) {
            $forced_index = $idx;
            break;
        }
    }
    if ($forced_index === null) {
        respond([
            'error' => "Fonte '$force_source' non trovata in config.php.",
            'available_sources' => array_column($sources, 'name'),
        ], $is_web);
    }
}

if (DEBUG_ENABLED) {
    // Sovrascrive (non accoda) ad ogni run con debug=1: un file pulito per
    // ogni chiamata di debug, non un log infinito da scorrere.
    file_put_contents(DEBUG_LOG_FILE, sprintf(
        "=== Debug run %s (batch=%d%s) ===\n",
        date('c'),
        $batch_size,
        $forced_index !== null ? ", force_source={$sources[$forced_index]['name']}" : ''
    ));
}

// Carica lo stato del ciclo in corso, o ne inizia uno nuovo
$state = null;
if (file_exists(STATE_FILE)) {
    $raw_state = json_decode(file_get_contents(STATE_FILE), true);
    if (is_array($raw_state) && isset($raw_state['source_i'])) {
        $state = $raw_state;
    }
}
if (!$state) {
    $state = [
        'source_i'      => 0,   // indice della fonte corrente in config.php
        'item_i'        => 0,   // indice dell'articolo corrente dentro quella fonte
        'current_items' => [],  // articoli grezzi (senza immagine) già scaricati per la fonte corrente
        'buffer'        => [],  // articoli già arricchiti in questo ciclo
        'fetch_diag'    => [],  // diagnostica: per ogni fonte, esito del fetch di questo ciclo
        'dl_counts'     => [],  // items_downloaded per fonte: persiste tra i batch, resettato a nuovo ciclo
        'item_errors'   => [],  // errori catturati per singolo articolo (vedi try/catch più sotto)
    ];
}
if (!isset($state['fetch_diag'])) {
    $state['fetch_diag'] = []; // compatibilità con uno state.json salvato prima di questa modifica
}
if (!isset($state['dl_counts'])) {
    $state['dl_counts'] = [];
}
if (!isset($state['item_errors'])) {
    $state['item_errors'] = [];
}

// Pattern di URL bloccati manualmente dalla card (long-press -> "Blocca").
// Caricati una sola volta per run: block_paths.php può modificare il file
// mentre questo script gira, ma è comunque coerente processare l'intero
// batch con l'elenco letto all'inizio.
$blocked_patterns = load_blocked_patterns();
debug_log('Pattern bloccati caricati: ' . (empty($blocked_patterns) ? '(nessuno)' : implode(', ', $blocked_patterns)));

if ($forced_index !== null) {
    $forced_name = $sources[$forced_index]['name'];
    // Se questa fonte era già stata (parzialmente o interamente) processata
    // in questo stesso ciclo — es. un force_source precedente, o il
    // cursore normale che ci era già passato — i suoi articoli sono già
    // nel buffer. Senza rimuoverli qui, il nuovo passaggio li aggiungerebbe
    // IN PIÙ (non al posto di), risultando in doppioni nella cache finale
    // (visto esattamente questo: 30 articoli bellunopress invece di 15,
    // metà dal tentativo vecchio con og:image vuota, metà dal tentativo
    // nuovo corretto).
    $before_count = count($state['buffer']);
    $state['buffer'] = array_values(array_filter(
        $state['buffer'],
        fn($a) => $a['source'] !== $forced_name
    ));
    $removed = $before_count - count($state['buffer']);

    $state['source_i'] = $forced_index;
    $state['item_i'] = 0;
    $state['current_items'] = [];
    // Una fonte intera in un colpo solo. Non esiste più un max_items per
    // sapere in anticipo quanti item ha la fonte (ora si scaricano tutti),
    // quindi usiamo un tetto ampio e fisso, sufficiente per la stragrande
    // maggioranza dei feed RSS/Atom reali.
    $batch_size = max($batch_size, FORCE_SOURCE_BATCH_SIZE);
    debug_log("force_source attivo: rimossi $removed articoli di '$forced_name' già nel buffer di questo ciclo (evita doppioni), cursore puntato sull'indice $forced_index, batch_size alzato a $batch_size.");
}

$processed = 0;

while ($processed < $batch_size) {

    // force_source: appena il cursore si sposta OLTRE la fonte forzata (i suoi
    // articoli sono esauriti), ci fermiamo qui invece di proseguire a
    // processare anche la fonte successiva — l'utente ha chiesto di
    // aggiornare/testare SOLO quella fonte.
    if ($forced_index !== null && $processed > 0 && $state['source_i'] !== $forced_index) {
        debug_log("force_source: fonte '{$sources[$forced_index]['name']}' esaurita, mi fermo (niente altre fonti in questa chiamata).");
        break;
    }

    // Ciclo completo: tutte le fonti esaurite -> scriviamo la cache definitiva
    if ($state['source_i'] >= count($sources)) {
        // JSON_INVALID_UTF8_SUBSTITUTE evita che json_encode() fallisca (e
        // restituisca silenziosamente false) se anche un solo carattere in
        // titolo/descrizione/link di un articolo non è UTF-8 valido — cosa
        // tutt'altro che rara scrappando feed e pagine con encoding vari.
        // Senza questo flag, un singolo articolo "sporco" fa fallire
        // l'intero payload, cache.json finisce vuoto, e il sintomo (0
        // articoli nonostante fetch_diag dica che i feed ne hanno trovati
        // 30) è indistinguibile da una race condition sullo stato — da qui
        // il sospetto iniziale, poi escluso ragionando sul fatto che con
        // batch=200 tutto il ciclo avviene in un'unica richiesta sincrona.
        $payload = json_encode(
            ['articles' => $state['buffer'], 'updated' => date('c'), 'version' => APP_VERSION],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($payload === false) {
            // Se fallisce ANCHE con il substitute, il motivo non è più
            // UTF-8 invalido: lo riportiamo esplicitamente invece di
            // lasciar apparire silenziosamente "0 articoli".
            respond([
                'error' => 'json_encode del payload cache fallito: ' . json_last_error_msg(),
                'buffer_count' => count($state['buffer']),
            ], $is_web);
        }
        $written = file_put_contents(CACHE_FILE, $payload);
        if ($written === false) {
            respond(['error' => 'Impossibile scrivere ' . CACHE_FILE . ' — controlla i permessi della cartella cache/.'], $is_web);
        }

        $diag_for_response = $state['fetch_diag'];
        $item_errors_for_response = $state['item_errors'] ?? [];

        // ── source_stats.json ──────────────────────────────────────────────
        // Scritto a ogni ciclo completo; il frontend (popup selezione fonte)
        // lo legge per mostrare [scaricati/trovati] e l'ora dell'ultimo
        // controllo accanto al nome di ogni fonte, anche quelle senza articoli.
        $stats_file = BASE_DIR . '/cache/source_stats.json';
        $stats = [];
        // Carica le statistiche del ciclo precedente (per le fonti che in
        // questo ciclo non sono state toccate, ad es. in caso di run parziale).
        if (file_exists($stats_file)) {
            $prev = json_decode((string) file_get_contents($stats_file), true);
            if (is_array($prev)) $stats = $prev;
        }
        // Aggiorna con i dati del ciclo appena completato.
        // items_found e items_downloaded sono già scritti correttamente
        // dal blocco fetch-fonte (immediato) e dal blocco fine-batch:
        // qui aggiorniamo solo last_checked e result, preservando i
        // contatori già sul disco per evitare di azzerarli con i valori
        // vuoti di fetch_diag (che viene resettato a ogni nuovo ciclo).
        foreach ($diag_for_response as $_d) {
            $_name = $_d['source'] ?? '';
            if ($_name === '') continue;
            if (!isset($stats[$_name])) {
                $stats[$_name] = ['items_found' => 0, 'items_downloaded' => 0];
            }
            $stats[$_name]['last_checked'] = $_d['last_checked'] ?? null;
            $stats[$_name]['result']       = $_d['result'] ?? 'unknown';
            // Aggiorna items_found solo se il diag lo ha valorizzato (> 0):
            // evita di sovrascrivere con 0 se fetch_diag era già resettato.
            if (($_d['items_found'] ?? 0) > 0) {
                $stats[$_name]['items_found'] = $_d['items_found'];
            }
        }
        // Assicura che tutte le fonti configurate siano presenti nel file,
        // anche quelle mai riuscite in nessun ciclo (result: never_fetched).
        foreach ($sources as $_s) {
            if (!isset($stats[$_s['name']])) {
                $stats[$_s['name']] = [
                    'last_checked'     => null,
                    'result'           => 'never_fetched',
                    'items_found'      => 0,
                    'items_downloaded' => 0,
                ];
            }
        }
        @file_put_contents($stats_file, json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // Diagnostica anti-race: quanti articoli "presi in carico" (fetch_diag,
        // somma di items_processed sulle fonti 'ok' — esclude i link bloccati, a
        // differenza di items_found che è il totale REALE del feed) contro
        // quanti ne sono finiti davvero nel buffer scritto in cache. Il push nel
        // buffer è incondizionato (vedi sotto), quindi in condizioni normali i
        // due numeri devono coincidere. Se items_processed_total > 0 ma il
        // buffer è più corto (in particolare 0), è il sintomo di un altro
        // processo che ha letto/sovrascritto state.json in mezzo a questo run
        // (race condition su STATE_FILE) — non di un problema di parsing o di rete.
        $items_processed_total = array_sum(array_map(
            fn($d) => $d['items_processed'] ?? ($d['items_found'] ?? 0),
            $diag_for_response
        ));

        // Contiamo il buffer direttamente (fonte di verità in memoria),
        // NON ri-decodificando $payload: se in futuro l'encode dovesse
        // fallire per un motivo diverso da UTF-8 e non abortire sopra,
        // vogliamo comunque un conteggio corretto qui, non un altro "0"
        // fuorviante ereditato da un giro di encode/decode fallito.
        $article_count = count($state['buffer']);

        // reset per il prossimo ciclo
        $state = ['source_i' => 0, 'item_i' => 0, 'current_items' => [], 'buffer' => [], 'fetch_diag' => [], 'dl_counts' => [], 'item_errors' => []];
        file_put_contents(STATE_FILE, json_encode($state));

        $resp = ['status' => "Ciclo completo, cache.json aggiornata ($article_count articoli). Nuovo ciclo iniziato."];
        if (($tr = translation_stats_for_response()) !== null) $resp['translation'] = $tr;
        $resp['items_processed_total'] = $items_processed_total; // per confronto rapido con $article_count
        if ($article_count === 0 || $article_count < $items_processed_total) {
            // Con 0 articoli, o con un conteggio finale inferiore a quanto
            // trovato nei feed, allego SEMPRE la diagnostica per fonte,
            // altrimenti è impossibile capire dalla sola risposta se il
            // problema è stato di rete, di parsing XML, o una race condition.
            $resp['fetch_diag'] = $diag_for_response;
        }
        if (!empty($item_errors_for_response)) {
            $resp['item_errors'] = $item_errors_for_response;
            $resp['error_log'] = 'cache/errors.log'; // storico persistente, non solo di questo run
        }
        respond($resp, $is_web);
    }

    $src = $sources[$state['source_i']];

    // Se è la prima volta che tocchiamo questa fonte in questo ciclo,
    // scarichiamo e parsiamo l'XML UNA SOLA VOLTA (non ad ogni batch).
    if (empty($state['current_items']) && $state['item_i'] === 0) {
        $items_raw = [];
        $diag = ['source' => $src['name'], 'url' => $src['url'], 'last_checked' => time(), 'items_downloaded' => 0];
        $xml_raw = gnews_curl($src['url'], $UA);

        if (!$xml_raw) {
            $diag['result'] = 'curl_failed';
            $diag['detail'] = 'gnews_curl() ha restituito vuoto/false: la richiesta HTTP verso il feed non è andata a buon fine (rete, DNS, timeout, o blocco dal sito remoto).';
            debug_log("[{$src['name']}] fetch feed FALLITO (curl vuoto/false) su {$src['url']}");
        } else {
            $diag['http_bytes'] = strlen($xml_raw);
            debug_log("[{$src['name']}] fetch feed OK: " . strlen($xml_raw) . " byte da {$src['url']}");
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($xml_raw);
            if (!$xml) {
                $xml_errors = array_map(fn($e) => trim($e->message), libxml_get_errors());
                libxml_clear_errors();
                $diag['result'] = 'xml_parse_failed';
                $diag['detail'] = 'simplexml_load_string ha fallito: il contenuto scaricato non è XML valido (possibile pagina di errore/blocco al posto del feed).';
                $diag['xml_errors'] = array_slice($xml_errors, 0, 3);
            } else {
                // Rilevamento automatico del formato: RSS 2.0 (<rss><channel><item>)
                // oppure Atom (<feed><entry>) — indipendentemente da 'type' in
                // config.php (che oggi distingue solo standard/google_news, non
                // il "dialetto" XML). Alcune fonti (es. spaziogames) pubblicano
                // Atom pur essendo feed "normali", quindi guardiamo la struttura
                // reale del documento invece di assumerla dalla configurazione.
                $format = null;
                if (isset($xml->channel->item)) {
                    $format = 'rss';
                } elseif ($xml->getName() === 'feed' && isset($xml->entry)) {
                    $format = 'atom';
                }

                if ($format === null) {
                    $diag['result'] = 'no_items_in_feed';
                    $diag['detail'] = 'XML valido ma senza <channel><item> (RSS) né <feed><entry> (Atom) — il feed è stato raggiunto ma risulta vuoto o in un formato non riconosciuto.';
                } else {
                    $diag['format'] = $format; // utile in fetch_diag per capire a colpo d'occhio cosa ha rilevato
                    $c = 0;
                    $entries = $format === 'rss' ? $xml->channel->item : $xml->entry;
                    // Numero REALE di record presenti nel feed: il tetto per-fonte
                    // (max_items) è stato rimosso su richiesta — ora si scaricano
                    // TUTTI gli item offerti dal feed, nessun cap. Questo valore
                    // alimenta anche il badge "[scaricati/trovati]" nella card.
                    $feed_total_entries = count($entries);

                    foreach ($entries as $item) {
                        if ($format === 'rss') {
                            $enclosure = '';
                            if (isset($item->enclosure) && isset($item->enclosure['url'])) {
                                $enclosure = (string) $item->enclosure['url'];
                            }

                            $media_img = '';
                            $ns = $item->getNamespaces(true);
                            if (isset($ns['media'])) {
                                $media = $item->children($ns['media']);
                                if (isset($media->thumbnail) && isset($media->thumbnail->attributes()->url)) {
                                    $media_img = (string) $media->thumbnail->attributes()->url;
                                } elseif (isset($media->content) && isset($media->content->attributes()->url)) {
                                    $media_img = (string) $media->content->attributes()->url;
                                }
                            }

                            // HDblog (e forse altre fonti simili) non usa <enclosure>
                            // né il namespace media: RSS standard, ma due tag custom
                            // propri: <thumb> (180x180) e <thumb_intermedia> (480x320,
                            // preferibile perché più grande). Senza questo blocco,
                            // $enclosure e $media_img restano sempre vuoti per queste
                            // fonti e lo script finisce a fare scraping og:image sulla
                            // pagina dell'articolo, inutilmente e in modo più fragile.
                            $thumb_img = '';
                            if (isset($item->thumb_intermedia) && trim((string) $item->thumb_intermedia) !== '') {
                                $thumb_img = trim((string) $item->thumb_intermedia);
                            } elseif (isset($item->thumb) && trim((string) $item->thumb) !== '') {
                                $thumb_img = trim((string) $item->thumb);
                            }

                            // Nessun enclosure/media/thumb: la copertina può essere un <img> nella descrizione o nel testo completo
                            // (<content:encoded>). Si legge dal feed prima di andare a scaricare la pagina (che può dare 403).
                            if ($thumb_img === '') {
                                $thumb_img = feed_html_image((string) $item->description, trim((string) $item->link));
                                if ($thumb_img === '' && isset($ns['content'])) {
                                    $thumb_img = feed_html_image((string) $item->children($ns['content'])->encoded, trim((string) $item->link));
                                }
                            }

                            $feed_categories = [];
                            foreach ($item->category as $cat) {
                                $cat_str = mb_strtolower(trim((string) $cat), 'UTF-8');
                                if ($cat_str !== '') {
                                    $feed_categories[] = $cat_str;
                                }
                            }

                            $item_link = trim((string) $item->link);

                            // Notizia esclusa manualmente (long-press -> "Blocca" dalla
                            // card): saltiamo del tutto, non finisce in items_processed.
                            if (is_link_blocked($item_link, $blocked_patterns)) {
                                debug_log("[{$src['name']}] articolo SALTATO (pattern bloccato): $item_link");
                                continue;
                            }

                            $desc_parsed = parse_gnews_description((string) $item->description);

                            $items_raw[] = [
                                'title'       => trim((string) $item->title),
                                'link'        => $item_link,
                                'description' => $desc_parsed['description'],
                                'related'     => $desc_parsed['related'],
                                'pubDate'     => trim((string) $item->pubDate),
                                'enclosure'   => $enclosure,
                                'media_img'   => $media_img,
                                'thumb_img'   => $thumb_img,
                                'categories'  => $feed_categories,
                            ];
                        } else {
                            // ─── Atom: struttura diversa da RSS 2.0 su quasi ogni campo ───
                            // <link> è un elemento vuoto con attributi (non testo): può
                            // comparire più volte con rel diversi (alternate/enclosure/
                            // self/hub...). Prendiamo rel="alternate" come URL principale
                            // dell'articolo, con fallback al primo href disponibile se
                            // "alternate" non è presente esplicitamente.
                            $link = '';
                            $enclosure = '';
                            foreach ($item->link as $l) {
                                $attrs = $l->attributes();
                                $rel = (string) ($attrs['rel'] ?? 'alternate');
                                $type = (string) ($attrs['type'] ?? '');
                                $href = (string) ($attrs['href'] ?? '');
                                if ($href === '') {
                                    continue;
                                }
                                if ($rel === 'alternate' || $link === '') {
                                    $link = $href;
                                }
                                if ($rel === 'enclosure' && stripos($type, 'image') === 0) {
                                    $enclosure = $href;
                                }
                            }

                            // Alcune fonti Atom (es. feed WordPress con plugin media)
                            // usano comunque il namespace media: sul singolo <entry>,
                            // stessa logica già usata per RSS.
                            $media_img = '';
                            $ns = $item->getNamespaces(true);
                            if (isset($ns['media'])) {
                                $media = $item->children($ns['media']);
                                if (isset($media->thumbnail) && isset($media->thumbnail->attributes()->url)) {
                                    $media_img = (string) $media->thumbnail->attributes()->url;
                                } elseif (isset($media->content) && isset($media->content->attributes()->url)) {
                                    $media_img = (string) $media->content->attributes()->url;
                                }
                            }

                            // Atom non ha <description>: <summary> è il riassunto,
                            // <content> il corpo completo. Preferiamo summary (più
                            // vicino, per lunghezza, a una RSS <description>) e
                            // ricadiamo su content solo se summary manca.
                            $description_raw = isset($item->summary) ? (string) $item->summary : ((string) ($item->content ?? ''));

                            // Data: <published> è la data di prima pubblicazione (il
                            // corrispondente più fedele di <pubDate> RSS); <updated> è
                            // obbligatorio in Atom ma riflette l'ultima modifica, quindi
                            // lo usiamo solo come fallback se published manca.
                            $pub_date = '';
                            if (isset($item->published) && trim((string) $item->published) !== '') {
                                $pub_date = trim((string) $item->published);
                            } elseif (isset($item->updated)) {
                                $pub_date = trim((string) $item->updated);
                            }

                            // Atom: <category term="..."/> è un attributo, non testo
                            // come in RSS.
                            $feed_categories = [];
                            foreach ($item->category as $cat) {
                                $term = (string) ($cat->attributes()['term'] ?? '');
                                if ($term === '') {
                                    $term = trim((string) $cat); // alcuni feed la scrivono comunque come testo
                                }
                                $cat_str = mb_strtolower(trim($term), 'UTF-8');
                                if ($cat_str !== '') {
                                    $feed_categories[] = $cat_str;
                                }
                            }

                            $link = trim($link);

                            // Notizia esclusa manualmente (long-press -> "Blocca" dalla
                            // card): saltiamo del tutto, non finisce in items_processed.
                            if (is_link_blocked($link, $blocked_patterns)) {
                                debug_log("[{$src['name']}] articolo SALTATO (pattern bloccato): $link");
                                continue;
                            }

                            $desc_parsed = parse_gnews_description($description_raw);

                            $items_raw[] = [
                                'title'       => trim((string) $item->title),
                                'link'        => $link,
                                'description' => $desc_parsed['description'],
                                'related'     => $desc_parsed['related'],
                                'pubDate'     => $pub_date,
                                'enclosure'   => $enclosure,
                                'media_img'   => $media_img,
                                // Atom di spaziogames e simili: la copertina è un <img> dentro <summary> (o <content>)
                                'thumb_img'   => feed_html_image($description_raw, $link) ?: feed_html_image((string) ($item->content ?? ''), $link),
                                'categories'  => $feed_categories,
                            ];
                        }
                        $c++;
                    }
                    $diag['result'] = 'ok';
                    // items_found = record REALI nel feed. Il tetto max_items è
                    // stato rimosso: si processano tutti, quindi items_found e
                    // items_processed coincidono salvo articoli bloccati da
                    // is_link_blocked() (che riducono solo items_processed).
                    $diag['items_found'] = $feed_total_entries;
                    // items_processed = quanti item sono stati effettivamente presi
                    // in carico in questo giro (esclusi i link bloccati). Serve al
                    // controllo anti-race qui sotto, per confronto col buffer.
                    $diag['items_processed'] = $c;
                }
            }
        }

        $state['fetch_diag'][] = $diag;
        $state['current_items'] = $items_raw;
        // Salva items_found in dl_counts subito dopo il fetch: fetch_diag
        // viene resettato a nuovo ciclo ma dl_counts persiste tra i batch.
        $_dn = $diag['source'];
        $state['dl_counts'][$_dn]['found']      = $diag['items_found'] ?? 0;
        $state['dl_counts'][$_dn]['downloaded'] = $state['dl_counts'][$_dn]['downloaded'] ?? 0;
    }

    // Fonte vuota o fetch fallito: passa alla successiva
    if (empty($state['current_items'])) {
        $state['source_i']++;
        $state['item_i'] = 0;
        $state['current_items'] = [];
        continue;
    }

    // Fonte esaurita: passa alla successiva
    if ($state['item_i'] >= count($state['current_items'])) {
        // Scrive SUBITO in cache.json gli articoli di questa fonte appena
        // completata (vedi commento su flush_source_to_cache() più sopra),
        // invece di aspettare che anche tutte le altre fonti finiscano.
        $source_articles = array_values(array_filter(
            $state['buffer'],
            fn($a) => ($a['source'] ?? '') === $src['name']
        ));
        if (!empty($source_articles)) {
            flush_source_to_cache($src['name'], $source_articles);
            debug_log("[{$src['name']}] fonte esaurita: " . count($source_articles) . " articoli scritti subito in cache.json (senza aspettare fine ciclo).");
        }
        $state['source_i']++;
        $state['item_i'] = 0;
        $state['current_items'] = [];
        continue;
    }

    // Elabora UN articolo (questa è la parte "costosa": decodifica Google News
    // e/o scraping og:image). Tutto il corpo è avvolto in try/catch: PRIMA di
    // questa modifica, un errore fatale/eccezione lanciata da una qualsiasi
    // delle funzioni qui sotto (gnews_resolve, fetch_og_image,
    // classify_topics, gtranslate_auto_to_it) interrompeva lo script PRIMA
    // di raggiungere $state['item_i']++ più in fondo — quindi state.json
    // NON veniva mai aggiornato oltre l'articolo che falliva, e il prossimo
    // giro di cron ripartiva SEMPRE dallo stesso identico articolo,
    // ricadendo nello stesso errore: la fonte restava bloccata a "0
    // scaricati" per sempre (visto in produzione su hwupgrade/bellunopress),
    // in silenzio, perché l'output del cron va su /dev/null. Ora un errore
    // su un singolo articolo viene loggato e quell'articolo viene scartato,
    // ma il ciclo prosegue regolarmente con il prossimo.
    $raw = $state['current_items'][$state['item_i']];

    try {
        $link = $raw['link'];
        $image = '';
        $related = $raw['related'] ?? [];

        debug_log("[{$src['name']}] articolo: \"{$raw['title']}\" -> $link");

        if ($src['type'] === 'google_news') {
            $real_url = gnews_resolve($link, $UA);
            debug_log("[{$src['name']}]   tipo=google_news, gnews_resolve() -> " . ($real_url ?: '(fallito, null)'));
            if ($real_url) {
                $image = fetch_og_image($real_url, $UA);
                debug_log("[{$src['name']}]   fetch_og_image($real_url) -> " . ($image !== '' ? $image : '(vuoto)'));
                $link = $real_url;
            }

            // Fallback: se la fonte "principale" scelta da Google News non ha
            // un'immagine recuperabile, proviamo le fonti correlate della
            // stessa notizia (stesso cluster, mostrato da Google come lista di
            // testate) finché non ne troviamo una con un'immagine valida.
            // MAX_RELATED_IMAGE_TRIES limita quante fonti correlate tentiamo,
            // per non allungare troppo il tempo di un singolo batch: ogni
            // tentativo richiede una risoluzione del link + uno scraping og:image.
            if (!$image && !empty($related)) {
                $max_tries = defined('MAX_RELATED_IMAGE_TRIES') ? MAX_RELATED_IMAGE_TRIES : 3;
                $tries = 0;
                foreach ($related as $i => $rel_item) {
                    if ($tries >= $max_tries) {
                        break;
                    }
                    $rel_link = trim($rel_item['link'] ?? '');
                    if ($rel_link === '') {
                        continue;
                    }
                    $tries++;
                    // Il link nella lista correlata può essere già l'URL diretto
                    // della testata, oppure un altro redirect di Google News: in
                    // entrambi i casi proviamo prima a risolverlo, e se fallisce
                    // tentiamo comunque lo scraping og:image sul link così com'è.
                    $rel_real_url = gnews_resolve($rel_link, $UA) ?: $rel_link;
                    $rel_image = fetch_og_image($rel_real_url, $UA);
                    if ($rel_image) {
                        $image = $rel_image;
                        // Salviamo l'immagine trovata anche dentro la voce
                        // "related" corrispondente: così anche chi consuma
                        // questo JSON (es. la card) può risalire a quale fonte
                        // correlata ha fornito l'immagine, se mai servisse.
                        $related[$i]['image'] = $rel_image;
                        break;
                    }
                }
            }
        } else {
            $image = $raw['enclosure'] ?: $raw['media_img'] ?: ($raw['thumb_img'] ?? '');
            debug_log("[{$src['name']}]   tipo=standard, enclosure=\"{$raw['enclosure']}\" media_img=\"{$raw['media_img']}\" thumb_img=\"{$raw['thumb_img']}\"");
            if (!$image) {
                $image = fetch_og_image($link, $UA);
                debug_log("[{$src['name']}]   nessuna immagine nel feed, fetch_og_image($link) -> " . ($image !== '' ? $image : '(vuoto, NESSUNA IMMAGINE TROVATA)'));
            } else {
                debug_log("[{$src['name']}]   immagine presa dal feed: $image");
            }
        }

        $keyword_topics = classify_topics($raw['title'], $raw['description']);
        $topics = array_values(array_unique(array_merge($raw['categories'], $keyword_topics)));

        debug_log("[{$src['name']}]   ESITO FINALE immagine: " . ($image !== '' ? $image : '(NESSUNA — articolo pubblicato senza immagine)'));

        // Traduzione automatica in italiano: rileviamo la lingua dal titolo (una
        // sola chiamata) e, se non è italiano, traduciamo titolo e descrizione,
        // conservando gli originali in *_original. Se il titolo risulta già in
        // italiano (o la rilevazione fallisce), non tocchiamo nulla: niente
        // chiamate sprecate sulla descrizione, e title_original/description_original
        // restano null così la card sa di non mostrare una riga in più.
        $title = $raw['title'];
        $description = $raw['description'];
        $title_original = null;
        $description_original = null;

        $title_tr = gtranslate_auto_to_it($title, $UA);
        if ($title_tr['detected_lang'] !== null && $title_tr['detected_lang'] !== 'it') {
            $title_original = $title;
            $title = $title_tr['translated'];

            if ($description !== '') {
                $desc_tr = gtranslate_auto_to_it($description, $UA);
                // Usiamo la traduzione della descrizione solo se è arrivata
                // davvero (translated !== originale): altrimenti mostreremmo
                // "originale" due volte identico.
                if ($desc_tr['translated'] !== $description) {
                    $description_original = $description;
                    $description = $desc_tr['translated'];
                }
            }
        }

        $state['buffer'][] = [
            'title'                => $title,
            'title_original'       => $title_original,
            'link'                 => $link,
            'description'          => $description,
            'description_original' => $description_original,
            'related'              => $related,
            'pubDate'              => $raw['pubDate'],
            'image'                => $image,
            'source'               => $src['name'],
            'topics'               => $topics,
        ];

        // Accumula items_downloaded in $state['dl_counts']['downloaded'].
        // dl_counts persiste in state.json tra i batch (fetch_diag viene resettato).
        $state['dl_counts'][$src['name']]['downloaded'] =
            ($state['dl_counts'][$src['name']]['downloaded'] ?? 0) + 1;

    } catch (\Throwable $e) {
        $err_msg = get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
        debug_log("[{$src['name']}]   *** ERRORE sull'articolo \"{$raw['title']}\": $err_msg — articolo SCARTATO, proseguo col prossimo.");
        log_item_error($src['name'], $raw['title'] ?? '(senza titolo)', $raw['link'] ?? '', $err_msg);
        if (!isset($state['item_errors'])) {
            $state['item_errors'] = [];
        }
        $state['item_errors'][] = [
            'source' => $src['name'],
            'title'  => $raw['title'] ?? '(senza titolo)',
            'link'   => $raw['link'] ?? '',
            'error'  => $err_msg,
        ];
        // Teniamo solo gli ultimi 20 errori per non far crescere state.json
        // all'infinito se una fonte fallisce sistematicamente.
        $state['item_errors'] = array_slice($state['item_errors'], -20);
    }

    // Avanza SEMPRE, sia in caso di successo che di errore catturato sopra:
    // è proprio questo che impedisce a un singolo articolo problematico di
    // bloccare la fonte per sempre.
    $state['item_i']++;
    $processed++;
}

// JSON_INVALID_UTF8_SUBSTITUTE qui è FONDAMENTALE quanto lo è già per
// CACHE_FILE più sopra: $state contiene 'buffer' e 'current_items', cioè
// titoli/descrizioni scrapati da pagine web reali — se anche uno solo ha
// byte UTF-8 invalidi, senza questo flag json_encode() fallisce e
// restituisce false SILENZIOSAMENTE. file_put_contents(STATE_FILE, false)
// scrive un file di 0 byte: alla chiamata successiva lo stato risulta
// "non valido", lo script lo scarta e RIPARTE L'INTERO CICLO DA ZERO,
// perdendo tutto il progresso accumulato fin lì (buffer, dl_counts,
// cursore) — esattamente il sintomo osservato in produzione (state.json
// trovato vuoto, tutte le fonti ripartite insieme).
$state_json = json_encode($state, JSON_INVALID_UTF8_SUBSTITUTE);
if ($state_json === false) {
    // Fallito anche col substitute: motivo diverso da UTF-8 invalido.
    // Logghiamo esplicitamente invece di scrivere un file vuoto in silenzio.
    debug_log('*** ERRORE CRITICO: json_encode(\$state) fallito anche con JSON_INVALID_UTF8_SUBSTITUTE: ' . json_last_error_msg() . ' — state.json NON aggiornato in questo giro, per non sovrascriverlo con un file vuoto/corrotto.');
    log_item_error('(globale)', '(scrittura state.json)', '', 'json_encode(\$state) fallito: ' . json_last_error_msg());
    $state_written = false;
} else {
    $state_written = file_put_contents(STATE_FILE, $state_json);
}
// Il lock (flock) si rilascia da solo quando il processo termina (respond()
// chiama exit()) — non serve più liberarlo esplicitamente qui.

// ── Aggiorna source_stats.json a fine batch con i contatori accumulati ──────
// dl_counts accumula items_downloaded per fonte durante questo batch e persiste
// in state.json. Qui fondiamo i contatori nel file su disco: sommiamo invece
// di sovrascrivere, così i batch successivi si aggiungono a quelli precedenti.
if (!empty($state['dl_counts'])) {
    $_sf = BASE_DIR . '/cache/source_stats.json';
    $_sd = [];
    if (file_exists($_sf)) {
        $_tmp = json_decode((string) file_get_contents($_sf), true);
        if (is_array($_tmp)) $_sd = $_tmp;
    }
    foreach ($state['dl_counts'] as $_sname => $_dc) {
        if (!isset($_sd[$_sname])) {
            $_sd[$_sname] = ['last_checked' => null, 'result' => 'ok', 'items_found' => 0, 'items_downloaded' => 0];
        }
        if (($_dc['found'] ?? 0) > 0) {
            $_sd[$_sname]['items_found'] = $_dc['found'];
        }
        $_sd[$_sname]['items_downloaded'] = $_dc['downloaded'] ?? 0;
    }
    @file_put_contents($_sf, json_encode($_sd, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

if ($state_written === false) {
    respond(['error' => 'Impossibile scrivere ' . STATE_FILE . ' — controlla i permessi della cartella cache/.'], $is_web);
}

$resp_final = [
    'status' => "Batch elaborato: $processed articoli. Fonte {$state['source_i']}/" . count($sources) . ", item {$state['item_i']}.",
];
if (($tr = translation_stats_for_response()) !== null) $resp_final['translation'] = $tr;
if (!empty($state['item_errors'])) {
    $resp_final['item_errors'] = $state['item_errors'];
    $resp_final['error_log'] = 'cache/errors.log';
}
respond($resp_final, $is_web);
