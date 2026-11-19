<?php
declare(strict_types=1);

// summarize.php
// Endpoint per il popup "riassunto" della card: dato il link di una notizia
// scarica la pagina dell'articolo, ne estrae il testo, sceglie le frasi
// principali e — se l'articolo non è in italiano — le traduce.
//
// È un riassunto ESTRATTIVO: non riscrive il testo, seleziona le frasi che
// meglio rappresentano l'articolo (le più "cariche" delle parole ricorrenti,
// più il primo paragrafo, che nelle notizie è di solito il riassunto vero e
// proprio). Non richiede nessuna chiave API né servizio a pagamento.
//
// Sicurezza: questo script scarica URL dal server, che sta nella tua rete
// locale, quindi è protetto in più modi:
//   - stesso api_token degli altri endpoint (settings.php)
//   - accetta SOLO link che compaiono in cache/news.json (cioè notizie che
//     arrivano davvero dai feed configurati): non è un "scarica qualunque URL"
//   - rifiuta host che risolvono a indirizzi privati/locali (192.168.x.x,
//     127.0.0.1, ecc.), anche dopo i redirect, e "fissa" l'IP verificato
//     per evitare cambi DNS tra il controllo e il download
//
// Contratto:
//   GET summarize.php?token=...&url=https%3A%2F%2Fsito.it%2Farticolo
//        [&refresh=1]  ignora la cache
//        [&debug=1]    aggiunge dettagli sull'estrazione e i tempi di ogni fase
//   GET summarize.php?token=...&sample=1[&source=bbc][&debug=1]
//        modalità di PROVA: prende da sola la prima notizia di news.json (o la
//        prima della fonte indicata), senza dover copiare a mano un link. Serve
//        per capire se il server funziona, indipendentemente dalla card.
//     -> { ok:true, sentences:[...], lang:"it", translated:false,
//          source:"article"|"meta", partial:bool, cached:bool, build:"..." }
//        Se il testo non era in italiano e la traduzione è riuscita, c'è anche
//        sentences_original: le stesse frasi nella lingua originale (stesso
//        ordine), così la card può mostrare prima il riassunto tradotto e poi
//        l'originale.
//     -> { ok:false, error:"...", build:"..." }

// Versione e build di QUESTO file (le aggiorno io ad ogni modifica che ti
// mando, non vanno toccate a mano né messe in settings.php): compaiono in ogni
// risposta JSON ("version" e "build") e nell'intestazione HTTP X-App-Version,
// come per rebuild_cache.php, così vedi subito quale file sta girando sul server.
// La build fa parte anche della chiave di cache: cambiandola, i riassunti
// vecchi vengono ignorati e rigenerati con la logica nuova.
//
// Storico:
//   1.0.0  (build 2026-09-28-01/-02)  riassunto estrattivo, traduzione, cache
//   1.1.0  (build 2026-09-28-03)      testo originale accanto alla traduzione
//   1.2.0  (build 2026-09-28-04)      modalità sample=1, tempi per fase, la
//                                      traduzione non insiste se il servizio è giù
//   1.3.0  (build 2026-09-28-05)      rilevamento della codifica dai byte
//                                      (niente più "Ã¨" al posto di "è")
//   1.11.0 (build 2026-10-21-06)      più servizi di traduzione in ORDINE (es. traduttore, poi Ollama, poi Google): le statistiche del
//                                      popup mostrano tutta la catena ("chain")
//   1.10.0 (build 2026-10-21-04)      traduzione anche con un traduttore locale "POST /translate" (LibreTranslate o compatibile),
//                                      scelto nelle impostazioni della card; le statistiche del popup ne tengono conto
//   1.9.0  (build 2026-10-21-01)      statistiche del caricamento: ogni fase (pagina, testo, riassunto, luogo, traduzione) con il suo
//                                      tempo, e per la traduzione quante frasi sono fatte e a che velocità (Ollama: lettura,
//                                      scrittura, token al secondo). Sempre nella risposta ("stats"); in diretta, mentre lavora, se la
//                                      card manda "rid" (?progress=<rid> legge lo stato)
//   1.8.1  (build 2026-10-20-01)      richieste alle pagine come le fa un browser attuale (Chrome 151, intestazioni Sec-Fetch-*,
//                                      HTTP/2): spaziogames.it rifiutava con HTTP 403 la firma "Chrome/124.0"; se il sito
//                                      rifiuta per una protezione anti-bot il motivo lo dice (es. "Cloudflare")
//   1.8.0  (build 2026-10-11-03)      luoghi ESTERI (paesi, capitali, grandi città, stati USA) per articoli in italiano e in
//                                      inglese, con i nomi in italiano (es. "Londra, Regno Unito"): lib/world_places.json
//   1.7.1  (build 2026-10-11-02)      riconoscimento della lingua che distingue l'italiano da spagnolo, portoghese,
//                                      catalano e francese (prima: articoli spagnoli/portoghesi "italiani", non tradotti)
//   1.7.0  (build 2026-10-11-01)      luogo di cui parla l'articolo (comune e provincia, es. "Longarone, BL"),
//                                      ricavato dal testo originale con lib/place_finder.php
//   1.6.1  (build 2026-10-08-04)      scadenza lunga anche quando Ollama è il ripiego di Google
//   1.6.0  (build 2026-10-08-03)      traduzione con Ollama (AI locale) se scelto nell'editor della card:
//                                      tempi più lunghi (un modello locale è lento), lingua non determinata
//   1.5.0  (build 2026-10-08-02)      traduzione tramite lib/translate_cache.php (cache permanente delle
//                                      frasi, pausa dopo un 429, tetti); retry=1 = tocco esplicito dell'utente
//   1.4.0  (build 2026-10-08-01)      traduzione: un nuovo tentativo dopo una pausa per gli
//                                      errori passeggeri (429, 5xx, connessione caduta)
//   1.3.1  (build 2026-09-28-06)      versione e intestazione X-App-Version;
//                                      con debug=1 mostra quale codifica ha
//                                      rilevato; scarta anche <?xml encoding=...>
const SUMMARIZE_VERSION = '1.11.0';
const SUMMARIZE_BUILD   = '2026-10-21-06';
const SUMMARIZE_CHROME  = '151';     // versione di Chrome dichiarata ai siti (luglio 2026): da aggiornare ogni tanto

const SUMMARIZE_CACHE_TTL   = 3 * 86400;   // un riassunto resta valido 3 giorni
const SUMMARIZE_MAX_BYTES   = 1500000;      // massimo di HTML scaricato
const SUMMARIZE_MAX_REDIRS  = 4;
const SUMMARIZE_MAX_CACHED  = 400;          // tetto file in cache/summaries/

$ALLOWED_ORIGIN = '*'; // TODO: restringere all'origine reale di HA in produzione

header('X-App-Version: ' . SUMMARIZE_VERSION . ' (build ' . SUMMARIZE_BUILD . ')');
header("Access-Control-Allow-Origin: $ALLOWED_ORIGIN");
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Token');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

