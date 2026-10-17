# Odgođeni razvoj uvoza cijelog sitea

Uvoz cijelog Confluence sitea odgođen je 17. listopada 2026. Rad je sačuvan u
grani `confluence-site`, nije odobren za izdanje i nije spojen u `main`.
Podržani put izdanja ostaje postojeći uvoz jednog područja.

## Sačuvani rad

- Inventar sitea, odabrana područja, zajedničke arhive i privitci.
- Mapiranja korisnika/grupa, dupli identiteti i pravila neaktivnih imenika/računa.
- Osobni/timski kalendari, izričiti vlasnici, boje i ponašanje pretplata.
- Jedinstveni pregledani postupak, napredak, zasebni izvještaji i poveznice područja.
- Dodavanje u osobna područja bez zamjene, kalendarski makroi, testovi i EN/HR upute.

Povezane snimke koriste isto ime grane u Simbiozi, Calendaru i Simbioza Useru.
Auth i Framework nisu dio ove razvojne snimke. Lokalni HFC nacrti, dokazi
mapiranja i izvorne arhive privatni su radni podaci, ne Git testni primjeri.
Ovdje spremljeni testni primjeri su sintetički.

## Prije nastavka

1. Pročitati `site-import-plan_hr.md`; dovršena implementacija nije potvrda
   prijedloga identiteta i vlasništva iz konkretnog izvora.
2. Provjeriti aktualnu aktivnost računa/imenika i izričite arhivske račune.
3. Osvježiti projekcije vlasnika kalendara iz aktualnih odabira korisnika.
   Neprazna spremljena oznaka vlasnika nije dovoljna ako se njegov račun preskače.
4. Dobiti ljudsku potvrdu nejasnih identiteta, vrsta i vlasnika kalendara.
   Pravo uređivanja ni stranica koja prikazuje kalendar nisu dokaz vlasništva.
5. Napraviti potrošni HFC nacrt te ponoviti regresiju jednog područja, GUI uvoz
   sitea, privitke, backup/restore i provjere instalacije.
6. Prije budućeg izdanja uskladiti ovisnosti kandidata sa stvarno objavljenim
   verzijama; brojevi kandidata u ovoj grani nisu objavljena izdanja.

Čuvanje grane ne znači automatska pravila za konkretan izvor, izmjene produkcije
ni novo izdanje. Postojeći uvoz jednog područja ostaje na `main`.
