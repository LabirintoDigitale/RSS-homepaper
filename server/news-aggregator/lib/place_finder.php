<?php
// lib/place_finder.php
//
// Trova il luogo di cui parla un testo italiano: comune e provincia ("Longarone, BL").
// Nessuna AI e nessuna chiamata di rete: cerca nel testo i nomi dei comuni italiani
// (lib/comuni_it.json, ricavato dall'elenco ISTAT) e pesa gli indizi:
//
//   - "Longarone (BL)", "Longarone, BL"        sigla della provincia accanto al nome   (molto forte)
//   - "BELLUNO – ..." all'inizio               "dateline" delle agenzie e dei giornali (molto forte)
//   - "a Feltre", "nel comune di Sedico"       preposizione di luogo                   (forte)
//   - "il sindaco di Feltre", "da Belluno"     altre preposizioni                      (media)
//   - un nome lungo da solo ("San Vito di Cadore")                                     (medio)
//   - la posizione: titolo e primo paragrafo contano di più.
//
// Prudenza, perché un luogo sbagliato è peggio di nessun luogo:
//   - i nomi di comune che sono anche parole comuni (Sale, Ora, Fonte, Grado, Massa...) valgono
//     solo con un indizio forte ("a Grado", "Massa (MS)"), mai da soli;
//   - un nome a inizio frase non conta come luogo se non c'è un indizio (la maiuscola lì non dice nulla);
//   - se due comuni diversi hanno lo stesso nome (Livo, Castro...) si sceglie con gli altri luoghi e
//     con "provincia di ...": se non si può decidere si indica solo il comune, senza provincia;
//   - sotto una soglia di punteggio non si restituisce nulla.
//
// Luoghi ESTERI (paesi, capitali, grandi città, stati USA): lib/world_places.json. Stesse idee, più regole:
//   - "Austin, Texas", "Kharkiv, Ukraine", "Austin, TX"   paese o stato dopo la virgola       (molto forte, risolve gli omonimi)
//   - "LONDON (Reuters) —", "KYIV, Ukraine (AP) —"        dateline di agenzia                 (molto forte)
//   - "in Paris", "at Davos", "near Gaza"                  preposizione di luogo               (forte)
//   - "from X", "against X", "of X"                        altre preposizioni                  (media)
//   - titoli con la maiuscola su ogni parola (Title Case): lì la maiuscola non dice nulla, contano solo gli indizi forti
//   - nomi di squadre e aziende ("Liverpool FC", "Boston Dynamics", "Prince of Wales"): non sono luoghi
//   - se compaiono città e paese, si indica la città con il paese ("Kharkiv, Ucraina")
//   I nomi sono mostrati in italiano ("Londra, Regno Unito"); per gli Stati Uniti anche lo stato.
//
// Uso:  $p = pf_find_place($titolo, $testo, $lingua);   // $lingua: 'it' (default) o 'en'; null oppure
//       ['city'=>'Longarone','province'=>'BL','province_name'=>'Belluno','region'=>'Veneto',
//        'label'=>'Longarone, BL','score'=>9.5,'evidence'=>[...]]
//       Per un luogo estero: ['city'=>'Londra' (null per un paese),'country'=>'Regno Unito','country_code'=>'GB',
//        'kind'=>'city|country|state|region','label'=>'Londra, Regno Unito', ...]

if (!defined('PF_DATA_FILE')) define('PF_DATA_FILE', __DIR__ . '/comuni_it.json');
if (!defined('PF_MIN_SCORE')) define('PF_MIN_SCORE', 3.0);
if (!defined('PF_WORLD_FILE')) define('PF_WORLD_FILE', __DIR__ . '/world_places.json');

function pf_norm(string $s): string {
    static $map = null;
    if ($map === null) {
        $map = ['à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i',
                'î' => 'i', 'ï' => 'i', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
                'ç' => 'c', 'ñ' => 'n', '’' => "'", '‘' => "'", '`' => "'"];
    }
    return strtr(mb_strtolower($s, 'UTF-8'), $map);
}

// Parole del testo: [testo originale, normalizzata, posizione in byte, lunghezza in byte].
function pf_tokens(string $text): array {
    $out = [];
    if (!preg_match_all('/[\p{L}\p{M}][\p{L}\p{M}\'’]*|\d+/u', $text, $m, PREG_OFFSET_CAPTURE)) return $out;
    foreach ($m[0] as [$raw, $off]) {
        $raw = rtrim($raw, "'’");     // apostrofo finale ("dell'" davanti a una parola scollegata)
        if (preg_match("/^(.{2,}?)['’]s$/u", $raw, $pm)) $raw = $pm[1];     // possessivo inglese: "Ukraine's" -> "Ukraine"
        $out[] = [$raw, pf_norm($raw), $off, strlen($raw)];
    }
    return $out;
}

