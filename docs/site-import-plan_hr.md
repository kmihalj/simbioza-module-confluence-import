# Plan implementacije uvoza Confluence sitea

[Engleska verzija](site-import-plan_en.md)

Proširiti postojeći importer jednog područja kontroliranom migracijom cijelog
Confluence sitea ili izvoza više područja. Postojeći postupak jednog područja
ostaje dostupan, sa sadašnjom pretvorbom sadržaja, ovlastima, privitcima i izvještajima.
Razvoj i prihvat provode se na HFC-u. Ne objavljivati release, nadograđivati demo
ni mijenjati apps-test dok korisnik ne odobri dovršeni rad.

## Napredak

- [x] **C1 Prepoznavanje arhive i pregled sadržaja** — implementirano i provjereno na HFC-u, 15. listopada 2026.
- [x] **C2 Migracija identiteta i pristupa** — korisnici/grupe/članstva implementirani i prihvaćeni; prijava i globalna administracija ostaju u Authu.
- [x] **C3 Uvoz odabranih područja** — implementirano i provjereno na HFC-u, 15. listopada 2026.
- [x] **C4 Migracija kalendara** — implementirano i provjereno na HFC-u, 15. listopada 2026.
- [x] **C5 Izvještaji i završni prihvat** — implementirano i lokalno provjereno, 15. listopada 2026.; objava je zaseban korak.
- [ ] **C6 Jedan pregledani uvoz sitea** — implementirano; stvarno izvršavanje koordinatora prošlo je. Otključani GUI pregled otkrio je greške vrste/vlasništva kalendara, palete i pretplate izvršitelja; vidi dopunu prihvata. Još nema izdanja.

## C6 Jedan pregledani uvoz sitea

### Aktualni prihvat — 16. listopada 2026.

#### Dopuna: kalendarski makroi i neaktivni identiteti

- [x] Sačuvati sve odabrane kalendare višekalendarskog makroa, podržane postavke prikaza, djelomično povezivanje i odvojena upozorenja za nedostajuće kalendare.
- [x] Odvojeno čitati aktivnost računa i imenika iz prenosivih metapodataka arhive; prikazati nepoznata stanja i ne davati prednost isključenom imeniku samo zbog aktivne oznake računa.
- [x] Zadano ne stvarati automatski nove račune neaktivnih izvora/povijesnih referenci, čuvati izričita mapiranja i ponuditi svjesnu izradu arhivskih računa ili administratorsko preuzimanje bez spajanja nepovezanih osobnih područja i dodjele izvornih ACL prava preko zamjenskog identiteta.
- [x] Provesti ciljane provjere kvalitete/regresije i HFC pregleda bez novog HFC uvoza; sačuvati deset odobrenih veza i ostale spremljene odabire.
- [x] Dopuniti odvojenu englesku/hrvatsku dokumentaciju i primjere; bez izvorno specifičnih korisničkih imena, naziva/ID-jeva imenika ili ID-jeva kalendara u pravilima aplikacije.

Mijenja se samo importer. Auth/framework kod i produkcijski wiki ostaju samo za
čitanje; za ovu dopunu nisu odobreni uvoz, commit, tag ni izdanje.

Implementirano kroz `ConfluenceCalendarMacro`, pretvarač/razrješivač,
`ConfluenceSourceActivity`, pregled/planer izvornih računa i izričito skrbništvo
po području. Privatni indeks identiteta v2 obnovljen je iz nepromijenjene arhive:
217 aktivnih identiteta, 108 neaktivnih računa, 44 računa ugašenog imenika i 73
isključivo povijesne reference. To su činjenice snimke izvoza, ne aktualizacija iz
produkcije. HFC pregled sada planira 181 novi neaktivni račun, 65 mapiranih referenci
i 196 preskakanja; ostala su dva sukoba izrade. Svih deset odobrenih parova/veza i
svi spremljeni nacrti/odabiri nepromijenjeni su, kao i zaštićeni Auth podaci,
kalendari, pretplate, sadržaj i trajna mapiranja. HFC uvoz nije pokrenut.

Prolaze PHPCS, Rector i PHPStan te **290 testova / 1.824 provjere** u izvornom i
provjerenom okruženju ovisnosti. Prolazi **pet izoliranih GUI scenarija**: zajednički
uvoz s više kalendara/djelomičnim povezivanjem i zasebnim izvještajima, straničeni
nacrti, dodavanje duplih osobnih područja, izričito skrbništvo neaktivnog vlasnika
i postojeći uvoz jednog područja s ACL-om/privatnim privicima. HFC GUI prikazuje
odvojene oznake aktivnosti, filtre neaktivnih/povijesnih/nepoznatih izvora i dodatni
odabir arhivskih računa. Prolaze provjere prijevoda, dvojezičnih komentara i odvojene
jezične dokumentacije. Osobni kalendar i dalje zahtijeva svjestan odabir vlasnika;
skrbništvo ne pretplaćuje uvozitelja niti mu dodjeljuje sve kalendare bivših korisnika.

#### Deset potvrđenih duplih parova spremljeno u nacrt

Korisnik je potvrdio deset parova s podudarnim punim imenom i identifikatorom.
Odabiri glavnog računa i veze drugog izvora spremljeni su kroz HFC GUI, bez primjene
identiteta ili pokretanja uvoza. Ostalo je deset skupina za pregled (sedam samo po
imenu i tri različitih imena/dijeljenog identifikatora), uključujući šest neriješenih
sukoba izrade izvornog računa. Nacrt sada planira 371 novi neaktivni glavni račun
i 65 mapiranih izvornih referenci, uključujući deset povezanih oznaka; šest referenci
ostaje preskočeno do pregleda. To su planirane radnje, ne novostvoreni korisnici.

Provjera prije/poslije bez upisa potvrđuje izmjenu točno dvadeset odobrenih unosa
nacrta. Svi ostali odabiri identiteta, područja, postavke postupka/kalendara, postojeći
Auth/korisnički/grupni retci, kalendari, pretplate, sadržaj područja i trajna mapiranja
uvoza ostali su nepromijenjeni. Preračunati prijedlozi kalendara imaju 101 nedovršen
odabir nakon razrješenja potvrđenih veza; nijedan kalendarski odabir nije ručno
odobren ni izmijenjen. Bez izmjena Auth/framework koda, HFC uvoza i izdanja.

#### Prijedlozi mapiranja bez upisa spremni za korisničku potvrdu

Slijedi početno stanje prijedloga prije gore navedene potvrde deset parova.

HFC posao ima privatnu stranicu prijedloga za vlasnika (`mapping_review=1`),
povezanu iz postavki identiteta/kalendara. Grupira 20 mogućih duplih skupova:
deset s podudarnim punim imenom/identifikatorom, sedam samo prema imenu i tri s
različitim imenima uz zajedničke identifikatore. Posljednji uključuju servisne/
dijeljene adrese; ništa se ne spaja automatski. Svih 26 sukoba izrade izvora još
čeka odluku. Tablica kalendara bez upisa obuhvaća svih 107 nedovršenih odabira:
65 prijedloga timskog, 39 osobnog i tri koja traže odluku o vrsti. Devet predloženih
osobnih kalendara nema pouzdanog traga vlasnika. Isto ime i login osobnog područja
izričito su slab trag; proturječni tragovi vlasnika stranice izostavljaju se.
Stranica ne mijenja spremljene odabire ni poslovne HFC retke. Ima zasebne pretrage/
paginacije i uređivačke poveznice na točan izvorni kalendar/filtrirani identitet.
Vidi zasebnu [uputu pregleda mapiranja](site-mapping-review_hr.md).

