# Uvoz kalendara iz Confluence sitea

[English version](site-calendars_en.md)

## Postupak

1. Otvorite pripremljeni **site** import u Postavke → Confluence import. Postojeći
   uvoz jednog područja i njegovo ručno povezivanje ICS-a/kalendara ostaju isti.
2. Pripremite korisnike/grupe i podesite mapiranja. Spremljeni pregled nije uvoz.
   Identiteti se izvršavaju prvi u zajedničkom potvrđenom postupku sitea.
3. U odjeljku **Uvoz kalendara sitea** odaberite **Pripremi pregled kalendara**.
   Jednim prolazom čita se Active Objects zapis i sprema privatni potpuni pregled.
   Time se ne stvaraju kalendari, računi, grupe, pretplate ili događaji.
   Prikaz napretka pokazuje provjeru arhive, čitanje, raspoređivanje događaja i
   dovršavanje pregleda. Tijekom čitanja prikazuju se obrađeni zapisi i proteklo
   vrijeme, ne izmišljeni ukupni postotak. Raspoređivanje ima izmjereni broj i
   postotak. Pregled se prikazuje automatski kada završi. Osvježavanje nastavlja
   praćenje aktivne pripreme; ponovljeni klik ne pokreće novo čitanje. Prekinutu ili
   neuspjelu pripremu može se ponoviti umjesto beskonačne poruke o čekanju. Privremeni
   prekid veze ponavlja provjeru napretka, nikada samu pripremu.
4. Koristi se glavna adresa unesena u odjeljku identiteta. Filtrirajte/odaberite
   kalendare neovisno o odabranim područjima. Za svaki odabrani redak izaberite
   **Osobni kalendar** ili **Timski kalendar**. Osobni kalendar traži izričit odabir
   vlasnika. Pretražite birač vlasnika po imenu, korisničkoj oznaci ili emailu;
   dohvaća najviše 25 rezultata po zahtjevu uz **Učitaj još**, a postojeće korisnike
   pretražuje i paginira u bazi. Uključuje postojeće neaktivne račune i nove neaktivne
   račune već odabrane u postavkama identiteta. Postojeći lokalni korisnik može se
   odabrati bez mapiranja izvornog identiteta. Time se određuje samo vlasništvo
   kalendara: ne stvara se mapiranje identiteta niti mijenjaju prijava/admin postavke. Stvaratelj
   kalendara nije nužno njegov osobni vlasnik i ne odabire se unaprijed kao vlasnik.
   Nazivi poput „službeni”, „privatni” i „osobni” te povezanost s osobnim područjem
   samo predlažu vrstu **Osobni**; nejasni retci traže izričit odabir vrste umjesto
   zadane vrste **Timski**. Nijedan kalendar
   nije automatski odabran. „Odaberi vidljive” vrijedi samo za trenutačnu filtriranu
   stranicu; **Odaberi sve filtrirane kalendare** uključuje podudaranja sa svih stranica.
   Zasebna paginacija s 25/50/100 redaka iznad/ispod tablice ima ograničeni prozor
   brojeva s trotočkama. Pretraga i filtar vlasništva primjenjuju se prije listanja.
   Brojači odabira uključuju ostale stranice, a nespremljene vrste/vlasnici ostaju
   pri navigaciji. Spremanje šalje cijeli odabrani nacrt kalendara, ne samo vidljivu stranicu.
5. **Spremi postavke kalendara** sprema samo odabire. Odaberite **Pregledaj cijeli
   uvoz**, otvorite detalje kalendara i provjerite podržane/preskočene događaje,
   vlasnike, ovlasti i upozorenja prije **Potvrdi i pokreni cijeli uvoz**.
6. Postupak stvara/mapira identitete, zatim kalendare i područja. Svaki kalendarski zahtjev kroz Calendar skupni ICS API potvrđuje
   najviše 250 događaja. Ostavite stranicu otvorenom. Nakon prekida ponovno otvorite
   isti posao i odaberite **Nastavi cijeli uvoz**; potvrđeni paketi ne ponavljaju se.