set_time_limit(90);
error_reporting(E_ALL & ~E_DEPRECATED);

// ─── Risposta / log ─────────────────────────────────────────────────────────
function json_out(array $data, int $status = 200): void {
    http_response_code($status);
    $data['version'] = SUMMARIZE_VERSION;
    $data['build'] = SUMMARIZE_BUILD;
    // JSON_INVALID_UTF8_SUBSTITUTE: il testo viene da pagine web esterne; senza
    // questo flag un solo byte non valido farebbe fallire json_encode()
    // restituendo false (e quindi una risposta vuota).
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = '{"ok":false,"error":"json_encode fallito: ' . json_last_error_msg() . '","version":"' . SUMMARIZE_VERSION . '","build":"' . SUMMARIZE_BUILD . '"}';
    }
    echo $json;
    exit;
}

// Stesso file/formato di rebuild_cache.php, così tutti gli errori del progetto
// stanno nello stesso posto (cache/errors.log).
function summ_log_error(string $url, string $msg): void {
    $file = __DIR__ . '/cache/errors.log';
    if (!is_dir(dirname($file))) return;
    if (is_file($file) && filesize($file) > 2 * 1024 * 1024) {
        $existing = (string) @file_get_contents($file);
        $existing = substr($existing, (int) (strlen($existing) / 2));
        $cut = strpos($existing, "\n[");
        if ($cut !== false) $existing = substr($existing, $cut + 1);
        @file_put_contents($file, $existing . "...[log troncato]...\n");
    }
    @file_put_contents(
        $file,
        sprintf("[%s] fonte=(riassunto) articolo=\"\" link=%s\n  errore: %s\n", date('Y-m-d H:i:s'), $url, $msg),
        FILE_APPEND | LOCK_EX
    );
}

// ─── Avanzamento e statistiche ──────────────────────────────────────────────
// Le fasi (pagina, testo, riassunto, luogo, traduzione) con i loro tempi si annotano sempre in $PROG e tornano in "stats" nella
// risposta. Se la card manda anche "rid" (un codice casuale), lo stato viene scritto in cache/progress/<rid>.json a ogni passo:
// la card lo legge ogni mezzo secondo (?progress=<rid>) e lo mostra nel popup mentre il riassunto è ancora in corso.
define('SUMMARIZE_PROGRESS_DIR', __DIR__ . '/cache/progress');
$PROG = ['rid' => '', 't0' => microtime(true), 'state' => 'running', 'stage' => 'fetch', 'stages' => []];

function prog_rid($s): string { return is_string($s) && preg_match('/^[a-f0-9]{12,32}$/', $s) ? $s : ''; }

function prog_write(): void {
    global $PROG;
    if ($PROG['rid'] === '') return;                 // nessuno sta guardando: niente file
    $PROG['updated'] = microtime(true);
    $dir = SUMMARIZE_PROGRESS_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_dir($dir) || !is_writable($dir)) return;
    $json = json_encode($PROG, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $tmp = $dir . '/.' . $PROG['rid'] . '.' . getmypid() . '.tmp';
    // scrittura atomica: chi legge non vede mai un file a metà
    if ($json !== false && @file_put_contents($tmp, $json) !== false) @rename($tmp, $dir . '/' . $PROG['rid'] . '.json');
}

// Fase: $state = running | done | skipped; $info = numeri e parole semplici (il testo lo scrive la card, nella sua lingua).
function prog_stage(string $id, string $state, array $info = []): void {
    global $PROG;
    $now = microtime(true);
    $st = array_merge($PROG['stages'][$id] ?? ['started' => $now], $info);
    $st['state'] = $state;
    if ($state === 'running') $PROG['stage'] = $id;
    if ($state === 'done') $st['ms'] = (int) round(($now - $st['started']) * 1000);
    if ($state === 'skipped') $st['ms'] = 0;
    $PROG['stages'][$id] = $st;
    prog_write();
}

// Una frase della traduzione è stata fatta (o è fallita): aggiorna i contatori della fase e, per Ollama, le velocità.
function prog_translate_call(string $src, int $ms, ?string $error, ?array $last, int $chars): void {
    global $PROG;
    $st = $PROG['stages']['translate'] ?? null;
    if ($st === null) return;
    $st['done'] = ($st['done'] ?? 0) + 1;
    if ($error !== null) {
        if (strpos($error, 'tempo massimo') === 0) $st['timeout'] = ($st['timeout'] ?? 0) + 1;
        else { $st['errors'] = ($st['errors'] ?? 0) + 1; $st['last_error'] = mb_substr($error, 0, 200); }
    } elseif ($src === 'cache') {
        $st['cache'] = ($st['cache'] ?? 0) + 1;
    } elseif ($src === 'local_it') {
        $st['local_it'] = ($st['local_it'] ?? 0) + 1;
    } else {
        $st['calls'] = ($st['calls'] ?? 0) + 1;
        $st['ms_sum'] = ($st['ms_sum'] ?? 0) + $ms;
        $call = ['ms' => $ms, 'src' => $src, 'chars' => $chars];
        if ($last && ($last['provider'] ?? '') === 'ollama' && !empty($last['stats'])) {
            foreach (['load_ms', 'prompt_ms', 'prompt_tokens', 'out_tokens', 'gen_ms', 'tokens_per_s'] as $k) {
                if (isset($last['stats'][$k])) $call[$k] = $last['stats'][$k];
            }
            foreach (['load_ms', 'prompt_ms', 'out_tokens', 'gen_ms'] as $k) {
                if (isset($call[$k])) $st[$k] = ($st[$k] ?? 0) + $call[$k];
            }
        }
        $st['last'] = $call;
    }
    $PROG['stages']['translate'] = $st;
    prog_write();
}

function prog_end(string $state, array $extra = []): void {
    global $PROG;
    $PROG['state'] = $state;
    $PROG['total_ms'] = (int) round((microtime(true) - $PROG['t0']) * 1000);
    $PROG = array_merge($PROG, $extra);
    prog_write();
}

// Versione per la risposta (senza i dati interni).
function prog_stats(): array {
    global $PROG;
    $stages = [];
    foreach ($PROG['stages'] as $id => $st) { unset($st['started']); $stages[$id] = $st; }
    return ['total_ms' => (int) round((microtime(true) - $PROG['t0']) * 1000), 'stages' => $stages];
}

// ─── Auth ───────────────────────────────────────────────────────────────────
$settings = require __DIR__ . '/settings.php';
$expectedToken = $settings['api_token'] ?? null;
$providedToken = $_SERVER['HTTP_X_API_TOKEN'] ?? ($_GET['token'] ?? null);
if (!$expectedToken || !$providedToken || !hash_equals((string) $expectedToken, (string) $providedToken)) {
    json_out(['ok' => false, 'error' => 'unauthorized'], 401);
}

