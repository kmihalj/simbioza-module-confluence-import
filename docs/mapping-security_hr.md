# Mapiranje identiteta, ACL i sigurnost

English version: [mapping-security_en.md](mapping-security_en.md)

## Korisnici

Confluence zapisi korisnika izvorni su identiteti, a ne računi za prijavu. Početna provjera može predložiti postojeći Simbioza račun prema oznaci prijave, e-pošti ili ranije potvrđenom mapiranju, ali administrator mora potvrditi odabir. Importer ne izrađuje lokalne lozinke ni sintetičke korisnike.

Kod SAML/OIDC/OAuth/CAS instalacije stvarna osoba najprije treba dobiti ili povezati račun kroz podešeni provider, a zatim se Confluence identitet mapira na taj postojeći račun.

## Grupe

Izvornu grupu moguće je mapirati na postojeću običnu grupu ili izričito izraditi kao novu običnu grupu. Confluence članstva i administratorski status ne kopiraju se automatski, čime se sprječava da zastarjeli izvoz poveća nečije ovlasti.

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

Kada je instaliran, Audit bilježi administratora, izvorni space, ciljno područje i ishod. Tehničke iznimke koriste aplikacijski logger bez zapisivanja tajni ili sadržaja datoteka. Backup sadrži dovršena trajna mapiranja i privatne uvezene datoteke, ali ne privremene prijenose ni tehničke logove. Backup jednog područja izvozi samo njegova mapiranja i šifrirane privatne blobove privitaka; obnova kao kopija stvara nove UUID-ove prije nego što poveznice postanu dostupne.
