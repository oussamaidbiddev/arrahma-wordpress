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
koppelen categorie + niveau + dag + tijd. Deze twee **moeten identiek blijven**. 11 groepen.
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
Zondag 19:00. Staat **niet publiek op het formulier** — de groep verschijnt alleen voor bezoekers
met de toegangslink, en is dan een gewone, direct inschrijfbare groep. De link deel je uit aan wie
de niveautoets heeft gehaald; de toets zit dus vóór de link in plaats van erna.
Zie *Alleen via link* hieronder.

**Status per lesblok/lesgroep** (alle 16: 5 kinderen-lesblokken + 11 groepen), instelbaar via
**Instellingen**: `open` | `gesloten` (zichtbaar, niet kiesbaar, badge "Gesloten") | `verborgen` (niet getoond)
| `op_uitnodiging` (zie hieronder). Serverzijde afgedwongen (`slot_closed`, HTTP 409) in zowel de losse
als de bulk-inzending. De site-brede open/gesloten-schakelaar gaat hiervóór.

**Alleen via link (`op_uitnodiging`).** Zo'n lesgroep gedraagt zich als `verborgen`, behalve wanneer de
bezoeker zijn sleutel in de URL heeft: `?toegang=br_volw_n5_zo`. Meerdere sleutels mogen met komma's
(`?toegang=br_volw_n5_zo,br_volw_n4_zo`); andere querystring-parameters storen niet. Met de link is de
groep gewoon kiesbaar, telt de capaciteit mee en accepteert de server de inzending.

Dit is **bewust geen beveiliging** — het zorgt er alleen voor dat de groep niet publiek op het
formulier staat. Wie de link heeft (of doorstuurt) kan inschrijven; de sleutel is te raden. Dat is de
afgesproken afweging: de niveautoets gaat vooraf, de link is de uitkomst daarvan, niet de poort.

**Toegangslink-modus: de rest gaat op slot.** Ontgrendelt de link minstens één lesmoment, dan staat het
formulier in linkmodus en wordt álles wat niet in de link zit vergrendeld — zichtbaar maar grijs
(`opacity .55`), niet aanklikbaar, met een neutraal grijs badge **"Vergrendeld"** (`.lock-badge`,
bewust niet het rood van "Vol"/"Gesloten"). Dat geldt op drie plekken tegelijk: de doelgroepen op
stap 1, de lesgroepen op stap 2, en de kinderen-lesblokken op stap 3 inclusief de per-kind-dropdown in
bulkmodus. De knop "Meerdere kinderen" gaat mee op slot zodra de link niet voor Kinderen geldt.

Er staat bewust géén uitlegtekst bij: het grijs plus het badge spreekt voor zich, en de bezoeker kwam
zelf via de link binnen. (Een uitleg-box bovenaan stap 1 en 2 is gebouwd en op 2026-09-01 weer
verwijderd.)

**Doelgroep wordt alvast gekozen.** De bijbehorende doelgroep staat op stap 1 meteen aangevinkt, zodat
de bezoeker in één klik bij zijn niveau is. Alleen als álle meegegeven codes bij dezelfde doelgroep
horen — wijzen ze naar meerdere, dan blijven díe doelgroepen allemaal open (de rest op slot) en is er
geen voorselectie. Codes die niets ontgrendelen tellen niet mee: onbekend (`isBekendSlot()`), of een
groep die inmiddels `gesloten`/`verborgen` is. Een doelgroep die zelf dicht staat wordt nooit
voorgeselecteerd. Handmatig klikken en automatisch kiezen lopen via dezelfde functie
(`kiesCategorie()`), dus ze doen gegarandeerd hetzelfde. Na "nog een inschrijving" wordt de doelgroep
opnieuw voorgeselecteerd — de link geldt immers nog steeds.

> **Let op bij `isBekendSlot()`:** zonder die controle geldt een onbekende sleutel als `open`
> (`slotStatusVoor()` valt terug op `'open'` voor sleutels die het niet kent). Eén typefout in de link
> zou dan linkmodus activeren en het hele formulier vergrendelen zónder dat er iets kiesbaar is.