// ─── Lettura dell'avanzamento (la card lo chiede mentre aspetta il riassunto) ───
if (isset($_GET['progress'])) {
    $prid = prog_rid((string) $_GET['progress']);
    if ($prid === '') json_out(['ok' => false, 'error' => 'codice di avanzamento non valido'], 400);
    header('Cache-Control: no-store');
    $pf = SUMMARIZE_PROGRESS_DIR . '/' . $prid . '.json';
    $pd = (is_file($pf) && time() - (int) @filemtime($pf) < 900) ? json_decode((string) @file_get_contents($pf), true) : null;
    if (!is_array($pd)) json_out(['ok' => true, 'state' => 'waiting']);       // la richiesta non è ancora partita
    unset($pd['rid']);
    $pd['ok'] = true;
    $pd['now'] = microtime(true);
    // tempo trascorso: finché lavora, fino ad adesso; a lavoro finito, fino all'ultimo aggiornamento
    $endAt = (($pd['state'] ?? '') === 'running') ? $pd['now'] : ($pd['updated'] ?? $pd['now']);
    $pd['elapsed_ms'] = (int) round(($endAt - ($pd['t0'] ?? $endAt)) * 1000);
    json_out($pd);
}

// ─── Validazione dell'URL richiesto ─────────────────────────────────────────
function sample_url(string $source): string {
    $file = __DIR__ . '/cache/news.json';
    if (!is_file($file)) return '';
    $data = json_decode((string) file_get_contents($file), true);
    foreach (($data['articles'] ?? []) as $a) {
        if (empty($a['link'])) continue;
        if ($source !== '' && strcasecmp((string) ($a['source'] ?? ''), $source) !== 0) continue;
        return (string) $a['link'];
    }
    return '';
}

$url = trim((string) ($_GET['url'] ?? ''));
if (!empty($_GET['sample'])) {
    $url = sample_url(trim((string) ($_GET['source'] ?? '')));
    if ($url === '') {
        json_out(['ok' => false, 'error' => 'nessuna notizia trovata in news.json per la prova (fonte inesistente o cache vuota)'], 404);
    }
}
$parts = $url !== '' ? parse_url($url) : false;
if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
    json_out(['ok' => false, 'error' => 'URL mancante o non valido (solo http/https)'], 400);
}

// Solo link che arrivano davvero dai feed: elenco costruito da news.json
// (link principale e link delle fonti correlate).
function allowed_urls(): array {
    $file = __DIR__ . '/cache/news.json';
    if (!is_file($file)) return [];
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data) || !isset($data['articles']) || !is_array($data['articles'])) return [];
    $set = [];
    foreach ($data['articles'] as $a) {
        if (!empty($a['link'])) $set[(string) $a['link']] = true;
        foreach (($a['related'] ?? []) as $r) {
            if (!empty($r['link'])) $set[(string) $r['link']] = true;
        }
    }
    return $set;
}
if (!isset(allowed_urls()[$url])) {
    json_out(['ok' => false, 'error' => 'Questo link non è tra le notizie correnti (la cache potrebbe essersi aggiornata: ricarica la card)'], 403);
}

// ─── Cache su disco ─────────────────────────────────────────────────────────
$CACHE_DIR = __DIR__ . '/cache/summaries';
$cacheFile = $CACHE_DIR . '/' . sha1(SUMMARIZE_BUILD . '|' . $url) . '.json';
$refresh = !empty($_GET['refresh']);
$debug = !empty($_GET['debug']);

if (!$refresh && is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < SUMMARIZE_CACHE_TTL) {
    $cached = json_decode((string) file_get_contents($cacheFile), true);
    if (is_array($cached) && !empty($cached['ok'])) {
        $cached['cached'] = true;
        json_out($cached);
    }
}

// Da qui il riassunto va davvero preparato: se la card ha mandato il suo codice (rid), lo stato si scrive su file a ogni passo.
$PROG['rid'] = prog_rid((string) ($_GET['rid'] ?? ''));
$PROG['t0'] = microtime(true);
if ($PROG['rid'] !== '') {
    prog_write();
    if (mt_rand(1, 20) === 1) {                      // ogni tanto ripulisce i file di avanzamento vecchi
        foreach (glob(SUMMARIZE_PROGRESS_DIR . '/*.json') ?: [] as $oldf) if (time() - (int) @filemtime($oldf) > 900) @unlink($oldf);
    }
}

// ─── Rete: risoluzione sicura e download ────────────────────────────────────
function is_public_ip(string $ip): bool {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return false;
    }
    // 100.64.0.0/10 (CGNAT, usato anche da Tailscale): non coperto dai flag di PHP.
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $long = ip2long($ip);
        if ($long !== false && ($long & 0xFFC00000) === 0x64400000) return false;
    }
    return true;
}

// Ritorna gli IP dell'host se sono TUTTI pubblici, altrimenti null.
function resolve_public(string $host): ?array {
    $host = trim($host, '[]');
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) $ips = array_merge($ips, $v4);
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $r) if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
        }
    }
    if (!$ips) return null;
    // Solo per i test automatici (definita da un prepend, mai da una richiesta).
    if (defined('SUMMARIZE_ALLOW_PRIVATE') && SUMMARIZE_ALLOW_PRIVATE) return $ips;
    foreach ($ips as $ip) {
        if (!is_public_ip($ip)) return null;
    }
    return $ips;
}

function absolutize_url(string $base, string $loc): string {
    if (preg_match('#^https?://#i', $loc)) return $loc;
    $b = parse_url($base);
    $scheme = $b['scheme'] ?? 'http';
    $auth = ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
    if (substr($loc, 0, 2) === '//') return $scheme . ':' . $loc;
    if (substr($loc, 0, 1) === '/') return $scheme . '://' . $auth . $loc;
    $path = $b['path'] ?? '/';
    $dir = substr($path, 0, (int) strrpos($path, '/') + 1);
    return $scheme . '://' . $auth . $dir . $loc;
}

// Se il rifiuto (403, 429, 503...) viene da una protezione anti-bot, lo si capisce dalle intestazioni di risposta o dal
// testo della pagina di blocco. Serve solo a spiegare il motivo all'utente ("Cloudflare"), non cambia cosa si fa.
function block_hint(array $headers, string $body): string {
    $server = strtolower($headers['server'] ?? '');
    $who = [];
    if (isset($headers['cf-mitigated']) || isset($headers['cf-ray']) || strpos($server, 'cloudflare') !== false) $who[] = 'Cloudflare';
    if (isset($headers['x-sucuri-id']) || isset($headers['x-sucuri-cache'])) $who[] = 'Sucuri';
    if (isset($headers['x-datadome']) || isset($headers['x-datadome-cid'])) $who[] = 'DataDome';
    if (isset($headers['x-iinfo']) || isset($headers['x-cdn']) && stripos($headers['x-cdn'], 'imperva') !== false) $who[] = 'Imperva';
    if (strpos($server, 'akamai') !== false || isset($headers['x-akamai-request-id'])) $who[] = 'Akamai';
    $challenge = (bool) preg_match('/just a moment|attention required|checking your browser|enable javascript and cookies|captcha|access denied|pardon our interruption/i', substr($body, 0, 4000));
    if (!$who && !$challenge) return '';
    return 'protezione anti-bot' . ($who ? ': ' . implode(', ', $who) : '') . ($challenge ? ', pagina di verifica' : '');
}

