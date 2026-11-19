<?php
declare(strict_types=1);

// opml_import.php
// Backend della pagina "Importa fonti da OPML" (opml_import.html): legge un
// file OPML (l'elenco di feed RSS che molti lettori sanno esportare) e fa
// l'anteprima dei singoli feed. Dalla 1.1 accetta anche l'indirizzo di una
// pagina web (es. https://rssly.io/feeds): cerca i feed nella pagina e nelle
// sue sottopagine e tiene solo quelli che rispondono davvero come RSS/Atom.
// NON modifica config.php: l'aggiunta delle fonti la fa la pagina chiamando
// sources_admin.php, cioè lo stesso codice che già scrive config.php per
// l'editor della card.
//
// Sicurezza: scarica indirizzi scelti da chi possiede il token, quindi
//   - richiede lo stesso api_token di settings.php
//   - accetta solo http/https e rifiuta host che risolvono a indirizzi
//     privati/locali (192.168.x.x, 127.0.0.1, ...), anche dopo i redirect,
//     fissando l'IP verificato per evitare cambi DNS a metà
//   - limita dimensione e tempo dei download
//   - rifiuta XML con dichiarazioni <!ENTITY (attacchi XXE / "billion laughs")
//
// Contratto:
//   GET opml_import.php?action=list&url=<link OPML>
//        -> { ok:true, opml_title, source_url, converted:bool, total,
//             skipped_invalid, duplicates_in_file, categories:[{name,count}],
//             feeds:[{id,title,url,html_url,category,exists}] }
//        I link "github.com/.../blob/..." vengono convertiti da soli nel
//        corrispondente raw.githubusercontent.com.
//   GET opml_import.php?action=preview&url=<link feed>
//        -> { ok:true, feed_title, format, items_found, items:[{title,date,link}] }
//        (fino a 4 articoli; il risultato si tiene in cache 6 ore)
//   Il token va in X-API-Token oppure in ?token=.

const OPML_IMPORT_VERSION = '1.1.0';
const OPML_IMPORT_BUILD   = '2026-11-19-01';

const OPML_MAX_BYTES        = 3000000;   // dimensione massima del file OPML
const OPML_MAX_FEEDS        = 3000;      // oltre questo numero il resto è ignorato
const PREVIEW_MAX_ITEMS     = 4;
const PREVIEW_CACHE_TTL     = 6 * 3600;
const PREVIEW_MAX_CACHED    = 1500;
const FETCH_MAX_REDIRS      = 4;
// Ricerca dei feed in una pagina web (quando l'indirizzo non è un OPML)
const SCAN_MAX_PAGES        = 30;        // sottopagine esplorate (stesso sito, sotto lo stesso percorso)
const SCAN_MAX_CANDIDATES   = 400;       // indirizzi controllati per capire se sono feed
const SCAN_PAGE_MAX_BYTES   = 2000000;   // massimo scaricato per pagina
const SCAN_TIME_BUDGET      = 70.0;      // secondi per tutta la ricerca

$ALLOWED_ORIGIN = '*'; // come gli altri endpoint del progetto

header('X-App-Version: ' . OPML_IMPORT_VERSION . ' (build ' . OPML_IMPORT_BUILD . ')');
header("Access-Control-Allow-Origin: $ALLOWED_ORIGIN");
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Token');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

set_time_limit(60);
error_reporting(E_ALL & ~E_DEPRECATED);

function json_out(array $data, int $status = 200): void {
    http_response_code($status);
    $data['version'] = OPML_IMPORT_VERSION;
    $data['build'] = OPML_IMPORT_BUILD;
    // I testi arrivano da siti esterni: un solo byte non valido farebbe
    // fallire json_encode() senza il flag SUBSTITUTE.
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = '{"ok":false,"error":"json_encode fallito: ' . json_last_error_msg() . '","version":"' . OPML_IMPORT_VERSION . '","build":"' . OPML_IMPORT_BUILD . '"}';
    }
    echo $json;
    exit;
}

// ─── Auth ───────────────────────────────────────────────────────────────────
$settings = require __DIR__ . '/settings.php';
$expectedToken = $settings['api_token'] ?? null;
$providedToken = $_SERVER['HTTP_X_API_TOKEN'] ?? ($_GET['token'] ?? null);
if (!$expectedToken || !$providedToken || !hash_equals((string) $expectedToken, (string) $providedToken)) {
    json_out(['ok' => false, 'error' => 'unauthorized'], 401);
}

// ─── Rete: host pubblico, download con redirect controllati ─────────────────
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
    if (defined('OPML_ALLOW_PRIVATE') && OPML_ALLOW_PRIVATE) return $ips;
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

