# Korisnici, grupe i članstva sitea

[Engleska verzija](site-identities_en.md)

Identitete podesite uz [pregled sitea](site-inventory_hr.md),
[sadržaj](site-content_hr.md) i [kalendare](site-calendars_hr.md).
Sve su to pripremne postavke: jedna završna potvrda izvršava odabrani uvoz sitea
redoslijedom ovisnosti. Import jednog područja, Auth kod i framework ostaju nepromijenjeni.

## Aktivnost računa, imenika i povijesni autori

Izvoz je snimka stanja, a ne upit živom imeniku. Aktivnost **računa** i **imenika**
odvojene su činjenice iz `InternalUser.active` i `DirectoryImpl.active`. Aktivan račun
u ugašenom imeniku nije aktivan izvorni identitet. Nedostajuće ili nepoznate oznake
prikazuju se kao **Nepoznato**; ne zaključuju se iz naziva ili ID-a imenika,
implementacije konektora ni oblika emaila. Povijesni zapisi `ConfluenceUserImpl`
bez jednoznačnog računa imenika označeni su samo kao reference autora. Filteri
korisnika uključuju neaktivne izvore, povijesne reference i nepoznatu aktivnost.

Automatska izrada zadano preskače poznate neaktivne račune/imenike i povijesne
reference. Za namjerno zadržavanje arhivskih računa uključite automatsku izradu i
**U automatsku izradu uključi i izvorno neaktivne račune, ugašene imenike i povijesne
reference autora**. Novi računi i dalje nemaju lozinku, omogućenu prijavu ni
administratorske ovlasti. Ranije potvrđena mapiranja i izričiti pojedinačni odabiri
ostaju sačuvani. Jednoznačno podudaranje s postojećim ciljem može očuvati povijesni
identitet; zajednički email ili isto ime nikada sami ne spajaju izvore.
Pregled duplih računa predlaže aktivan izvor prije ugašenog imenika, ali ne mijenja
odobrene glavne račune i veze. Nepoznata aktivnost sama ne blokira izradu neaktivnog
cilja; pregledajte je prije potvrde uvoza.