// Scarica la pagina seguendo i redirect A MANO (max 4), ricontrollando a ogni
// passaggio che la destinazione sia pubblica. Ritorna [body, final_url, content_type].
function fetch_page(string $url): array {
    // Si presenta come un Chrome attuale che apre il link: versione scritta per intero e le intestazioni che un browser
    // manda sempre (Sec-Fetch-*, sec-ch-ua). I firewall anti-bot rifiutano con HTTP 403 le richieste che non le hanno
    // o che dichiarano un Chrome vecchio. Da aggiornare ogni tanto: 151 = luglio 2026.
    $chrome = SUMMARIZE_CHROME;
    $ua = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/{$chrome}.0.0.0 Safari/537.36";
    $current = $url;
    for ($hop = 0; $hop <= SUMMARIZE_MAX_REDIRS; $hop++) {
        $p = parse_url($current);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) {
            throw new RuntimeException('redirect verso un URL non valido');
        }
        $ips = resolve_public($p['host']);
        if ($ips === null) {
            throw new RuntimeException('destinazione non consentita (host non risolvibile o indirizzo privato): ' . $p['host']);
        }

        $headers = [];
        $body = '';
        $ch = curl_init($current);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_USERAGENT      => $ua,
            // Come tutto il resto del progetto (feed, scraping immagini): il
            // server può non avere i certificati CA aggiornati. Qui si legge
            // solo testo pubblico, nessuna credenziale viene inviata.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_ENCODING       => '',
            // HTTP/2 come un browser (se la libreria curl non lo ha, resta HTTP/1.1 da sola)
            CURLOPT_HTTP_VERSION   => defined('CURL_HTTP_VERSION_2TLS') ? CURL_HTTP_VERSION_2TLS : 0,
            CURLOPT_HTTPHEADER     => [
                'sec-ch-ua: "Chromium";v="' . $chrome . '", "Google Chrome";v="' . $chrome . '", "Not.A/Brand";v="99"',
                'sec-ch-ua-mobile: ?0',
                'sec-ch-ua-platform: "Windows"',
                'Upgrade-Insecure-Requests: 1',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                'Sec-Fetch-Site: none',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-User: ?1',
                'Sec-Fetch-Dest: document',
                'Accept-Language: it-IT,it;q=0.9,en-US;q=0.8,en;q=0.7',
            ],
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                $len = strlen($line);
                $kv = explode(':', $line, 2);
                if (count($kv) === 2) $headers[strtolower(trim($kv[0]))] = trim($kv[1]);
                return $len;
            },
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$body) {
                $body .= $chunk;
                // Interrompe il download oltre il tetto (curl segnalerà "Failed
                // writing body": è voluto, il testo che serve sta all'inizio).
                return strlen($body) >= SUMMARIZE_MAX_BYTES ? 0 : strlen($chunk);
            },
        ];
        // Fissa l'IP già verificato: evita che un secondo lookup DNS (diverso
        // dal primo) porti curl verso un indirizzo interno.
        if (!filter_var(trim($p['host'], '[]'), FILTER_VALIDATE_IP)) {
            foreach ($ips as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $port = $p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80);
                    $opts[CURLOPT_RESOLVE] = [$p['host'] . ':' . $port . ':' . $ip];
                    break;
                }
            }
        }
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $truncated = strlen($body) >= SUMMARIZE_MAX_BYTES;
        if ($errno !== 0 && !$truncated) {
            throw new RuntimeException("download fallito (curl errno $errno: $err)");
        }
        if ($code >= 300 && $code < 400 && !empty($headers['location'])) {
            $current = absolutize_url($current, $headers['location']);
            continue;
        }
        if ($code >= 400) {
            $hint = block_hint($headers, $body);
            throw new RuntimeException("il sito ha risposto HTTP $code" . ($hint !== '' ? " ($hint)" : ''));
        }
        return [$body, $current, $headers['content-type'] ?? ''];
    }
    throw new RuntimeException('troppi redirect');
}

// ─── Estrazione del testo ───────────────────────────────────────────────────
// Porta l'HTML in UTF-8 in modo affidabile. Regola di fondo: si guardano i
// BYTE, non solo le dichiarazioni, perché header e <meta> sono spesso
// sbagliati o in conflitto, e una singola sequenza sporca non deve far
// rileggere come Latin-1 un'intera pagina che in realtà è UTF-8 (il risultato
// sono le classiche "Ã¨" al posto di "è", "piÃ¹" al posto di "più").
function to_utf8(string $html, string $contentType, ?string &$path = null): string {
    // 1) BOM UTF-8: non ci sono dubbi.
    if (substr($html, 0, 3) === "\xEF\xBB\xBF") {
        $path = 'BOM UTF-8';
        return mb_convert_encoding(substr($html, 3), 'UTF-8', 'UTF-8');
    }

    // 2) UTF-8 valido (ASCII puro compreso): non si converte niente. Vale
    //    anche se l'header o una <meta> dicono altro: un testo che forma
    //    sequenze UTF-8 valide non è Latin-1 per caso.
    if (mb_check_encoding($html, 'UTF-8')) {
        $path = 'UTF-8 valido, nessuna conversione';
        return $html;
    }

    // 3) Non è UTF-8 valido. Due possibilità: è UTF-8 con qualche byte
    //    sporco (commenti degli utenti, taglio a metà carattere al limite di
    //    download...) oppure è davvero una codifica a byte singolo. Si contano
    //    le sequenze multibyte valide contro i byte alti "orfani".
    $seq = '/[\xC2-\xDF][\x80-\xBF]|[\xE0-\xEF][\x80-\xBF]{2}|[\xF0-\xF4][\x80-\xBF]{3}/';
    $validSeqs = (int) preg_match_all($seq, $html);
    $orphans   = (int) preg_match_all('/[\x80-\xFF]/', (string) preg_replace($seq, '', $html));
    if ($validSeqs > 0 && $validSeqs >= $orphans) {
        // UTF-8 con qualche imperfezione: si tiene com'è e si eliminano solo i byte non validi.
        $path = "UTF-8 con byte non validi ($validSeqs sequenze valide, $orphans orfani): byte scartati";
        return mb_convert_encoding($html, 'UTF-8', 'UTF-8');
    }

    // 4) Codifica a byte singolo: si usa quella dichiarata (header, poi
    //    <meta>), altrimenti Windows-1252. Come fanno i browser, "ISO-8859-1"
    //    si legge come Windows-1252 (ha anche € e le virgolette curve).
    $charset = null;
    if (preg_match('/charset=["\']?([\w-]+)/i', $contentType, $m)) {
        $charset = $m[1];
    } elseif (preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($html, 0, 4096), $m)) {
        $charset = $m[1];
    }
    $charset = strtoupper((string) $charset);
    if ($charset === '' || $charset === 'UTF-8' || $charset === 'UTF8' || $charset === 'ISO-8859-1' || $charset === 'LATIN1') {
        $charset = 'Windows-1252';
    }
    $path = "codifica a byte singolo, letta come $charset";
    try {
        return mb_convert_encoding($html, 'UTF-8', $charset);
    } catch (\Throwable $e) {
        $path = "charset '$charset' sconosciuto, letta come Windows-1252";
        return mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
    }
}

