# Integracija za developere

Engleska verzija: [developer-guide_en.md](developer-guide_en.md)

## Arhitektura

Modul posjeduje samo stanje specifično za Confluence import:

- poslove i metapodatke nastavivog prijenosa;
- mapiranja izvornog spacea i sadržaja;
- potvrđena mapiranja korisnika i grupa;
- mapiranja neriješenih i riješenih poveznica među spaceovima;
- izvorne metapodatke privitaka registriranih kao nativni Editor asseti.

Jedinstveni ključ izvornog privitka je `(job_id, source_attachment_id,
source_version)`. Confluence identitet vrijedi samo unutar odabranog
izvoza/importa, pa generirani Editor UUID pripada jednom import poslu i ne može
se sudariti sa zamjenom ili zasebno uvezenom kopijom.

Workspace, HTML Editor, Auth, Menu i Simbioza User ostaju vlasnici svojih zapisa. Opcionalne integracije prepoznaju se kroz instalirane javne ugovore servisa, čime importer ne ulazi u privatne sheme drugih modula.

## Zaseban pregled sitea

`ConfluenceArchive::inspect()` iz descriptora određuje vrstu arhive i sigurnosne
limite. Za site i više područja `ConfluenceExportScanner` delegira obradu servisima
`ConfluenceSiteInventory`, `ConfluenceInventoryReader` i ograničenom čitaču
`ConfluenceXmlInventoryFramer`. Izvorni čitač jednog područja i pretvarači ostaju
na postojećem putu. Servise registrirajte kroz modulov `config/services.php`,
umjesto izrade scannera bez ovisnosti za pregled sitea.

Pregled je verzionirani, atomski JSON indeks u privatnim podacima modula, vezan
uz SHA-256 ZIP-a. Sadrži samo brojače i dopuštene metapodatke područja.
`saveInventorySelection()` zaključava posao prijavljenog vlasnika, provjerava
otisak izvora i odabrane ID-eve te mijenja samo opcije posla. `queue()`,
`queueBatch()` i `import()` odbijaju posao koji je samo pregled prije prvog
sadržajnog upisa. C1 ne traži migraciju sheme. Limite, čišćenje i ponovno
pokretanje početnog prolaza opisuje [pregled sitea](site-inventory_hr.md).

## Grana identiteta sitea

Grana identiteta sitea je odvojena: `ConfluenceSitePrincipals` tokom čita samo
dopuštene metapodatke u privatni indeks, `ConfluenceSiteAccessService` upravlja
pregledom/primjenom/ponavljanjem, a `ConfluenceSiteAuth` povezuje nepromijenjene
Auth servise. Adapter dodavanja članstava namjerno ne poziva GUI setter za
zamjenu članstava jer bi time automatske dodjele postale ručne ili bi se
prepisivala izuzeća. Standardni događaji članstava objavljuju se tek nakon
potvrde transakcije. Ciljni podaci i potvrđena mapiranja čitaju se skupno, ne
zasebno za svaki izvorni identitet. Dovršeni posao `site_access` uključuje
mapiranja u backup neovisno o nedovršenom sadržajnom poslu. Pogledajte
[C2 uvoz identiteta](site-identities_hr.md).

## Grana sadržaja sitea

`ConfluenceSiteContentService` pregledava/zaključava C3 odabire vlasnika, provjerava
otiske ciljeva i C2 mapiranja te koordinira roditeljski `site_import` i podposlove
`site_space` kroz postojeći `ConfluenceImportService`. Zajednički
`ConfluenceSiteContentIndex` najprije razrješava neuređeno vlasništvo, zatim razdvaja
samo tražena područja/verzije tijela. Milijuni nepovezanih svojstava odbacuju se
prije DOM obrade; ne izrađuje se međudatoteka sa svim povijesnim tijelima.
Postojeći scanner/pretvarač čita te tokove bez novog raspakiravanja XML-a.

