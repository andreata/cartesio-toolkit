# Changelog

Formato [Keep a Changelog](https://keepachangelog.com/it/1.1.0/), versionamento
[semantico](https://semver.org/lang/it/).

## [1.0.0] — 2026-08-12

Prima release. Estratto dai mu-plugin dello starter per poter essere aggiornato
in un posto solo su tutti i progetti.

### Aggiunto

- **Migrazioni versionate del database.** Comandi `wp cartesio migrate`,
  `migrate-status`, `migrate-mark`. Tracking in `cartesio_migrations_applied`,
  esecuzione in ordine di nome file, stop al primo errore.
- **Rilevamento del drift.** `wp cartesio drift` elenca template, parti di template
  e stili globali finiti nel database invece che nei file. `--reset` li elimina,
  `--porcelain` restituisce exit 1 per l'uso in CI e nel deploy. I menu
  (`wp_navigation`) sono contenuto e non contano come drift, salvo
  `--include-menus`.
- **Guardrail sugli ambienti.** Fuori dalla produzione: email bloccate salvo
  allowlist, webhook WooCommerce fermi, gateway forzati in sandbox o ridotti ai
  metodi offline, badge dell'ambiente nella admin bar.
- **Separazione contenuti/struttura.** Site Editor chiuso a chi non è nel team e
  a tutti in produzione, "Modifica template" rimosso dall'editor, pattern remoti
  disattivati.
- **Profili di editing per progetto.** `CARTESIO_EDITING` sceglie fra
  `contenuti`, `composizione` e `struttura`: quanta libertà dare al cliente è
  una decisione del singolo sito, non una regola del modello. Il default è il
  profilo più chiuso, e `struttura` è ignorato sui temi classici, dove il Site
  Editor non esiste.
- **Moduli disattivabili** con `CARTESIO_TOOLKIT_MODULES`.