Preskakanje računa **ne mapira** njega, njegova članstva ni ovlasti na administratora.
Nemapirani autori stranica koriste izvršitelja uvoza, dok izvorni ključevi autora i
uređivača ostaju u zadržanim metapodacima. Za odabrana osobna područja neaktivnih
vlasnika postoji izričito [skrbništvo administratora](site-content_hr.md#neaktivni-vlasnici-i-skrbništvo-administratora).
Kalendari i dalje zahtijevaju izričitog vlasnika; kreator ni administrator nisu
prešutni zamjenski vlasnici. Izvještaj identiteta bilježi preskočene arhivske izvore i razloge.

## Pregled i primjena

1. U **Postavke → Područja → Confluence import** prenesite XML ZIP sitea ili više
   područja. Odabir područja ne pokreće import.
2. U **Korisnici, grupe i članstva sitea** odaberite **Pripremi pregled identiteta**.
   Velika arhiva može zahtijevati nekoliko minuta. Radnja samo čita metapodatke.
   Prekinuta početna priprema kreće ispočetka; koristi se samo potpun indeks.
3. Unesite izvornu glavnu adresu, npr. `https://wiki.primjer.hr/confluence`.
   Pri kasnijim uvozima koristite istu adresu. Normaliziraju se velika/mala slova
   hosta, zadani portovi i završne kose crte; prijavni podaci, upiti i fragmenti
   nisu dopušteni. Adresa određuje prostor mapiranja; importer je ne dohvaća.
4. **Automatski mapiraj po jednoznačnom korisničkom imenu ili emailu** predlaže
   postojeće račune, uključujući neaktivne. Točno podudaranje login oznake bez
   razlike velikih/malih slova i podudaranje emaila/aliasa moraju upućivati na isti
   jedini cilj. Prikazna imena nisu dokaz identiteta. Sukobi i dvosmislenosti
   zahtijevaju ručnu provjeru. Svaki red i pregled/izvještaj pokazuju je li odabir
   nastao po loginu, emailu, oboma, ranije potvrđenom mapiranju ili ručno.
   To su prijedlozi, ne potvrđeni upisi prije završne potvrde sitea.
   Možete ručno preskočiti, odabrati postojeći račun ili izraditi neaktivni.
   **Koristi automatska pravila** uklanja ručnu iznimku za taj red. Preskakanje
   nikada ne dodjeljuje administratora importa.
   Po želji uključite **Automatski kreiraj neaktivne korisnike za sve nemapirane**.
   Izrada se planira nad cijelim izvorom, neovisno o stranici/pretrazi/statusnom
   filtru. Postojeći/ručni odabiri i izričita ručna preskakanja imaju prednost.
   Ponavljanja login oznaka/emaila u izvoru ili cilju i nevaljana polja novog računa
   prijavljuju se za ručnu provjeru, bez prešutne izrade duplikata. Isključivanje
   opcije uklanja automatski planiranu izradu, ne ručne odabire.
5. **Automatski mapiraj grupe po jednoznačnom nazivu** predlaže točna podudaranja
   naziva bez razlike velikih/malih slova nad cijelim pregledom. Po želji uključite
   **Automatski kreiraj sve grupe koje ne postoje**. Do završne potvrde izrada se samo
   planira; pregled/izvještaj pokazuju automatska povezivanja/izradu i sukobe.
   Oba su pravila neovisna o pravilima korisnika. Ručna preskakanja/mapiranja imaju
   prednost; **Koristi automatska pravila** uklanja ručnu iznimku grupe.
   Isključivanje izrade uklanja automatsku izradu bez gubitka ručnih odabira.
   Neovisno odaberite preskakanje/stvaranje/mapiranje grupa. Ponuđene su samo
   uključene obične ciljne grupe, ne sistemski Administrator. Istoimene grupe
   različitih imenika ostaju odvojene; nove po potrebi dobivaju nastavak
   `[ID imenika]`; tako razlučeno ime koristi se i za automatsko mapiranje.
   Ponovljeni ciljevi i nazivi sistemskih/isključenih grupa traže ručnu provjeru,
   ne izradu duplikata. Točno novo ime prikazuje se prije primjene.
6. Po želji uključite **Dodaj članstva odabranih korisnika u odabrane grupe**.
   Treba odabrati oba kraja. Neriješena članstva i ugniježđene grupe broje se,
   bez prešutnog izravnavanja ili zaključivanja iz ovlasti sadržaja.
7. Korisnici su abecedno poredani i podijeljeni po 25, 50 (zadano) ili 100 redaka.
   Navigacija iznad i ispod tablice sadrži prvu/prethodnu/sljedeću/zadnju stranicu,
   kompaktne oznake `« ‹ … › »`, označenu aktualnu stranicu i raspon rezultata.
   Kao na Auth popisu korisnika, prikazuju se najviše dva susjedna broja sa svake
   strane te prvi/zadnji broj, uz preskoke `…`. Kontrole se na uskim zaslonima
   prelamaju unutar kartice; tisuće korisnika ne stvaraju stotine gumba. Pretraga i
   promjena veličine stranice vraćaju prikaz na prvu stranicu rezultata.
   Promijenjeni odabiri automatski se spremaju u pregled prije prelaska na drugu
   stranicu ili pretragu. Time se ne stvaraju računi, grupe ni članstva. Ako
   spremanje ne uspije, prikaz i odabiri ostaju za ispravak/ponovni pokušaj.
   Za spremanje bez navigacije odaberite **Spremi postavke korisnika i grupa**.
   Izbornik mapiranja prikazuje ime i login odabranog korisnika. Pri ponovnom
   otvaranju ili pretrazi odabrani rezultat označen je naglaskom/kvačicom;
   odabir mišem i tipkovnicom poništava prethodni zajednički pregled.
   **Status mapiranja** filtrira sve, mapirane, nemapirane, predviđene za izradu,
   automatska povezivanja ili sukobe za ručnu provjeru. Brojanja prate tekstualnu
   pretragu; filtriranje prethodi paginaciji i čuva odabire skrivenih stranica.
   Pregledajte ukupna brojanja i potpunu tablicu izvora/radnje/cilja. Spremanje
   pregleda mijenja samo pripremni posao, ne račune ni članstva.
8. Podesite područja i kalendare pa odaberite **Pregledaj cijeli uvoz** i
   **Potvrdi i pokreni cijeli uvoz**. Sekcije nemaju neovisnu Primjenu.
   Stvaranje/mapiranje korisnika i članstva prethode kalendarima i sadržaju:
   odabrani korisnici postoje prije dodjele autorstva i ovlasti.
   Zajednički pregled vrijedi 30 minuta. Izmjene odabira, izvora/ciljnog imenika
   ili zastarjeli ciljevi zahtijevaju novi pregled. Ponovljena potvrda vraća
   postojeći posao bez dupliranja upisa.
9. Trajni izvještaj je u **Nedavni Confluence importi**. Navodi stvorene/mapirane
   identitete, dodana/postojeća/izuzeta članstva, neriješene slučajeve i broj
   zapisa izvornih ovlasti predviđenih za C3.

Nevaljani nacrt ispravite i ponovno pregledajte ili odaberite **Poništi
neprimijenjene odabire**. Potvrđena mapiranja/računi ne brišu se. Sukob postojeće
login oznake/emaila ili imena grupe zahtijeva izričito mapiranje; po potrebi
najprije stvorite/uredite cilj u Auth postavkama. Postojeći profil ne prepisuje se.

## Dupli izvorni računi

U [pregledu prijedloga bez upisa](site-mapping-review_hr.md) najprije provjerite
grupirane duplikate, izvorne imenike/aktivnost, zajedničke identifikatore, slabija
podudaranja samo imena i upozorenja za servisne račune prije odabira glavnog računa.

Ista osoba može imati stari kratki login i noviji login u obliku emaila u različitim
Confluence imenicima. Zajednički email samo je trag za pregled, ne automatsko
dopuštenje za spajanje: servisni/admin račun može koristiti adresu druge osobe.

1. Odlučite koji je izvorni račun glavni. Mapirajte ga na postojećeg korisnika
   Simbioze ili odaberite **Izradi neaktivnog korisnika bez prijave**, pa spremite
   postavke.
2. Za drugi izvorni račun iste osobe otvorite ciljni birač, pretražite glavni račun
   i odaberite **Isti korisnik kao izvorni račun: …**. Može upućivati na već mapiran
   glavni račun ili novi račun koji još nije stvoren. Rezultati ostaju paginirani:
   najviše 20 postojećih korisnika i pet izvornih odabira.
3. Spremite i pregledajte cijeli prikaz izvor/radnja/cilj. Primjerice,
   `stari-login → aktualni-login → novi neaktivni račun` stvara samo jedan račun,
   uz očuvanje obiju izvornih referenci, autorstva i članstava. Stvarni korisnik
   ne nastaje prije završne zajedničke potvrde.

Importer razrješava veze neovisno o redoslijedu izvora. Nepostojeći/preskočeni glavni
računi, veza na sebe i ciklusi odbijaju se prije upisa. Potvrđeno mapiranje nije
moguće preusmjeriti na drugi cilj povezivanjem izvora. Ovo je povezivanje izvora,
ne spajanje/brisanje dvaju postojećih Auth računa; njihovi atributi, vjerodajnice,
profili i pravila prijave ostaju isti. Različite osobe/servisne račune koji dijele
email razriješite izričitim odabirom ciljnih računa, ne automatskim spajanjem.

## Pravila računa i pristupa

Novi korisnici su neaktivni, nisu administratori, nemaju lozinku i svi su im
provideri isključeni. Profilna polja prolaze kroz postojeće Auth servise.
Mapirani korisnici zadržavaju vjerodajnice, oznake aktivnosti/administracije,
providere i atribute. Ime `confluence-administrators` ne promiče korisnika.

Nove grupe su obične uključene Auth grupe. Članstva se dodaju: nepovezani redovi
ostaju, automatska se ne pretvaraju u ručna, a izričita izuzeća sprječavaju
vraćanje pristupa. Importer koristi postojeće servise računa/grupa; njegov adapter
članstava dodaje samo nedostajuće veze i nakon potvrde transakcije objavljuje
standardne događaje `AuthUserGroupsChanged`. Sam Auth nije mijenjan.
Greška poništava račune, grupe, članstva, mapiranja i izvještaj tog pokušaja.
Pripremljeni odabiri ostaju za novi pregled.

Računi imenika i autorski ključevi spajaju se samo pri jednoznačnoj vezi imena.
Dvosmislene reference i reference bez računa ostaju zasebne. Potvrđena mapiranja
koriste izvornu adresu i ID-jeve, ne globalno ime grupe.

## Spremište i backup

Pravila su u `HPH_CONFIG_PATH/confluence-import/confluence-import.php`:

| Ključ | Zadano | Značenje |
| --- | ---: | --- |
| `max_site_identity_records` | 100.000 | Zadržani objekti identiteta, imenika, članstava i ovlasti |
| `max_site_identity_index_size` | 32 MiB | Najveći potpuni indeks identiteta |
| `max_inventory_object_size` | 16 MiB | Najveći zadržani XML objekt metapodataka |
| `import_execution_time_limit` | 900 sekundi | PHP vrijeme čitanja; zasebno uskladiti FPM/proxy ograničenja |

Privremeni `<upravljani-upload>.zip.principals.json` privatni je podatak u
`data/confluence-import/uploads/` ili podešenom `data_path`. Spremaju se samo
izričito dopušteni metapodaci identiteta/ovlasti, ne hashovi lozinki, tokeni,
SSO tajne ili tijela stranica. Početna priprema provjerava SHA-256; cache traži
isti hash i nepromijenjen identitet/stat datoteke. Obični GET ne čita ZIP.

Odustajanje/istek uklanjaju privremeni pregled i upload, ne već primijenjene C2
račune ili mapiranja. Promjene imaju zaseban dovršeni izvještaj `site_access`.
Backup sitea/komponente uključuje njegova mapiranja i dok priprema sadržaja nije
dovršena. Privremeni glavni poslovi, ZIP-ovi i indeksi izostavljaju se. Ciljni
ID-jevi korisnika/grupa/poslova koriste postojeća mapiranja stranih ključeva;
JSON izvještaja čuva prenosive oznake, ne nemapirane lokalne ciljne ID-jeve.

## Prijava i administracija ostaju u Authu

Importer ne pretvara Confluence hashove lozinki, konfiguracije providera, tokene,
sesije ni administratorske ovlasti. To nije nedovršeni dio C2, nego dogovorena
granica importera. `confluence-administrators` ostaje obična grupa. Aktivaciju,
lokalnu/SSO prijavu i administraciju podesite u postojećim korisničkim/globalnim
Auth postavkama. C3 koristi potvrđena mapiranja za vlasnike područja i ACL sadržaja.
