# Izvještaji uvoza sitea i završne provjere

[English version](site-reports_en.md)

## Zajednički pregled

Otvorite pripremljeni posao sitea u **Postavke → Confluence import** pa odaberite
**Otvori zajednički izvještaj**. Isti pregled nalazi se ispod koraka uvoza.
Radi prije završetka sadržajnog importa, nakon uklanjanja radnog izvora te nakon
vraćanja potpunog backupa. GET ne čita arhivu niti mijenja sadržaj.

Zajednički, identitetski i kalendarski izvještaji smještaju izvor/stanje, objašnjenja
i rezultate u sadržajnu karticu aktivne teme. To nije slobodan tekst preko hero
zaglavlja; površinama i bojama teksta i dalje upravlja tema.

Pregled navodi povezane postupke identiteta/članstava, pojedina područja i postupke
kalendara. Obrada u tijeku ili s greškom ne prikazuje se kao dovršena. Zaustavljeni
kalendarski postupak označen je kao djelomičan. Svaki dovršeni red vodi na detaljni
izvještaj; nazivi izvora, upozorenja i uvezeni kod ne umeću se kao sirovi HTML.
Brojanja predstavljaju zabilježene postupke, ne jedinstvene objekte kroz ponavljanja.
Izvještaj korisnika/grupa/članstava, izvještaj kalendara i postojeći detaljni izvještaj
svakog područja ostaju odvojeni. Zajednički pregled samo ih povezuje i sažima.
Razriješene/nerazriješene poveznice broje se iz aktualnih spremljenih redaka, ne iz
kopije povijesnog brojanja. Kad kasniji uvoz područja razriješi link u ranijem
području, i stari izvještaj pokazuje novo stanje, uz isti UUID i izvorna brojanja
uvoza. Povezivanje kalendara ažurira izvorni izvještaj pripadajuće stranice.

Detaljni izvještaji područja zadržavaju sadašnji pregled privitaka, neriješenih
poveznica, kalendarskih makroa i odobravanja nepoznatih skripti. Otvaranje izvještaja
ne izvršava nepoznatu skriptu. Odobrene skripte i dalje prate pravila registra za
aktivaciju po stranici. Pregled broji neriješene skripte/stavke provjere; odobrenja
i ispravci ostaju u pripadajućem izvještaju i GUI-ju vlasničkog modula. Izvještaji
kalendara razlikuju nove, postojeće i preskočene događaje. Nepodržano ponavljanje
prijavljuje se, ne pretvara se prešutno u pojedinačne događaje.

Veze izvještaja provjeravaju vlasnika/izvor i dohvaćaju se u paketima do 20.
Zajednički pregled ne učitava sve probleme stranica svih područja u jedan niz.
Izvještaje čitaju samo administratori. Uvoz, ponavljanje i čišćenje ograničeni su
na administratora vlasnika posla te koriste uobičajene CSRF provjere.

## Zadržavanje izvora i izričito čišćenje

Zajednički uvoz prikazuje istaknutu traku **Napredak trenutačnog koraka** s odvojenim
postotkom i aktivnom fazom. Napredak kalendara broji potvrđene pakete, a napredak
područja prati spremljene kontrolne točke podposlova. Brojač ispod trake prikazuje
dovršene/odabrane kalendare ili područja. Postotak vrijedi za trenutačni korak,
ne za proteklo vrijeme ili cijeli uvoz, pa počinje iznova pri promjeni koraka.
Priprema i završno povezivanje prikazuju animiranu traku bez izmišljenog postotka.
Osvježavanje stranice čita spremljeni napredak; za nastavak odaberite **Nastavi
cijeli uvoz**. Greška zaustavlja animaciju i označuje traku crveno; uspjeh znači
isključivo završetak cijelog postupka.

Zatvaranje kartice nije odustajanje: otvorite isti posao i odaberite
**Nastavi cijeli uvoz**. Koordinator nastavlja trajne kontrolne točke redom:
identiteti/članstva, kalendari, područja pa povezivanje referenci. Greška zaustavlja
posao i čuva izvor te dovršene/djelomične izvještaje, bez lažnog uspjeha.

Nakon završetka cijelog postupka, uključujući povezivanje referenci, vlasnik
može odabrati **Ukloni radnu kopiju arhive i indekse** i potvrditi upozorenje.
Brišu se samo
upravljani preneseni ZIP, poznati privatni indeksi i staging dovršenih podposlova.
Izvorna arhiva izvan aplikacije nikada se ne briše. Korisnici, grupe, kalendari,
dokumenti, privici, trajna mapiranja i izvještaji ostaju sačuvani.

