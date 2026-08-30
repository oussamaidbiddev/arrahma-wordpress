# Arabisch onderwijs — jongeren & volwassenen

Built 2026-08-30 from `Briefing_website_Arabisch_onderwijs_jongeren_volwassenen.docx` (schooljaar 2026–2027).
Plugin version **1.10.0**. De open punten uit de eerste versie zijn op 2026-08-30 bevestigd en verwerkt.

> **⚠️ NOG NIET GETEST OP EEN LIVE WORDPRESS.** Alles clientside is in de browser geverifieerd,
> maar de PHP is nooit uitgevoerd — zie *Verificatie*. Test op staging voordat dit live gaat.
> Zet het formulier zo nodig op *Gesloten* via **Inschrijvingen → Instellingen**.

## Wat er is gebouwd

**Vier nieuwe doelgroepen** naast `kinderen` (die ongewijzigd is):
`broeders_jongeren` (12–16), `broeders_volwassenen` (17+), `zusters_jongeren` (12–16), `zusters_volwassenen` (17+).
De oude sleutels (`tieners_broeders`, `tieners_zusters`, `broeders_18plus`, `zusters_18plus`) staan nog in
`arrahma_category_labels()` zodat bestaande inschrijvingen leesbaar blijven, maar staan niet meer op het formulier
(zie `arrahma_active_categories()`).

**Lesgroepen als single source of truth.** `arrahma_groepen()` in de plugin en `GROEPEN` in `index.html`
koppelen categorie + niveau + dag + tijd. Deze twee **moeten identiek blijven**. 12 groepen.
Een groep toevoegen = één regel in beide bestanden; formulier, capaciteit, admin, CSV en validatie volgen automatisch.

**Opslag:** geen schemawijziging. De groepskeuze schrijft naar de bestaande kolommen —
`niveau` krijgt de niveausleutel (`n1`…`n5`, `z_basis`, `z_gevorderd`) en `rooster` de groepssleutel
(`br_volw_n1_zo` enz.).

**Stapvolgorde (gewijzigd 2026-08-30):**
1 Voor wie → 2 Niveau/lesmoment → 3 Lesdagen *(alleen kinderen)* → 4 Gegevens → 5 Akkoord.
Bij jongeren & volwassenen zit het lesmoment al in de groepskeuze, dus stap 3 vervalt daar.
De paneel-ids zijn omgenummerd; de element-ids `error-stap3`/`error-stap4` zijn historisch
en verwijzen naar respectievelijk het niveau- en lesdagenpaneel.

**Niveau 5 (al-Ājurrūmiyyah):** alleen voor **broeders volwassenen (17+)**, niet voor jongeren 12–16.
Zondag 19:00, wél zichtbaar maar niet direct inschrijfbaar — knop "Niveautoets aanvragen"
naar communicatie@vereniging-arrahma.nl.

**Status per lesblok/lesgroep** (alle 17: 5 kinderen-lesblokken + 12 groepen), instelbaar via
**Instellingen**: `open` | `gesloten` (zichtbaar, niet kiesbaar, badge "Gesloten") | `verborgen` (niet getoond).
Serverzijde afgedwongen (`slot_closed`, HTTP 409). De site-brede open/gesloten-schakelaar gaat hiervóór.

**Capaciteit per lesblok/lesgroep**, eveneens via **Instellingen** (kolom *Max.*, 1–999).
Opgeslagen in `arrahma_slot_caps` als `[ sleutel => aantal ]`; leeg of 0 valt terug op de standaard
(`ARRAHMA_ROOSTER_CAP` = 30 voor kinderen-lesblokken, `ARRAHMA_GROEP_CAP` = 20 voor lesgroepen —
gespiegeld als `ROOSTER_CAP` / `GROEP_CAP` in `index.html`). Bij het bereiken van de capaciteit krijgt
het lesmoment automatisch het label "Vol" en is het niet meer kiesbaar; serverzijde blijft
`rooster_full` (HTTP 409) de harde grens. Het formulier haalt de waarden op via `/form-mode`
(sleutel `caps`), dat al is opgehaald vóórdat het formulier zichtbaar wordt.

**Prijzen:** kinderen €30/mnd (€27,50 volgend kind), jongeren & volwassenen €20/mnd — geen gezinskorting.
Jaarbedragen zijn 10 lesmaanden (de zomervakantie telt niet mee): €300 / €275 / €200.
Laatste stap is categorie-afhankelijk: eigen bedragen én eigen reglement-link.

