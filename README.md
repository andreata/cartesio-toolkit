# Cartesio WP Toolkit

Infrastruttura condivisa dei progetti WordPress Cartesio, distribuita come pacchetto
Composer. Vive in un repo solo e arriva a tutti i siti: una correzione qui si
propaga con `composer update`, senza toccare 12 repository a mano.

Va installato in un progetto [Bedrock](https://roots.io/bedrock/) o comunque in
un WordPress gestito da Composer.

## Cosa contiene

| Modulo | Cosa fa |
|---|---|
| `migrations` | Migrazioni versionate del database, comandi WP-CLI `wp cartesio …` |
| `env-guard` | Neutralizza gli ambienti non-production: email, webhook, gateway |
| `editor-lock` | Separa l'editing di contenuti da quello di struttura |

## Installazione

```json
{
  "repositories": [
    { "type": "vcs", "url": "git@github.com:andreata/cartesio-toolkit.git" }
  ],
  "require": {
    "cartesio/toolkit": "^1.0"
  }
}
```

```bash
composer require cartesio/toolkit
```

`composer/installers` lo colloca in `web/app/mu-plugins/cartesio-toolkit/` e
l'autoloader di Bedrock lo carica. Non serve attivare nulla.

## Comandi

```bash
wp cartesio migrate                 # applica le migration in sospeso
wp cartesio migrate --pretend       # simula
wp cartesio migrate --only=<id>     # applica una sola migration
wp cartesio migrate-status          # stato di tutte
wp cartesio migrate-mark <id>       # registra come applicata senza eseguirla
wp cartesio migrate-mark <id> --undo
wp cartesio drift                   # override finiti nel DB invece che nei file
wp cartesio drift --reset           # li elimina, ripristinando i file del tema
wp cartesio drift --porcelain       # exit 1 se c'è drift (per CI e deploy)
wp cartesio drift --include-menus   # conta anche i menu (di default sono contenuto)
```

## Configurazione

**Dove cerca le migration.** Di default `<root>/db/migrations`, dove la root è
`CARTESIO_ROOT_DIR` se definita (Bedrock la definisce in `config/application.php`),
altrimenti due livelli sopra `ABSPATH`. Si sovrascrive con:

```php
Config::define( 'CARTESIO_MIGRATIONS_DIR', '/percorso/assoluto' );
```

**Quali moduli caricare.** Tutti, salvo diversa indicazione:

```php
Config::define( 'CARTESIO_TOOLKIT_MODULES', array( 'migrations', 'env-guard' ) );
```

**Quanta libertà dare al cliente.** Si sceglie per progetto, in
`config/application.php`:

```php
Config::define( 'CARTESIO_EDITING', 'contenuti' );
```

| Profilo | Il cliente può | Come |
|---|---|---|
| `contenuti` *(default)* | Riempire testi e immagini nelle strutture previste. Nessun inseritore: l'editor si comporta come un form. | `templateLock => contentOnly` |
| `composizione` | Comporre la pagina con i blocchi in allowlist. Template e stili globali restano nei file. | allowlist del tema |
| `struttura` | Aprire il Site Editor, se ha uno dei ruoli abilitati. | richiede un block theme |

Il default è il profilo più chiuso di proposito: un sito che non dichiara nulla
non deve trovarsi il Site Editor aperto per distrazione. Un valore non
riconosciuto ricade su `contenuti`.

`struttura` viene ignorato su un tema classico: lì il Site Editor non esiste e
concederlo non significherebbe nulla.

Per leggere il profilo dal codice: `cartesio_editing_profile()`, oppure
`cartesio_editing_almeno( 'composizione' )` per un confronto di livello.

**Chi può usare il Site Editor.** Di default solo gli amministratori, e mai in
produzione. Per aprirlo in produzione a chi lavora sul repo:

```php
Config::define( 'CARTESIO_ALLOW_SITE_EDITOR', true );
```

```php
add_filter( 'cartesio_site_editor_roles', fn() => array( 'administrator', 'editor' ) );
```

**Email in staging.** `CARTESIO_MAIL_ALLOWLIST` nel `.env` elenca i domini verso cui
le email possono comunque partire. Vuoto significa che non parte niente.

**Action Scheduler.** `CARTESIO_DISABLE_SCHEDULER=1` nel `.env` mette in pausa il
cron di WooCommerce su staging.

## Versionamento

Semver. Il numero major cambia solo per modifiche che richiedono un intervento
sui progetti: allora il changelog dice esattamente cosa fare.

I siti pinnano `^1.0`, quindi ricevono correzioni e funzionalità in automatico
con `composer update cartesio/toolkit` e non vengono mai sorpresi da un major.

## Sviluppo

Questo pacchetto è codice infrastrutturale: **non si modifica dentro un sito**.
Si lavora qui, si tagga, si aggiorna la dipendenza sui progetti.

```bash
composer install
composer lint
```

Per provare una modifica su un sito prima di taggarla, in quel progetto:

```json
"repositories": [
  { "type": "path", "url": "../cartesio-toolkit", "options": { "symlink": true } }
]
```

Ricordati di rimuoverlo prima di committare.