Prolaze PHPCS/Rector/PHPStan i **281 test / 1.769 provjera**; četiri izdvojena
preglednička scenarija importera, uključujući privatni pristup prijedlozima,
straničenje/pretragu, poveznice uređivanja točnog kalendara, očuvanje nacrta,
uvoz duplih osobnih izvora i nepromijenjeni uvoz jednog područja s ACL-om/privatnim
privitkom. Prolaze provjere odvojenih jezika dokumentacije, prijevoda i dvojezičnih
komentara. HFC ostaje u pripremljenom pregledu sa 352 odabrana područja, 361
planiranim novim neaktivnim računom i 26 sukoba na čekanju; otisci vjerodajnica/
SSO-a/admina i izvornog osobnog sadržaja ostaju isti, bez novih obavijesti/emaila.
Nema odobrenja mapiranja, novog HFC uvoza ni izdanja.

#### Korisnički zatražen test cijelog izvora — pripremljen, nije uvezen

Korisnik je proširio test na sve izvorne korisnike, kalendare i područja. Novi ZIP
prenesen je i provjeren kroz HFC GUI: u nacrtu su odabrana sva **352 područja /
44.594 aktualne stranice** i **266 kalendara**. Pravila identiteta odabiru 55
mapiranih referenci, 361 novi neaktivni račun, devet postojećih i 11 novih običnih
grupa, uz članstva. **26 sukoba izvornih računa još treba pregledati**; ne spajaju
se niti dupliciraju prešutno. Sedam osobnih područja trenutačno nema razriješenog
vlasnika. Duple izvorne reference sada se mogu izričito vezati uz isti postojeći
ili planirani glavni račun; nepostojeće/kružne veze odbijaju se prije upisa.
Dva osobna područja istog vlasnika podržana su uz dodavanje za svaki izvor,
jedno provjereno nativno osobno područje te zasebna mapiranja/izvještaje izvora.
Njihov HFC nacrt koristi dodavanje; postojeći sadržaj ostaje nepromijenjen.

Nacrt kalendara prihvaća 159 jednoznačnih prijedloga vlasnika (postojećih ili
planiranih izvornih računa); **64 osobna kalendara još nemaju izričitog vlasnika,
a 43 kalendara još nemaju određenu vrstu**. Nije korišteno pripisivanje kreatora
vlasniku ni automatska timska vrsta. Ranije pogrešno razvrstani testni ciljevi
također zahtijevaju izričito odobrenje čišćenja/ponovnog uvoza; postojeće vlasništvo
kalendara ne prepisuje se prešutno. Izvršavanje cijelog izvora **nije pokrenuto**.
Administratorov ispravan osobni kalendar i sedam aktivnih pretplata izvan su svakog
predloženog čišćenja.

Izvor nema binarnih privitaka. Ponovljeni su testovi zajedničkog site ZIP-a/indeksa
te odabira, registracije i izvještaja privitaka: prolazi **13 testova / 66 provjera**.
To potvrđuje očuvanje sintetičkih binarnih podataka, ne stvarni prijenos privitaka
cijelog sitea. C6 i objava ostaju otvoreni do odluka o mapiranjima i stvarnog GUI
izvršavanja. Bez izdanja te izmjena Auth i framework koda.

#### Otključani vizualni pregled i dorada kalendara

**Dopuna duplih/osobnih izvora i paginacije, 16. listopada:** samo importer dobiva
izričite veze izvornih računa, jednokratnu izradu glavnog računa, uklanjanje duplih
članstava i grupirane postavke osobnog dodavanja. Kasniji poduvoz sprema provjereni
cilj prije ulaska u nepromijenjeni red uvoza jednog područja. Stvarni izdvojeni GUI
primjer prolazi spajanje, autorstvo, poveznice među izvorima, preuzimanje privatnog
binarnog privitka i očuvanje pri ponavljanju. Priprema ovog pregleda ne pokreće HFC
uvoz. Filtar **Potrebna potvrda vrste ili vlasnika** prikazuje 107 neriješenih
redaka; njihove odabire još treba potvrditi korisnik.

Odabir područja, postavke odabranih područja i kalendari sada imaju zasebno listanje
25/50/100 redaka iznad/ispod, sažete brojeve s trotočkama, filtriranje cijelog popisa,
brojače odabira i odvojeni odabir trenutačne stranice/svih filtriranih. Polja ostalih
stranica ostaju u obrascu i spremaju se zajedno. Pregled ne mijenja Auth/framework,
vjerodajnice, članstva ni postojeće HFC pretplate na kalendare. Uvoz cijelog izvora
i objava još čekaju.

Provjereno za ovu dopunu: prolaze PHPCS/Rector/PHPStan importera i **273 testa /
1.739 provjera**. Pokretač potrošne aplikacije prolazi **četiri stvarna preglednička
scenarija**: zajedničke izvještaje sitea, paginaciju 131 područja/132 kalendara uz
očuvanje nacrta, dodavanje/ponavljanje duplih osobnih izvora i izvorni uvoz jednog
područja s ACL-om/privatnim binarnim privitkom. HFC pokazuje odabir 352/352 područja
i 266/266 kalendara uz 25 vidljivih redaka po odjeljku; listanje čuva nespremljene
nazive sadržaja. Prolaze provjere prijevoda, dvojezičnih komentara, odvojenih jezika
dokumentacije i razmaka. HFC otisci vjerodajnica/SSO-a/admina i izvorni osobni sadržaj
ostaju isti; nema novih obavijesti/emaila, uz **16 izvršenih / nula pending migracija**.
Paginacijski scenarij zasebno prolazi i nativnu provjeru praznog obaveznog polja na
skrivenoj stranici: neispravni redak se prikaže, bez slanja djelomičnog nacrta.
Njegov potrošni izvor uklonjen je uobičajenim odustajanjem kroz GUI.

**Dopuna pripreme/pretrage, 16. listopada:** priprema sada objavljuje privatne faze
i brojače vezane uz izvor pod istim zaključavanjem postupka. Endpoint čitanja dostupan
samo prijavljenom vlasniku omogućuje automatsko GUI praćenje i nakon osvježavanja
ili izgubljenog odgovora pripreme. Provjere ne pokreću ponovno čitanje niti rotiraju
CSRF tokene promjena. Nepoznati ukupni brojevi imaju neodređenu traku; samo raspodjela
događaja ima izmjereni postotak faze. Greška/prekid završava čekanje i omogućuje
izričito ponavljanje. Čišćenje upravljanog izvora uklanja i podatke o napretku.

Vlasnici kalendara koriste postojeći asinkroni birač s pretragom/paginacijom, ne cijeli
imenik ponovljen u svakom retku. Najviše 25 rezultata obuhvaća postojeće neaktivne
lokalne račune i odabrane buduće neaktivne izvorne račune. Postojeći korisnici traže
se postojećim Auth API-jem paginiranim u bazi, bez izmjene Autha. Lokalni vlasnik može
se izričito odabrati neovisno o mapiranju izvora; to ne stvara vezu identiteta niti
mijenja prijavu/admin postavke. Nedostajući/obrisani vlasnici provjeravaju se prije
upisa. Osobna vrsta više nije isključena zbog praznog popisa spremljenih mapiranja.
Regresijski test uključuje više od 10.000 postojećih računa.

Provjere nakon izmjena: **262 testa / 1.672 provjere**, PHPCS, Rector i PHPStan
prolaze na PHP-u 8.5. Prolaze oba izdvojena preglednička scenarija: zajednički uvoz
prati zaključanu pripremu i nakon osvježavanja, prikazuje izmjereni napredak raspodjele,
automatski otvara pregled te kroz stvarni Calendar API uvozi predloženog budućeg i
pretraženog postojećeg lokalnog vlasnika. Prolazi i nepromijenjeni scenarij privitaka/
ovlasti jednog područja. Na HFC-u nema isključenih osobnih opcija; **Učitaj još** učita
20 pa 40 postojećih korisnika, a pretraga izdvoji jedan rezultat. Endpoint napretka za
vlasnika vraća spreman pregled 266 kalendara. Nije izvršen novi stvarni HFC uvoz,
promjena vlasništva/prava ni izdanje.