## Afgehandelde punten (2026-08-30)

| # | Punt | Uitkomst |
|---|------|----------|
| 1 | Groepsgrootte was een placeholder. | **Per lesblok/lesgroep instelbaar gemaakt** via Instellingen; `ARRAHMA_GROEP_CAP` / `GROEP_CAP` zijn nu alleen nog de standaardwaarde. Geen vast getal meer nodig. |
| 2 | Jaarbedrag €200 was een aanname. | **Bevestigd**: €20/mnd × 10 lesmaanden, zomervakantie uitgezonderd. |
| 3 | Niveau 5 stond onder béide broeders-doelgroepen. | **Alleen volwassenen (17+).** `br_jong_n5_zo` verwijderd uit `arrahma_groepen()` en `GROEPEN`; 13 → 12 groepen. |
| 4 | Beginnersgroep `br_jong_n1_zo` (zondag 11:00–12:30, parallel aan de Niveau 2-groep). | **Bevestigd correct.** |
| 5 | Reglement jongeren & volwassenen niet te openen (HTTP 403 op geautomatiseerde requests). | Alleen een beperking van deze omgeving — de PDF opent gewoon vanaf de eigen machine. URL in `REGLEMENT_URL_JONGEREN_VOLWASSENEN` is goed. |
| 6 | `showClosedMessage()` was half afgemaakt. | **Afgemaakt**: leeg bericht laat de standaardtekst staan, en `display:block` wordt nu altijd op de `<p>` gezet. Getest mét een `p:empty { display:none }`-themaregel actief, ook vanuit een écht lege paragraaf. |

## Open punten

| # | Punt | Status |
|---|------|--------|
| 1 | **Geen enkele regel PHP is ooit uitgevoerd** — zie *Verificatie*. Vooral de Instellingen-pagina (opslaan van status én capaciteit) en een echte inzending moeten nog draaien. | Staging-test |

## Verificatie

Alles clientside is in de browser getest: stapvolgorde voor beide paden (incl. validatie die blokkeert
en correcte terug-navigatie), doelgroep-specifieke groepen, "Vol" bij bereikte capaciteit,
`gesloten`/`verborgen` gedrag voor zowel groepen als kinderen-lesblokken, prijzen en reglement-links
per categorie, en de payload-mapping (`niveau: n2`, `rooster: br_volw_n2_zo`).

Extra getest op 2026-08-30:

- **Capaciteit per slot.** `capForKey()` geeft 30/20 zonder instelling, en de ingestelde waarde zodra
  die er is. Een lesgroep met `cap 3` en 3 inschrijvingen wordt "Vol" terwijl een groep met 5 van de
  standaard 20 gewoon kiesbaar blijft; hetzelfde voor een kinderen-lesblok dat op 8 is gezet
  (8/8 = Vol) naast een blok op 25/30 dat openblijft.
- **Status + capaciteit samen.** Bij `n2 = gesloten`, `n4 = verborgen` en `cap n1 = 2`: n1 kiesbaar-af
  met "Vol", n2 zichtbaar-maar-niet-kiesbaar met "Gesloten", n3 gewoon open, n4 volledig weg,
  Niveau 5 als niveautoets-kaart.
- **Niveau 5.** `broeders_jongeren` toont drie groepen zonder Niveau 5; `broeders_volwassenen` toont
  er vier plus de niveautoets-kaart.
- **`showClosedMessage()`.** Met een `p:empty { display:none }`-themaregel actief: een custom bericht
  verschijnt, een leeg bericht laat de standaardtekst staan, en zelfs vanuit een écht lege `<p>`
  komt de tekst zichtbaar terug (`display: block`).

**PHP is nooit uitgevoerd.** Er is geen PHP-interpreter en geen draaiende Docker-daemon in deze omgeving,
dus de plugin is alleen structureel gecontroleerd (haakjesbalans, `if`/`endif`, kruisverwijzingen van
functies, pariteit van `arrahma_groepen()` vs `GROEPEN` — 12 sleutels, identiek en in dezelfde volgorde).
**Test op staging voordat dit live gaat** — met name de Instellingen-pagina (opslaan van zowel status
als capaciteit), de nieuwe groepskeuze en een echte inzending.

## Deployen

Nog steeds handmatig zippen en uploaden via wp-admin — zie
[Plugin deploy automation](../wayfinder/maps/deploy-automation.md) (geparkeerd).