`ConfluenceImportRepository::withSiteContext()` ograničava ključeve/ID-eve područja
na normalizirani izvorni URL i vraća kontekst u `finally`. Potvrđeni C2 korisnici/grupe
učitavaju se skupno. ID-evi stranica/privitaka ostaju izvorni za putanje u ZIP-u.
Aliasi kopija i razrješavanje poveznica odvojeni su od glavnih ciljeva i drugih siteova.
Roditelj čuva arhivu za C4. Trajno brisanje područja čisti cilj i staging podposla,
ne zajednički ZIP ni trajni UUID podposla, čime omogućuje izričito ponavljanje.
Backup uklanja izvršive/lokalne opcije posla, a čuva trajna mapiranja.
Pogledajte [C3 postupak i oporavak](site-content_hr.md); generički Auth/framework ne mijenjaju se.
Osobna migracija neaktivnih vlasnika traži i odgovarajući razvojni kod modula Simbioza
User. Prije releasea objavite tu ovisnost i prilagodite ograničenje importera novom
provjerenom tagu; importer ne objavljivati uz staro ponašanje pripreme područja.

## Grana kalendara sitea

`ConfluenceSiteCalendarService` koristi potvrđene C2 identitete i javni Calendar
API. Ograničeni AO čitač/indeks ne raspakirava cijeli XML dodataka; pretvorba,
izvještaji, transakcijski nastavak i povezivanje stranica opisani su u
[C4 migraciji kalendara](site-calendars_hr.md). Trajni izvorni aliasi imaju zaseban
backup skup. Calendar ostaje opcionalan: obični uvoz sadržaja ne smije ovisiti o
njemu niti importer smije izravno čitati/pisati Calendar tablice.
Prije releasea objavite provjerene Calendar dorade i zahtijevajte njihov tag pri
uključivanju C4; stariji Calendar nema ugovor potreban za ovu pretvorbu.

## Faze obrade jednog područja

1. `ConfluenceImportUploadService` izrađuje posao i provjerava poredane dijelove prijenosa.
2. `ConfluenceArchive` provjerava ZIP ograničenja i sigurne nazive zapisa.
3. `ConfluenceExportReader` strujno čita objekte iz `entities.xml`.
4. `ConfluenceExportScanner` izrađuje read-only inventar i moguća mapiranja.
5. `ConfluencePrincipalMatcher` strogo predlaže točne postojeće Auth korisnike
   i grupe; ne izvodi fuzzy podudaranje.
6. `ConfluenceImportService` provjerava administratorsku potvrdu, po izričitom
   izboru kroz javni Auth servis izrađuje neaktivne predračune te izrađuje ciljne objekte.
7. `ConfluenceHtmlConverter` proizvodi prenosivi HTML s privremenim oznakama.
8. `ConfluenceReferenceResolver` zamjenjuje lokalna odredišta, a finalizacija
   trajno materijalizira naknadno razriješene poveznice u sadržaj Editora.
9. Opcionalne Search, Audit, Comment i Backup integracije rade kroz svoje javne ugovore.

Importer ne upisuje izravno u Auth tablice. `AuthUserService` provodi zajednička
pravila za neaktivan račun bez providera, aktivaciju, lokalnu lozinku i obaveznu
promjenu administratorske privremene lozinke. Time isti životni ciklus vrijedi
za ovaj importer i bilo koji budući migracijski alat.

Masovni upisi stranica, workflowa i ACL-a izvode se kroz javni Workspace servis `WorkspaceContentChangeBatch`. Izvorni događaji se tijekom te cjeline prikupljaju, a na kraju se šalje jedan `bulk_content_changed` događaj po promijenjenom području. Time backlink i Search listeneri ne obnavljaju izvedene podatke nakon svake pojedine stranice. `finally` završetak namjerno šalje objedinjeni događaj i nakon djelomičnog neuspjeha kako izvedeni indeksi ne bi ostali u stanju starijem od stvarno spremljenog izvornog sadržaja.