**Dopuna pregleda vlasnika, 16. listopada:** `ConfluenceCalendarOwnerSuggestions.php`
sada daje stvarne prijedloge importera/GUI-a, ne samo razvojni popravak. Odvojeno se
prikazuju puno ime/login, isti ciljevi, nemapirani izvori i slabi tragovi osobnog
područja; nema pretpostavke vlasništva prema kreatoru. Ručno prihvaćanje popunjava
samo postavke, ne označava kalendar za uvoz, a skupno prihvaćanje čuva već odabrane
vlasnike. Dovršeni uvozi imaju pregled bez uređivanja. Pretraga ne uključuje
neodabrane opcije odabira vlasnika; filtri imaju vidljive brojače.

Stvarni izvor ima **226 kandidata za osobno vlasništvo**: 35 jednoznačno mapiranih,
125 nemapiranih, 11 dvosmislenih, 21 slab trag i 34 bez pouzdanog prijedloga.
Namjerno je uključeno još 12 prilagođenih kandidata/prepoznatih imena uz ranije
214 prepoznatih po nazivima/vezama osobnih područja. To su prijedlozi, ne tvrdnja
da svih 226 ima potvrđenog osobnog vlasnika. Korisnička potvrda još se čeka.
Nova arhiva prenesena je i provjerena kroz prijavljeni HFC GUI; novi pregledi
identiteta i kalendara spremni su. Prije potvrde nisu primijenjeni
novi sadržaj, vlasništvo ni prava kalendara. Sljedeći stvarni uvoz također treba
pokrenuti i nastaviti kroz GUI, ne CLI koordinator.

Ciljane provjere kvalitete prolaze na PHP-u 8.5 (252 testa / 1.592 provjere nakon
provjera osobnih kandidata), uz provjere dvojezičnosti/dokumentacije/prijevoda.
Izdvojeni preglednički test provjerava prihvaćanje prijedloga bez označavanja
kalendara za uvoz, ispravnu pretragu, zajednički uvoz i stabilne zasebne izvještaje.
Prolazi i uobičajeni preglednički scenarij uvoza jednog područja. Bez izdanja.

Ranije 43 provjere ispod dokazuju izvršavanje i očuvanje odabranog plana, ne
semantičku ispravnost svih odabranih vlasnika/vrsta kalendara. Vizualni pregled
našao je 70 kalendara nazvanih kao osobni službeni kalendari, a označenih timskima.
Kalendari različitih ljudi imaju istog Confluence `CREATOR` korisnika; to polje
nije dokaz osobnog vlasništva. Raniji broj od 64 mapirana kreatora ne smije se
prikazivati kao 64 potvrđena osobna vlasnika.

Importer sada traži izričit odabir osobnog vlasnika i nikada unaprijed ne bira
kreatora. Prepoznatljivi službeni/privatni/osobni nazivi predlažu vrstu Osobni, a
nejasni retci traže odabir vrste. Čuvaju se imenovane Confluence boje. Skupna
izrada isključuje automatsku pretplatu izvršitelja; ovlasti i pretplate ostaju
odvojene. Tri izvještaja sitea prikazuju metapodatke na normalnim karticama teme,
ne preko pozadine zaglavlja.

Bez ponovnog HFC uvoza javni Calendar API ispravio je 162 izvorne boje i uklonio
162 administratorske pretplate nastale uvozom. Šest ranijih pretplata i vlastiti
uvezeni osobni kalendar ostali su. Provjereno su nepromijenjeni događaji, semantičke
ovlasti, vlasnici/vrste, Auth/SSO i pretplate drugih korisnika; nema novih obavijesti
ni pošte. Dvosmisleni osobni kalendari namjerno nisu dodijeljeni drugom vlasniku,
niti su stari izvještaji prepisani kao da je početni odabir bio ispravan.

- [x] Pregledati stvarni zajednički, korisnički i kalendarski izvještaj na otključanom Macu.
- [x] Potvrditi da Moji kalendari koriste pretplate, a administracija zadržava pristup ostalima.
- [x] Ispraviti budući uvoz palete, pretplate izvršitelja i izričitu provjeru osobnog vlasnika.
- [ ] Potvrditi stvarne osobne vlasnike/vrste prije ispravka preostalih HFC testnih metapodataka.
- [ ] Ponoviti završnu provjeru cijelog kandidata/kontejnera nakon ovih dodatnih izmjena.

Slijede povijesni dokazi izvršavanja; brojke opisuju dovršeni početni odabir,
ne novi odobreni plan vlasnika/vrsta.

Stvarni HFC GUI prenio je novu arhivu i prihvatio zajednički pregled. Zaključani
Mac blokirao je nativnu završnu potvrdu. Prema korisničkoj uputi za samostalan
nastavak isti zaključani odabir dovršen je uobičajenim aplikacijskim CLI
koordinatorom, s pravim URL kontekstom `/hfc` prije obrade sadržaja. To nije uspjeh
cjelovitog GUI pokretanja. Dovršeni uvoz ne duplicirati samo radi vizualnog prihvata.

Stvarni rezultati: 55 mapiranih identiteta, bez novih korisnika, osam novih običnih
grupa, jedna mapirana grupa, 105 članstava, 346 stranica u 30 osobnih područja
(29 novih i jedan postojeći cilj uz dodavanje), 41 osobni i 122 timska kalendara,
32.755 uvezenih i 131 izričito preskočeni događaj. Sva 32 izvještaja su dovršena.
Prolaze sve 43 provjere bez upisa: početni ID-jevi, mapiranja autora/zadnjih
urednika, čitljivi datotečni dokumenti područja, mapirani osobni vlasnici,
ograničeni pristup kalendarima i nepromijenjeni dugi naslovi/lokacija. Auth/SSO/admin
stanje te dva stara čvora/dokumenti/početna stranica/ACL osobnog cilja ostali su
jednaki. Nema novih obavijesti ni pošte. Točni ID-jevi, sažeci i UUID-jevi ostaju privatni.

Izvještaji zadržavaju 197 razriješenih i 656 nerazriješenih poveznica, 83 stavke
pregleda i pet stavki vanjskih skripti koje čekaju odobrenje. Neodabrani/nemapirani
ciljevi ne stvaraju se potajno; 679 izvornih članstava ostaje izvan potvrđenog
mapiranog opsega. Arhiva nema binarnih privitaka, a odabrane stranice nemaju
ugradnji kalendara; te putove pokrivaju izdvojeni samostalni/servisni fixturei, ne
ovaj stvarni odabir izvora. Jedna stara osobna referenca dokumenta nije bila
čitljiva ni prije uvoza i njezini su sažeci nepromijenjeni; završni vizualni pregled
još slijedi. Bez izdanja, pusha te izmjena Autha/frameworka.

### Greška duljine naslova i čisto ponavljanje

Prvi stvarni GUI pokušaj stao je na starom Calendar stupcu `title VARCHAR(255)`.
Izvor ima dva duža naslova (najviše 706 znakova) i lokaciju od 468 znakova.
Korisnik je odobrio granice naslova/lokacije 765/510 znakova. Calendar upravlja
novom shemom i migracijom bez gubitka podataka; Simbioza ima poziv iz updatera.
API/ICS i pregled izvora odbijaju/prijavljuju višak bez rezanja. Vidljiva pristupačna
traka napretka trenutačnog koraka ima zaseban postotak te neodređeni prikaz pripreme/
povezivanja.