7. Povezivanje kalendara automatski slijedi sadržaj. Obrađuju se oznake kalendara u
   dovršenim vlastitim izvještajima istog provjerenog izvora, uključujući ranije
   uvoze. Kasniji uvoz samo kalendara može zato povezati ranije stranice. Postojeći resolver
   objavljuje novu verziju stranice; nacrti ili promijenjene oznake ostaju za ručnu
   provjeru. Ne mijenjaju se proizvoljne stranice na siteu.

Calendar je opcionalan. Bez njega uvoz kalendara nije dostupan, ali obični uvoz
sadržaja nastavlja raditi. Uz ovu C4 implementaciju koristite razvojni Calendar
kod: skupni API sada čuva `TZID` i prihvaća opcionalni `event_type_name`. Za C4
još nije objavljen release.

## Stranice s više kalendara u jednom makrou

Izvorni makro `calendar` može imati više ID-jeva kalendara odvojenih zarezima.
Pretvorba čuva izvorni redoslijed, uklanja ponovljene reference i stvara zasebnu
stavku izvještaja/oznaku za svaki izvorni ID. Cijeli popis ne smatra jednim ID-jem
kalendara niti prazan odabir zamjenjuje pretplatama gledatelja. ID ne mora imati
oblik UUID-a neke određene instalacije; pojedina referenca ograničena je na 190
sigurnih znakova identifikatora, najviše 1.000 različitih odabira. Nevaljani ili
preveliki odabiri ostaju vidljivi za ručnu provjeru.

Povezane reference istog izvornog makroa skupljaju se u **jedan nativni Calendar
blok**, uz podržani mjesečni, tjedni ili dnevni prikaz (`month`, `week`, `day`).
Nedostajuće/obrisane reference ostaju zasebne vidljive oznake i neriješene stavke
izvještaja; ne blokiraju povezivanje susjednih kalendara. Kasniji uvoz istog izvora
može ih povezati bez dupliciranja postojećeg bloka. Različiti makroi ostaju odvojeni.
Nepodržane mogućnosti prikaza, primjerice skrivanje vikenda, ne prikazuju se kao
prenesene; vrijedi ponašanje nativnog Calendara.

Povezivanje i dalje provjerava objavu, pravo čitanja i izvornu oznaku. Neobjavljen
nacrt ili promijenjena/nestala oznaka zahtijeva ručnu provjeru, ne prepisivanje.
Nazivi su kodirani opcionalni tragovi prikaza; vrlo velik skup naziva može se
izostaviti uz očuvanje cijelog odabira UUID-eva. Prikaz i provjera pristupa koriste
aktualne podatke Calendara i ovlasti gledatelja. Pretvorba ni povezivanje makroa
ne stvaraju pretplate.

## Što se prenosi

### Pregled predloženih osobnih vlasnika

Zasebni [pregled prijedloga mapiranja](site-mapping-review_hr.md) prikazuje nedovršene
odabire kalendara sa spremljenom/predloženom vrstom, razlogom i dokazima kandidata.
Bez upisa odvaja zajedničke dvorane/vozila/timske resurse od osobnih kalendara,
bez korištenja ugradnje na osobnu stranicu kao dokaza vlasništva.

**Potrebna potvrda vrste ili vlasnika** prikazuje retke bez odabrane vrste ili s
osobnom vrstom, a bez odabranog vlasnika. Prikaz se odmah ažurira pri promjeni
odabira; ne dodjeljuje nedostajuće vlasnike. Najprije povežite duple izvorne račune
u postavkama identiteta: izričite veze na istog novog/postojećeg korisnika uklanjaju
lažne prijedloge više vlasnika bez oslanjanja na kreatora kalendara. Stvarne
dvosmislenosti i slabi tragovi i dalje traže korisničku odluku prije uvoza.

