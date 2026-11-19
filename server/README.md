# Server news-aggregator

È la parte che lavora dietro la card. Legge i feed RSS, scarica le immagini, traduce titoli e descrizioni e prepara i riassunti. Le notizie pronte finiscono in `cache/news.json`, e Home Assistant le legge da `feed.php`.

## Cosa serve

- Un server web con **PHP 7.4 o più recente**, per esempio Apache o nginx su un Raspberry, un NAS o un container.
- Le estensioni PHP **curl**, **mbstring**, **simplexml** e **dom** (su Debian/Ubuntu: `apt install php-curl php-mbstring php-xml`).
- La cartella `news-aggregator/` deve poter essere scritta dal server web, perché lì viene creata `cache/`.

## Installazione

1. Copia la cartella `news-aggregator/` sul server, per esempio in `/var/www/html/news-aggregator/`.
2. **Crea il token** e scrivilo in `settings.php` al posto di `CAMBIA_QUESTO_TOKEN`. Usa una stringa lunga e casuale, per esempio quella che restituisce `openssl rand -hex 24`. **Lo stesso token** va scritto anche nel sensore di Home Assistant (passo sotto) e nella card (**Token amministrazione fonti**). Se non coincide in tutti e tre i posti, il server risponde `forbidden` / `unauthorized`.
3. Dai al server web il permesso di scrivere nella cartella:
   ```bash
   sudo chown -R www-data:www-data /var/www/html/news-aggregator
   ```
4. Fai girare `rebuild_cache.php` ogni 1-2 minuti. Ogni volta elabora qualche articolo, e `cache/news.json` viene riscritto solo quando il giro di tutte le fonti è completo. Usa uno dei due modi:
   - da cron, con PHP da riga di comando:
     ```bash
     */2 * * * * php /var/www/html/news-aggregator/rebuild_cache.php >> /var/www/html/news-aggregator/cache/rebuild.log 2>&1
     ```
   - da cron (oppure dal NAS o dal router) chiamando l'indirizzo web:
     ```bash
     */2 * * * * curl -s "http://IP-DEL-SERVER/news-aggregator/rebuild_cache.php?token=IL_TUO_TOKEN&batch=2" > /dev/null 2>&1
     ```
5. Dopo il primo giro completo, `http://IP-DEL-SERVER/news-aggregator/feed.php?token=IL_TUO_TOKEN` risponde con le notizie. Prima risponde "cache non ancora generata".

## Sensore in Home Assistant

In `configuration.yaml`:

```yaml
command_line:
  - sensor:
      name: News Aggregator
      unique_id: news_aggregator
      command: 'curl -s "http://IP-DEL-SERVER/news-aggregator/feed.php?token=IL_TUO_TOKEN"'
      value_template: "{{ value_json.articles | count }}"
      json_attributes:
        - articles
      scan_interval: 300
```

Riavvia Home Assistant: nasce `sensor.news_aggregator`, che è il sensore predefinito della card. Per avere solo alcuni argomenti, aggiungi `&topic=tecnologia,politica` all'indirizzo.

## Nella card

Nelle impostazioni della card (ingranaggio ⚙️):

- **Percorso server fonti RSS**: `http://IP-DEL-SERVER/news-aggregator/`, cioè la cartella, senza nome file.
- **Token amministrazione fonti**: lo stesso token di `settings.php`.

Da lì gestisci le fonti (vengono scritte in `config.php`), importi un file OPML e scegli l'ordine dei traduttori. `config.php` contiene già alcune fonti di esempio.

## I file

| File | A cosa serve |
|---|---|
| `settings.php` | Token e versioni: si modifica solo il token |
| `config.php` | Elenco delle fonti, gestito dalla card |
| `rebuild_cache.php` | Scarica i feed, le immagini e le traduzioni e scrive `cache/news.json` (da cron) |
| `feed.php` | Restituisce le notizie a Home Assistant |
| `summarize.php` | Riassunto automatico per il popup della card |
| `translate_admin.php` | Impostazioni di dove tradurre: Google, Ollama o un traduttore locale tipo LibreTranslate |
| `sources_admin.php` | Aggiunge, modifica ed elimina le fonti dalla card |
| `test_feed.php` | Bottone 🔍 "Verifica" di una fonte |
| `block_paths.php` | Notizie bloccate con il tocco prolungato |
| `opml_import.html`, `opml_import.php` | Importazione delle fonti da un file OPML |
| `lib/` | Decodifica dei link Google News, recupero immagini, argomenti, luoghi, traduzione |

`summarize.php` e `opml_import.php` scaricano solo link di notizie dei tuoi feed (o feed scelti da chi ha il token) e rifiutano gli indirizzi della rete locale.

Tutti gli endpoint chiedono il token, ma accettano richieste da qualunque origine (`Access-Control-Allow-Origin: *`). Va bene in rete locale. Se il server è raggiungibile da internet, conviene limitarlo all'indirizzo del tuo Home Assistant.
