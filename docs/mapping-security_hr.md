# Mapiranje identiteta, ACL i sigurnost

English version: [mapping-security_en.md](mapping-security_en.md)

## Korisnici

Confluence zapisi korisnika izvorni su identiteti, a ne računi za prijavu. Početna provjera može predložiti postojeći Simbioza račun prema oznaci prijave, e-pošti ili ranije potvrđenom mapiranju, ali administrator mora potvrditi odabir. Podudaranje je strogo i dvosmislen rezultat ostaje nemapiran.

Potvrđeno mapiranje izvornog računa globalno je za ovaj importer i ponovno se koristi kada se isti Confluence račun pojavi u arhivi drugog spacea. Kasniji import ne može ga poništiti ni preusmjeriti na drugi račun. Ponovna uporaba ne mijenja ciljni račun, providere prijave, aktivnost, članstva u grupama, administratorski status ni ovlasti iz ranijih područja.

Za identitet bez ciljnog računa administrator može ostaviti zatvoreni pristup ili izričito izraditi neaktivan Auth predračun. Takav zapis nema lozinku, nema dopušten provider i ne može se prijaviti. Auth modul je jedini vlasnik kasnije aktivacije: aktivni račun mora imati provider, aktivni local-only račun mora dobiti administratorsku privremenu lozinku, a ta se lozinka obavezno mijenja pri prvoj lokalnoj prijavi. Korisnik s vanjskim providerom može dobiti local pristup bez lokalne lozinke i zatim je sam privatno postaviti.

Kod SAML/OIDC/OAuth/CAS instalacije stvarna osoba najprije treba dobiti ili povezati račun kroz podešeni provider, a zatim se Confluence identitet mapira na taj postojeći račun.

## Grupe

Izvornu grupu moguće je mapirati na postojeću običnu grupu ili izričito izraditi kao novu običnu grupu. Točan postojeći ključ ili naziv grupe predlaže se automatski; slični i dvosmisleni nazivi ne. Podržani XML izvozi jednog spacea sadrže reference na grupe u ovlastima, ali testirane arhive ne sadrže pouzdan popis članstva korisnika u grupama. Članstvo se zato nikada ne zaključuje iz ACL zapisa, a Confluence članstva i administratorski status ne kopiraju se. Budući importer cijelog sitea ili API importer smije dodavati samo izričita članstva preko javnog Auth ugovora.

Sam import radi s ovlastima administratora koji ga je pokrenuo kako bi provjere stvaranja i workflowa ostale valjane. Nakon svakog uspješnog spremanja Editor zapisuje mapiranog Confluence autora i zadnjeg urednika na dokument i njegovu točnu verziju. Za nemapiranog autora sigurna zamjena ostaje administrator importa. To autorstvo nikada ne mijenja vlasništvo dokumenta ni ACL.

## Pravilo ovlasti

Ovlasti primjenjuju pravilo zatvorenog pristupa:

- mapirani subjekti dobivaju samo uvezeno pravo;
- neriješena ograničenja korisnika i grupa ostaju restriktivna;
- ograničenje pregleda privatnu stranicu nikada ne pretvara u javnu;
- ograničenje samo uređivanja čuva naslijeđeni pregled, ali sužava uređivanje;
- administrator nakon importa treba provjeriti reprezentativne ograničene stranice.

## Privitci

Moguće je sačuvati sve MIME tipove jer privatni sustav znanja legitimno može arhivirati izvorni kod, izvršne ili neuobičajene formate. Datoteke su izvan javnih asseta, pristup se pri svakom zahtjevu provjerava prema mapiranoj stranici, a odgovor prisiljava preuzimanje. Naziv ili MIME vrijednost iz izvora datoteku nikada ne čine izvršnom.

Provjera arhive odbija prolazak kroz putanju, prevelik broj zapisa, preveliku raspakiranu veličinu, preveliku pojedinačnu datoteku i sumnjiv omjer kompresije.

## Logovi i backup

Kada je instaliran, Audit bilježi administratora, izvorni space, ciljno područje i ishod. Tehničke iznimke koriste aplikacijski logger bez zapisivanja tajni ili sadržaja datoteka. Dovršeni posao čuva strukturirani izvještaj stranica za provjeru. Backup sadrži trajna mapiranja i taj izvještaj, ali ne privremene prijenose ni tehničke logove. Nativne uvezene privitke štiti i sprema Editor; Confluence Import čuva samo njihovo podrijetlo pa ne duplicira blobove.
