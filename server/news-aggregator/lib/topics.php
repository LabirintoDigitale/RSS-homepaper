<?php
// lib/topics.php
//
// Classificazione "leggera" per argomento basata su parole chiave nel
// titolo + descrizione. Non è machine learning, è un semplice
// pattern-matching: sufficiente per filtrare un feed di notizie senza
// dipendenze esterne o costi di API aggiuntivi.
//
// Un articolo può appartenere a PIÙ argomenti (es. "l'UE regola
// l'intelligenza artificiale" -> sia 'tecnologia' che 'politica').
// Se non matcha nulla, finisce nel bucket 'altro'.
//
// Personalizza pure le liste qui sotto: più parole chiave aggiungi,
// più preciso diventa il filtro (ma occhio ai falsi positivi con
// parole troppo generiche tipo "nuovo").

$TOPIC_KEYWORDS = [
    'tecnologia' => [
        'tecnologia', 'intelligenza artificiale', ' ai ', 'smartphone', 'app',
        'software', 'hardware', 'tech', 'robot', 'chip', 'digitale',
        'internet', 'cybersicurezza', 'startup', 'app store', 'iphone',
        'android', 'microsoft', 'google', 'apple', 'meta', 'openai',
    ],
    'politica' => [
        'governo', 'parlamento', 'elezioni', 'ministro', 'presidente',
        'politica', 'decreto', 'senato', 'camera dei deputati',
        'unione europea', ' ue ', 'partito', 'premier', 'coalizione',
        'referendum', 'consiglio dei ministri',
    ],
    'natura' => [
        'clima', 'ambiente', 'natura', 'animali', 'biodiversità',
        'inquinamento', 'riscaldamento globale', 'foresta', 'oceano',
        'specie', 'ecosistema', 'parco naturale', 'terremoto', 'meteo',
        'alluvione', 'siccità',
    ],
    'economia' => [
        'economia', 'borsa', 'pil', 'inflazione', 'banca', 'finanza',
        'mercati', 'euro', 'dollaro', 'bce', 'tassi', 'recessione',
        'bilancio', 'azioni', 'investimenti',
    ],
    'sport' => [
        'calcio', 'sport', 'tennis', 'olimpiadi', 'campionato', 'serie a',
        'formula 1', 'basket', 'nazionale', 'mondiali', 'champions league',
        'giro d\'italia', 'atleta',
    ],
    'salute' => [
        'salute', 'medicina', 'vaccino', 'ospedale', 'malattia', 'virus',
        'sanità', 'pandemia', 'terapia', 'farmaco', 'oms',
    ],
];

function classify_topics($title, $desc) {
    global $TOPIC_KEYWORDS;
    $text = mb_strtolower($title . ' ' . $desc, 'UTF-8');
    $matched = [];

    foreach ($TOPIC_KEYWORDS as $topic => $keywords) {
        foreach ($keywords as $kw) {
            if (mb_stripos($text, $kw, 0, 'UTF-8') !== false) {
                $matched[] = $topic;
                break; // basta una keyword per confermare il topic, passo al prossimo
            }
        }
    }

    if (empty($matched)) {
        $matched[] = 'altro';
    }
    return $matched;
}
