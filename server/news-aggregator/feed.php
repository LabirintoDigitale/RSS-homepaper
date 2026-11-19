<?php
// feed.php
// Endpoint leggero da chiamare da Home Assistant (sensore REST).
// NON fa scraping qui: legge solo la cache già generata da rebuild_cache.php.
//
// Token e versione sono centralizzati in settings.php — non editare
// qui il token ad ogni release.

$settings = require __DIR__ . '/settings.php';
define('API_TOKEN', $settings['api_token']);
define('APP_VERSION', $settings['feed_version']);

header('Content-Type: application/json; charset=utf-8');
header('X-App-Version: ' . APP_VERSION);

if (!isset($_GET['token']) || !hash_equals(API_TOKEN, $_GET['token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden', 'version' => APP_VERSION]);
    exit;
}

$cache_file = __DIR__ . '/cache/news.json';

if (!file_exists($cache_file)) {
    http_response_code(503);
    echo json_encode(['error' => 'cache non ancora generata, attendi il primo run del cron', 'version' => APP_VERSION]);
    exit;
}

// Evita cache lato client/proxy troppo aggressiva
header('Cache-Control: no-cache, must-revalidate');

// Filtro opzionale per argomento: /feed.php?token=...&topic=tecnologia
// Puoi anche passare più argomenti separati da virgola: &topic=tecnologia,politica
if (!empty($_GET['topic'])) {
    $wanted = array_map('mb_strtolower', array_map('trim', explode(',', $_GET['topic'])));

    $data = json_decode(file_get_contents($cache_file), true);
    if (is_array($data) && isset($data['articles'])) {
        $data['articles'] = array_values(array_filter($data['articles'], function ($article) use ($wanted) {
            $article_topics = array_map('mb_strtolower', $article['topics'] ?? []);
            return count(array_intersect($wanted, $article_topics)) > 0;
        }));
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

readfile($cache_file);