// Carica e indicizza l'elenco dei comuni (una volta per esecuzione). null se il file manca o è rovinato.
function pf_data(): ?array {
    static $d = null;
    if ($d !== null) return $d ?: null;
    $j = json_decode((string) @file_get_contents(PF_DATA_FILE), true);
    if (!is_array($j) || empty($j['c']) || empty($j['p'])) { $d = false; return null; }
    $index = []; $max = 1;
    foreach ($j['c'] as $i => $c) {
        $toks = pf_tokens($c[0]);
        $key = implode(' ', array_column($toks, 1));
        $index[$key][] = $i;
        $max = max($max, count($toks));
        if (preg_match("/^l'(.+)$/u", $key, $mm)) $index[$mm[1]][] = $i;   // "L'Aquila" anche come "all'Aquila"
    }
    foreach (($j['alias'] ?? []) as $alias => $full) {
        $fk = implode(' ', array_column(pf_tokens($full), 1));
        if (isset($index[$fk]) && !isset($index[$alias])) $index[$alias] = $index[$fk];
    }
    // nomi delle province -> sigla (es. "forli cesena" -> FC)
    $provByName = [];
    foreach ($j['p'] as $sigla => [$pname, $region]) {
        $provByName[implode(' ', array_column(pf_tokens($pname), 1))] = $sigla;
    }
    $regions = [];
    foreach ($j['p'] as [$pname, $region]) $regions[implode(' ', array_column(pf_tokens($region), 1))] = $region;
    return $d = ['prov' => $j['p'], 'c' => $j['c'], 'index' => $index, 'max' => min(6, $max),
                 'common' => array_flip($j['common'] ?? []), 'brand' => array_flip($j['brand'] ?? []), 'provByName' => $provByName, 'regions' => $regions];
}

// Il token comincia con la maiuscola? ("d'Ampezzo" conta dalla parte dopo l'apostrofo)
function pf_cap(string $raw): bool {
    $p = max((int) mb_strrpos($raw, "'", 0, 'UTF-8'), (int) mb_strrpos($raw, '’', 0, 'UTF-8'));
    $ch = mb_substr($raw, $p > 0 ? $p + 1 : 0, 1, 'UTF-8');
    return $ch !== '' && mb_strtoupper($ch, 'UTF-8') === $ch && mb_strtolower($ch, 'UTF-8') !== $ch;
}

function pf_is_connector(string $norm): bool {
    static $c = null;
    if ($c === null) {
        $c = array_flip(['di', 'del', 'della', 'dei', 'delle', 'dello', 'degli', "d'", 'in', 'nel', 'nella', 'nelle', 'nei', 'negli', "nell'",
                         'sul', 'sulla', 'sulle', 'sui', 'sugli', "sull'", 'sotto', 'sopra', 'al', 'alla', 'alle', 'ai', 'agli', "all'", 'e', 'ed',
                         'su', 'dal', 'dalla', 'dalle', 'dai', 'dagli', 'la', 'le', 'lo', 'il', "l'", 'de', 'da', 'a', 'ad', 'tra', 'fra', 'con', 'per']);
    }
    return isset($c[$norm]);
}

// Il nome che segue è del tipo "Belluno Press", "Feltre Calcio": il nome di una testata o di una squadra.
function pf_brand_follows(?string $nextNorm): bool {
    static $b = null;
    if ($b === null) $b = array_flip(['press', 'notizie', 'oggi', 'news', 'tv', 'radio', 'calcio', 'basket', 'volley', 'magazine', 'informa', 'online', 'web', 'cronaca', 'sport']);
    return $nextNorm !== null && isset($b[$nextNorm]);
}