De parameternaam staat in `ARRAHMA_TOEGANG_PARAM` (plugin) en `TOEGANG_PARAM` (index.html) en moet
gelijk blijven. Een groep kan via `standaard_status` / `standaardStatus` in `arrahma_groepen()` /
`GROEPEN` standaard op deze status staan; `br_volw_n5_zo` doet dat. Zodra de status in Instellingen is
opgeslagen, toont die pagina het stukje URL om achter de inschrijfpagina te plakken.

Het oude `toets`-mechanisme (mailto-kaart "Niveautoets aanvragen") bestaat nog in de code, maar wordt
sinds 2026-08-31 door geen enkele groep meer gebruikt.

**De doelgroep op stap 1 volgt zijn lesmomenten.** Is géén enkel lesmoment van een doelgroep nog
zichtbaar, dan verdwijnt de doelgroep helemaal van stap 1. Zijn er wel zichtbare lesmomenten maar is
er niets kiesbaar, dan blijft de doelgroep staan maar grijs en niet aanklikbaar, met de badge
"Gesloten". Eén open lesmoment is genoeg om de doelgroep open te houden. Afgeleid in
`categorieStatus()`, toegepast door `applyCategoryStatuses()` — er is dus geen aparte
doelgroep-schakelaar in de admin en niets kan uit de pas lopen.

De knop **"Meerdere kinderen"** volgt de doelgroep Kinderen mee (bulk-inschrijving is kinderen-only):
weg bij verborgen, uitgeschakeld bij gesloten. Stond bulk aan op het moment dat Kinderen dichtgaat,
dan springt het formulier terug naar "Eén inschrijving". Een al gekozen doelgroep die dichtgaat wordt
losgelaten (radio uit, `selectedCategory` leeg).

Staat géén enkele doelgroep meer open, dan toont het formulier het gesloten-bericht in plaats van vijf
grijze kaarten zonder uitleg — feitelijk hetzelfde als de site-brede schakelaar op *Gesloten*.

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
| 3 | Niveau 5 stond onder béide broeders-doelgroepen. | **Alleen volwassenen (17+).** `br_jong_n5_zo` verwijderd uit `arrahma_groepen()` en `GROEPEN`. |
| 4 | Beginnersgroep `br_jong_n1_zo` toegevoegd voor broeders jongeren. | **Bevestigd correct.** Rooster later gecorrigeerd — zie hieronder. |
| 5 | Reglement jongeren & volwassenen niet te openen (HTTP 403 op geautomatiseerde requests). | Alleen een beperking van deze omgeving — de PDF opent gewoon vanaf de eigen machine. URL in `REGLEMENT_URL_JONGEREN_VOLWASSENEN` is goed. |
| 6 | `showClosedMessage()` was half afgemaakt. | **Afgemaakt**: leeg bericht laat de standaardtekst staan, en `display:block` wordt nu altijd op de `<p>` gezet. Getest mét een `p:empty { display:none }`-themaregel actief, ook vanuit een écht lege paragraaf. |

### Rooster broeders jongeren (12–16) gecorrigeerd — 2026-08-30

De briefingtabel klopte niet voor deze doelgroep. Definitief, **twee** groepen:

| Sleutel | Niveau | Dag | Tijd |
|---|---|---|---|
| `br_jong_n1_zo` | Niveau 1 | Zondag | 11:30–13:30 |
| `br_jong_n2_za` | Niveau 2 | Zaterdag | 11:30–13:30 |

Wat er veranderde: de zaterdaggroep was Niveau 3 en is Niveau 2 geworden (sleutel `br_jong_n3_za` →
`br_jong_n2_za`); de zondaggroep blijft Niveau 1 maar loopt nu 11:30–13:30 in plaats van 11:00–12:30;
de tweede zondaggroep (`br_jong_n2_zo`) is vervallen. **Niveau 3 bestaat niet voor 12–16.**
Daarmee 12 → 11 lesgroepen. De vervallen sleutels stonden nergens in de database — het formulier is
nooit gepubliceerd — en eventuele resten in `arrahma_slot_status` / `arrahma_slot_caps` worden
genegeerd, omdat beide helpers over de actuele `arrahma_roster_labels()` lopen.

### Toegangslink voor Niveau 5 — 2026-08-31

