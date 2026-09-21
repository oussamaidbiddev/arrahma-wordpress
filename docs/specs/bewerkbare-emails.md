# Bewerkbare e-mailteksten

Gebouwd 2026-09-12, plugin **1.16.0**. Doel: e-mailteksten aanpassen en nieuwe e-mails maken zónder
nieuwe pluginversie te hoeven uploaden.

## Waar

**Inschrijvingen → E-mailteksten** (`arrahma_templates_page()`, slug `arrahma-emailteksten`, alleen
`manage_options`). Versturen gaat ongewijzigd via **Inschrijvingen → E-mails**; die pagina haalt haar
e-mailtypes nu uit de teksten (`arrahma_email_types()` is afgeleid), dus een nieuwe e-mail verschijnt daar
vanzelf — met de gewone doelgroep-vergrijzing, batches, doelgroepfilter en verzendlog.

## Opslag

| Wat | Waar |
|---|---|
| Standaardteksten van de ingebouwde e-mails | code: `arrahma_builtin_email_templates()` (nowdocs, geen opmaak) |
| Aanpassingen + eigen e-mails | optie `arrahma_email_templates` (autoload uit) |
| Eenmalige migratie correctiemail | vlag `arrahma_email_templates_seed` |

Vorm van de optie (synthetisch):

```php
[
  'indeling'      => [ 'varianten' => [ 'kinderen' => [ 'onderwerp' => '…', 'inhoud' => '<h2>…</h2>…' ] ] ],
  'e_herinnering' => [ 'custom' => true, 'label' => 'Herinnering', 'omschrijving' => '',
                       'categorieen' => [ 'kinderen' ],            // null = alle doelgroepen
                       'varianten' => [ 'standaard' => [ 'onderwerp' => '…', 'inhoud' => '…' ] ] ],
]
```

- Ingebouwd: alleen de aangepaste varianten staan in de optie; "Standaardtekst herstellen" haalt de sleutel weg.
- Sleutel van een eigen e-mail: `e_` + slug van de naam, max. **30 tekens** (`email_type` in
  `arrahma_email_log` is `VARCHAR(30)`); stabiel na aanmaken.
- De correctiemail voor de zusters staat nu als eigen e-mail onder de oude sleutel `correctie_zusters`,
  zodat "al gemaild" blijft kloppen. Mag worden verwijderd zodra de echte plaatsing voor de zusters uit is.

## Varianten

`bevestiging` en `indeling` hebben twee teksten, `ouderavond` en eigen e-mails één (`standaard`).
Keuze per ontvanger (`arrahma_template_variant_for()`): **`12plus`** als niemand in de e-mail een kind is,
anders **`kinderen`** — dus ook gemengde adressen (kinderen + volwassene) krijgen de oudertekst.

## Syntax

| | |
|---|---|
| `{naam}` | variabele, ge-escaped ingevuld |
| `[bij één]…[/bij één]`, `[bij meerdere]…[/bij meerdere]` | op aantal inschrijvingen in de e-mail |
| `[als aanhef]…[/als aanhef]` | alleen als die (e-mail)variabele niet leeg is |
| `[per inschrijving]…[/per inschrijving]` | herhaald per ingeschrevene; binnenin gelden diens waarden |

Variabelen (`arrahma_email_variabelen()`):

- **e-mail**: `aanhef` (leeg als het de naam van een kind zou zijn), `namen` ("A en B"), `aantal`, `email`,
  `gekozen_doelgroep`, `leeftijdsgroep`, `startdatum`, `incasso`, `ouderavond_link`
- **per inschrijving**: `voornaam`, `achternaam`, `naam`, `doelgroep`, `niveau`, `dag`, `tijd`, `lesmoment`,
  `geboortedatum`, `woonplaats` — buiten een herhaling samengevoegd ("Aisha en Yusuf")
- **blokken** (alleen in de tekst, niet in het onderwerp): `lesmomenten`, `gegevens`, `ouderavond_knop`,
  `ondertekening`, `ondertekening_vereniging`

## Huisstijl

De editor slaat kaal HTML op (WordPress-editor, `wpautop`). Bij versturen zet
`arrahma_email_apply_style()` de inline-opmaak van de andere e-mails: `h2` = begroeting, `h3`/`h4` =
tussenkop in kapitalen, citaatblok = grijs infokader, `p`/lijsten = broodtekst, links in huisstijlkleur.
Een blok dat alleen in een alinea staat vervangt die alinea (geen tabel binnen een `<p>`).

## Veiligheid

- Waarden worden altijd ge-escaped; editorinhoud gaat bij opslaan door `wp_kses_post`.
- **Fouten blokkeren versturen**: onbekende `{variabele}`, niet-gesloten of verkeerd gespelde opdracht,
  blok in het onderwerp, leeg onderwerp/tekst (`arrahma_email_template_problemen()`). Op de verzendpagina
  is zo'n e-mail grijs en de testknop uit; de AJAX-batch en het pad zonder JS weigeren hem ook.
