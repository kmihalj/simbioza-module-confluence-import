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

### Prenio sam arhivu, ali ne želim pokrenuti import

Otvorite spremljeno mapiranje ili pronađite posao pod **Nedavni Confluence
importi** i odaberite **Odustani od importa**. Potvrdom se odmah brišu arhiva i
podaci pripreme. Ova radnja dostupna je samo prije početka sadržajnog importa.

### Import nije moguće pokrenuti

Početna provjera najprije mora uspješno završiti. Osobni space dodatno zahtijeva potvrđeno mapiranje vlasnika.

### Korisnik ne vidi ograničenu stranicu

Provjerite izvorne korisnike/grupe i potvrdite mapiranje. To je siguran neuspjeh: neriješeno ograničenje uskraćuje pristup umjesto otvaranja stranice.

### Postojeća grupa nije automatski predložena

Prijedlog zahtijeva točan tehnički ključ ili točan naziv aktivne grupe, bez
obzira na veličinu slova i suvišne razmake. Sličan naziv, isključena grupa ili
dvosmislen rezultat namjerno ostaju nemapirani. Provjerite ključ u Auth
postavkama pa cilj odaberite ručno samo ako predstavlja istu grupu.

### Uvezeni neaktivni korisnik ne može se prijaviti

To je očekivano. Predračun nema lozinku ni provider. U Auth postavkama mu
administrator treba uključiti stvarni provider i aktivirati ga. Za local-only
pristup mora postaviti privremenu lozinku; kod kombiniranog vanjskog i local
pristupa korisnik može nakon vanjske prijave sam postaviti lokalnu lozinku.

### Poveznica među spaceovima i dalje otvara resolver

Uvezite odredišni space. Resolver čuva izvorni ID stranice i ponovno pokušava usklađivanje nakon svakog uspješnog importa. Ako izvor upućuje na obrisanu ili neizvezenu stranicu, izvorni URL ostaje dostupan.

### Datoteka nije uvezena

U sažetku posla provjerite nedostajući fizički ZIP zapis, neispravan identifikator ili sigurnosno ZIP ograničenje. Ograničenje mijenjajte tek nakon provjere arhive, zatim je prenesite kao novi posao. Staging direktorij nikada ne izlažite kroz web-poslužitelj.

### Potvrđeni import prekinuo se na pola

Nemojte ponavljati isti posao jer izrada stranica nije idempotentna. Pregledajte i prema potrebi uklonite djelomično područje, ispravite prijavljeni uzrok i ponovno prenesite izvor. Već sigurno spremljene binarne verzije privitaka ponovno se koriste i ne dupliciraju na disku. Nakon neuspjeha izvorna arhiva ostaje na poslužitelju radi dijagnostike i mora biti zaštićena ovlastima datotečnog sustava.

Provjera velike arhive i potvrđeni import koriste `confluence_import.import_execution_time_limit` (zadano 900 sekundi). Import skupno osvježava backlinkove i indeks pretrage samo jednom po području. Ako PHP ipak završi fatalnom pogreškom, posao se označava neuspjelim, a sigurna poruka prikazuje se u popisu poslova; tehnički detalji ostaju u tehničkom logu.