// Ricerca dei comuni in una parte di testo. $weight moltiplica i punteggi (titolo = 2).
// Aggiunge a $buckets (per nome) e a $hints (indizi sulla provincia).
function pf_scan(string $text, float $weight, bool $isTitle, array $d, array &$buckets, array &$hints): void {
    $tokens = pf_tokens($text);
    $n = count($tokens);
    if ($n === 0) return;
    $textLen = strlen($text);

    // dateline: "BELLUNO –", "(ANSA) - ROMA, 10 ott -" nei primi 90 byte
    $datelineEnd = 0;
    if (!$isTitle && preg_match('/^\W*(?:\(\w+\)\W*)?([A-ZÀ-Ý][A-ZÀ-Ý\' ]{2,40})\s*(?:,|—|–|-|\()/u', substr($text, 0, 120), $dm, PREG_OFFSET_CAPTURE)) {
        $datelineEnd = $dm[1][1] + strlen($dm[1][0]);
    }

    $i = 0;
    while ($i < $n) {
        $hit = null;
        // "dell'Aquila", "all'Aquila", "nell'Aquila": articolo/preposizione attaccati al nome ("L'Aquila" si indicizza anche come "aquila")
        $alt = null; $attached = null;
        if (preg_match("/^(l|dell|all|nell|sull|dall|coll|un)'(.+)$/u", $tokens[$i][1], $am)) { $alt = $am[2]; $attached = $am[1]; }
        for ($len = min($d['max'], $n - $i); $len >= 1; $len--) {
            $key = implode(' ', array_map(fn($t) => $t[1], array_slice($tokens, $i, $len)));
            $viaAttached = false;
            if (!isset($d['index'][$key]) && $alt !== null) {
                $key2 = implode(' ', array_merge([$alt], array_map(fn($t) => $t[1], array_slice($tokens, $i + 1, $len - 1))));
                if (isset($d['index'][$key2])) { $key = $key2; $viaAttached = true; }
            }
            if (!isset($d['index'][$key])) continue;
            // maiuscole: tutte le parole che non siano "di", "del", "nell'"... devono avere la iniziale maiuscola
            $ok = true;
            for ($k = $i; $k < $i + $len; $k++) {
                if (pf_is_connector($tokens[$k][1]) && $k > $i) continue;
                if (!pf_cap($tokens[$k][0])) { $ok = false; break; }
            }
            if (!$ok) continue;
            $hit = [$key, $len, $viaAttached];
            break;
        }
        if ($hit === null) { $i++; continue; }
        [$key, $len, $viaAttached] = $hit;
        $start = $tokens[$i][2];
        $endTok = $tokens[$i + $len - 1];
        $end = $endTok[2] + $endTok[3];
        $nextNorm = $tokens[$i + $len][1] ?? null;
        $i += $len;

        // testata o squadra ("Belluno Press", "Feltre Calcio"): non è un luogo
        if (pf_brand_follows($nextNorm)) continue;

        $cands = $d['index'][$key];
        $before = substr($text, max(0, $start - 40), min(40, $start));
        $after = substr($text, $end, 24);
        $sentenceStart = $start === 0 || (bool) preg_match('/(?:^|[.!?:;»”"\n])\s*[«“"\'(]?\s*$/u', $before);
        $isCommon = $len === 1 && isset($d['common'][$key]);
        $isBrand = $len === 1 && isset($d['brand'][$key]);

        $score = 0.0; $why = [];
        // sigla della provincia accanto al nome: "Longarone (BL)" / "Longarone, BL"
        $sigla = null;
        if (preg_match('/^\s*(?:\(\s*|,\s*)([A-Z]{2})\b/u', $after, $sm) && isset($d['prov'][$sm[1]])) {
            foreach ($cands as $ci) if ($d['c'][$ci][1] === $sm[1]) { $sigla = $sm[1]; break; }
        }
        if ($sigla !== null) { $score += 6; $why[] = 'sigla ' . $sigla; }
        // dateline
        if ($start < $datelineEnd) { $score += 5; $why[] = 'dateline'; }
        // preposizioni di luogo
        $b = mb_strtolower($before, 'UTF-8');
        if ($viaAttached && in_array($attached, ['all', 'nell'], true)) {
            $score += 3; $why[] = 'prep forte';
        } elseif ($viaAttached && in_array($attached, ['dell', 'dall', 'sull'], true)) {
            $score += 1.5; $why[] = 'prep';
        } elseif (preg_match('/\b(?:a|ad|presso)\s+$/u', $b) || preg_match('/\b(?:comune|città|citta|paese|cittadina|località|localita|frazione|provincia|territorio|centro|borgo|zona|quartiere)\s+(?:di|del|della|dello|dei)\s+$/u', $b)) {
            $score += 3; $why[] = 'prep forte';
        } elseif (preg_match('/\b(?:in|di|da|nel|nella|sul|sulla|verso|tra|fra|su|per|dopo|vicino)\s+$/u', $b)) {
            $score += 1.5; $why[] = 'prep';
        }
        // formula dei titoli: "Feltre, incendio in centro" / "Longarone: frana sulla statale"
        if ($sentenceStart && $len === 1 && !$isCommon && $score < 3 && preg_match('/^\s*[:,–—-]\s/u', $after)) {
            $score += 4.0; $why[] = 'inizio titolo';     // quasi sempre è il luogo della notizia
        }
        // nome da solo
        if ($score < 3) {
            if ($len >= 2) { $score += 2; $why[] = 'nome lungo'; }
            elseif (!$isCommon && !$sentenceStart) { $score += (mb_strlen($endTok[0], 'UTF-8') >= 6 ? 1.0 : 0.5); $why[] = 'nome'; }
        } elseif ($len >= 2) { $score += 1; }
        if ($isCommon && $score < 3) { $score = 0; }                 // parola comune: serve un indizio forte
        // nome di un'azienda ("a Meta"): una preposizione non basta, serve la sigla o la dateline
        if ($isBrand && $sigla === null && !in_array('dateline', $why, true)) { $score = 0; }
        if ($sentenceStart && $len === 1 && $score < 2.5 + 0.01) { $score = 0; } // inizio frase: la maiuscola non dice nulla
        if ($score <= 0) continue;

        $w = $weight;
        if (!$isTitle && $start < 400) $w *= 1.4;                    // primo paragrafo
        $score *= $w;

        $b_ = &$buckets[$key];
        if (!isset($b_)) $b_ = ['score' => 0.0, 'cands' => $cands, 'sigla' => [], 'why' => [], 'first' => ($isTitle ? -1 : $start), 'raw' => $text !== '' ? substr($text, $start, $end - $start) : ''];
        $b_['score'] += $score;
        if ($sigla !== null) $b_['sigla'][$sigla] = ($b_['sigla'][$sigla] ?? 0) + $score;
        foreach ($why as $x) if (!in_array($x, $b_['why'], true)) $b_['why'][] = $x;
        unset($b_);
        if ($sigla !== null) $hints['prov'][$sigla] = ($hints['prov'][$sigla] ?? 0) + 2;
    }

    // "provincia di Belluno" / "in provincia di Forlì-Cesena": indizio sulla provincia (e a volte l'unico luogo)
    for ($i = 0; $i < $n - 1; $i++) {
        if ($tokens[$i][1] !== 'provincia' || !in_array($tokens[$i + 1][1], ['di', 'del', 'della', "dell'", 'dello'], true)) continue;
        for ($len = min(4, $n - $i - 2); $len >= 1; $len--) {
            $k = implode(' ', array_map(fn($t) => $t[1], array_slice($tokens, $i + 2, $len)));
            if (isset($d['provByName'][$k]) && pf_cap($tokens[$i + 2][0])) {
                $s = $d['provByName'][$k];
                $hints['prov'][$s] = ($hints['prov'][$s] ?? 0) + 3;
                $hints['provMention'][$s] = ($hints['provMention'][$s] ?? 0) + 1;
                break;
            }
        }
    }
    // regioni citate
    foreach ($tokens as $t) {
        if (isset($d['regions'][$t[1]]) && pf_cap($t[0])) $hints['region'][$d['regions'][$t[1]]] = ($hints['region'][$d['regions'][$t[1]]] ?? 0) + 1;
    }
    $normText = pf_norm($text);
    foreach ($d['regions'] as $rk => $rname) {
        if (strpos($rk, ' ') !== false && strpos($normText, $rk) !== false) $hints['region'][$rname] = ($hints['region'][$rname] ?? 0) + 1;
    }
}