Niveau 5 was een mailto-kaart ("Niveautoets aanvragen") die iedereen zag. Nu: standaard
`op_uitnodiging`, dus onzichtbaar tot iemand met `?toegang=br_volw_n5_zo` binnenkomt, en dan direct
inschrijfbaar. Toegevoegd als een vierde slot-status, niet als eenmalige uitzondering voor deze groep,
zodat het via Instellingen op elk lesblok of elke lesgroep te zetten is.

Meegenomen: de bulk-inzending (meerdere kinderen) controleerde de slot-status helemaal niet
serverzijde — alleen de capaciteit. Een `gesloten` of `verborgen` kinderen-lesblok was daar dus nog
inzendbaar. Nu doet die handler dezelfde controle als de losse inzending.

### E-mails: doelgroepgrens en aanspreekvorm — 2026-09-07

**Wie mag welke e-mail krijgen.** `arrahma_email_recipients()` selecteert álle rijen met een
e-mailadres, dus sinds deze doelgroepen bestaan stond een broeder van 30 gewoon tussen de "ouders".
`arrahma_email_types()` legt nu per e-mailtype vast voor welke doelgroepen het bedoeld is
(`ouderavond` → alleen `kinderen`; `bevestiging` en `indeling` → alle). Filtering gebeurt **per rij**,
want één adres kan gemengd zijn: een vader met twee kinderen die zichzelf ook inschreef krijgt de
ouderavond-mail met alleen zijn kinderen erin. Zie ook
[parent-meeting-emails](../wayfinder/maps/parent-meeting-emails.md).

**Handmatige doelgroepkeuze bij het versturen.** Naast de vaste grens per e-mailtype staat er op de
verzendpagina een keuzelijst *Welke doelgroep?* (`Alle doelgroepen` + elke actieve doelgroep). Die
versmalt de selectie verder, zodat je bij één e-mailadres met twee kinderen én een volwassene alleen
de plaatsing van de kinderen kunt sturen; de volwassene krijgt zijn eigen mail wanneer jij die
verstuurt. De effectieve set is de **doorsnede** van de doelgroepen van het e-mailtype en de gekozen
doelgroep — de keuzelijst kan een type dus nooit oprekken: `ouderavond` + "Broeders — volwassenen"
levert niets op en grijst alles uit.

De kolom **Aantal** toont wat er daadwerkelijk in de e-mail komt, niet het totaal op dat adres: bij
doelgroep "Kinderen" staat er 2 bij een gezin van 2 kinderen + 1 volwassene. De UI rekent dat uit met
`data-counts` per rij (`{"kinderen":2,"broeders_volwassenen":1}`) en dezelfde type→doelgroep-tabel als
de server (`TYPE_CATS`), zodat wat je ziet en wat er verstuurd wordt niet uit elkaar kunnen lopen.
Serverzijde doet `arrahma_rows_for_email_type( $rows, $type, $doelgroep )` dezelfde berekening nog een
keer; ontvangers zonder passende rij worden overgeslagen en geteld in de bevestigingsmelding.

**Nieuwe e-mail: definitieve plaatsing** (`arrahma_send_indeling_email()`) — dag en tijd van het
toegewezen lesmoment, bewust zonder geboortedatum, adres, IBAN of betaalwijze. Eén blok per
ingeschrevene, dus een gezin krijgt alle kinderen in één mail; bij meerdere staat de naam boven het
blok. Niet-ingedeelden tonen "Nog niet ingedeeld".

**Opmaak: uitsluitend de gedeelde bouwstenen.** De mail gebruikt dezelfde onderdelen als alle andere
e-mails — `arrahma_email_wrap()` als omhulsel, de `<h2>`-begroeting, de bodyparagraaf
(`font-size:15px;line-height:1.7;color:#444`), het kopje in kapitalen
(`11px / letter-spacing .1em / uppercase / #2d3a4a`), de gegevenstabel met `arrahma_email_row()` en de
info-box met de linkerrand. Dag en tijd staan dus als twee tabelrijen ("📅 Dag" / "🕐 Tijd") onder een
kopje, precies zoals "Gegevens ingeschrevene" in de bevestigingsmail. Een eerdere versie had hiervoor
eigen blokstijlen; die zijn vervangen omdat de mail binnen het bestaande sjabloon moet blijven.