Prije brisanja potvrđeno je vlasništvo pokušaja: uklonjeno je samo njegovih osam
novih grupa, 105 članstava, 22 kalendara, 4.025 događaja, 27 tipova događaja i novih
mapiranja importera. Početni ID-jevi/sažeci ostali su jednaki. Neuspjeli izvještaji
i izvorni ZIP-ovi su sačuvani; neuspjeli roditelj nije preimenovan u uspješan.
Novo ponavljanje dovršeno je bez greške uvoza.

### Implementirani koordinator i granice očuvanja

`ConfluenceSiteWorkflow.php` koordinira zaključani odabir;
`ConfluenceSiteIdentityPlan.php` provjerava planirane korisnike bez izmišljenih
ID-jeva. `views/settings/site-workflow.php` i sekcijske forme spremaju nacrt prije
jednog završnog pregleda/potvrde. Neovisne HTTP ulazne točke komponenti ne mogu
zaobići zaključani postupak.

Odvojeni izvještaji korisnika/grupa/članstava, kalendara i svakog područja povezani
su iz roditelja. Aktualna brojanja spremljenih poveznica osvježavaju stare
izvještaje bez zamjene UUID-a. Povezivanje kalendara obrađuje ranije dovršene
izvještaje istog vlasnika/izvora. Reference potvrđenog izvora povezuju miješane
samostalne/site uvoze; tuđi/nepoznati izvori i odvojene kopije ostaju odvojeni.

Izričito osobno dodavanje čuva postavke područja, ACL, početnu stranicu i postojeće
stranice. Ponavljanje koristi izvorna mapiranja bez uređivanja starih dokumenata,
dupliciranja privitaka/komentara ili zamjene područja pri oporavku. Istoimene
nativne stranice ostaju netaknute; nove dobivaju slobodne slugove. Fixturei stvarnih
servisa prolaze za oba HTML spremišta, uz kasniju novu djecu/poveznice.
Stvarni postojeći osobni HFC cilj i njegovi izvorni sažeci ostali su očuvani.

### Provjere i preostalo

Aktualni importerov `composer on-commit` prolazi na PHP-u 8.2–8.5: **242 testa /
1.509 provjera**, PHPCS, Rector i PHPStan. Puni aplikacijski wrapper nakon izmjena
naslova/napretka prolazi **81/81 browser/API/performance testova u 23,2 minute**,
uz nepromijenjeni samostalni ACL/privitke/pretragu. Ranije greške ostaju zapisane:
izvorni kontekst ugrađene pretrage i utrka testne navigacije pri čišćenju popravljeni
su bez slabljenja izolacije ili isključivanja provjera.

Sigurna FPM GUI nadogradnja prošla je uz očuvanje konfiguracije/sadržaja. Aktualna
Docker PostgreSQL provjera završava sa 16 migracija bez čekanja, nepromjenjivim kodom
i očuvanim trajnim stanjem. Potpuni dokazi izdanja su u odvojenim aplikacijskim
dokumentima `docs/release-preflight-0.3.3_en.md` / `_hr.md`.

- [x] Svi ekrani sitea služe podešavanju/pregledu prije završne potvrde.
- [x] Zaključati i provjeriti identitete, članstva, kalendare, vlasnike i područja; odbiti zastarjele/istodobne promjene.
- [x] Pod jednim roditeljem nastavivim idempotentnim koracima izvršiti identitete, kalendare, područja i povezivanje.
- [x] Omogućiti izričit izbor osobni/timski uz izričito odabranog postojećeg lokalnog ili mapiranog/planiranog izvornog vlasnika osobnih kalendara.
- [x] Koristiti pretvorbu, autore, ACL, privitke i dvosmjerne poveznice samostalnog importa.
- [x] Povezati podržane ugradnje kalendara; prijaviti nepodržane reference i neodobrene skripte.
- [x] Prikazati vidljiv zajednički napredak i stabilne zasebne izvještaje; iz backupa izostaviti privremene odabire.
- [x] Provjeriti čist pregled, projekcije neaktivnih korisnika, zastarjele preglede, istodobnost/nastavak, očuvanje dodavanjem i oba HTML spremišta.
- [x] Dovršiti stvarni HFC koordinator te provjere izvještaja/pristupa/obavijesti; osvježiti odvojene EN/HR upute.
- [x] Dovršiti vizualni HFC prihvat bez upisa na otključanom Macu i bez dupliciranja uvoza; nativnu potvrdu provjeriti izdvojenim pregledničkim testom.
- [ ] Dovršiti gore opisanu semantičku provjeru vlasnika/vrsta kalendara prije odobrenja izdanja.

C6 ostaje otvoren dok se ta interaktivna provjera stvarno ne potvrdi.
Objava ostaje zasebna radnja koju odobrava korisnik.

## Izvorne arhive

Izvoz jednog DABAR područja sadrži 253 aktualne stranice i 393 datoteke privitaka,
uključujući druge verzije i obrisane zapise. Site export označen je s
`exportType=site` i `backupAttachments=false`.

Pregledana snimka sitea sadrži 352 područja (206 općih i 146 osobnih), 44.594
aktualne stranice, 369 korisnika imenika, 442 Confluence reference identiteta,
20 zapisa grupa imenika s 19 različitih naziva i 784 članstva. Račune i reference
autora treba povezati, a ne zbrojiti kao različite korisnike.

Paket Active Objects sadrži 266 izvornih kalendara, 102.636 zapisa događaja,
pretplate, tipove događaja i ograničenja pristupa. Njegova 5.752 zapisa podkalendara
nisu 5.752 različita kalendara. Većina zapisa dodataka tehnička je povijest, a ne
sadržaj za migraciju. ZIP ima oko 3,76 GB, a raspakirani zapisi ukupno oko 33,9 GB;
ne raspakiravati cijeli izvoz samo radi pregleda.

## C1 Prepoznavanje arhive i pregled sadržaja

- [x] Prepoznati jedno područje, više područja i site iz descriptora i provjerenog
  sadržaja, ne naziva datoteke. Site s jednim područjem i dalje je site.
- [x] Zadržati postojeći scanner, mapiranja i potvrđeni uvoz jednog područja.
- [x] Izraditi pregled s ograničenom memorijom i privatni indeks na disku za site
  ili više područja. U pregled i sažetak posla ne spremati tijela dokumenata,
  lozinke, tokene ni potpune zapise dodataka.
- [x] Prikazati identifikatore, nazive, vrste i količine sadržaja po području;
  administrator smije spremiti odabir bez stvaranja korisnika, grupa ili sadržaja.
- [x] Izostavljene binarne privitke razlikovati od oštećene arhive.
- [x] Limite velikog sitea podešavati u
  `HPH_CONFIG_PATH/confluence-import/confluence-import.php`; zadržati zaštite od
  prolaska kroz putanju, symlinkova, omjera kompresije, DTD-a i vanjskih entiteta.
- [x] Privremene indekse držati u privatnim podacima aplikacije. Velike testne
  podatke smjestiti na izričito odobreni Seagate; ne brisati izvorne ZIP arhive.
- [x] Odbiti potvrđeni uvoz više područja/sitea dok C2–C4 ne implementiraju te
  upise; C1 nikada ne smije prešutno uvesti samo prvo područje.
- [x] Testirati prepoznavanje, ponovno korištenje indeksa, provjeru odabira,
  neispravne arhive, limite i nepromijenjeni uvoz jednog područja. Provjeriti
  stvarnu snimku sitea i HFC GUI. Zapisati kreće li prekinuti pregled ispočetka
  ili se nastavlja.

## C2 Migracija identiteta i pristupa