function pf_find_italy(string $title, string $text): ?array {
    $d = pf_data();
    if ($d === null) return null;
    $text = mb_substr($text, 0, 20000, 'UTF-8');    // basta l'inizio: oltre, i luoghi sono quasi sempre di contorno
    $buckets = []; $hints = ['prov' => [], 'region' => [], 'provMention' => []];
    if (trim($title) !== '') pf_scan($title, 2.0, true, $d, $buckets, $hints);
    if (trim($text) !== '') pf_scan($text, 1.0, false, $d, $buckets, $hints);

    // Risoluzione dei nomi con più comuni omonimi, usando le altre prove: sigle, "provincia di", luoghi non ambigui, regioni.
    foreach ($buckets as $key => $bk) {            // i luoghi non ambigui danno indizi sulla provincia
        if (count($bk['cands']) === 1) {
            $s = $d['c'][$bk['cands'][0]][1];
            $hints['prov'][$s] = ($hints['prov'][$s] ?? 0) + $bk['score'] * 0.5;
        }
    }
    $best = null;
    foreach ($buckets as $key => $bk) {
        if ($bk['score'] < PF_MIN_SCORE) continue;
        $ci = null; $ambiguous = false;
        if (count($bk['cands']) === 1) {
            $ci = $bk['cands'][0];
        } elseif ($bk['sigla']) {
            arsort($bk['sigla']);
            $sg = array_key_first($bk['sigla']);
            foreach ($bk['cands'] as $c) if ($d['c'][$c][1] === $sg) { $ci = $c; break; }
        } else {
            $top = null; $topV = 0.0; $tie = false;
            foreach ($bk['cands'] as $c) {
                $sg = $d['c'][$c][1];
                $v = ($hints['prov'][$sg] ?? 0) + 0.5 * ($hints['region'][$d['prov'][$sg][1]] ?? 0);
                if ($v > $topV) { $topV = $v; $top = $c; $tie = false; } elseif ($v == $topV && $v > 0) { $tie = true; }
            }
            if ($top !== null && !$tie) $ci = $top; else { $ci = $bk['cands'][0]; $ambiguous = true; }
        }
        if ($best === null || $bk['score'] > $best['score'] || ($bk['score'] == $best['score'] && $bk['first'] < $best['first'])) {
            $best = ['score' => $bk['score'], 'ci' => $ci, 'ambiguous' => $ambiguous, 'why' => $bk['why'], 'first' => $bk['first'], 'raw' => $bk['raw']];
        }
    }
    if ($best !== null) {
        [$name, $sg] = [$d['c'][$best['ci']][0], $d['c'][$best['ci']][1]];
        return [
            'city'          => $name,
            'province'      => $best['ambiguous'] ? null : $sg,
            'province_name' => $best['ambiguous'] ? null : $d['prov'][$sg][0],
            'region'        => $best['ambiguous'] ? null : $d['prov'][$sg][1],
            'label'         => $best['ambiguous'] ? $name : $name . ', ' . $sg,
            'score'         => round($best['score'], 1),
            'evidence'      => array_slice($best['why'], 0, 5),
        ];
    }
    // Nessun comune sicuro, ma una provincia citata ("in provincia di Belluno"): si indica quella.
    if ($hints['provMention']) {
        arsort($hints['provMention']);
        $sg = array_key_first($hints['provMention']);
        return ['city' => null, 'province' => $sg, 'province_name' => $d['prov'][$sg][0], 'region' => $d['prov'][$sg][1],
                'label' => 'Provincia di ' . $d['prov'][$sg][0], 'score' => 3.0, 'evidence' => ['provincia citata']];
    }
    return null;
}

// ═══════════════════════════════════════════════════════════════════════════
// Luoghi esteri
// ═══════════════════════════════════════════════════════════════════════════

// Carica e indicizza paesi, città, stati USA e regioni (una volta per esecuzione). null se il file manca.
// Righe di 'cities': [0 nome inglese, 1 nome italiano, 2 paese, 3 popolazione, 4 stato USA, 5 capitale (0/1), 6 altri nomi inglesi, 7 stesso nome del paese (0/1)]
function pf_world_data(): ?array {
    static $w = null;
    if ($w !== null) return $w ?: null;
    $j = json_decode((string) @file_get_contents(PF_WORLD_FILE), true);
    if (!is_array($j) || empty($j['countries']) || empty($j['cities'])) { $w = false; return null; }
    $index = []; $max = 1;
    $add = function (string $name, string $type, $ref) use (&$index, &$max) {
        $toks = pf_tokens($name);
        if (!$toks) return;
        $key = implode(' ', array_column($toks, 1));
        foreach ($index[$key] ?? [] as $e) if ($e[0] === $type && $e[1] === $ref) return;     // già presente
        $index[$key][] = [$type, $ref];
        $max = max($max, count($toks));
    };
    $ccIdx = [];
    foreach ($j['countries'] as $i => $c) {
        $ccIdx[$c['cc']] = $i;
        foreach ($c['en'] as $n) $add($n, 'c', $i);
        foreach ($c['it_names'] as $n) $add($n, 'c', $i);
    }
    foreach ($j['cities'] as $i => $c) {
        $add($c[0], 'k', $i); $add($c[1], 'k', $i);
        foreach ($c[6] as $a) $add($a, 'k', $i);
    }
    $stateAbbr = [];
    foreach ($j['states'] as $i => [$n, $code]) { $add($n, 's', $i); $stateAbbr[$code] = $i; }
    foreach ($j['regions'] as $i => $r) {
        $add($r[0], 'r', $i);
        foreach ($r[1] as $a) $add($a, 'r', $i);
        $add($r[2], 'r', $i);
        foreach (($r[4] ?? []) as $a) $add($a, 'r', $i);
    }
    foreach ($j['it_cities_en'] as $en => $itName) $add($en, 'i', $itName);     // "Rome" -> comune "Roma"
    return $w = ['j' => $j, 'index' => $index, 'max' => min(6, $max), 'common' => array_flip($j['common'] ?? []), 'brand' => array_flip($j['brand'] ?? []),
                 'ccIdx' => $ccIdx, 'stateAbbr' => $stateAbbr];
}

