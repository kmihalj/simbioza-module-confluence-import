# Pretvorba sadržaja i poveznice

English version: [content-links_en.md](content-links_en.md)

## Stranice i verzije

Importer grupira Confluence objekte stranica prema logičkom ID-u sadržaja. Najnovija objavljena verzija postaje aktualni Simbioza dokument. Odnos roditelja ponovno gradi stablo stranica područja. Kada su odabrane, ranije objavljene verzije ulaze u povijest, nacrti ostaju nacrti, a obrisane stranice ostaju soft-obrisane kako bi ih administrator mogao vratiti.

Izvorni identifikatori spacea i stranice, verzija, stanje i napomene pretvorbe ostaju u tablicama kojima upravlja ovaj modul. Vidljivi su administratorima importa i ne dodaju Confluence-specifična polja u uobičajene forme područja.

Confluence XML export može zapisati završetak CDATA bloka kao `]] >` ili
`]] ]>` te koristiti HTML entitete koji nisu dio osnovnog XML-a. Importer
normalizira cijelo `plain-text` tijelo prije DOM pretvorbe. Time primjeri koda,
uključujući doslovni `<![CDATA[` tekst, ostaju kod umjesto da ostatak stranice
postane escapirani Confluence zapis.

## Poveznice

Pretvornik prepoznaje moderne `/spaces/SPACE/pages/ID/title`, stare `/display/SPACE/title`, `viewpage.action?pageId=ID` i URL-ove privitaka.

- Poveznice unutar istog spacea zamjenjuju se novom rutom područja.
- Fragmenti stranice ostaju sačuvani.
- Poveznice na već uvezeni space rješavaju se kroz mapiranja izvornih ID-eva.
- Poveznice na space koji još nije uvezen koriste stabilni resolver URL i čuvaju izvorno odredište.
- Svaki kasniji uspješni import pokreće usklađivanje u oba smjera.
- Vanjske web-poveznice ostaju nepromijenjene.

## Makroi i zadaci

Code, noformat, info, note, tip i warning strukture imaju sigurne HTML prikaze. Confluence task liste postaju read-only oznake liste zadataka u dokumentu. Nepodržani ili aplikacijski specifični makroi dobivaju vidljivi sigurni zamjenski prikaz i administratorsko upozorenje umjesto tihog nestanka.

Moduli Calendar i Task ostaju vlasnici živih kalendara i zadataka. Confluence makro ne pretvara se neprimjetno u živi poslovni objekt ako se njegovi potpuni podaci i ACL ne mogu sigurno mapirati; u tom slučaju u uvezenoj stranici ostaje statički prikaz.

### Pretvorba podržanih makroa

- `details` / Page Properties postaje nativni skup strukturiranih svojstava
  stranice. Podržani su okomiti raspored ključ-vrijednost i vodoravni raspored
  zaglavlja-vrijednosti. Korisničke reference koriste potvrđeno mapirano ime,
  a skrivena Confluence tablica ne duplicira se u tijelu dokumenta.
- `detailssummary` i `contentbylabel` postaju nativni **Izvještaj stranica**.
  Importer prenosi oznaku iz CQL-a, stupce, naziv prvog stupca, redoslijed i
  ograničenje rezultata. Kada su zatraženi stupci svojstava, prikazuje samo
  stranice koje doista imaju strukturirana svojstva. Izvještaj se nakon importa
  dinamički ažurira i svaki put ponovno primjenjuje ACL.
- `gallery` postaje nativna galerija stvarnih Editor privitaka aktualne stranice.
- `livesearch` i `pagetreesearch` postaju nativna pretraga ograničena na
  uvezeno područje.
- `recently-updated` postaje ACL-siguran popis nedavnih objavljenih promjena.
- `panel` postaje tematska kartica. Stari `section` i `column` makroi postaju
  responzivni red kartica: postotne širine preslikavaju se na Bootstrap mrežu,
  a na uskom zaslonu svaki stupac zauzima cijelu širinu.
- `expand` postaje statički blok s izvornim naslovom iznad tijela. Vrsta liste
  se ne mijenja: `ul` ostaje lista s grafičkim oznakama, a `ol` numerirana lista.
- `html` s jednim sigurnim HTTPS iframeom postaje kanonski Editor embed širine
  100%. Čuva visinu, `allowfullscreen` i ograničene `allow` mogućnosti. Službeni
  H5P resizer prepoznaje se i kontrolirano učitava u pregledu i izvozu. Druga
  skripta ne izvršava se i cijeli makro ulazi u izvještaj za ručnu provjeru.
- `profile` postaje statički prikaz mapiranog Auth imena. Ako je administrator
  izradio neaktivan predračun, importer koristi sigurno izvedeno ime umjesto
  sirove login oznake. Prikaz ne oponaša Confluence profil ni njegovu autorizaciju.