`import_execution_time_limit` određuje gornju granicu jednog
procesnog koraka. `queue()` veliki nepromjenjivi plan sprema jednom u
`manifest.json`, a mali promjenjivi napredak u `state.json`. Pritom jednom
odabire aktualne verzije privitaka i priprema kontekst makroa svih stranica.
`process()` pod zaključavanjem obrađuje ograničeni batch privitaka ili stranica
te atomarno sprema samo novi napredak.
Završni korak usklađuje reference, izvještaj i izvedene indekse samo jednom.
Ponovljeni ili istodobni poziv vraća trenutačno stanje umjesto dupliciranja
sadržaja. Fatalni PHP prekid označava posao neuspjelim i ostavlja dovoljno
metapodataka za administratorsku dijagnostiku.

Nakon uspješne finalizacije normalni prikaz stranice koristi samo Workspace,
Editor i njihove izvedene indekse. Ne čita `simbioza_confluence_import_*`
tablice. Privremene resolver poveznice zamjenjuju se trajnim rutama čim se cilj
može razriješiti, a dinamičke forme pretrage spremaju obične Workspace slugove.
Zato se modul nakon dovršenih importa može isključiti; njegove tablice služe
samo ponovnom importu, izvještaju i administratorskom porijeklu podataka.

Registracija privitaka jedne stranice dohvaća sve pripadne retke jednim upitom i
skupno potvrđuje prijenos vlasništva Editoru. Izrada web-varijanti slika nije dio
kritičnog puta importa: varijante se izrađuju lijeno na prvi prikaz ili kroz
Editorov nastavivi administratorski posao optimizacije slika.

Prije `startImport()` priprema ponovnog uvoza razrješava kanonsko mapiranje
izvora. Strategija `replace` kroz javni Maintenance servis trajno briše ranije
uvezeno područje, dok `copy` dodaje sufiks izvornim identitetima i izrađuje
zasebno područje. Prije brisanja zamjena pamti javne slugove stranica i UUID-e
nativnih privitaka te ih vraća istim logičkim izvornim zapisima. Finalizacija
ponovno provjerava i razriješene i neriješene uvezene poveznice, pa drugo
uvezeno područje ne može nakon zamjene ostati usmjereno na zastarjeli cilj.
Izrada stranica označava logički Confluence `homePage` kao
naslovnicu područja, uz fallback na aktualnu korijensku stranicu.

Otkazivanje radi samo nad zaključanim privremenim poslom prijavljenog
administratora. Servis najprije provjerava da sadržajni import nije započeo,
zatim briše isključivo datoteku unutar upravljanog privatnog upload direktorija
i tek potom zapis posla. Tako nedovršeni prijenos ne ostavlja arhivu, a
djelomično izvedeni import ne može se pogrešno prikazati kao sigurno otkazan.

## Ugovor za backup

Opcionalni Backup provideri za cijeli site i poslovnu cjelinu izvoze samo dovršene poslove i njihove trajne retke, uključujući strukturirani izvještaj stranica za provjeru. Zasebni provider `simbioza-confluence-import-workspace` izvršava se nakon `workspace-scope` providera i prenosi samo izvorne podatke odabranog područja. Registrirane binarne privitke posjeduje Editor i prenosi ih svojim redovnim backup providerom, bez dupliciranja blobova. Obnova kao kopija preko zajedničkog stanja ponovo veže izvorne identitete i dokumente. Privremeni prijenosi i staging datoteke nisu backup podaci.

Ponovni cleanup dovršenog posla uklanja samo njegov izvorni ZIP i parserski
staging direktorij. Ne briše spremljeni privitak samo zato što mu ciljni čvor ili
dokument još nije postavljen: takav privitak može biti referenciran iz drugog
Confluence spacea i zahtijeva izričitu provjeru referenci prije uklanjanja.

## Trajno čišćenje područja

Modul uvjetno sluša javni događaj trajnog brisanja Workspace modula. Prije nego
Workspace ukloni vlastite retke, listener briše samo pripadajuće metapodatke
završenog/import posla, mapiranja sadržaja, poveznica i identiteta te upravljane
datoteke čije kanonske putanje ostaju unutar podešenog Confluence data
direktorija. Izvorni ZIP prijenosi već se uklanjaju nakon uspješnog importa.