function pf_world_is_connector(string $norm): bool {
    static $c = null;
    if ($c === null) $c = array_flip(['of', 'the', 'and', 'de', 'la', 'le', 'les', 'el', 'al', 'du', 'des', 'am', 'an', 'upon', 'on', 'au', 'del', 'da', 'do', 'dos', 'das', 'di', 'y', 'e', 'bin', 'ben']);
    return isset($c[$norm]);
}

// Squadre e aziende: "Liverpool FC", "Boston Dynamics", "Dallas Cowboys"
function pf_world_brand_follows(?string $next): bool {
    static $b = null;
    if ($b === null) $b = array_flip(['united', 'city', 'fc', 'cf', 'sc', 'rovers', 'wanderers', 'athletic', 'albion', 'town', 'rangers', 'cowboys', 'lions', 'bears', 'tigers', 'giants', 'jets',
        'dolphins', 'bucks', 'knicks', 'lakers', 'warriors', 'spurs', 'mavericks', 'rockets', 'dynamics', 'university', 'college', 'airlines', 'times', 'post', 'journal', 'herald', 'tribune',
        'gazette', 'raiders', 'eagles', 'panthers', 'saints', 'pirates', 'celtics', 'bulls', 'heat', 'suns', 'nets', 'magic', 'thunder', 'clippers', 'kings', 'mets', 'yankees', 'cubs',
        'orioles', 'astros', 'braves', 'cardinals', 'brewers', 'padres', 'rays', 'twins', 'royals', 'reds', 'red', 'white', 'blue', 'inter']);
    return $next !== null && isset($b[$next]);
}

