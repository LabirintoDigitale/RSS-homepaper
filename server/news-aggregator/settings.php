<?php
// settings.php
// Impostazioni centralizzate — modificale QUI UNA SOLA VOLTA.
// feed.php e rebuild_cache.php le leggono da qui, non serve più
// editare ogni file ad ogni release.
//
// feed_version e cron_version sono indipendenti: cambiano solo quando
// cambia il rispettivo file, così puoi verificare al volo (header
// HTTP X-App-Version, o campo "version"/"cron_version" nelle risposte)
// quale versione di QUALE file è effettivamente in esecuzione sul
// server, senza doverle tenere sincronizzate a forza.

return [
    'api_token'    => 'CAMBIA_QUESTO_TOKEN', // scegli una stringa lunga e segreta
    'feed_version' => '1.2.0', // versione di feed.php
    'cron_version' => '1.5.0', // versione di rebuild_cache.php
];