function meta_content(DOMXPath $xp, string $attr, string $name): string {
    $n = $xp->query("//meta[@$attr='$name']/@content");
    return ($n && $n->length) ? trim($n->item(0)->nodeValue) : '';
}

const NOISE_CLASS_RE = '/(^|[\s_-])(comments?|share|sharing|social|related|newsletter|cookies?|banner|advert|ads?|promo|sidebar|widget|breadcrumbs?|tags?|byline|paywall|subscribe|newsletter|correlati|correlate)([\s_-]|$)/i';
const BOILERPLATE_RE = '/^(leggi anche|leggi tutto|leggi la notizia|leggi qui|iscriviti|seguici|segui |scopri|potrebbe interessarti|ti potrebbe|clicca|condividi|copyright|©|tutti i diritti|riproduzione riservata|per ricevere|newsletter|accedi|registrati|read more|related|share this|sign up|follow us|subscribe|all rights|advertisement|pubblicit|foto:|photo:|credit)/iu';

// Ritorna [paragrafi, descrizione del contenitore usato].
function extract_paragraphs(DOMDocument $dom): array {
    $xp = new DOMXPath($dom);

    // Rimozione dei blocchi che non sono mai testo dell'articolo. NB: <form>
    // volutamente NON rimosso: certi siti (ASP.NET) avvolgono tutta la pagina
    // in un unico <form> e cancellarlo cancellerebbe l'articolo.
    foreach (['script', 'style', 'noscript', 'iframe', 'nav', 'aside', 'footer', 'header', 'svg', 'button', 'select', 'textarea', 'figure'] as $tag) {
        foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $n) {
            if ($n->parentNode) $n->parentNode->removeChild($n);
        }
    }
    // <br> -> a capo, per i siti che non usano <p>.
    foreach (iterator_to_array($dom->getElementsByTagName('br')) as $br) {
        if ($br->parentNode) $br->parentNode->replaceChild($dom->createTextNode("\n"), $br);
    }

    $hasClass = fn(string $c) => "contains(concat(' ', normalize-space(@class), ' '), ' $c ')";
    $queries = [
        "//*[@itemprop='articleBody']",
        '//article',
        '//*[' . $hasClass('td-post-content') . ']',   // tema "Newspaper" (es. Bellunopress)
        '//*[' . $hasClass('entry-content') . ']',
        '//*[' . $hasClass('post-content') . ']',
        '//*[' . $hasClass('article-body') . ']',
        '//*[' . $hasClass('article__body') . ']',
        '//*[' . $hasClass('article-content') . ']',
        '//*[' . $hasClass('story-body') . ']',
        '//*[' . $hasClass('single-content') . ']',
        '//main',
        "//*[@id='content']",
        '//body',
    ];

    $collect = function (DOMNode $container) use ($xp): array {
        $out = [];
        $seen = [];
        foreach ($xp->query('.//p', $container) as $p) {
            // Salta i paragrafi dentro blocchi "rumore" (condivisioni, correlati, cookie...).
            $skip = false;
            for ($n = $p; $n && $n !== $container; $n = $n->parentNode) {
                if ($n instanceof DOMElement) {
                    $sig = $n->getAttribute('class') . ' ' . $n->getAttribute('id');
                    if (trim($sig) !== '' && preg_match(NOISE_CLASS_RE, $sig)) { $skip = true; break; }
                }
            }
            if ($skip) continue;
            $t = trim((string) preg_replace('/\s+/u', ' ', $p->textContent));
            $len = mb_strlen($t);
            $endsSentence = (bool) preg_match('/[.!?»"”…]$/u', $t);
            if (!($len >= 60 || ($len >= 30 && $endsSentence))) continue;
            if (preg_match(BOILERPLATE_RE, $t)) continue;
            if (preg_match('/\b(cookie|privacy policy|informativa|javascript)\b/iu', $t)) continue;
            if (isset($seen[$t])) continue;
            $seen[$t] = true;
            $out[] = $t;
        }
        return $out;
    };

    $best = [];
    $bestLabel = '';
    foreach ($queries as $q) {
        $nodes = $xp->query($q);
        if (!$nodes || $nodes->length === 0) continue;
        $candBest = [];
        foreach ($nodes as $node) {
            $paras = $collect($node);
            if (mb_strlen(implode(' ', $paras)) > mb_strlen(implode(' ', $candBest))) $candBest = $paras;
        }
        $total = mb_strlen(implode(' ', $candBest));
        if ($total >= 300) return [$candBest, $q];
        if ($total > mb_strlen(implode(' ', $best))) { $best = $candBest; $bestLabel = $q; }
    }
    if ($best) return [$best, $bestLabel . ' (poco testo)'];

    // Ultima spiaggia: testo grezzo del body spezzato sugli a capo.
    $body = $dom->getElementsByTagName('body')->item(0);
    $out = [];
    if ($body) {
        foreach (preg_split('/\n+/u', $body->textContent) ?: [] as $chunk) {
            $t = trim((string) preg_replace('/\s+/u', ' ', $chunk));
            if (mb_strlen($t) >= 60 && !preg_match(BOILERPLATE_RE, $t)) $out[] = $t;
        }
    }
    return [$out, 'body (testo grezzo)'];
}

// ─── Riassunto estrattivo ───────────────────────────────────────────────────
const STOP_IT = 'a ad al allo alla alle agli ai anche ancora avere aveva che chi ci cioè come con contro cui da dal dallo dalla dalle dai degli dei del dello della delle di dopo dove due e è ed era erano essere fa fra gli ha hanno ho i il in io la le lei li lo loro lui ma mi mia mio molto ne nei nel nella nelle nello no noi non nostro o ogni per perché più poi può quale quali quando quanto quella quelle quelli quello questa queste questi questo se sé sei senza si sia siamo sono sta su sua sue sui sul sulla sulle suo suoi tra tre tu tua tuo tutti tutto un una uno vi voi già così dopo dove oggi ieri sarà sarebbe stato stata stati state essere stesso dice detto fatto';
const STOP_EN = 'a about after again all also am an and any are as at be because been before being but by can could did do does doing down for from had has have he her here him his how i if in into is it its just me more most my no nor not of off on once only or other our out over own said say says she should so some such than that the their them then there these they this those through to too under until up us very was we were what when where which while who why will with would you your after before during said told according mr mrs';