function pf_world_scan(string $text, float $weight, bool $isTitle, array $w, string $lang, array &$buckets, array &$hints): void {
    $tokens = pf_tokens($text);
    $n = count($tokens);
    if ($n === 0) return;
    $j = $w['j'];

    // Titolo con la maiuscola su ogni parola ("Boston Dynamics Unveils New Robot"): la maiuscola non dice nulla.
    $titleCase = false;
    if ($isTitle) {
        $long = 0; $cap = 0;
        foreach ($tokens as $t) if (mb_strlen($t[0], 'UTF-8') >= 4 && !pf_world_is_connector($t[1])) { $long++; if (pf_cap($t[0])) $cap++; }
        $titleCase = $long >= 4 && $cap / $long >= 0.7;
    }

    // dateline: "LONDON (Reuters) —", "KYIV, Ukraine (AP) —", "WASHINGTON —"
    $datelineEnd = 0;
    if (!$isTitle && preg_match('/^\W*(?:\(\w+\)\W*)?([A-ZÀ-Ý][A-ZÀ-Ý .\'’-]{2,40}?)(?:,\s*[A-Za-zÀ-ÿ .]{2,30})?\s*(?:\((?:Reuters|AP|AFP|CNN|BBC|UPI|Xinhua|TASS|dpa|ANSA|Bloomberg)\)\s*)?(?:[—–-]|\(|,\s*\w+ \d)/u', substr($text, 0, 140), $dm, PREG_OFFSET_CAPTURE)) {
        $datelineEnd = $dm[1][1] + strlen($dm[1][0]);
    }

    $i = 0;
    while ($i < $n) {
        $hit = null;
        for ($len = min($w['max'], $n - $i); $len >= 1; $len--) {
            $key = implode(' ', array_map(fn($t) => $t[1], array_slice($tokens, $i, $len)));
            if (!isset($w['index'][$key])) continue;
            $ok = true; $allUpper = true;
            for ($k = $i; $k < $i + $len; $k++) {
                $raw = $tokens[$k][0];
                if (mb_strtoupper($raw, 'UTF-8') !== $raw) $allUpper = false;
                if ($k > $i && pf_world_is_connector($tokens[$k][1])) continue;
                if (!pf_cap($raw)) { $ok = false; break; }
            }
            if (!$ok) continue;
            // nomi cortissimi ("US", "UK", "UAE"): solo in maiuscolo, altrimenti sono parole ("us")
            if ($len === 1 && mb_strlen($tokens[$i][0], 'UTF-8') <= 3 && !$allUpper) continue;
            $hit = [$key, $len, $allUpper];
            break;
        }
        if ($hit === null) { $i++; continue; }
        [$key, $len, $allUpper] = $hit;
        $first = $tokens[$i]; $last = $tokens[$i + $len - 1];
        $start = $first[2]; $end = $last[2] + $last[3];
        $prev1 = $tokens[$i - 1][1] ?? null; $prev2 = $tokens[$i - 2][1] ?? null; $prevRaw = $tokens[$i - 1][0] ?? null;
        $next = $tokens[$i + $len] ?? null;
        $startTok = $i;
        $i += $len;

        // squadre e aziende; "Prince of Wales", "Queen Victoria"
        if ($next !== null && pf_world_brand_follows($next[1])) continue;
        if (in_array($prev1, ['prince', 'princess', 'duke', 'duchess', 'queen', 'king', 'lady', 'lord', 'sir', 'dr', 'mr', 'mrs', 'ms'], true)) continue;
        // testate localizzate ("Wired Italia", "IGN Italia", "Vogue Italia"): un paese preceduto da un nome proprio che non è un
        // punto cardinale. Non vale nei testi tutti in maiuscolo, dove ogni parola è maiuscola.
        $hasCountry = false;
        foreach ($w['index'][$key] as $e0) if ($e0[0] === 'c') $hasCountry = true;
        if ($hasCountry && $prevRaw !== null && pf_cap($prevRaw) && !pf_world_is_connector($prev1) && !isset($w['index'][$prev1])
            && !in_array($prev1, ['nord', 'sud', 'centro', 'medio', 'north', 'south', 'east', 'west', 'northern', 'southern', 'eastern', 'western', 'central', 'upper', 'lower',
                                  'greater', 'new', 'old', 'middle', 'far', 'late', 'early', 'ex', 'vecchia', 'nuova'], true)) {
            $win = array_slice($tokens, max(0, $startTok - 4), 8); $up = 0;
            foreach ($win as $t) if (mb_strlen($t[0], 'UTF-8') > 1 && mb_strtoupper($t[0], 'UTF-8') === $t[0]) $up++;
            if ($up / max(1, count($win)) < 0.6) continue;
        }
        if ($prev1 === 'of' && in_array($prev2, ['prince', 'princess', 'duke', 'duchess', 'bank', 'university', 'college', 'church', 'archbishop', 'bishop', 'kingdom', 'museum', 'institute', 'banca', 'università', 'chiesa'], true)) continue;

        $entries = $w['index'][$key];
        $before = strtolower(substr($text, max(0, $start - 40), min(40, $start)));
        $after = substr($text, $end, 80);
        $sentenceStart = $start === 0 || (bool) preg_match('/(?:^|[.!?:;»”"\n])\s*[«“"\'(]?\s*$/u', substr($text, max(0, $start - 40), min(40, $start)));
        $isAmbig = $len === 1 && isset($w['common'][$key]);
        $isBrand = $len === 1 && isset($w['brand'][$key]);
        $isCountry = false; foreach ($entries as $e) if ($e[0] === 'c' || $e[0] === 'r') $isCountry = true;
        $isRealCountry = false; foreach ($entries as $e) if ($e[0] === 'c') $isRealCountry = true;

        $score = 0.0; $why = []; $resolve = null;
        // "City, Country" / "City, State" / "City, TX"
        if (preg_match('/^\s*,\s*([A-Z][\p{L}.\'’ -]{0,40})/u', $after, $cm)) {
            $tail = pf_tokens($cm[1]);
            for ($tl = min(5, count($tail)); $tl >= 1; $tl--) {
                $k2 = implode(' ', array_map(fn($t) => $t[1], array_slice($tail, 0, $tl)));
                foreach (($w['index'][$k2] ?? []) as $e2) {
                    if ($e2[0] === 'c' && pf_cap($tail[0][0])) { $resolve = ['cc' => $j['countries'][$e2[1]]['cc']]; break 2; }
                    if ($e2[0] === 's' && pf_cap($tail[0][0])) { $resolve = ['cc' => 'US', 'state' => $j['states'][$e2[1]][0]]; break 2; }
                }
                if ($tl === 1 && strlen($tail[0][0]) === 2 && isset($w['stateAbbr'][$tail[0][0]]) && $tail[0][0] === strtoupper($tail[0][0])) {
                    $resolve = ['cc' => 'US', 'state' => $j['states'][$w['stateAbbr'][$tail[0][0]]][0]]; break;
                }
            }
        }
        if ($resolve !== null && !$isRealCountry) { $score += 6; $why[] = 'città, ' . ($resolve['state'] ?? $resolve['cc']); }
        // dateline
        if ($start < $datelineEnd) { $score += 5; $why[] = 'dateline'; }
        // preposizioni (inglesi per 'en'; italiane e inglesi per 'it': nei testi italiani si citano anche in inglese)
        $strong = $lang === 'it' ? '(?:in|at|near|a|ad|presso|nel|nella|nei|nelle|vicino a|nei pressi di|dentro|attraverso)' : '(?:in|at|near|outside|inside|across|around|within)';
        $medium = $lang === 'it' ? '(?:to|from|into|toward|towards|via|verso|da|fino a)' : '(?:to|from|into|toward|towards|via)';
        $weak = $lang === 'it' ? '(?:of|over|against|on|for|with|by|di|su|sul|sulla|per|contro|tra|fra|dopo|sopra|sotto|attorno a|con)' : '(?:of|over|against|on|upon|for|with|by|throughout|between|after|amid)';
        $weakPrep = false;
        // "the capital Kyiv", "the city of Liverpool", "la città di Kiev", "il porto di Odessa": dopo queste parole è un luogo
        $apposition = $lang === 'it' ? '(?:capitale|città|cittadina|paese|villaggio|porto|regione|provincia|distretto|metropoli)(?:\s+(?:di|del|della|dello|dei))?' : '(?:capital|city|town|village|port|region|province|district|suburb|metropolis)(?:\s+of)?';
        if (preg_match('/\b' . $strong . '\s+$/u', $before) || preg_match('/\b' . $apposition . '\s+$/u', $before)) { $score += 3; $why[] = 'prep forte'; }
        elseif (preg_match('/\b' . $medium . '\s+$/u', $before)) { $score += 2; $why[] = 'prep'; $weakPrep = true; }
        elseif (preg_match('/\b' . $weak . '\s+$/u', $before)) { $score += 1.5; $why[] = 'prep'; $weakPrep = true; }
        // formula dei titoli: "Kiev: ..." / "Gaza - ..." (e in italiano anche "Gaza, ...")
        if ($sentenceStart && $len === 1 && !$isAmbig && !$titleCase && $score < 3 && preg_match($lang === 'it' ? '/^\s*[:,–—-]\s/u' : '/^\s*[:–—-]\s/u', $after)) { $score += 4.0; $why[] = 'inizio titolo'; }
        // nome da solo
        if ($score < 3) {
            if ($titleCase) {
                // maiuscola su ogni parola: un nome da solo non prova nulla
            } elseif ($isCountry && !$isAmbig) {
                $score += $sentenceStart ? 1.5 : 2.0; $why[] = 'paese';
            } elseif ($len >= 2) { $score += 2; $why[] = 'nome lungo'; }
            elseif (!$isAmbig && !$sentenceStart) { $score += (mb_strlen($last[0], 'UTF-8') >= 6 ? 1.0 : 0.5); $why[] = 'nome'; }
        } elseif ($len >= 2) { $score += 1; }
        // marchio o parola comune: solo con "Città, Paese", la sigla dello stato o l'apertura di agenzia
        if ($isBrand && $resolve === null && !in_array('dateline', $why, true)) $score = 0;
        // nome ambiguo (anche nome di persona, parola comune): serve un indizio forte
        if ($isAmbig && $score < 3 && !$weakPrep) $score = 0;
        // "Austin Butler", "Jackson Pollock": nome ambiguo seguito da un'altra parola in maiuscolo = una persona
        if ($isAmbig && $resolve === null && !in_array('dateline', $why, true) && $next !== null && pf_cap($next[0]) && !pf_world_is_connector($next[1]) && !isset($w['index'][$next[1]])) $score = 0;
        if ($score <= 0) continue;

        $wgt = $weight;
        if (!$isTitle && $start < 400) $wgt *= 1.4;
        $score *= $wgt;

        $b_ = &$buckets[$key];
        if (!isset($b_)) $b_ = ['score' => 0.0, 'entries' => $entries, 'resolve' => null, 'why' => [], 'first' => ($isTitle ? -1 : $start), 'afterWord' => null];
        $b_['score'] += $score;
        if ($resolve !== null) $b_['resolve'] = $resolve;
        if ($next !== null && $next[1] === 'state') $b_['afterWord'] = 'state';
        if ($prev1 === 'of' && $prev2 === 'state') $b_['afterWord'] = 'state';
        foreach ($why as $x) if (!in_array($x, $b_['why'], true)) $b_['why'][] = $x;
        unset($b_);
        // indizio sul paese per scegliere tra omonimi
        foreach ($entries as $e) if ($e[0] === 'c') $hints['cc'][$j['countries'][$e[1]]['cc']] = ($hints['cc'][$j['countries'][$e[1]]['cc']] ?? 0) + 1;
        if ($resolve !== null) $hints['cc'][$resolve['cc']] = ($hints['cc'][$resolve['cc']] ?? 0) + 2;
    }
}

