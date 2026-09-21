# Docentenportaal: aanwezigheid en notities

Status: **gebouwd in plugin 1.17.0** (2026-09-21), getest in de Docker-test-WordPress. Nog niet live.

## Doel

Docenten houden per les de aanwezigheid bij en schrijven korte notities over leerlingen, voor **alle
doelgroepen** (kinderen, jongeren, volwassenen). Het bestuur ziet alles. Afwezigen worden handmatig
door de vereniging benaderd; het systeem mailt ouders niet.

## Besluiten (gebruiker, 2026-09-21)

| Vraag | Besluit |
|---|---|
| Waar werken docenten? | Aparte pagina op de website (`/docenten`) achter de WordPress-login. Géén wp-admin. |
| Wat zien docenten? | Persoonsgegevens van leerling en contactpersoon. **Geen** bankgegevens of betaalwijze. |
| Wie is het bestuur? | Wie inlogt met een beheerdersaccount (`manage_options`). Geen aparte bestuursrol. |
| Wie leest notities? | Alle docenten van die leerling en het bestuur. |
| Ouders bij afwezigheid | Handmatig; geen automatische mail. |
| Bewaren | Geen automatische verwijdering; export als CSV. Bewaartermijn bepaalt de vereniging zelf. |
| Klassen | Een blok/groep kan in klassen worden verdeeld; zonder klassen is het hele blok de klas. |
| Lesdata | Geen begin- of einddatum: de kalender loopt het hele jaar door, minus vakanties en uitzonderingen. |
| Oude lessen wijzigen | Mag, onbeperkt; elke wijziging legt vast wie en wanneer. |

## Hoe het werkt

### Toegang
- Rol **Docent** (`arrahma_docent`): alleen `read` + `arrahma_aanwezigheid`. Aangemaakt bij de
  versie-update (`arrahma_registreer_docent_rol()` in `arrahma_create_table()`).
- Na inloggen naar `/docenten` (`login_redirect`); wp-admin stuurt terug naar `/docenten` (`admin_init` en
  `admin_page_access_denied`); geen admin-balk.
- Afscherming op de server: `arrahma_mag_eenheid()` en `arrahma_mag_leerling()` bij elke weergave,
  AJAX-opslag en notitie-actie, plus nonces. Uitgeschreven/afgewezen leerlingen zijn voor docenten onzichtbaar.

### Eenheden en klassen
- **Eenheid** = waar een docent aan hangt: een heel blok/groep (slot-sleutel uit `rooster`) of één klas
  (klas-id). `arrahma_eenheid()`, `arrahma_alle_eenheden()`, `arrahma_docent_eenheden()`.
- Klassen: optie `arrahma_klassen` = `{ klas_id: { slot, naam } }`; indeling in kolom `klas` op de
  inschrijving. Een docent van een klas ziet ook de leerlingen van dat blok die **nog niet in een klas**
  zitten, zodat niemand wordt overgeslagen.
- Verandert een leerling van blok, dan hoort de oude klas-id niet bij het nieuwe blok en staat hij daar
  automatisch bij "Nog niet in een klas".

### Lesdata (`arrahma_lesdag_info()`)
Volgorde: uitzondering voor die groep op die dag → weekdag uit de `dag`-tekst van de groep
("Zaterdag & zondag" = za + zo) → vakantie. Opties `arrahma_vakanties` en `arrahma_les_uitzonderingen`
(per groep: vervalt / extra).

**Vakantie per blok of groep blokken**: elke vakantie heeft `slots` (lijst blok-/groepsleutels); leeg =
alle groepen (zo blijven oude vakanties werken). In het beheer: "Alle groepen" of "Alleen gekozen
groepen", met per doelgroep een vinkje "— alles" om een hele groep blokken in één keer te kiezen.
Opgeslagen wordt de lijst blokken zelf, dus een blok dat later wordt toegevoegd valt er niet vanzelf onder.
`arrahma_vakantie_geldt()` en `arrahma_vakantie_bereik()` (leesbare omschrijving in de tabel).

