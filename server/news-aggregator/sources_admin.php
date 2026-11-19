<?php
// sources_admin.php
// Endpoint di amministrazione per l'array delle fonti RSS in config.php.
// Permette a un client (es. l'editor grafico della card in Home Assistant)
// di leggere/aggiungere/modificare/cancellare le fonti senza toccare
// a mano il file config.php.
//
// Sicurezza: richiede lo stesso api_token già definito in settings.php,
// passato come header "X-API-Token" oppure come query string ?token=...
//
// Contratto:
//   GET  sources_admin.php                       -> { ok:true, sources:[ {id, name, url, type, color}, ... ] }
//   POST sources_admin.php  body JSON:
//        { action:"add",    source:{name,url,type,color} }
//        { action:"edit",   id:<int>, source:{name,url,type,color} }
//        { action:"delete", id:<int> }
//
// "color": stringa esadecimale opzionale (es. "#1a73e8") che identifica la
// fonte nella card — usata al posto del blu di default per la categoria
// dell'articolo. Se assente/vuota, la card usa il colore predefinito.
//
// "id" = indice posizionale nell'array al momento della richiesta.
// Va bene per un pannello ad uso singolo/interno; se in futuro serve
// robustezza contro modifiche concorrenti, si può aggiungere un campo
// "id" persistente ad ogni fonte.

declare(strict_types=1);

// ─── CORS ───────────────────────────────────────────────────────────────────
// Consente le richieste dall'origine del frontend Home Assistant.
// Metti qui l'origine esatta (schema+host+porta) del tuo HA, es:
// 'https://homeassistant.tuodominio.it'
$ALLOWED_ORIGIN = '*'; // TODO: restringere all'origine reale di HA in produzione

header("Access-Control-Allow-Origin: $ALLOWED_ORIGIN");
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Token');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── Config / auth ──────────────────────────────────────────────────────────
$CONFIG_FILE = __DIR__ . '/config.php';
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

// ─── Lettura / scrittura sicura del file ────────────────────────────────────
function read_sources(string $file): array {
    if (!file_exists($file)) return [];
    // require in un ambito isolato per non "sporcare" le variabili locali
    $sources = (function (string $f) { return require $f; })($file);
    return is_array($sources) ? $sources : [];
}

// Costruisce un messaggio diagnostico dettagliato su un file: esistenza,
// proprietario/gruppo (nome se possibile, altrimenti uid/gid numerico),
// permessi ottali, e se è scrivibile dal processo PHP corrente. Usato per
// dare un errore utile invece del generico "impossibile aprire in scrittura".
function file_diag(string $file): string {
    if (!file_exists($file)) {
        $dir = dirname($file);
        $dirWritable = is_writable($dir) ? 'sì' : 'NO';
        return sprintf(
            "il file non esiste; la cartella '%s' è scrivibile dal processo PHP: %s",
            $dir,
            $dirWritable
        );
    }
    clearstatcache(true, $file);
    $perms = substr(sprintf('%o', fileperms($file)), -4);
    $uid = fileowner($file);
    $gid = filegroup($file);
    $ownerName = function_exists('posix_getpwuid') ? (posix_getpwuid($uid)['name'] ?? null) : null;
    $groupName = function_exists('posix_getgrgid') ? (posix_getgrgid($gid)['name'] ?? null) : null;
    $procUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
    $procUser = ($procUid !== null && function_exists('posix_getpwuid')) ? (posix_getpwuid($procUid)['name'] ?? null) : null;

    return sprintf(
        "file esistente, permessi %s, proprietario %s(%d):%s(%d); il processo PHP gira come %s(%s); is_writable(): %s",
        $perms,
        $ownerName ?? '?', $uid,
        $groupName ?? '?', $gid,
        $procUser ?? '?', $procUid ?? '?',
        is_writable($file) ? 'sì' : 'NO'
    );
}

function write_sources(string $file, array $sources): void {
    $fh = @fopen($file, 'c+');
    if (!$fh) {
        $lastError = error_get_last();
        $phpReason = $lastError['message'] ?? 'motivo sconosciuto';
        throw new RuntimeException(
            'Impossibile aprire config.php in scrittura — ' . $phpReason . '. Diagnostica: ' . file_diag($file)
        );
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        throw new RuntimeException('Impossibile ottenere il lock su config.php (file bloccato da un altro processo?)');
    }

    $header = "<?php\n"
        . "// config.php\n"
        . "// Elenco delle fonti RSS da aggregare.\n"
        . "// Generato/aggiornato da sources_admin.php.\n"
        . "//\n"
        . "// type:\n"
        . "//   'google_news' -> il link va decodificato (redirect cifrato) e poi\n"
        . "//                    va fatto scraping della pagina reale per l'og:image\n"
        . "//   'standard'    -> feed \"normale\" che di solito ha già <enclosure> o\n"
        . "//                    <media:thumbnail>/<media:content> con l'immagine\n\n"
        . "return " . array_to_php_literal($sources) . ";\n";

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, $header);
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    // Invalida esplicitamente la entry OPcache di config.php: senza questo,
    // il prossimo require($file) (qui sotto in read_sources(), o nella card/
    // editor che chiama subito dopo una GET per rileggere l'elenco) può
    // restituire la versione COMPILATA precedente invece di quella appena
    // scritta, finché OPcache non rivaluta da sé il file (dipende da
    // opcache.validate_timestamps/opcache.revalidate_freq in php.ini). Questo
    // è il motivo per cui una fonte appena aggiunta/modificata "non si vede"
    // subito e serve uscire e rientrare nell'editor: nel frattempo passa
    // abbastanza tempo perché OPcache si accorga della modifica da solo.
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($file, true);
    }
}