function pf_world_label(array $w, string $type, $ref, ?string $stateHint): array {
    $j = $w['j'];
    if ($type === 'c') {
        $c = $j['countries'][$ref];
        return ['kind' => 'country', 'city' => null, 'country' => $c['it'], 'country_code' => $c['cc'], 'label' => $c['it']];
    }
    if ($type === 'k') {
        $c = $j['cities'][$ref]; $cc = $c[2]; $co = $j['countries'][$w['ccIdx'][$cc] ?? 0];
        $country = $co['it'] ?? $cc;
        if ($c[7]) return ['kind' => 'country', 'city' => null, 'country' => $country, 'country_code' => $cc, 'label' => $country];   // Singapore, Kuwait...
        $parts = [$c[1]];
        if ($cc === 'US' && $c[4] && $c[4] !== 'District of Columbia' && pf_norm($c[4]) !== pf_norm($c[1])) $parts[] = $c[4];
        $parts[] = $country;
        return ['kind' => 'city', 'city' => $c[1], 'country' => $country, 'country_code' => $cc, 'state' => $c[4] ?: null, 'label' => implode(', ', $parts)];
    }
    if ($type === 's') {
        $s = $j['states'][$ref]; $us = $j['countries'][$w['ccIdx']['US']]['it'];
        return ['kind' => 'state', 'city' => null, 'country' => $us, 'country_code' => 'US', 'state' => $s[0], 'label' => $s[0] . ', ' . $us];
    }
    if ($type === 'r') {
        $r = $j['regions'][$ref]; $cc = $r[3]; $country = $j['countries'][$w['ccIdx'][$cc] ?? 0]['it'] ?? $cc;
        return ['kind' => 'region', 'city' => $r[2], 'country' => $country, 'country_code' => $cc, 'label' => $r[2] . ', ' . $country];
    }
    return [];
}