// Scarica $url seguendo i redirect A MANO (max 4) e ricontrollando a ogni
// passaggio che la destinazione sia pubblica. $stopWhen, se dato, riceve il
// buffer ad ogni blocco e può interrompere il download anticipatamente.
// Ritorna [body, url_finale, content_type, troncato].
function fetch_remote(string $url, int $maxBytes, int $timeout, ?callable $stopWhen = null): array {
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
    $current = $url;
    for ($hop = 0; $hop <= FETCH_MAX_REDIRS; $hop++) {
        $p = parse_url($current);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) {
            throw new RuntimeException('indirizzo non valido (solo http/https)');
        }
        $ips = resolve_public($p['host']);
        if ($ips === null) {
            throw new RuntimeException('destinazione non consentita (host non risolvibile o indirizzo privato): ' . $p['host']);
        }

        $headers = [];
        $body = '';
        $stopped = false;
        $ch = curl_init($current);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_USERAGENT      => $ua,
            // Come tutto il resto del progetto (feed, scraping): il server può
            // non avere i certificati CA aggiornati. Si legge solo testo
            // pubblico, nessuna credenziale viene inviata.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/rss+xml,application/atom+xml,application/xml;q=0.9,text/xml;q=0.9,text/plain;q=0.8,*/*;q=0.5',
                'Accept-Language: it-IT,it;q=0.9,en-US;q=0.8,en;q=0.7',
            ],
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                $len = strlen($line);
                $kv = explode(':', $line, 2);
                if (count($kv) === 2) $headers[strtolower(trim($kv[0]))] = trim($kv[1]);
                return $len;
            },
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$body, &$stopped, $maxBytes, $stopWhen) {
                $body .= $chunk;
                if (strlen($body) >= $maxBytes) { $stopped = true; return 0; }
                if ($stopWhen !== null && $stopWhen($body)) { $stopped = true; return 0; }
                return strlen($chunk);
            },
        ];
        // Fissa l'IP già verificato: evita che un secondo lookup DNS porti
        // curl verso un indirizzo interno.
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

        if ($errno !== 0 && !$stopped) {
            if ($errno === 28) throw new RuntimeException("tempo scaduto (il sito non risponde entro $timeout s)");
            throw new RuntimeException("download fallito (curl errno $errno: $err)");
        }
        if ($code >= 300 && $code < 400 && !empty($headers['location'])) {
            $current = absolutize_url($current, $headers['location']);
            continue;
        }
        if ($code >= 400) {
            throw new RuntimeException("il sito ha risposto HTTP $code");
        }
        return [$body, $current, $headers['content-type'] ?? '', $stopped];
    }
    throw new RuntimeException('troppi redirect');
}

// ─── Utilità di testo e indirizzi ───────────────────────────────────────────
function clean_text(string $s, int $max = 0): string {
    $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = (string) preg_replace('/\s+/u', ' ', $s);
    $s = trim(mb_convert_encoding($s, 'UTF-8', 'UTF-8'));
    if ($max > 0 && mb_strlen($s) > $max) $s = rtrim(mb_substr($s, 0, $max - 1)) . '…';
    return $s;
}

// Chiave per confrontare due indirizzi di feed: ignora http/https, maiuscole
// nel dominio, "www.", porta predefinita, "/" finale e frammento.
function url_key(string $url): string {
    $p = parse_url(trim($url));
    if (!$p || empty($p['host'])) return strtolower(trim($url));
    $host = strtolower($p['host']);
    if (strpos($host, 'www.') === 0) $host = substr($host, 4);
    $port = isset($p['port']) && !in_array((int) $p['port'], [80, 443], true) ? ':' . $p['port'] : '';
    $path = rtrim($p['path'] ?? '', '/');
    return $host . $port . $path . (isset($p['query']) ? '?' . $p['query'] : '');
}

// I link "github.com/utente/repo/blob/ramo/file" sono pagine HTML, non il file:
// il contenuto vero sta su raw.githubusercontent.com.
function normalize_opml_url(string $url): array {
    $url = trim($url);
    if (preg_match('#^https?://(?:www\.)?github\.com/([^/]+)/([^/]+)/(?:blob|raw)/(.+?)(?:[?\#].*)?$#i', $url, $m)) {
        return ['https://raw.githubusercontent.com/' . $m[1] . '/' . $m[2] . '/' . $m[3], true];
    }
    return [$url, false];
}

function existing_sources(): array {
    $file = __DIR__ . '/config.php';
    if (!is_file($file)) return [];
    $sources = (function (string $f) { return require $f; })($file);
    return is_array($sources) ? $sources : [];
}

