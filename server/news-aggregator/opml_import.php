<?php
declare(strict_types=1);

// opml_import.php
// Backend della pagina "Importa fonti da OPML" (opml_import.html): legge un
// file OPML (l'elenco di feed RSS che molti lettori sanno esportare) e fa
// l'anteprima dei singoli feed. NON modifica config.php: l'aggiunta delle
// fonti la fa la pagina chiamando sources_admin.php, cioè lo stesso codice che
// già scrive config.php per l'editor della card.
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

const OPML_IMPORT_VERSION = '1.0.0';
const OPML_IMPORT_BUILD   = '2026-09-28-01';

const OPML_MAX_BYTES        = 3000000;   // dimensione massima del file OPML
const OPML_MAX_FEEDS        = 3000;      // oltre questo numero il resto è ignorato
const PREVIEW_MAX_ITEMS     = 4;
const PREVIEW_CACHE_TTL     = 6 * 3600;
const PREVIEW_MAX_CACHED    = 1500;
const FETCH_MAX_REDIRS      = 4;

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

function action_list(): void {
    [$url, $converted] = normalize_opml_url((string) ($_GET['url'] ?? ''));
    if ($url === '' || !preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        json_out(['ok' => false, 'error' => 'Indirizzo OPML mancante o non valido (serve http/https)'], 400);
    }
    try {
        [$raw, $finalUrl, , $truncated] = fetch_remote($url, OPML_MAX_BYTES, 25);
        if ($truncated) {
            throw new RuntimeException('il file OPML supera ' . (int) (OPML_MAX_BYTES / 1000000) . ' MB: troppo grande');
        }
        $trim = ltrim($raw, "\xEF\xBB\xBF \t\r\n");
        if (stripos(substr($trim, 0, 400), '<html') !== false || stripos(substr($trim, 0, 400), '<!doctype html') !== false) {
            throw new RuntimeException('l\'indirizzo restituisce una pagina web, non un file OPML (se è GitHub serve il link al file, anche "blob" va bene)');
        }
        $xml = load_xml($raw);
        if (strtolower($xml->getName()) !== 'opml' || !isset($xml->body)) {
            throw new RuntimeException('il file non è un OPML (manca <opml><body>)');
        }

        $feeds = [];
        $invalid = 0;
        walk_outlines($xml->body, [], $feeds, $invalid);

        // Duplicati dentro il file e già presenti in config.php.
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

        $title = isset($xml->head->title) ? clean_text((string) $xml->head->title, 120) : '';
        json_out([
            'ok'                 => true,
            'opml_title'         => $title,
            'source_url'         => $finalUrl,
            'converted'          => $converted,
            'total'              => count($out),
            'skipped_invalid'    => $invalid,
            'duplicates_in_file' => $dups,
            'categories'         => $catList,
            'feeds'              => $out,
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
