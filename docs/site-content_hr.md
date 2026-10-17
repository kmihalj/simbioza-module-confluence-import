# Uvoz odabranih područja Confluence sitea

[English version](site-content_en.md)

Ova neobjavljena razvojna mogućnost na HFC-u proširuje postojeći import jednog
područja, bez promjene njegova postupka. [Kalendari](site-calendars_hr.md) podešavaju
se odvojeno, ali izvršavaju unutar iste završne potvrde sitea.

## Pregled i pokretanje

1. Prenesite XML ZIP sitea ili više područja i pričekajte [inventar](site-inventory_hr.md).
2. Odaberite potrebna područja i kliknite **Spremi odabir područja**. Sam odabir
   ne stvara sadržaj. Nije potrebno odabrati sva područja iz izvoza sitea.
3. Pripremite [identitete](site-identities_hr.md) i podesite željene korisnike,
   grupe i članstva. Spremanje postavki ih ne stvara. Zajednički pregled može
   provjeriti planiranog novog neaktivnog korisnika bez izmišljanja brojčanog ID-ja.
   Izvršavanje primjenjuje identitete prije sadržaja i kalendara. Nemapirani subjekti
   ACL-a ne dobivaju pristup; neodabrani autori zamjenjuju se uvoziteljem.
4. Glavnu adresu izvornog Confluencea unesite jednom, u odjeljku identiteta,
   uključujući podputanju aplikacije ako postoji. Sve sekcije koriste isti izvorni
   prostor mapiranja. Druga adresa znači drugi prostor mapiranja.
   Raniji samostalni import jednog područja zadržava svoje ciljeve i mapiranja.
   Reference ga smiju koristiti samo kada mu spremljena glavna adresa izvora
   točno odgovara nakon normalizacije. Isti naziv ili brojčani ID nije dovoljan;
   nepoznati izvori i drugi Confluence siteovi ostaju odvojeni.
5. Provjerite naziv, slug i radnju svakog cilja. **Novo područje** služi nemapiranom
   izvoru; ranije mapirani izvor zahtijeva **Zamijeni ranije uvezeno područje** ili
   **Nova kopija**. Zamjena trajno briše taj mapirani cilj, uključujući lokalne
   izmjene, prije ponovne izrade. Ne može ciljati nepovezano područje. Kopije imaju
   odvojena mapiranja i povezuju se na pripadajuće kopije ako se uvoze zajedno.
6. Odaberite privitke/komentare prema potrebi (oboje je zadano uključeno). Povijest,
   nacrti i obrisane stranice zadano su isključeni. Povijest obuhvaća stranice i
   privitke. Osobno područje zahtijeva odabranog vlasnika (postojećeg ili planiranog
   neaktivnog korisnika). Više osobnih izvora može pripadati istom ciljnom korisniku.
   Za svaki takav izvor, kao i za postojeće osobno područje, odaberite **Dodaj samo
   nove stranice; postojeće ostaju nepromijenjene**. Grupirani pregled vlasnika nudi
   gumb za postavljanje te radnje svim prikazanim izvorima istog vlasnika.
   Osobne kopije nisu dopuštene.
7. **Spremi postavke sadržaja** sprema samo konfiguraciju. Podesite kalendare prema
   potrebi pa odaberite **Pregledaj cijeli uvoz** i **Potvrdi i pokreni cijeli uvoz**.
   Pregled vezan uz vlasnika vrijedi 30 minuta i provjerava sve odabrane sekcije
   prije poslovnih upisa. Promjene zahtijevaju novi zajednički pregled.

## Neaktivni vlasnici i skrbništvo administratora

Osobni izvor inače zahtijeva mapiranog vlasnika, uključujući namjerno izrađenog
neaktivnog arhivskog korisnika. Ako umjesto toga preskočite poznatog neaktivnog
vlasnika, u postavkama tog područja odaberite **Zasebno arhivsko područje pod
upravom administratora**. To je izričit odabir za svako područje, nikada zadani.
Dokaz mora biti izvezena neaktivnost računa/imenika ili isključivo povijesna
referenca autora; nepoznat ili aktivan vlasnik ne smije se prešutno zamijeniti.

Skrbništvo stvara zasebno ograničeno obično područje za svaki odabrani izvor.
Ne dodaje stranice svih bivših korisnika u administratorovo osobno područje,
ne stvara mapiranje identiteta na administratora ni prijenos članstava kroz takvu
zamjenu. Naziv i oznaka izvora, izvorni vlasnik i metapodaci autora ostaju u uvozu
i izvještaju; nemapirani autori i uređivači u Simbiozi koriste izvršitelja uvoza.
Mapirani autori zadržavaju mapirano autorstvo. Ostale izričito mapirane izvorne
ovlasti i dalje se pretvaraju uobičajeno, bez dodjele pristupa nemapiranima.