function tokens_of(string $s): array {
    preg_match_all('/\p{L}[\p{L}\p{N}\'’-]*/u', mb_strtolower($s), $m);
    return $m[0];
}

function stem_of(string $w): string {
    if (mb_strlen($w) > 4) {
        $s = preg_replace('/[aeiouàèéìòùs]+$/u', '', $w);
        if ($s !== null && mb_strlen($s) >= 3) return $s;
    }
    return $w;
}

function split_sentences(string $paragraph): array {
    // Protegge i punti di abbreviazioni e iniziali per non spezzare lì.
    // Titoli/abbreviazioni comuni: il punto non chiude la frase. Le forme
    // italiane si accettano anche in minuscolo ("l'ing. Rossi"); "On", "St",
    // "es", "n" solo con la maiuscola/forma esatta per non confonderle con
    // parole normali a fine frase (es. "...turned on.").
    $p = (string) preg_replace('/\b((?i:ing|dott|prof|avv|sig|sigg|dr|mr|mrs|ms|vs|ecc|pag|art|ca))\./u', "$1\u{2024}", $paragraph);
    $p = (string) preg_replace('/\b(On|St|Jr|Sr|es|n)\./u', "$1\u{2024}", $p);
    $p = (string) preg_replace('/\b([A-Z])\.(?=\s?[A-Z]\b)/u', "$1\u{2024}", $p);
    $parts = preg_split(
        '/(?:(?<=[.!?…])|(?<=[.!?…]["»”’\)\]]))\s+(?=["«“‘\(\[]?[\p{Lu}\d])/u',
        $p, -1, PREG_SPLIT_NO_EMPTY
    ) ?: [$p];
    return array_map(fn($s) => trim(str_replace("\u{2024}", '.', $s)), $parts);
}

// Ritorna [frasi scelte, lingua stimata 'it'|'en'|'other', dettagli debug].
function summarize_text(array $paragraphs): array {
    $stopIt = array_flip(explode(' ', STOP_IT));
    $stopEn = array_flip(explode(' ', STOP_EN));

    $sentences = [];
    foreach ($paragraphs as $para) {
        foreach (split_sentences($para) as $s) {
            $len = mb_strlen($s);
            if ($len >= 30 && $len <= 420) $sentences[] = $s;
        }
    }
    $n = count($sentences);
    if ($n === 0) return [[], 'other', []];

    // Lingua: quale lista di stopword compare di più nel testo.
    $it = $en = $tot = 0;
    foreach (array_slice(tokens_of(implode(' ', $sentences)), 0, 600) as $t) {
        $tot++;
        if (isset($stopIt[$t])) $it++;
        if (isset($stopEn[$t])) $en++;
    }
    if (function_exists('tc_text_lang')) {
        // Elenco del modulo di traduzione: distingue le parole SOLO italiane da quelle condivise con
        // spagnolo, portoghese, catalano o francese (la, le, con, del, una, al...). Prima, con un elenco
        // unico, un articolo in spagnolo o portoghese risultava "italiano" e non veniva tradotto.
        $lang = tc_text_lang(implode(' ', $sentences));
    } else {
        $lang = ($it > $en * 1.2 && $it >= $tot * 0.12) ? 'it' : (($en > $it * 1.2 && $en >= $tot * 0.12) ? 'en' : 'other');
    }
    $stop = $lang === 'it' ? $stopIt : ($lang === 'en' ? $stopEn : $stopIt + $stopEn);

    // Frequenza dei termini (radice grezza: governo/governi -> govern).
    $sentStems = [];
    $freq = [];
    foreach ($sentences as $i => $s) {
        $stems = [];
        foreach (tokens_of($s) as $t) {
            if (mb_strlen($t) < 3 || isset($stop[$t])) continue;
            $stems[] = stem_of($t);
        }
        $sentStems[$i] = $stems;
        foreach ($stems as $st) $freq[$st] = ($freq[$st] ?? 0) + 1;
    }

    $scores = [];
    foreach ($sentences as $i => $s) {
        $stems = $sentStems[$i];
        if (count($stems) < 3) { $scores[$i] = 0.0; continue; }
        $sc = 0;
        foreach ($stems as $st) $sc += $freq[$st];
        $sc /= (count($stems) ** 0.7);
        // Peso di posizione: nelle notizie il primo periodo è il riassunto.
        if ($i === 0) $sc *= 1.5; elseif ($i === 1) $sc *= 1.2; elseif ($i === 2) $sc *= 1.1;
        if ($i > $n * 0.6) $sc *= 0.9;
        if (preg_match('/^["«“]/u', $s)) $sc *= 0.9;
        if (count(tokens_of($s)) > 45) $sc *= 0.85;
        $scores[$i] = $sc;
    }

    $k = $n <= 3 ? $n : ($n <= 12 ? 3 : ($n <= 30 ? 4 : 5));
    $chosen = [0]; // il primo periodo c'è sempre
    $order = array_keys($scores);
    usort($order, fn($a, $b) => $scores[$b] <=> $scores[$a]);
    $chars = mb_strlen($sentences[0]);
    foreach ($order as $i) {
        if (count($chosen) >= $k) break;
        if (in_array($i, $chosen, true) || $scores[$i] <= 0) continue;
        if ($chars + mb_strlen($sentences[$i]) > 900) continue;
        // Scarta frasi troppo simili a una già scelta.
        $dup = false;
        foreach ($chosen as $c) {
            $a = array_unique($sentStems[$i]);
            $b = array_unique($sentStems[$c]);
            $union = count(array_unique(array_merge($a, $b)));
            if ($union > 0 && count(array_intersect($a, $b)) / $union > 0.5) { $dup = true; break; }
        }
        if ($dup) continue;
        $chosen[] = $i;
        $chars += mb_strlen($sentences[$i]);
    }
    sort($chosen);

    $out = [];
    foreach ($chosen as $i) {
        $s = $sentences[$i];
        if (mb_strlen($s) > 320) {
            $cut = mb_substr($s, 0, 320);
            $sp = mb_strrpos($cut, ' ');
            $s = rtrim($sp ? mb_substr($cut, 0, $sp) : $cut, " ,;:") . '…';
        }
        $out[] = $s;
    }
    return [$out, $lang, ['sentences_total' => $n, 'chosen_idx' => $chosen, 'top_terms' => array_slice(array_keys((function ($f) { arsort($f); return $f; })($freq)), 0, 8)]];
}

// ─── Luogo di cui parla l'articolo ──────────────────────────────────────────
// lib/place_finder.php + lib/comuni_it.json (comuni italiani, "Longarone, BL") + lib/world_places.json (paesi, capitali,
// grandi città, "Londra, Regno Unito"). Se i file mancano il riassunto funziona lo stesso, senza luogo (o solo con quello
// che si può trovare con i file presenti).
if (is_file(__DIR__ . '/lib/place_finder.php')) {
    require __DIR__ . '/lib/place_finder.php';
}

