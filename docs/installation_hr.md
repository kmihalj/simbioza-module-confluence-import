# Instalacija i konfiguracija

English version: [installation_en.md](installation_en.md)

## Preduvjeti

- PHP 8.2 ili noviji;
- ekstenzije DOM, Fileinfo, LibXML, XMLReader i ZIP;
- obavezni paketi navedeni u korijenskom [README-u](../README_hr.md);
- aplikacijski direktorij `data` u koji je dopušteno pisanje;
- aplikacijska baza koju podržava HeartPhrame ORM.

U `app.modules.enabled` ovaj modul mora biti iza ORM-a, Menija, Autha, HTML Editora, Workspacea i Simbioza Usera.

## Instalacija

```bash
composer require aaieduhr/simbioza-module-confluence-import:dev-main
vendor/bin/hph simbioza-confluence-import:install-migration
vendor/bin/hph orm-migrate up
```

Instalacijska naredba kopira vremenski označenu reverzibilnu migraciju u host aplikaciju. Prije pokretanja pregledajte je zajedno s ostalim aplikacijskim migracijama.

## Konfiguracija

Aplikacijske vrijednosti pod `confluence_import.*` nadjačavaju zadane vrijednosti modula:

| Ključ | Zadano | Namjena |
| --- | ---: | --- |
| `data_path` | `confluence-import` | Direktorij ispod aplikacijskog direktorija `data` |
| `max_archive_size` | 4 GiB | Najveća ZIP arhiva |
| `chunk_size` | 8 MiB | Veličina dijela nastavivog prijenosa |
| `upload_ttl` | 24 sata | Zadržavanje nedovršenog prijenosa |
| `max_entries` | 250.000 | Najveći broj ZIP zapisa |
| `max_uncompressed_size` | 16 GiB | Ukupna raspakirana veličina |
| `max_entry_size` | 4 GiB | Najveća pojedinačna raspakirana datoteka |
| `max_compression_ratio` | 250 | Zaštita od ZIP bombi |
| `default_language` | `hr` | Jezik sadržaja kada ga izvoz ne navodi |

Primjer konfiguracije host aplikacije:

```php
'confluence_import' => [
    'chunk_size' => 16 * 1024 * 1024,
    'max_archive_size' => 8 * 1024 * 1024 * 1024,
    'default_language' => 'hr',
],
```

Za velike importe potrebno je prilagoditi i vremenska ograničenja web-poslužitelja i PHP-a. Preglednik može nastaviti prijenos, ali potvrđeni import izvodi se kao jedna kontrolirana operacija.

Istekli prijenos nije moguće nastaviti. Sigurno se uklanja prije otvaranja novog
prijenosa. Dovršeni i djelomično izvedeni importi nikada se ne uklanjaju tim
čišćenjem privremenih prijenosa.
