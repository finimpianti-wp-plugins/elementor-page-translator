# Elementor Page Translator

Traduce le pagine costruite con Elementor e i template header/footer di Ultimate Addons for Elementor in copie per lingua (`/en/`, `/de/`…): testi, link interni, menu e shortcode per lingua, SEO multilingua (hreflang, title, description, Open Graph, compatibile con Yoast e Rank Math), selettore lingua (shortcode o voce di menu), memoria traduzioni, proposte DeepL facoltative, aggiornamento delle traduzioni quando cambia la pagina italiana, ricerca e pagina 404 per lingua.

## Installazione

1. Scarica lo zip dell'ultima versione da **Releases** (a destra in questa pagina): `elementor-page-translator-<versione>.zip`.
2. In WordPress: *Plugin → Aggiungi nuovo → Carica plugin* → scegli lo zip → *Installa* (o *Sostituisci quella attuale*).

## Aggiornamenti

Il plugin controlla da solo le release di questo repository: quando ne esce una nuova compare in
*Bacheca → Aggiornamenti* e in *Plugin*, e si installa con un clic. Impostazioni e dati restano nel database del sito.

## Nuove versioni (per chi sviluppa)

1. Aggiorna `Version:` nell'intestazione del file principale (e la costante della versione) e aggiungi le novità in `CHANGELOG.md`.
2. Pubblica l'etichetta `v<versione>` (es. `v0.16.0`): GitHub crea lo zip e la release (`.github/workflows/release.yml`).

Per creare lo zip in locale: `bash bin/build-zip.sh` (risultato in `dist/`).

## Licenza

GPL-2.0-or-later. Include [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (MIT).