// ─── Traduzione (stesso modulo usato da rebuild_cache.php) ──────────────────
// lib/translate_cache.php: cache permanente, pausa dopo un 429, tetti di richieste.
if (is_file(__DIR__ . '/lib/translate_cache.php')) {
    require __DIR__ . '/lib/translate_cache.php';
} else {
    function tc_translate(string $text, string $ua, array $opts = []): array {
        return ['translated' => $text, 'detected_lang' => null, 'source' => 'missing_module',
                'error' => 'lib/translate_cache.php mancante: copialo nella cartella lib/', 'logworthy' => true];
    }
}
// Come si traduce, per questa richiesta: servizio scelto, eventuale ripiego, modello e tempo massimo (più lungo con Ollama).
function summ_plan(): array {
    static $p = null;
    if ($p !== null) return $p;
    $cfg = function_exists('tc_settings') ? tc_settings() : ['provider' => 'google', 'ollama' => [], 'libre' => []];
    $order = (isset($cfg['order']) && is_array($cfg['order']) && $cfg['order']) ? $cfg['order'] : [$cfg['provider']];
    $o = $cfg['ollama'] ?? [];
    $l = $cfg['libre'] ?? [];
    $usesOllama = in_array('ollama', $order, true);
    $usesLibre = in_array('libre', $order, true);
    return $p = [
        'provider' => $order[0],
        'fallback' => $order[1] ?? null,                 // per le card che conoscono solo "servizio + ripiego"
        'chain'    => $order,                            // tutti i servizi, nell'ordine in cui si provano
        'model'    => $usesOllama ? (($o['model'] ?? '') ?: null) : null,
        'endpoint' => $usesLibre && !empty($l['host']) ? $l['host'] . ':' . (int) ($l['port'] ?? 0) : null,   // traduttore locale
        'threads'  => $usesOllama ? (int) ($o['threads'] ?? 0) : 0,
        // un servizio locale è più lento di Google: più tempo (la card aspetta fino a 50 s in tutto)
        'limit_s'  => ($usesOllama || $usesLibre) ? 36 : 15,
    ];
}

function summ_translate(string $text, bool $force = false): array {
    // Una singola richiesta non deve restare appesa a lungo: oltre 15 secondi
    // di traduzione si smette (certi server/proxy chiudono la connessione prima).
    // Con Ollama (AI locale) serve più tempo: un modello su CPU impiega qualche
    // secondo per frase. La card aspetta fino a 50 s in tutto, articolo compreso.
    static $deadline = null;
    if ($deadline === null) $deadline = microtime(true) + summ_plan()['limit_s'];
    if (microtime(true) > $deadline) {
        return ['text' => $text, 'lang' => null, 'error' => 'tempo massimo per la traduzione superato', 'logworthy' => false, 'source' => 'deadline'];
    }
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/' . SUMMARIZE_CHROME . '.0.0.0 Safari/537.36';
    // Qui un 5xx o una connessione caduta si riprovano una volta (chi aspetta
    // il popup merita il tentativo in più); 429/403 invece aprono la pausa.
    $r = tc_translate($text, $ua, ['retry_transient' => true, 'force' => $force, 'ollama_timeout' => 30, 'ollama_budget' => 34]);
    return ['text' => $r['translated'], 'lang' => $r['detected_lang'], 'error' => $r['error'], 'logworthy' => !empty($r['logworthy']), 'source' => $r['source'] ?? ''];
}

