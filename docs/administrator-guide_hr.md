# Vodič za administratore

English version: [administrator-guide_en.md](administrator-guide_en.md)

## Izvoz iz Confluencea

Izradite XML izvoz točno jednog Confluence spacea. ZIP nemojte raspakirati ni mijenjati prije prijenosa. Importer ne koristi Confluence administratorsku lozinku i ne treba pristup aktivnom izvornom siteu.

## Tijek importa

1. Otvorite **Postavke → Područja → Confluence import**.
2. Odaberite `.xml.zip` datoteku i pokrenite prijenos.
3. Preglednik je prenosi u dijelovima. Povratkom u istu sesiju preglednika i ponovnim odabirom iste datoteke prijenos se nastavlja od položaja koji potvrdi poslužitelj.
4. Početna provjera čita samo metapodatke i prikazuje space, količine sadržaja, korisnike, grupe, ovlasti, makroe i privitke.
5. Potvrdite naziv, slug i jezik ciljnog područja.
6. Ostavite zadani aktualni sadržaj ili izričito uključite povijest, nacrte ili obrisane stranice.
7. Pregledajte svako predloženo mapiranje korisnika i grupa. Nemapirane stavke ostaju blokirane.
8. Pokrenite potvrđeni import i ostavite stranicu otvorenom do završetka.
9. Otvorite novo područje i pregledajte upozorenja importa.

Prenesena izvorna arhiva briše se nakon uspjeha. Neuspjeli import koji nije idempotentan ne može se ponoviti na istom poslu: pregledajte djelomični cilj, prema potrebi ga uklonite, ponovno prenesite izvor i pokrenite novi kontrolirani import.

## Osobni spaceovi

Osobni Confluence space zahtijeva potvrđeno mapiranje vlasnika. Importer ponovno koristi ili izrađuje osobno područje tog korisnika u Simbiozi te primjenjuje privatne ovlasti usmjerene na vlasnika. Ne izrađuje lokalnog korisnika samo zato što Confluence identitet postoji.

## Nakon importa

- pregledajte stablo stranica i naslovnicu područja;
- ovlasti ograničenih stranica provjerite računom koji nije administrator;
- pregledajte upozorenja o nepodržanim makroima;
- potvrdite da se privitci preuzimaju, a ne izvršavaju unutar preglednika;
- uvezite vanjski space na koji postoje poveznice kako bi se neriješene poveznice mogle uskladiti;
- provjerite Audit log kada je opcionalni Audit modul uključen.
