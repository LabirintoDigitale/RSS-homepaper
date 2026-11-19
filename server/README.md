# Server news-aggregator

È la parte che lavora dietro la card. Legge i feed RSS, scarica le immagini, traduce titoli e descrizioni e prepara i riassunti. Le notizie pronte finiscono in `cache/news.json`, e Home Assistant le legge da `feed.php`.

## Cosa serve

Serve un **server web con PHP**. Puoi usare:

- **un server separato**, per esempio un Raspberry, un NAS, un PC o un container con Apache o nginx (vedi [Installazione A](#installazione-a-server-separato));
- **Home Assistant stesso**, con un add-on che fa da server web con PHP. Così non ti serve un'altra macchina (vedi [Installazione B](#installazione-b-dentro-home-assistant)).

In tutti e due i casi servono:

- **PHP 7.4 o più recente**.
- Le estensioni PHP **curl**, **mbstring**, **simplexml** e **dom** (su Debian/Ubuntu: `apt install php-curl php-mbstring php-xml`).
- La cartella `news-aggregator/` deve poter essere scritta dal server web, perché lì viene creata `cache/`.

## Installazione A: server separato

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

## Installazione B: dentro Home Assistant

Con Home Assistant OS o Supervised puoi far girare il server PHP come add-on, sulla stessa macchina di Home Assistant. Uno degli add-on più usati è **Apache2** di FaserF, che include PHP.

1. **Installa l'add-on**:
   - **Impostazioni → Componenti aggiuntivi → Raccolta componenti aggiuntivi** → ⋮ → **Repository**;
   - aggiungi `https://github.com/FaserF/hassio-addons`;
   - installa **Apache2**, la versione completa: quella "minimal" non ha PHP.
2. **Configura l'add-on**: lascia la cartella dei siti su `/share/htdocs` (o quella indicata nella scheda *Configurazione*). Poi avvialo e attiva **Avvia all'avvio**.
3. **Copia i file**: metti la cartella `news-aggregator/` dentro `/share/htdocs/`. Puoi usare l'add-on **Samba share** (cartella `share` → `htdocs`) oppure **Studio Code Server**.
4. **Token**: in `/share/htdocs/news-aggregator/settings.php` sostituisci `CAMBIA_QUESTO_TOKEN` con il tuo token (vedi passo 2 dell'Installazione A).
5. **Prova**: apri `http://IP-DI-HOME-ASSISTANT/news-aggregator/feed.php?token=IL_TUO_TOKEN`. Usa la porta dell'add-on se non è la 80. Se risponde "cache non ancora generata", il PHP funziona.
6. **Aggiornamento automatico**: senza cron, lo fa Home Assistant chiamando `rebuild_cache.php` ogni 2 minuti. In `configuration.yaml`:
   ```yaml
   rest_command:
     news_rebuild:
       url: "http://IP-DI-HOME-ASSISTANT/news-aggregator/rebuild_cache.php?token=IL_TUO_TOKEN&batch=2"
       timeout: 60
   ```
   e un'automazione:
   ```yaml
   alias: News aggregator - aggiorna notizie
   triggers:
     - trigger: time_pattern
       minutes: "/2"
   actions:
     - action: rest_command.news_rebuild
   mode: single
   ```
7. **Sensore e card**: aggiungi il sensore (sezione sotto) e nella card imposta il **percorso server** `http://IP-DI-HOME-ASSISTANT/news-aggregator/`.

Cose da sapere:

- Nome e opzioni dell'add-on possono cambiare tra una versione e l'altra: controlla la sua documentazione nella scheda *Documentazione*. Verifica anche che abbia le estensioni PHP **curl**, **mbstring** e **xml**.
- Se apri Home Assistant in **HTTPS** e il server PHP è in HTTP, il browser blocca le chiamate della card (fonti, riassunti). Il riassunto mostra "contenuto misto". In quel caso abilita l'HTTPS anche nell'add-on.
- Il primo giro completo può richiedere qualche minuto: dipende da quante fonti hai.

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

Da lì gestisci le fonti (vengono scritte in `config.php`), importi fonti da un file OPML o da una pagina web e scegli l'ordine dei traduttori. `config.php` contiene già alcune fonti di esempio.

### Importare fonti da una pagina web

Nel campo dell'importazione puoi mettere, al posto di un file OPML:

- **un elenco di feed**, per esempio `https://rssly.io/feeds`. Il server legge la pagina e le sue sottopagine sotto lo stesso percorso (al massimo 30), quindi qui tutte le categorie. Le categorie diventano i gruppi nella pagina di scelta;
- **il sito di un giornale**, per esempio `https://www.ilpost.it/`. Il server prende i feed dichiarati dal sito. Se il sito non ne dichiara, prova gli indirizzi più comuni (`/feed`, `/rss.xml`, `/atom.xml`…);
- **un feed**: viene proposto solo quello.

Ogni indirizzo trovato viene scaricato per controllare che sia davvero un feed RSS/Atom: nella lista restano solo quelli che funzionano. Su un elenco grande servono 10-60 secondi. Le pagine che mostrano l'elenco solo dopo il caricamento (JavaScript) non si possono leggere.

## Pulizia della cache

Tutto quello che finisce in `cache/` si pulisce da solo, non serve fare niente:

| Cosa | Quando si cancella |
|---|---|
| `news.json`, `state.json` (notizie) | Riscritti da zero a ogni giro completo, non crescono |
| `translations/` (traduzioni) | Dopo 21 giorni che non vengono usate. Finché un articolo è nei feed la sua traduzione resta. Il controllo lo fa ogni giro di `rebuild_cache.php` |
| `summaries/` (riassunti) | Validi 3 giorni, cancellati dopo 7 giorni o oltre 400 file |
| `progress/` | Dopo 15 minuti |
| `opml_previews/` | Dopo 3 giorni o oltre 1500 file |
| `errors.log` | Sopra i 2 MB tiene solo la metà più recente |
| `debug.log` | Riscritto a ogni giro |

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
| `opml_import.html`, `opml_import.php` | Importazione delle fonti da un file OPML o da una pagina web |
| `lib/` | Decodifica dei link Google News, recupero immagini, argomenti, luoghi, traduzione |

`summarize.php` e `opml_import.php` scaricano solo link di notizie dei tuoi feed (o feed scelti da chi ha il token) e rifiutano gli indirizzi della rete locale.

Tutti gli endpoint chiedono il token, ma accettano richieste da qualunque origine (`Access-Control-Allow-Origin: *`). Va bene in rete locale. Se il server è raggiungibile da internet, conviene limitarlo all'indirizzo del tuo Home Assistant.