De **tekst voor kinderen is aangeleverd door de Religieuze Commissie** en staat er zo goed als
letterlijk in, inclusief de opening "Assalam alaykoum wa rahmatullahi wa barakatuh," (dus zonder naam
— die staat niet in de aangeleverde tekst), de 📅/🕐-regels, het stuk over de **verplichte
ouderbijeenkomst** en de ondertekening "Religieuze Commissie / Afdeling Arabisch Onderwijs".
Enkelvoud/meervoud wisselt mee ("jullie kind" ↔ "jullie kinderen", "kan jullie kind" ↔ "kunnen zij").

Elke lesmoment-tabel begint met een **Naam**-regel, ook bij één ingeschrevene, zodat de ontvanger niet
uit de aanhef hoeft af te leiden over wie het gaat. Bij meerdere staat er "Lesmoment 1", "Lesmoment 2"
boven de tabellen.

**Startdatum van de lessen** staat in `ARRAHMA_START_LESSEN` (nu `maandag 5 oktober 2026`) en wordt
alleen in de kinderenversie getoond, in een uitgelicht blok onder het lesmoment. Elk schooljaar is dat
één constante bijwerken.

> **Waarom "de lessen starten op" en niet "de eerste les is op".** 5 oktober 2026 is een maandag,
> terwijl de kinderen-lesblokken op verschillende dagen vallen (za/zo, ma/wo, di/do). Alleen voor het
> `ma_wo`-blok zou "je eerste les is op 5 oktober" kloppen; voor de andere vier zou het onjuist zijn.
> De formulering noemt de datum onverkort, maar koppelt het kind aan zijn eigen lesmoment:
> "Vanaf die week wordt jullie kind op het hierboven genoemde lesmoment verwacht."

**Onderwerpregel.** Is er op één doelgroep gefilterd, dan komt die tussen haakjes in het onderwerp:
*Definitieve plaatsing (Kinderen) — Vereniging Arrahma*. Bij "Alle doelgroepen" blijft het onderwerp
neutraal, want de e-mail gaat dan over meerdere doelgroepen. Daarvoor bestaat
`arrahma_category_short_label()`: de volledige labels ("Broeders — volwassenen (17+)") bevatten zelf
al haakjes en een gedachtestreepje en lopen vast in een onderwerpregel.

Wie zichzelf inschrijft (12+: jongeren én volwassenen) krijgt sinds 2026-09-10 een **eigen, eveneens
door de Religieuze Commissie aangeleverde tekst** (`arrahma_indeling_html_zelf()`): geen startdatum —
die volgt via de WhatsApp-groep van de lesgroep —, geen ouderbijeenkomst, geen "We proberen op hetzelfde
lesmoment…"-alinea, maar wel een blok *WhatsApp-groep* met de vraag om te melden als je niets hebt
ontvangen. Onderwerp: "Bevestiging plaatsing Arabisch onderwijs – (volwassenen) | Vereniging Arrahma";
het haakje wordt uit de inschrijvingen afgeleid — "(jongeren)" voor 12–16, "(volwassenen)" voor 17+ —
en vervalt als één e-mail beide bevat (`arrahma_indeling_onderwerp_zelf()`). De kinderenversie is bij die
wijziging tekenletterlijk gelijk gebleven (voor/na-render in een test-WordPress vergeleken). `gemengd` (kinderen én een
volwassene op één adres) krijgt wél de oudertekst: er zitten kinderen bij, dus die verplichting geldt.

Dag en tijd worden apart getoond, dus die moeten los beschikbaar zijn. Daarvoor is
`arrahma_kinderen_blokken()` toegevoegd — de kinderen-lesblokken met `dag`/`tijd` als losse velden,
net als `arrahma_groepen()` al had — en `arrahma_slot_dag_tijd()` die voor beide soorten sleutels
werkt. `arrahma_roster_labels()` bouwt de kinderen-labels nu uit die map op in plaats van
hardgecodeerde strings, zodat er één bron van waarheid is; de labels zijn daarbij tekenletterlijk
gelijk gebleven (gecontroleerd), dus admin, CSV en de bevestigingsmail veranderen niet.

**Aanspreekvorm.** `arrahma_email_doelgroep_soort()` bepaalt uit de rijen wie er meeleest:
`ouder` (alles kinderen), `zelf` (niemand een kind) of `gemengd`. Dat stuurt de intro, de zin over
indelen ("je kind" / "je" / "iedereen") en de kopjes boven de gegevens ("Gegevens kind" vs
"Gegevens ingeschrevene").

