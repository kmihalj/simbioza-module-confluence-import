# Testiranje i rješavanje problema

English version: [testing-troubleshooting_en.md](testing-troubleshooting_en.md)

## Lokalni skup provjera

Instalirajte aktualne razvojne ovisnosti pa pokrenite:

```bash
composer validate --strict
composer on-commit
```

Skup provjerava PSR-12, Rector dry-run, PHPStan razinu 7 i PHPUnit. Jedinični testovi pokrivaju sigurnu obradu ZIP-a, čitanje stvarnih Confluence entiteta, pretvorbu storage-formata i reverzibilnu izradu SQLite sheme.

Prije izdanja host aplikacije pokrenite i njezinu cjelovitu MySQL, PostgreSQL i SQLite E2E matricu s uključenim modulom.

## Read-only dijagnostika

```bash
vendor/bin/hph simbioza-confluence-import:inspect /srv/import/izvor.xml.zip
```

JSON izlaz omogućuje provjeru identiteta spacea, količine objekata, stanja, grupa i kategorija upozorenja bez izmjene aplikacijskih podataka.

## Česti problemi

### Prijenos se ne nastavlja

Koristite istu sesiju preglednika i odaberite istu lokalnu datoteku. Ako se naziv ili veličina izvora razlikuju, potreban je novi prijenos. Prijenos stariji od `upload_ttl` nije moguće ponovno koristiti i automatski se uklanja pri pokretanju novog prijenosa.

### Import nije moguće pokrenuti

Početna provjera najprije mora uspješno završiti. Osobni space dodatno zahtijeva potvrđeno mapiranje vlasnika.

### Korisnik ne vidi ograničenu stranicu

Provjerite izvorne korisnike/grupe i potvrdite mapiranje. To je siguran neuspjeh: neriješeno ograničenje uskraćuje pristup umjesto otvaranja stranice.

### Poveznica među spaceovima i dalje otvara resolver

Uvezite odredišni space. Resolver čuva izvorni ID stranice i ponovno pokušava usklađivanje nakon svakog uspješnog importa. Ako izvor upućuje na obrisanu ili neizvezenu stranicu, izvorni URL ostaje dostupan.

### Datoteka nije uvezena

U sažetku posla provjerite nedostajući fizički ZIP zapis, neispravan identifikator ili sigurnosno ZIP ograničenje. Ograničenje mijenjajte tek nakon provjere arhive, zatim je prenesite kao novi posao. Staging direktorij nikada ne izlažite kroz web-poslužitelj.

### Potvrđeni import prekinuo se na pola

Nemojte ponavljati isti posao jer izrada nije idempotentna. Pregledajte i prema potrebi uklonite djelomično područje, ispravite prijavljeni uzrok i ponovno prenesite izvor. Nakon neuspjeha izvorna arhiva ostaje na poslužitelju radi dijagnostike i mora biti zaštićena ovlastima datotečnog sustava.