Tablica kalendara prikazuje **Predloženi vlasnik i razlog**, uz filtre za osobne
kandidate, jednoznačne mapirane prijedloge, planirane račune, nemapirane korisnike,
dvosmislena podudaranja, slabe tragove osobnog područja i nedostajuće prijedloge.
Pretraga obuhvaća nazive kalendara, predložene kandidate i odabranog vlasnika, ne
sve skrivene opcije svakog odabira vlasnika. Brojač vidljivih redaka radi i bez uređivanja.

Prijedlozi uspoređuju puna imena, korisničke oznake i emailove koji se kao cijeli
dijelovi pojavljuju u nazivu kalendara s izvornim imenikom. Veličina slova,
razdjelnici i hrvatski dijakritici normaliziraju se samo za prijedloge, nikada za
mapiranje identiteta računa. Imena mogu biti jednaka: različiti ciljni korisnici
ostaju dvosmisleni. Dva odabrana izvorna identiteta istog cilja nisu lažni sukob.
Izvorni kreator kalendara nikada nije dokaz osobnog vlasništva. Veza s jednim
osobnim područjem samo je slab trag jer stranica može ugrađivati tuđi kalendar.

**Prihvati prijedlog vlasnika** popunjava samo vlasnika/vrstu. Ne uključuje kućicu
kalendara, ne stvara račun, ne daje prava i ne pokreće uvoz. Skupni gumb primjenjuje
samo jednoznačne mapirane/planirane prijedloge vidljivih redaka i ne zamjenjuje već
odabranog vlasnika. Za gumb prijedloga nemapiranog izvornog kandidata prvo treba
mapirati/izričito planirati u odjeljku identiteta. Druga je mogućnost izričito odabrati
postojećeg lokalnog vlasnika u pretraživom biraču. Slabi/dvosmisleni prijedlozi traže ručnu provjeru i odabir.
Završni zajednički pregled i potvrda i dalje su obavezni.

Dovršeni uvozi prikazuju istu tablicu bez mogućnosti uređivanja dok je sačuvan
privatni izvorni indeks; raniji odabir vlasnika i aktualni prijedlog odvojeni su
stupci. Novi prijedlog ne prepisuje povijesne odabire/rezultate. Za nova potvrđena
mapiranja pripremite novi GUI uvoz. Uklanjanje radne arhive/indeksa uklanja mogućnost
ponovnog izračuna prijedloga, ne trajne detaljne izvještaje.

Calendar čuva naslove događaja do 765 Unicode znakova i lokacije do 510 znakova.
Duže izvorne vrijednosti navode se kao preskočeni događaji s razlogom u
pregledu/izvještaju; nikada se prešutno ne skraćuju niti šalju u neuspjeli SQL upis.
Pri nadogradnji postojećeg sitea primijenite pripadajuću migraciju Calendar sheme.

Podržane tablice `AO_950DC3_TC_*` opisuju nativne kalendare, podkalendare tipova
događaja, događaje, iznimke ponavljanja, ograničenja pristupa i veze s područjima.
Interne pretplate zamjenski su identifikatori izvornika, ne duplikati kalendara.
Vanjski/Jira izvori ne dohvaćaju se. Vjerodajnice, postavke dodataka, podsjetnici,
URL-ovi vanjskih pretplata i nepovezane AO tablice ne spremaju se u pregled.
Mapiranja korisnika/grupa dolaze isključivo iz potvrđenih C2 veza; C4 ne stvara
ili aktivira račune, ne mijenja Auth ili SSO.

Nativni izvorni kalendar postaje **osobni ili timski, prema izričitom odabiru u
pregledu**; naziv ili stvaratelj sami ne određuju vrstu. Osobni traži izričit odabir
postojećeg lokalnog računa ili izvornog korisnika mapiranog na postojeći ili
planirani novi neaktivni račun. Izvršavanje potvrđuje stvarni ID prije stvaranja kalendara.
Poslužitelj to ponovno provjerava i za ručno sastavljene zahtjeve: administrator
koji uvozi nikada ne zamjenjuje nedostajućeg vlasnika. Redak prikazuje oznaku
odabranog vlasnika, a pregled i trajni izvještaj bilježe odabranu vrstu. Promjena
odabira poništava aktualni pregled pa ga treba ponoviti. Osobni kalendar koristi
standardni prilagođeni naziv Calendara s prefiksom vlasnika i stabilnim kratkim
nastavkom izvornog ID-ja radi izbjegavanja sudara jednakih uvezenih naziva.