> **Waarom de begroeting soms geen naam heeft.** Bij een kind staat in `voornaam` de naam van het
> *kind*, terwijl de ouder de mail leest, en het vinkje "contactpersoon is iemand anders" is
> optioneel. Stond dat niet aan, dan begon de bevestiging met *"As-salāmu ʿalaykum Aisha,"* — gericht
> aan het kind. `arrahma_email_aanhef()` groet daarom neutraal zodra we de lezer niet met zekerheid
> kunnen benoemen. Bij een bulk-inschrijving speelt dit niet: die zet `cp_anders` altijd op 1.

### Batchgewijs versturen + verzendlog — 2026-09-07

Bij ~83 ontvangers liep de oude opzet tegen een muur: één klik was één HTTP-verzoek met alle
`wp_mail()`-aanroepen achter elkaar. Bij ~1 seconde per SMTP-handshake is dat 80+ seconden, terwijl
gedeelde hosting vaak op 30 of 60 seconden staat. Erger dan het afbreken zelf: de succesmelding komt
ná de lus, dus bij een timeout zag je een witte pagina en wist je niet hoeveel er verstuurd waren — en
opnieuw versturen betekende dubbele e-mails naar iedereen die al gemaild was.

**Batches.** De browser stuurt nu per `ARRAHMA_BATCH_SIZE` (10) ontvangers een AJAX-verzoek naar
`wp_ajax_arrahma_send_batch`, met een voortgangsbalk. Elk verzoek blijft daarmee ruim binnen een
krappe tijdslimiet. Gaat een batch mis, dan **stopt** de lus bewust in plaats van door te razen: je
weet dan precies tot waar het goed ging. Het formulier werkt zonder JavaScript nog steeds, maar dan
via het oude pad-in-één-verzoek — beide paden lopen door dezelfde
`arrahma_send_to_recipient()`, dus de regels kunnen niet uit elkaar lopen.

**Verzendlog** in de nieuwe tabel `{prefix}arrahma_email_log`
(`email`, `email_type`, `doelgroep`, `aantal`, `gelukt`, `verzonden_op`). Aangemaakt via `dbDelta()`
binnen `arrahma_create_table()`, die door de bestaande `plugins_loaded`-versiecontrole opnieuw draait
— dus de tabel verschijnt na het uploaden vanzelf, zonder de plugin te hoeven deactiveren.

Daarmee: de kolom **Al gemaild** toont per e-mailtype wanneer dat adres het laatst iets kreeg, en het
vinkje **"Sla over wie deze e-mail al heeft gehad"** (standaard aan) maakt hervatten na een
onderbreking veilig. Uitzetten om bewust opnieuw te versturen — dat blijft dus mogelijk, het is alleen
niet langer de standaard.

**Mislukte verzendingen zijn nu zichtbaar.** De drie ontvanger-mails geven `bool` terug in plaats van
`void`, zodat `wp_mail()`-fouten worden gelogd (`gelukt = 0`) en per adres in de voortgang verschijnen.
Voorheen verdween een mislukte verzending geruisloos.

> **Let op — dit is geen bewijs van bezorging.** `wp_mail()` geeft alleen aan dat de mail is
> overgedragen aan de mailer. De Email Deliverability-plugin herschrijft de afzender naar
> `sitemailerservice.com`; wat die relay ermee doet (limieten, bounces) staat niet in dit log.

## Open punten

