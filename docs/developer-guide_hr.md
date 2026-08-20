# Integracija za developere

English version: [developer-guide_en.md](developer-guide_en.md)

## Arhitektura

Modul posjeduje samo stanje specifično za Confluence import:

- poslove i metapodatke nastavivog prijenosa;
- mapiranja izvornog spacea i sadržaja;
- potvrđena mapiranja korisnika i grupa;
- mapiranja neriješenih i riješenih poveznica među spaceovima;
- metapodatke privatno sačuvanih uvezenih privitaka.

Workspace, HTML Editor, Auth, Menu i Simbioza User ostaju vlasnici svojih zapisa. Opcionalne integracije prepoznaju se kroz instalirane javne ugovore servisa, čime importer ne ulazi u privatne sheme drugih modula.

## Faze obrade

1. `ConfluenceImportUploadService` izrađuje posao i provjerava poredane dijelove prijenosa.
2. `ConfluenceArchive` provjerava ZIP ograničenja i sigurne nazive zapisa.
3. `ConfluenceExportReader` strujno čita objekte iz `entities.xml`.
4. `ConfluenceExportScanner` izrađuje read-only inventar i moguća mapiranja.
5. `ConfluenceImportService` provjerava administratorsku potvrdu i izrađuje ciljne objekte.
6. `ConfluenceHtmlConverter` proizvodi prenosivi HTML s privremenim oznakama.
7. `ConfluenceReferenceResolver` zamjenjuje lokalna odredišta i čuva stabilna neriješena odredišta.
8. Opcionalne Search, Audit, Comment i Backup integracije rade kroz svoje javne ugovore.

## Ugovor za backup

Opcionalni Backup provideri za cijeli site i poslovnu cjelinu izvoze samo dovršene poslove i njihove trajne retke. Uklanjaju izvornu upload putanju, privatne putanje privitaka pretvaraju u prenosive zapise i tijekom obnove ponovno grade apsolutnu privatnu putanju. Zasebni provider `simbioza-confluence-import-workspace` izvršava se nakon `workspace-scope` providera i prenosi samo izvorne podatke te privatne blobove odabranog područja. Obnova kao kopija stvara nove konfliktne UUID-ove i izvorne identitete, preko zajedničkog stanja ponovo veže stranice i dokumente te prepisuje poveznice privitaka. Privremeni prijenosi nisu backup podaci.

## Proširenje podrške za makroe

Dodajte pretvornik storage-format sadržaja makroa, sigurni statički zamjenski prikaz te fixturee za neispravne i potpune podatke. Izrada živog Calendar/Task objekta zahtijeva izričiti adapter u vlasničkom modulu i potpuno mapiranje ACL-a. Iz nedostajućeg izvornog subjekta nikada ne zaključujte da je pristup javan.

## Vlasništvo sheme

Reverzibilna migracija modula služi kao predložak. Instalacija je kopira u povijest migracija host aplikacije. Nove kolone i tablice moraju ostati prefiksirane kroz konstante modula i imati pokriće za MySQL, PostgreSQL i SQLite u matrici host aplikacije.