Modul sluša i skupni događaj `WorkspacePagesPermanentlyDeleting`. On nastaje
prije trajnog uklanjanja nikad objavljene stranice ili dovoljno stare
soft-obrisane stranice iz Održavanja. Listener uklanja samo Confluence retke i
privatne datoteke ciljanih stranica. Izlazne poveznice obrisane stranice nestaju,
a preostale dolazne poveznice postaju neriješene kako bi ih kasniji import mogao
ponovno povezati. Obični soft delete ne pokreće čišćenje jer se stranica još može
vratiti.

## Proširenje podrške za makroe

Dodajte pretvornik storage-format sadržaja makroa, sigurni statički zamjenski
prikaz te fixturee za neispravne i potpune podatke. Izrada živog Calendar/Task
objekta zahtijeva izričiti adapter kroz javni servis vlasničkog modula. Ako XML
ne sadrži događaje ili potpuni ACL, kao kod Team Calendara, trajni izvještaj mora
zahtijevati svjestan administratorski izbor ICS-a/cilja, vrste i početne
vidljivosti. Iz nedostajućeg izvornog subjekta nikada ne zaključujte da je
pristup javan.

Adapter za `chart` referentni je primjer nativnog Editor sadržaja: čita tablicu
i parametre prikaza makroa, normaliziranu definiciju predaje servisu
`EditorHtmlChartService`, a kod nepotpunih podataka zadržava izvornu tablicu.
Uvezeni grafikon zatim bez Confluence-specifičnog koda slijedi ponašanje Editor
API-ja, Backupa te izvoza stranice i Područja.

Adapter za `roadmap` slijedi isto pravilo vlasništva. Dekodira Roadmap Planner
JSON, normalizira ga kroz `EditorHtmlRoadmapService` i sprema isti kanonski blok
koji stvara Editorovo sučelje. Renderiranje, API blokovi, Backup te izvoz stranice
i područja zato ostaju odgovornost Editora. Adapter za `widget` namjerno koristi
popis dopuštenih providera: novi provider smije se dodati samo uz ograničeni URL
parser, siguran izlaz, testove neispravnog ulaza i bez kopiranja udaljenih
skripti. Nepoznati widget mora ostati u zamjenskom prikazu koji ulazi u izvještaj.

Prije serijalizacije `ConfluenceHtmlConverter` svaku preostalu tablicu
normalizira na ugovor HTML Editora: `table table-bordered table-striped
table-hover`, zaglavlje `table-light`, semantičke sekcije tablice i točno jedan
`table-responsive` omotač. Pretvornici pojedinih makroa zato daju podatke i
semantiku tablice, a ne vlastita konkurentna pravila prikaza.

## Vlasništvo sheme

Reverzibilna migracija modula služi kao predložak. Instalacija je kopira u povijest migracija host aplikacije. Nove kolone i tablice moraju ostati prefiksirane kroz konstante modula i imati pokriće za MySQL, PostgreSQL i SQLite u matrici host aplikacije.

## Confluence datumi i doslovno vrijeme

Confluence datum često čuva vrijednost samo u praznom elementu
`<time datetime="2019-01-22" />`. Converter atribut pretvara u siguran vidljiv
tekst prije nego Editorova sanitizacija ukloni nepodržane oznake. Prolaz se
ponavlja nakon pretvorbe makroa za time elemente dodane HTML-tabličnim makroom.
Postojeće vidljive oznake ostaju iste; datum/vrijeme/vremenska zona ne mijenjaju
se prešutno. Atribut nikad ne postaje izvršni HTML. Susjedni doslovni tekst,
primjerice `, 13:00, Srce`, ostaje sačuvan.

Regresijski test datuma sastanka DABAR-a pokriva izvorni oblik iz dostavljene
arhive i završni sanitizirani HTML. Prethodno uvezena verzija koja je već izgubila
datum ne može ga pouzdano zaključiti bez arhive; izmjena convertera štiti nove
uvoze, ne izmišlja datum iz naslova stranice.