Koristite **Novo područje** (ili **Nova kopija** za ranije uvezen izvor). Skrbništvo
ne smije dodavati u osobno područje, zamijeniti postojeći cilj ni nadjačati
odabranog/potvrđenog vlasnika. Postojeći sadržaj ostaje netaknut. Zajednički pregled
provjerava te granice prije upisa. Ovaj odabir sadržaja ne prenosi osobne kalendare:
zasebno im odaberite valjanog vlasnika ili ih nemojte odabrati za uvoz.

## Dodavanje u postojeće osobno područje

Postavke odabranih područja imaju zasebnu pretragu i paginaciju s 25/50/100 redaka,
iznad i ispod tablice. Pretraga obuhvaća izvorni naziv/ključ te uređeni ciljni naziv
i slug. Listanje/filtriranje čuva nespremljene nazive, slugove i radnje sa svih
stranica. **Spremi postavke sadržaja** sprema cijeli odabrani nacrt, ne samo vidljivu
stranicu. Ako obavezni naziv/slug nedostaje na drugoj stranici, provjera prikazuje
taj redak prije slanja umjesto da ga prešutno izostavi.

Ako stari/novi izvorni login pripadaju istoj osobi, najprije ih povežite u
[pregledu identiteta](site-identities_hr.md#dupli-izvorni-računi). Njihova odabrana
osobna područja tada se prikazuju pod **Više osobnih područja istog vlasnika**.
Odaberite **Postavi dodavanje za ova osobna područja**, spremite i pregledajte
zajednički uvoz. Novi vlasnik dobije jedno osobno područje; kasniji izvori dodaju
sadržaj u isti cilj. Svaki izvor zadržava svoje mapiranje i detaljni izvještaj, pa
se poveznice obaju izvornih ključeva razrješavaju na zajedničko područje.

Za svakog člana grupe obavezno je dodavanje, nikada zamjena/kopija. Izvršavanje veže
kasniji izvor samo uz dovršeni raniji podposao provjerenog vlasnika u istom postupku.
Nepovezani/istodobno promijenjeni cilj odbija se. Ponavljanje istih izvora čuva stare
stranice, ID-jeve dokumenata i URL-ove privitaka; nove izvorne stranice dobivaju
slobodne slugove, ne prepisuju se prema naslovu.

Dodavanje koristi nativno osobno područje mapiranog vlasnika; ne zamjenjuje ga.
Naziv, slug, početna stranica, meniji, teme, ovlasti i sadržaj postojećih stranica
ostaju nepromijenjeni. ACL izvornog područja ne prepisuje ACL postojećeg područja,
pa njegova postojeća granica pristupa vrijedi i za nove stranice.

Ranije uvezene stranice prepoznaju se po potvrđenom Confluence izvornom identitetu,
ne po naslovu. Njihov HTML, verzije, metapodaci, komentari i privici ne nadograđuju
se čak ni ako ih je novi izvoz promijenio. Uvoze se samo nove izvorne stranice i
njihovi odabrani privici/komentari. Nova podstranica može imati ranije uvezenog
roditelja. Nativna stranica istog naslova ostaje sačuvana, a nova izvorna stranica
dobiva slobodan slug. Ponovljeni uvoz iste arhive ne dodaje ništa. Obrisana osobna
područja i nedosljedna mapiranja zahtijevaju pregled, ne prešutno vraćanje ili
zamjenu. Izvještaj zasebno broji sačuvane izvorne i novouvezene stranice; nepovezane
nativne stranice ne broje se kao uvezene.

Potvrda sadrži otisak cilja. Neuspjelo dodavanje nikada ne pokreće zamjenu/ponovnu
izradu cijelog područja. Prije novog uvoza pregledajte samo nove objekte toga
pokušaja; čišćenjem nikada ne uklanjajte postojeće osobno područje ili nepovezane
stranice. Uobičajeni uvoz jednog područja ostaje nepromijenjen.

## Privici, sadržaj i izvještaji

Zajednički indeks čuva samo odabrana područja i tražene verzije sadržaja. Postojeći
pretvarač uvozi stablo, stranice, povijest, komentare, ACL, makroe, pregled vanjskih
skripti i izvorne privatne privitke Editora. Binarne putanje zadržavaju Confluence
ID-eve stranice/privitka/verzije; izvorni ZIP ne prepravlja se. Sigurne poveznice
stranica i privitaka obrađuje postojeći pretvarač/resolver. Poveznice između područja
razrješavaju se unutar istog izvora, uključujući one čiji se cilj uveze kasnije i
ranije uvoze istog sitea. Njihovi postojeći izvještaji prikazuju novo stanje linkova.
Poveznice prema još neuvezenim područjima ostaju u uobičajenom izvještaju neriješenih
poveznica. Pravilo istog potvrđenog izvora omogućuje oba smjera poveznica između
samostalnog i site uvoza bez prepisivanja ključeva mapiranja. Raniji izvještaji
područja prikazuju naknadna razrješenja. Kopije ostaju odvojene i nikada ne koriste
izvornike kao zamjenski cilj. Povezivanje referenci ne znači prešutno preuzimanje
samostalnog cilja za zamjenu sadržaja.

Izvoz bez binarnih privitaka može sadržavati njihove XML zapise. Uvoz sadržaja
nastavlja se uz jasno upozorenje o izvoru; XML zapisi ne prikazuju se kao prenesene
datoteke. Ako binarne datoteke postoje, vrijede postojeća pravila privitaka/povijesti
i ACL-a stranice. Djelomično popunjena arhiva prijavljuje stvarno nedostajuće aktualne
datoteke u postojećem izvještaju pojedinog područja.

Roditeljski izvještaj povezuje uobičajeni izvještaj svakog podposla, s privicima,
poveznicama, makroima i odlukama o skriptama. Vanjske skripte ne zaobilaze registar
Editora. Uvoz identiteta ne daje globalne administratorske ovlasti niti mijenja
providere prijave; `confluence-administrators` ostaje obična grupa.

## Napredak i oporavak

Prvi sadržajni korak izrađuje privatni indeks kroz dva zajednička prolaza
`entities.xml`: vlasništvo metapodataka, zatim odabrani sadržaj. **Ne** raspakirava
cijeli XML niti zadano zapisuje sva povijesna tijela na disk. Veliki izvori mogu
trajati nekoliko minuta; uskladite PHP/FPM i proxy timeout s
`import_execution_time_limit` te omogućite `import_memory_limit_mb` (zadano 512 MiB).
Zaključani niži FPM memorijski limit prijavljuje se prije indeksiranja. CLI `inspect`
ostaje samo pregled inventara, ne nova naredba za pokretanje uvoza sadržaja.

Nakon indeksiranja svako područje ima zaseban nastavivi podposao. Preglednik prikazuje
broj dovršenih/ukupnih područja i napredak trenutačnog podposla. **Nastavi cijeli uvoz**
nastavlja roditeljski posao nakon osvježavanja ili izgubljenog zahtjeva. Paralelna
obrada istog roditelja zaključava se. Potpuni indeks vezan uz izvor i odabrana pravila
može se ponovno koristiti; prekinuto početno indeksiranje kreće ispočetka.

Izričito neuspjeli zajednički posao zaustavlja se i čuva dovršene izvještaje i
djelomični sadržaj; ne prikazuje se kao uspješan uvoz. Ne popravlja djelomično
uvezene retke i ne ponavlja neuspjelu komponentu prešutno. Pri razvojnoj provjeri
uklonite samo objekte tog pokušaja, ispravite postupak i pokrenite novi uvoz.
Odustajanje nije dostupno nakon završne potvrde.

Roditelj posjeduje i čuva zajednički ZIP i privatne `*.zip.content/` podjele do
dovršetka zajedničkog postupka i povezivanja. Dovršeni ili obrisani podposao ne
briše zajednički izvor ni susjede. Ne uklanjajte ih ručno dok obrada traje. Privremeni
indeksi, izvršivi tokeni pregleda i lokalni ID-jevi područja/roditelja za dodavanje
nisu prenosivi backup podaci; trajna mapiranja i dovršeni izvještaji ostaju
obuhvaćeni postojećim backup providerima.

Uključivanje povijesti za mnogo područja i dalje može tražiti znatno privatnog prostora:
tražena povijesna tijela stvarni su ulaz migracije, a ne nepotrebna međudatoteka
cijelog sitea. Provjerite slobodan prostor prije uključivanja te opcije.

## Granica provjere

Testovi pokrivaju više područja, prisutne/izostavljene datoteke privitaka, povijest,
međusobne poveznice, kopije, izričito ponavljanje, vlasništvo i nepromijenjeni import
jednog područja. Stvarna dostavljena site arhiva **nema binarne privitke**; budući
site izvozi s privicima provjeravaju se izoliranim primjerima, ne prikazuju se kao
provjereni na toj snimci. Kalendari koriste C4; završna provjera više baza/cijelog sitea ostaje C5.