- [x] C2a Tokom pročitati i provjeriti privatni pregled identiteta/ovlasti; spojiti samo jednoznačne reference autora.
- [x] C2b Pregledati neovisan odabir korisnika/grupa/članstava i identitet izvornog sitea prije upisa.
- [x] C2c Transakcijski primijeniti potvrđeni odabir i zadržati trajna mapiranja/izvještaje bez izmjene Auth koda.
- [x] C2d Provjeriti ponavljanje, postojeća članstva, izostavljanje tajni, backup i stvarni HFC GUI.

Auth i framework ostaju nepromijenjeni. Koristiti postojeće servise korisnika/grupa;
integracija članstava u importeru mora sačuvati nepovezana ručna, automatska i
izuzeta članstva. Ne prepisivati HFC SSO profil niti postojeću politiku prijave.

Uvesti identitet izvornog sitea i zajednička mapiranja svih odabranih područja.
Razlikovati Confluence korisničke ključeve, ID-eve korisnika i ID-eve grupa imenika.
Izričito razriješiti istoimene grupe različitih imenika. Postojeće korisnike
koristiti tek nakon nedvosmislenog mapiranja, uz očuvanje njihovih postavki.

Omogućiti neovisan odabir korisnika, grupa i članstava. Odabrane nemapirane
korisnike stvarati kao neaktivne račune bez prijave.
Koristiti postojeće Auth servise računa/grupa i adapter dodavanja članstava
importera, bez zamjene nepovezanih članstava ili zaključivanja iz ovlasti sadržaja.
Vlasništvo osobnih područja i ograničenja sadržaja ostaju zadaci C3.

### Prihvaćena granica prijave i administracije

`confluence-administrators` obična je uvezena grupa. Globalne administratorske
ovlasti dodjeljuju se izričito kroz postojeću Auth administraciju. Lokalna/SSO
prijava podešava se u postavkama korisnika ili globalno u Authu. Ne prenositi
hashove lozinki, konfiguracije providera, tokene ni sesije i ne dodavati takve
pretvorbe ili radnje aktivacije u importer. To je prihvaćeni opseg C2, ne odgođeni
posao C2. Auth kod i operativni HFC provideri ostaju nepromijenjeni.

## C3 Uvoz odabranih područja

- [x] C3a Pripremiti ograničeni privatni zajednički indeks sadržaja odabranih područja.
- [x] C3b Uskladiti glavne/podređene poslove vezane uz vlasnika, prostore izvora i sigurno čišćenje zajedničke arhive.
- [x] C3c Ponovno koristiti pretvorbu stranica/povijesti/privitaka i ACL uz C2 mapiranja; zahtijevati vlasnike osobnih područja.
- [x] C3d Pregledati zamjenu/kopiju i uskladiti poveznice područja; prikazati prekid/nastavak/ponovni pokušaj.
- [x] C3e Provjeriti izolirane primjere, stvarne HFC arhive i nepromijenjeni import jednog područja; dokumentirati stvarna ograničenja.

Koristiti jedan glavni posao sitea i zasebne nastavive poslove odabranih područja.
Ponovno koristiti sadašnje pretvarače i pravila stranica/povijesti/privitaka/ACL-a
uz indeksirane podatke ograničene na područje. ID-eve i poveznice između područja
vezati uz ispravan izvor. Ne skenirati ni raspakiravati cijeli ZIP ponovno za
svaki podposao i ne ukloniti zajednički ZIP nakon dovršetka prvog područja.

Zadano obrađivati aktualni sadržaj, a povijest/nacrte/obrisane zapise po izričitom
odabiru. Osobna područja zahtijevaju mapiranje vlasnika. Za postojeće ciljeve
tražiti pregledanu odluku zamjena/kopija. Ne prepisivati nepovezana područja ili
identitete. Pozadinsku/CLI obradu i prikaz napretka prilagoditi dugim migracijama;
prije uključivanja opisati prekid, nastavak, ponovni pokušaj i odustajanje.

## C4 Migracija kalendara

- [x] C4a Ograničeni Active Objects čitač i privatni indeks kalendara/događaja; provjera stvarne site arhive.
- [x] C4b Pregled odabira kalendara, potvrđenih C2 identiteta, vlasnika/ovlasti i nepodržanih zapisa.
- [x] C4c Nastavivi, idempotentni uvoz kroz Calendar API bez pozivnica, podsjetnika i vanjskih zahtjeva.
- [x] C4d Povezivanje kalendara na uvezenim stranicama, trajni izvještaji i prenosiva mapiranja.
- [x] C4e Ciljani/integracijski testovi, odvojena EN/HR dokumentacija i provjera na HFC-u; bez releasea.

Čitati podržane Team Calendars tablice iz paketa Active Objects, odabrati izvorne
kalendare te razlikovati pretplate i podkalendare tipova događaja. Vlasnike,
izvorne ID-eve, tipove, ovlasti i ugradnju u stranice mapirati kroz Calendar API.
Očuvati podržanu semantiku vremenske zone/cjelodnevnih događaja, UID-eva,
ponavljanja i iznimaka. Prijaviti nepodržane izvore i reference na nepostojeća
područja. Tijekom migracije ne slati povijesne pozivnice, podsjetnike ni vanjske
zahtjeve. Ponovno pokretanje ne smije duplicirati kalendare ni događaje.

## C5 Izvještaji i završni prihvat

- [x] C5a Zajednički aktualni/trajni izvještaj identiteta, područja i kalendara uz ograničene upite.
- [x] C5b Izričito sigurno čišćenje izvora, prenosivi izvještaji i testovi oporavka.
- [x] C5c Čista instalacija lokalnih izvora; SQLite/PostgreSQL/MySQL/MariaDB i oba načina spremanja sadržaja.
- [x] C5d Stvarni mod_php, obični/sigurni FPM te regresije područja/sitea/privitaka/skripti.
- [x] C5e Provjera stvarnog HFC-a, odvojena EN/HR dokumentacija i zapis granica prihvata; bez releasea.

Objediniti napredak glavnog posla i podposlova te trajne izvještaje. Nakon
dovršetka odabranog posla uskladiti poveznice između područja i kalendare.
Zadržati registar odobrenih skripti i pregled nepoznatih skripti umjesto
izvršavanja uvezenog koda. Podatke zadataka/bočnog izbornika/predložaka koristiti
samo uz namjernu pretvorbu; ne uvoziti audit/analitičku povijest, programske
dodatke ili proizvoljne AO tablice.

U backup uključiti trajna mapiranja i izvještaje, ali ne upload arhive, indekse
i privremene podatke. Dokumentirati odustajanje i čišćenje. Provjeriti SQLite,
PostgreSQL i MySQL/MariaDB, sadržaj na filesystemu i u bazi, sigurni FPM i mod_php,
regresije jednog područja i site uvoz s privitcima i bez njih. Framework ostaje
nepromijenjen. Release tek nakon korisnikova pregleda HFC-a i odobrenja.

## Dovršeni rad

### C1 dovršen 15. listopada 2026.

Implementacija:
- Novi čitači metapodataka: `ConfluenceXmlInventoryFramer.php`,
  `ConfluenceInventoryReader.php` i `ConfluenceSiteInventory.php`.
- Prepoznavanje arhive, posebni limiti i DI nalaze se u
  `ConfluenceArchive.php`, `ConfluenceImportConfig.php`,
  `ConfluenceExportScanner.php` i `config/services.php`.
- Spremanje posla, provjera odabira, odustajanje i čišćenje su u
  `ConfluenceImportRepository.php` i `ConfluenceImportUploadService.php`.
  Sva tri ulaza za potvrđeni import odbijaju posao koji je samo pregled.
- Administratorska ruta/kontroler i `views/settings/site-inventory.php`
  prikazuju pregled, pretražuju ga i spremaju izvorne ID-eve. EN/HR prijevodi i
  [vodič pregleda](site-inventory_hr.md) opisuju trenutačno ponašanje.