- **Terugval**: bevat een *ingebouwde* tekst een fout, dan verstuurt `arrahma_send_template_email()` de
  standaardtekst — de automatische bevestiging bij aanmelden valt nooit stil. Eigen e-mails met fouten
  worden niet verstuurd.
- Voorbeeld (`wp_ajax_arrahma_tpl_preview`): rendert de níet-opgeslagen tekst voor een gekozen ontvanger;
  onbekende variabelen rood. "Stuur naar mij" mailt het voorbeeld naar de ingelogde beheerder.
- Emoji gaan als tekens mee: `arrahma_wp_mail()` schakelt `wp_staticize_emoji_for_email` tijdens onze
  verzendingen uit.

## Verzendpad

Alles loopt via `arrahma_send_template_email( $key, $email, $rows, $prefix, $extra )`.
`arrahma_send_confirmation_email()` is een dunne wrapper (gebruikt bij aanmelden en bulkaanmelding).
Verwijderd: `arrahma_send_indeling_email()`, `arrahma_indeling_html_zelf()`, `arrahma_indeling_onderwerp_zelf()`,
`arrahma_send_ouderavond_email()`, `arrahma_send_correctie_zusters_email()`.

Meegenomen bugfix: `arrahma_names_from_rows()` las alleen objecten, terwijl de aanmelding arrays doorgeeft.
Sinds de aanspreekvorm-wijziging stond in élke bevestiging na een kinderaanmelding een lege naam
("…de inschrijving van ."). Opgelost.

## Bijlagen (1.17.0)

Op **Inschrijvingen → E-mails** kun je Word-, Excel- en PDF-bestanden (`pdf doc docx xls xlsx`) uit de
mediabibliotheek meesturen: max. `ARRAHMA_BIJLAGE_MAX` (5) bestanden, samen max. `ARRAHMA_BIJLAGE_MAX_MB`
(10 MB). Elke ontvanger krijgt dezelfde bijlagen; ze gaan ook mee met de testmail.

- Kiezen: `wp.media` (geladen met `wp_enqueue_media()` alleen op die pagina), gefilterd op de vijf mimetypes.
- Server: `arrahma_bijlagen_uit_request()` controleert elk media-id (bestaat, leesbaar, juiste extensie,
  totale grootte). Een fout blokkeert het versturen — in de AJAX-batch, het pad zonder JS en de testmail.
- Doorgifte: `arrahma_send_to_recipient( …, $bijlagen )` → `arrahma_send_template_email( …, $bijlagen )` →
  `arrahma_wp_mail( …, $bijlagen )` → `wp_mail()` attachments.
- Bestanden in de mediabibliotheek zijn via hun URL openbaar; de pagina waarschuwt daarvoor.

## Verificatie (test-WordPress in Docker)

- `php -l` op 8.2 en 7.2 schoon.
- Alle 15 bestaande e-mailscenario's opnieuw gerenderd: onderwerpen identiek; tekst alleen anders waar
  bedoeld (naam hersteld, "A en B" i.p.v. "A, B", emoji als teken, gemengde bevestiging — zie open punten).
- 23 enginetests (voorwaarden, herhaling, escaping, foutdetectie, terugval, eigen e-mail maken en versturen).
- Adminpagina's renderen zonder fouten; voorbeeld-endpoint met echte ontvanger; kapotte e-mail vergrijsd.
- **Niet getest**: de editor in een echte browser aanklikken (TinyMCE, variabele invoegen). Alleen de
  server-rendering en de JS-syntax zijn gecontroleerd.

Opnieuw opzetten van de teststack: zie projectgeheugen *php-can-be-tested-with-docker*.

## Open punten

| # | Punt | Status |
|---|------|--------|
| 1 | **`{gegevens}` (met rekeningnummer) kan in élke e-mail**, ook een nieuwe bulkmail. Toegezegd was dat de IBAN alleen in de bevestigingsmail kan voorkomen. | Beperken tot `bevestiging` — nog doen |
| 2 | Gemengd adres (kinderen + volwassene) krijgt bij de bevestiging de oudertekst ("de kinderen definitief zijn ingedeeld"). Alleen bij handmatig opnieuw versturen, niet bij aanmelden. | Acceptabel of eigen variant |
| 3 | Editor nog niet in een echte browser doorgeklikt. | Eén keer testen op de live site |
| 4 | `jongeren-volwassenen-lessen.md` noemt nog `arrahma_indeling_html_zelf()` / `_onderwerp_zelf()`; die bestaan niet meer (de teksten staan nu in de e-mailteksten). | Doc bijwerken |
| 5 | `arrahma-inschrijvingen.zip` is gebouwd vóór 1.16.0 en dus verouderd; staat nog in git. | Opnieuw bouwen of uit git halen |