- `children` postaje popis lokalnih poveznica na neposrednu djecu; uz Confluence opciju `all=true` uključuje i potomke.
- `pagetree` postaje hijerarhijski popis lokalnih stranica od zadanog korijena; `@self` znači trenutačnu stranicu.
- `attachments` postaje read-only popis stvarno uvezenih privitaka.
- `multimedia` postaje siguran HTML audio/video pregled kada je privitak uvezen.
- `view-file` postaje obična poveznica na isti lokalni, ACL-zaštićeni privitak
  kada fizička datoteka postoji u arhivi.
- `tableenhancer` čuva izvornu tablicu kao običan responzivni HTML jer dodatak
  ne sadrži zaseban poslovni objekt. Ta tablica, kao i svaka obična uvezena
  tablica, dobiva standardne obrubljene i prugaste klase HTML Editora, hover
  stanje i responzivni omotač, pa slijedi aktivnu temu, a širok se sadržaj pomiče
  unutar stranice.
- `status` i `anchor` postaju nativna oznaka i HTML sidro.
- `toc` se ne umeće u uvezeni dokument jer Simbioza gradi vlastitu tablicu sadržaja iz naslova stranice.
- `include` postaje Editorova nativna dinamička referenca **Uključi sadržaj stranice**. Importer forward reference unutar istog spacea razrješava nakon izrade svih stranica, odmah povezuje već uvezeni vanjski space te staru neriješenu referencu usklađuje kada se njezin space uveze kasnije. Sadržaj ostaje dinamičan, a ACL ciljne stranice ponovno se provjerava pri svakom pregledu.
- `chart` postaje Editorov nativni uređivi grafikon kada makro sadrži
  upotrebljivu tablicu. Importer mapira vrstu, orijentaciju, odabrane stupce,
  naslov, nazive osi, legendu i Confluence 3D opciju. Neispravni ili nepotpuni
  izvorni podaci zadržavaju tablicu kao siguran statički prikaz i stvaraju
  napomenu u izvještaju.
- `roadmap` postaje Editorov nativni uređivi **Vremenski plan**. Importer mapira
  razdoblje, dnevno/tjedno/mjesečno/tromjesečno mjerilo, grupe i boje, trake
  aktivnosti, opise, oznake i sigurne HTTP(S) poveznice. Neispravan Roadmap
  Planner JSON zadržava vidljivi zamjenski prikaz i stvara napomenu u izvještaju.
- `widget` pretvara samo providere za koje postoji izričita sigurna politika.
  YouTube koristi responzivni `youtube-nocookie.com` iframe s ograničenim
  mogućnostima, a Figma i Twitter/X postaju tematske kartice s vanjskom
  poveznicom. Nepoznati provider zadržava vidljivi zamjenski prikaz i stvara
  napomenu u izvještaju. Skripte providera nikada se ne kopiraju iz Confluencea.
- `create-from-template` s Confluence file-list blueprintom izostavlja se jer
  njegova Confluence uređivačka akcija nije primjenjiva na uvezenu stranicu.
- `content-report-table` postaje obična uređiva HTML tablica s odgovarajućim
  poveznicama poznatim u trenutku importa. To nije predložak ni dinamička
  Workspace komponenta. Izvorne oznake ostaju prenosivi metapodaci importa.

Confluence rasporedi s dva ili tri stupca postaju responzivni Bootstrap retci i
stupci. Code/noformat blokovi čuvaju opcionalni naslov i sigurnu oznaku jezika,
uvezenim slikama ostaju numeričke naznake širine/visine, a složene oznake
poveznica ostaju čitljive. Nativna tablica sadržaja Simbioze i dalje koristi naslove uvezene stranice.

Ostali makroi koji stvaraju dinamički Confluence sadržaj ili uređivačke akcije ostaju jasno označeni kao nepodržani; izvorni podaci ne gube se tiho.

## Komentari i privitci

Komentari se uvoze samo kada postoji Comment modul, ciljna stranica postoji i
autor ima potvrđeno mapiranje korisnika. Komentar privitka pripaja se stranici
kojoj taj privitak pripada; povijesni komentar koji nije moguće jednoznačno
vezati uz stranicu preskače se i ulazi u završni sažetak. U ostalim slučajevima
izvorni metapodaci ostaju dostupni administratoru importa.

Importer odabire traženu verziju privitka ili najvišu fizičku verziju prisutnu u ZIP-u. Provjerenu binarnu datoteku privremeno čuva samo do izrade ciljnog dokumenta, zatim je s istim stabilnim UUID-om registrira kao nativni Editor privitak i uklanja privremenu kopiju. Sadržaj stranice i spajalica zato upućuju na isti ACL-zaštićeni asset. Nedostajuće ili neispravne datoteke prijavljuju se bez dopuštanja izlaska iz arhive.

Svaki dovršeni posao čuva izvještaj u popisu **Nedavni Confluence importi**. Nepodržani makroi grupirani su po ciljnoj stranici, a svaki red izvještaja vodi izravno na tu stranicu u Simbiozi. Prazan izvještaj izričito potvrđuje da sadržaj ne zahtijeva ručnu provjeru.
