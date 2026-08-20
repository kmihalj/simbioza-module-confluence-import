# Simbioza modul za Confluence import

English version: [README.md](README.md)

Modul specifičan za Simbiozu koji sigurno uvozi Confluence XML backup jednog spacea u područje. Čuva stablo stranica, aktualni sadržaj, privitke, unutarnje poveznice i izričito mapirane ovlasti. Povijest stranica, nacrti i obrisane stranice opcionalni su i zadano isključeni.

## Ovisnosti

Obavezne i učitane prije ovog modula:

- `aaieduhr/heartphrame-framework`
- `aaieduhr/heartphrame-module-orm`
- `aaieduhr/heartphrame-module-menu`
- `aaieduhr/heartphrame-module-auth`
- `aaieduhr/heartphrame-module-editor-html`
- `aaieduhr/heartphrame-module-workspace`
- `aaieduhr/simbioza-module-user`

Opcionalne integracije:

- Audit bilježi administratorske odluke i rezultate importa.
- Backup cijelog sitea i poslovne cjeline Područja uključuje trajna mapiranja importa i sve uvezene privatne datoteke.
- Backup jednog područja uključuje samo njegove Confluence izvorne podatke i privatne privitke; obnova kao kopija stvara nove UUID-ove i prepisuje poveznice privitaka.
- Comment uvozi komentare čiji su autori i stranice mapirani.
- Workspace Search automatski obnavlja izvedeni indeks nakon importa.

Svi interni paketi prate `dev-main`; ovaj razvojni modul ne sprema `composer.lock`.

## Što importer radi

- prima velike `.xml.zip` izvoze nastavivim prijenosom u dijelovima;
- prije dopuštanja izmjena izvodi read-only provjeru;
- predlaže naziv i ključ Confluence spacea kao naziv i slug područja;
- zadano uvozi aktualne stranice i ponovno gradi njihovo stablo;
- opcionalno uvozi povijest, nacrte i soft-obrisane stranice;
- prepisuje poveznice unutar spacea i naknadno usklađuje poveznice prema poslije uvezenim spaceovima;
- sve vrste privitaka sprema privatno i isporučuje ih kao preuzimanja;
- izričito mapira postojeće korisnike i grupe bez izrade lažnih računa;
- neriješene ACL identitete obrađuje zatvoreno, bez proširenja pristupa;
- osobni Confluence space mapira u osobno područje potvrđenog vlasnika;
- bilježi nepodržane makroe i druge odluke za administratorski pregled;
- nakon uspješnog importa briše prenesenu izvornu arhivu.

## Brzi početak

```bash
composer require aaieduhr/simbioza-module-confluence-import:dev-main
vendor/bin/hph simbioza-confluence-import:install-migration
vendor/bin/hph orm-migrate up
```

Uključite paket nakon svih obaveznih modula pa otvorite **Postavke → Područja → Confluence import**. Prenesite Confluence XML ZIP izvoz jednog spacea, pregledajte provjeru, potvrdite mapiranja korisnika i grupa te pokrenite import.

Read-only pregled kroz CLI:

```bash
vendor/bin/hph simbioza-confluence-import:inspect /srv/import/podrucje-tima.xml.zip
```

## Dokumentacija

- [Indeks dokumentacije](docs/index_hr.md)
- [Instalacija i konfiguracija](docs/installation_hr.md)
- [Vodič za administratore](docs/administrator-guide_hr.md)
- [Mapiranje identiteta, ACL i sigurnost](docs/mapping-security_hr.md)
- [Pretvorba sadržaja i poveznice](docs/content-links_hr.md)
- [Integracija za developere](docs/developer-guide_hr.md)
- [Testiranje i rješavanje problema](docs/testing-troubleshooting_hr.md)

## Licenca

Ovaj je rad objavljen pod licencom European Union Public License (EUPL) v1.2.