// ─── XML ────────────────────────────────────────────────────────────────────
function load_xml(string $raw) {
    // XXE e "billion laughs": nessuna dichiarazione di entità, mai.
    if (preg_match('/<!ENTITY/i', $raw)) {
        throw new RuntimeException('file XML rifiutato: contiene dichiarazioni di entità');
    }
    if (PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
        @libxml_disable_entity_loader(true);
    }
    $raw = ltrim($raw, "\xEF\xBB\xBF \t\r\n");
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    if ($xml === false) {
        $errs = array_map(fn($e) => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        throw new RuntimeException('XML non valido' . ($errs ? ': ' . $errs[0] : ''));
    }
    return $xml;
}

// ─── Pagina web: ricerca dei feed ───────────────────────────────────────────
// Se l'indirizzo dato a action=list non è un OPML ma una pagina web (un
// elenco di feed come rssly.io/feeds, o il sito di un giornale), si cercano i
// feed nella pagina e nelle sue sottopagine, poi si tengono solo gli indirizzi
// che rispondono davvero con un feed RSS/Atom. Il risultato ha la stessa forma
// dell'elenco OPML, così la pagina di scelta funziona uguale.

// Host già risolti in questa richiesta (molte sottopagine e feed sullo stesso sito).
function resolve_public_cached(string $host): ?array {
    static $cache = [];
    $k = strtolower($host);
    if (!array_key_exists($k, $cache)) $cache[$k] = resolve_public($host);
    return $cache[$k];
}

function curl_handle_id($ch): int {
    return is_resource($ch) ? (int) $ch : spl_object_id($ch);
}

// Scarica più indirizzi in parallelo, con gli stessi controlli di fetch_remote():
// solo http/https, solo host pubblici (IP fissato), redirect seguiti a mano e
// ricontrollati. Si ferma a $maxBytes per indirizzo (il resto non serve) e a
// $deadline (timestamp) per tutto il gruppo.
// Ritorna [url_originale => ['body'=>..., 'url'=>finale, 'type'=>...] | ['error'=>...]].
function multi_fetch(array $urls, int $maxBytes, int $timeout, int $concurrency, float $deadline): array {
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
    $results = [];
    $queue = [];
    foreach (array_values(array_unique($urls)) as $u) $queue[] = ['orig' => $u, 'url' => $u, 'hops' => 0];
    $mh = curl_multi_init();
    $running = [];   // id handle => job
    $bodies = [];    // id handle => testo scaricato
    $heads = [];     // id handle => header
    $stopped = [];   // id handle => interrotto da noi (abbastanza byte)

    $start = function (array $job) use (&$running, &$bodies, &$heads, &$stopped, $mh, $ua, $maxBytes, $timeout): ?string {
        $p = parse_url($job['url']);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) {
            return 'indirizzo non valido';
        }
        $ips = resolve_public_cached($p['host']);
        if ($ips === null) return 'destinazione non consentita';
        $ch = curl_init($job['url']);
        $id = curl_handle_id($ch);
        $bodies[$id] = '';
        $heads[$id] = [];
        $stopped[$id] = false;
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/rss+xml,application/atom+xml,application/xml;q=0.9,text/xml;q=0.9,text/html;q=0.8,*/*;q=0.5',
                'Accept-Language: it-IT,it;q=0.9,en-US;q=0.8,en;q=0.7',
            ],
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$heads) {
                $kv = explode(':', $line, 2);
                if (count($kv) === 2) $heads[curl_handle_id($ch)][strtolower(trim($kv[0]))] = trim($kv[1]);
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$bodies, &$stopped, $maxBytes) {
                $id = curl_handle_id($ch);
                $bodies[$id] .= $chunk;
                if (strlen($bodies[$id]) >= $maxBytes) { $stopped[$id] = true; return 0; }
                return strlen($chunk);
            },
        ];
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
        curl_multi_add_handle($mh, $ch);
        $running[$id] = $job + ['ch' => $ch];
        return null;
    };

    while ($queue || $running) {
        if (microtime(true) > $deadline) break;
        while ($queue && count($running) < $concurrency) {
            $job = array_shift($queue);
            $err = $start($job);
            if ($err !== null) $results[$job['orig']] = ['error' => $err];
        }
        do { $st = curl_multi_exec($mh, $still); } while ($st === CURLM_CALL_MULTI_PERFORM);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $id = curl_handle_id($ch);
            $job = $running[$id] ?? null;
            if ($job === null) continue;
            unset($running[$id]);
            $errno = (int) $info['result'];
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            $body = $bodies[$id]; $hdr = $heads[$id]; $stop = $stopped[$id];
            unset($bodies[$id], $heads[$id], $stopped[$id]);
            if ($errno !== 0 && !$stop) {
                $results[$job['orig']] = ['error' => $errno === 28 ? 'tempo scaduto' : "download fallito (curl errno $errno)"];
                continue;
            }
            if ($code >= 300 && $code < 400 && !empty($hdr['location'])) {
                if ($job['hops'] >= FETCH_MAX_REDIRS) { $results[$job['orig']] = ['error' => 'troppi redirect']; continue; }
                $queue[] = ['orig' => $job['orig'], 'url' => absolutize_url($job['url'], $hdr['location']), 'hops' => $job['hops'] + 1];
                continue;
            }
            if ($code >= 400) { $results[$job['orig']] = ['error' => "HTTP $code"]; continue; }
            $results[$job['orig']] = ['body' => $body, 'url' => $job['url'], 'type' => $hdr['content-type'] ?? ''];
        }
        if ($running) curl_multi_select($mh, 0.5);
    }
    foreach ($running as $id => $job) {
        curl_multi_remove_handle($mh, $job['ch']);
        curl_close($job['ch']);
        $results[$job['orig']] = ['error' => 'tempo scaduto'];
    }
    foreach ($queue as $job) $results[$job['orig']] = ['error' => 'tempo scaduto'];
    curl_multi_close($mh);
    return $results;
}

// L'indirizzo "sembra" un feed? Un pezzo del percorso deve essere proprio
// feed/feeds/rss/atom/rdf (es. /feed/, /rss/news), oppure finire in
// .xml/.rss/.atom/.rdf, oppure l'host è feeds.* / rss.* / feedburner, oppure
// la query chiede quel formato (?feed=rss2, ?format=atom). "rss-reader" o
// "rss-feed-preview" non contano: sono pagine che PARLANO di RSS.
function looks_like_feed_url(string $url): bool {
    $p = parse_url($url);
    $host = strtolower($p['host'] ?? '');
    if (preg_match('/^(feeds?|rss)\./', $host) || strpos($host, 'feedburner') !== false) return true;
    foreach (explode('/', strtolower($p['path'] ?? '')) as $seg) {
        if (preg_match('/^(feeds?|rss2?|atom|rdf)$/', $seg)) return true;
        if (preg_match('/\.(xml|rss|atom|rdf)$/', $seg)) return true;
    }
    $q = strtolower($p['query'] ?? '');
    return $q !== '' && (bool) preg_match('/(^|&)(feed|format|type|output)=(rss2?|atom|rdf|xml)(&|$)|(^|&)(rss|feed)(=|&|$)/', $q);
}

// File che non sono certamente né feed né pagine da esplorare.
function is_asset_url(string $url): bool {
    $path = strtolower((string) parse_url($url, PHP_URL_PATH));
    return (bool) preg_match('/\.(png|jpe?g|gif|webp|svg|ico|css|js|mjs|woff2?|ttf|eot|pdf|zip|gz|mp[34]|webm|avi|mov|json|txt|map)$/', $path);
}

function clean_found_url(string $u, string $base): ?string {
    $u = html_entity_decode(trim($u), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $u = str_replace('\\/', '/', $u);
    $u = rtrim($u, '.,;:!?)]}\'"');
    if ($u === '' || $u[0] === '#' || preg_match('#^(mailto|javascript|tel|data):#i', $u)) return null;
    $u = absolutize_url($base, $u);
    $u = preg_replace('/#.*$/', '', $u);
    if (!preg_match('#^https?://#i', $u) || !filter_var($u, FILTER_VALIDATE_URL)) return null;
    return $u;
}

// Titolo leggibile di una pagina: <h1>, altrimenti <title> senza " | Sito".
function page_heading(string $html): string {
    if (preg_match('#<h1\b[^>]*>(.*?)</h1>#is', $html, $m)) {
        $h = clean_text($m[1], 80);
        // "Tech RSS Feeds" -> "Tech": la categoria si legge meglio senza.
        $short = trim((string) preg_replace('/\s+(rss\s+)?(feeds?|rss)$/i', '', $h));
        if ($short !== '') $h = $short;
        if ($h !== '') return $h;
    }
    if (preg_match('#<title\b[^>]*>(.*?)</title>#is', $html, $m)) {
        $t = clean_text($m[1], 120);
        $t = trim((string) preg_split('/\s+[|–—-]\s+/u', $t)[0]);
        return mb_substr($t, 0, 80);
    }
    return '';
}

// Ultimo testo breve (2-60 caratteri) di un elemento nel pezzo di HTML: di
// solito il nome della fonte che precede il suo indirizzo. Si saltano le
// descrizioni (<p>), i numeri, gli indirizzi e i link tipo "Vedi tutti →".
function name_before(string $seg): string {
    if (!preg_match_all('#<(\w+)\b[^>]*>([^<]{2,120})</\1>#', $seg, $mm, PREG_SET_ORDER)) return '';
    for ($i = count($mm) - 1; $i >= 0; $i--) {
        $tag = strtolower($mm[$i][1]);
        $txt = clean_text($mm[$i][2]);
        if ($tag === 'p' || $tag === 'code' || $txt === '' || mb_strlen($txt) > 60) continue;
        if (preg_match('#^https?://|^[\d\s.,]+$|→|»#u', $txt)) continue;
        return $txt;
    }
    return '';
}

// Cerca in una pagina HTML:
//   feeds: indirizzi candidati => nome trovato vicino (o '')
//   pages: sottopagine dello stesso sito sotto lo stesso percorso (da esplorare)
function extract_from_page(string $html, string $pageUrl, string $baseUrl): array {
    $feeds = [];
    $pages = [];
    $add = function (?string $u, string $name = '') use (&$feeds) {
        if ($u === null || is_asset_url($u)) return;
        if (!isset($feeds[$u]) || ($feeds[$u] === '' && $name !== '')) $feeds[$u] = $name;
    };

    // 1) Feed dichiarati: <link rel="alternate" type="application/rss+xml" href="...">
    if (preg_match_all('#<link\b[^>]*>#i', $html, $mm)) {
        foreach ($mm[0] as $tag) {
            if (!preg_match('#type\s*=\s*["\']?application/(rss|atom|rdf)\+xml#i', $tag)) continue;
            if (!preg_match('#href\s*=\s*["\']([^"\']+)#i', $tag, $h)) continue;
            $name = preg_match('#title\s*=\s*["\']([^"\']*)#i', $tag, $tt) ? clean_text($tt[1], 80) : '';
            $add(clean_found_url($h[1], $pageUrl), $name);
        }
    }

    // 2) Link <a>: quelli che sembrano feed sono candidati; quelli sotto lo
    //    stesso percorso della pagina di partenza sono sottopagine da esplorare.
    $b = parse_url($baseUrl);
    $baseHost = strtolower(preg_replace('/^www\./i', '', $b['host'] ?? ''));
    $basePath = rtrim($b['path'] ?? '/', '/');
    if (preg_match_all('#<a\b[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $html, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) {
            $u = clean_found_url($m[1], $pageUrl);
            if ($u === null || is_asset_url($u)) continue;
            $p = parse_url($u);
            $host = strtolower(preg_replace('/^www\./i', '', $p['host'] ?? ''));
            $path = rtrim($p['path'] ?? '/', '/');
            // Sottopagina (stesso sito, sotto il percorso di partenza): si esplora.
            // Se poi risponde con un feed, viene riconosciuta lo stesso.
            if ($host === $baseHost && $basePath !== '' && strpos($path . '/', $basePath . '/') === 0 && $path !== $basePath) {
                $pages[preg_replace('/[?#].*$/', '', $u)] = true;
                continue;
            }
            if (looks_like_feed_url($u)) $add($u, clean_text($m[2], 80));
        }
    }

    // 3) Indirizzi scritti come testo (elenchi tipo rssly.io): tutti candidati.
    $noScript = preg_replace('#<(script|style|noscript)\b.*?</\1>#is', ' ', $html);
    $text = html_entity_decode(strip_tags(preg_replace('#<[^>]+>#', ' $0 ', $noScript)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    //    Il nome è il testo breve più vicino prima dell'indirizzo (nelle schede
    //    tipo "Nome / descrizione / indirizzo"), cercato dopo l'indirizzo precedente.
    if (preg_match_all('#https?://[^\s<>"\'(){}\[\]]+#i', $text, $mm)) {
        $lastEnd = 0;
        foreach ($mm[0] as $u) {
            $name = '';
            $pos = strpos($noScript, $u, $lastEnd);
            if ($pos !== false) {
                $from = max($lastEnd, $pos - 1500);
                $name = name_before(substr($noScript, $from, $pos - $from));
                $lastEnd = $pos + strlen($u);
            }
            $add(clean_found_url($u, $pageUrl), $name);
        }
    }

    // 4) Nei dati degli script (siti fatti con framework JS): solo quelli che sembrano feed.
    if (preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $html, $ss)) {
        foreach ($ss[1] as $js) {
            if (preg_match_all('#https?:(?:\\\\/|/){2}[^\s<>"\'(){}\[\]\\\\]+(?:\\\\/[^\s<>"\'(){}\[\]\\\\]+)*#i', $js, $mm)) {
                foreach ($mm[0] as $u) {
                    $u = str_replace('\\/', '/', $u);
                    if (looks_like_feed_url($u)) $add(clean_found_url($u, $pageUrl));
                }
            }
        }
    }
    return ['feeds' => $feeds, 'pages' => array_keys($pages)];
}

// Il testo scaricato è un feed? Ritorna il suo titolo ('' se senza titolo) o null.
function sniff_feed(string $body): ?string {
    $head = ltrim(substr($body, 0, 4000), "\xEF\xBB\xBF \t\r\n");
    $head = preg_replace('#<\?xml.*?\?>|<!--.*?-->|<\?xml-stylesheet.*?\?>|<!DOCTYPE[^>]*>#is', '', $head);
    $head = ltrim((string) $head);
    if (!preg_match('#^<(rss|feed|rdf:RDF)\b#i', $head)) return null;
    $scope = $body;
    if (preg_match('#<channel\b[^>]*>(.*)#is', $body, $c)) $scope = $c[1];
    if (preg_match('#<title\b[^>]*>(.*?)</title>#is', $scope, $m)) {
        $t = preg_replace('#^<!\[CDATA\[(.*)\]\]>$#s', '$1', trim($m[1]));
        return clean_text((string) $t, 80);
    }
    return '';
}

function scan_web_page(string $html, string $pageUrl, string $baseCategory): array {
    $deadline = microtime(true) + SCAN_TIME_BUDGET;
    $first = extract_from_page($html, $pageUrl, $pageUrl);

    // Candidati => [nome, categoria]. Le sottopagine danno la categoria
    // (es. "Tech" per rssly.io/feeds/tech) e vincono sulla pagina principale.
    $cand = [];
    foreach ($first['feeds'] as $u => $name) $cand[$u] = ['name' => $name, 'category' => $baseCategory];

    $pages = array_slice($first['pages'], 0, SCAN_MAX_PAGES);
    $pagesOk = 0;
    if ($pages) {
        foreach (multi_fetch($pages, SCAN_PAGE_MAX_BYTES, 15, 8, $deadline - 25) as $pu => $r) {
            if (!isset($r['body'])) continue;
            $pagesOk++;
            // La "sottopagina" era in realtà un feed (es. /feeds/notizie.xml).
            if (($ft = sniff_feed($r['body'])) !== null) {
                if (!isset($cand[$pu])) $cand[$pu] = ['name' => $ft, 'category' => $baseCategory];
                continue;
            }
            $cat = page_heading($r['body']);
            foreach (extract_from_page($r['body'], $r['url'], $pageUrl)['feeds'] as $u => $name) {
                if (!isset($cand[$u])) {
                    $cand[$u] = ['name' => $name, 'category' => $cat];
                } else {
                    if ($cat !== '') $cand[$u]['category'] = $cat;
                    if ($cand[$u]['name'] === '' && $name !== '') $cand[$u]['name'] = $name;
                }
            }
        }
    }

    // La pagina stessa non è un candidato.
    unset($cand[$pageUrl]);
    $total = count($cand);
    $cand = array_slice($cand, 0, SCAN_MAX_CANDIDATES, true);

    // Si tengono solo i veri feed (primi 64 KB bastano a riconoscerli).
    $feeds = [];
    $notFeeds = 0;
    foreach (multi_fetch(array_keys($cand), 65536, 12, 16, $deadline) as $u => $r) {
        $title = isset($r['body']) ? sniff_feed($r['body']) : null;
        if ($title === null) { $notFeeds++; continue; }
        $name = $cand[$u]['name'];
        // Il nome nella pagina è spesso "RSS" o l'indirizzo stesso: meglio il titolo del feed.
        if ($name === '' || preg_match('#^(rss|feed|atom|xml|rss feed|subscribe)$#i', $name) || preg_match('#^https?://#i', $name)) {
            $name = $title;
        }
        $feeds[] = [
            'title'    => $name,
            'url'      => $u,
            'html_url' => '',
            'category' => $cand[$u]['category'],
        ];
    }
    // Niente nella pagina: si provano gli indirizzi più comuni del sito
    // (molti siti hanno /feed/ o /rss.xml senza dichiararlo nella home).
    if (!$feeds && microtime(true) < $deadline) {
        $o = parse_url($pageUrl);
        $origin = $o['scheme'] . '://' . $o['host'] . (isset($o['port']) ? ':' . $o['port'] : '');
        $guess = [];
        foreach (['/feed', '/feed/', '/rss', '/rss/', '/rss.xml', '/feed.xml', '/atom.xml', '/index.xml', '/feeds/posts/default', '/?feed=rss2'] as $path) {
            $guess[$origin . $path] = true;
        }
        $seenFinal = [];
        foreach (multi_fetch(array_keys($guess), 65536, 10, 8, $deadline) as $u => $r) {
            $title = isset($r['body']) ? sniff_feed($r['body']) : null;
            if ($title === null) continue;
            // Più indirizzi possono portare allo stesso feed (redirect): uno solo.
            $k = url_key($r['url']);
            if (isset($seenFinal[$k])) continue;
            $seenFinal[$k] = true;
            $cand[$u] = ['name' => $title, 'category' => $baseCategory];
            $feeds[] = ['title' => $title, 'url' => $u, 'html_url' => '', 'category' => $baseCategory];
        }
    }

    // Stesso ordine della pagina.
    $order = array_flip(array_keys($cand));
    usort($feeds, fn($a, $b) => ($order[$a['url']] ?? 0) <=> ($order[$b['url']] ?? 0));

    return [
        'feeds'              => $feeds,
        'pages_scanned'      => 1 + $pagesOk,
        'candidates_checked' => count($cand),
        'candidates_skipped' => $total - count($cand),
        'not_feeds'          => $notFeeds,
        'timed_out'          => microtime(true) > $deadline,
    ];
}

// ─── action=list ────────────────────────────────────────────────────────────
function walk_outlines(SimpleXMLElement $node, array $path, array &$feeds, int &$invalid): void {
    foreach ($node->outline as $o) {
        $attrs = [];
        foreach ($o->attributes() as $k => $v) $attrs[strtolower((string) $k)] = (string) $v;
        $label = clean_text($attrs['title'] ?? ($attrs['text'] ?? ''), 80);
        $xmlUrl = trim($attrs['xmlurl'] ?? '');
        if ($xmlUrl !== '') {
            if (count($feeds) >= OPML_MAX_FEEDS) continue;
            if (!filter_var($xmlUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $xmlUrl)) {
                $invalid++;
                continue;
            }
            $feeds[] = [
                'title'    => $label,
                'url'      => $xmlUrl,
                'html_url' => trim($attrs['htmlurl'] ?? ''),
                'category' => implode(' / ', array_filter($path, fn($x) => $x !== '')),
            ];
        }
        if (isset($o->outline)) {
            walk_outlines($o, $label !== '' ? array_merge($path, [$label]) : $path, $feeds, $invalid);
        }
    }
}

// Doppioni, fonti già presenti in config.php, categorie: uguale per OPML,
// pagina web e feed singolo.
function list_response(array $feeds, int $invalid, array $extra): void {
    $existing = [];
    foreach (existing_sources() as $s) {
        if (!empty($s['url'])) $existing[url_key((string) $s['url'])] = true;
    }
    $seen = [];
    $out = [];
    $dups = 0;
    foreach ($feeds as $f) {
        $key = url_key($f['url']);
        if (isset($seen[$key])) { $dups++; continue; }
        $seen[$key] = true;
        if ($f['title'] === '') {
            $f['title'] = preg_replace('/^www\./i', '', (string) parse_url($f['url'], PHP_URL_HOST));
        }
        $f['id'] = count($out);
        $f['exists'] = isset($existing[$key]);
        $out[] = $f;
    }

    $cats = [];
    foreach ($out as $f) {
        $c = $f['category'] !== '' ? $f['category'] : '';
        $cats[$c] = ($cats[$c] ?? 0) + 1;
    }
    $catList = [];
    foreach ($cats as $name => $n) $catList[] = ['name' => (string) $name, 'count' => $n];

    json_out(array_merge([
        'ok'                 => true,
        'mode'               => 'opml',
        'opml_title'         => '',
        'converted'          => false,
        'total'              => count($out),
        'skipped_invalid'    => $invalid,
        'duplicates_in_file' => $dups,
        'categories'         => $catList,
        'feeds'              => $out,
    ], $extra));
}

function action_list(): void {
    [$url, $converted] = normalize_opml_url((string) ($_GET['url'] ?? ''));
    if ($url === '' || !preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        json_out(['ok' => false, 'error' => 'Indirizzo mancante o non valido (serve http/https)'], 400);
    }
    try {
        [$raw, $finalUrl, , $truncated] = fetch_remote($url, OPML_MAX_BYTES, 25);
        $trim = ltrim($raw, "\xEF\xBB\xBF \t\r\n");
        $start = substr($trim, 0, 1000);

        // Pagina web: si cercano i feed nella pagina e nelle sue sottopagine.
        if (stripos($start, '<html') !== false || stripos($start, '<!doctype html') !== false) {
            set_time_limit((int) SCAN_TIME_BUDGET + 40);
            $title = page_heading($raw);
            $scan = scan_web_page($raw, $finalUrl, $title);
            if (!$scan['feeds']) {
                throw new RuntimeException('nella pagina non ho trovato nessun feed RSS/Atom ('
                    . $scan['candidates_checked'] . ' indirizzi controllati su ' . $scan['pages_scanned'] . ' pagine, più gli indirizzi comuni come /feed e /rss.xml). '
                    . 'Se l\'elenco compare solo dopo il caricamento della pagina (JavaScript), il server non lo vede.');
            }
            list_response($scan['feeds'], 0, [
                'mode'               => 'page',
                'opml_title'         => $title,
                'source_url'         => $finalUrl,
                'pages_scanned'      => $scan['pages_scanned'],
                'candidates_checked' => $scan['candidates_checked'],
                'candidates_skipped' => $scan['candidates_skipped'],
                'not_feeds'          => $scan['not_feeds'],
                'timed_out'          => $scan['timed_out'],
            ]);
        }

        if ($truncated) {
            throw new RuntimeException('il file OPML supera ' . (int) (OPML_MAX_BYTES / 1000000) . ' MB: troppo grande');
        }
        $xml = load_xml($raw);
        $root = strtolower($xml->getName());

        // L'indirizzo è già un feed: si propone solo quello.
        if (in_array($root, ['rss', 'feed', 'rdf'], true)) {
            $t = sniff_feed($raw);
            list_response([['title' => (string) $t, 'url' => $finalUrl, 'html_url' => '', 'category' => '']], 0, [
                'mode' => 'feed', 'opml_title' => (string) $t, 'source_url' => $finalUrl,
            ]);
        }

        if ($root !== 'opml' || !isset($xml->body)) {
            throw new RuntimeException('il file non è un OPML (manca <opml><body>) né una pagina web o un feed');
        }

        $feeds = [];
        $invalid = 0;
        walk_outlines($xml->body, [], $feeds, $invalid);
        $title = isset($xml->head->title) ? clean_text((string) $xml->head->title, 120) : '';
        list_response($feeds, $invalid, [
            'mode'       => 'opml',
            'opml_title' => $title,
            'source_url' => $finalUrl,
            'converted'  => $converted,
        ]);
    } catch (\Throwable $e) {
        json_out(['ok' => false, 'error' => $e->getMessage()], 502);
    }
}

// ─── action=preview ─────────────────────────────────────────────────────────
// Se il download è stato interrotto a metà, tiene solo gli articoli completi e
// richiude l'XML, così i primi articoli si leggono comunque.
function salvage_truncated(string $raw): string {
    $posItem = strripos($raw, '</item>');
    $posEntry = strripos($raw, '</entry>');
    if ($posItem === false && $posEntry === false) return $raw;
    // Si taglia dopo l'ultimo articolo completo e si richiude nel modo giusto
    // per il formato: Atom (<feed>), RSS 1.0 (<rdf:RDF>) o RSS 2.0 (<rss>).
    if ($posEntry !== false && ($posItem === false || $posEntry > $posItem)) {
        return substr($raw, 0, $posEntry + strlen('</entry>')) . '</feed>';
    }
    $head = substr($raw, 0, $posItem + strlen('</item>'));
    if (stripos($raw, '<rdf:RDF') !== false) return $head . '</rdf:RDF>';
    return $head . '</channel></rss>';
}

function item_date(array $candidates): string {
    foreach ($candidates as $c) {
        $c = trim((string) $c);
        if ($c === '') continue;
        $ts = strtotime($c);
        if ($ts !== false && $ts > 86400) return gmdate('c', $ts);
        return clean_text($c, 40);
    }
    return '';
}

function parse_feed_preview(SimpleXMLElement $xml, bool $partial): array {
    $root = strtolower($xml->getName());
    $items = [];
    $feedTitle = '';
    $format = null;
    $total = 0;

    if ($root === 'rss' && isset($xml->channel)) {
        $format = 'rss';
        $feedTitle = clean_text((string) $xml->channel->title, 120);
        $total = count($xml->channel->item);
        foreach ($xml->channel->item as $it) {
            $dc = $it->children('http://purl.org/dc/elements/1.1/');
            $items[] = [
                'title' => clean_text((string) $it->title, 140),
                'date'  => item_date([$it->pubDate ?? '', $dc->date ?? '']),
                'link'  => trim((string) $it->link),
            ];
            if (count($items) >= PREVIEW_MAX_ITEMS) break;
        }
    } elseif ($root === 'feed') {
        $format = 'atom';
        $feedTitle = clean_text((string) $xml->title, 120);
        $total = count($xml->entry);
        foreach ($xml->entry as $en) {
            $link = '';
            foreach ($en->link as $l) {
                $rel = (string) $l['rel'];
                if ($rel === '' || $rel === 'alternate') { $link = (string) $l['href']; break; }
            }
            $items[] = [
                'title' => clean_text((string) $en->title, 140),
                'date'  => item_date([$en->published ?? '', $en->updated ?? '']),
                'link'  => trim($link),
            ];
            if (count($items) >= PREVIEW_MAX_ITEMS) break;
        }
    } elseif ($root === 'rdf') {
        // RSS 1.0: <rdf:RDF> con <channel> e <item> allo stesso livello.
        $format = 'rdf';
        $rss = $xml->children('http://purl.org/rss/1.0/');
        $feedTitle = clean_text((string) $rss->channel->title, 120);
        $total = count($rss->item);
        foreach ($rss->item as $it) {
            $dc = $it->children('http://purl.org/dc/elements/1.1/');
            $items[] = [
                'title' => clean_text((string) $it->title, 140),
                'date'  => item_date([$dc->date ?? '']),
                'link'  => trim((string) $it->link),
            ];
            if (count($items) >= PREVIEW_MAX_ITEMS) break;
        }
    }
    if ($format === null) {
        throw new RuntimeException('XML valido ma non è un feed RSS/Atom (manca <channel><item> o <feed><entry>)');
    }
    $items = array_values(array_filter($items, fn($i) => $i['title'] !== ''));
    return ['format' => $format, 'feed_title' => $feedTitle, 'items_found' => $total, 'items_found_partial' => $partial, 'items' => $items];
}

function action_preview(): void {
    $url = trim((string) ($_GET['url'] ?? ''));
    if ($url === '' || !preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        json_out(['ok' => false, 'error' => 'Indirizzo del feed mancante o non valido'], 400);
    }

    $cacheDir = __DIR__ . '/cache/opml_previews';
    $cacheFile = $cacheDir . '/' . sha1($url) . '.json';
    $refresh = !empty($_GET['refresh']);
    if (!$refresh && is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < PREVIEW_CACHE_TTL) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached['ok'])) {
            $cached['cached'] = true;
            json_out($cached);
        }
    }

    try {
        // Si ferma appena ha scaricato abbastanza articoli completi.
        $stop = function (string $buf): bool {
            return substr_count($buf, '</item>') >= PREVIEW_MAX_ITEMS + 2
                || substr_count($buf, '</entry>') >= PREVIEW_MAX_ITEMS + 2;
        };
        [$raw, , , $stopped] = fetch_remote($url, 1500000, 15, $stop);
        $xml = null;
        try {
            $xml = load_xml($raw);
        } catch (\RuntimeException $e) {
            if (!$stopped) throw $e;
            $xml = load_xml(salvage_truncated($raw)); // download interrotto da noi: ripara e riprova
        }
        $res = parse_feed_preview($xml, $stopped);
        if (!$res['items']) throw new RuntimeException('il feed non contiene articoli');
        $res = ['ok' => true, 'cached' => false] + $res;

        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        if (is_dir($cacheDir) && is_writable($cacheDir)) {
            $json = json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json !== false) @file_put_contents($cacheFile, $json, LOCK_EX);
            if (mt_rand(1, 20) === 1) {
                $files = glob($cacheDir . '/*.json') ?: [];
                usort($files, fn($a, $b) => filemtime($a) <=> filemtime($b));
                $excess = count($files) - PREVIEW_MAX_CACHED;
                foreach ($files as $f) {
                    if ($excess > 0 || (time() - filemtime($f)) > 3 * 86400) { @unlink($f); $excess--; }
                }
            }
        }
        json_out($res);
    } catch (\Throwable $e) {
        json_out(['ok' => false, 'error' => $e->getMessage()], 502);
    }
}

// ─── Routing ────────────────────────────────────────────────────────────────
$action = (string) ($_GET['action'] ?? '');
if ($action === 'list') action_list();
if ($action === 'preview') action_preview();
json_out(['ok' => false, 'error' => 'action non riconosciuta (list | preview)'], 400);