Mapirani stvaratelj dobiva čitanje/uređivanje. Izričita mapirana VIEW ograničenja postaju prava čitanja.
EDIT pravila primjenjuju se samo kada ne zaobilaze posebna VIEW ograničenja.
Nepoznati ili nemapirani identiteti prijavljuju se i ne dobivaju prava. Administratori
kalendara i zaštićena grupa Kalendari zadržavaju uobičajeni pristup. Nedostatak izvornih ograničenja **ne**
uključuje javni pristup ili pristup svim prijavljenima. Posebni ACL podkalendara,
koji se ne može predstaviti jednim nativnim kalendarom, ostavlja objedinjeni kalendar
dostupnim samo upraviteljima kalendara radi provjere. Ponovni uvoz ne prepisuje postavke/ACL
postojećeg uvezenog kalendara.
Postojeći cilj druge vrste ili drugog osobnog vlasnika odbija se, ne pretvara se
niti prenosi drugom vlasniku. Ispravite odabir ili izričito uredite/uklonite cilj
prije novog uvoza; ne popravljajte djelomično uvezene retke događaja.

Čuvaju se UID-jevi događaja, obični tekst opisa/lokacije, isključivi završni datumi
cjelodnevnih događaja, vremenske zone, podržana ponavljanja, EXDATE i premještene
instance. Oznake izvornih tipova koriste se kao nativni tipovi bez stvaranja tipa
za svaki događaj. ICS tekst je kodiran; HTML se ne izvršava. Uvezeni događaji vode
se na administratora koji uvozi. Izvorni organizatori/sudionici ne pretvaraju se u
aktivne pozivnice za sastanke ni korisničke postavke praćenja. Administrator koji
uvozi ne pretplaćuje se na uvezene kalendare samo zato što ih smije čitati ili
njima upravljati. Osobni vlasnik zadržava uobičajenu vlasničku pretplatu. Ostali
kalendari dostupni su u administraciji ili izričitim odabirom pretplate; ne ulaze
u **Moje kalendare** samo zato što su čitljivi.
Čuvaju se izvorne heksadecimalne boje i Confluenceova imenovana paleta
`subcalendar-*`; nepoznata boja koristi zadanu uz upozorenje u pregledu/izvještaju.
Ne šalju se povijesne
pozivnice, podsjetnici ni pojedinačne obavijesti događaja.

Postojeći nativni mehanizam podržava dnevne/tjedne/mjesečne/godišnje obrasce.
C4 prihvaća samo obrasce koje može vjerno ponoviti, uključujući odgovarajući jedan
tjedni dan i podržane redne mjesečne dane. Nepodržane klauzule, dvosmisleni dvostruki
UID-jevi, iznimke bez serije i neusklađeni datumi preskaču se uz razlog, ne pretvaraju
se tiho u drugačije događaje. Confluence `COUNT=-1` znači neograničeno i uklanja se.
Upozorenja navode najviše 200 primjera ID-jeva događaja po kalendaru; ukupno brojanje
preskočenih obuhvaća sve zapise. Zadržite izvornu arhivu za ručnu obradu tih zapisa.
Kalendar opravdano može uvesti nula događaja.

## Dosljednost, spremanje i backup