To nije poništavanje uvoza. Nakon čišćenja ista radna kopija više nije dostupna;
za kasniju migraciju ponovno prenesite sačuvani izvornik. Automatski istek vrijedi
samo za privremene poslove prije uvoza, nikada za aktivne/djelomično potvrđene
kalendare. Nepoznate datoteke indeksa ili symlinkovi prekidaju čišćenje umjesto
brisanja proizvoljnih putanja. Zauzeta obrada blokira čišćenje. Obrisan ili nedostupan
povezani izvještaj prikazuje se kao nedostupan, ne kao uspješan korak.

## Backup i drugi site

Koristite backup cijelog sitea ili uključite potrebne vlasničke cjeline kod backupa
komponenti. Identiteti i kalendari imaju zasebne dovršene izvještaje pa njihova
trajna mapiranja ne ovise o izvozu nedovršenog roditelja. Sažetak dovršenog roditelja
čuva UUID-jeve izvještaja/podposlova nakon vraćanja. Nativni Calendar i Workspace/
Editor provideri vlasnici su stvarnog sadržaja i ID-jeva. Importer mapira strane
ID-jeve i izostavlja putanje ZIP-ova, tokene pregleda i lokalne izvršne odabire.
Privatni indeksi nisu prenosiva konfiguracija.

Vraćanjem backupa vraćaju se izvještaji, ne izvršivi posao izvora. Nedostajuće
arhive na odredištu ne mogu se nastaviti. Koristite obični preflight backupa i
pravila izvorne/ciljne adrese; drugo odredište koristi premještanje internih
poveznica. Nakon obnove provjerite neriješene poveznice i skripte. Lozinke/tokeni,
SSO konfiguracija i pretvaranje globalnih administratora nisu dio Confluence
uvoza. Ne uvoze se binarni dodaci, audit/analitička povijest ni proizvoljne AO
tablice; sadržaj zadataka, bočnih izbornika, predložaka i makroa koristi samo
postojeće podržane pretvarače.

## Ponovljive razvojne provjere

Iz repozitorija importera pokrenite analize/testove modula:

```sh
composer on-commit
```

Iz Simbiozina izvornog checkouta stvorite izoliranu aplikaciju sa svim modulima:

```sh
php scripts/run_e2e.php --local --keep --spec=confluence-import.spec.js
```

Ispisani potrošni direktorij `e2e-all-*`, **nikada HFC**, proslijedite fixtureu:

```sh
php tests/Fixtures/site_acceptance.php /apsolutna/privremena/heartphrame-clean-matrix/e2e-all-PRIMJER filesystem
php tests/Fixtures/site_acceptance.php /apsolutna/privremena/heartphrame-clean-matrix/e2e-all-PRIMJER database
php tests/Fixtures/site_workflow_acceptance.php /apsolutna/privremena/heartphrame-clean-matrix/e2e-all-PRIMJER filesystem
php tests/Fixtures/site_workflow_acceptance.php /apsolutna/privremena/heartphrame-clean-matrix/e2e-all-PRIMJER database
```

Fixture odbija obične instalacije. Stvara izmišljeni C2/C3/C4 sadržaj, provjerava
stvarni način spremanja HTML verzija, ponavljanja/mapiranja, zajedničke/prenosive
izvještaje i izričito čišćenje izvora. Mrežne baze koriste opciju postojećeg runnera
`--database=pgsql|mysql|mariadb` i varijable `HPH_MATRIX_DB_*` nad **novom praznom
potrošnom bazom**.
Zajednički fixture provjerava čistoću pregleda, odbijanje zastarjele potvrde,
odgodu istodobnog zahtjeva, budućeg vlasnika osobnog kalendara, ponovljene zahtjeve,
autorstvo prije sadržaja, oba smjera poveznica i kasniji kalendar automatski povezan
na ranijoj stranici, uz ažuriranje njezina postojećeg izvještaja.

`site_backup_acceptance.php IZVOR CILJ` provjerava vraćanje na drugi site između
dviju različitih potrošnih clean-matrix instalacija. Izvor prvo treba proći
`site_acceptance.php`; odredište može nastati matricom čiste instalacije. Zasebni
`site_http_probe.php`, priprema i FPM/nginx/Apache fixturei ograničeni su na
`/srv/simbioza-c5-acceptance/` u potrošnom Linux okruženju. Te sintetičke provjere
bez autentikacije nikada ne kopirati u javnu instalaciju.

Plan implementacije navodi stvarno provedenu matricu i otvorene granice prihvata.
Prihvat lokalnog razvojnog izvora ne znači da su objavljeni novi tagovi ili prošao
Git CI. Ovim provjerama ne objavljuje se release.