| # | Punt | Status |
|---|------|--------|
| 1 | **Geen enkele regel PHP is ooit uitgevoerd** — zie *Verificatie*. Vooral de Instellingen-pagina (opslaan van status én capaciteit) en een echte inzending moeten nog draaien. | Staging-test |
| 2 | De sleutel `zu_jong_basis_wo` eindigt op `_wo` (woensdag) maar de groep staat inmiddels op **dinsdag**. Alleen cosmetisch; hernoemen zou opgeslagen status/capaciteit onder die sleutel loskoppelen. | Laten staan, tenzij het verwarring geeft |

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
- **Doelgroepstatus afgeleid uit de lesmomenten.** Alle lesmomenten verborgen → kaart weg; alle
  gesloten → kaart zichtbaar, grijs (opacity .5), niet aanklikbaar, badge "Gesloten"; verborgen én
  gesloten door elkaar zonder iets kiesbaars → ook grijs; één gesloten tussen open lesmomenten →
  doelgroep blijft gewoon open. Weer opengezet verdwijnt de badge en is de kaart weer bruikbaar.
  Kinderen verborgen/gesloten stuurt de bulk-knop mee (weg / uitgeschakeld), een actieve bulk-modus
  valt terug naar "Eén inschrijving", en een al geselecteerde doelgroep die dichtgaat wordt losgelaten.
  Met álles dicht (of álles verborgen) geeft `eenDoelgroepOpen()` false, waarna het gesloten-bericht
  verschijnt.
- **Toegangslink.** Zonder `?toegang=` is Niveau 5 volledig afwezig (4 kaarten in plaats van 5) terwijl
  de doelgroep zelf gewoon open blijft; mét de link staat het er als normale, kiesbare kaart en levert
  de payload `niveau: n5` / `rooster: br_volw_n5_zo`. De code ontgrendelt alléén zijn eigen groep — een
  tweede `op_uitnodiging`-groep bleef verborgen. Komma-gescheiden codes ontgrendelen er meerdere, een
  extra parameter (`&utm_source=…`) stoort niet, en capaciteit werkt gewoon (cap 2 met 2 inschrijvingen
  → "Vol", niet kiesbaar). Staat een hele doelgroep op `op_uitnodiging`, dan verdwijnt die doelgroep
  van stap 1 zolang de bezoeker de codes niet heeft. Getest over `http://` — de querystring is nodig,
  dus dit gaat niet via een `file://`-preview.
- **Voorselectie van de doelgroep.** Met `?toegang=br_volw_n5_zo` staat "Broeders — volwassenen" na het
  laden aangevinkt (radio én kaartopmaak) en is stap 2 al ingericht met Niveau 5 in de lijst. Zonder de
  parameter verandert er niets: geen selectie, geen gemarkeerde kaart. Codes voor twee doelgroepen of
  een onbekende code leiden tot géén voorselectie; een code voor een kinderen-lesblok kiest Kinderen;
  een code waarvan de groep op `gesloten` staat en een doelgroep die zelf dicht is worden geweigerd.
  Na `startNewInschrijving()` staat de doelgroep er weer. Werkt ook als `/form-mode` onbereikbaar is,
  omdat `standaardStatus` uit `GROEPEN` dan de status levert — precies zoals in het catch-pad getest.
- **Vergrendelen van de rest.** Met `?toegang=br_volw_n5_zo`: vier doelgroepen grijs (opacity 0.5,
  `disabled`, badge "Vergrendeld"), Broeders — volwassenen normaal én aangevinkt; op stap 2 vier
  lesgroepen grijs met badge en alleen Niveau 5 kiesbaar; de bulk-knop uitgeschakeld; de infobox op
  stap 2 toont gewoon de standaardtekst. Met `?toegang=ma_wo`: Kinderen gekozen, bulk blíjft beschikbaar,
  de vier andere lesblokken grijs met badge, en in de bulk-dropdown staan ze als "(Vergrendeld)" en
  `disabled`. Met twee codes uit verschillende doelgroepen blijven beide doelgroepen open, gaan de
  andere drie op slot en volgt er geen voorselectie. Zonder link: nul `.lock-badge`-elementen, nul
  uitgeschakelde doelgroepen, normale doorloop ongewijzigd.

**PHP is nooit uitgevoerd.** Er is geen PHP-interpreter en geen draaiende Docker-daemon in deze omgeving,
dus de plugin is alleen structureel gecontroleerd (haakjesbalans, `if`/`endif`, kruisverwijzingen van
functies, pariteit van `arrahma_groepen()` vs `GROEPEN` — 12 sleutels, identiek en in dezelfde volgorde).
**Test op staging voordat dit live gaat** — met name de Instellingen-pagina (opslaan van zowel status
als capaciteit), de nieuwe groepskeuze en een echte inzending.

## Deployen

Nog steeds handmatig zippen en uploaden via wp-admin — zie
[Plugin deploy automation](../wayfinder/maps/deploy-automation.md) (geparkeerd).