- Ažuriran je stvarni modul koji HFC učitava, ne samo izvorni repozitorij.
  Nisu bile potrebne izmjene frameworka, Auth postavki, sheme baze, demoa ni apps-testa.

Provjere:
- Prošla su 132 PHPUnit testa / 829 tvrdnji, također s autoloaderom ovisnosti
  instaliranih na HFC-u. Prošli su PHPCS, Rector dry-run, PHPStan i `git diff --check`.
- Postojeći aplikacijski E2E `confluence-import.spec.js` prošao je na izoliranom
  SQLiteu, uključujući sadržaj jednog područja, ACL i privatne privitke.
- Stvarni ZIP sitea daje provjerenih 352 područja / 44.594 aktualne stranice i
  ponovno upotrebljiv privatni indeks bez raspakiravanja XML-a. Vršna PHP
  memorija samostalnog čitača bila je 8 MiB, a integrirane HFC pripreme 24 MiB.
  Izvorni DABAR čitač zadržao je 253 aktualne stranice, 393 zapisa privitaka
  i 332 logička aktualna privitka.
- Na HFC-u prošli su pretraga, odabir prikazanih redaka, spremanje i vraćanje
  odabira nakon osvježavanja. Izravni pozivi `queue()`, `queueBatch()` i `import()`
  odbili su pregled sitea prije upisa. Hash Auth konfiguracije i brojevi korisnika,
  grupa, članstava, područja, čvorova, dokumenata i privitaka ostali su isti.
  Izravni HTTP pristup privatnom indeksu vratio je 404.

Ograničenja i nastavak: početni pregled nakon prekida kreće ispočetka; ponovno se
koristi samo potpun indeks. C1 ne pretvara Active Objects kalendare i ne upisuje
sadržaj sitea. Minimalni PHP ostaje 8.2; lokalne provjere izvedene su na PHP-u
8.5.11, ne na novoj CI matrici 8.2–8.5. Ovo je neobjavljena razvojna izmjena HFC-a.
Naknadne implementacije C2–C4 i provjere zapisane su ispod; C5 ostaje otvoren.

## Zapis osnovne implementacije C2 — 15. listopada 2026.

- `ConfluenceSitePrincipals.php` stvara ograničeni privatni pregled metapodataka;
  `ConfluenceSiteAuth.php` povezuje postojeće Auth servise računa/grupa i dodaje
  članstva bez izmjene Autha. `ConfluenceSiteAccessService.php` provjerava izvorni
  site, vlasnika pregleda, snimku ciljeva i transakcijsku primjenu bez dupliranja.
  Repozitorij potvrđena mapiranja čita skupno.
- `ConfluenceImportController.php`, manifest i `config/services.php` izlažu
  autentificiranu pripremu/pregled/primjenu/pretragu. `views/settings/site-access.php`
  dijeli korisnike po stranicama, čuva spremljene odabire, navodi svaku pregledanu
  radnju i isključuje primjenu nakon izmjena. Zaseban prikaz izvještava o dovršenom
  koraku identiteta.
- Čišćenje uploada uključuje `.principals.json`; odabir područja čuva odabire
  identiteta, ali poništava neprimijenjeni pregled. Odustajanje ne poništava već
  primijenjene identitete niti briše njihov zasebni dovršeni izvještaj.
- [Vodič identiteta](site-identities_hr.md), README, pregled, instalacija,
  developerske upute i rješavanje problema opisuju aktualno ponašanje u odvojenim
  EN/HR datotekama. Nema nove migracije niti izmjene Autha/providera/frameworka.

Provjere: ciljani testovi pokrivaju stvaranje/mapiranje/preskakanje, neaktivne ciljeve,
prostore imena, nepromjenjiva mapiranja, dodavanje uz ručna/automatska/izuzeta
članstva, poništavanje nakon greške, zastarjele ciljeve, vlasnika, izostavljanje
tajni i prenosive strane ključeve backupa. Postojeći browser/API test jednog
područja prošao je na izoliranom SQLiteu. Stvarni HFC pregled ima 442 usklađena
odabira identiteta, 20 grupa imenika, 784 članstva i 35.924 zapisa ovlasti. Početno
čitanje trajalo je 106 sekundi, koristilo oko 34 MiB i stvorilo privatni indeks
od 6,1 MiB bez raspakiravanja XML-a. Čitanje spremljenog HFC pregleda koristi šest
upita, ne jedan za svakog izvornog korisnika. Browser pretraga/mapiranje/pregled
provjereni su bez primjene stvarnih računa. Hashovi Auth redova/konfiguracije i
brojanja sadržaja ostali su isti; migracija je 14 izvršenih i nula na čekanju.
Završno je prošlo 155 PHPUnit testova / 933 provjere uz HFC ovisnosti, PHPCS,
Rector bez izmjena, PHPStan, `git diff --check`, pokrivenost prijevoda, parovi
dokumentacije i ciljani audit komentara EN prije HR. Engleski i hrvatski HFC GUI
provjereni su bez grešaka u konzoli.

Importer nije tagiran niti objavljen. C2 prihvaćen je s granicom Autha navedenom
iznad. Slijedi C3 uvoz sadržaja odabranih područja; ne tvrditi da migracija
sadržaja/ACL-a/kalendara već radi.

### Dorada sučelja i automatskog planiranja C2 — 15. listopada 2026.

- `ConfluenceSiteUserPagination.php` i `site-user-pagination.php` daju stabilan
  abecedni redoslijed, 25/50/100 redaka, raspon rezultata i prozor od dva susjeda
  sa svake strane uz prvu/prethodnu/sljedeću/zadnju oznaku i preskoke. Prozor
  ostaje ograničen za 5.000 korisnika i prelama se unutar prikaza širokog 320 piksela.
- Statusni filtri obuhvaćaju mapirane, nemapirane, predviđene za izradu, automatska
  povezivanja i sukobe. Filtriranje prethodi paginaciji i ne ograničava skupne radnje.
- `ConfluenceSiteUserPlanner.php` jednom indeksira ciljne logine i email aliase.
  Predlaže samo jednoznačna točna povezivanja, prijavljuje sukobe izvora/cilja i
  po želji planira neaktivne račune za cijeli pregled. Ručni odabiri (i preskakanje)
  imaju prednost; red se može vratiti na automatska pravila. Auth i framework nisu
  mijenjani. Samo izričita Primjena upisuje račune.
- `ConfluenceSiteAccessService.php`, kontroler i prikazi čuvaju razlog odabira u
  pregledu/izvještaju, odluke skrivenih stranica i nespremljene odabire nakon greške.
  Izbornik koristi nativni gornji sloj kako ga fiksno zaglavlje ne bi prekrivalo,
  prikazuje odabrani račun/kvačicu i šalje change za tipkovnicu i miš. Promjena
  pravila na istom URL-u izričito ponovno učitava prikaz.
- Provjere: prolazi 178 PHPUnit testova / 1.179 tvrdnji, također uz HFC ovisnosti;
  prolaze PHPCS, Rector bez izmjena, PHPStan, audit prijevoda/dokumentacije,
  ciljana provjera komentara EN prije HR i `git diff --check`. Izolirani postojeći
  browser/API scenarij jednog područja prolazi uz ACL i privatne datoteke.
  HFC provjere obuhvaćaju filtre, pregled cijelog izvora, odabir/ponovno otvaranje,
  tipkovnicu/miš, grešku spremanja bez navigacije, prvu/srednju/zadnju stranicu i
  prikaz širok 320 piksela. Hashovi Auth redova/konfiguracije i brojanja sadržaja
  ostali su isti. Probna skupna izrada ponovno je isključena; stvarna Primjena
  računa nije pokretana.

