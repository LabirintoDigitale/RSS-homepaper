<?php
declare(strict_types=1);

// translate_admin.php
// Impostazioni di "dove tradurre", per l'editor della card. A tradurre sono gli
// script PHP (rebuild_cache.php e summarize.php), non la card: per questo le
// impostazioni stanno sul server (cache/translate_settings.json) e la card le
// legge e le salva da qui, con lo stesso token delle altre pagine di
// amministrazione (sources_admin.php).
//
// Contratto (token in X-API-Token oppure ?token=):
//   GET  translate_admin.php
//        -> { ok, settings:{provider, ollama:{host,port,model,fallback_google}},
//             status:{ google:{cooldown_until,level,last_code,hour_calls,hourly_budget},
//                      ollama:{last_ok,last_ms,last_model,last_error,last_error_at},
//                      cache_entries } }
//   POST { "action":"save",   "order":["libre","ollama","google"],  (da 1 a 3 servizi: il primo è la prima scelta, poi i ripieghi in ordine)
//                             "ollama":{host,port,model,threads}, "libre":{host,port,path,source} }
//          (formato precedente, ancora accettato: "provider":"google|ollama|libre" + gli interruttori fallback_* dentro "ollama" / "libre":
//           se manca "order" lo si ricava da lì)
//   POST { "action":"libre_check", "host":"...", "port":11434, "path":"/translate" }   verifica il collegamento (GET /languages)
//   POST { "action":"libre_test",  "host":"...", "port":11434, "path":"/translate", "source":"auto", "text":"(facoltativo)" }
//        -> { ok, settings }          (rifiuta con 400 e un motivo se qualcosa non va)
//   POST { "action":"models", "host":"192.168.1.6", "port":11434 }
//        -> { ok, version, models:[{name,size_mb,params,quant}] }   | { ok:false, error, kind }
//   POST { "action":"test",   "host":..., "port":..., "model":"gemma3:4b", "text":"(facoltativo)", "timeout":90, "threads":0 }
//        -> { ok, translation, ms, model, load_ms, tokens_per_s, prompt_ms, prompt_tokens } | { ok:false, error, kind, loaded? }
//
// Sicurezza: il server PHP contatta l'indirizzo scelto, ma SOLO su /api/tags,
// /api/version e /api/chat di Ollama, in HTTP, senza seguire redirect; IP e
// nomi sono validati (niente indirizzi dei servizi di metadati dei cloud).

const TRANSLATE_ADMIN_VERSION = '1.5.0';
const TRANSLATE_ADMIN_BUILD   = '2026-10-21-07';

header('X-App-Version: ' . TRANSLATE_ADMIN_VERSION . ' (build ' . TRANSLATE_ADMIN_BUILD . ')');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Token');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

set_time_limit(90);
error_reporting(E_ALL & ~E_DEPRECATED);

function ta_out(array $data, int $status = 200): void {
    http_response_code($status);
    $data['version'] = TRANSLATE_ADMIN_VERSION;
    $data['build'] = TRANSLATE_ADMIN_BUILD;
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    echo $json !== false ? $json : '{"ok":false,"error":"json_encode fallito"}';
    exit;
}

// ─── Auth ───────────────────────────────────────────────────────────────────
$settings = require __DIR__ . '/settings.php';
$expected = $settings['api_token'] ?? null;
$given = $_SERVER['HTTP_X_API_TOKEN'] ?? ($_GET['token'] ?? null);
if (!$expected || !$given || !hash_equals((string) $expected, (string) $given)) {
    ta_out(['ok' => false, 'error' => 'unauthorized'], 401);
}

if (!is_file(__DIR__ . '/lib/translate_cache.php')) {
    ta_out(['ok' => false, 'error' => 'manca lib/translate_cache.php: copialo nella cartella lib/'], 500);
}
require __DIR__ . '/lib/translate_cache.php';

// ─── Stato per la schermata ─────────────────────────────────────────────────
function ta_status(): array {
    $st = tc_state_read();
    $o = is_array($st['ollama'] ?? null) ? $st['ollama'] : [];
    $lb = is_array($st['libre'] ?? null) ? $st['libre'] : [];
    $bucket = intdiv(time(), 3600);
    $files = glob(TC_CACHE_DIR . '/translations/*/*.json') ?: [];
    return [
        'google' => [
            'cooldown_until' => (int) ($st['cooldown_until'] ?? 0) > time() ? (int) $st['cooldown_until'] : 0,
            'level'          => (int) ($st['level'] ?? 0),
            'last_code'      => (int) ($st['last_code'] ?? 0),
            'hour_calls'     => (int) ($st['hour'] ?? -1) === $bucket ? (int) ($st['calls'] ?? 0) : 0,
            'hourly_budget'  => TC_HOURLY_BUDGET,
        ],
        'ollama' => [
            'last_ok'       => (int) ($o['last_ok'] ?? 0),
            'last_ms'       => (int) ($o['last_ms'] ?? 0),
            'last_model'    => (string) ($o['last_model'] ?? ''),
            'last_error'    => (string) ($o['last_error'] ?? ''),
            'last_error_at' => (int) ($o['last_error_at'] ?? 0),
        ],
        'libre' => [
            'last_ok'       => (int) ($lb['last_ok'] ?? 0),
            'last_ms'       => (int) ($lb['last_ms'] ?? 0),
            'last_error'    => (string) ($lb['last_error'] ?? ''),
            'last_error_at' => (int) ($lb['last_error_at'] ?? 0),
        ],
        'cache_entries' => count($files),
    ];
}

// ─── GET: impostazioni correnti ─────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    ta_out(['ok' => true, 'settings' => tc_settings(), 'status' => ta_status()]);
}

