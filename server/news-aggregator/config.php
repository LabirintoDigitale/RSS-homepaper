<?php
// config.php
// Elenco delle fonti RSS da aggregare.
// Generato/aggiornato da sources_admin.php.
//
// type:
//   'google_news' -> il link va decodificato (redirect cifrato) e poi
//                    va fatto scraping della pagina reale per l'og:image
//   'standard'    -> feed "normale" che di solito ha già <enclosure> o
//                    <media:thumbnail>/<media:content> con l'immagine

return [
    [
        'name' => 'wired',
        'url' => 'https://www.wired.it/feed/rss',
        'type' => 'standard',
        'max_items' => 15,
        'color' => '#ffff00',
    ],
    [
        'name' => 'bellunopress',
        'url' => 'https://www.bellunopress.it/feed/',
        'type' => 'standard',
        'max_items' => 15,
        'color' => '#00ff00',
    ],
    [
        'name' => 'hdblog',
        'url' => 'https://www.hdblog.it/feed/',
        'type' => 'standard',
        'max_items' => 15,
        'color' => '#ffffff',
    ],
    [
        'name' => 'hwupgrade',
        'url' => 'http://feeds.hwupgrade.it/rss_news.xml',
        'type' => 'standard',
        'max_items' => 15,
        'color' => '#00ffff',
    ],
    [
        'name' => 'google',
        'url' => 'https://news.google.com/rss?hl=it&gl=IT&ceid=IT:it',
        'type' => 'google_news',
        'max_items' => 15,
    ],
    [
        'name' => 'bbc',
        'url' => 'https://feeds.bbci.co.uk/news/rss.xml',
        'type' => 'standard',
        'max_items' => 15,
        'color' => '#3f3175',
    ],
    [
        'name' => 'everyeye',
        'url' => 'https://www.everyeye.it/feed/feed_news_rss.asp',
        'type' => 'standard',
        'max_items' => 15,
        'color' => '#00ff00',
    ],
    [
        'name' => 'Birdmen',
        'url' => 'https://birdmenmagazine.com/feed',
        'type' => 'standard',
        'max_items' => 15,
        'color' => '#c74900',
    ],
    [
        'name' => 'spaziogames',
        'url' => 'https://www.spaziogames.it/feed',
        'type' => 'standard',
        'max_items' => 15,
        'color' => '#1a73e8',
    ],
    [
        'name' => 'Il Dolomiti',
        'url' => 'https://news.google.com/rss/search?q=site%3Aildolomiti.it&hl=it&gl=IT&ceid=IT:it',
        'type' => 'google_news',
        'color' => '#ffff00',
    ],
    [
        'name' => 'Hacker News',
        'url' => 'http://news.ycombinator.com/rss',
        'type' => 'standard',
        'color' => '',
    ],
    [
        'name' => 'The Verge',
        'url' => 'http://www.theverge.com/rss/full.xml',
        'type' => 'standard',
        'color' => '',
    ],
    [
        'name' => 'Engadget',
        'url' => 'http://www.engadget.com/rss-full.xml',
        'type' => 'standard',
        'color' => '',
    ],
    [
        'name' => 'TechCrunch',
        'url' => 'https://techcrunch.com/feed/',
        'type' => 'standard',
        'color' => '',
    ],
    [
        'name' => 'Neuroscience News -- ScienceDaily',
        'url' => 'https://sciencedaily.com/rss/mind_brain/neuroscience.xml',
        'type' => 'standard',
        'color' => '',
    ],
];
