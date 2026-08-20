# Pretvorba sadržaja i poveznice

English version: [content-links_en.md](content-links_en.md)

## Stranice i verzije

Importer grupira Confluence objekte stranica prema logičkom ID-u sadržaja. Najnovija objavljena verzija postaje aktualni Simbioza dokument. Odnos roditelja ponovno gradi stablo stranica područja. Kada su odabrane, ranije objavljene verzije ulaze u povijest, nacrti ostaju nacrti, a obrisane stranice ostaju soft-obrisane kako bi ih administrator mogao vratiti.

Izvorni identifikatori spacea i stranice, verzija, stanje i napomene pretvorbe ostaju u tablicama kojima upravlja ovaj modul. Vidljivi su administratorima importa i ne dodaju Confluence-specifična polja u uobičajene forme područja.

## Poveznice

Pretvornik prepoznaje moderne `/spaces/SPACE/pages/ID/title`, stare `/display/SPACE/title`, `viewpage.action?pageId=ID` i URL-ove privitaka.

- Poveznice unutar istog spacea zamjenjuju se novom rutom područja.
- Fragmenti stranice ostaju sačuvani.
- Poveznice na već uvezeni space rješavaju se kroz mapiranja izvornih ID-eva.
- Poveznice na space koji još nije uvezen koriste stabilni resolver URL i čuvaju izvorno odredište.
- Svaki kasniji uspješni import pokreće usklađivanje u oba smjera.
- Vanjske web-poveznice ostaju nepromijenjene.

## Makroi i zadaci

Code, noformat, info, note, tip, warning i sadržaj stranice imaju sigurne HTML prikaze. Confluence task liste postaju read-only oznake liste zadataka u dokumentu. Nepodržani ili aplikacijski specifični makroi dobivaju vidljivi sigurni zamjenski prikaz i administratorsko upozorenje umjesto tihog nestanka.

Moduli Calendar i Task ostaju vlasnici živih kalendara i zadataka. Confluence makro ne pretvara se neprimjetno u živi poslovni objekt ako se njegovi potpuni podaci i ACL ne mogu sigurno mapirati; u tom slučaju u uvezenoj stranici ostaje statički prikaz.

## Komentari i privitci

Komentari se uvoze samo kada postoji Comment modul, ciljna stranica postoji i autor ima potvrđeno mapiranje korisnika. U suprotnom njihovi izvorni metapodaci ostaju dostupni administratoru importa.

Importer odabire traženu verziju privitka ili najvišu fizičku verziju prisutnu u ZIP-u. Nedostajuće ili neispravne datoteke prijavljuju se bez dopuštanja izlaska iz arhive.