// Genera un array literal PHP con sintassi corta [ ... ], leggibile,
// invece del formato verboso di var_export().
function array_to_php_literal(array $arr, int $indent = 0): string {
    $pad = str_repeat('    ', $indent);
    $padIn = str_repeat('    ', $indent + 1);
    $isList = array_keys($arr) === range(0, count($arr) - 1);

    if (empty($arr)) return '[]';

    $lines = [];
    foreach ($arr as $k => $v) {
        $keyPart = $isList ? '' : var_export((string)$k, true) . ' => ';
        if (is_array($v)) {
            $lines[] = $padIn . $keyPart . array_to_php_literal($v, $indent + 1) . ',';
        } else {
            $lines[] = $padIn . $keyPart . var_export($v, true) . ',';
        }
    }
    return "[\n" . implode("\n", $lines) . "\n" . $pad . "]";
}

// ─── Validazione di una fonte in ingresso ───────────────────────────────────
function validate_source(array $in): array {
    $name = trim((string)($in['name'] ?? ''));
    $url  = trim((string)($in['url'] ?? ''));
    $type = (string)($in['type'] ?? 'standard');
    $color = trim((string)($in['color'] ?? ''));

    if ($name === '') throw new InvalidArgumentException('Il nome della fonte è obbligatorio');
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) throw new InvalidArgumentException('URL non valido');
    if (!in_array($type, ['standard', 'google_news'], true)) throw new InvalidArgumentException('Type non valido (standard|google_news)');
    // Colore opzionale: se presente deve essere un hex valido (#rgb o #rrggbb).
    // Stringa vuota = nessun colore personalizzato, la card userà il default.
    if ($color !== '' && !preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) {
        throw new InvalidArgumentException('Colore non valido (usa formato esadecimale es. #1a73e8)');
    }

    return ['name' => $name, 'url' => $url, 'type' => $type, 'color' => $color];
}

// ─── Routing ─────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $sources = read_sources($CONFIG_FILE);
        $out = [];
        foreach ($sources as $i => $s) {
            $out[] = [
                'id'        => $i,
                'name'      => $s['name'] ?? '',
                'url'       => $s['url'] ?? '',
                'type'      => $s['type'] ?? 'standard',
                'color'     => $s['color'] ?? '',
            ];
        }
        // Aggiunge source_stats.json alla risposta GET: il JS legge last_checked,
        // items_found e items_downloaded dallo stesso endpoint già autenticato e
        // con CORS, senza dover fare un secondo fetch su un file statico.
        $stats = [];
        $stats_file = __DIR__ . '/cache/source_stats.json';
        if (file_exists($stats_file)) {
            $s = json_decode((string) file_get_contents($stats_file), true);
            if (is_array($s)) $stats = $s;
        }
        json_out(['ok' => true, 'sources' => $out, 'stats' => $stats]);
    }

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) json_out(['ok' => false, 'error' => 'body JSON non valido'], 400);

        $action = $body['action'] ?? '';
        $sources = read_sources($CONFIG_FILE);

        switch ($action) {
            case 'add':
                $newSource = validate_source($body['source'] ?? []);
                $sources[] = $newSource;
                write_sources($CONFIG_FILE, $sources);
                json_out(['ok' => true, 'id' => count($sources) - 1, 'source' => $newSource]);
                break;

            case 'edit':
                $id = $body['id'] ?? null;
                if (!is_int($id) && !ctype_digit((string)$id)) json_out(['ok' => false, 'error' => 'id mancante o non valido'], 400);
                $id = (int)$id;
                if (!isset($sources[$id])) json_out(['ok' => false, 'error' => 'fonte non trovata'], 404);
                $updated = validate_source($body['source'] ?? []);
                $sources[$id] = $updated;
                write_sources($CONFIG_FILE, $sources);
                json_out(['ok' => true, 'id' => $id, 'source' => $updated]);
                break;

            case 'delete':
                $id = $body['id'] ?? null;
                if (!is_int($id) && !ctype_digit((string)$id)) json_out(['ok' => false, 'error' => 'id mancante o non valido'], 400);
                $id = (int)$id;
                if (!isset($sources[$id])) json_out(['ok' => false, 'error' => 'fonte non trovata'], 404);
                array_splice($sources, $id, 1);
                write_sources($CONFIG_FILE, $sources);
                json_out(['ok' => true]);
                break;

            default:
                json_out(['ok' => false, 'error' => 'action non riconosciuta'], 400);
        }
    }

    json_out(['ok' => false, 'error' => 'metodo non supportato'], 405);

} catch (InvalidArgumentException $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'errore interno: ' . $e->getMessage()], 500);
}