Pregled vrijedi 30 minuta i vezan je uz administratora, hash izvora, potvrđena C2
mapiranja i snimku postojećih ciljnih kalendara. Promjene zahtijevaju novi pregled.
Nakon potvrde neovisno uređivanje/izvršavanje komponenti blokirano je za isti posao. Tijekom uvoza
ne mijenjajte mapirane korisnike/grupe. Neuspješan paket zajedno poništava cilj,
događaje, izvorna mapiranja i pokazivač napretka. Ponovljeni uvoz iste izvorne
adrese/kalendara koristi mapirani kalendar i preskače postojeće UID-jeve; ne
prepisuje ručno uređene događaje niti briše događaje kojih nema u kasnijem izvozu.
To je migracija, ne trajna sinkronizacija.

Neočekivana greška zaustavlja zajednički posao i čuva dovršene/djelomične izvještaje.
Ne poništava ranije potvrđene pakete. Pri razvojnoj provjeri uklonite samo objekte
tog pokušaja, ispravite postupak i pokrenite novi uvoz.

Privatni indeksi nalaze se uz upravljane prijenose u
`data/confluence-import/uploads/<managed>.zip.calendars/`, nikada u `HPH_CONFIG_PATH`.
Konfiguracija ostaje u `HPH_CONFIG_PATH/confluence-import/`. Javno preuzimanje
indeksa nije dostupno. Limiti uključuju 2 milijuna odabranih AO redaka, 20.000
podkalendara, 512 MiB zadržanih događaja, 64 MiB/100.000 izvornih događaja po kalendaru
te ograničene XML vrijednosti/JSON zapise. Cijeli višegigabajtni XML ne raspakirava
se. Nepotpuno čitanje kreće ispočetka, a potpuni indeks ponovno se koristi.
Ponovno pripremite pregled za obnovu nedostajućih/oštećenih dijelova. Zaključavanje
pripreme sprječava istodobnu obnovu.
Susjedni `.calendars.progress.json` sadrži samo sažetak izvora, fazu, brojače i
vrijeme početka, nikada osobe/događaje ili vjerodajnice. Atomski snimci imaju
ograničenu učestalost upisa; aktivno zaključavanje, ne proizvoljno vrijeme ili
samo postojanje datoteke zaključavanja, određuje traje li priprema. Te privremene
datoteke uklanjaju se s upravljanim izvorom i nisu prenosivi backup podaci.

Tablica importera `simbioza_confluence_import_calendars` veže hash izvornog sitea i
ID kalendara/podkalendara uz nativni ID. Instalacija početne sheme stvara i tu tablicu
kada se ponovno pokrene na postojećoj razvojnoj instalaciji:

```sh
php vendor/bin/hph simbioza-confluence-import:install-migration
php vendor/bin/hph orm-migrate:up
php vendor/bin/hph orm-migrate:status
```

Prije testiranja provjerite status migracija. Auth/framework migracije nisu potrebne.
Izvještaj je zaseban dovršeni posao `site_calendars` i ostaje sačuvan čak i prije C3.
Backup cijelog sitea/komponente prenosi trajna mapiranja i izvještaje, uz mapiranje
Calendar/Auth/job ID-jeva na odredištu. Pri vraćanju mapiranja uključite Calendar
komponentu. Stvarni kalendari/događaji pripadaju nativnom Calendar backupu; ugradnje
koriste stabilne UUID-jeve. Privremeni indeksi, tokeni pregleda, lokalni C2 izvršni
odabiri i putanje izvornih ZIP-ova nisu prenosivo stanje.

## Provjere

Ciljani fixturei pokrivaju odbijanje DTD-a, filtriranje tajni drugih dodataka,
hijerarhiju i zamjenske identifikatore, promjenu indeksa, pretvaranje zona/datuma/
ponavljanja, C2 vlasnike/grupe, vlasništvo pregleda, transakcijsko poništavanje,
nastavak i ponovni uvoz. Potpuna potrošna aplikacija dodatno provjerava stvarni
Calendar API i nepromijenjeni uvoz jednog područja u pregledniku/API-ju. Razvoj
se primjenjuje samo na HFC-u; demo, apps-test, Auth, framework i tagovi ne mijenjaju se.