// Il luogo estero più probabile ('en' o 'it'). Ritorna null se nessuno raggiunge la soglia.
function pf_find_world(string $title, string $text, string $lang): ?array {
    $w = pf_world_data();
    if ($w === null) return null;
    $j = $w['j'];
    $text = mb_substr($text, 0, 20000, 'UTF-8');
    $buckets = []; $hints = ['cc' => []];
    if (trim($title) !== '') pf_world_scan($title, 2.0, true, $w, $lang, $buckets, $hints);
    if (trim($text) !== '') pf_world_scan($text, 1.0, false, $w, $lang, $buckets, $hints);

    $cands = []; $weakCands = [];     // luoghi candidati; e quelli sotto soglia, che servono solo a preferire una città al suo paese
    foreach ($buckets as $key => $bk) {
        if ($bk['score'] < 1.0) continue;
        // sceglie tra più significati dello stesso nome
        $best = null; $bestV = -1.0;
        foreach ($bk['entries'] as $e) {
            [$type, $ref] = $e;
            if ($type === 'i') { $v = 1000.0; }                                  // alias inglese di una città italiana (Rome)
            elseif ($type === 'c') { $v = 900.0; }
            elseif ($type === 'r') { $v = 800.0; }
            elseif ($type === 'k') { $v = (float) $j['cities'][$ref][3] / 1000.0 + ($j['cities'][$ref][5] ? 300.0 : 0.0); }
            else { $v = 100.0; }                                                  // stato USA: dopo le città, salvo "Washington State"
            $cc = $type === 'k' ? $j['cities'][$ref][2] : ($type === 'c' ? $j['countries'][$ref]['cc'] : ($type === 's' ? 'US' : ($type === 'r' ? $j['regions'][$ref][3] : 'IT')));
            if (($bk['resolve']['cc'] ?? null) === $cc) $v += 100000.0;           // "Austin, Texas" / "Paris, France"
            if ($type === 'k' && !empty($bk['resolve']['state']) && $j['cities'][$ref][4] === $bk['resolve']['state']) $v += 50000.0;
            if (($hints['cc'][$cc] ?? 0) > 0) $v += 20000.0;                      // un paese citato nel testo
            if ($bk['afterWord'] === 'state' && $type === 's') $v += 200000.0;    // "Washington state"
            if ($v > $bestV) { $bestV = $v; $best = $e; }
        }
        $row = ['score' => $bk['score'], 'e' => $best, 'why' => $bk['why'], 'first' => $bk['first'], 'resolve' => $bk['resolve']];
        if ($bk['score'] >= PF_MIN_SCORE) $cands[] = $row; else $weakCands[] = $row;
    }
    if (!$cands) return null;
    usort($cands, fn($a, $b) => $b['score'] <=> $a['score'] ?: $a['first'] <=> $b['first']);
    $top = $cands[0];

    // Se il luogo migliore è un paese ma c'è anche una città (o regione) di quel paese con un punteggio non trascurabile, meglio la città.
    $ccOf = function (array $e) use ($j, $w): ?string {
        [$t, $r] = $e;
        return $t === 'c' ? $j['countries'][$r]['cc'] : ($t === 'k' ? $j['cities'][$r][2] : ($t === 's' ? 'US' : ($t === 'r' ? $j['regions'][$r][3] : null)));
    };
    if ($top['e'][0] === 'c') {
        $cc = $ccOf($top['e']);
        $pool = array_merge($cands, $weakCands);
        usort($pool, fn($a, $b) => $b['score'] <=> $a['score']);
        foreach ($pool as $c) {
            // una città (o regione) di quel paese citata anche solo una volta è più precisa del paese
            if ($c['e'][0] !== 'c' && $c['e'][0] !== 'i' && $ccOf($c['e']) === $cc && $c['score'] >= max(1.0, 0.25 * $top['score'])) { $top = $c; break; }
        }
    }
    [$type, $ref] = $top['e'];
    if ($type === 'i') {      // città italiana citata con il nome inglese: etichetta come per le altre città italiane
        $d = pf_data();
        if ($d === null) return null;
        $k = implode(' ', array_column(pf_tokens($ref), 1));
        $ci = $d['index'][$k][0] ?? null;
        if ($ci === null) return null;
        [$name, $sg] = [$d['c'][$ci][0], $d['c'][$ci][1]];
        return ['city' => $name, 'province' => $sg, 'province_name' => $d['prov'][$sg][0], 'region' => $d['prov'][$sg][1], 'country' => 'Italia', 'country_code' => 'IT',
                'kind' => 'city', 'label' => $name . ', ' . $sg, 'score' => round($top['score'], 1), 'evidence' => array_slice($top['why'], 0, 5)];
    }
    $lab = pf_world_label($w, $type, $ref, null);
    if (!$lab) return null;
    return $lab + ['score' => round($top['score'], 1), 'evidence' => array_slice($top['why'], 0, 5)];
}

// Titoli e og:title finiscono spesso con la testata: "Gears of War: la recensione - IGN Italia",
// "Incendio a Feltre | Il Giornale di Vicenza". Quel pezzo finale non parla dell'articolo: si toglie.
// Si toglie solo se è breve (al massimo 5 parole) e se prima c'è abbastanza titolo.
function pf_strip_publisher(string $title): string {
    if (preg_match('/^(.{12,}?)\s+[-–—|]\s+([^-–—|]{2,45})$/us', trim($title), $m) && str_word_count($m[2], 0, 'àèéìòùÀÈÉÌÒÙ') <= 5) return $m[1];
    return $title;
}

// Entrata unica: il luogo di cui parla il testo. $lang = 'it' (comuni italiani e luoghi esteri) o 'en' (luoghi esteri e
// città italiane con il nome inglese). Altre lingue: nessun luogo.
function pf_find_place(string $title, string $text, string $lang = 'it'): ?array {
    $it = null; $world = null;
    $title = pf_strip_publisher($title);
    if ($lang === 'it') $it = pf_find_italy($title, $text);
    if ($lang === 'it' || $lang === 'en') $world = pf_find_world($title, $text, $lang);
    if ($it !== null && $world !== null) return $world['score'] > $it['score'] ? $world : $it;     // a parità, l'Italia
    return $it ?? $world;
}
