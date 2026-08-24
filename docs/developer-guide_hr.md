# Integracija za developere

English version: [developer-guide_en.md](developer-guide_en.md)

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

## Faze obrade

1. `ConfluenceImportUploadService` izrađuje posao i provjerava poredane dijelove prijenosa.
2. `ConfluenceArchive` provjerava ZIP ograničenja i sigurne nazive zapisa.
3. `ConfluenceExportReader` strujno čita objekte iz `entities.xml`.
4. `ConfluenceExportScanner` izrađuje read-only inventar i moguća mapiranja.
5. `ConfluencePrincipalMatcher` strogo predlaže točne postojeće Auth korisnike
   i grupe; ne izvodi fuzzy podudaranje.
6. `ConfluenceImportService` provjerava administratorsku potvrdu, po izričitom
   izboru kroz javni Auth servis izrađuje neaktivne predračune te izrađuje ciljne objekte.
7. `ConfluenceHtmlConverter` proizvodi prenosivi HTML s privremenim oznakama.
8. `ConfluenceReferenceResolver` zamjenjuje lokalna odredišta i čuva stabilna neriješena odredišta.
9. Opcionalne Search, Audit, Comment i Backup integracije rade kroz svoje javne ugovore.

Importer ne upisuje izravno u Auth tablice. `AuthUserService` provodi zajednička
pravila za neaktivan račun bez providera, aktivaciju, lokalnu lozinku i obaveznu
promjenu administratorske privremene lozinke. Time isti životni ciklus vrijedi
za ovaj importer i bilo koji budući migracijski alat.

Masovni upisi stranica, workflowa i ACL-a izvode se kroz javni Workspace servis `WorkspaceContentChangeBatch`. Izvorni događaji se tijekom te cjeline prikupljaju, a na kraju se šalje jedan `bulk_content_changed` događaj po promijenjenom području. Time backlink i Search listeneri ne obnavljaju izvedene podatke nakon svake pojedine stranice. `finally` završetak namjerno šalje objedinjeni događaj i nakon djelomičnog neuspjeha kako izvedeni indeksi ne bi ostali u stanju starijem od stvarno spremljenog izvornog sadržaja.

`confluence_import.import_execution_time_limit` određuje gornju granicu jednog
procesnog koraka. `queue()` sprema plan i stanje, a `process()` pod zaključavanjem
obrađuje ograničeni batch privitaka ili stranica te atomarno sprema novi offset.
Završni korak usklađuje reference, izvještaj i izvedene indekse samo jednom.
Ponovljeni ili istodobni poziv vraća trenutačno stanje umjesto dupliciranja
sadržaja. Fatalni PHP prekid označava posao neuspjelim i ostavlja dovoljno
metapodataka za administratorsku dijagnostiku.

Prije `startImport()` priprema ponovnog uvoza razrješava kanonsko mapiranje
izvora. Strategija `replace` kroz javni Maintenance servis trajno briše ranije
uvezeno područje, dok `copy` dodaje sufiks izvornim identitetima i izrađuje
zasebno područje. Izrada stranica označava logički Confluence `homePage` kao
naslovnicu područja, uz fallback na aktualnu korijensku stranicu.

Otkazivanje radi samo nad zaključanim privremenim poslom prijavljenog
administratora. Servis najprije provjerava da sadržajni import nije započeo,
zatim briše isključivo datoteku unutar upravljanog privatnog upload direktorija
i tek potom zapis posla. Tako nedovršeni prijenos ne ostavlja arhivu, a
djelomično izvedeni import ne može se pogrešno prikazati kao sigurno otkazan.

## Ugovor za backup

Opcionalni Backup provideri za cijeli site i poslovnu cjelinu izvoze samo dovršene poslove i njihove trajne retke, uključujući strukturirani izvještaj stranica za provjeru. Zasebni provider `simbioza-confluence-import-workspace` izvršava se nakon `workspace-scope` providera i prenosi samo izvorne podatke odabranog područja. Registrirane binarne privitke posjeduje Editor i prenosi ih svojim redovnim backup providerom, bez dupliciranja blobova. Obnova kao kopija preko zajedničkog stanja ponovo veže izvorne identitete i dokumente. Privremeni prijenosi i staging datoteke nisu backup podaci.

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

Dodajte pretvornik storage-format sadržaja makroa, sigurni statički zamjenski prikaz te fixturee za neispravne i potpune podatke. Izrada živog Calendar/Task objekta zahtijeva izričiti adapter u vlasničkom modulu i potpuno mapiranje ACL-a. Iz nedostajućeg izvornog subjekta nikada ne zaključujte da je pristup javan.

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