// ─── Flusso principale ──────────────────────────────────────────────────────
$T = ['t0' => microtime(true)];
try {
    prog_stage('fetch', 'running');
    [$rawHtml, $finalUrl, $contentType] = fetch_page($url);
    $T['fetch'] = microtime(true);
    prog_stage('fetch', 'done', ['bytes' => strlen($rawHtml), 'host' => (string) parse_url($finalUrl, PHP_URL_HOST)]);
    prog_stage('extract', 'running');
    $encPath = '';
    $html = to_utf8($rawHtml, $contentType, $encPath);
    // Il testo ora è UTF-8: si tolgono le <meta> che dichiarano un charset e
    // l'eventuale dichiarazione XML iniziale con encoding (pagine XHTML),
    // altrimenti libxml può dar loro retta e rileggere tutto con un'altra
    // codifica (vecchie dichiarazioni Latin-1 rimaste in pagine UTF-8).
    $html = (string) preg_replace('/<meta\b[^>]*\bcharset\b[^>]*>/i', '', $html);
    $html = (string) preg_replace('/<\?xml\b[^>]*\?>/i', '', $html);

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    libxml_clear_errors();
    $xp = new DOMXPath($dom);
    $metaDesc = meta_content($xp, 'property', 'og:description') ?: meta_content($xp, 'name', 'description');
    $pageTitle = meta_content($xp, 'property', 'og:title');
    // Titolo per cercare il luogo: og:title, altrimenti il primo <h1>, altrimenti <title>.
    $placeTitle = $pageTitle;
    if ($placeTitle === '') { $h1 = $xp->query('//h1'); if ($h1 && $h1->length) $placeTitle = trim((string) $h1->item(0)->textContent); }
    if ($placeTitle === '') { $tt = $xp->query('//title'); if ($tt && $tt->length) $placeTitle = trim((string) $tt->item(0)->textContent); }

    [$paragraphs, $containerUsed] = extract_paragraphs($dom);
    $T['extract'] = microtime(true);
    $text = implode(' ', $paragraphs);
    prog_stage('extract', 'done', ['chars' => mb_strlen($text), 'paragraphs' => count($paragraphs)]);
    prog_stage('summarize', 'running');

    $source = 'article';
    $partial = false;
    $sentences = [];
    $lang = 'other';
    $dbg = [];

    if (mb_strlen($text) >= 200) {
        [$sentences, $lang, $dbg] = summarize_text(array_slice($paragraphs, 0, 60));
    }
    if (!$sentences) {
        // Poco/niente testo utilizzabile (pagina con paywall, consenso cookie,
        // contenuto caricato via JavaScript...): ripiega sulla descrizione
        // che il sito dichiara nei meta tag, segnalandolo come parziale.
        $metaDesc = trim((string) preg_replace('/\s+/u', ' ', $metaDesc));
        if (mb_strlen($metaDesc) >= 40) {
            $source = 'meta';
            $partial = true;
            $sentences = [mb_strlen($metaDesc) > 400 ? mb_substr($metaDesc, 0, 400) . '…' : $metaDesc];
            [, $lang] = summarize_text([$metaDesc . ' ' . $metaDesc]);
        }
    }
    if (!$sentences) {
        throw new RuntimeException('nessun testo utilizzabile nella pagina (paywall, consenso cookie o contenuto caricato via JavaScript)');
    }

    $T['summarize'] = microtime(true);
    prog_stage('summarize', 'done', ['sentences' => count($sentences), 'source' => $source, 'lang' => $lang]);
    prog_stage('place', 'running');

    // Luogo di cui si parla, dal testo ORIGINALE (prima della traduzione). Per articoli in italiano (comuni italiani e luoghi
    // esteri) e in inglese (luoghi esteri e città italiane); in altre lingue i nomi di luogo sono scritti in modo diverso
    // e coinciderebbero troppo spesso con parole comuni.
    $location = null;
    if (($lang === 'it' || $lang === 'en') && function_exists('pf_find_place')) {
        try {
            $location = pf_find_place($placeTitle, $partial ? (string) $metaDesc : $text, $lang);
        } catch (\Throwable $e) {
            $location = null;       // un difetto qui non deve mai impedire il riassunto
        }
    }
    $T['place'] = microtime(true);
    prog_stage('place', 'done', ['label' => $location['label'] ?? null]);

    // Traduzione in italiano se il testo non lo è.
    $translated = false;
    $trErrors = [];
    $trLog = [];                      // solo i veri errori del servizio (le pause volute non vanno nel log)
    $forceTr = !empty($_GET['retry']); // "Riprova la traduzione" del popup: un tentativo anche in pausa
    $originalSentences = $sentences; // copia prima della traduzione
    if ($lang !== 'it') {
        $plan = summ_plan();
        prog_stage('translate', 'running', ['provider' => $plan['provider'], 'fallback' => $plan['fallback'], 'chain' => $plan['chain'], 'model' => $plan['model'], 'endpoint' => $plan['endpoint'], 'threads' => $plan['threads'],
                                            'limit_s' => $plan['limit_s'], 'total' => count($sentences), 'done' => 0, 'from' => $lang]);
        foreach ($sentences as $i => $s) {
            $GLOBALS['TC_LAST_CALL'] = null;
            $tcStart = microtime(true);
            $r = summ_translate($s, $forceTr);
            prog_translate_call((string) ($r['source'] ?? ''), (int) round((microtime(true) - $tcStart) * 1000), $r['error'], $GLOBALS['TC_LAST_CALL'] ?? null, mb_strlen($s));
            if ($r['error'] !== null) { $trErrors[] = $r['error']; if ($r['logworthy']) $trLog[] = $r['error']; continue; }
            if ($r['lang'] !== null && $r['lang'] !== 'it' && $r['text'] !== $s) {
                $sentences[$i] = $r['text'];
                $translated = true;
                // Con Ollama la lingua di partenza può essere "und" (non determinata): si tiene quella
                // stimata dalla pagina e la card mostra solo "Originale", senza un nome sbagliato.
                if ($r['lang'] !== 'und') $lang = $r['lang'];
            } elseif ($r['lang'] === 'it') {
                $lang = 'it';
            }
        }
        if ($trLog) summ_log_error($url, 'traduzione del riassunto fallita: ' . $trLog[0]);
        prog_stage('translate', 'done');
    } else {
        prog_stage('translate', 'skipped');          // articolo già in italiano
    }

    $T['translate'] = microtime(true);

    $response = [
        'ok'         => true,
        'sentences'  => array_values($sentences),
        'lang'       => $lang,
        'translated' => $translated,
        'source'     => $source,
        'partial'    => $partial,
        'cached'     => false,
        'title'      => $pageTitle,
    ];
    // Solo se almeno una frase è stata davvero tradotta: altrimenti le due
    // liste sarebbero identiche e la card mostrerebbe lo stesso testo due volte.
    if ($translated) $response['sentences_original'] = array_values($originalSentences);
    if ($location !== null) $response['location'] = $location;
    $response['stats'] = prog_stats();              // tempi di ogni fase (e della traduzione): il popup li mostra in "Statistiche"
    if ($trErrors) $response['translation_warning'] = $trErrors[0];
    if ($debug) {
        $response['debug'] = $dbg + [
            'container'  => $containerUsed,
            'paragraphs' => count($paragraphs),
            'text_chars' => mb_strlen($text),
            'final_url'  => $finalUrl,
            // Come è stata letta la codifica della pagina: se il testo esce
            // rovinato, qui si vede quale decisione è stata presa e perché.
            'encoding'   => [
                'content_type'       => $contentType,
                'decision'           => $encPath,
                'bytes_downloaded'   => strlen($rawHtml),
                // true = la pagina ORIGINALE contiene già testo doppiamente
                // codificato ("Ã¨"): il difetto è nel sito, non qui.
                'raw_double_encoded' => strpos($rawHtml, "\xC3\x83\xC2") !== false,
            ],
            'first_paragraph' => mb_substr($paragraphs[0] ?? '', 0, 120),
        ];
        // Quanto ha impiegato ogni fase (ms): dice subito se il tempo se ne va
        // nel download, nell'analisi o nella traduzione.
        $response['debug']['timing_ms'] = array_map(fn($x) => (int) round($x * 1000), [
            'fetch'     => $T['fetch'] - $T['t0'],
            'extract'   => $T['extract'] - $T['fetch'],
            'summarize' => $T['summarize'] - $T['extract'],
            'place'     => $T['place'] - $T['summarize'],
            'translate' => $T['translate'] - $T['place'],
            'total'     => $T['translate'] - $T['t0'],
        ]);
    }

    // Salva in cache SOLO i risultati completi. Se la traduzione è fallita
    // (servizio temporaneamente giù) o c'è solo la descrizione del sito
    // (pagina con consenso cookie/JavaScript), non salviamo nulla: altrimenti
    // un guasto passeggero resterebbe "congelato" per giorni. Al prossimo click
    // si riprova da capo. Ogni tanto ripulisce anche i file vecchi.
    $cacheable = !$trErrors && !$partial;
    if ($cacheable && !is_dir($CACHE_DIR)) @mkdir($CACHE_DIR, 0775, true);
    if ($cacheable && is_dir($CACHE_DIR) && is_writable($CACHE_DIR)) {
        $toStore = $response;
        unset($toStore['debug']);
        $json = json_encode($toStore, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json !== false) @file_put_contents($cacheFile, $json, LOCK_EX);
        if (mt_rand(1, 15) === 1) {
            $files = glob($CACHE_DIR . '/*.json') ?: [];
            usort($files, fn($a, $b) => filemtime($a) <=> filemtime($b));
            $excess = count($files) - SUMMARIZE_MAX_CACHED;
            foreach ($files as $f) {
                if ($excess > 0 || (time() - filemtime($f)) > 7 * 86400) { @unlink($f); $excess--; }
            }
        }
    }

    prog_end('done');
    json_out($response);

} catch (\Throwable $e) {
    summ_log_error($url, $e->getMessage());
    prog_end('error', ['error' => mb_substr($e->getMessage(), 0, 300)]);
    json_out(['ok' => false, 'error' => $e->getMessage()], 502);
}