Ove korisnički zatražene dopune C2 prethodile su implementaciji C3 opisanoj ispod.
Release nije objavljen.

### C2 automatska pravila grupa — 15. listopada 2026.

`ConfluenceSiteGroupPlanner.php` jednom indeksira točne normalizirane nazive ciljeva.
Skupno mapiranje i opcionalna izrada nepostojećih grupa vrijede za cijeli katalog;
ručni odabiri mapiranja/izrade/preskakanja imaju prednost i mogu se vratiti na automatiku.
Duplikati iz različitih imenika koriste nazive s ID-em imenika. Nejednoznačni,
sistemski, isključeni ili nevaljani ciljevi traže ručnu provjeru. Primjena je izričita.
Ciljani testovi i stvarni HFC pregled prolaze. Probna skupna izrada ponovno je
isključena, a izvornih devet ručnih odabira sačuvano. Tijekom provjere nije pokrenuta
stvarna Primjena identiteta; Auth izvor ostao je nepromijenjen.

### C3 implementacija i provjera — 15. listopada 2026.

- `ConfluenceSiteContentIndex.php` koristi dva zajednička ZIP prolaza i podjele
  odabranih područja/verzija. Filtrira nepovezane zapise prije DOM obrade, ograničava
  resurse, provjerava izvor/pravila cachea i ne raspakirava cijeli XML. Dohvat vlasništva
  preskače velike neprozirne CDATA sadržaje uz poštovanje komentara; odabrani sadržaj
  koristi izvorni XML. Osjetljivi XML argumenti skrivaju se u tragovima iznimaka.
- `ConfluenceSiteContentService.php`, kontroler, DI, manifest i C3 prikazi upravljaju
  zasebnim pregledom/potvrdom, roditeljem/podposlovima, izvorima, aliasima kopija,
  napretkom i oporavkom. Postojeća obrada pregleda/pretvorbe/povijesti/privitaka/ACL-a
  koristi potvrđena C2 mapiranja. Zajednički izvori ostaju dostupni za C4.
- Trajno brisanje područja čuva UUID podposla i zajednički ZIP, a briše samo ciljana
  mapiranja/staging. Ponavljanje obnavlja samo vlastiti parcijalni neuspjeli cilj,
  ne dovršene susjede. Backup uklanja lokalne izvršne opcije, ne trajno porijeklo.
- Osobni import vraća stvarni Workspace red umjesto omotnice mapiranja. Simbioza
  Userov `ensureForUser()` dopušta neaktivnog vlasnika samo uz izričitu neautomatsku
  pripremu drugog aktivnog administratora, bez aktivacije računa ili promjena
  vjerodajnica/providera. Auth/framework ostali su nepromijenjeni.
- [C3 uputa](site-content_hr.md) i zasebna engleska uputa opisuju privitke, povijest,
  izvorne adrese, ograničenja vlasnika, oporavak/odustajanje i granice C4/C5.

Dosad provjereno: PHPCS/Rector/PHPStan importera i 205 testova / 1.299 provjera prije
dodanog testa velike CDATA-e; postojeći browser/API scenarij jednog područja s ACL-om
i privatnim datotekama (1 prolazi, 58,6 s). Potpuna izolirana integracija s HFC
ovisnostima provjerila je dva područja s privicima/bez njih, povijest, dvosmjerne
poveznice, susjedne kopije, oporavak parcijalne kopije, mapirani grupni ACL i neaktivnog
mapiranog vlasnika osobnog područja. Na HFC-u su provjereni C3 pregled, blokada
zastarjele potvrde i EN/HR prikaz, bez pokretanja stvarnog uvoza sadržaja. Auth
hashovi/redovi i brojanja HFC sadržaja ostali su isti. Uklonjeni su privremeni probni
zapisi, ne izvorne arhive.

Završna provjera velikog izvora prošla je nakon optimizacije CDATA dohvaćanja vlasništva:
odabrani DABAR iz stvarnog XML-a veličine 28,6 GB, bez raspakiravanja, trajao je
261,33 sekunde, dosegao 466,02 MiB memorije i zapisao 7,3 MiB privatnih podjela.
Očuvane su 253 aktualne stranice, 393 zapisa privitaka i 332 logička aktualna
privitka, jednako izvozu jednog područja. Ponovno čitanje provjerenog cachea trajalo
je 0,29 sekundi. Raniji timeouti nisu prihvaćeni kao uspješni indeksi. Izvorne arhive
ostale su netaknute.

Završne provjere importera: 206 testova / 1.301 provjera, PHPCS, Rector dry-run i PHPStan
prolaze. Simbioza User: 23 testa / 223 provjere te analiza koda/stila prolaze. Posljednji
browser/API scenarij jednog područja prošao je za 56,9 sekundi. Potpuna izolirana
integracija dodatno provjerava izvorno uključivanje sadržaja drugog područja (i kopije),
grupni ACL, pripremu neaktivnog vlasnika i potvrđenu zamjenu osobnog područja. Odvojene
EN/HR upute i HFC prijevodi usklađeni su. Tijekom testova nisu uvoženi ni zamjenjivani
stvarni HFC poslovni podaci. Nije bilo releasea, taga ni pusha.
Naknadna implementacija C4 opisana je ispod; potpuna provjera više baza i načina
instalacije ostaje izričito u C5.

### C4 implementacija i provjera — 15. listopada 2026.

- `ConfluenceTeamCalendarReader.php` tokom čita samo dopuštene Team Calendars
  tablice iz AO zapisa u ZIP-u, odbija DTD/entitete i ograničava retke/vrijednosti.
  `ConfluenceSiteCalendarIndex.php` stvara privatni pregled s provjerom izvora i
  podjele događaja po izvornom kalendaru. Razrješava interne pretplate i zamjenske
  ID-eve tipova bez dohvaćanja vanjskih kalendara ili raspakiravanja cijelog XML-a.
- `ConfluenceTeamCalendarConverter.php` čuva podržane datume/zone/cjelodnevne
  događaje, UID-jeve, ponavljanja, EXDATE i premještene instance. Nepodržane ili
  dvosmislene serije prijavljuje, ne pretvara ih tiho u pojedinačne događaje. ICS
  sadržaj ne može umetnuti svojstva, izvršavati HTML ni slati povijesne obavijesti.
- `ConfluenceSiteCalendarService.php`, kontroler/DI/rute i novi prikazi kalendara/
  izvještaja daju neovisan odabir, vremenski ograničen pregled vezan uz vlasnika,
  izričitu potvrdu, pakete do 250 događaja, ponavljanje/nastavak/zaustavljanje i
  povezivanje stranica. Vlasništvo/ACL koriste samo potvrđena C2 mapiranja korisnika
  i grupa. Nepoznati identiteti ili neprenosivi ACL podkalendara ne otvaraju široki
  pristup. Ponovni uvoz čuva mapirane ciljeve i ručne izmjene događaja/ovlasti.
- Idempotentna shema importera dodaje `simbioza_confluence_import_calendars`;
  backup uključuje trajne izvorne/ciljne veze i dovršene izvještaje s mapiranjem
  stranih ID-jeva, ne privatne indekse, ZIP putanje ili tokene pregleda/izvršavanja.
  C4 i C2/C3 upisi za isti izvorni posao ne mogu raditi istodobno.
- Javni Calendar skupni API sada čuva kanonski `TZID`, prihvaća opcionalni
  `event_type_name` bez upita za tip svakog događaja i koristi transakciju pozivatelja.
  UTC završeci ponavljanja prilagođavaju se nativnom računanju uz očuvani izvorni
  ICS. Auth i framework nisu mijenjani. Ove Calendar dorade nisu objavljene i treba
  ih uključiti u C5 provjeru ovisnosti/releasea.
