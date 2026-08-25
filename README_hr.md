# Simbioza modul za Confluence import

English version: [README.md](README.md)

Modul specifičan za Simbiozu koji sigurno uvozi Confluence XML backup jednog spacea u područje. Čuva stablo stranica, aktualni sadržaj, privitke, unutarnje poveznice i izričito mapirane ovlasti. Povijest stranica, nacrti i obrisane stranice opcionalni su i zadano isključeni.

## Ovisnosti

Obavezne i učitane prije ovog modula:

- `aaieduhr/heartphrame-framework`
- `aaieduhr/heartphrame-module-orm`
- `aaieduhr/heartphrame-module-menu`
- `aaieduhr/heartphrame-module-auth`
- `aaieduhr/heartphrame-module-editor-html`
- `aaieduhr/heartphrame-module-workspace`
- `aaieduhr/simbioza-module-user`

Opcionalne integracije:

- Audit bilježi administratorske odluke i rezultate importa.
- Backup cijelog sitea i poslovne cjeline Područja uključuje trajna mapiranja importa, a Editorovi backup provideri uključuju nativne privitke stranica.
- Backup jednog područja uključuje njegove Confluence izvorne podatke, dok se stvarni privitci prenose zajedno s Editor dokumentima.
- Comment uvozi komentare čiji su autori i stranice mapirani.
- Workspace Search automatski obnavlja izvedeni indeks nakon importa.

Svi interni paketi prate `dev-main`; ovaj razvojni modul ne sprema `composer.lock`.

## Što importer radi

- prima velike `.xml.zip` izvoze prijenosom u dijelovima koji se nakon prekida može nastaviti;
- prije dopuštanja izmjena izvodi read-only provjeru;
- predlaže naziv i ključ Confluence spacea kao naziv i slug područja;
- Confluence početnu stranicu postavlja kao naslovnicu područja, a kada je izvoz
  ne sadrži koristi prvu aktualnu korijensku stranicu kao jednoznačan fallback;
- u novom uvezenom području zadano skriva sadržaj stranice, dok stablo stranica
  ostaje dostupno;
- prepoznaje već uvezeni izvor i zahtijeva izričit izbor između zamjene tog
  područja i uvoza izolirane kopije;
- zadano uvozi aktualne stranice i ponovno gradi njihovo stablo;
- opcionalno uvozi povijest, nacrte i soft-obrisane stranice;
- prepisuje poveznice unutar spacea i naknadno usklađuje poveznice prema poslije uvezenim spaceovima;
- svaku uvezenu datoteku registrira kao stvarni privatni Editor privitak stranice i isporučuje je uz aktualnu provjeru ACL-a područja i stranice; izvorni identitet privitka izoliran je po import poslu pa nova kopija ili zamjena nikada ne preuzima UUID drugog dokumenta;
- nakon registracije uvezenih JPEG, PNG i WebP privitaka priprema njihove
  predmemorirane web-verzije; originali ostaju nepromijenjeni i dostupni klikom;
- točno podudarne postojeće korisnike i grupe sigurno predlaže za mapiranje;
- administrator za nemapirani identitet može izričito izraditi neaktivan Auth
  predračun bez lozinke i providera, koji nema mogućnost prijave;
- ranije potvrđeno mapiranje računa ponovno koristi u kasnijim importima
  spaceova bez promjene tog računa, grupa, providera ili prava;
- čuva mapirane Confluence autore i zadnje urednike kao autore dokumenata i
  verzija, dok administrator importa ostaje operativni izvršitelj;
- neriješene ACL identitete obrađuje zatvoreno, bez proširenja pristupa;
- osobni Confluence space mapira u osobno područje potvrđenog vlasnika;
- nepodržane makroe i druge odluke bilježi u trajnom izvještaju importa povezanom iz popisa **Nedavni Confluence importi**;
- omogućuje odustajanje od nedovršenog importa uz trenutačno brisanje prenesene arhive i podataka pripreme;
- nakon uspješnog importa briše prenesenu izvornu arhivu.
- veliki potvrđeni import obrađuje u ograničenim faznim koracima koji se mogu nastaviti i
  tek jednom na kraju usklađuje poveznice, izvještaj i indeks pretrage.