### Docentenpagina — shortcode `[arrahma_docenten]`
Ontwerp: artifact "Arrahma Docentenportaal" (https://claude.ai/artifact/DoQz1myiiNQUiWGP4Ra99X).
Pagina-layout in WordPress: **Elementor Canvas** (geen header/footer van thema of Elementor).

| Scherm | URL-parameters | Inhoud |
|---|---|---|
| Inloggen | — | websitelogo (`ARRAHMA_LOGO_PAD` in uploads, filter `arrahma_docenten_logo_url`), WordPress-inlogformulier, "Ingelogd blijven" standaard uit |
| Mijn groepen | — | les(sen) van vandaag als grote kaart ("Presentie opnemen" / "Verder · x van y" / "Bekijken"), **Nog in te vullen** (verstreken lessen van de afgelopen `ARRAHMA_OPEN_DAGEN` dagen die niet compleet zijn, max. `ARRAHMA_OPEN_MAX`), overige groepen met volgende les. Bestuur: knop naar het maandoverzicht. Iedereen komt hier eerst, ook met één groep, zodat de to-do zichtbaar is. |
| Presentielijst | `eenheid`, `datum` | Lijst/Kalender-schakelaar, vorige/volgende les, voortgangsbalk en tellingen, per leerling vier ronde knoppen met eigen icoon (✓ klok envelop ✕) en de status in woorden, "Nog niet in een klas"-sectie, vaste balk onderaan met opslagstatus en "Rest op aanwezig". Opslaan per tik via `wp_ajax_arrahma_aanwezigheid`; nogmaals tikken wist; mislukt → rij rood met reden. |
| Kalender | `eenheid`, `weergave=kalender`, `maand=JJJJ-MM` | maandraster; per lesdag compleet / deels / niet ingevuld / nog niet geweest, aantal afwezig; vakanties gearceerd met naam, vervallen les doorgestreept, extra les gelabeld, vandaag goud omrand; tik → presentielijst van die dag. |
| Leerling | `leerling`, `eenheid` | kop met initialen, bel- en mailknop (contactpersoon eerst), aanwezigheid (tellingen, strip laatste 8 lessen in letters, alle lessen), notities (toevoegen; eigen aanpassen/verwijderen, bestuur alles), contact & gegevens. Geen bankgegevens. |
| Maandoverzicht (alleen bestuur) | `bestuur=1`, `maand` | rij per groep/klas × kolom per dag, zelfde tekens als de kalender, vakantieband, vandaag getint; zijbalk: % lessen compleet, % aanwezig of te laat, niet ingevulde lessen, vaak afwezig zonder bericht. |

Lege toestanden zeggen waarom er niets staat en bieden de volgende stap (vakantie → volgende les, nog
niet begonnen, niet gekoppeld, geen leerlingen).

Voltooiing van een les (`arrahma_voltooiing()`): telt de leerlingen die nu op de lijst van die eenheid
staan (inclusief "nog niet in een klas") tegen de registraties van die dag.

Weergave: de pagina vult het hele scherm. Body-klasse `arr-docentenpagina` (via shortcode in de inhoud,
`_elementor_data` of de vastgelegde URL) plus `:has(.arr-doc)` halen padding, marges en maximale breedte
van Elementor-containers weg. Onder de kop staat alles in `.arr-hoofd`, met een breedte per scherm
(`--breedte`: lijst 960, overzicht 1040, kalender/leerling 1120, maandoverzicht 1480 px); de kop lijnt daarop uit.
Vanaf 700 px: statusknoppen met tekst, lijst en voortgang als kaarten. Vanaf 960 px: kop met titel links en
schakelaar/navigatie rechts; Mijn groepen in twee kolommen; kalender met grotere dagen (incl. "12/20") en een
zijkolom met legenda; leerling in twee kolommen (aanwezigheid + contact links, notities rechts); inloggen als
gecentreerde kaart.

Huisstijl (`arrahma_docenten_css()`): Open Sans; Roboto Slab alleen voor datums en maandnamen; leisteen
`#263034`/`#323F44` voor koppen; goud `#DB9F30` voor hoofdacties en "vandaag"; statuskleuren groen / goud /
leisteen / rood, altijd samen met een icoon of letter. Lettertypen komen van de site zelf (geen Google-verzoek).
Alles afgeschermd onder `.arr-doc`. De pagina-URL wordt vastgelegd in optie `arrahma_docenten_url`.

### Beheer (wp-admin, alleen beheerders)
- **Inschrijvingen → Klassen & docenten**: tabbladen *Klassen* (aanmaken, hernoemen, verwijderen,
  leerlingen indelen), *Docenten* (koppelen aan groepen/klassen), *Lesrooster & vakanties* (vakanties,
  uitzonderingen, controlelijst eerstvolgende lessen).
- **Inschrijvingen → Aanwezigheid**: signaallijst (≥ 3× "Afwezig" in 8 weken, met telefoonnummers),
  overzicht per groep (leerlingen × lessen, max. 190 dagen), exports: aanwezigheid (periode of alles) en
  notities.

### Status `uitgeschreven`
Nieuw naast nieuw/verwerkt/afgewezen. Uit klaslijsten en signaallijst, telt niet mee voor capaciteit,
krijgt geen bulkmail; aanwezigheid en notities blijven bewaard. Een inschrijving **verwijderen** verwijdert
ook haar aanwezigheid en notities.

## Opslag

```sql
{prefix}arrahma_aanwezigheid
  id, inschrijving_id, rooster VARCHAR(40), lesdatum DATE, status VARCHAR(12),
  door (WP user id), gewijzigd_op DATETIME
  UNIQUE (inschrijving_id, lesdatum, rooster), KEY (rooster, lesdatum)

{prefix}arrahma_notities
  id, inschrijving_id, tekst TEXT, auteur (WP user id), aangemaakt_op DATETIME, gewijzigd_op DATETIME NULL

{prefix}arrahma_inschrijvingen: nieuwe kolom klas VARCHAR(20) DEFAULT ''
user meta arrahma_docent_eenheden: lijst eenheid-sleutels
```

## Livegang

1. Plugin 1.17.0 uploaden (één bestand vervangen; de versiecheck maakt tabellen, kolom en rol aan).
2. Pagina **Docenten** maken, adres `/docenten`, inhoud alleen `[arrahma_docenten]` (Elementor: shortcode-widget).
   Eén keer bekijken als beheerder.
3. Vakanties invullen onder *Lesrooster & vakanties*; klassen aanmaken waar een blok gesplitst is.
4. Per docent: Gebruikers → Nieuwe gebruiker, rol **Docent**; daarna koppelen onder *Docenten*.
5. Controleren: als docent inloggen op een telefoon en één les invullen.

Staat er een cache-plugin (bijv. LiteSpeed Cache) aan: sluit `/docenten` uit van de cache. Ingelogde
gebruikers worden meestal al niet gecachet en de shortcode zet `DONOTCACHEPAGE`.

## Verificatie (test-WordPress in Docker)

- `php -l` op 8.2 en 7.2 schoon; geen PHP-waarschuwingen in de serverlog.
- 31 functionele checks: weekdagen, vakanties, uitzonderingen, vorige/volgende les, toegang per
  docent/klas/blok, uitgeschreven, zetten/overschrijven/wissen, e-mailontvangers.
- 61 HTTP-checks als docent en beheerder: redirects uit wp-admin, geweigerde URL's naar andere groepen en
  leerlingen (lijst, kalender, maandoverzicht), AJAX (goed, andermans leerling 403, toekomst, geen lesdag,
  onbekende status, foute nonce), notities, to-do "Nog in te vullen", kalender (klikbare lesdagen, toekomst,
  vakantie), maandoverzicht (cellen, zijbalk, vakantieband), inlogscherm, alle beheerpagina's, beide CSV-exports.
- Weergave: telefoon (375 px) zonder horizontale scroll; maandoverzicht op 1280 px past volledig naast de zijbalk.
- **Niet getest**: tikken in een echte browser met ingelogde docent (de AJAX-aanroep zelf is wel via HTTP getest),
  weergave binnen het echte Elementor-thema.

## Open

| # | Punt |
|---|---|
| 1 | Bewaartermijn in de privacyverklaring (vereniging bepaalt zelf). |
| 2 | Vakanties gelden voor alle groepen; per doelgroep kan nu alleen via losse uitzonderingen per groep. |