- Odvojene [engleske](site-calendars_en.md) i [hrvatske](site-calendars_hr.md)
  upute opisuju postupak, ograničenja, oporavak, spremanje i backup.

Provjere:
- Importer: prolaze PHPCS, Rector bez izmjena, PHPStan i **217 testova / 1.370
  tvrdnji**. Calendar: iste analize i **88 testova / 702 tvrdnje**. Prolaze ciljani
  auditi dvojezičnih komentara, prijevoda i odvojene dokumentacije.
- Integracija u potrošnoj punoj aplikaciji provjerava stvarni Calendar API,
  mapiranog vlasnika/grupni ACL, tipove, zone/ponavljanja/premještene instance,
  ponavljanje bez duplikata, poništavanje transakcije, C3 reference i sigurno
  ponovno povezivanje stranica. Prolazi postojeći browser/API scenarij jednog
  područja. Ponovljena C3 integracija prolazi privitke/izostale binarne datoteke,
  povijest, poveznice/uključivanja područja, kopije, oporavak i osobne vlasnike.
- Stvarni dostavljeni izvor daje **266 izvornih kalendara / 102.636 zapisa
  događaja**: **101.523 podržana i 1.113 preskočenih uz razlog**, u 647 paketa.
  Prvo indeksiranje/pretvorba trajalo je 87,39 sekundi uz vršnih 42 MiB; ponovno
  čitanje 3,28 sekundi uz 40 MiB. Ti događaji nisu uvezeni na HFC.
- Stvarni HFC kod i nova shema mapiranja ažurirani su: **15 izvršenih / nula
  neizvršenih migracija**. Provjeren je hrvatski GUI odabira/filtriranja/pregleda
  jednog kalendara (626 podržanih / 42 preskočena). Potvrda uvoza nije pokrenuta.
  Auth hashovi konfiguracije/redova, brojanja sadržaja i šest kalendara/44 događaja
  ostali su isti. Potrošna testna instalacija premještena je u Smeće; izvorne
  arhive i HFC privatni indeksi sačuvani su. Demo/apps-test nisu ažurirani;
  nema taga, releasea ni pusha.

Lokalna C5 matrica prihvata zabilježena je ispod. Ignorirani razvojni
`composer.lock` modula nije release datoteka. Čisto Composer razrješavanje
provjereno je s lokalnim kandidatima izvora; novi objavljeni tagovi ovisnosti i
udaljeni Git CI ostaju uvjeti objave, ne ovog izričito neobjavljenog koraka.

### C5 implementacija i završni lokalni prihvat — 15. listopada 2026.

Ovi datirani C5 dokazi prethode C6. Za dovršeni stvarni HFC uvoz, aktualna brojanja
migracija/testova i preostalu GUI provjeru koristiti C6 odjeljak iznad.

- `ConfluenceSiteReportService.php`, ograničeni repozitorijski upiti izvještaja i
  `site-report-overview.php` objedinjuju izvršavanja identiteta, područja i kalendara
  uz provjeru vlasnika/izvora. Nedovršeni, neuspješni i djelomični koraci ostaju
  vidljivi; pregled broji upozorenja, neuspjele privitke i neriješene skripte/stavke
  pregleda bez otkrivanja uvezenog koda, tajni ili privatnih putanja. Postojeći
  detaljni izvještaji i dalje imaju svoje radnje pregleda/odobravanja.
- Roditeljski sažeci čuvaju UUID-jeve izvještaja. `ConfluenceSiteJobOptions.php`
  iz prenosivih metapodataka uklanja lokalne C2/C3/C4 izvršne odabire. Potpuni backup
  i obnova na drugoj adresi čuvaju objedinjeni izvještaj i veze bez putanja izvora.
- `ConfluenceImportUploadService.php` dodaje izričitu radnju čišćenja izvora,
  zaštićenu vlasnikom/CSRF-om, tek nakon dovršenih područja i zaustavljenih obrada.
  Brave sadržaja, indeksa kalendara i baze sprječavaju utrke; nepoznate datoteke i
  simboličke poveznice zaustavljaju čišćenje. Uvezeni sadržaj, binarni privitci,
  mapiranja i izvještaji ostaju. Automatski istek više ne uklanja aktivne ili
  djelomično izvršene kalendarske poslove.
- Kontroler/rute/pogledi/prijevodi, [uputa izvještaja](site-reports_hr.md), README
  i indeks dokumentacije opisuju novo stanje. Nisu trebale nove C5 migracije,
  izmjene Autha ni frameworka. Stvarni izvršni kod nalazi se na HFC-u.

Prošle provjere:
- Importer PHPCS, Rector bez izmjena, PHPStan i **222 testa / 1.398 tvrdnji**;
  testovi prolaze i s autoloaderom HFC ovisnosti. Prolaze provjere dvojezičnih
  komentara, prijevoda, odvojene dokumentacije i razmaka.
- Prolazi čista lokalna Composer instalacija svih modula, CLI, migracije i HTTP.
  Puni aplikacijski pokretač prolazi **80 browser/API/performance testova za
  20 minuta**, uključujući privitke/ACL jednog područja, skripte, backup, Calendar
  API, spremanje editora, autorizaciju i mjerene granice SQL upita.
- Postojeći browser/API test jednog područja zasebno prolazi na SQLiteu,
  PostgreSQLu 18, MySQLu 8.4 i MariaDB-u 11.4. Sintetički C2/C3/C4 prihvat prolazi
  svih **osam kombinacija baze i filesystem/baza spremanja HTML-a**, uz provjeru
  stvarnog načina spremanja verzije, Calendar API-ja/ACL-a/ponavljanja, ponovnog
  uvoza, prenosivosti izvještaja i čišćenja izvora. Proširene C3 regresije binarnih
  privitaka/izostavljenih datoteka, povijesti, poveznica/uključivanja područja,
  kopija, osobnih vlasnika i oporavka prolaze na PostgreSQLu, MySQLu i MariaDB-u.
- HTTP prihvat na localhostu prolazi na **stvarnom mod_php-u (PHP 8.4)** i
  **običnom i sigurnom FPM-u (PHP 8.3)** s oba spremišta HTML-a. Sigurni FPM ima
  kod samo za čitanje, vanjsku konfiguraciju i podatke za pisanje, izričit okoliš
  i ograničen pristup filesystemu. Probe su ograničene na potrošne testne putanje.
- Potpuni šifrirani backup jednog potrošnog sitea obnavlja se na drugoj adresi:
  ostaju čitljiva četiri povezana izvještaja, bez lokalnih putanja arhive/izvršavanja.
- HFC izvještaj provjeren je na engleskom i hrvatskom bez pokretanja stvarnog uvoza
  sitea ili čišćenja njegove arhive. Migracije ostaju **15 izvršenih / nula
  neizvršenih**; postojeći Auth hashovi konfiguracije/redova i brojanja poslovnog
  sadržaja/kalendara nisu promijenjeni. Testni kontejneri/instalacije uklonjeni su;
  izvorni ZIP-ovi i ponovo upotrebljivi HFC izvor/indeksi ostaju.

Granice prihvata: stvarni dostavljeni site nema binarnih privitaka; site privitci
zato su provjereni sintetičkim primjerima, dok je stvarni ZIP jednog područja ranije
pregledan. Cijeli stvarni site s 44.594 stranice nije upisan na HFC. Lokalni testovi
nisu nova PHP 8.2–8.5 CI matrica ni provjera neobjavljenih tagova. Prvo pregled HFC-a;
release, tagovi ovisnosti, udaljeni CI, demo i apps-test ostaju zaseban, izričito
odobren posao. **Nisu napravljeni release, tag, commit ni push.**