// ─── POST ───────────────────────────────────────────────────────────────────
$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) ta_out(['ok' => false, 'error' => 'body JSON non valido'], 400);
$action = (string) ($body['action'] ?? '');

// Indirizzo e porta di un server Ollama, controllati.
function ta_target(array $b): array {
    $host = trim((string) ($b['host'] ?? ''));
    $port = ($b['port'] ?? '') === '' || !isset($b['port']) ? TC_OLLAMA_DEFAULT_PORT : $b['port'];
    if (!tc_valid_host($host)) ta_out(['ok' => false, 'error' => 'IP o nome del server non valido', 'kind' => 'input'], 400);
    if (!tc_valid_port($port)) ta_out(['ok' => false, 'error' => 'porta non valida (1-65535)', 'kind' => 'input'], 400);
    return [$host, (int) $port];
}

// Traduttore locale (POST /translate): indirizzo, porta e percorso, controllati.
function ta_libre_target(array $b): array {
    [$host, $port] = ta_target($b);
    $path = trim((string) ($b['path'] ?? '')); if ($path === '') $path = TC_LIBRE_DEFAULT_PATH;
    if (!tc_valid_path($path)) ta_out(['ok' => false, 'error' => 'percorso non valido (es. /translate)', 'kind' => 'input'], 400);
    $src = trim((string) ($b['source'] ?? '')); if ($src === '') $src = 'auto';
    if (!tc_valid_lang_code($src)) ta_out(['ok' => false, 'error' => 'lingua di partenza non valida (auto oppure un codice come "en")', 'kind' => 'input'], 400);
    return [$host, $port, $path, $src];
}

// Verifica del collegamento: il server risponde? ha l'italiano? (GET /languages)
if ($action === 'libre_check') {
    [$host, $port, $path] = ta_libre_target($body);
    ta_out(tc_libre_languages($host, $port, $path));
}

// Prova di una traduzione (nessuna cache, nessuna impostazione toccata).
if ($action === 'libre_test') {
    [$host, $port, $path, $src] = ta_libre_target($body);
    $text = trim((string) ($body['text'] ?? ''));
    if ($text === '') $text = 'Scientists discover that a cup of coffee changes the bacteria in your gut';
    $text = mb_substr($text, 0, 300, 'UTF-8');
    if ($src === 'auto' && tc_guess_lang($text) === 'en') $src = 'en';
    $to = max(3, min(60, (int) ($body['timeout'] ?? 30)));
    $r = tc_libre_translate($host, $port, $path, $text, $src, $to);
    if ($r['ok']) ta_out(['ok' => true, 'translation' => $r['text'], 'ms' => $r['ms'], 'input' => $text, 'source' => $src, 'detected' => $r['detected']]);
    ta_out(['ok' => false, 'error' => $r['error'], 'kind' => $r['kind'], 'ms' => $r['ms'], 'source' => $src]);
}

if ($action === 'models') {
    [$host, $port] = ta_target($body);
    $r = tc_ollama_models($host, $port);
    ta_out($r, 200);   // ok:false con "error" e "kind": la card lo mostra, non è un errore del server PHP
}

if ($action === 'test') {
    [$host, $port] = ta_target($body);
    $model = trim((string) ($body['model'] ?? ''));
    if (!tc_valid_model($model)) ta_out(['ok' => false, 'error' => 'scegli un modello valido', 'kind' => 'input'], 400);
    $text = trim((string) ($body['text'] ?? ''));
    if ($text === '') $text = 'Scientists discover that a cup of coffee changes the bacteria in your gut';
    $text = mb_substr($text, 0, 300, 'UTF-8');
    $to = max(5, min(120, (int) ($body['timeout'] ?? 90)));
    $threads = $body['threads'] ?? 0;
    if ($threads === '' || $threads === null) $threads = 0;
    if (!is_numeric($threads) || (int) $threads != $threads || (int) $threads < 0 || (int) $threads > 64) {
        ta_out(['ok' => false, 'error' => 'thread CPU non validi (0-64, 0 = automatico)', 'kind' => 'input'], 400);
    }
    $r = tc_ollama_translate($host, $port, $model, $text, $to, (int) $threads);
    if ($r['ok']) {
        ta_out(['ok' => true, 'translation' => $r['text'], 'ms' => $r['ms'], 'model' => $model, 'input' => $text,
                'load_ms' => $r['stats']['load_ms'], 'tokens_per_s' => $r['stats']['tokens_per_s'],
                'prompt_ms' => $r['stats']['prompt_ms'], 'prompt_tokens' => $r['stats']['prompt_tokens']]);
    }
    $out = ['ok' => false, 'error' => $r['error'], 'kind' => $r['kind'], 'ms' => $r['ms']];
    if ($r['kind'] === 'timeout') {
        // Se Ollama non ha risposto in tempo: il modello è in memoria (e quindi è lento a generare)
        // oppure no (e quindi sta ancora caricando)? Cambia cosa conviene fare.
        $ps = tc_ollama_ps($host, $port);
        if ($ps !== null) { $out['loaded'] = in_array($model, $ps, true); $out['loaded_models'] = $ps; }
    }
    ta_out($out);
}

if ($action === 'save') {
    [$norm, $err] = tc_settings_validate($body);
    if ($norm === null) ta_out(['ok' => false, 'error' => $err], 400);
    $norm['updated_at'] = time();
    if (!tc_write_json(TC_SETTINGS_FILE, $norm)) {
        ta_out(['ok' => false, 'error' => 'impossibile scrivere ' . basename(TC_SETTINGS_FILE) . ': controlla i permessi della cartella cache/'], 500);
    }
    unset($norm['updated_at']);
    ta_out(['ok' => true, 'settings' => $norm]);
}

ta_out(['ok' => false, 'error' => 'action non riconosciuta (save | models | test | libre_check | libre_test)'], 400);
