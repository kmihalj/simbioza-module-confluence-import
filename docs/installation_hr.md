# Instalacija i konfiguracija

Engleska verzija: [installation_en.md](installation_en.md)

## Preduvjeti

- PHP 8.2 ili noviji;
- ekstenzije DOM, Fileinfo, LibXML, XMLReader i ZIP;
- obavezni paketi navedeni u korijenskom [README-u](../README_hr.md);
- aplikacijski direktorij `data` u koji je dopušteno pisanje;
- aplikacijska baza koju podržava HeartPhrame ORM.

U `app.modules.enabled` ovaj modul mora biti iza ORM-a, Menija, Autha, HTML Editora, Workspacea i Simbioza Usera.

## Instalacija

```bash
composer require aaieduhr/simbioza-module-confluence-import:^0.3.1
vendor/bin/hph simbioza-confluence-import:install-migration
vendor/bin/hph orm-migrate up
```

Instalacijska naredba kopira vremenski označenu reverzibilnu migraciju u host aplikaciju. Prije pokretanja pregledajte je zajedno s ostalim aplikacijskim migracijama.

## Konfiguracija

Instalacijske postavke spremite u
`HPH_CONFIG_PATH/confluence-import/confluence-import.php`. Bez varijable okruženja
putanja je `<aplikacija>/config/confluence-import/confluence-import.php`.
Nepromijenjeni framework učitava ova PHP polja i uređene
`HPH_CONFIG_OVERRIDE_PATHS` slojeve prije izrade servisa modula. Izostavljeni
ključevi zadržavaju paketne vrijednosti; prazno polje višeg sloja resetira ovu politiku.
Staro ravno stablo `confluence_import` ne koristi se.

| Ključ | Zadano | Namjena |
| --- | ---: | --- |
| `data_path` | `confluence-import` | Direktorij ispod aplikacijskog direktorija `data` |
| `max_archive_size` | 8 GiB | Najveća ZIP arhiva |
| `chunk_size` | 8 MiB | Veličina dijela nastavivog prijenosa |
| `upload_ttl` | 24 sata | Zadržavanje nedovršenog prijenosa |
| `max_entries` | 250.000 | Najveći broj ZIP zapisa |
| `max_uncompressed_size` | 16 GiB | Ukupna raspakirana veličina jednog područja |
| `max_entry_size` | 4 GiB | Najveći raspakirani zapis jednog područja |
| `max_site_uncompressed_size` | 128 GiB | Ukupna raspakirana veličina sitea ili više područja |
| `max_site_entry_size` | 64 GiB | Najveći raspakirani zapis sitea ili više područja |
| `max_inventory_object_size` | 16 MiB | Najveći zadržani objekt metapodataka |
| `max_inventory_spaces` | 10.000 | Najveći broj zadržanih područja/referenci |
| `max_site_identity_records` | 100.000 | Zadržani objekti identiteta/imenika/članstava/ovlasti |
| `max_site_identity_index_size` | 32 MiB | Najveći potpuni privatni pregled identiteta |
| `max_compression_ratio` | 250 | Zaštita od ZIP bombi |
| `import_execution_time_limit` | 900 sekundi | Gornja granica provjere velike arhive ili jednog procesnog koraka importa |
| `import_memory_limit_mb` | 512 MiB | Memorija koja se traži samo tijekom administratorske obrade importa |
| `default_language` | `hr` | Jezik sadržaja kada ga izvoz ne navodi |
| `source_base_url` | prazno | Početna vrijednost URL-a izvornog Confluencea; administrator ga potvrđuje ili mijenja za svaki pojedinačni ili batch import |

Primjer datoteke (bez dodatnog vanjskog polja):

```php
<?php

return [
    'chunk_size' => 16 * 1024 * 1024,
    'max_archive_size' => 8 * 1024 * 1024 * 1024,
    'import_execution_time_limit' => 1200,
    'default_language' => 'hr',
    'source_base_url' => 'https://wiki.example.org',
];
```

`source_base_url` nije fiksna postavka instalacije. Ona samo unaprijed popunjava
administratorsko polje, a stvarni se URL sprema uz svaki pokrenuti import.
`data_path` mora biti relativni poddirektorij bez `..`, apsolutnih putanja i
obrnutih kosih crta. Prijenosi i stanje importa ostaju u privatnom `data` direktoriju.
Postojeći GUI, mapiranja i odabir izvora po poslu ostaju nepromijenjeni.

Prijenos i potvrđeni sadržajni import jednog područja rade u nastavivim fazama. Nakon potvrde mapiranja
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

Razvojna dopuna dodaje [pregled sitea/više područja](site-inventory_hr.md), zaseban
[pregled/uvoz identiteta](site-identities_hr.md) i [odabrani sadržaj](site-content_hr.md).
Prekinuti početni pregledi kreću ispočetka; koriste se samo potpuni indeksi. C3 čuva
podjele odabranog sadržaja uz privatni ZIP i zajednički izvor za C4. Nijedan korak
ne traži novu migraciju. Omogućite vrijeme i memoriju za dva zajednička XML prolaza,
zatim ograničenu obradu područja; pogledajte C3 uputu za oporavak.
