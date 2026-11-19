<?php
// test_feed.php
// Endpoint leggero e SENZA effetti collaterali per il bottone "Verifica"
// nell'editor della card: prende un URL (anche non ancora salvato in
// config.php — tipicamente il valore attuale digitato nel campo, prima di
// premere salva) e risponde con quanti articoli contiene il feed.
//
// Non tocca config.php, cache.json, state.json né debug.log: niente
// scraping og:image, niente decodifica Google News, niente traduzione —
// solo fetch + parse XML + conteggio, così è rapido e non interferisce
// col ciclo di rebuild_cache.php.
//
// Contratto:
//   GET  test_feed.php?token=...&url=https%3A%2F%2Fesempio.it%2Ffeed
//        -> { ok:true, format:"rss"|"atom", items_found:N, sample_titles:[...] }
//        -> { ok:false, error:"..." }

declare(strict_types=1);

$ALLOWED_ORIGIN = '*'; // TODO: restringere all'origine reale di HA in produzione

header("Access-Control-Allow-Origin: $ALLOWED_ORIGIN");
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Token');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$settings = require __DIR__ . '/settings.php';
$expectedToken = $settings['api_token'] ?? null;

function json_out(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$providedToken = $_SERVER['HTTP_X_API_TOKEN'] ?? ($_GET['token'] ?? null);
if (!$expectedToken || !$providedToken || !hash_equals((string)$expectedToken, (string)$providedToken)) {
    json_out(['ok' => false, 'error' => 'unauthorized'], 401);
}

$url = trim((string) ($_GET['url'] ?? ''));
if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
    json_out(['ok' => false, 'error' => 'URL mancante o non valido']);
}

// Stesso User-Agent "da browser vero" usato da rebuild_cache.php: molti
// siti WordPress con plugin di sicurezza trattano le richieste senza uno
// User-Agent riconoscibile come sospette (bot), restituendo una pagina
// diversa dal feed vero.
$UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERAGENT      => $UA,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 12,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER     => [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: it-IT,it;q=0.9,en-US;q=0.8,en;q=0.7',
    ],
]);
$xml_raw = curl_exec($ch);
$curl_err = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($xml_raw === false || $xml_raw === '') {
    json_out([
        'ok' => false,
        'error' => 'Richiesta HTTP fallita' . ($curl_err !== '' ? " ($curl_err)" : '') . " — http_code=$http_code",
    ]);
}

libxml_use_internal_errors(true);
$xml = simplexml_load_string($xml_raw);
if (!$xml) {
    $xml_errors = array_map(fn($e) => trim($e->message), libxml_get_errors());
    libxml_clear_errors();
    json_out([
        'ok' => false,
        'error' => 'Il contenuto scaricato non è XML valido (possibile pagina di errore/blocco al posto del feed). http_code=' . $http_code,
        'xml_errors' => array_slice($xml_errors, 0, 3),
        'http_code' => $http_code,
    ]);
}

// Stessa logica di rilevamento formato di rebuild_cache.php: RSS 2.0
// (<rss><channel><item>) oppure Atom (<feed><entry>), a prescindere dal
// 'type' scelto nel form (che distingue solo standard/google_news, non
// il "dialetto" XML).
$format = null;
if (isset($xml->channel->item)) {
    $format = 'rss';
} elseif ($xml->getName() === 'feed' && isset($xml->entry)) {
    $format = 'atom';
}

if ($format === null) {
    json_out([
        'ok' => false,
        'error' => 'XML valido ma senza <channel><item> (RSS) né <feed><entry> (Atom) — feed raggiunto ma vuoto o in formato non riconosciuto.',
        'http_code' => $http_code,
    ]);
}

$entries = $format === 'rss' ? $xml->channel->item : $xml->entry;
$items_found = count($entries);

$sample_items = [];
$i = 0;
foreach ($entries as $item) {
    if ($i >= 3) break;
    $title = trim((string) ($item->title ?? ''));
    $pubDate = '';
    if ($format === 'rss') {
        $pubDate = trim((string) ($item->pubDate ?? ''));
    } else {
        // Atom: <published> se presente, altrimenti <updated>.
        $pubDate = trim((string) ($item->published ?? $item->updated ?? ''));
    }
    if ($title !== '') $sample_items[] = ['title' => $title, 'pubDate' => $pubDate];
    $i++;
}

json_out([
    'ok' => true,
    'format' => $format,
    'items_found' => $items_found,
    'sample_items' => $sample_items,
    'http_code' => $http_code,
]);
