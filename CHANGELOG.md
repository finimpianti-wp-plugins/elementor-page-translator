# Novità

## 0.16.2

- Le copie tradotte hanno sempre uno slug unico, anche in bozza: per i template UAE (che non hanno il prefisso della lingua nell'indirizzo) lo slug dell'originale riceve il suffisso della lingua ("footer01" → "footer01-en"). Prima una bozza tradotta poteva avere lo stesso slug dell'originale e l'anteprima di Elementor del template italiano caricava la copia tradotta, lasciando l'editor su "Caricamento".
- Rigenerare o aggiornare una traduzione mantiene il suo slug e non lo riporta mai uguale a quello dell'originale (prima veniva ricalcolato dal titolo); vale anche per i salvataggi dall'editor e dalla modifica rapida.
- Le pagine tradotte tengono il loro slug sotto la pagina della lingua (/en/servizi/ e /servizi/ sono indirizzi diversi).
- All'aggiornamento le copie tradotte con slug duplicato già presenti vengono sistemate e un avviso in bacheca elenca le modifiche. In *Traduzioni* (impostazioni) la sezione "Slug duplicati" mostra i contenuti che condividono uno slug, con il pulsante per sistemare le copie tradotte.

## 0.16.1

- Editor di Elementor: sulle richieste dell'editor e della sua anteprima (`?elementor-preview`, anteprima dei template) il plugin non fa mai redirect, non risponde 404 e non sostituisce la pagina richiesta (redirect dei vecchi indirizzi, pagina 404 per lingua, lingue nascoste). Precauzione: nessuna di queste funzioni interveniva su un'anteprima che risponde 200.

## 0.16.0

- Aggiornamenti automatici da GitHub: le nuove versioni compaiono in Bacheca → Aggiornamenti.
- Licenza GPL-2.0-or-later dichiarata nel plugin.

## 0.15.5

- Selettore lingua nel menu: effetto vetro sopra l'header trasparente, spaziature come le altre voci, elenco "Lingue" nel menu hamburger, pulsante dell'ultima voce di UAE mantenuto sulla voce precedente.

## 0.14.0

- Memoria dei link: le scelte fatte nel pannello Link vengono proposte sulle altre pagine.

## 0.13.0

- Nome e motto per lingua, ricerca e pagina 404 per lingua, lingue non pubbliche invisibili ai visitatori, adattatore per il banner Complianz.

## 0.12.0

- API pubblica (`ept_current_language()` e altre), traduzioni robuste al cambio di dominio, guida d'uso.
