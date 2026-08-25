# Vodič za administratore

English version: [administrator-guide_en.md](administrator-guide_en.md)

## Izvoz iz Confluencea

Izradite XML izvoz točno jednog Confluence spacea. ZIP nemojte raspakirati ni mijenjati prije prijenosa. Importer ne koristi Confluence administratorsku lozinku i ne treba pristup aktivnom izvornom siteu.

## Tijek importa

1. Otvorite **Postavke → Područja → Confluence import**.
2. Odaberite `.xml.zip` datoteku i pokrenite prijenos.
3. Preglednik je prenosi u dijelovima. Povratkom u istu sesiju preglednika i ponovnim odabirom iste datoteke prijenos se nastavlja od položaja koji potvrdi poslužitelj.
4. Početna provjera čita samo metapodatke i prikazuje space, količine sadržaja, korisnike, grupe, ovlasti, makroe i privitke.
5. Potvrdite naziv, slug i jezik ciljnog područja. Ako je isti Confluence izvor
   već uvezen, odaberite i **Zamijeni postojeće područje** ili **Uvezi zasebnu
   kopiju**. Zamjena trajno uklanja ranije uvezeno područje prije prvog novog
   zapisa. Kopija ostavlja postojeće područje netaknuto i koristi izolirane
   identitete izvora i privitaka.
6. Ostavite zadani aktualni sadržaj ili izričito uključite povijest, nacrte ili obrisane stranice.
7. Pregledajte svako predloženo mapiranje korisnika i grupa. Za nemapiranog
   korisnika možete ostaviti blokiran pristup ili izričito izraditi neaktivan
   predračun bez lozinke i providera. Za više nemapiranih korisnika dostupna je
   i zajednička opcija, nakon koje i dalje možete promijeniti pojedini redak.
8. Pokrenite potvrđeni import. Stranica prikazuje fazu, postotak te broj
   obrađenih privitaka i stranica. Možete je zatvoriti; ponovnim otvaranjem
   istog posla nastavlja se od zadnjeg potvrđenog koraka.
9. Pod **Nedavni Confluence importi** otvorite trajni izvještaj. On povezuje svaku stranicu čiji nepodržani sadržaj zahtijeva ručnu provjeru.
10. Otvorite novo područje i provjerite prijavljene stranice.

Provjereni prijenos i mapiranja ostaju dostupni nakon napuštanja stranice. Dok
stvarni import još nije počeo, gumb **Odustani od importa** odmah briše prenesenu
arhivu i podatke pripreme. Nedovršeni prijenos možete otkazati i iz popisa
nedavnih poslova.

Prenesena izvorna arhiva automatski se briše nakon uspješnog importa. Neuspjeli
import koji je već počeo mijenjati sadržaj ne može se otkazati kao običan
prijenos niti ponoviti na istom poslu: pregledajte djelomični cilj, prema potrebi
ga uklonite, ponovno prenesite izvor i pokrenite novi kontrolirani import.

Završna faza usklađuje unutarnje i vanjske reference, sprema izvještaj te samo
jednom osvježava izvedeni indeks pretrage. Zbog toga stranice mogu postajati
vidljive tijekom obrade, ali konačni izvještaj i potpuna pretraga mjerodavni su
tek kada posao dobije stanje **Dovršeno**.

Roadmap Planner sadržaj uvozi se kao uređiv nativni **Vremenski plan**. YouTube
widgeti postaju responzivni videozapisi s pojačanom privatnošću, a Figma i
Twitter/X widgeti sigurne kartice s vanjskom poveznicom. Svaki nepodržani widget
ili neispravan roadmap ulazi u završni izvještaj umjesto tihog pokretanja
izvornog koda.

Naslovnica područja preuzima se iz Confluence `homePage` metapodatka. Ako izvoz
ne sadrži tu referencu, importer bira prvu aktualnu korijensku stranicu, a zatim
prvu aktualnu stranicu. Time dovršeni import ne ostavlja područje bez podešene
naslovnice.

U novom običnom području uvezenom iz Confluencea sadržaj stranice zadano je
skriven. Stablo stranica ostaje dostupno, a administrator obje postavke prikaza
naknadno može promijeniti u postavkama područja.

## Osobni spaceovi

Osobni Confluence space zahtijeva potvrđeno mapiranje vlasnika. Importer ponovno koristi ili izrađuje osobno područje tog korisnika u Simbiozi te primjenjuje privatne ovlasti usmjerene na vlasnika. Ako vlasnik još nema račun, administrator može izričito izraditi neaktivan predračun, ali prije pristupa osobnom području mora ga u Auth postavkama povezati sa stvarnim načinom prijave i aktivirati.

## Prijedlozi mapiranja

Automatski prijedlog koristi samo prethodno potvrđeno mapiranje ili točno
podudaranje nakon zanemarivanja veličine slova i suvišnih razmaka:

- korisnik: prvo e-pošta i aliasi, zatim login oznaka;
- grupa: prvo tehnički ključ, zatim naziv grupe.

Sličan naziv ili dvosmisleno podudaranje nikada se ne odabiru automatski.
Primjerice, postojeća grupa `srce-zaposlenici` predlaže se za jednaku
Confluence grupu, ali ne i za `srce-zaposlenik`.

Auth podržava prikazno ime te odvojeno ime i prezime. Importer ih prenosi kada
ih Confluence izvoz sadrži. Neki Confluence izvozi čuvaju samo tehničku login
oznaku i e-poštu; neaktivni predračun tada tu vrijednost koristi kao vidljivi
fallback dok administrator ili budući provider prijave ne dostavi stvarne
profilne podatke.

## Nakon importa

- pregledajte stablo stranica i naslovnicu područja;
- ovlasti ograničenih stranica provjerite računom koji nije administrator;
- otvorite ikonu izvještaja pod **Nedavni Confluence importi** i pregledajte svaku povezanu stranicu;
- provjerite prikazuje li spajalica Confluence datoteke kao stvarne privitke stranice;
- potvrdite da se privitci preuzimaju, a ne izvršavaju unutar preglednika;
- provjerite učitavaju li velike uvezene slike svoju web-verziju, dok klik na
  sliku i dalje otvara netaknuti original;
- uvezite vanjski space na koji postoje poveznice kako bi se neriješene poveznice mogle uskladiti;
- provjerite Audit log kada je opcionalni Audit modul uključen.
