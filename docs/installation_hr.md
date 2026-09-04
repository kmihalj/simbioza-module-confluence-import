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
composer require aaieduhr/simbioza-module-confluence-import:^0.1.10
vendor/bin/hph simbioza-confluence-import:install-migration
vendor/bin/hph orm-migrate up
```

Instalacijska naredba kopira vremenski označenu reverzibilnu migraciju u host aplikaciju. Prije pokretanja pregledajte je zajedno s ostalim aplikacijskim migracijama.

## Konfiguracija

Aplikacijske vrijednosti pod `confluence_import.*` nadjačavaju zadane vrijednosti modula:

| Ključ | Zadano | Namjena |
| --- | ---: | --- |
| `data_path` | `confluence-import` | Direktorij ispod aplikacijskog direktorija `data` |
| `max_archive_size` | 8 GiB | Najveća ZIP arhiva |
| `chunk_size` | 8 MiB | Veličina dijela nastavivog prijenosa |
| `upload_ttl` | 24 sata | Zadržavanje nedovršenog prijenosa |
| `max_entries` | 250.000 | Najveći broj ZIP zapisa |
| `max_uncompressed_size` | 16 GiB | Ukupna raspakirana veličina |
| `max_entry_size` | 4 GiB | Najveća pojedinačna raspakirana datoteka |
| `max_compression_ratio` | 250 | Zaštita od ZIP bombi |
| `import_execution_time_limit` | 900 sekundi | Gornja granica provjere velike arhive ili jednog procesnog koraka importa |
| `default_language` | `hr` | Jezik sadržaja kada ga izvoz ne navodi |

Primjer konfiguracije host aplikacije:

```php
'confluence_import' => [
    'chunk_size' => 16 * 1024 * 1024,
    'max_archive_size' => 8 * 1024 * 1024 * 1024,
    'import_execution_time_limit' => 1200,
    'default_language' => 'hr',
],
```

Prijenos i sadržajni import rade u nastavivim fazama. Nakon potvrde mapiranja
poslužitelj pripremi stabilan plan, zatim svaki zahtjev obrađuje ograničen broj
privitaka ili stranica. Preglednik prikazuje napredak i poziva sljedeći korak
dok finalizacija ne dovrši poveznice, izvještaj i izvedene indekse. Prekid
preglednika ne poništava dovršene korake; ponovno otvaranje posla nastavlja iz
spremljenog stanja bez dupliciranja sadržaja.

Web-poslužitelj i PHP zato moraju dopustiti trajanje jednog procesnog koraka, a
ne cijelog višegigabajtnog importa. Vrijednost ipak treba uskladiti s proxyjem i
PHP-FPM-om jer početna priprema arhive i finalizacija mogu biti skuplje od
običnog batcha.

Istekli prijenos nije moguće nastaviti. Sigurno se uklanja prije otvaranja novog
prijenosa. Administrator ga prije isteka može odmah ukloniti naredbom
**Odustani od importa**. Isto vrijedi za posao kojem početna provjera nije
uspjela, ako sadržajni import nije započeo. Dovršeni i djelomično izvedeni
importi nikada se ne uklanjaju čišćenjem privremenih prijenosa.
