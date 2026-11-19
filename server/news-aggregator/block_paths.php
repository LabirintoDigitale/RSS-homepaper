<?php
// block_paths.php
// Endpoint di amministrazione per l'elenco di pattern di URL da escludere
// dal prossimo rebuild_cache.php. Stesso identico impianto di
// sources_admin.php (stesso token, stesso stile CORS/auth/risposta), solo
// che invece di leggere/scrivere config.php legge/scrive
// cache/blocked_paths.json.
//
// Usato dalla card: long-press su una notizia -> popup "Blocca le notizie
// da questo percorso" -> POST action:"add" col pattern estratto dal link.
//
// Sicurezza: richiede lo stesso api_token di settings.php, passato come
// header "X-API-Token" oppure come query string ?token=...
//
// Contratto:
//   GET  block_paths.php                         -> { ok:true, blocked:[ {id, pattern, note, created}, ... ] }
//   POST block_paths.php  body JSON:
//        { action:"add",    pattern:"/articoli/", note:"opzionale" }
//        { action:"delete", id:<int> }
//
// "id" = timestamp in millisecondi al momento della creazione (univoco a
// sufficienza per un pannello ad uso singolo/interno, come già fa il resto
// del progetto per cose simili).

declare(strict_types=1);

// ─── CORS ───────────────────────────────────────────────────────────────────
// Stessa identica logica di sources_admin.php: la card gira sull'origine di
// Home Assistant, questo script su un'altra origine (il server news-
// aggregator) — ogni fetch() è quindi cross-origin, con preflight OPTIONS.
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
$BLOCKED_FILE = __DIR__ . '/cache/blocked_paths.json';
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
function read_blocked(string $file): array {
    if (!file_exists($file)) return [];
    $raw = file_get_contents($file);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

// Stessa diagnostica dettagliata di sources_admin.php: invece del generico
// "impossibile scrivere", dice esattamente permessi/proprietario/utente PHP,
// utile perché cache/ storicamente è la cartella con più problemi di
// permessi in questo progetto (vedi rebuild_cache.php).
function file_diag(string $file): string {
    $dir = dirname($file);
    if (!file_exists($file)) {
        $dirExists = is_dir($dir) ? 'sì' : 'NO (verrà creata)';
        $dirWritable = is_dir($dir) ? (is_writable($dir) ? 'sì' : 'NO') : 'n/d';
        return sprintf(
            "il file non esiste; cartella '%s' esiste: %s, scrivibile dal processo PHP: %s",
            $dir, $dirExists, $dirWritable
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
        $perms, $ownerName ?? '?', $uid, $groupName ?? '?', $gid,
        $procUser ?? '?', $procUid ?? '?', is_writable($file) ? 'sì' : 'NO'
    );
}

function write_blocked(string $file, array $list): void {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $fh = @fopen($file, 'c+');
    if (!$fh) {
        $lastError = error_get_last();
        $phpReason = $lastError['message'] ?? 'motivo sconosciuto';
        throw new RuntimeException(
            'Impossibile aprire blocked_paths.json in scrittura — ' . $phpReason . '. Diagnostica: ' . file_diag($file)
        );
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        throw new RuntimeException('Impossibile ottenere il lock su blocked_paths.json (file bloccato da un altro processo?)');
    }
    $json = json_encode(array_values($list), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, $json);
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

// ─── Validazione di un pattern in ingresso ──────────────────────────────────
function validate_pattern(array $in): array {
    $pattern = trim((string)($in['pattern'] ?? ''));
    $note = trim((string)($in['note'] ?? ''));
    if ($pattern === '') throw new InvalidArgumentException('Il pattern è obbligatorio');
    return ['pattern' => $pattern, 'note' => $note];
}

// ─── Routing ─────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        json_out(['ok' => true, 'blocked' => read_blocked($BLOCKED_FILE)]);
    }

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) json_out(['ok' => false, 'error' => 'body JSON non valido'], 400);

        $action = $body['action'] ?? '';
        $list = read_blocked($BLOCKED_FILE);

        switch ($action) {
            case 'add':
                $entry = validate_pattern($body);
                // Evita duplicati esatti dello stesso pattern.
                foreach ($list as $existing) {
                    if (($existing['pattern'] ?? '') === $entry['pattern']) {
                        json_out(['ok' => true, 'blocked' => $list, 'note' => 'pattern già presente']);
                    }
                }
                $new = [
                    'id'      => (int) round(microtime(true) * 1000),
                    'pattern' => $entry['pattern'],
                    'note'    => $entry['note'],
                    'created' => date('c'),
                ];
                $list[] = $new;
                write_blocked($BLOCKED_FILE, $list);
                json_out(['ok' => true, 'blocked' => $list, 'entry' => $new]);
                break;

            case 'delete':
                $id = $body['id'] ?? null;
                if (!is_int($id) && !ctype_digit((string)$id)) json_out(['ok' => false, 'error' => 'id mancante o non valido'], 400);
                $id = (int)$id;
                $before = count($list);
                $list = array_values(array_filter($list, fn($e) => (int)($e['id'] ?? 0) !== $id));
                if (count($list) === $before) json_out(['ok' => false, 'error' => 'pattern non trovato'], 404);
                write_blocked($BLOCKED_FILE, $list);
                json_out(['ok' => true, 'blocked' => $list]);
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