Pretvarač prikazuje `children` i `pagetree` kao lokalne ACL-zaštićene poveznice,
popise privitaka i multimediju iz nativnih Editor privitaka, responzivne
Confluence rasporede, statuse, sidra, metapodatke koda i lokalni sadržaj
stranice. Confluence `include` postaje Editorova nativna dinamička referenca
**Uključi sadržaj stranice**, pa svaki pregled dobiva aktualni ciljni sadržaj
tek nakon njegove vlastite ACL provjere. Valjani Confluence `chart` postaje
uređiv nativni Editor grafikon, dok nepotpuni podaci zadržavaju izvornu tablicu
i napomenu u izvještaju. Roadmap Planner postaje Editorov uređiv nativni
**Vremenski plan** koji čuva razdoblje, mjerilo, grupe, boje, trake aktivnosti,
oznake, opise i sigurne poveznice. Podržani Confluence widgeti nikada ne prenose
skripte providera u Simbiozu: YouTube postaje responzivan video s pojačanom
privatnošću, a Figma i Twitter/X tematske kartice s vanjskom poveznicom.
Nepodržani provideri widgeta ostaju navedeni u izvještaju importa. Na Confluence stranici s popisom datoteka izostavlja
se Confluenceova uređivačka akcija, a `content-report-table` materijalizira se
kao običan uređiv HTML s poveznicama poznatim u trenutku importa. Ne stvara se
Workspace predložak ni dinamička komponenta. Trajno brisanje
uvezenog područja uklanja i njegovo podrijetlo, mapiranja, izvještaje i metapodatke
privitaka. Trajno uklanjanje jedne uvezene stranice briše samo
njezino mapiranje i izlazne poveznice; stvarnim privitcima upravlja Editor, a dolazna
mapiranja postaju neriješena, a ostatak importa ostaje netaknut.

Confluence Page Properties u okomitom i vodoravnom rasporedu te Page Properties
Report mapiraju se na nativna Workspace svojstva i dinamički izvještaj.
Korisničke reference u tim svojstvima prikazuju mapirano ime. Gallery koristi
stvarne privitke, Live Search i Page Tree Search ostaju ograničeni na trenutačno
područje, a Recently Updated koristi ACL-sigurne objavljene promjene. Jednostavni
paneli, rasporedi i dodatno poboljšane tablice ostaju običan responzivan HTML
umjesto posebnih Confluence makro komponenti. Svaka uvezena tablica normalizira
se na isti obrubljeni, prugasti, responzivni HTML s hover stanjem kakav izrađuje
HTML Editor, pa aktivna tema upravlja zaglavljem, redcima, obrubima i bojama.

## Brzi početak

```bash
composer require aaieduhr/simbioza-module-confluence-import:dev-main
vendor/bin/hph simbioza-confluence-import:install-migration
vendor/bin/hph orm-migrate up
```

Uključite paket nakon svih obaveznih modula pa otvorite **Postavke → Područja → Confluence import**. Prenesite Confluence XML ZIP izvoz jednog spacea, pregledajte provjeru, potvrdite mapiranja korisnika i grupa ili izričito odaberite izradu neaktivnih predračuna te pokrenite import.

Read-only pregled kroz CLI:

```bash
vendor/bin/hph simbioza-confluence-import:inspect /srv/import/podrucje-tima.xml.zip
```

## Dokumentacija

- [Indeks dokumentacije](docs/index_hr.md)
- [Instalacija i konfiguracija](docs/installation_hr.md)
- [Vodič za administratore](docs/administrator-guide_hr.md)
- [Mapiranje identiteta, ACL i sigurnost](docs/mapping-security_hr.md)
- [Pretvorba sadržaja i poveznice](docs/content-links_hr.md)
- [Integracija za developere](docs/developer-guide_hr.md)
- [Testiranje i rješavanje problema](docs/testing-troubleshooting_hr.md)

## Licenca

Ovaj je rad objavljen pod licencom European Union Public License (EUPL) v1.2.
