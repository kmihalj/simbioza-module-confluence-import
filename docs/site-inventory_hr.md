# Pregled Confluence sitea

[English version](site-inventory_en.md)

Razvojna verzija prepoznaje XML ZIP izvoz jednog područja, više područja i cijelog
sitea. Import jednog područja zadržava postojeći postupak. Za više područja i site
dostupni su pregled, spremanje odabira područja i
[postavke identiteta](site-identities_hr.md) za jedan zajednički pregledani uvoz. Sam odabir područja **ne stvara**
korisnike, grupe, članstva, ovlasti, kalendare ni sadržaj. Preostale korake prati
[plan importa sitea](site-import-plan_hr.md).

## Pregled i odabir područja

1. Otvorite **Postavke → Područja → Confluence import** i prenesite XML ZIP.
   Vrstu određuje descriptor unutar arhive, ne ime datoteke. Izvoz označen kao
   `site` ostaje site čak i kada sadrži samo jedno područje.
2. Pričekajte pregled bez promjene sadržaja. Za site i više područja prikazuju se
   područja, broj aktualnih stranica, računi imenika, reference autora, broj zapisa
   grupa i članstava, stvarne datoteke privitaka te prisutnost podataka dodataka.
   Podaci dodataka u ovom se koraku ne pretvaraju.
3. Pretražite tablicu i označite pojedina područja ili **Odaberi prikazana područja**.
   Tablice prikazuju 25, 50 ili 100 redaka po stranici, uz brojače rezultata/odabira
   i sažetu navigaciju `« ‹ 1 2 … N › »` iznad i ispod. Pretraga filtrira cijeli
   pregled, ne samo trenutačnu stranicu. **Odaberi prikazana područja** vrijedi samo
   za trenutačnu stranicu; **Odaberi sva filtrirana područja** uključuje podudaranja
   sa svih stranica. Listanje i filtriranje čuvaju odabire i ne šalju niti pokreću uvoz.
   **Poništi odabir** uklanja sve odabire, uključujući skrivene retke.
4. Pritisnite **Spremi odabir područja**. Ponovno otvaranje posla vraća odabir.
   Spremanje mijenja samo posao importa; ne stvara područje i ne dodjeljuje ovlasti.
5. **Odustani od importa** uklanja privremeni prijenos, njegov indeks i posao
   pripreme. Ne briše izvornu arhivu na vašem računalu. Već primijenjeni C2
   identiteti i trajni izvještaj ostaju; odustajanje nije poništavanje primjene.

Računi imenika i Confluence reference identiteta autora različite su vrste zapisa;
ne zbrajaju se kao novi korisnici. Grupe istog imena mogu pripadati različitim
imenicima. Usklađivanje, mapiranje na postojeće račune i potvrđeni prijenos članstava
opisuje C2 vodič. XML zapis privitka nije datoteka privitka: izvoz bez datoteka i dalje
može sadržavati zapise privitaka.

## Pregled iz CLI-ja

Iz direktorija aplikacije:

```bash
vendor/bin/hph simbioza-confluence-import:inspect /srv/import/confluence-site.xml.zip
```

Naredba ispisuje pregled bez stvaranja korisnika, grupa, kalendara i sadržaja.
Sprema privatni indeks, ali ne stvara posao za pregled u GUI-ju. Izlaz sadrži nazive
područja i izvorne identifikatore pa ga tretirajte kao privatne podatke migracije.
Ne sadrži tijela dokumenata, hashove lozinki, tokene ni postavke dodataka.

## Spremanje i ograničenja

Ograničenja se podešavaju u
`HPH_CONFIG_PATH/confluence-import/confluence-import.php`. Izostavljeni ključ
koristi zadanu vrijednost modula. Prijenosi i indeksi podaci su, ne konfiguracija:

- Prijenosi iz preglednika: `<aplikacija>/data/confluence-import/uploads/`.
- Indeks prijenosa: `<id-prijenosa>.zip.inventory.json` uz ZIP kojim upravlja modul.
- CLI indeks: `<aplikacija>/data/confluence-import/inventory/<sha256-izvora>.json`.

Putanje prate `data_path` ako je različit od `confluence-import`. Indeks prijenosa
uklanja se s prijenosom pri odustajanju ili isteku. CLI indeksi mogu se ponovno
izraditi i ukloniti kada pregled nije u tijeku. Nisu trajni sadržaj backupa.
Potpuni indeksi spremaju se atomski s dozvolama `0660`, a prije ponovne uporabe
provjeravaju svježim SHA-256 sažetkom ZIP-a.

Čitač sitea obrađuje `entities.xml` izravno iz ZIP-a u ograničenim dijelovima.
Provjerava XML omotnicu i odabrane objekte metapodataka, broji druge vrste objekata
bez deserijalizacije njihova sadržaja te odbacuje tijela i vjerodajnice.
U C1 ne raspakirava cijeli XML i ne obrađuje Active Objects tablice.
DTD i deklaracije vanjskih entiteta nisu dopušteni. Ostaju zaštite od izlaska iz
ZIP putanje, ponovljenih putanja, symlinkova, previše zapisa i ZIP bombi.

| Ključ | Zadano | Primjena |
| --- | ---: | --- |
| `max_site_entry_size` | 64 GiB | Jedan raspakirani zapis sitea ili više područja |
| `max_site_uncompressed_size` | 128 GiB | Svi raspakirani zapisi sitea ili više područja |
| `max_inventory_object_size` | 16 MiB | Jedan zadržani objekt metapodataka |
| `max_inventory_spaces` | 10.000 | Područja i reference područja zadržane u pregledu |

Postojeći limit prijenosa od 8 GiB i zaštite od ZIP bombi ostaju isti. Za jedno
područje ostaju limiti od 4 GiB po zapisu i 16 GiB ukupno. Sve ključeve opisuje
[instalacija i konfiguracija](installation_hr.md).

## Prekidi i opseg

Prijenos datoteke ostaje nastaviv. **Početni pregled jedan je prolaz kroz izvor**:
ako se prekine, djelomični indeks se ne prihvaća i nova priprema kreće ispočetka.
Odustanite od neuspjele ili zaglavljene pripreme i ponovno prenesite datoteku;
nastavljanje importa sadržaja ne znači nastavljanje početnog pregleda. Dovršeni
CLI pregled može ponovno koristiti provjereni indeks. PHP/FPM i proxy timeout
uskladite s `import_execution_time_limit`; za veće arhive može trebati dulji rok.

Ulazi za import jednog područja odbijaju pregled sitea, čak i sa samo jednim područjem:
site se ne smije prešutno uvesti kao prvo područje. Odabrani sadržaj koristi zaseban
[C3 pregled/potvrdu](site-content_hr.md). Početni pregled ne traži nove migracije
niti mijenja Auth konfiguraciju ili SSO providere.

## Provjereni izvozi

Dostavljeni izvoz cijelog sitea obrađen je bez raspakiravanja njegova približno
28,6 GB velikog `entities.xml`: 5.813.804 objekta, 352 područja (206 općih, 146
osobnih), 44.594 aktualne stranice, 369 računa imenika, 20 zapisa grupa i 784
članstva. Nema datoteka privitaka. Kalendarski zapisi paketa dodataka koriste odvojeni
[C4 uvoz](site-calendars_hr.md); sam inventar ih ne uvozi. Dostavljeni izvoz jednog područja
DABAR i dalje prolazi postojeći čitač: 253 aktualne stranice, 393 zapisa privitaka
i 332 logička aktualna privitka.
