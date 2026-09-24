<?php
/**
 * Plugin Name: Arrahma Inschrijvingen
 * Description: Slaat lesaanmeldingen op in de database en toont ze in een overzichtspagina met CSV-export.
 * Version:     1.17.0
 * Author:      Vereniging Arrahma
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ARRAHMA_TABLE',       'arrahma_inschrijvingen' );

// Verzendlog: wie welke e-mail wanneer kreeg. Maakt hervatten na een onderbroken verzending
// mogelijk en voorkomt dubbele e-mails.
define( 'ARRAHMA_LOG_TABLE',   'arrahma_email_log' );

// Aantal e-mails per HTTP-verzoek tijdens het verzenden. Klein genoeg om ruim binnen een
// krappe max_execution_time (30s) te blijven, ook als een SMTP-verbinding traag is.
define( 'ARRAHMA_BATCH_SIZE',  10 );
define( 'ARRAHMA_VERSION',     '1.17.0' );

// Standaardcapaciteit. De capaciteit is per lesblok/lesgroep in te stellen via
// Inschrijvingen → Instellingen; deze waarden gelden zolang daar niets is opgeslagen.
// Moeten gelijk blijven aan ROOSTER_CAP / GROEP_CAP in index.html.
define( 'ARRAHMA_ROOSTER_CAP', 30 ); // kinderen — per lesdagen-tijdslot
define( 'ARRAHMA_GROEP_CAP',   20 ); // jongeren & volwassenen — per lesgroep

// Grenzen waarbinnen een ingestelde capaciteit moet vallen.
const ARRAHMA_CAP_MIN = 1;
const ARRAHMA_CAP_MAX = 999;

// Staan inschrijvingen open? 'open' toont het formulier, 'gesloten' toont een bericht.
define( 'ARRAHMA_FORM_MODE_OPTION',      'arrahma_form_mode' );
define( 'ARRAHMA_CLOSED_MESSAGE_OPTION', 'arrahma_closed_message' );

// Status per lesblok/lesgroep: 'open' | 'gesloten' (zichtbaar, niet kiesbaar) | 'verborgen'.
define( 'ARRAHMA_SLOT_STATUS_OPTION',    'arrahma_slot_status' );

// Capaciteit per lesblok/lesgroep: [ sleutel => aantal ]. Ontbrekend = standaard hierboven.
define( 'ARRAHMA_SLOT_CAP_OPTION',       'arrahma_slot_caps' );

// Startdatum van het lesjaar, zoals genoemd in de plaatsingsmail voor kinderen.
// Elk schooljaar hier bijwerken — de tekst eromheen hoeft dan niet aangeraakt te worden.
define( 'ARRAHMA_START_LESSEN', 'maandag 5 oktober 2026' );

// Lesgroepen met status 'op_uitnodiging' verschijnen alleen als de bezoeker hun sleutel
// meekrijgt in de URL: ?toegang=br_volw_n5_zo (meerdere gescheiden door komma's).
// Bewust géén beveiliging — wie de link heeft, mag inschrijven.
// Moet gelijk blijven aan TOEGANG_PARAM in index.html.
define( 'ARRAHMA_TOEGANG_PARAM', 'toegang' );

// Ouderavond-uitnodiging: prefill-doelvelden op het Google Form (zie docs/wayfinder/tickets/parent-meeting-emails/01-create-google-form.md)
define( 'ARRAHMA_OUDERAVOND_FORM_ID',     '1FAIpQLSflfMJHypxN8eccuTc5ScD-UWaqzG941QJ2rvTKD-iK5l-q2g' );
define( 'ARRAHMA_OUDERAVOND_ENTRY_EMAIL', '1335557813' ); // "Ouder e-mail"
define( 'ARRAHMA_OUDERAVOND_ENTRY_KIND',  '366086080' );  // "Kind(eren)"

// ─────────────────────────────────────────────────────────────
// ACTIVATIE & DATABASE
// ─────────────────────────────────────────────────────────────
register_activation_hook( __FILE__, 'arrahma_create_table' );

function arrahma_create_table() {
    global $wpdb;
    $table   = $wpdb->prefix . ARRAHMA_TABLE;
    $charset = $wpdb->get_charset_collate();

    // dbDelta handles both CREATE and ALTER safely
    $sql = "CREATE TABLE {$table} (
        id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        inschrijving_voor    VARCHAR(30)  NOT NULL,
        voornaam             VARCHAR(100) NOT NULL,
        achternaam           VARCHAR(100) NOT NULL,
        geboortedatum        DATE         NULL,
        telefoon             VARCHAR(30)  NOT NULL DEFAULT '',
        email                VARCHAR(150) NOT NULL,
        adres                VARCHAR(200) NOT NULL,
        postcode             VARCHAR(10)  NOT NULL,
        woonplaats           VARCHAR(100) NOT NULL,
        niveau               VARCHAR(50)  NOT NULL,
        rooster              VARCHAR(40)  NOT NULL DEFAULT '',
        klas                 VARCHAR(20)  NOT NULL DEFAULT '',
        rekeningnummer       VARCHAR(40)  NOT NULL,
        naam_rekeninghouder  VARCHAR(150) NOT NULL,
        betaalwijze          VARCHAR(20)  NOT NULL DEFAULT 'maandelijks',
        cp_anders            TINYINT(1)   NOT NULL DEFAULT 0,
        cp_voornaam          VARCHAR(100) NULL,
        cp_achternaam        VARCHAR(100) NULL,
        cp_telefoon          VARCHAR(30)  NULL,
        groep_id             VARCHAR(36)  NULL,
        status               VARCHAR(20)  NOT NULL DEFAULT 'nieuw',
        datum_inschrijving   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) {$charset};";

    $log_table = $wpdb->prefix . ARRAHMA_LOG_TABLE;
    $log_sql   = "CREATE TABLE {$log_table} (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email        VARCHAR(150) NOT NULL,
        email_type   VARCHAR(30)  NOT NULL,
        doelgroep    VARCHAR(30)  NOT NULL DEFAULT '',
        aantal       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        gelukt       TINYINT(1)   NOT NULL DEFAULT 1,
        verzonden_op DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY type_email (email_type, email(100))
    ) {$charset};";

    $aanwezig_table = $wpdb->prefix . ARRAHMA_AANWEZIG_TABLE;
    $aanwezig_sql   = "CREATE TABLE {$aanwezig_table} (
        id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        inschrijving_id  BIGINT UNSIGNED NOT NULL,
        rooster          VARCHAR(40)     NOT NULL,
        lesdatum         DATE            NOT NULL,
        status           VARCHAR(12)     NOT NULL,
        door             BIGINT UNSIGNED NOT NULL DEFAULT 0,
        gewijzigd_op     DATETIME        NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniek (inschrijving_id,lesdatum,rooster),
        KEY les (rooster,lesdatum)
    ) {$charset};";

    $notitie_table = $wpdb->prefix . ARRAHMA_NOTITIE_TABLE;
    $notitie_sql   = "CREATE TABLE {$notitie_table} (
        id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        inschrijving_id  BIGINT UNSIGNED NOT NULL,
        tekst            TEXT            NOT NULL,
        auteur           BIGINT UNSIGNED NOT NULL DEFAULT 0,
        aangemaakt_op    DATETIME        NOT NULL,
        gewijzigd_op     DATETIME        NULL,
        PRIMARY KEY  (id),
        KEY inschrijving (inschrijving_id)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    dbDelta( $log_sql );
    dbDelta( $aanwezig_sql );
    dbDelta( $notitie_sql );
    arrahma_registreer_docent_rol();

    update_option( 'arrahma_db_version', ARRAHMA_VERSION );
}

// Run table update on every plugin load (catches upgrades from v1.0.0)
add_action( 'plugins_loaded', function () {
    if ( get_option( 'arrahma_db_version' ) !== ARRAHMA_VERSION ) {
        arrahma_create_table();
    }
} );

// ─────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────
/**
 * Alle categorieën, inclusief oude waarden zodat bestaande inschrijvingen leesbaar blijven.
 * Alleen de categorieën uit arrahma_active_categories() staan nog op het formulier.
 */
function arrahma_category_labels(): array {
    return [
        'kinderen'             => 'Kinderen (6–11)',
        'broeders_jongeren'    => 'Broeders — jongeren (12–16)',
        'broeders_volwassenen' => 'Broeders — volwassenen (17+)',
        'zusters_jongeren'     => 'Zusters — jongeren (12–16)',
        'zusters_volwassenen'  => 'Zusters — volwassenen (17+)',

        // ── Oud (niet meer op het formulier, blijven bestaan voor reeds opgeslagen inschrijvingen)
        'tieners_broeders' => 'Tieners — Broeders (12–17)',
        'tieners_zusters'  => 'Tieners — Zusters (12–17)',
        'broeders_18plus'  => 'Broeders 18+',
        'zusters_18plus'   => 'Zusters 18+',
    ];
}

/** Categorieën die het formulier daadwerkelijk aanbiedt. */
/**
 * Korte doelgroepnaam, bedoeld voor onderwerpregels.
 *
 * De volledige labels ("Broeders — volwassenen (17+)") bevatten zelf al een gedachtestreepje en
 * haakjes; die lopen vast in een onderwerp dat zelf ook haakjes gebruikt.
 */
function arrahma_category_short_label( string $categorie ): string {
    $kort = [
        'kinderen'             => 'Kinderen',
        'broeders_jongeren'    => 'Broeders 12–16',
        'broeders_volwassenen' => 'Broeders 17+',
        'zusters_jongeren'     => 'Zusters 12–16',
        'zusters_volwassenen'  => 'Zusters 17+',
    ];
    return $kort[ $categorie ] ?? ( arrahma_category_labels()[ $categorie ] ?? $categorie );
}

/**
 * Statussen van een inschrijving. "uitgeschreven" = halverwege gestopt: verdwijnt uit de klaslijsten,
 * telt niet mee voor de capaciteit en krijgt geen bulkmail meer, maar de aanwezigheid blijft bewaard.
 */
function arrahma_statussen(): array {
    return [ 'nieuw', 'verwerkt', 'afgewezen', 'uitgeschreven' ];
}

function arrahma_active_categories(): array {
    return [ 'kinderen', 'broeders_jongeren', 'broeders_volwassenen', 'zusters_jongeren', 'zusters_volwassenen' ];
}

function arrahma_niveau_labels(): array {
    return [
        // ── Kinderen (Onderwijsreglement v1.0)
        'instroom'  => 'Instroomniveau',
        'basis'     => 'Basisniveau',
        'gevorderd' => 'Gevorderd niveau',

        // ── Broeders jongeren & volwassenen (briefing 2026–2027)
        'n1' => 'Niveau 1 — Alif Baa | Basis',
        'n2' => 'Niveau 2 — Letters herkennen & leesvaardigheid',
        'n3' => 'Niveau 3 — Lezen & basisgrammatica',
        'n4' => 'Niveau 4 — Grammatica toepassen & tekstverwerking',
        'n5' => 'Niveau 5 — al-Ājurrūmiyyah',

        // ── Zusters jongeren & volwassenen
        'z_basis'     => 'Basis — Alif Baa',
        'z_gevorderd' => 'Gevorderd Arabisch',
    ];
}

/**
 * Lesgroepen voor jongeren & volwassenen. Elke groep koppelt categorie + niveau aan één vast
 * lesmoment, dus de deelnemer kiest niveau en lesmoment in één keer.
 *
 * Optionele velden per groep:
 *   'standaard_status' => een van arrahma_slot_status_labels(); geldt zolang er in Instellingen
 *                         niets is opgeslagen. Zonder dit veld staat een groep standaard open.
 *   'toets' => true     => geen directe inschrijving, eerst een niveautoets aanvragen (mailto-kaart).
 *                         Op dit moment door geen enkele groep gebruikt: Niveau 5 loopt sinds
 *                         2026-08-31 via een toegangslink. Het mechanisme blijft beschikbaar.
 *
 * UITBREIDEN: hier een regel toevoegen is genoeg — formulier, capaciteit, admin en CSV volgen mee.
 */
function arrahma_groepen(): array {
    return [
        // ── Broeders
        'br_volw_n1_zo' => [ 'categorie' => 'broeders_volwassenen', 'niveau' => 'n1', 'dag' => 'Zondag',   'tijd' => '09:30–11:00' ],
        'br_volw_n2_zo' => [ 'categorie' => 'broeders_volwassenen', 'niveau' => 'n2', 'dag' => 'Zondag',   'tijd' => '11:00–12:30' ],
        'br_volw_n3_za' => [ 'categorie' => 'broeders_volwassenen', 'niveau' => 'n3', 'dag' => 'Zaterdag', 'tijd' => '09:30–11:00' ],
        'br_volw_n4_zo' => [ 'categorie' => 'broeders_volwassenen', 'niveau' => 'n4', 'dag' => 'Zondag',   'tijd' => '09:30–11:00' ],
        'br_jong_n1_zo' => [ 'categorie' => 'broeders_jongeren',    'niveau' => 'n1', 'dag' => 'Zondag',   'tijd' => '11:30–13:30' ],
        'br_jong_n2_za' => [ 'categorie' => 'broeders_jongeren',    'niveau' => 'n2', 'dag' => 'Zaterdag', 'tijd' => '11:30–13:30' ],

        // Niveau 5 (al-Ājurrūmiyyah): alleen voor volwassen broeders (17+), en staat standaard
        // niet publiek op het formulier — de groep verschijnt pas met de toegangslink, die wordt
        // uitgedeeld aan wie de niveautoets heeft gehaald. Zie ARRAHMA_TOEGANG_PARAM.
        'br_volw_n5_zo' => [ 'categorie' => 'broeders_volwassenen', 'niveau' => 'n5', 'dag' => 'Zondag', 'tijd' => '19:00', 'standaard_status' => 'op_uitnodiging' ],

        // ── Zusters
        'zu_volw_basis_ma' => [ 'categorie' => 'zusters_volwassenen', 'niveau' => 'z_basis',     'dag' => 'Maandag',   'tijd' => '19:00–20:30' ],
        'zu_volw_gev_do'   => [ 'categorie' => 'zusters_volwassenen', 'niveau' => 'z_gevorderd', 'dag' => 'Donderdag', 'tijd' => '19:00–20:30' ],
        'zu_jong_basis_wo' => [ 'categorie' => 'zusters_jongeren',    'niveau' => 'z_basis',     'dag' => 'Dinsdag',  'tijd' => '18:00–19:30' ],
        'zu_jong_gev_wo'   => [ 'categorie' => 'zusters_jongeren',    'niveau' => 'z_gevorderd', 'dag' => 'Woensdag',  'tijd' => '18:00–19:30' ],
    ];
}

/** Leesbare omschrijving van één lesgroep, bijv. "Zondag 09:30–11:00 — Niveau 1 — Alif Baa | Basis". */
function arrahma_groep_label( string $key ): string {
    $g = arrahma_groepen()[ $key ] ?? null;
    if ( ! $g ) return '';
    return $g['dag'] . ' ' . $g['tijd'] . ' — ' . arrahma_niveau_label( $g['niveau'] );
}

/**
 * Vaste lesblokken voor kinderen, met dag en tijd apart.
 *
 * Apart gehouden omdat de plaatsingsmail dag en tijd los van elkaar toont; het label eronder
 * wordt hieruit opgebouwd, zodat er één bron van waarheid blijft.
 */
function arrahma_kinderen_blokken(): array {
    return [
        'za_zo_blok1' => [ 'dag' => 'Zaterdag & zondag',   'tijd' => '09:00–11:00', 'blok' => 'blok 1' ],
        'za_zo_blok2' => [ 'dag' => 'Zaterdag & zondag',   'tijd' => '11:15–13:15', 'blok' => 'blok 2' ],
        'za_zo_blok3' => [ 'dag' => 'Zaterdag & zondag',   'tijd' => '13:30–15:30', 'blok' => 'blok 3' ],
        'ma_wo'       => [ 'dag' => 'Maandag & woensdag',  'tijd' => '15:30–17:30' ],
        'di_do'       => [ 'dag' => 'Dinsdag & donderdag', 'tijd' => '15:30–17:30' ],
    ];
}

/** Dag en tijd van één lesblok of lesgroep, los van elkaar. Onbekende sleutel geeft lege waarden. */
function arrahma_slot_dag_tijd( string $key ): array {
    $groep = arrahma_groepen()[ $key ] ?? null;
    if ( $groep ) {
        return [ 'dag' => $groep['dag'], 'tijd' => $groep['tijd'] ];
    }

    $blok = arrahma_kinderen_blokken()[ $key ] ?? null;
    if ( $blok ) {
        return [ 'dag' => $blok['dag'], 'tijd' => $blok['tijd'] ];
    }

    return [ 'dag' => '', 'tijd' => '' ];
}

/** Vaste lesblokken (kinderen) plus alle lesgroepen (jongeren & volwassenen). */
function arrahma_roster_labels(): array {
    $labels = [];

    foreach ( arrahma_kinderen_blokken() as $key => $blok ) {
        $labels[ $key ] = $blok['dag'] . ' — ' . $blok['tijd']
                        . ( isset( $blok['blok'] ) ? ' (' . $blok['blok'] . ')' : '' );
    }

    foreach ( array_keys( arrahma_groepen() ) as $key ) {
        $labels[ $key ] = arrahma_groep_label( $key );
    }

    return $labels;
}

/** Houdt een ingestelde capaciteit binnen ARRAHMA_CAP_MIN..ARRAHMA_CAP_MAX. */
function arrahma_clamp_cap( int $cap ): int {
    return max( ARRAHMA_CAP_MIN, min( ARRAHMA_CAP_MAX, $cap ) );
}

/** Standaardcapaciteit zolang er niets is ingesteld: lesgroepen wijken af van kinderen-lesblokken. */
function arrahma_default_cap_for( string $key ): int {
    return isset( arrahma_groepen()[ $key ] ) ? ARRAHMA_GROEP_CAP : ARRAHMA_ROOSTER_CAP;
}

/** Capaciteit van alle lesblokken en lesgroepen; ontbrekende sleutels vallen terug op de standaard. */
function arrahma_slot_caps(): array {
    $opgeslagen = get_option( ARRAHMA_SLOT_CAP_OPTION, [] );
    if ( ! is_array( $opgeslagen ) ) $opgeslagen = [];

    $caps = [];
    foreach ( array_keys( arrahma_roster_labels() ) as $key ) {
        $caps[ $key ] = isset( $opgeslagen[ $key ] ) && (int) $opgeslagen[ $key ] > 0
            ? arrahma_clamp_cap( (int) $opgeslagen[ $key ] )
            : arrahma_default_cap_for( $key );
    }
    return $caps;
}

/** Maximum aantal deelnemers voor één lesblok of lesgroep. */
function arrahma_cap_for( string $key ): int {
    return arrahma_slot_caps()[ $key ] ?? arrahma_default_cap_for( $key );
}

/** Human-readable niveau label, with graceful fallback for legacy values. */
function arrahma_niveau_label( string $value ): string {
    return arrahma_niveau_labels()[ $value ] ?? ( $value !== '' ? ucfirst( $value ) : '—' );
}

/** Human-readable roster label, with fallback to the raw stored value. */
function arrahma_roster_label( string $value ): string {
    return arrahma_roster_labels()[ $value ] ?? ( $value !== '' ? $value : '—' );
}

/** Standaardtekst als inschrijvingen gesloten zijn. Aan te passen via Instellingen. */
const ARRAHMA_CLOSED_MESSAGE_DEFAULT = 'De inschrijvingen zijn op dit moment gesloten. Houd onze website en WhatsApp-groepen in de gaten voor het moment waarop de inschrijvingen weer opengaan.';

/** Staan de inschrijvingen open? Standaard ja. */
function arrahma_form_is_open(): bool {
    return get_option( ARRAHMA_FORM_MODE_OPTION, 'open' ) !== 'gesloten';
}

/** Bericht dat het formulier toont wanneer inschrijvingen gesloten zijn. */
function arrahma_closed_message(): string {
    $msg = trim( (string) get_option( ARRAHMA_CLOSED_MESSAGE_OPTION, '' ) );
    return $msg !== '' ? $msg : ARRAHMA_CLOSED_MESSAGE_DEFAULT;
}

/** Mogelijke statussen per lesblok/lesgroep. */
function arrahma_slot_status_labels(): array {
    return [
        'open'           => 'Open — kan gekozen worden',
        'gesloten'       => 'Gesloten — wel zichtbaar, niet kiesbaar',
        'verborgen'      => 'Verborgen — helemaal niet tonen',
        'op_uitnodiging' => 'Alleen via link — verborgen, behalve voor wie de link heeft',
    ];
}

/** Standaardstatus zolang er in Instellingen niets is opgeslagen; per lesgroep in te stellen. */
function arrahma_default_slot_status( string $key ): string {
    return arrahma_groepen()[ $key ]['standaard_status'] ?? 'open';
}

/** Status van alle lesblokken en lesgroepen; ontbrekende sleutels krijgen hun standaardstatus. */
function arrahma_slot_statuses(): array {
    $opgeslagen = get_option( ARRAHMA_SLOT_STATUS_OPTION, [] );
    if ( ! is_array( $opgeslagen ) ) $opgeslagen = [];

    $statuses = [];
    foreach ( array_keys( arrahma_roster_labels() ) as $key ) {
        $standaard = arrahma_default_slot_status( $key );
        $waarde    = $opgeslagen[ $key ] ?? $standaard;
        $statuses[ $key ] = isset( arrahma_slot_status_labels()[ $waarde ] ) ? $waarde : $standaard;
    }
    return $statuses;
}

function arrahma_slot_status_for( string $key ): string {
    return arrahma_slot_statuses()[ $key ] ?? arrahma_default_slot_status( $key );
}

/**
 * Mag er op dit lesblok/deze lesgroep ingeschreven worden?
 *
 * 'op_uitnodiging' telt hier mee als open. De link is bewust géén beveiliging — hij zorgt er
 * alleen voor dat de groep niet publiek op het formulier staat. Wie de link heeft, mag inschrijven.
 */
function arrahma_slot_accepts_signups( string $key ): bool {
    return in_array( arrahma_slot_status_for( $key ), [ 'open', 'op_uitnodiging' ], true );
}

function arrahma_betaalwijze_labels(): array {
    return [
        'maandelijks' => 'Maandelijks',
        'jaarlijks'   => 'Jaarlijks (in één keer)',
    ];
}

function arrahma_betaalwijze_label( string $value ): string {
    return arrahma_betaalwijze_labels()[ $value ] ?? 'Maandelijks';
}

/** Cadence-aware incasso sentence fragment for confirmation e-mails. */
function arrahma_incasso_zin( string $betaalwijze ): string {
    if ( $betaalwijze === 'jaarlijks' ) {
        return 'het lesgeld in één keer aan het begin van het schooljaar wordt ge&iuml;ncasseerd';
    }
    return 'het lesgeld maandelijks wordt ge&iuml;ncasseerd (niet tijdens de zomervakantie)';
}

function email_to_send_to(string $category): string {
  switch ($category) {
    case 'kinderen':
    case 'tieners_broeders':
    case 'tieners_zusters':
      return 'oudercomite@vereniging-arrahma.nl';
    case 'broeders_18plus':
    case 'broeders_jongeren':
    case 'broeders_volwassenen':
      return 'lessen@vereniging-arrahma.nl';
    case 'zusters_18plus':
    case 'zusters_jongeren':
    case 'zusters_volwassenen':
      return 'zusters@vereniging-arrahma.nl';
    default:
      return 'lessen@vereniging-arrahma.nl';
  }
}

// ─────────────────────────────────────────────────────────────
// REST API  →  POST /wp-json/arrahma/v1/inschrijving
// ─────────────────────────────────────────────────────────────
add_action( 'rest_api_init', function () {
    register_rest_route( 'arrahma/v1', '/inschrijving', [
        'methods'             => 'POST',
        'callback'            => 'arrahma_handle_submission',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( 'arrahma/v1', '/rooster-counts', [
        'methods'             => 'GET',
        'callback'            => 'arrahma_get_rooster_counts',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( 'arrahma/v1', '/form-mode', [
        'methods'             => 'GET',
        'callback'            => 'arrahma_get_form_mode',
        'permission_callback' => '__return_true',
    ] );
} );

/** Of het inschrijfformulier open staat, plus de tekst bij gesloten. Publiek — geen persoonsgegevens. */
function arrahma_get_form_mode() {
    return rest_ensure_response( [
        'open'    => arrahma_form_is_open(),
        'message' => arrahma_closed_message(),
        'slots'   => arrahma_slot_statuses(),
        'caps'    => arrahma_slot_caps(),
    ] );
}

/** Aantal inschrijvingen per lesdagen-tijdslot, ongeacht status. Publiek — gebruikt door het embedded formulier. */
function arrahma_get_rooster_counts() {
    global $wpdb;
    $table = $wpdb->prefix . ARRAHMA_TABLE;

    $counts = array_fill_keys( array_keys( arrahma_roster_labels() ), 0 );

    $rows = $wpdb->get_results( "SELECT rooster, COUNT(*) as cnt FROM {$table} WHERE rooster != '' AND status != 'uitgeschreven' GROUP BY rooster" );
    foreach ( $rows as $row ) {
        if ( isset( $counts[ $row->rooster ] ) ) {
            $counts[ $row->rooster ] = (int) $row->cnt;
        }
    }

    return rest_ensure_response( $counts );
}

function arrahma_handle_submission( WP_REST_Request $request ) {
    global $wpdb;

    $data = $request->get_json_params() ?: $request->get_params();

    // ── Inschrijvingen gesloten: ook serverzijde weigeren, zodat een reeds geopend
    //    tabblad (of een directe POST) na sluiting geen inschrijving meer aanmaakt.
    if ( ! arrahma_form_is_open() ) {
        return new WP_Error( 'form_closed', arrahma_closed_message(), [ 'status' => 403 ] );
    }

    // ── Bulk (meerdere kinderen) heeft een andere payload-vorm
    if ( ( $data['mode'] ?? '' ) === 'bulk' || ! empty( $data['children'] ) ) {
        return arrahma_handle_bulk_submission( $data );
    }

    // ── Validatie
    $voor         = sanitize_text_field( $data['inschrijving_voor'] ?? '' );
    $allowed_voor = array_keys( arrahma_category_labels() );

    if ( ! in_array( $voor, $allowed_voor, true ) ) {
        return new WP_Error( 'invalid_type', 'Ongeldig inschrijvingstype.', [ 'status' => 400 ] );
    }

    $voornaam   = sanitize_text_field( $data['voornaam']   ?? '' );
    $achternaam = sanitize_text_field( $data['achternaam'] ?? '' );
    $email      = sanitize_email( $data['email'] ?? '' );

    if ( ! $voornaam || ! $achternaam || ! is_email( $email ) ) {
        return new WP_Error( 'missing_fields', 'Verplichte velden ontbreken.', [ 'status' => 400 ] );
    }

    $geboortedatum = sanitize_text_field( $data['geboortedatum'] ?? '' );
    if ( $geboortedatum && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $geboortedatum ) ) {
        $geboortedatum = '';
    }

    $cp_anders    = ! empty( $data['cp_anders'] );
    $cp_voornaam  = $cp_anders ? sanitize_text_field( $data['cp_voornaam']  ?? '' ) : null;
    $cp_achternaam = $cp_anders ? sanitize_text_field( $data['cp_achternaam'] ?? '' ) : null;
    $cp_telefoon  = $cp_anders ? sanitize_text_field( $data['cp_telefoon']  ?? '' ) : null;

    $betaalwijze = sanitize_text_field( $data['betaalwijze'] ?? '' );
    if ( ! in_array( $betaalwijze, [ 'maandelijks', 'jaarlijks' ], true ) ) {
        $betaalwijze = 'maandelijks';
    }

    // ── Opslaan
    $insert = [
        'inschrijving_voor'   => $voor,
        'voornaam'            => $voornaam,
        'achternaam'          => $achternaam,
        'geboortedatum'       => $geboortedatum ?: null,
        'telefoon'            => sanitize_text_field( $data['telefoon']   ?? '' ),
        'email'               => $email,
        'adres'               => sanitize_text_field( $data['adres']      ?? '' ),
        'postcode'            => sanitize_text_field( $data['postcode']   ?? '' ),
        'woonplaats'          => sanitize_text_field( $data['woonplaats'] ?? '' ),
        'niveau'              => sanitize_text_field( $data['niveau']     ?? '' ),
        'rooster'             => sanitize_text_field( $data['rooster']    ?? '' ),
        'rekeningnummer'      => sanitize_text_field( $data['rekeningnummer']      ?? '' ),
        'naam_rekeninghouder' => sanitize_text_field( $data['naam_rekeninghouder'] ?? '' ),
        'betaalwijze'         => $betaalwijze,
        'cp_anders'           => $cp_anders ? 1 : 0,
        'cp_voornaam'         => $cp_voornaam,
        'cp_achternaam'       => $cp_achternaam,
        'cp_telefoon'         => $cp_telefoon,
        'status'              => 'nieuw',
        'datum_inschrijving'  => current_time( 'mysql' ),
    ];

    $formats = [ '%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s' ];

    $table = $wpdb->prefix . ARRAHMA_TABLE;

    // ── Capaciteitscontrole (race-conditie: iemand anders kan het tijdslot inmiddels hebben volgemaakt)
    // ── Is dit lesblok/lesgroep überhaupt open voor inschrijving?
    if ( $insert['rooster'] !== '' && ! arrahma_slot_accepts_signups( $insert['rooster'] ) ) {
        return new WP_Error( 'slot_closed', 'Voor deze groep is de inschrijving gesloten. Kies een andere groep.', [
            'status'  => 409,
            'rooster' => $insert['rooster'],
        ] );
    }

    if ( $insert['rooster'] !== '' ) {
        $huidig = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE rooster = %s", $insert['rooster']
        ) );
        if ( $huidig >= arrahma_cap_for( $insert['rooster'] ) ) {
            return new WP_Error( 'rooster_full', 'Dit lesdagen-tijdslot is helaas net vol geraakt. Kies een ander tijdslot.', [
                'status'  => 409,
                'rooster' => $insert['rooster'],
            ] );
        }
    }

    $result = $wpdb->insert( $table, $insert, $formats );

    if ( $result === false ) {
        return new WP_Error( 'db_error', 'Opslaan in database mislukt.', [ 'status' => 500 ] );
    }

    $new_id = $wpdb->insert_id;
    arrahma_send_notification( $insert, $new_id );
    arrahma_send_confirmation_email( $insert['email'], [ $insert ] );

    return rest_ensure_response( [ 'success' => true, 'id' => $new_id ] );
}

// ─────────────────────────────────────────────────────────────
// BULK: één gezin, meerdere kinderen → één rij per kind
// ─────────────────────────────────────────────────────────────
function arrahma_handle_bulk_submission( array $data ) {
    global $wpdb;

    $contact  = is_array( $data['contact'] ?? null ) ? $data['contact'] : [];
    $adres    = is_array( $data['adres']   ?? null ) ? $data['adres']   : [];
    $bank     = is_array( $data['bank']    ?? null ) ? $data['bank']    : [];
    $children = is_array( $data['children'] ?? null ) ? $data['children'] : [];

    if ( count( $children ) === 0 ) {
        return new WP_Error( 'no_children', 'Geen kinderen opgegeven.', [ 'status' => 400 ] );
    }

    // ── Gedeelde contactpersoon (ouder/verzorger)
    $cp_voornaam   = sanitize_text_field( $contact['voornaam']   ?? '' );
    $cp_achternaam = sanitize_text_field( $contact['achternaam'] ?? '' );
    $cp_telefoon   = sanitize_text_field( $contact['telefoon']   ?? '' );
    $cp_email      = sanitize_email( $contact['email'] ?? '' );

    if ( ! $cp_voornaam || ! $cp_achternaam || ! is_email( $cp_email ) ) {
        return new WP_Error( 'missing_fields', 'Verplichte contactgegevens ontbreken.', [ 'status' => 400 ] );
    }

    // ── Gedeeld adres & bank
    $adres_str  = sanitize_text_field( $adres['adres']      ?? '' );
    $postcode   = sanitize_text_field( $adres['postcode']   ?? '' );
    $woonplaats = sanitize_text_field( $adres['woonplaats'] ?? '' );

    $rekeningnummer      = sanitize_text_field( $bank['rekeningnummer']      ?? '' );
    $naam_rekeninghouder = sanitize_text_field( $bank['naam_rekeninghouder'] ?? '' );

    $betaalwijze = sanitize_text_field( $data['betaalwijze'] ?? '' );
    if ( ! in_array( $betaalwijze, [ 'maandelijks', 'jaarlijks' ], true ) ) {
        $betaalwijze = 'maandelijks';
    }

    $groep_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'grp_', true );
    $table    = $wpdb->prefix . ARRAHMA_TABLE;
    $formats  = [ '%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s' ];

    // ── Capaciteitscontrole (vóór het invoegen — voorkomt een halve gezinsinschrijving als een tijdslot niet meer past)
    $rooster_gevraagd = [];
    foreach ( $children as $child ) {
        if ( ! is_array( $child ) ) continue;
        $r = sanitize_text_field( $child['rooster'] ?? '' );
        if ( $r !== '' ) {
            $rooster_gevraagd[ $r ] = ( $rooster_gevraagd[ $r ] ?? 0 ) + 1;
        }
    }
    foreach ( $rooster_gevraagd as $rooster_waarde => $aantal_gevraagd ) {
        // Staat dit lesblok überhaupt open? (Dezelfde controle als bij één inschrijving.)
        if ( ! arrahma_slot_accepts_signups( $rooster_waarde ) ) {
            return new WP_Error( 'slot_closed', 'Voor één of meer gekozen lesdagen is de inschrijving gesloten. Kies andere lesdagen.', [
                'status'  => 409,
                'rooster' => $rooster_waarde,
            ] );
        }

        $huidig = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE rooster = %s", $rooster_waarde
        ) );
        if ( $huidig + $aantal_gevraagd > arrahma_cap_for( $rooster_waarde ) ) {
            return new WP_Error( 'rooster_full', 'Eén of meer gekozen lesdagen-tijdsloten zijn helaas net vol geraakt. Kies een ander tijdslot.', [
                'status'  => 409,
                'rooster' => $rooster_waarde,
            ] );
        }
    }

    $inserted = []; // rijen voor de gegroepeerde e-mails
    foreach ( $children as $child ) {
        if ( ! is_array( $child ) ) continue;

        $voornaam   = sanitize_text_field( $child['voornaam']   ?? '' );
        $achternaam = sanitize_text_field( $child['achternaam'] ?? '' );
        if ( ! $voornaam || ! $achternaam ) continue; // onvolledige rij overslaan

        $geboortedatum = sanitize_text_field( $child['geboortedatum'] ?? '' );
        if ( $geboortedatum && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $geboortedatum ) ) {
            $geboortedatum = '';
        }

        $row = [
            'inschrijving_voor'   => 'kinderen',
            'voornaam'            => $voornaam,
            'achternaam'          => $achternaam,
            'geboortedatum'       => $geboortedatum ?: null,
            'telefoon'            => '',
            'email'               => $cp_email,
            'adres'               => $adres_str,
            'postcode'            => $postcode,
            'woonplaats'          => $woonplaats,
            'niveau'              => sanitize_text_field( $child['niveau']  ?? '' ),
            'rooster'             => sanitize_text_field( $child['rooster'] ?? '' ),
            'rekeningnummer'      => $rekeningnummer,
            'naam_rekeninghouder' => $naam_rekeninghouder,
            'betaalwijze'         => $betaalwijze,
            'cp_anders'           => 1,
            'cp_voornaam'         => $cp_voornaam,
            'cp_achternaam'       => $cp_achternaam,
            'cp_telefoon'         => $cp_telefoon,
            'groep_id'            => $groep_id,
            'status'              => 'nieuw',
            'datum_inschrijving'  => current_time( 'mysql' ),
        ];

        if ( $wpdb->insert( $table, $row, $formats ) !== false ) {
            $inserted[] = $row;
        }
    }

    if ( count( $inserted ) === 0 ) {
        return new WP_Error( 'db_error', 'Opslaan in database mislukt.', [ 'status' => 500 ] );
    }

    arrahma_send_group_notification( $inserted, $groep_id );
    arrahma_send_confirmation_email( $cp_email, $inserted );

    return rest_ensure_response( [
        'success'  => true,
        'groep_id' => $groep_id,
        'aantal'   => count( $inserted ),
    ] );
}

// ─────────────────────────────────────────────────────────────
// E-MAIL HELPERS
// ─────────────────────────────────────────────────────────────

/**
 * Returns the site's logo URL from the WordPress Customizer,
 * or an empty string if none is set.
 */
function arrahma_email_logo_url(): string {
    $logo_id = get_theme_mod( 'custom_logo' );
    if ( $logo_id ) {
        $src = wp_get_attachment_image_url( $logo_id, 'medium' );
        return $src ?: '';
    }
    return '';
}

/**
 * Wraps $inner_html in the shared branded email shell.
 * All CSS is inlined — required for broad email client support.
 */
function arrahma_email_wrap( string $inner_html ): string {
    $logo_url  = arrahma_email_logo_url();
    $logo_html = $logo_url
        ? '<img src="' . esc_url( $logo_url ) . '" alt="Vereniging Arrahma" width="120" style="display:block;margin:0 auto 12px;height:auto;max-width:120px;">'
        : '';
    $site_url  = get_site_url();
    $year      = date( 'Y' );

    return '<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Vereniging Arrahma</title>
</head>
<body style="margin:0;padding:0;background:#f0f2f5;font-family:Arial,Helvetica,sans-serif;color:#333;">

  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f2f5;padding:32px 16px;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">

        <!-- Header -->
        <tr>
          <td style="background:#2d3a4a;border-radius:10px 10px 0 0;padding:32px 40px;text-align:center;">
            ' . $logo_html . '
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="background:#ffffff;padding:36px 40px;">
            ' . $inner_html . '
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="background:#f8f9fb;border-radius:0 0 10px 10px;padding:20px 40px;text-align:center;border-top:1px solid #e8eaed;">
            <p style="margin:0 0 6px;font-size:12px;color:#888;">
              <a href="' . esc_url( $site_url ) . '" style="color:#2d3a4a;text-decoration:none;font-weight:600;">vereniging-arrahma.nl</a>
            </p>
            <p style="margin:0;font-size:11px;color:#aaa;">&copy; ' . $year . ' Vereniging Arrahma. Alle rechten voorbehouden.</p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>

</body>
</html>';
}

/** Renders a single data row for the notification table. */
function arrahma_email_row( string $label, string $value, bool $shade = false ): string {
    $bg = $shade ? '#f8f9fb' : '#ffffff';
    return '<tr>
      <td style="background:' . $bg . ';padding:10px 14px;font-size:13px;color:#888;width:38%;border-bottom:1px solid #f0f0f0;vertical-align:top;">' . esc_html( $label ) . '</td>
      <td style="background:' . $bg . ';padding:10px 14px;font-size:13px;color:#1a1a1a;border-bottom:1px solid #f0f0f0;vertical-align:top;">' . esc_html( $value ) . '</td>
    </tr>';
}

// ─────────────────────────────────────────────────────────────
// E-MAIL: MELDING NAAR BESTUUR
// ─────────────────────────────────────────────────────────────
function arrahma_send_notification( array $d, int $id ) {
    $to     = email_to_send_to( $d['inschrijving_voor'] );
    $naam   = $d['voornaam'] . ' ' . $d['achternaam'];
    $labels = arrahma_category_labels();

    $rows  = arrahma_email_row( 'Categorie',           $labels[ $d['inschrijving_voor'] ] ?? $d['inschrijving_voor'], false );
    $rows .= arrahma_email_row( 'Naam ingeschrevene',  $naam,                                                          true );
    $rows .= arrahma_email_row( 'Geboortedatum',       $d['geboortedatum'] ?? '—',                                    false );
    $rows .= arrahma_email_row( 'E-mailadres',         $d['email'],                                                    true );
    $rows .= arrahma_email_row( 'Telefoonnummer',      $d['telefoon'] ?: '—',                                         false );
    $rows .= arrahma_email_row( 'Adres',               $d['adres'] . ', ' . $d['postcode'] . ' ' . $d['woonplaats'],  true );
    $rows .= arrahma_email_row( 'Niveau',              arrahma_niveau_label( $d['niveau'] ),                          false );
    $rows .= arrahma_email_row( 'Lesdagen (voorkeur)', arrahma_roster_label( $d['rooster'] ?? '' ),                    true );
    $rows .= arrahma_email_row( 'Rekeningnummer',      $d['rekeningnummer'],                                           false );
    $rows .= arrahma_email_row( 'Naam rekeninghouder', $d['naam_rekeninghouder'],                                      true );
    $rows .= arrahma_email_row( 'Betaalwijze',         arrahma_betaalwijze_label( $d['betaalwijze'] ?? '' ),          false );

    $cp_section = '';
    if ( $d['cp_anders'] ) {
        $cp_rows  = arrahma_email_row( 'Naam contactpersoon',     $d['cp_voornaam'] . ' ' . $d['cp_achternaam'], false );
        $cp_rows .= arrahma_email_row( 'Telefoon contactpersoon', $d['cp_telefoon'] ?? '—',                       true );

        $cp_section = '
        <p style="margin:24px 0 8px;font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#2d3a4a;">Contactpersoon</p>
        <table width="100%" cellpadding="0" cellspacing="0" style="border-radius:6px;overflow:hidden;border:1px solid #e8eaed;">
          ' . $cp_rows . '
        </table>';
    }

    $admin_url  = admin_url( 'admin.php?page=arrahma-inschrijvingen' );
    $inner_html = '
      <h2 style="margin:0 0 4px;font-size:20px;font-weight:700;color:#1a1a1a;">Nieuwe aanmelding' . $id . '</h2>
      <p style="margin:0 0 24px;font-size:14px;color:#888;">Er is een nieuwe inschrijving ontvangen via het formulier.</p>

      <p style="margin:0 0 8px;font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#2d3a4a;">Gegevens ingeschrevene</p>
      <table width="100%" cellpadding="0" cellspacing="0" style="border-radius:6px;overflow:hidden;border:1px solid #e8eaed;">
        ' . $rows . '
      </table>

      ' . $cp_section . '

      <div style="margin-top:28px;text-align:center;">
        <a href="' . esc_url( $admin_url ) . '" style="display:inline-block;background:#2d3a4a;color:#ffffff;text-decoration:none;font-size:13px;font-weight:700;letter-spacing:0.05em;text-transform:uppercase;padding:12px 28px;border-radius:50px;">
          Bekijk alle inschrijvingen &rarr;
        </a>
      </div>';

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: Vereniging Arrahma <oudercomite@vereniging-arrahma.nl>',
    ];

    wp_mail( $to, 'Aanmelding nieuw lid van Vereniging Arrahma', arrahma_email_wrap( $inner_html ), $headers );
}

// ─────────────────────────────────────────────────────────────
// E-MAIL: BEVESTIGING NAAR OUDER / INGESCHREVENE
// ─────────────────────────────────────────────────────────────

/** Normaliseert een inschrijvingsrij (DB-object of insert-array) naar een array. */
function arrahma_row_to_array( $row ): array {
    return is_object( $row ) ? get_object_vars( $row ) : (array) $row;
}

/** Formatteert een yyyy-mm-dd datum als d-m-Y; lege waarde wordt een streepje. */
function arrahma_format_date( ?string $date ): string {
    if ( ! $date ) return '—';
    $ts = strtotime( $date );
    return $ts ? date_i18n( 'd-m-Y', $ts ) : $date;
}

/**
 * Bouwt het gegevensoverzicht voor de bevestigingsmail: één blok per ingeschrevene,
 * gevolgd door één gedeeld blok met contact-, adres- en betaalgegevens.
 */
/**
 * Wie leest deze e-mail? Bepaalt de aanspreekvorm.
 *
 *   'ouder'   — alle rijen zijn kinderen; de lezer is de ouder/verzorger, niet de ingeschrevene
 *   'zelf'    — niemand is een kind; de lezer heeft zichzelf ingeschreven
 *   'gemengd' — allebei op één e-mailadres, bijv. een vader die ook zichzelf inschreef
 */
function arrahma_email_doelgroep_soort( array $rows ): string {
    $rows     = array_values( $rows );
    $kinderen = 0;
    foreach ( $rows as $row ) {
        $r = arrahma_row_to_array( $row );
        if ( ( $r['inschrijving_voor'] ?? '' ) === 'kinderen' ) $kinderen++;
    }
    if ( $kinderen === 0 ) return 'zelf';
    return $kinderen === count( $rows ) ? 'ouder' : 'gemengd';
}

/**
 * Voornaam om mee te groeten, of '' als we niemand met zekerheid kunnen aanspreken.
 *
 * Bij een kind staat in 'voornaam' de naam van het kínd terwijl de ouder de e-mail leest, en
 * de contactpersoon-vinkje is optioneel. Staat die niet aan, dan groeten we liever neutraal
 * ("As-salāmu ʿalaykum,") dan dat we de ouder met de naam van zijn kind aanspreken.
 */
function arrahma_email_aanhef( array $rows ): string {
    if ( empty( $rows ) ) return '';
    $first = arrahma_row_to_array( array_values( $rows )[0] );

    if ( ! empty( $first['cp_anders'] ) ) {
        return trim( (string) ( $first['cp_voornaam'] ?? '' ) );
    }
    return arrahma_email_doelgroep_soort( $rows ) === 'zelf'
        ? trim( (string) ( $first['voornaam'] ?? '' ) )
        : '';
}

function arrahma_confirmation_details_html( array $rows, string $soort = 'zelf' ): string {
    $labels = arrahma_category_labels();
    $first  = arrahma_row_to_array( $rows[0] );
    $meer   = count( $rows ) > 1;

    $html = '';
    $i    = 0;
    foreach ( $rows as $row ) {
        $r = arrahma_row_to_array( $row );
        $i++;

        $krows  = arrahma_email_row( 'Naam',          trim( ( $r['voornaam'] ?? '' ) . ' ' . ( $r['achternaam'] ?? '' ) ) ?: '—', false );
        $krows .= arrahma_email_row( 'Geboortedatum', arrahma_format_date( $r['geboortedatum'] ?? '' ),                          true );
        $krows .= arrahma_email_row( 'Categorie',     $labels[ $r['inschrijving_voor'] ?? '' ] ?? ( ( $r['inschrijving_voor'] ?? '' ) ?: '—' ), false );
        $krows .= arrahma_email_row( 'Niveau',        arrahma_niveau_label( $r['niveau'] ?? '' ),                                true );
        if ( ! empty( $r['rooster'] ) ) {
            $krows .= arrahma_email_row( 'Lesdagen', arrahma_roster_label( $r['rooster'] ), false );
        }

        // Bij een ouder die alleen kinderen inschrijft leest "Kind 1" natuurlijker dan "Ingeschrevene 1".
        $titel = $soort === 'ouder'
            ? ( $meer ? 'Kind ' . $i : 'Gegevens kind' )
            : ( $meer ? 'Ingeschrevene ' . $i : 'Gegevens ingeschrevene' );
        $html .= '
        <p style="margin:22px 0 8px;font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#2d3a4a;">' . esc_html( $titel ) . '</p>
        <table width="100%" cellpadding="0" cellspacing="0" style="border-radius:6px;overflow:hidden;border:1px solid #e8eaed;">
          ' . $krows . '
        </table>';
    }

    // ── Gedeelde gegevens (contact, adres, betaling)
    $telefoon = ! empty( $first['cp_anders'] ) ? ( $first['cp_telefoon'] ?? '' ) : ( $first['telefoon'] ?? '' );
    $adres    = trim( ( $first['adres'] ?? '' ) . ', ' . ( $first['postcode'] ?? '' ) . ' ' . ( $first['woonplaats'] ?? '' ), ', ' );

    $srows  = arrahma_email_row( 'E-mailadres',    ( $first['email'] ?? '' ) ?: '—',  false );
    $srows .= arrahma_email_row( 'Telefoonnummer', $telefoon ?: '—',        true );
    if ( ! empty( $first['cp_anders'] ) ) {
        $srows .= arrahma_email_row( 'Contactpersoon', trim( ( $first['cp_voornaam'] ?? '' ) . ' ' . ( $first['cp_achternaam'] ?? '' ) ) ?: '—', false );
    }
    $srows .= arrahma_email_row( 'Adres',               $adres ?: '—',                                          true );
    $srows .= arrahma_email_row( 'Rekeningnummer',      ( $first['rekeningnummer'] ?? '' ) ?: '—',              false );
    $srows .= arrahma_email_row( 'Naam rekeninghouder', ( $first['naam_rekeninghouder'] ?? '' ) ?: '—',         true );
    $srows .= arrahma_email_row( 'Betaalwijze',         arrahma_betaalwijze_label( $first['betaalwijze'] ?? '' ), false );

    $html .= '
    <p style="margin:22px 0 8px;font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#2d3a4a;">Contact, adres &amp; betaling</p>
    <table width="100%" cellpadding="0" cellspacing="0" style="border-radius:6px;overflow:hidden;border:1px solid #e8eaed;">
      ' . $srows . '
    </table>';

    return $html;
}

// ─────────────────────────────────────────────────────────────
// BEWERKBARE E-MAILTEKSTEN
// ─────────────────────────────────────────────────────────────
// Alle e-mails aan ouders en deelnemers worden opgebouwd uit teksten die in wp-admin te bewerken zijn
// (Inschrijvingen → E-mailteksten). Standaardteksten staan in arrahma_builtin_email_templates();
// aanpassingen en eigen e-mails staan in de optie ARRAHMA_TEMPLATES_OPTION. De huisstijl (letters,
// kleuren, infokader) wordt pas bij het versturen toegepast, zodat elke e-mail er hetzelfde uitziet.
//
// Syntax in een tekst:
//   {naam}                                   variabele (zie arrahma_email_variabelen())
//   [bij één]…[/bij één]                     alleen bij één inschrijving; [bij meerdere]…[/bij meerdere] bij twee of meer
//   [als aanhef]…[/als aanhef]               alleen als die variabele niet leeg is
//   [per inschrijving]…[/per inschrijving]   wordt voor elke ingeschrevene herhaald

define( 'ARRAHMA_TEMPLATES_OPTION', 'arrahma_email_templates' );

/**
 * Bevestiging van de inschrijving — automatisch bij aanmelden én handmatig via "E-mails versturen".
 * Dunne wrapper, zodat de aanmeldcode niets van templates hoeft te weten.
 */
function arrahma_send_confirmation_email( string $email, array $rows, string $subject_prefix = '' ): bool {
    return arrahma_send_template_email( 'bevestiging', $email, $rows, $subject_prefix );
}

// ── Bijlagen bij bulkmail: alleen Word, Excel, PowerPoint en PDF uit de mediabibliotheek.
define( 'ARRAHMA_BIJLAGE_EXT',    [ 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'pptx' ] );
define( 'ARRAHMA_BIJLAGE_MAX',    5 );   // bestanden per e-mail
define( 'ARRAHMA_BIJLAGE_MAX_MB', 10 );  // samen; veel mailservers weigeren grotere berichten

/**
 * Leest gekozen bijlagen (media-ids in $_POST['bijlagen']) en controleert ze.
 * [ 'paden' => string[], 'namen' => string[], 'fout' => string ]. Een fout blokkeert het versturen.
 */
function arrahma_bijlagen_uit_request(): array {
    $ids   = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $_POST['bijlagen'] ?? [] ) ) ) ) );
    $uit   = [ 'paden' => [], 'namen' => [], 'fout' => '' ];
    if ( ! $ids ) return $uit;
    if ( count( $ids ) > ARRAHMA_BIJLAGE_MAX ) {
        $uit['fout'] = 'Maximaal ' . ARRAHMA_BIJLAGE_MAX . ' bijlagen per e-mail.';
        return $uit;
    }
    $totaal = 0;
    foreach ( $ids as $id ) {
        $pad = get_post_type( $id ) === 'attachment' ? (string) get_attached_file( $id ) : '';
        $ext = strtolower( pathinfo( $pad, PATHINFO_EXTENSION ) );
        if ( $pad === '' || ! is_readable( $pad ) ) {
            $uit['fout'] = 'Een gekozen bijlage bestaat niet meer in de mediabibliotheek.';
            return $uit;
        }
        if ( ! in_array( $ext, ARRAHMA_BIJLAGE_EXT, true ) ) {
            $uit['fout'] = basename( $pad ) . ' is geen Word-, Excel-, PowerPoint- of PDF-bestand.';
            return $uit;
        }
        $totaal          += (int) filesize( $pad );
        $uit['paden'][]  = $pad;
        $uit['namen'][]  = basename( $pad );
    }
    if ( $totaal > ARRAHMA_BIJLAGE_MAX_MB * 1024 * 1024 ) {
        $uit['fout'] = 'De bijlagen zijn samen groter dan ' . ARRAHMA_BIJLAGE_MAX_MB . ' MB. Veel mailservers weigeren dat.';
    }
    return $uit;
}

/** Verstuurt een HTML-e-mail met de vaste afzender, optioneel met bijlagen (bestandspaden). */
function arrahma_wp_mail( string $to, string $subject, string $html, array $bijlagen = [] ): bool {
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: Vereniging Arrahma <oudercomite@vereniging-arrahma.nl>',
    ];
    // WordPress zet emoji in e-mails om naar afbeeldingen op s.w.org; veel mailprogramma's blokkeren
    // die, waardoor 📅 en 🕐 verdwijnen. Voor onze e-mails laten we de tekens gewoon staan.
    $had_filter = remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
    $ok         = wp_mail( $to, $subject, $html, $headers, $bijlagen );
    if ( $had_filter ) add_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
    return (bool) $ok;
}

/** preg_replace dat bij een regex-fout (bijv. ongeldige UTF-8) de invoer teruggeeft in plaats van null. */
function arrahma_re( string $pattern, string $replacement, string $subject ): string {
    $uit = preg_replace( $pattern, $replacement, $subject );
    return $uit === null ? $subject : $uit;
}

function arrahma_re_cb( string $pattern, callable $callback, string $subject ): string {
    $uit = preg_replace_callback( $pattern, $callback, $subject );
    return $uit === null ? $subject : $uit;
}

/** "A", "A en B", "A, B en C" — lege en dubbele waarden vallen weg. */
function arrahma_join_nl( array $items ): string {
    $items = array_values( array_unique( array_filter( array_map( 'strval', $items ), 'strlen' ) ) );
    if ( count( $items ) <= 1 ) return $items[0] ?? '';
    $laatste = array_pop( $items );
    return implode( ', ', $items ) . ' en ' . $laatste;
}

function arrahma_template_variant_labels(): array {
    return [
        'standaard' => 'Tekst',
        'kinderen'  => 'Kinderen (aan ouders)',
        '12plus'    => 'Jongeren & volwassenen (12+)',
    ];
}

function arrahma_template_doelgroep_tekst( ?array $cats ): string {
    if ( ! $cats ) return 'Alle doelgroepen';
    return implode( ', ', array_map( function ( $c ) { return arrahma_category_labels()[ $c ] ?? $c; }, $cats ) );
}

/**
 * Standaardteksten van de ingebouwde e-mails. Een aanpassing in wp-admin overschrijft ze per variant;
 * "Standaardtekst herstellen" gaat hierop terug. 'kinderen' is voor e-mails aan ouders (ook als er naast
 * kinderen een volwassene op het adres staat), '12plus' voor jongeren & volwassenen die zichzelf inschrijven.
 */
function arrahma_builtin_email_templates(): array {
    $ouderavond = <<<'TXT'
<h2>As-salāmu ʿalaykum,</h2>
Dit bericht is voor de ouder/verzorger van <strong>{namen}</strong>.

Voorafgaand aan het nieuwe schooljaar organiseren wij twee ouderavonden. Het is verplicht dat minimaal één ouder/verzorger één van de twee ouderavonden bijwoont. Aanwezigheid is een voorwaarde voor toelating van [bij één]je kind[/bij één][bij meerdere]je kinderen[/bij meerdere] tot de lessen.

Geef via onderstaande knop aan welke avond je kunt bijwonen.

{ouderavond_knop}

<blockquote>Heb je vragen? Neem dan contact op via <a href="mailto:lessen@vereniging-arrahma.nl">lessen@vereniging-arrahma.nl</a>.</blockquote>

{ondertekening_vereniging}
TXT;

    $bev_kinderen = <<<'TXT'
<h2>As-salāmu ʿalaykum[als aanhef] {aanhef}[/als aanhef],</h2>
Bedankt voor de aanmelding. Hieronder vind je de bevestiging van de inschrijving van <strong>{namen}</strong>.

Afhankelijk van de beschikbaarheid wordt er contact met je opgenomen. Nadat [bij één]je kind definitief is ingedeeld[/bij één][bij meerdere]de kinderen definitief zijn ingedeeld[/bij meerdere] in een klas zal {incasso}.

{gegevens}

<blockquote><strong>Controleer de gegevens hierboven.</strong>
Klopt er iets niet of wil je iets wijzigen? Laat het ons weten via <a href="mailto:lessen@vereniging-arrahma.nl">lessen@vereniging-arrahma.nl</a>.</blockquote>

{ondertekening_vereniging}
TXT;

    $bev_12plus = <<<'TXT'
<h2>As-salāmu ʿalaykum[als aanhef] {aanhef}[/als aanhef],</h2>
Bedankt voor je aanmelding. Hieronder vind je de bevestiging van [bij één]je inschrijving[/bij één][bij meerdere]de inschrijvingen op dit e-mailadres: <strong>{namen}</strong>[/bij meerdere].

Afhankelijk van de beschikbaarheid wordt er contact met je opgenomen. Nadat [bij één]je definitief bent ingedeeld[/bij één][bij meerdere]iedereen definitief is ingedeeld[/bij meerdere] in een klas zal {incasso}.

{gegevens}

<blockquote><strong>Controleer de gegevens hierboven.</strong>
Klopt er iets niet of wil je iets wijzigen? Laat het ons weten via <a href="mailto:lessen@vereniging-arrahma.nl">lessen@vereniging-arrahma.nl</a>.</blockquote>

{ondertekening_vereniging}
TXT;

    $ind_kinderen = <<<'TXT'
<h2>Assalam alaykoum wa rahmatullahi wa barakatuh,</h2>
BarakAllahu feekum voor de inschrijving van jullie [bij één]kind[/bij één][bij meerdere]kinderen[/bij meerdere]. Mocht je nog geen eerdere bevestiging hebben ontvangen, dan bevestigen wij hierbij alsnog de inschrijving.

Via deze e-mail laten wij weten dat jullie [bij één]kind definitief is geplaatst op het volgende lesmoment[/bij één][bij meerdere]kinderen definitief zijn geplaatst op de volgende lesmomenten[/bij meerdere]:

{lesmomenten}

<blockquote><strong>De lessen starten op {startdatum}.</strong>
Vanaf die week [bij één]wordt jullie kind op het hierboven genoemde lesmoment verwacht.[/bij één][bij meerdere]worden jullie kinderen op de hierboven genoemde lesmomenten verwacht.[/bij meerdere]</blockquote>

Bij de inschrijving hebben jullie zelf een inschatting gemaakt van het niveau van jullie [bij één]kind[/bij één][bij meerdere]kinderen[/bij meerdere]. Tijdens de eerste lessen zal de docent het niveau verder beoordelen. Mocht blijken dat een ander niveau beter aansluit, dan [bij één]kan jullie kind[/bij één][bij meerdere]kunnen zij[/bij meerdere] worden overgeplaatst naar een andere groep.

We proberen op hetzelfde lesmoment de verschillende niveaus aan te bieden. Dit kunnen we echter niet in alle gevallen garanderen. Mocht voor een passende niveau-indeling een andere dag en/of tijdstip nodig zijn, dan nemen wij hierover eerst contact met jullie op.

<h3>Verplichte ouderbijeenkomst</h3>
Binnenkort ontvangen jullie een aparte e-mail met een uitnodiging voor de verplichte ouderbijeenkomst. Aanwezigheid van minimaal één ouder/verzorger van ieder ingeschreven kind is een voorwaarde voor deelname aan het onderwijs.

We zullen de aanmeldingen hiervoor monitoren en tijdens de ouderbijeenkomst wordt de aanwezigheid geregistreerd.

Tijdens de bijeenkomst staan we onder andere stil bij de nieuwe onderwijsopzet, lesmethode, doelstellingen en afspraken voor het komende schooljaar. Uiteraard is er ook ruimte voor vragen.

<blockquote>📌 Houd je inbox en voor de zekerheid ook je spamfolder in de gaten voor de uitnodiging.</blockquote>

<blockquote>Heb je vragen of is iets niet duidelijk? Neem dan contact met ons op via <a href="mailto:lessen@vereniging-arrahma.nl">lessen@vereniging-arrahma.nl</a>.</blockquote>

Tot de ouderbijeenkomst, in shaa Allah.

JazakumAllahu khayran.

{ondertekening}
TXT;

    $ind_12plus = <<<'TXT'
<h2>Assalam alaykoum wa rahmatullahi wa barakatuh,</h2>
BarakAllahu feek voor je inschrijving voor het Arabisch onderwijs. Mocht je nog geen eerdere bevestiging hebben ontvangen, dan bevestigen wij hierbij alsnog je inschrijving.

Je bent definitief geplaatst op het volgende lesmoment:

{lesmomenten}

Bij de inschrijving heb je zelf een inschatting gemaakt van je niveau. Tijdens de eerste lessen zal de docent dit verder beoordelen. Mocht blijken dat een ander niveau beter bij je aansluit, dan kan het nodig zijn om je in een andere groep te plaatsen. Als hierdoor ook je lesdag of tijdstip verandert, nemen we hierover contact met je op.

<h3>WhatsApp-groep</h3>
Je bent inmiddels toegevoegd, of ontvangt binnenkort een uitnodiging, voor de WhatsApp-groep van jouw lesgroep. Houd deze groep goed in de gaten. Hier delen we de praktische informatie over de lessen, waaronder de exacte startdatum.

<blockquote>Controleer daarom of je bent toegevoegd of een uitnodiging hebt ontvangen. Heb je niets ontvangen? Laat het ons dan weten via <a href="mailto:lessen@vereniging-arrahma.nl">lessen@vereniging-arrahma.nl</a>.</blockquote>

We verwachten je op het hierboven genoemde vaste lesmoment vanaf de startdatum die in de WhatsApp-groep wordt gecommuniceerd, in shaa Allah.

JazakAllahu khayran en tot de eerste les!

{ondertekening}
TXT;

    return [
        'ouderavond' => [
            'label'        => 'Ouderavond-uitnodiging',
            'omschrijving' => 'link naar het ouderavond-formulier, vooraf ingevuld met e-mailadres en kindnamen.',
            'categorieen'  => [ 'kinderen' ],
            'varianten'    => [
                'standaard' => [ 'onderwerp' => 'Ouderavond Vereniging Arrahma — kies je moment', 'inhoud' => $ouderavond ],
            ],
        ],
        'bevestiging' => [
            'label'        => 'Bevestiging inschrijving',
            'omschrijving' => 'volledig overzicht van de ingevulde gegevens, zodat ze gecontroleerd kunnen worden. Gaat ook automatisch uit bij aanmelden.',
            'categorieen'  => null,
            'varianten'    => [
                'kinderen' => [ 'onderwerp' => 'Bevestiging inschrijving — Vereniging Arrahma', 'inhoud' => $bev_kinderen ],
                '12plus'   => [ 'onderwerp' => 'Bevestiging inschrijving — Vereniging Arrahma', 'inhoud' => $bev_12plus ],
            ],
        ],
        'indeling' => [
            'label'        => 'Definitieve plaatsing',
            'omschrijving' => 'lesdag en lestijd van het toegewezen lesmoment — te versturen zodra de indeling rond is.',
            'categorieen'  => null,
            'varianten'    => [
                'kinderen' => [
                    'onderwerp' => 'Definitieve plaatsing[als gekozen_doelgroep] ({gekozen_doelgroep})[/als gekozen_doelgroep] — Vereniging Arrahma',
                    'inhoud'    => $ind_kinderen,
                ],
                '12plus'   => [
                    'onderwerp' => 'Bevestiging plaatsing Arabisch onderwijs[als leeftijdsgroep] – ({leeftijdsgroep})[/als leeftijdsgroep] | Vereniging Arrahma',
                    'inhoud'    => $ind_12plus,
                ],
            ],
        ],
    ];
}

/** Alle e-mails: ingebouwde (met eventuele aanpassingen) plus eigen e-mails uit wp-admin. */
function arrahma_email_templates(): array {
    $opgeslagen = get_option( ARRAHMA_TEMPLATES_OPTION, [] );
    if ( ! is_array( $opgeslagen ) ) $opgeslagen = [];

    $templates = [];
    foreach ( arrahma_builtin_email_templates() as $key => $t ) {
        $t['builtin']   = true;
        $t['aangepast'] = false;
        $t['standaard'] = $t['varianten'];
        foreach ( $t['varianten'] as $v => $std ) {
            $eigen = $opgeslagen[ $key ]['varianten'][ $v ] ?? null;
            if ( is_array( $eigen ) ) {
                $t['varianten'][ $v ] = [
                    'onderwerp' => (string) ( $eigen['onderwerp'] ?? $std['onderwerp'] ),
                    'inhoud'    => (string) ( $eigen['inhoud'] ?? $std['inhoud'] ),
                ];
                $t['aangepast'] = true;
            }
        }
        $templates[ $key ] = $t;
    }
    foreach ( $opgeslagen as $key => $rec ) {
        if ( isset( $templates[ $key ] ) || empty( $rec['custom'] ) ) continue;
        $templates[ $key ] = arrahma_custom_record_naar_template( $rec );
    }
    return $templates;
}

/**
 * De e-mails die vanaf "E-mails versturen" verstuurd kunnen worden — afgeleid van de e-mailteksten,
 * zodat een nieuwe e-mail uit wp-admin daar vanzelf verschijnt. 'categorieen' => null = alle doelgroepen.
 */
function arrahma_email_types(): array {
    $types = [];
    foreach ( arrahma_email_templates() as $key => $t ) {
        $types[ $key ] = [ 'label' => $t['label'], 'omschrijving' => $t['omschrijving'], 'categorieen' => $t['categorieen'] ];
    }
    return $types;
}

function arrahma_custom_record_naar_template( array $rec ): array {
    $cats = ( ! empty( $rec['categorieen'] ) && is_array( $rec['categorieen'] ) ) ? array_values( $rec['categorieen'] ) : null;
    return [
        'label'        => (string) ( $rec['label'] ?? '' ),
        'omschrijving' => (string) ( $rec['omschrijving'] ?? '' ),
        'categorieen'  => $cats,
        'varianten'    => [ 'standaard' => [
            'onderwerp' => (string) ( $rec['varianten']['standaard']['onderwerp'] ?? '' ),
            'inhoud'    => (string) ( $rec['varianten']['standaard']['inhoud'] ?? '' ),
        ] ],
        'builtin'      => false,
    ];
}

function arrahma_sanitize_variant( $in ): array {
    $in = (array) $in;
    return [
        'onderwerp' => sanitize_text_field( wp_unslash( (string) ( $in['onderwerp'] ?? '' ) ) ),
        'inhoud'    => wp_kses_post( wp_unslash( (string) ( $in['inhoud'] ?? '' ) ) ),
    ];
}

/** Eigen e-mail uit het formulier (label, omschrijving, doelgroepen, tekst). */
function arrahma_custom_template_uit_post(): array {
    $in   = (array) ( $_POST['varianten'] ?? [] );
    $cats = array_values( array_intersect(
        arrahma_active_categories(),
        array_map( 'sanitize_key', (array) wp_unslash( $_POST['categorieen'] ?? [] ) )
    ) );
    return [
        'custom'       => true,
        'label'        => sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ),
        'omschrijving' => sanitize_text_field( wp_unslash( $_POST['omschrijving'] ?? '' ) ),
        'categorieen'  => $cats ?: null,
        'varianten'    => [ 'standaard' => arrahma_sanitize_variant( $in['standaard'] ?? [] ) ],
    ];
}

/** Stabiele sleutel voor een nieuwe e-mail. Max. 30 tekens: email_type in het verzendlog is VARCHAR(30). */
function arrahma_nieuwe_template_sleutel( string $label, array $opgeslagen ): string {
    $basis = rtrim( substr( 'e_' . str_replace( '-', '_', sanitize_title( $label ) ), 0, 26 ), '_' );
    if ( $basis === 'e' || $basis === '' ) $basis = 'e_email';
    $key = $basis;
    $i   = 2;
    while ( isset( $opgeslagen[ $key ] ) || isset( arrahma_builtin_email_templates()[ $key ] ) ) {
        $key = $basis . '_' . $i++;
    }
    return $key;
}

/** Alle variabelen, met uitleg voor de editor. */
function arrahma_email_variabelen(): array {
    return [
        'tekst' => [
            'aanhef'            => 'Voornaam om mee te groeten: de contactpersoon of de ingeschrevene zelf. Leeg als dat de naam van een kind zou zijn — gebruik dus [als aanhef] {aanhef}[/als aanhef].',
            'namen'             => 'Alle namen in deze e-mail, bijv. "Aisha Yildiz en Yusuf Yildiz".',
            'aantal'            => 'Aantal inschrijvingen in deze e-mail.',
            'email'             => 'E-mailadres van de ontvanger.',
            'gekozen_doelgroep' => 'De doelgroep die je bij het versturen kiest (kort, bijv. "Kinderen"); leeg bij "Alle doelgroepen".',
            'leeftijdsgroep'    => '"jongeren" of "volwassenen" als iedereen in de e-mail in die groep valt, anders leeg.',
            'startdatum'        => 'Startdatum van de lessen: ' . ARRAHMA_START_LESSEN . '.',
            'incasso'           => 'Zin over de incasso, volgens de gekozen betaalwijze.',
            'ouderavond_link'   => 'Persoonlijke, vooraf ingevulde link naar het ouderavond-formulier.',
        ],
        'inschrijving' => [
            'voornaam'      => 'Voornaam.',
            'achternaam'    => 'Achternaam.',
            'naam'          => 'Voor- en achternaam.',
            'doelgroep'     => 'Doelgroep, bijv. "Kinderen (6–11)".',
            'niveau'        => 'Niveau.',
            'dag'           => 'Lesdag(en), bijv. "Maandag & woensdag".',
            'tijd'          => 'Lestijd, bijv. "15:30–17:30".',
            'lesmoment'     => 'Lesmoment zoals in het overzicht (dag, tijd en eventueel niveau).',
            'geboortedatum' => 'Geboortedatum (dd-mm-jjjj).',
            'woonplaats'    => 'Woonplaats.',
        ],
        'blok' => [
            'lesmomenten'              => 'Tabel met per ingeschrevene naam, dag en tijd.',
            'gegevens'                 => 'Volledig overzicht van de ingevulde gegevens, inclusief rekeningnummer — zoals in de bevestigingsmail.',
            'ouderavond_knop'          => 'Knop naar het vooraf ingevulde ouderavond-formulier.',
            'ondertekening'            => 'Religieuze Commissie / Afdeling Arabisch Onderwijs / Vereniging Arrahma.',
            'ondertekening_vereniging' => 'Wassalāmu ʿalaykum wa raḥmatullāhi wa barakātuh, / Vereniging Arrahma.',
        ],
    ];
}

/** Alles wat een tekst nodig heeft om ingevuld te worden. Rijen mogen objecten of arrays zijn. */
function arrahma_email_context( string $email, array $rows, array $extra = [] ): array {
    $rows  = array_map( 'arrahma_row_to_array', array_values( $rows ) );
    $namen = arrahma_names_from_rows( $rows );
    $cats  = arrahma_category_labels();

    $inschrijvingen = [];
    foreach ( $rows as $r ) {
        $dt = arrahma_slot_dag_tijd( (string) ( $r['rooster'] ?? '' ) );
        $inschrijvingen[] = [
            'voornaam'      => trim( (string) ( $r['voornaam'] ?? '' ) ),
            'achternaam'    => trim( (string) ( $r['achternaam'] ?? '' ) ),
            'naam'          => trim( ( $r['voornaam'] ?? '' ) . ' ' . ( $r['achternaam'] ?? '' ) ),
            'doelgroep'     => $cats[ $r['inschrijving_voor'] ?? '' ] ?? (string) ( $r['inschrijving_voor'] ?? '' ),
            'niveau'        => ( (string) ( $r['niveau'] ?? '' ) ) !== '' ? arrahma_niveau_label( (string) $r['niveau'] ) : '',
            'dag'           => $dt['dag'],
            'tijd'          => $dt['tijd'],
            'lesmoment'     => ! empty( $r['rooster'] ) ? arrahma_roster_label( (string) $r['rooster'] ) : '',
            'geboortedatum' => ! empty( $r['geboortedatum'] ) ? arrahma_format_date( (string) $r['geboortedatum'] ) : '',
            'woonplaats'    => trim( (string) ( $r['woonplaats'] ?? '' ) ),
        ];
    }

    $groepen = array_values( array_unique( array_map( function ( $r ) {
        return preg_match( '/_(jongeren|volwassenen)$/', (string) ( $r['inschrijving_voor'] ?? '' ), $m ) ? $m[1] : '';
    }, $rows ) ) );
    $dg = (string) ( $extra['doelgroep'] ?? '' );

    return [
        'aantal'         => count( $rows ),
        'soort'          => arrahma_email_doelgroep_soort( $rows ),
        'rows'           => $rows,
        'inschrijvingen' => $inschrijvingen,
        'tekst'          => [
            'aanhef'            => arrahma_email_aanhef( $rows ),
            'namen'             => arrahma_join_nl( $namen ),
            'aantal'            => (string) count( $rows ),
            'email'             => $email,
            'gekozen_doelgroep' => ( $dg !== '' && isset( $cats[ $dg ] ) ) ? arrahma_category_short_label( $dg ) : '',
            'leeftijdsgroep'    => ( count( $groepen ) === 1 && $groepen[0] !== '' ) ? $groepen[0] : '',
            'startdatum'        => ARRAHMA_START_LESSEN,
            'incasso'           => html_entity_decode( arrahma_incasso_zin( (string) ( $rows[0]['betaalwijze'] ?? 'maandelijks' ) ), ENT_QUOTES, 'UTF-8' ),
            'ouderavond_link'   => arrahma_ouderavond_prefill_url( $email, $namen ),
        ],
    ];
}

/** Tabel per ingeschrevene met naam, dag en tijd (de {lesmomenten}-blok). */
function arrahma_lesmomenten_html( array $rows ): string {
    $rows = array_values( $rows );
    $meer = count( $rows ) > 1;
    $html = '';
    $i    = 0;
    foreach ( $rows as $row ) {
        $r    = arrahma_row_to_array( $row );
        $naam = trim( ( $r['voornaam'] ?? '' ) . ' ' . ( $r['achternaam'] ?? '' ) );
        $dt   = arrahma_slot_dag_tijd( (string) ( $r['rooster'] ?? '' ) );
        $i++;

        $rijen  = arrahma_email_row( 'Naam', $naam !== '' ? $naam : '—', false );
        $rijen .= ( $dt['dag'] !== '' || $dt['tijd'] !== '' )
            ? arrahma_email_row( '📅 Dag', $dt['dag'] ?: '—', true ) . arrahma_email_row( '🕐 Tijd', $dt['tijd'] ?: '—', false )
            : arrahma_email_row( 'Lesmoment', 'Nog niet ingedeeld', true );

        $html .= '
        <p style="margin:22px 0 8px;font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#2d3a4a;">' . esc_html( $meer ? 'Lesmoment ' . $i : 'Lesmoment' ) . '</p>
        <table width="100%" cellpadding="0" cellspacing="0" style="border-radius:6px;overflow:hidden;border:1px solid #e8eaed;">
          ' . $rijen . '
        </table>';
    }
    return $html;
}

/** HTML van een blok ({lesmomenten}, {gegevens}, …). */
function arrahma_email_blok( string $naam, array $ctx ): string {
    switch ( $naam ) {
        case 'lesmomenten':
            return arrahma_lesmomenten_html( $ctx['rows'] );
        case 'gegevens':
            return arrahma_confirmation_details_html( $ctx['rows'], $ctx['soort'] );
        case 'ouderavond_knop':
            return '<div style="text-align:center;margin-bottom:28px;"><a href="' . esc_url( $ctx['tekst']['ouderavond_link'] ) . '" style="display:inline-block;background:#2d3a4a;color:#ffffff;text-decoration:none;font-size:13px;font-weight:700;letter-spacing:0.05em;text-transform:uppercase;padding:12px 28px;border-radius:50px;">Kies je ouderavond &rarr;</a></div>';
        case 'ondertekening':
            return '<p style="margin:0;font-size:14px;color:#666;line-height:1.7;"><strong style="color:#1a1a1a;">Religieuze Commissie</strong><br>Afdeling Arabisch Onderwijs<br>Vereniging Arrahma</p>';
        case 'ondertekening_vereniging':
            return '<p style="margin:0;font-size:14px;color:#666;">Wassalāmu ʿalaykum wa raḥmatullāhi wa barakātuh,<br><strong style="color:#1a1a1a;">Vereniging Arrahma</strong></p>';
    }
    return '';
}

/** Huisstijl: kaal HTML uit de editor krijgt dezelfde inline-opmaak als de andere e-mails. */
function arrahma_email_apply_style( string $html ): string {
    $s = [
        'h2'   => 'margin:0 0 20px;font-size:22px;font-weight:700;color:#1a1a1a;',
        'h3'   => 'margin:28px 0 8px;font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#2d3a4a;',
        'p'    => 'margin:0 0 16px;font-size:15px;line-height:1.7;color:#444;',
        'list' => 'margin:0 0 16px;padding-left:22px;font-size:15px;line-height:1.7;color:#444;',
        'a'    => 'color:#2d3a4a;font-weight:600;text-decoration:none;',
    ];
    // Citaatblok = grijs infokader, met de kleinere tekst van de andere e-mails.
    $html = arrahma_re_cb( '#<blockquote[^>]*>(.*?)</blockquote>#su', function ( $m ) {
        $binnen = arrahma_re( '#<p(?![^>]*\bstyle=)(\s[^>]*)?>#u', '<p style="margin:0;font-size:13px;color:#555;line-height:1.6;"$1>', $m[1] );
        $binnen = arrahma_re( '#<strong(?![^>]*\bstyle=)(\s[^>]*)?>#u', '<strong style="color:#1a1a1a;"$1>', $binnen );
        return '<div style="border-left:3px solid #2d3a4a;padding:14px 18px;background:#f4f6f8;border-radius:0 6px 6px 0;margin:28px 0;">' . $binnen . '</div>';
    }, $html );
    foreach ( [ 'h2' => 'h2', 'h3' => 'h3', 'h4' => 'h3', 'p' => 'p', 'ul' => 'list', 'ol' => 'list', 'a' => 'a' ] as $tag => $stijl ) {
        $html = arrahma_re( '#<' . $tag . '(?![^>]*\bstyle=)(\s[^>]*)?>#u', '<' . $tag . ' style="' . $s[ $stijl ] . '"$1>', $html );
    }
    return $html;
}

/** Vult {variabelen} in; onbekende blijven staan (die vangt de controle af). */
function arrahma_template_vars( string $tpl, array $waarden, bool $html ): string {
    return arrahma_re_cb( '#\{([a-z_]+)\}#', function ( $m ) use ( $waarden, $html ) {
        if ( ! array_key_exists( $m[1], $waarden ) ) return $m[0];
        $w = (string) $waarden[ $m[1] ];
        return $html ? esc_html( $w ) : $w;
    }, $tpl );
}

/** Zet een tekst om naar de uiteindelijke e-mail (HTML) of het onderwerp (platte tekst). */
function arrahma_render_template( string $tpl, array $ctx, bool $html ): string {
    $vars    = arrahma_email_variabelen();
    $blokken = implode( '|', array_keys( $vars['blok'] ) );
    $tags    = '(?:per inschrijving|bij één|bij een|bij meerdere|als [a-z_]+)';

    if ( $html ) {
        $tpl = wpautop( $tpl );
        // Opdrachten die de editor in een eigen alinea zette, uit die alinea halen.
        $tpl = arrahma_re( '#<p[^>]*>\s*(\[/?' . $tags . '\])\s*</p>#u', '$1', $tpl );
    }

    // Voorwaarden: aantal inschrijvingen, en "is deze variabele gevuld?".
    $tpl = arrahma_re_cb( '#\[bij (één|een|meerdere)\](.*?)\[/bij \1\]#su', function ( $m ) use ( $ctx ) {
        return ( $m[1] === 'meerdere' ) === ( $ctx['aantal'] > 1 ) ? $m[2] : '';
    }, $tpl );
    $tpl = arrahma_re_cb( '#\[als ([a-z_]+)\](.*?)\[/als \1\]#su', function ( $m ) use ( $ctx ) {
        return trim( (string) ( $ctx['tekst'][ $m[1] ] ?? '' ) ) !== '' ? $m[2] : '';
    }, $tpl );

    // Herhaling per inschrijving: binnenin gelden de waarden van die ene ingeschrevene.
    $tpl = arrahma_re_cb( '#\[per inschrijving\](.*?)\[/per inschrijving\]#su', function ( $m ) use ( $ctx, $html ) {
        $uit = '';
        foreach ( $ctx['inschrijvingen'] as $ins ) {
            $uit .= arrahma_template_vars( $m[1], $ins, $html );
        }
        return $uit;
    }, $tpl );

    if ( $html ) {
        $tpl = arrahma_re( '#<p[^>]*>\s*</p>#u', '', $tpl );
        $tpl = arrahma_re( '#<p[^>]*>\s*(\{(?:' . $blokken . ')\})\s*</p>#u', '$1', $tpl );
        $tpl = arrahma_email_apply_style( $tpl );
    }

    // Inschrijvingsvariabelen buiten een herhaling tonen alle waarden samen ("Aisha en Yusuf").
    $samen = [];
    foreach ( array_keys( $vars['inschrijving'] ) as $v ) {
        $samen[ $v ] = arrahma_join_nl( array_column( $ctx['inschrijvingen'], $v ) );
    }
    $tpl = arrahma_template_vars( $tpl, $ctx['tekst'] + $samen, $html );

    if ( $html ) {
        // Blokken als laatste, zodat hun HTML niet nog eens door de variabelen gaat.
        $tpl = arrahma_re_cb( '#\{(' . $blokken . ')\}#', function ( $m ) use ( $ctx ) {
            return arrahma_email_blok( $m[1], $ctx );
        }, $tpl );
    }
    return $tpl;
}

/** Fouten in één tekst: onbekende variabelen, niet-gesloten opdrachten, blokken in een onderwerp. */
function arrahma_template_problemen( string $tpl, bool $html ): array {
    $vars      = arrahma_email_variabelen();
    $tekstvars = array_merge( array_keys( $vars['tekst'] ), array_keys( $vars['inschrijving'] ) );
    $problemen = [];

    foreach ( [ 'per inschrijving', 'bij één', 'bij een', 'bij meerdere' ] as $tag ) {
        $open  = substr_count( $tpl, '[' . $tag . ']' );
        $dicht = substr_count( $tpl, '[/' . $tag . ']' );
        if ( $open !== $dicht ) {
            $problemen[] = sprintf( '[%1$s] wordt %2$d× geopend en %3$d× gesloten met [/%1$s].', $tag, $open, $dicht );
        }
    }

    preg_match_all( '#\[(/?)als ([a-z_]+)\]#u', $tpl, $m, PREG_SET_ORDER );
    $als = [];
    foreach ( $m as $x ) {
        $kant = $x[1] === '/' ? 1 : 0;
        $als[ $x[2] ][ $kant ] = ( $als[ $x[2] ][ $kant ] ?? 0 ) + 1;
    }
    foreach ( $als as $naam => $n ) {
        if ( ! isset( $vars['tekst'][ $naam ] ) ) $problemen[] = sprintf( '[als %s] verwijst naar een onbekende variabele.', $naam );
        if ( ( $n[0] ?? 0 ) !== ( $n[1] ?? 0 ) ) {
            $problemen[] = sprintf( '[als %1$s] wordt %2$d× geopend en %3$d× gesloten.', $naam, $n[0] ?? 0, $n[1] ?? 0 );
        }
    }

    // Opdrachten die op de onze lijken maar niet kloppen, zoals [per inschrijvng] of [bij twee].
    preg_match_all( '#\[/?(?:per|bij|als)\b[^\]]*\]#u', $tpl, $m );
    foreach ( array_unique( $m[0] ) as $tag ) {
        if ( ! preg_match( '#^\[/?(?:per inschrijving|bij één|bij een|bij meerdere|als [a-z_]+)\]$#u', $tag ) ) {
            $problemen[] = sprintf( 'Onbekende opdracht %s.', $tag );
        }
    }

    preg_match_all( '#\{([A-Za-z_]+)\}#', $tpl, $m );
    foreach ( array_unique( $m[1] ) as $naam ) {
        if ( isset( $vars['blok'][ $naam ] ) ) {
            if ( ! $html ) $problemen[] = sprintf( '{%s} is een blok en kan niet in het onderwerp.', $naam );
        } elseif ( ! in_array( $naam, $tekstvars, true ) ) {
            $problemen[] = sprintf( 'Onbekende variabele {%s}.', $naam );
        }
    }
    return $problemen;
}

/** Fouten in een hele e-mail (alle varianten, onderwerp én tekst). Leeg = klaar om te versturen. */
function arrahma_email_template_problemen( array $t ): array {
    $labels = arrahma_template_variant_labels();
    $uit    = [];
    foreach ( $t['varianten'] as $v => $inhoud ) {
        $pre = count( $t['varianten'] ) > 1 ? ( $labels[ $v ] ?? $v ) . ' — ' : '';
        if ( trim( (string) $inhoud['onderwerp'] ) === '' ) $uit[] = $pre . 'het onderwerp is leeg.';
        if ( trim( wp_strip_all_tags( (string) $inhoud['inhoud'] ) ) === '' ) $uit[] = $pre . 'de tekst is leeg.';
        foreach ( arrahma_template_problemen( (string) $inhoud['onderwerp'], false ) as $p ) $uit[] = $pre . 'onderwerp: ' . $p;
        foreach ( arrahma_template_problemen( (string) $inhoud['inhoud'], true ) as $p ) $uit[] = $pre . 'tekst: ' . $p;
    }
    return $uit;
}

/** Welke tekstvariant hoort bij deze rijen: '12plus' als niemand een kind is, anders 'kinderen'. */
function arrahma_template_variant_for( array $t, array $rows ): string {
    $v = $t['varianten'];
    if ( isset( $v['12plus'] ) && arrahma_email_doelgroep_soort( $rows ) === 'zelf' ) return '12plus';
    if ( isset( $v['kinderen'] ) ) return 'kinderen';
    return (string) array_keys( $v )[0];
}

function arrahma_render_email_from_template( array $t, string $email, array $rows, array $extra = [], bool $standaard = false ): array {
    $variant = arrahma_template_variant_for( $t, $rows );
    $v       = ( $standaard && isset( $t['standaard'][ $variant ] ) ) ? $t['standaard'][ $variant ] : $t['varianten'][ $variant ];
    $ctx     = arrahma_email_context( $email, $rows, $extra );

    $onderwerp = arrahma_render_template( (string) $v['onderwerp'], $ctx, false );
    $onderwerp = trim( arrahma_re( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( $onderwerp, ENT_QUOTES, 'UTF-8' ) ) ) );

    return [
        'variant'   => $variant,
        'onderwerp' => $onderwerp,
        'html'      => arrahma_render_template( (string) $v['inhoud'], $ctx, true ),
        'problemen' => array_merge(
            arrahma_template_problemen( (string) $v['onderwerp'], false ),
            arrahma_template_problemen( (string) $v['inhoud'], true )
        ),
    ];
}

function arrahma_render_email( string $key, string $email, array $rows, array $extra = [], bool $standaard = false ): ?array {
    $templates = arrahma_email_templates();
    if ( ! isset( $templates[ $key ] ) || empty( $rows ) ) return null;
    return arrahma_render_email_from_template( $templates[ $key ], $email, $rows, $extra, $standaard );
}

/** Verstuurt een e-mail op basis van de tekst met deze sleutel. */
function arrahma_send_template_email( string $key, string $email, array $rows, string $subject_prefix = '', array $extra = [], array $bijlagen = [] ): bool {
    if ( empty( $rows ) || ! $email ) return false;
    $r = arrahma_render_email( $key, $email, $rows, $extra );
    if ( ! $r ) return false;

    // Bevat een ingebouwde e-mail een fout, dan gaat de standaardtekst mee: de automatische
    // bevestiging bij aanmelden mag nooit stilletjes uitblijven door een typefout in wp-admin.
    // (Handmatig versturen van een e-mail met fouten wordt op de verzendpagina al tegengehouden.)
    if ( $r['problemen'] ) {
        if ( empty( arrahma_email_templates()[ $key ]['builtin'] ) ) return false;
        $r = arrahma_render_email( $key, $email, $rows, $extra, true );
    }
    return arrahma_wp_mail( $email, $subject_prefix . $r['onderwerp'], arrahma_email_wrap( $r['html'] ), $bijlagen );
}

/** Eenmalig: de correctiemail voor de zusters, voorheen in de code, als eigen e-mail opslaan. */
add_action( 'admin_init', 'arrahma_seed_email_templates' );

function arrahma_seed_email_templates(): void {
    if ( get_option( 'arrahma_email_templates_seed' ) ) return;
    $opgeslagen = get_option( ARRAHMA_TEMPLATES_OPTION, [] );
    if ( ! is_array( $opgeslagen ) ) $opgeslagen = [];

    $inhoud = <<<'TXT'
<h2>Assalam alaykoum wa rahmatullahi wa barakatuh,</h2>
Onlangs heb je van ons een e-mail ontvangen met de bevestiging van je plaatsing voor het Arabisch onderwijs. Die e-mail is helaas per vergissing verstuurd. Onze excuses voor de verwarring.

De lesdag en het tijdstip die in die e-mail stonden, zijn nog niet definitief. Je hoeft daar dus nog geen rekening mee te houden. Dat geldt ook voor de WhatsApp-groep die in die e-mail werd genoemd: daarover hoor je later meer.

<blockquote><strong>Binnenkort ontvang je van ons meer informatie over je plaatsing.</strong>
Heb je in de tussentijd vragen? Neem dan contact met ons op via <a href="mailto:lessen@vereniging-arrahma.nl">lessen@vereniging-arrahma.nl</a>.</blockquote>

JazakAllahu khayran voor je begrip.

{ondertekening}
TXT;

    // Zelfde sleutel als voorheen, zodat "al gemaild" in het verzendlog blijft kloppen.
    if ( ! isset( $opgeslagen['correctie_zusters'] ) ) {
        $opgeslagen['correctie_zusters'] = [
            'custom'       => true,
            'label'        => 'Correctie plaatsing zusters (eenmalig)',
            'omschrijving' => 'excuses voor de per vergissing verstuurde plaatsingsmail; meer informatie volgt. Mag weg zodra de echte plaatsing voor de zusters verstuurd is.',
            'categorieen'  => [ 'zusters_jongeren', 'zusters_volwassenen' ],
            'varianten'    => [ 'standaard' => [ 'onderwerp' => 'Correctie: plaatsing Arabisch onderwijs | Vereniging Arrahma', 'inhoud' => $inhoud ] ],
        ];
        update_option( ARRAHMA_TEMPLATES_OPTION, $opgeslagen, false );
    }
    update_option( 'arrahma_email_templates_seed', 1, false );
}

/** Voorbeeld in de editor: rendert de (nog niet opgeslagen) tekst voor een echte ontvanger. */
add_action( 'wp_ajax_arrahma_tpl_preview', 'arrahma_ajax_tpl_preview' );

function arrahma_ajax_tpl_preview(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Geen rechten.' ], 403 );
    }
    check_ajax_referer( 'arrahma_tpl_preview' );

    $key       = sanitize_key( wp_unslash( $_POST['sleutel'] ?? '' ) );
    $templates = arrahma_email_templates();
    $in        = (array) ( $_POST['varianten'] ?? [] );
    if ( isset( $templates[ $key ] ) && ! empty( $templates[ $key ]['builtin'] ) ) {
        $t = $templates[ $key ];
        foreach ( array_keys( $t['varianten'] ) as $v ) {
            if ( isset( $in[ $v ] ) ) $t['varianten'][ $v ] = arrahma_sanitize_variant( $in[ $v ] );
        }
    } else {
        $t = arrahma_custom_record_naar_template( arrahma_custom_template_uit_post() );
    }

    $doelgroep = sanitize_key( wp_unslash( $_POST['doelgroep'] ?? '' ) );
    if ( ! in_array( $doelgroep, arrahma_active_categories(), true ) ) $doelgroep = '';

    $gekozen    = strtolower( sanitize_text_field( wp_unslash( $_POST['ontvanger'] ?? '' ) ) );
    $recipients = arrahma_email_recipients();
    if ( $gekozen !== '' && isset( $recipients[ $gekozen ] ) ) {
        $email = $recipients[ $gekozen ]['email'];
        $rows  = $recipients[ $gekozen ]['rows'];
    } else {
        $sample = arrahma_sample_row();
        $email  = $sample->email ?: 'voorbeeld@example.nl';
        $rows   = [ $sample ];
    }
    $rows = arrahma_filter_rows_by_categories( $rows, $t['categorieen'], $doelgroep );
    if ( empty( $rows ) ) {
        wp_send_json_error( [ 'message' => 'Deze ontvanger heeft geen inschrijving in de doelgroep van deze e-mail.' ], 400 );
    }

    $r         = arrahma_render_email_from_template( $t, $email, $rows, [ 'doelgroep' => $doelgroep ] );
    $verstuurd = '';
    if ( ! empty( $_POST['stuur'] ) ) {
        if ( $r['problemen'] ) wp_send_json_error( [ 'message' => 'Los eerst de fouten op.' ], 400 );
        $mij = wp_get_current_user()->user_email;
        if ( ! arrahma_wp_mail( $mij, '[VOORBEELD] ' . $r['onderwerp'], arrahma_email_wrap( $r['html'] ) ) ) {
            wp_send_json_error( [ 'message' => 'Versturen is mislukt.' ], 500 );
        }
        $verstuurd = $mij;
    }

    // Overgebleven {…} zijn onbekende variabelen: in het voorbeeld rood, zodat je ze ziet staan.
    $html   = arrahma_re( '#\{([A-Za-z_]+)\}#', '<span style="background:#fdeaea;color:#d32f2f;font-weight:700">{$1}</span>', arrahma_email_wrap( $r['html'] ) );
    $labels = arrahma_template_variant_labels();
    wp_send_json_success( [
        'onderwerp' => $r['onderwerp'],
        'html'      => $html,
        'problemen' => $r['problemen'],
        'variant'   => count( $t['varianten'] ) > 1 ? ( $labels[ $r['variant'] ] ?? $r['variant'] ) : '',
        'ontvanger' => $email,
        'verstuurd' => $verstuurd,
    ] );
}

// ─────────────────────────────────────────────────────────────
// ADMIN PAGINA: E-MAILTEKSTEN
// ─────────────────────────────────────────────────────────────
function arrahma_templates_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $opgeslagen = get_option( ARRAHMA_TEMPLATES_OPTION, [] );
    if ( ! is_array( $opgeslagen ) ) $opgeslagen = [];
    $builtin  = arrahma_builtin_email_templates();
    $notice   = '';
    $fout     = '';
    $bewerk   = isset( $_GET['bewerk'] ) ? sanitize_key( wp_unslash( $_GET['bewerk'] ) ) : '';
    $nieuw    = isset( $_GET['nieuw'] );
    $post_tpl = null;

    if ( isset( $_POST['arrahma_tpl_actie'], $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'arrahma_tpl' ) ) {
        $actie = sanitize_key( wp_unslash( $_POST['arrahma_tpl_actie'] ) );
        $key   = sanitize_key( wp_unslash( $_POST['sleutel'] ?? '' ) );

        if ( $actie === 'verwijder' && ! empty( $opgeslagen[ $key ]['custom'] ) ) {
            unset( $opgeslagen[ $key ] );
            update_option( ARRAHMA_TEMPLATES_OPTION, $opgeslagen, false );
            $notice = 'E-mail verwijderd.';
            $bewerk = '';
            $nieuw  = false;
        } elseif ( $actie === 'herstel' && isset( $builtin[ $key ] ) ) {
            unset( $opgeslagen[ $key ] );
            update_option( ARRAHMA_TEMPLATES_OPTION, $opgeslagen, false );
            $notice = 'Standaardtekst hersteld.';
            $bewerk = $key;
        } elseif ( $actie === 'opslaan' ) {
            $in = (array) ( $_POST['varianten'] ?? [] );
            if ( isset( $builtin[ $key ] ) ) {
                $rec = [ 'varianten' => [] ];
                foreach ( array_keys( $builtin[ $key ]['varianten'] ) as $v ) {
                    $rec['varianten'][ $v ] = arrahma_sanitize_variant( $in[ $v ] ?? [] );
                }
                $opgeslagen[ $key ] = $rec;
            } else {
                $rec = arrahma_custom_template_uit_post();
                if ( $rec['label'] === '' ) {
                    $fout     = 'Geef de e-mail een naam.';
                    $post_tpl = arrahma_custom_record_naar_template( $rec );
                } else {
                    if ( $key === '' || empty( $opgeslagen[ $key ]['custom'] ) ) {
                        $key = arrahma_nieuwe_template_sleutel( $rec['label'], $opgeslagen );
                    }
                    $opgeslagen[ $key ] = $rec;
                }
            }
            if ( $fout === '' ) {
                update_option( ARRAHMA_TEMPLATES_OPTION, $opgeslagen, false );
                $notice = 'Opgeslagen.';
                $bewerk = $key;
                $nieuw  = false;
            }
        }
    }

    $templates = arrahma_email_templates();
    if ( $nieuw || ( $bewerk !== '' && isset( $templates[ $bewerk ] ) ) ) {
        $t = $post_tpl ?: ( $nieuw ? arrahma_custom_record_naar_template( [] ) : $templates[ $bewerk ] );
        arrahma_render_template_editor( $nieuw ? '' : $bewerk, $t, $notice, $fout );
        return;
    }
    arrahma_render_template_lijst( $templates, $notice );
}

function arrahma_render_template_lijst( array $templates, string $notice ): void {
    $basis = admin_url( 'admin.php?page=arrahma-emailteksten' );
    ?>
    <div class="wrap">
      <h1 style="display:flex;align-items:center;gap:.5rem">
        <span class="dashicons dashicons-edit" style="font-size:1.5rem;margin-top:3px"></span>
        E-mailteksten
        <a href="<?= esc_url( $basis . '&nieuw=1' ) ?>" class="page-title-action">Nieuwe e-mail</a>
      </h1>
      <?php if ( $notice ) : ?><div class="notice notice-success is-dismissible"><p><?= esc_html( $notice ) ?></p></div><?php endif; ?>
      <p style="color:#555;max-width:760px">
        Pas hier de tekst van de e-mails aan of maak een nieuwe e-mail met gegevens van de ingeschrevenen.
        Opslaan is direct actief — er hoeft geen nieuwe pluginversie meer voor geüpload te worden.
        Versturen gaat zoals altijd via <a href="<?= esc_url( admin_url( 'admin.php?page=arrahma-emails' ) ) ?>">E-mails</a>.
      </p>
      <table class="wp-list-table widefat striped" style="max-width:980px;border-radius:8px;overflow:hidden">
        <thead><tr><th>E-mail</th><th>Voor wie</th><th style="width:170px">Soort</th><th style="width:150px">Status</th></tr></thead>
        <tbody>
        <?php foreach ( $templates as $key => $t ) :
            $problemen = arrahma_email_template_problemen( $t );
            $soort     = ! empty( $t['builtin'] ) ? ( ! empty( $t['aangepast'] ) ? 'Standaard (aangepast)' : 'Standaard' ) : 'Eigen e-mail';
        ?>
          <tr>
            <td><a href="<?= esc_url( $basis . '&bewerk=' . rawurlencode( $key ) ) ?>"><strong><?= esc_html( $t['label'] ) ?></strong></a>
              <?php if ( $t['omschrijving'] ) : ?><br><span style="color:#888;font-size:.85em"><?= esc_html( $t['omschrijving'] ) ?></span><?php endif; ?></td>
            <td><?= esc_html( arrahma_template_doelgroep_tekst( $t['categorieen'] ) ) ?></td>
            <td><?= esc_html( $soort ) ?></td>
            <td><?= $problemen
                ? '<span style="color:#d32f2f;font-weight:600">' . count( $problemen ) . ' fout' . ( count( $problemen ) > 1 ? 'en' : '' ) . '</span>'
                : '<span style="color:#2e7d32">Klaar om te versturen</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
}

function arrahma_render_template_editor( string $key, array $t, string $notice, string $fout ): void {
    $labels     = arrahma_template_variant_labels();
    $vars       = arrahma_email_variabelen();
    $problemen  = arrahma_email_template_problemen( $t );
    $terug      = admin_url( 'admin.php?page=arrahma-emailteksten' );
    $ontvangers = arrahma_email_recipients();
    $kaart      = 'background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:1rem 1.25rem;margin-bottom:1rem';
    $editor_ids = [];
    foreach ( array_keys( $t['varianten'] ) as $v ) {
        // TinyMCE-id's mogen alleen kleine letters en underscores bevatten.
        $editor_ids[ $v ] = 'arrahma_tpl_' . ( $v === '12plus' ? 'twaalfplus' : $v );
    }
    $opdrachten = [
        '[bij één]…[/bij één]'           => '[bij één][/bij één]',
        '[bij meerdere]…[/bij meerdere]' => '[bij meerdere][/bij meerdere]',
        '[als aanhef] {aanhef}'          => '[als aanhef] {aanhef}[/als aanhef]',
        '[per inschrijving]…'            => '[per inschrijving]{voornaam}: {dag} om {tijd}[/per inschrijving]',
    ];
    ?>
    <div class="wrap">
      <h1 style="display:flex;align-items:center;gap:.5rem">
        <span class="dashicons dashicons-email" style="font-size:1.5rem;margin-top:3px"></span>
        <?= $key === '' ? 'Nieuwe e-mail' : esc_html( $t['label'] ) ?>
      </h1>
      <?php if ( $notice ) : ?><div class="notice notice-success is-dismissible"><p><?= esc_html( $notice ) ?></p></div><?php endif; ?>
      <?php if ( $fout ) : ?><div class="notice notice-error"><p><?= esc_html( $fout ) ?></p></div><?php endif; ?>
      <p><a href="<?= esc_url( $terug ) ?>" class="button">&larr; Alle e-mails</a></p>

      <?php if ( $problemen && $key !== '' ) : ?>
        <div class="notice notice-warning" style="max-width:980px">
          <p><strong>Deze e-mail kan pas verstuurd worden als dit is opgelost:</strong></p>
          <ul style="list-style:disc;margin:0 0 .75rem 1.5rem">
            <?php foreach ( $problemen as $p ) : ?><li><?= esc_html( $p ) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <div style="display:flex;gap:1.5rem;align-items:flex-start;flex-wrap:wrap">
        <form method="post" id="arrahma-tpl-form" style="flex:1 1 620px;min-width:0;max-width:900px">
          <?php wp_nonce_field( 'arrahma_tpl' ); ?>
          <input type="hidden" name="sleutel" value="<?= esc_attr( $key ) ?>">

          <?php if ( empty( $t['builtin'] ) ) : ?>
            <table class="form-table" role="presentation">
              <tr><th><label for="arrahma-tpl-label">Naam</label></th>
                <td><input type="text" id="arrahma-tpl-label" name="label" class="regular-text" value="<?= esc_attr( $t['label'] ) ?>">
                  <p class="description">Zo heet de e-mail op de verzendpagina.</p></td></tr>
              <tr><th><label for="arrahma-tpl-oms">Omschrijving</label></th>
                <td><input type="text" id="arrahma-tpl-oms" name="omschrijving" class="large-text" value="<?= esc_attr( $t['omschrijving'] ) ?>"></td></tr>
              <tr><th>Voor wie</th><td>
                <?php foreach ( arrahma_active_categories() as $c ) : ?>
                  <label style="display:block"><input type="checkbox" name="categorieen[]" value="<?= esc_attr( $c ) ?>" <?= checked( is_array( $t['categorieen'] ) && in_array( $c, $t['categorieen'], true ), true, false ) ?>> <?= esc_html( arrahma_category_labels()[ $c ] ?? $c ) ?></label>
                <?php endforeach; ?>
                <p class="description">Niets aangevinkt = alle doelgroepen. Wie er niet onder valt, is op de verzendpagina grijs.</p>
              </td></tr>
            </table>
          <?php else : ?>
            <p style="color:#555">Voor wie: <strong><?= esc_html( arrahma_template_doelgroep_tekst( $t['categorieen'] ) ) ?></strong> (vast bij deze e-mail).
              Schrijf gewoon tekst — de huisstijl wordt bij het versturen toegepast.</p>
          <?php endif; ?>

          <?php foreach ( $t['varianten'] as $v => $inhoud ) : ?>
            <?php if ( count( $t['varianten'] ) > 1 ) : ?>
              <h2 style="margin:2rem 0 .25rem"><?= esc_html( $labels[ $v ] ?? $v ) ?></h2>
              <p class="description" style="margin:0 0 .75rem"><?= $v === '12plus'
                  ? 'Voor e-mails waarin alleen jongeren en volwassenen staan (die zichzelf hebben ingeschreven).'
                  : 'Voor e-mails aan ouders — ook als er naast kinderen een volwassene op hetzelfde adres staat.' ?></p>
            <?php endif; ?>
            <p><label><strong>Onderwerp</strong><br>
              <input type="text" class="large-text arrahma-tpl-invoer" name="varianten[<?= esc_attr( $v ) ?>][onderwerp]" value="<?= esc_attr( $inhoud['onderwerp'] ) ?>"></label></p>
            <?php wp_editor( $inhoud['inhoud'], $editor_ids[ $v ], [
                'textarea_name' => 'varianten[' . $v . '][inhoud]',
                'textarea_rows' => 20,
                'media_buttons' => false,
                'tinymce'       => [
                    'block_formats' => 'Alinea=p;Begroeting=h2;Tussenkop=h3',
                    'toolbar1'      => 'formatselect,bold,italic,link,unlink,blockquote,bullist,numlist,undo,redo',
                    'toolbar2'      => '',
                ],
            ] ); ?>
          <?php endforeach; ?>

          <p class="submit" style="display:flex;gap:.5rem;flex-wrap:wrap">
            <button type="submit" name="arrahma_tpl_actie" value="opslaan" class="button button-primary">Opslaan</button>
            <?php if ( ! empty( $t['builtin'] ) && ! empty( $t['aangepast'] ) ) : ?>
              <button type="submit" name="arrahma_tpl_actie" value="herstel" class="button" onclick="return confirm('De aangepaste tekst vervangen door de standaardtekst?')">Standaardtekst herstellen</button>
            <?php endif; ?>
            <?php if ( empty( $t['builtin'] ) && $key !== '' ) : ?>
              <button type="submit" name="arrahma_tpl_actie" value="verwijder" class="button button-link-delete" onclick="return confirm('Deze e-mail verwijderen?')">Verwijderen</button>
            <?php endif; ?>
          </p>
        </form>

        <div style="flex:0 1 340px;min-width:280px">
          <div style="<?= esc_attr( $kaart ) ?>">
            <h2 style="margin-top:0;font-size:1rem">Voorbeeld</h2>
            <p style="margin:0 0 .5rem"><label>Ontvanger<br>
              <select id="arrahma-tpl-ontvanger" style="width:100%">
                <option value="">Voorbeeldgegevens (laatste inschrijving)</option>
                <?php foreach ( $ontvangers as $okey => $o ) : ?>
                  <option value="<?= esc_attr( $okey ) ?>"><?= esc_html( $o['email'] . ' — ' . implode( ', ', $o['names'] ) ) ?></option>
                <?php endforeach; ?>
              </select></label></p>
            <p style="margin:0 0 .75rem"><label>Doelgroep bij versturen<br>
              <select id="arrahma-tpl-doelgroep" style="width:100%">
                <option value="">Alle doelgroepen</option>
                <?php foreach ( arrahma_active_categories() as $c ) : ?>
                  <option value="<?= esc_attr( $c ) ?>"><?= esc_html( arrahma_category_labels()[ $c ] ?? $c ) ?></option>
                <?php endforeach; ?>
              </select></label></p>
            <button type="button" id="arrahma-tpl-toon" class="button button-secondary">Voorbeeld tonen</button>
            <button type="button" id="arrahma-tpl-stuur" class="button">Stuur naar mij</button>
            <p class="description" style="margin-top:.5rem">Gebruikt de tekst zoals die nu in de editor staat, ook als je nog niet hebt opgeslagen.</p>
          </div>

          <div style="<?= esc_attr( $kaart ) ?>">
            <h2 style="margin-top:0;font-size:1rem">Variabelen</h2>
            <p class="description" style="margin-top:0">Klik om in te voegen waar de cursor staat — ook in het onderwerp. Houd de muis erboven voor uitleg.</p>
            <?php foreach ( [ 'tekst' => 'Over de ontvanger', 'inschrijving' => 'Per inschrijving', 'blok' => 'Blokken (alleen in de tekst)' ] as $groep => $titel ) : ?>
              <p style="margin:.75rem 0 .25rem;font-weight:600"><?= esc_html( $titel ) ?></p>
              <?php foreach ( $vars[ $groep ] as $naam => $uitleg ) : ?>
                <button type="button" class="button button-small" style="margin:0 .25rem .3rem 0" data-invoegen="<?= esc_attr( '{' . $naam . '}' ) ?>" title="<?= esc_attr( $uitleg ) ?>">{<?= esc_html( $naam ) ?>}</button>
              <?php endforeach; ?>
            <?php endforeach; ?>
            <p class="description">"Per inschrijving"-variabelen buiten een herhaling tonen alle waarden samen, bijv. "Aisha en Yusuf".</p>
            <p style="margin:.75rem 0 .25rem;font-weight:600">Voorwaarden &amp; herhaling</p>
            <?php foreach ( $opdrachten as $knop => $tekst ) : ?>
              <button type="button" class="button button-small" style="margin:0 .25rem .3rem 0" data-invoegen="<?= esc_attr( $tekst ) ?>"><?= esc_html( $knop ) ?></button>
            <?php endforeach; ?>
            <p class="description">Opmaak: kies "Begroeting" of "Tussenkop" in het opmaakmenu; de citaatknop maakt een grijs infokader.</p>
          </div>
        </div>
      </div>

      <div id="arrahma-tpl-voorbeeld" hidden style="margin-top:1rem;max-width:900px">
        <div style="<?= esc_attr( $kaart ) ?>">
          <p style="margin:0 0 .25rem"><strong>Onderwerp:</strong> <span id="arrahma-tpl-onderwerp"></span></p>
          <p id="arrahma-tpl-info" style="margin:0;color:#666;font-size:.85rem"></p>
          <ul id="arrahma-tpl-problemen" style="list-style:disc;margin:.5rem 0 0 1.5rem;color:#d32f2f"></ul>
        </div>
        <iframe id="arrahma-tpl-frame" title="Voorbeeld van de e-mail" style="width:100%;height:900px;border:1px solid #e0e0e0;border-radius:10px;background:#f0f2f5"></iframe>
      </div>
    </div>

    <script>
    (function () {
      var form = document.getElementById('arrahma-tpl-form');
      if (!form) return;
      var AJAX   = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
      var NONCE  = <?php echo wp_json_encode( wp_create_nonce( 'arrahma_tpl_preview' ) ); ?>;
      var actief = <?php echo wp_json_encode( reset( $editor_ids ) ); ?>;
      var actiefInvoer = null;

      // Onthoud waar de cursor laatst stond: in een editor of in een onderwerpveld.
      if (window.jQuery) {
        jQuery(document).on('tinymce-editor-init', function (e, ed) {
          if (ed.id.indexOf('arrahma_tpl_') === 0) ed.on('focus', function () { actief = ed.id; actiefInvoer = null; });
        });
      }
      form.addEventListener('focusin', function (e) {
        var el = e.target;
        if (el.classList && el.classList.contains('arrahma-tpl-invoer')) { actiefInvoer = el; }
        else if (el.tagName === 'TEXTAREA' && el.id.indexOf('arrahma_tpl_') === 0) { actief = el.id; actiefInvoer = null; }
      });

      function inVeld(el, tekst) {
        var s = typeof el.selectionStart === 'number' ? el.selectionStart : el.value.length;
        var e = typeof el.selectionEnd === 'number' ? el.selectionEnd : s;
        el.value = el.value.slice(0, s) + tekst + el.value.slice(e);
        el.focus();
        el.selectionStart = el.selectionEnd = s + tekst.length;
      }
      function voegIn(tekst) {
        if (actiefInvoer) { inVeld(actiefInvoer, tekst); return; }
        var ed = window.tinymce ? window.tinymce.get(actief) : null;
        if (ed && !ed.isHidden()) { ed.focus(); ed.execCommand('mceInsertContent', false, tekst); return; }
        var ta = document.getElementById(actief);
        if (ta) inVeld(ta, tekst);
      }
      Array.prototype.forEach.call(document.querySelectorAll('[data-invoegen]'), function (b) {
        b.addEventListener('mousedown', function (e) { e.preventDefault(); }); // focus in de editor laten
        b.addEventListener('click', function () { voegIn(b.getAttribute('data-invoegen')); });
      });

      function vraag(stuur) {
        if (window.tinymce) window.tinymce.triggerSave();
        var fd = new FormData(form);
        fd.delete('_wpnonce');
        fd.delete('arrahma_tpl_actie');
        fd.append('action', 'arrahma_tpl_preview');
        fd.append('_ajax_nonce', NONCE);
        fd.append('ontvanger', document.getElementById('arrahma-tpl-ontvanger').value);
        fd.append('doelgroep', document.getElementById('arrahma-tpl-doelgroep').value);
        if (stuur) fd.append('stuur', '1');

        document.getElementById('arrahma-tpl-voorbeeld').hidden = false;
        var info = document.getElementById('arrahma-tpl-info');
        info.textContent = 'Bezig…';
        return fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (!j || !j.success) throw new Error((j && j.data && j.data.message) || 'Onbekende fout');
            var d = j.data;
            document.getElementById('arrahma-tpl-onderwerp').textContent = d.onderwerp;
            info.textContent = 'Aan: ' + d.ontvanger + (d.variant ? ' · tekst: ' + d.variant : '') +
                               (d.verstuurd ? ' · voorbeeld verstuurd naar ' + d.verstuurd : '');
            var ul = document.getElementById('arrahma-tpl-problemen');
            ul.innerHTML = '';
            (d.problemen || []).forEach(function (p) { var li = document.createElement('li'); li.textContent = p; ul.appendChild(li); });
            document.getElementById('arrahma-tpl-frame').srcdoc = d.html;
          })
          .catch(function (err) { info.textContent = 'Fout: ' + err.message; });
      }
      document.getElementById('arrahma-tpl-toon').addEventListener('click', function () { vraag(false); });
      document.getElementById('arrahma-tpl-stuur').addEventListener('click', function () {
        if (confirm('Dit voorbeeld naar je eigen e-mailadres sturen?')) vraag(true);
      });
    })();
    </script>
    <?php
}

// ─────────────────────────────────────────────────────────────
// OUDERAVOND: GEGROEPEERDE INSCHRIJVINGEN + PREFILL-LINK + E-MAIL
// ─────────────────────────────────────────────────────────────

/**
 * Groepeert alle inschrijvingen op e-mailadres (hoofdletterongevoelig).
 * Retourneert [ 'email@voorbeeld.nl' => [ 'email' => string, 'names' => string[], 'rows' => object[] ], ... ]
 * 'rows' bevat de volledige inschrijvingsrijen — nodig voor de bevestigingsmail met ingevulde gegevens.
 */
function arrahma_email_recipients(): array {
    global $wpdb;
    $table = $wpdb->prefix . ARRAHMA_TABLE;
    $rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE email != '' AND status != 'uitgeschreven' ORDER BY email, id" );

    $recipients = [];
    foreach ( $rows as $row ) {
        $key = strtolower( trim( $row->email ) );
        if ( ! isset( $recipients[ $key ] ) ) {
            $recipients[ $key ] = [ 'email' => $row->email, 'names' => [], 'rows' => [] ];
        }
        $recipients[ $key ]['names'][] = trim( $row->voornaam . ' ' . $row->achternaam );
        $recipients[ $key ]['rows'][]  = $row;
    }

    return $recipients;
}

/** Namen van een set inschrijvingsrijen (objecten óf arrays), in dezelfde volgorde. */
function arrahma_names_from_rows( array $rows ): array {
    return array_map(
        function ( $row ) {
            $r = arrahma_row_to_array( $row );
            return trim( ( $r['voornaam'] ?? '' ) . ' ' . ( $r['achternaam'] ?? '' ) );
        },
        array_values( $rows )
    );
}

/** Doelgroepen waarvoor dit e-mailtype bedoeld is, of null als het voor alle doelgroepen geldt. */
function arrahma_email_type_categories( string $type ): ?array {
    return arrahma_email_types()[ $type ]['categorieen'] ?? null;
}

/**
 * De inschrijvingsrijen van één ontvanger die bij dit e-mailtype horen. Eén adres kan gemengd zijn
 * (een vader met kinderen die zichzelf ook inschreef), daarom per rij en niet per ontvanger.
 */
function arrahma_rows_for_email_type( array $rows, string $type, string $doelgroep = '' ): array {
    return arrahma_filter_rows_by_categories( $rows, arrahma_email_type_categories( $type ), $doelgroep );
}

/** Rijen binnen deze doelgroepen (null = alle), eventueel verder versmald tot één gekozen doelgroep. */
function arrahma_filter_rows_by_categories( array $rows, ?array $categorieen, string $doelgroep = '' ): array {
    if ( $doelgroep !== '' ) {
        $categorieen = $categorieen === null ? [ $doelgroep ] : array_values( array_intersect( $categorieen, [ $doelgroep ] ) );
    }
    if ( $categorieen === null ) return array_values( $rows );
    return array_values( array_filter( $rows, function ( $row ) use ( $categorieen ) {
        return in_array( arrahma_row_to_array( $row )['inschrijving_voor'] ?? '', $categorieen, true );
    } ) );
}

// ─────────────────────────────────────────────────────────────
// VERZENDLOG + BATCHGEWIJS VERSTUREN
// ─────────────────────────────────────────────────────────────

function arrahma_log_table(): string {
    global $wpdb;
    return $wpdb->prefix . ARRAHMA_LOG_TABLE;
}

/** Legt één verzendpoging vast — ook een mislukte, zodat je die gericht kunt herhalen. */
function arrahma_log_email( string $email, string $type, string $doelgroep, int $aantal, bool $gelukt ): void {
    global $wpdb;
    $wpdb->insert(
        arrahma_log_table(),
        [
            'email'      => $email,
            'email_type' => $type,
            'doelgroep'  => $doelgroep,
            'aantal'     => $aantal,
            'gelukt'     => $gelukt ? 1 : 0,
        ],
        [ '%s', '%s', '%s', '%d', '%d' ]
    );

    // Cache van arrahma_sent_log() ongeldig maken: er is zojuist iets bijgekomen.
    arrahma_sent_log( '', true );
}

/**
 * Wanneer kreeg elk adres dit e-mailtype voor het laatst met succes?
 * Retourneert [ 'ouder@voorbeeld.nl' => '2026-09-07 14:21:03', ... ].
 */
function arrahma_sent_log( string $type, bool $leeg_cache = false ): array {
    global $wpdb;

    // Per request cachen: de verzendlus vraagt dit voor elke ontvanger op. De cache wordt geleegd
    // zodra er iets in het log wordt geschreven, zodat lezen na versturen nooit verouderd is.
    static $cache = [];
    if ( $leeg_cache ) { $cache = []; return []; }
    if ( isset( $cache[ $type ] ) ) return $cache[ $type ];

    $rows = $wpdb->get_results( $wpdb->prepare(
        'SELECT email, MAX(verzonden_op) AS laatst FROM ' . arrahma_log_table()
        . ' WHERE email_type = %s AND gelukt = 1 GROUP BY email',
        $type
    ) );

    $log = [];
    foreach ( (array) $rows as $row ) {
        $log[ strtolower( $row->email ) ] = $row->laatst;
    }

    $cache[ $type ] = $log;
    return $log;
}

/**
 * Verstuurt één e-mail aan één ontvanger en legt het resultaat vast.
 *
 * Gedeeld door het batchgewijze (AJAX) pad en het gewone formulier-pad, zodat beide precies
 * dezelfde regels volgen. Retourneert 'verstuurd', 'overgeslagen' of 'mislukt'.
 */
function arrahma_send_to_recipient( array $recipient, string $type, string $doelgroep, bool $skip_sent, array $bijlagen = [] ): string {
    $rows = arrahma_rows_for_email_type( $recipient['rows'], $type, $doelgroep );
    if ( empty( $rows ) ) return 'overgeslagen';

    if ( $skip_sent && isset( arrahma_sent_log( $type )[ strtolower( $recipient['email'] ) ] ) ) {
        return 'overgeslagen';
    }

    $ok = arrahma_send_template_email( $type, $recipient['email'], $rows, '', [ 'doelgroep' => $doelgroep ], $bijlagen );

    arrahma_log_email( $recipient['email'], $type, $doelgroep, count( $rows ), $ok );
    return $ok ? 'verstuurd' : 'mislukt';
}

/**
 * Verstuurt één batch. De browser roept dit herhaald aan, telkens met de volgende ARRAHMA_BATCH_SIZE
 * ontvangers, zodat er nooit één verzoek is dat langer duurt dan een krappe max_execution_time.
 */
add_action( 'wp_ajax_arrahma_send_batch', 'arrahma_ajax_send_batch' );

// De bijlagenkiezer gebruikt de WordPress-mediabibliotheek; alleen laden op de verzendpagina.
add_action( 'admin_enqueue_scripts', function () {
    if ( ( $_GET['page'] ?? '' ) === 'arrahma-emails' && current_user_can( 'manage_options' ) ) {
        wp_enqueue_media();
    }
} );

function arrahma_ajax_send_batch(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Geen rechten.' ], 403 );
    }
    check_ajax_referer( 'arrahma_send_emails' );

    $type      = sanitize_text_field( wp_unslash( $_POST['email_type'] ?? '' ) );
    $doelgroep = sanitize_text_field( wp_unslash( $_POST['doelgroep'] ?? '' ) );
    $skip_sent = ! empty( $_POST['skip_sent'] );
    $keys      = array_map( 'sanitize_text_field', (array) ( $_POST['recipients'] ?? [] ) );

    if ( ! isset( arrahma_email_types()[ $type ] ) ) {
        wp_send_json_error( [ 'message' => 'Onbekend e-mailtype.' ], 400 );
    }
    if ( arrahma_email_template_problemen( arrahma_email_templates()[ $type ] ) ) {
        wp_send_json_error( [ 'message' => 'De tekst van deze e-mail bevat fouten. Los die eerst op onder Inschrijvingen → E-mailteksten.' ], 400 );
    }
    if ( $doelgroep !== '' && ! in_array( $doelgroep, arrahma_active_categories(), true ) ) {
        $doelgroep = '';
    }
    if ( count( $keys ) > ARRAHMA_BATCH_SIZE ) {
        wp_send_json_error( [ 'message' => 'Batch is te groot.' ], 400 );
    }
    $bijlagen = arrahma_bijlagen_uit_request();
    if ( $bijlagen['fout'] !== '' ) {
        wp_send_json_error( [ 'message' => $bijlagen['fout'] ], 400 );
    }

    $recipients = arrahma_email_recipients();
    $resultaat  = [ 'verstuurd' => 0, 'overgeslagen' => 0, 'mislukt' => [] ];

    foreach ( $keys as $key ) {
        $key = strtolower( $key );
        if ( ! isset( $recipients[ $key ] ) ) { $resultaat['overgeslagen']++; continue; }

        $status = arrahma_send_to_recipient( $recipients[ $key ], $type, $doelgroep, $skip_sent, $bijlagen['paden'] );
        if ( $status === 'mislukt' ) {
            $resultaat['mislukt'][] = $recipients[ $key ]['email'];
        } else {
            $resultaat[ $status ]++;
        }
    }

    wp_send_json_success( $resultaat );
}

/** Aantal inschrijvingen per doelgroep voor één ontvanger, bijv. [ 'kinderen' => 2 ]. */
function arrahma_category_counts_for_recipient( array $recipient ): array {
    $counts = [];
    foreach ( $recipient['rows'] as $row ) {
        $cat = $row->inschrijving_voor;
        $counts[ $cat ] = ( $counts[ $cat ] ?? 0 ) + 1;
    }
    return $counts;
}

/** Bouwt een vooraf ingevulde Google Form-link voor één ouder. */
function arrahma_ouderavond_prefill_url( string $email, array $names ): string {
    return 'https://docs.google.com/forms/d/e/' . ARRAHMA_OUDERAVOND_FORM_ID . '/viewform'
        . '?usp=pp_url'
        . '&entry.' . ARRAHMA_OUDERAVOND_ENTRY_EMAIL . '=' . rawurlencode( $email )
        . '&entry.' . ARRAHMA_OUDERAVOND_ENTRY_KIND  . '=' . rawurlencode( implode( ', ', $names ) );
}

// ─────────────────────────────────────────────────────────────
// E-MAIL: GEGROEPEERDE MELDING NAAR BESTUUR (gezin)
// ─────────────────────────────────────────────────────────────
function arrahma_send_group_notification( array $rows, string $groep_id ) {
    $first = $rows[0];
    $to    = email_to_send_to( 'kinderen' );
    $count = count( $rows );

    // Gedeelde contact-, adres- en bankgegevens
    $shared  = arrahma_email_row( 'Contactpersoon',      $first['cp_voornaam'] . ' ' . $first['cp_achternaam'],                     false );
    $shared .= arrahma_email_row( 'Telefoon (WhatsApp)', $first['cp_telefoon'] ?: '—',                                             true );
    $shared .= arrahma_email_row( 'E-mailadres',         $first['email'],                                                          false );
    $shared .= arrahma_email_row( 'Adres',               $first['adres'] . ', ' . $first['postcode'] . ' ' . $first['woonplaats'], true );
    $shared .= arrahma_email_row( 'Rekeningnummer',      $first['rekeningnummer'],                                                 false );
    $shared .= arrahma_email_row( 'Naam rekeninghouder', $first['naam_rekeninghouder'],                                           true );
    $shared .= arrahma_email_row( 'Betaalwijze',         arrahma_betaalwijze_label( $first['betaalwijze'] ?? '' ),                 false );

    // Blok per kind
    $kinderen_html = '';
    $i = 0;
    foreach ( $rows as $r ) {
        $i++;
        $krows  = arrahma_email_row( 'Naam',                $r['voornaam'] . ' ' . $r['achternaam'], false );
        $krows .= arrahma_email_row( 'Geboortedatum',       $r['geboortedatum'] ?: '—',              true );
        $krows .= arrahma_email_row( 'Niveau',              arrahma_niveau_label( $r['niveau'] ),    false );
        $krows .= arrahma_email_row( 'Lesdagen (voorkeur)', arrahma_roster_label( $r['rooster'] ),   true );
        $kinderen_html .= '
        <p style="margin:22px 0 8px;font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#2d3a4a;">Kind ' . $i . '</p>
        <table width="100%" cellpadding="0" cellspacing="0" style="border-radius:6px;overflow:hidden;border:1px solid #e8eaed;">
          ' . $krows . '
        </table>';
    }

    $admin_url  = admin_url( 'admin.php?page=arrahma-inschrijvingen' );
    $inner_html = '
      <h2 style="margin:0 0 4px;font-size:20px;font-weight:700;color:#1a1a1a;">Nieuwe gezinsinschrijving — ' . $count . ' ' . ( $count === 1 ? 'kind' : 'kinderen' ) . '</h2>
      <p style="margin:0 0 24px;font-size:14px;color:#888;">Er is een gezinsinschrijving ontvangen via het formulier.</p>

      <p style="margin:0 0 8px;font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#2d3a4a;">Contact, adres &amp; bank</p>
      <table width="100%" cellpadding="0" cellspacing="0" style="border-radius:6px;overflow:hidden;border:1px solid #e8eaed;">
        ' . $shared . '
      </table>

      ' . $kinderen_html . '

      <div style="margin-top:28px;text-align:center;">
        <a href="' . esc_url( $admin_url ) . '" style="display:inline-block;background:#2d3a4a;color:#ffffff;text-decoration:none;font-size:13px;font-weight:700;letter-spacing:0.05em;text-transform:uppercase;padding:12px 28px;border-radius:50px;">
          Bekijk alle inschrijvingen &rarr;
        </a>
      </div>';

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: Vereniging Arrahma <oudercomite@vereniging-arrahma.nl>',
    ];

    wp_mail( $to, 'Nieuwe gezinsinschrijving (' . $count . ' kinderen) — Vereniging Arrahma', arrahma_email_wrap( $inner_html ), $headers );
}

// ─────────────────────────────────────────────────────────────
// CSV EXPORT + DELETE — fire on admin_init, before any HTML output
// ─────────────────────────────────────────────────────────────
add_action( 'admin_init', function () {
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'arrahma-inschrijvingen' ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;

    // CSV export
    if (
        isset( $_GET['export_csv'], $_GET['_wpnonce'] ) &&
        wp_verify_nonce( $_GET['_wpnonce'], 'arrahma_export_csv' )
    ) {
        arrahma_export_csv();
        exit;
    }

    // Delete single entry
    if (
        isset( $_GET['delete_entry'], $_GET['_wpnonce'] )
    ) {
        $id = intval( $_GET['delete_entry'] );
        if ( $id > 0 && wp_verify_nonce( $_GET['_wpnonce'], 'arrahma_delete_' . $id ) ) {
            global $wpdb;
            $wpdb->delete( $wpdb->prefix . ARRAHMA_TABLE, [ 'id' => $id ], [ '%d' ] );
            // Aanwezigheid en notities horen bij de persoon: bij verwijderen gaan ze mee.
            $wpdb->delete( $wpdb->prefix . ARRAHMA_AANWEZIG_TABLE, [ 'inschrijving_id' => $id ], [ '%d' ] );
            $wpdb->delete( $wpdb->prefix . ARRAHMA_NOTITIE_TABLE, [ 'inschrijving_id' => $id ], [ '%d' ] );
            wp_redirect( admin_url( 'admin.php?page=arrahma-inschrijvingen&arrahma_deleted=1' ) );
            exit;
        }
    }
} );

// ─────────────────────────────────────────────────────────────
// ADMIN MENU
// ─────────────────────────────────────────────────────────────
add_action( 'admin_menu', function () {
    add_menu_page(
        'Inschrijvingen',
        'Inschrijvingen',
        'manage_options',
        'arrahma-inschrijvingen',
        'arrahma_admin_page',
        'dashicons-groups',
        30
    );

    add_submenu_page(
        'arrahma-inschrijvingen',
        'E-mails versturen',
        'E-mails',
        'manage_options',
        'arrahma-emails',
        'arrahma_emails_page'
    );

    add_submenu_page(
        'arrahma-inschrijvingen',
        'E-mailteksten',
        'E-mailteksten',
        'manage_options',
        'arrahma-emailteksten',
        'arrahma_templates_page'
    );

    add_submenu_page(
        'arrahma-inschrijvingen',
        'Overzicht',
        'Overzicht',
        'manage_options',
        'arrahma-dashboard',
        'arrahma_dashboard_page'
    );

    add_submenu_page(
        'arrahma-inschrijvingen',
        'Instellingen',
        'Instellingen',
        'manage_options',
        'arrahma-instellingen',
        'arrahma_settings_page'
    );
} );

// ─────────────────────────────────────────────────────────────
// ADMIN PAGINA
// ─────────────────────────────────────────────────────────────
/** Velden die handmatig bewerkt mogen worden — systeemvelden (id, groep_id, datum) blijven buiten bereik. */
function arrahma_editable_fields(): array {
    return [
        'inschrijving_voor', 'voornaam', 'achternaam', 'geboortedatum',
        'telefoon', 'email', 'adres', 'postcode', 'woonplaats',
        'niveau', 'rooster', 'rekeningnummer', 'naam_rekeninghouder',
        'betaalwijze', 'cp_anders', 'cp_voornaam', 'cp_achternaam', 'cp_telefoon',
        'status',
    ];
}

/**
 * Aantal inschrijvingen in een lesdagen-tijdslot, optioneel met uitzondering van één rij.
 * Bij bewerken mag de rij zelf niet tegen zijn eigen tijdslot meetellen.
 */
function arrahma_rooster_count( string $rooster, int $exclude_id = 0 ): int {
    global $wpdb;
    if ( $rooster === '' ) return 0;
    $table = $wpdb->prefix . ARRAHMA_TABLE;
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE rooster = %s AND id != %d AND status != 'uitgeschreven'",
        $rooster,
        $exclude_id
    ) );
}

/** Lege beginwaarden voor een handmatig toegevoegde inschrijving. */
function arrahma_blank_entry_values(): array {
    $values = [];
    foreach ( arrahma_editable_fields() as $field ) {
        $values[ $field ] = '';
    }

    return array_merge( $values, [
        'inschrijving_voor'  => 'kinderen',
        'betaalwijze'        => 'maandelijks',
        'status'             => 'nieuw',
        'cp_anders'          => 0,
        'groep_id'           => '',
        'datum_inschrijving' => '',
    ] );
}

/**
 * Formulier voor één inschrijving (GET toont, POST slaat op).
 *
 * $id === 0 betekent: nieuwe inschrijving, handmatig toegevoegd vanuit het overzicht.
 * Dat verstuurt bewust géén e-mail — wie hier wordt ingevoerd komt van een papieren formulier
 * of een telefoontje en weet al dat hij is ingeschreven. Mailen kan daarna via "E-mails versturen".
 */
function arrahma_render_edit_form( int $id ) {
    global $wpdb;
    $table  = $wpdb->prefix . ARRAHMA_TABLE;
    $terug  = admin_url( 'admin.php?page=arrahma-inschrijvingen' );
    $nieuw  = ( $id === 0 );
    $entry  = null;

    if ( ! $nieuw ) {
        $entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
        if ( ! $entry ) {
            echo '<div class="wrap"><h1>Inschrijving bewerken</h1>'
               . '<div class="notice notice-error"><p>Inschrijving niet gevonden.</p></div>'
               . '<a href="' . esc_url( $terug ) . '" class="button">&larr; Terug naar overzicht</a></div>';
            return;
        }
    }

    $values  = $nieuw ? arrahma_blank_entry_values() : arrahma_row_to_array( $entry );
    $errors  = [];
    $notice  = '';

    // ── Opslaan
    if ( isset( $_POST['arrahma_save_entry'], $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'arrahma_edit_' . $id ) ) {
        foreach ( arrahma_editable_fields() as $f ) {
            $values[ $f ] = ( $f === 'cp_anders' )
                ? ( ! empty( $_POST[ $f ] ) ? 1 : 0 )
                : sanitize_text_field( wp_unslash( $_POST[ $f ] ?? '' ) );
        }

        if ( ! in_array( $values['inschrijving_voor'], array_keys( arrahma_category_labels() ), true ) ) {
            $errors[] = 'Ongeldige categorie.';
        }
        if ( $values['voornaam'] === '' || $values['achternaam'] === '' ) {
            $errors[] = 'Voornaam en achternaam zijn verplicht.';
        }
        if ( ! is_email( $values['email'] ) ) {
            $errors[] = 'Vul een geldig e-mailadres in.';
        }
        if ( $values['geboortedatum'] !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $values['geboortedatum'] ) ) {
            $errors[] = 'Geboortedatum moet de vorm jjjj-mm-dd hebben.';
        }
        if ( $values['rooster'] !== '' && ! isset( arrahma_roster_labels()[ $values['rooster'] ] ) ) {
            $errors[] = 'Ongeldig lesdagen-tijdslot.';
        }
        if ( $values['niveau'] !== '' && ! isset( arrahma_niveau_labels()[ $values['niveau'] ] ) ) {
            $errors[] = 'Ongeldig niveau.';
        }
        if ( ! in_array( $values['status'], arrahma_statussen(), true ) ) {
            $errors[] = 'Ongeldige status.';
        }

        // ── Capaciteitscontrole: de rij zelf telt niet mee tegen zijn eigen tijdslot
        if ( empty( $errors ) && $values['rooster'] !== '' ) {
            $bezet = arrahma_rooster_count( $values['rooster'], $id );
            $cap   = arrahma_cap_for( $values['rooster'] );
            if ( $bezet >= $cap ) {
                $errors[] = sprintf(
                    'Dit tijdslot zit vol (%d/%d) — %s. Kies een ander tijdslot.',
                    $bezet,
                    $cap,
                    arrahma_roster_label( $values['rooster'] )
                );
            }
        }

        if ( empty( $errors ) ) {
            $data    = [];
            $formats = [];
            foreach ( arrahma_editable_fields() as $f ) {
                $data[ $f ] = ( $f === 'geboortedatum' ) ? ( $values[ $f ] ?: null ) : $values[ $f ];
                $formats[]  = ( $f === 'cp_anders' ) ? '%d' : '%s';
            }

            if ( $nieuw ) {
                // datum_inschrijving laten we aan de kolomstandaard (CURRENT_TIMESTAMP) over.
                if ( $wpdb->insert( $table, $data, $formats ) === false ) {
                    $errors[] = 'Opslaan in de database is mislukt.';
                } else {
                    // Vanaf hier is het een gewone bewerking van de zojuist aangemaakte rij.
                    // Zo levert een herlaadde pagina geen tweede inschrijving op.
                    $id     = (int) $wpdb->insert_id;
                    $nieuw  = false;
                    $notice = 'Inschrijving toegevoegd. Er is geen e-mail verstuurd.';
                    $entry  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
                    $values = arrahma_row_to_array( $entry );
                }
            } elseif ( $wpdb->update( $table, $data, [ 'id' => $id ], $formats, [ '%d' ] ) === false ) {
                $errors[] = 'Opslaan in de database is mislukt.';
            } else {
                $notice = 'Inschrijving bijgewerkt.';
                $entry  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
                $values = arrahma_row_to_array( $entry );
            }
        }
    }

    $txt = function ( $key ) use ( $values ) { return esc_attr( $values[ $key ] ?? '' ); };
    ?>
    <div class="wrap">
      <h1 style="display:flex;align-items:center;gap:.5rem">
        <span class="dashicons <?= $nieuw ? 'dashicons-plus-alt' : 'dashicons-edit' ?>" style="font-size:1.5rem;margin-top:3px"></span>
        <?php if ( $nieuw ) : ?>
          Nieuwe inschrijving
        <?php else : ?>
          Inschrijving bewerken <span style="color:#999;font-weight:400">#<?= (int) $id ?></span>
        <?php endif; ?>
      </h1>

      <?php if ( $nieuw ) : ?>
        <p style="color:#555;max-width:680px">
          Handmatig toevoegen, bijvoorbeeld na een papieren formulier of een telefoontje.
          Er wordt <strong>geen e-mail verstuurd</strong> — dat kan daarna via
          <a href="<?= esc_url( admin_url( 'admin.php?page=arrahma-emails' ) ) ?>">E-mails versturen</a>.
        </p>
      <?php endif; ?>

      <?php if ( $notice ) : ?>
        <div class="notice notice-success is-dismissible"><p><?= esc_html( $notice ) ?></p></div>
      <?php endif; ?>

      <?php if ( ! empty( $errors ) ) : ?>
        <div class="notice notice-error"><p><strong>Niet opgeslagen:</strong></p><ul style="margin:0 0 .5rem 1.25rem;list-style:disc">
          <?php foreach ( $errors as $e ) : ?><li><?= esc_html( $e ) ?></li><?php endforeach; ?>
        </ul></div>
      <?php endif; ?>

      <p><a href="<?= esc_url( $terug ) ?>" class="button">&larr; Terug naar overzicht</a></p>

      <?php
        // Na een geslaagde toevoeging wijst het formulier naar de bewerk-URL van de nieuwe rij,
        // zodat opnieuw opslaan bijwerkt in plaats van een tweede inschrijving aan te maken.
        $form_action = $nieuw
            ? admin_url( 'admin.php?page=arrahma-inschrijvingen&new_entry=1' )
            : admin_url( 'admin.php?page=arrahma-inschrijvingen&edit_entry=' . $id );
      ?>
      <form method="post" action="<?= esc_url( $form_action ) ?>">
        <?php wp_nonce_field( 'arrahma_edit_' . $id ); ?>

        <h2 style="font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:#2d3a4a;margin:1.5rem 0 .5rem">Gegevens ingeschrevene</h2>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="inschrijving_voor">Categorie</label></th>
            <td>
              <select id="inschrijving_voor" name="inschrijving_voor">
                <?php foreach ( arrahma_category_labels() as $val => $label ) : ?>
                  <option value="<?= esc_attr( $val ) ?>" <?= selected( $values['inschrijving_voor'], $val, false ) ?>><?= esc_html( $label ) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="voornaam">Voornaam</label></th>
            <td><input type="text" id="voornaam" name="voornaam" class="regular-text" value="<?= $txt( 'voornaam' ) ?>"></td>
          </tr>
          <tr>
            <th scope="row"><label for="achternaam">Achternaam</label></th>
            <td><input type="text" id="achternaam" name="achternaam" class="regular-text" value="<?= $txt( 'achternaam' ) ?>"></td>
          </tr>
          <tr>
            <th scope="row"><label for="geboortedatum">Geboortedatum</label></th>
            <td><input type="date" id="geboortedatum" name="geboortedatum" value="<?= $txt( 'geboortedatum' ) ?>"></td>
          </tr>
          <tr>
            <th scope="row"><label for="niveau">Niveau</label></th>
            <td>
              <select id="niveau" name="niveau">
                <option value="">— Geen —</option>
                <?php foreach ( arrahma_niveau_labels() as $val => $label ) : ?>
                  <option value="<?= esc_attr( $val ) ?>" <?= selected( $values['niveau'], $val, false ) ?>><?= esc_html( $label ) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="rooster">Lesdagen</label></th>
            <td>
              <select id="rooster" name="rooster">
                <option value="">— Geen —</option>
                <?php foreach ( arrahma_roster_labels() as $val => $label ) :
                    $bezet_excl = arrahma_rooster_count( $val, $id );
                    $is_huidig  = ( $values['rooster'] === $val );
                    $bezet_disp = $bezet_excl + ( $is_huidig ? 1 : 0 );
                    $cap_val    = arrahma_cap_for( $val );
                    $vol        = $bezet_excl >= $cap_val;
                ?>
                  <option value="<?= esc_attr( $val ) ?>"
                          <?= selected( $values['rooster'], $val, false ) ?>
                          <?= ( $vol && ! $is_huidig ) ? 'disabled' : '' ?>>
                    <?= esc_html( $label ) ?> — <?= (int) $bezet_disp ?>/<?= (int) $cap_val ?><?= $vol && ! $is_huidig ? ' (vol)' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="description">Volle tijdsloten kunnen niet gekozen worden. De capaciteit per lesblok/lesgroep stel je in bij <a href="<?= esc_url( admin_url( 'admin.php?page=arrahma-instellingen' ) ) ?>">Instellingen</a>.</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="status">Status</label></th>
            <td>
              <select id="status" name="status">
                <?php foreach ( arrahma_statussen() as $s ) : ?>
                  <option value="<?= esc_attr( $s ) ?>" <?= selected( $values['status'], $s, false ) ?>><?= esc_html( ucfirst( $s ) ) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
        </table>

        <h2 style="font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:#2d3a4a;margin:1.5rem 0 .5rem">Contact &amp; adres</h2>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="email">E-mailadres</label></th>
            <td><input type="email" id="email" name="email" class="regular-text" value="<?= $txt( 'email' ) ?>"></td>
          </tr>
          <tr>
            <th scope="row"><label for="telefoon">Telefoonnummer</label></th>
            <td><input type="text" id="telefoon" name="telefoon" class="regular-text" value="<?= $txt( 'telefoon' ) ?>"></td>
          </tr>
          <tr>
            <th scope="row"><label for="adres">Adres</label></th>
            <td><input type="text" id="adres" name="adres" class="regular-text" value="<?= $txt( 'adres' ) ?>"></td>
          </tr>
          <tr>
            <th scope="row"><label for="postcode">Postcode</label></th>
            <td><input type="text" id="postcode" name="postcode" value="<?= $txt( 'postcode' ) ?>"></td>
          </tr>
          <tr>
            <th scope="row"><label for="woonplaats">Woonplaats</label></th>
            <td><input type="text" id="woonplaats" name="woonplaats" class="regular-text" value="<?= $txt( 'woonplaats' ) ?>"></td>
          </tr>
        </table>

        <h2 style="font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:#2d3a4a;margin:1.5rem 0 .5rem">Contactpersoon</h2>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">Afwijkende contactpersoon</th>
            <td>
              <label>
                <input type="checkbox" name="cp_anders" value="1" <?= checked( ! empty( $values['cp_anders'] ), true, false ) ?>>
                De contactpersoon is iemand anders dan de ingeschrevene
              </label>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="cp_voornaam">Voornaam contactpersoon</label></th>
            <td><input type="text" id="cp_voornaam" name="cp_voornaam" class="regular-text" value="<?= $txt( 'cp_voornaam' ) ?>"></td>
          </tr>
          <tr>
            <th scope="row"><label for="cp_achternaam">Achternaam contactpersoon</label></th>
            <td><input type="text" id="cp_achternaam" name="cp_achternaam" class="regular-text" value="<?= $txt( 'cp_achternaam' ) ?>"></td>
          </tr>
          <tr>
            <th scope="row"><label for="cp_telefoon">Telefoon contactpersoon</label></th>
            <td><input type="text" id="cp_telefoon" name="cp_telefoon" class="regular-text" value="<?= $txt( 'cp_telefoon' ) ?>"></td>
          </tr>
        </table>

        <h2 style="font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:#2d3a4a;margin:1.5rem 0 .5rem">Betaling</h2>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="rekeningnummer">Rekeningnummer (IBAN)</label></th>
            <td><input type="text" id="rekeningnummer" name="rekeningnummer" class="regular-text" value="<?= $txt( 'rekeningnummer' ) ?>"></td>
          </tr>
          <tr>
            <th scope="row"><label for="naam_rekeninghouder">Naam rekeninghouder</label></th>
            <td><input type="text" id="naam_rekeninghouder" name="naam_rekeninghouder" class="regular-text" value="<?= $txt( 'naam_rekeninghouder' ) ?>"></td>
          </tr>
          <tr>
            <th scope="row"><label for="betaalwijze">Betaalwijze</label></th>
            <td>
              <select id="betaalwijze" name="betaalwijze">
                <?php foreach ( arrahma_betaalwijze_labels() as $val => $label ) : ?>
                  <option value="<?= esc_attr( $val ) ?>" <?= selected( $values['betaalwijze'], $val, false ) ?>><?= esc_html( $label ) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
        </table>

        <?php if ( ! empty( $values['datum_inschrijving'] ) ) : ?>
        <p style="color:#888;font-size:.85rem">
          Ingeschreven op <?= esc_html( date_i18n( 'd-m-Y H:i', strtotime( $values['datum_inschrijving'] ) ) ) ?><?php if ( ! empty( $values['groep_id'] ) ) : ?> · onderdeel van een gezinsinschrijving<?php endif; ?>
        </p>
        <?php endif; ?>

        <p class="submit">
          <button type="submit" name="arrahma_save_entry" value="1" class="button button-primary">
            <?= $nieuw ? 'Inschrijving toevoegen' : 'Wijzigingen opslaan' ?>
          </button>
          <a href="<?= esc_url( $terug ) ?>" class="button" style="margin-left:.5rem">Annuleren</a>
        </p>
      </form>
    </div>
    <?php
}

function arrahma_admin_page() {
    global $wpdb;
    $table  = $wpdb->prefix . ARRAHMA_TABLE;
    $labels = arrahma_category_labels();

    // ── Bewerk- of toevoegformulier in plaats van de tabel
    if ( isset( $_GET['edit_entry'] ) ) {
        arrahma_render_edit_form( intval( $_GET['edit_entry'] ) );
        return;
    }
    if ( isset( $_GET['new_entry'] ) ) {
        arrahma_render_edit_form( 0 );
        return;
    }

    // ── Verwijderd melding
    if ( isset( $_GET['arrahma_deleted'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>Inschrijving verwijderd.</p></div>';
    }

    // ── Status bijwerken
    if (
        isset( $_POST['arrahma_update_status'], $_POST['_wpnonce'] ) &&
        wp_verify_nonce( $_POST['_wpnonce'], 'arrahma_update_status' )
    ) {
        $id     = intval( $_POST['entry_id'] );
        $status = sanitize_text_field( $_POST['new_status'] );
        if ( in_array( $status, arrahma_statussen(), true ) ) {
            $wpdb->update( $table, [ 'status' => $status ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
            echo '<div class="notice notice-success is-dismissible"><p>Status bijgewerkt.</p></div>';
        }
    }

    // ── Filter
    $filter = isset( $_GET['status_filter'] ) ? sanitize_text_field( $_GET['status_filter'] ) : '';
    $where  = $filter ? $wpdb->prepare( 'WHERE status = %s', $filter ) : '';

    $entries = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY datum_inschrijving DESC" );

    $totals = $wpdb->get_results( "SELECT status, COUNT(*) as cnt FROM {$table} GROUP BY status" );
    $counts = [ 'nieuw' => 0, 'verwerkt' => 0, 'afgewezen' => 0, 'uitgeschreven' => 0, 'totaal' => 0 ];
    foreach ( $totals as $row ) {
        $counts[ $row->status ] = (int) $row->cnt;
        $counts['totaal']      += (int) $row->cnt;
    }

    $export_url = wp_nonce_url(
        admin_url( 'admin.php?page=arrahma-inschrijvingen&export_csv=1' ),
        'arrahma_export_csv'
    );

    $stat_colors = [
        'totaal'    => '#2d3a4a',
        'nieuw'     => '#1976d2',
        'verwerkt'  => '#388e3c',
        'afgewezen' => '#d32f2f',
        'uitgeschreven' => '#757575',
    ];
    ?>
    <div class="wrap">
      <h1 style="display:flex;align-items:center;gap:.5rem">
        <span class="dashicons dashicons-groups" style="font-size:1.5rem;margin-top:3px"></span>
        Inschrijvingen
      </h1>

      <!-- Stats -->
      <div style="display:flex;gap:1rem;margin:1.25rem 0 1.5rem;flex-wrap:wrap">
        <?php foreach ( $stat_colors as $label => $color ) : ?>
        <div style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:1rem 1.5rem;min-width:90px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.06)">
          <div style="font-size:2rem;font-weight:700;color:<?= esc_attr( $color ) ?>;line-height:1"><?= (int) $counts[ $label ] ?></div>
          <div style="font-size:.75rem;color:#888;margin-top:.25rem;text-transform:capitalize"><?= esc_html( $label ) ?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Filter + Export -->
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;flex-wrap:wrap;gap:.5rem">
        <div style="display:flex;gap:.35rem;flex-wrap:wrap">
          <?php foreach ( [ '' => 'Alle', 'nieuw' => 'Nieuw', 'verwerkt' => 'Verwerkt', 'afgewezen' => 'Afgewezen', 'uitgeschreven' => 'Uitgeschreven' ] as $val => $lbl ) :
              $active = $filter === $val ? 'button-primary' : '';
              $url    = admin_url( 'admin.php?page=arrahma-inschrijvingen' . ( $val ? '&status_filter=' . $val : '' ) );
          ?>
          <a href="<?= esc_url( $url ) ?>" class="button <?= $active ?>"><?= esc_html( $lbl ) ?></a>
          <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
          <a href="<?= esc_url( admin_url( 'admin.php?page=arrahma-inschrijvingen&new_entry=1' ) ) ?>" class="button button-primary" style="display:flex;align-items:center;gap:.35rem">
            <span class="dashicons dashicons-plus-alt2" style="margin-top:3px"></span> Nieuwe inschrijving
          </a>
          <a href="<?= esc_url( $export_url ) ?>" class="button button-secondary" style="display:flex;align-items:center;gap:.35rem">
            <span class="dashicons dashicons-download" style="margin-top:3px"></span> Exporteer als CSV
          </a>
        </div>
      </div>

      <!-- Table -->
      <table class="wp-list-table widefat fixed striped" style="border-radius:8px;overflow:hidden">
        <thead>
          <tr>
            <th style="width:36px">#</th>
            <th>Naam</th>
            <th>Categorie</th>
            <th>Contact</th>
            <th style="width:160px">Niveau &amp; lesdagen</th>
            <th style="width:100px">Datum</th>
            <th style="width:90px">Status</th>
            <th style="width:200px">Actie</th>
          </tr>
        </thead>
        <tbody>
        <?php if ( empty( $entries ) ) : ?>
          <tr><td colspan="8" style="text-align:center;padding:2.5rem;color:#999">Geen inschrijvingen gevonden.</td></tr>
        <?php else : foreach ( $entries as $e ) :
          $sc = $stat_colors[ $e->status ] ?? '#888';
        ?>
          <tr>
            <td><?= (int) $e->id ?></td>
            <td>
              <strong><?= esc_html( $e->voornaam . ' ' . $e->achternaam ) ?></strong>
              <?php if ( $e->geboortedatum ) : ?>
                <br><small style="color:#999"><?= esc_html( date_i18n( 'd M Y', strtotime( $e->geboortedatum ) ) ) ?></small>
              <?php endif; ?>
              <?php if ( ! empty( $e->groep_id ) ) : ?>
                <br><small title="<?= esc_attr( $e->groep_id ) ?>" style="display:inline-block;margin-top:2px;padding:1px 8px;border-radius:50px;background:rgba(45,58,74,.1);color:#2d3a4a;font-size:.7rem;font-weight:600">gezin</small>
              <?php endif; ?>
            </td>
            <td>
              <?= esc_html( $labels[ $e->inschrijving_voor ] ?? $e->inschrijving_voor ) ?>
              <br><small style="color:#888"><?= esc_html( arrahma_betaalwijze_label( $e->betaalwijze ?? '' ) ) ?></small>
            </td>
            <td>
              <?php if ( $e->cp_anders && $e->cp_voornaam ) : ?>
                <strong><?= esc_html( $e->cp_voornaam . ' ' . $e->cp_achternaam ) ?></strong>
                <br><small style="color:#888"><?= esc_html( $e->cp_telefoon ) ?></small>
                <br><small style="color:#aaa">(contactpersoon)</small>
              <?php else : ?>
                <a href="mailto:<?= esc_attr( $e->email ) ?>"><?= esc_html( $e->email ) ?></a>
                <?php if ( $e->telefoon ) : ?>
                  <br><small style="color:#888"><?= esc_html( $e->telefoon ) ?></small>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td>
              <?= esc_html( arrahma_niveau_label( $e->niveau ) ) ?>
              <?php if ( $e->rooster ) : ?>
                <br><small style="color:#888"><?= esc_html( arrahma_roster_label( $e->rooster ) ) ?></small>
              <?php endif; ?>
            </td>
            <td><?= esc_html( date_i18n( 'd M Y', strtotime( $e->datum_inschrijving ) ) ) ?></td>
            <td>
              <span style="display:inline-block;padding:2px 10px;border-radius:50px;background:<?= esc_attr( $sc ) ?>1a;color:<?= esc_attr( $sc ) ?>;font-size:.78rem;font-weight:600;text-transform:capitalize">
                <?= esc_html( $e->status ) ?>
              </span>
            </td>
            <td>
              <div style="display:flex;gap:5px;align-items:center;flex-wrap:wrap">
                <form method="post" style="display:inline-flex;gap:5px;align-items:center">
                  <?php wp_nonce_field( 'arrahma_update_status' ); ?>
                  <input type="hidden" name="entry_id" value="<?= (int) $e->id ?>">
                  <select name="new_status" style="padding:3px 6px;font-size:.8rem;border-radius:4px;border:1px solid #ccc">
                    <?php foreach ( arrahma_statussen() as $s ) : ?>
                    <option value="<?= $s ?>" <?= selected( $e->status, $s, false ) ?>><?= ucfirst( $s ) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="button" style="padding:3px 10px;font-size:.8rem">OK</button>
                </form>
                <?php
                  $edit_url = admin_url( 'admin.php?page=arrahma-inschrijvingen&edit_entry=' . (int) $e->id );
                ?>
                <a href="<?= esc_url( $edit_url ) ?>"
                   class="button"
                   style="padding:3px 8px;font-size:.8rem;display:inline-flex;align-items:center;gap:3px">
                  <span class="dashicons dashicons-edit" style="font-size:14px;width:14px;height:14px;margin-top:1px"></span>
                </a>
                <?php
                  $delete_url = wp_nonce_url(
                      admin_url( 'admin.php?page=arrahma-inschrijvingen&delete_entry=' . (int) $e->id ),
                      'arrahma_delete_' . (int) $e->id
                  );
                ?>
                <a href="<?= esc_url( $delete_url ) ?>"
                   class="button"
                   style="padding:3px 8px;font-size:.8rem;color:#d32f2f;border-color:#d32f2f;display:inline-flex;align-items:center;gap:3px"
                   onclick="return confirm('Weet je zeker dat je deze inschrijving wilt verwijderen? Dit kan niet ongedaan worden gemaakt.')">
                  <span class="dashicons dashicons-trash" style="font-size:14px;width:14px;height:14px;margin-top:1px"></span>
                </a>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>

      <p style="margin-top:.75rem;color:#aaa;font-size:.8rem">
        <?= count( $entries ) ?> inschrijving<?= count( $entries ) !== 1 ? 'en' : '' ?> weergegeven.
      </p>
    </div>
    <?php
}

// ─────────────────────────────────────────────────────────────
// ADMIN PAGINA: OUDERAVOND UITNODIGEN
// ─────────────────────────────────────────────────────────────
/**
 * Voorbeeldrij voor test-e-mails: de meest recente echte inschrijving zodat de test
 * er realistisch uitziet, of een duidelijk herkenbare placeholder als de tabel leeg is.
 */
function arrahma_sample_row(): object {
    global $wpdb;
    $table = $wpdb->prefix . ARRAHMA_TABLE;
    $row   = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY datum_inschrijving DESC LIMIT 1" );

    if ( $row ) return $row;

    return (object) [
        'voornaam'            => 'Test',
        'achternaam'          => 'Kind',
        'geboortedatum'       => '2018-01-01',
        'inschrijving_voor'   => 'kinderen',
        'niveau'              => 'basis',
        'rooster'             => 'za_zo_blok1',
        'email'               => '',
        'telefoon'            => '0600000000',
        'cp_anders'           => 0,
        'cp_voornaam'         => '',
        'cp_achternaam'       => '',
        'cp_telefoon'         => '',
        'adres'               => 'Teststraat 1',
        'postcode'            => '1234 AB',
        'woonplaats'          => 'Almere',
        'rekeningnummer'      => 'NL00TEST0123456789',
        'naam_rekeninghouder' => 'Test Rekeninghouder',
        'betaalwijze'         => 'maandelijks',
        'groep_id'            => null,
        'datum_inschrijving'  => current_time( 'mysql' ),
    ];
}

function arrahma_emails_page() {
    $recipients = arrahma_email_recipients();
    $result     = null;

    $email_types = arrahma_email_types();
    $types       = wp_list_pluck( $email_types, 'label' );

    // E-mails met een fout in de tekst kunnen niet verstuurd worden (ook niet als test).
    $problemen_per_type = [];
    foreach ( arrahma_email_templates() as $k => $tpl ) {
        $problemen_per_type[ $k ] = arrahma_email_template_problemen( $tpl );
    }

    // ── Versturen (na bevestiging via nonce-form)
    if (
        isset( $_POST['arrahma_send_emails'], $_POST['_wpnonce'] ) &&
        wp_verify_nonce( $_POST['_wpnonce'], 'arrahma_send_emails' )
    ) {
        $type      = sanitize_text_field( wp_unslash( $_POST['email_type'] ?? '' ) );
        $doelgroep = sanitize_text_field( wp_unslash( $_POST['doelgroep'] ?? '' ) );
        $selected  = array_map( 'sanitize_text_field', (array) ( $_POST['recipients'] ?? [] ) );

        if ( $doelgroep !== '' && ! in_array( $doelgroep, arrahma_active_categories(), true ) ) {
            $doelgroep = '';
        }

        if ( ! isset( $types[ $type ] ) ) {
            $result = [ 'error' => 'Kies een geldig e-mailtype.' ];
        } elseif ( ! empty( $problemen_per_type[ $type ] ) ) {
            $result = [ 'error' => 'De tekst van deze e-mail bevat fouten. Los die eerst op onder Inschrijvingen → E-mailteksten.' ];
        } elseif ( empty( $selected ) ) {
            $result = [ 'error' => 'Selecteer minimaal één ontvanger.' ];
        } elseif ( ( $bijlagen = arrahma_bijlagen_uit_request() )['fout'] !== '' ) {
            $result = [ 'error' => $bijlagen['fout'] ];
        } else {
            // Terugvalpad zonder JavaScript. Normaal verstuurt de browser batchgewijs via AJAX;
            // dit pad doet alles in één verzoek en kan dus vastlopen op max_execution_time.
            // Het volgt wel exact dezelfde regels, inclusief overslaan en loggen.
            $skip_sent = ! empty( $_POST['skip_sent'] );
            $sent      = 0;
            $overgesl  = 0;
            $mislukt   = 0;
            foreach ( $selected as $key ) {
                $key = strtolower( $key );
                if ( ! isset( $recipients[ $key ] ) ) continue;

                $status = arrahma_send_to_recipient( $recipients[ $key ], $type, $doelgroep, $skip_sent, $bijlagen['paden'] );
                if ( $status === 'verstuurd' )    $sent++;
                elseif ( $status === 'mislukt' )  $mislukt++;
                else                              $overgesl++;
            }
            $result = [
                'sent'         => $sent,
                'label'        => $types[ $type ],
                'overgeslagen' => $overgesl,
                'mislukt'      => $mislukt,
                'doelgroep'    => $doelgroep !== '' ? ( arrahma_category_labels()[ $doelgroep ] ?? $doelgroep ) : '',
            ];
        }
    }

    // ── Testmail (naar een los, zelf opgegeven adres — telt niet als echte verzending)
    $test_result = null;
    if (
        isset( $_POST['arrahma_send_test'], $_POST['_wpnonce_test'] ) &&
        wp_verify_nonce( $_POST['_wpnonce_test'], 'arrahma_send_test' )
    ) {
        $test_type  = sanitize_text_field( wp_unslash( $_POST['arrahma_send_test'] ) );
        $test_email = sanitize_email( wp_unslash( $_POST['test_email'] ?? '' ) );

        if ( ! isset( $types[ $test_type ] ) ) {
            $test_result = [ 'error' => 'Kies een geldig e-mailtype.' ];
        } elseif ( ! empty( $problemen_per_type[ $test_type ] ) ) {
            $test_result = [ 'error' => 'De tekst van deze e-mail bevat fouten. Los die eerst op onder Inschrijvingen → E-mailteksten.' ];
        } elseif ( ! is_email( $test_email ) ) {
            $test_result = [ 'error' => 'Vul een geldig e-mailadres in.' ];
        } elseif ( ( $test_bijlagen = arrahma_bijlagen_uit_request() )['fout'] !== '' ) {
            $test_result = [ 'error' => $test_bijlagen['fout'] ];
        } else {
            $ok = arrahma_send_template_email( $test_type, $test_email, [ arrahma_sample_row() ], '[TEST] ', [], $test_bijlagen['paden'] );
            $test_result = $ok
                ? [ 'sent_to' => $test_email, 'label' => $types[ $test_type ], 'bijlagen' => $test_bijlagen['namen'] ]
                : [ 'error' => 'De testmail kon niet worden verstuurd. Controleer de mailinstellingen van de site.' ];
        }
    }

    // Verzendlog per e-mailtype, zodat de tabel "al gemaild" kan tonen en de JS kan overslaan.
    // Bewust ná het verzendblok: anders toont de pagina na een verzending nog de oude stand.
    $sent_logs = [];
    foreach ( array_keys( $email_types ) as $t ) {
        $sent_logs[ $t ] = arrahma_sent_log( $t );
    }
    ?>
    <div class="wrap">
      <h1 style="display:flex;align-items:center;gap:.5rem">
        <span class="dashicons dashicons-email-alt" style="font-size:1.5rem;margin-top:3px"></span>
        E-mails versturen
      </h1>

      <?php if ( isset( $result['error'] ) ) : ?>
        <div class="notice notice-error is-dismissible"><p><?= esc_html( $result['error'] ) ?></p></div>
      <?php elseif ( isset( $result['sent'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>
          <?= esc_html( $result['label'] ) ?><?php if ( ! empty( $result['doelgroep'] ) ) : ?> (alleen <?= esc_html( $result['doelgroep'] ) ?>)<?php endif; ?>
          verstuurd naar <?= (int) $result['sent'] ?> ontvanger<?= $result['sent'] !== 1 ? 's' : '' ?>.
          <?php if ( ! empty( $result['overgeslagen'] ) ) : ?>
            <?= (int) $result['overgeslagen'] ?> overgeslagen (geen inschrijving in de gekozen doelgroep, of al eerder gemaild).
          <?php endif; ?>
          <?php if ( ! empty( $result['mislukt'] ) ) : ?>
            <strong style="color:#d32f2f"><?= (int) $result['mislukt'] ?> mislukt</strong> — die staan als mislukt in het verzendlog en kun je opnieuw proberen.
          <?php endif; ?>
        </p></div>
      <?php endif; ?>

      <?php if ( isset( $test_result['error'] ) ) : ?>
        <div class="notice notice-error is-dismissible"><p><?= esc_html( $test_result['error'] ) ?></p></div>
      <?php elseif ( isset( $test_result['sent_to'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>
          Testmail "<?= esc_html( $test_result['label'] ) ?>" verstuurd naar <?= esc_html( $test_result['sent_to'] ) ?> (met voorbeeldgegevens van de meest recente inschrijving)<?php if ( ! empty( $test_result['bijlagen'] ) ) : ?>, met bijlage<?= count( $test_result['bijlagen'] ) > 1 ? 'n' : '' ?>: <?= esc_html( implode( ', ', $test_result['bijlagen'] ) ) ?><?php endif; ?>.
        </p></div>
      <?php endif; ?>

      <div style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:1.25rem 1.5rem;max-width:680px;margin-bottom:1.5rem">
        <h2 style="font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:#2d3a4a;margin:0 0 .5rem">Testmail versturen</h2>
        <p style="color:#888;font-size:.85rem;margin:0 0 1rem">
          Stuurt één van de twee e-mails naar een adres naar keuze, gevuld met de gegevens van de meest recente inschrijving (onderwerp krijgt een "[TEST]" voorvoegsel). Handig om het uiterlijk te controleren zonder een echte ouder te mailen.
        </p>
        <form method="post" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center">
          <?php wp_nonce_field( 'arrahma_send_test', '_wpnonce_test' ); ?>
          <span id="arrahma-test-bijlagen" hidden></span>
          <input type="email" name="test_email" required placeholder="jouw@email.nl" class="regular-text" style="min-width:220px">
          <?php foreach ( $email_types as $val => $meta ) : ?>
            <button type="submit" name="arrahma_send_test" value="<?= esc_attr( $val ) ?>" class="button" <?= ! empty( $problemen_per_type[ $val ] ) ? 'disabled title="Deze e-mail bevat fouten"' : '' ?>>Test: <?= esc_html( $meta['label'] ) ?></button>
          <?php endforeach; ?>
        </form>
      </div>

      <p style="color:#555;max-width:680px">
        Verstuurt één e-mail per geselecteerde ontvanger (gegroepeerd op e-mailadres, dus een gezin krijgt één e-mail voor al hun kinderen).
        De lijst bevat iedereen met een e-mailadres, dus ook jongeren en volwassenen die zichzelf hebben ingeschreven —
        per e-mailtype worden de ontvangers die er niet voor in aanmerking komen grijs en niet aanvinkbaar.
        Versturen gebeurt in blokken van <?= (int) ARRAHMA_BATCH_SIZE ?>, met een voortgangsbalk. Dat voorkomt dat een grote
        verzending vastloopt op de tijdslimiet van de server, en je ziet na elk blok waar je bent. Elke verzending wordt
        gelogd, dus als er iets afbreekt kun je met "Sla over wie deze e-mail al heeft gehad" gewoon de rest oppakken.
      </p>

      <?php if ( empty( $recipients ) ) : ?>
        <p style="color:#999">Geen inschrijvingen met een e-mailadres gevonden.</p>
      <?php else : ?>

        <form method="post" id="arrahma-email-form">
          <?php wp_nonce_field( 'arrahma_send_emails' ); ?>

          <h2 style="font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:#2d3a4a;margin:1.5rem 0 .5rem">Welke e-mail?</h2>
          <p style="margin:0 0 .5rem;font-size:.85rem"><a href="<?= esc_url( admin_url( 'admin.php?page=arrahma-emailteksten' ) ) ?>">Teksten bewerken of een nieuwe e-mail maken &rarr;</a></p>
          <fieldset style="margin-bottom:1.25rem">
            <?php
              // Type -> toegestane doelgroepen, zodat de JS dezelfde grens kan trekken als de server.
              $type_cats = [];
              foreach ( $email_types as $val => $meta ) { $type_cats[ $val ] = $meta['categorieen']; }
            ?>
            <?php $eerste = true; foreach ( $email_types as $val => $meta ) :
                $cats     = $meta['categorieen'];
                $cat_tekst = $cats === null
                    ? ''
                    : ' Alleen voor ' . implode( ', ', array_map(
                        function ( $c ) { return arrahma_category_labels()[ $c ] ?? $c; },
                        $cats
                      ) ) . '.';
            ?>
              <?php $kapot = ! empty( $problemen_per_type[ $val ] ); ?>
              <label style="display:block;margin-bottom:.4rem<?= $kapot ? ';opacity:.6' : '' ?>">
                <input type="radio" name="email_type" value="<?= esc_attr( $val ) ?>" data-label="<?= esc_attr( $meta['label'] ) ?>" <?= $kapot ? 'disabled' : checked( $eerste, true, false ) ?>>
                <strong><?= esc_html( $meta['label'] ) ?></strong>
                <span style="color:#888">— <?= esc_html( $meta['omschrijving'] ) ?><?= esc_html( $cat_tekst ) ?></span>
                <?php if ( $kapot ) : ?><span style="color:#d32f2f"> — bevat fouten, eerst oplossen onder <a href="<?= esc_url( admin_url( 'admin.php?page=arrahma-emailteksten&bewerk=' . rawurlencode( $val ) ) ) ?>">E-mailteksten</a>.</span><?php endif; ?>
              </label>
            <?php if ( ! $kapot ) $eerste = false; endforeach; ?>
          </fieldset>

          <h2 style="font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:#2d3a4a;margin:1.5rem 0 .5rem">Welke doelgroep?</h2>
          <p style="color:#888;font-size:.85rem;margin:0 0 .5rem;max-width:680px">
            Beperkt de e-mail tot één doelgroep. Staan er op één e-mailadres bijvoorbeeld twee kinderen én een
            volwassene, dan stuur je met "Kinderen" alleen de plaatsing van de twee kinderen — de volwassene
            staat er dan niet in en krijgt zijn eigen e-mail wanneer jij die verstuurt.
          </p>
          <select name="doelgroep" id="arrahma-doelgroep" style="min-width:280px;margin-bottom:1.25rem">
            <option value="">Alle doelgroepen — alles in één e-mail</option>
            <?php foreach ( arrahma_active_categories() as $cat ) : ?>
              <option value="<?= esc_attr( $cat ) ?>"><?= esc_html( arrahma_category_labels()[ $cat ] ?? $cat ) ?></option>
            <?php endforeach; ?>
          </select>

          <h2 style="font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:#2d3a4a;margin:1.5rem 0 .5rem">Bijlagen (optioneel)</h2>
          <p style="color:#888;font-size:.85rem;margin:0 0 .5rem;max-width:680px">
            Word, Excel, PowerPoint (.pptx) of PDF uit de mediabibliotheek, maximaal <?= (int) ARRAHMA_BIJLAGE_MAX ?> bestanden en samen <?= (int) ARRAHMA_BIJLAGE_MAX_MB ?> MB.
            Elke ontvanger krijgt dezelfde bijlagen; ze gaan ook mee met de testmail hierboven.
            Let op: bestanden in de mediabibliotheek zijn via hun link openbaar — zet er geen persoonsgegevens in.
          </p>
          <div id="arrahma-bijlagen" style="margin-bottom:1.25rem;max-width:680px">
            <ul id="arrahma-bijlagen-lijst" style="margin:0 0 .5rem;padding:0;list-style:none"></ul>
            <button type="button" class="button" id="arrahma-bijlage-kies"><span class="dashicons dashicons-paperclip" style="margin-top:3px"></span> Bijlage kiezen</button>
          </div>

          <label style="display:block;margin-bottom:1.25rem">
            <input type="checkbox" name="skip_sent" id="arrahma-skip-sent" value="1" checked>
            <strong>Sla over wie deze e-mail al heeft gehad</strong>
            <span style="color:#888">— aan laten staan om dubbele e-mails te voorkomen; uitzetten om bewust opnieuw te versturen.</span>
          </label>

          <h2 style="font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:#2d3a4a;margin:1.5rem 0 .5rem">Naar wie?</h2>
          <table class="wp-list-table widefat fixed striped" style="max-width:800px;border-radius:8px;overflow:hidden">
            <thead>
              <tr>
                <td class="check-column" style="width:2.5rem;padding:8px 0 8px 10px">
                  <input type="checkbox" id="arrahma-cb-all" title="Alles selecteren">
                </td>
                <th>E-mailadres</th>
                <th>Ingeschreven</th>
                <th style="width:230px">Doelgroep(en)</th>
                <th style="width:70px">Aantal</th>
                <th style="width:150px">Al gemaild</th>
              </tr>
            </thead>
            <tbody>
              <?php
                $cat_labels = arrahma_category_labels();
                foreach ( $recipients as $key => $r ) :
                  $cat_counts = arrahma_category_counts_for_recipient( $r );
                  $cats       = array_map(
                      function ( $cat ) use ( $cat_labels ) { return $cat_labels[ $cat ] ?? $cat; },
                      array_keys( $cat_counts )
                  );
              ?>
                <tr data-key="<?= esc_attr( $key ) ?>" data-counts="<?= esc_attr( wp_json_encode( $cat_counts ) ) ?>">
                  <th scope="row" class="check-column" style="padding:8px 0 8px 10px">
                    <input type="checkbox" name="recipients[]" value="<?= esc_attr( $key ) ?>">
                  </th>
                  <td><?= esc_html( $r['email'] ) ?></td>
                  <td><?= esc_html( implode( ', ', $r['names'] ) ) ?></td>
                  <td style="color:#666;font-size:.85em"><?= esc_html( implode( ', ', $cats ) ) ?></td>
                  <td class="arrahma-aantal"><?= count( $r['names'] ) ?></td>
                  <td class="arrahma-gemaild" style="color:#888;font-size:.85em">—</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <p class="submit">
            <button type="submit" name="arrahma_send_emails" value="1" class="button button-primary" id="arrahma-send-btn">
              Verstuur naar geselecteerde ontvangers
            </button>
            <span id="arrahma-selected-count" style="margin-left:.75rem;color:#888;font-size:.85rem">0 geselecteerd</span>
          </p>

          <!-- Voortgang tijdens het batchgewijs versturen; JS toont dit zodra er verzonden wordt. -->
          <div id="arrahma-progress" style="display:none;max-width:800px;margin:0 0 1.5rem">
            <div style="background:#e6e9ec;border-radius:50px;height:10px;overflow:hidden">
              <div id="arrahma-progress-bar" style="background:#2d3a4a;height:100%;width:0;transition:width .25s"></div>
            </div>
            <p id="arrahma-progress-text" style="margin:.5rem 0 0;color:#555;font-size:.9rem"></p>
            <ul id="arrahma-progress-fouten" style="margin:.25rem 0 0 1.25rem;color:#d32f2f;font-size:.85rem;list-style:disc"></ul>
          </div>
        </form>

        <script>
        (function () {
          var form = document.getElementById('arrahma-email-form');
          if (!form) return;

          var all       = document.getElementById('arrahma-cb-all');
          var counter   = document.getElementById('arrahma-selected-count');
          var doelgroep = document.getElementById('arrahma-doelgroep');

          // Per e-mailtype de toegestane doelgroepen; null = alle. Zelfde bron als de server.
          var TYPE_CATS  = <?php echo wp_json_encode( $type_cats ); ?>;
          // Per e-mailtype: welk adres kreeg 'm wanneer voor het laatst met succes.
          var SENT_LOG   = <?php echo wp_json_encode( $sent_logs ); ?>;
          var BATCH_SIZE = <?php echo (int) ARRAHMA_BATCH_SIZE; ?>;
          var AJAX_URL   = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
          var NONCE      = <?php echo wp_json_encode( wp_create_nonce( 'arrahma_send_emails' ) ); ?>;

          // ── Bijlagen: kiezen uit de mediabibliotheek (alleen Word, Excel, PowerPoint, PDF)
          var BIJLAGE_MAX = <?php echo (int) ARRAHMA_BIJLAGE_MAX; ?>;
          var BIJLAGE_MIME = [
            'application/pdf', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation'
          ];
          var bijlagen = [];   // [{ id, naam, grootte }]
          var kader;

          function toonBijlagen() {
            var lijst = document.getElementById('arrahma-bijlagen-lijst');
            lijst.innerHTML = '';
            bijlagen.forEach(function (b, i) {
              var li = document.createElement('li');
              li.style.cssText = 'display:flex;align-items:center;gap:.5rem;padding:.35rem .6rem;margin-bottom:.35rem;background:#f4f6f8;border-radius:6px';
              li.innerHTML = '<span class="dashicons dashicons-media-document"></span><span style="flex:1"></span><span style="color:#888;font-size:.85em"></span>' +
                             '<button type="button" class="button-link" style="color:#d32f2f">Verwijderen</button>';
              li.children[1].textContent = b.naam;
              li.children[2].textContent = b.grootte || '';
              li.querySelector('button').addEventListener('click', function () { bijlagen.splice(i, 1); toonBijlagen(); });
              lijst.appendChild(li);
            });
            // Verborgen velden in beide formulieren (verzenden en testmail), zodat ook het pad zonder JS ze meestuurt.
            [form.querySelector('#arrahma-bijlagen'), document.getElementById('arrahma-test-bijlagen')].forEach(function (houder) {
              if (!houder) return;
              houder.querySelectorAll('input[name="bijlagen[]"]').forEach(function (el) { el.remove(); });
              bijlagen.forEach(function (b) {
                var inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = 'bijlagen[]'; inp.value = b.id;
                houder.appendChild(inp);
              });
            });
            document.getElementById('arrahma-bijlage-kies').disabled = bijlagen.length >= BIJLAGE_MAX;
          }

          document.getElementById('arrahma-bijlage-kies').addEventListener('click', function () {
            if (!window.wp || !wp.media) { alert('De mediabibliotheek kon niet worden geladen. Herlaad de pagina.'); return; }
            if (!kader) {
              kader = wp.media({
                title: 'Bijlage kiezen (Word, Excel, PowerPoint of PDF)',
                button: { text: 'Toevoegen als bijlage' },
                multiple: 'add',
                library: { type: BIJLAGE_MIME }
              });
              kader.on('select', function () {
                kader.state().get('selection').each(function (m) {
                  var a = m.toJSON();
                  if (BIJLAGE_MIME.indexOf(a.mime) === -1) { alert(a.filename + ' is geen Word-, Excel-, PowerPoint- of PDF-bestand.'); return; }
                  if (bijlagen.some(function (b) { return b.id === a.id; })) return;
                  if (bijlagen.length >= BIJLAGE_MAX) { alert('Maximaal ' + BIJLAGE_MAX + ' bijlagen.'); return; }
                  bijlagen.push({ id: a.id, naam: a.filename, grootte: a.filesizeHumanReadable });
                });
                toonBijlagen();
              });
            }
            kader.open();
          });

          function boxes() { return form.querySelectorAll('input[name="recipients[]"]'); }
          function checkedBoxes() { return form.querySelectorAll('input[name="recipients[]"]:checked'); }
          function enabledBoxes() { return form.querySelectorAll('input[name="recipients[]"]:not(:disabled)'); }
          function huidigType() {
            var t = form.querySelector('input[name="email_type"]:checked');
            return t ? t.value : '';
          }

          /** De doelgroepen die overblijven na het e-mailtype én de handmatige doelgroepkeuze. */
          function actieveCategorieen() {
            var vanType = TYPE_CATS[huidigType()] || null;   // null = alle
            var gekozen = doelgroep && doelgroep.value ? doelgroep.value : '';
            if (!gekozen) return vanType;                     // null of de lijst van het type
            if (!vanType) return [gekozen];
            return vanType.indexOf(gekozen) !== -1 ? [gekozen] : [];
          }

          /** Hoeveel inschrijvingen van deze ontvanger vallen binnen de actieve doelgroepen? */
          function aantalVoorRij(rij, cats) {
            var counts = {};
            try { counts = JSON.parse(rij.dataset.counts || '{}'); } catch (e) { counts = {}; }
            return Object.keys(counts).reduce(function (som, cat) {
              return som + ((cats === null || cats.indexOf(cat) !== -1) ? counts[cat] : 0);
            }, 0);
          }

          // Ontvangers zonder inschrijving in de gekozen doelgroep gaan op slot: wel zichtbaar
          // (je wilt de hele lijst kunnen overzien), maar grijs, uitgevinkt en niet aan te vinken.
          // Het aantal toont wat er daadwerkelijk in de e-mail komt, niet het totaal op dat adres.
          function pasTypeToe() {
            var cats = actieveCategorieen();
            boxes().forEach(function (cb) {
              var rij    = cb.closest('tr');
              var aantal = aantalVoorRij(rij, cats);
              var kan    = aantal > 0;

              cb.disabled = !kan;
              if (!kan) cb.checked = false;
              rij.style.opacity = kan ? '' : '.45';
              rij.title = kan ? '' : 'Geen inschrijving in de gekozen doelgroep.';

              var cel = rij.querySelector('.arrahma-aantal');
              if (cel) cel.textContent = aantal;

              // "Al gemaild" hoort bij het gekozen e-mailtype, dus die kolom volgt de keuze.
              var gemaild = (SENT_LOG[huidigType()] || {})[rij.dataset.key];
              var gcel    = rij.querySelector('.arrahma-gemaild');
              if (gcel) {
                gcel.textContent = gemaild ? gemaild.replace(' ', ' · ').slice(0, 16) : '—';
                gcel.style.color = gemaild ? '#a06800' : '#888';
              }
            });
            updateCount();
          }

          function updateCount() {
            var n = checkedBoxes().length;
            var beschikbaar = enabledBoxes().length;
            counter.textContent = n + ' van ' + beschikbaar + ' geselecteerd';
            all.checked = (n > 0 && n === beschikbaar);
            all.indeterminate = (n > 0 && n < beschikbaar);
          }

          all.addEventListener('change', function () {
            var state = this.checked;
            enabledBoxes().forEach(function (cb) { cb.checked = state; });
            updateCount();
          });

          boxes().forEach(function (cb) { cb.addEventListener('change', updateCount); });
          form.querySelectorAll('input[name="email_type"]').forEach(function (r) {
            r.addEventListener('change', pasTypeToe);
          });
          if (doelgroep) doelgroep.addEventListener('change', pasTypeToe);

          // ── Batchgewijs versturen ──
          // Eén verzoek per BATCH_SIZE ontvangers in plaats van alles in één keer: zo loopt een
          // verzending van tientallen e-mails nooit tegen max_execution_time aan, en zie je na
          // elke batch waar je bent. Breekt er iets af, dan staat in het verzendlog wie al
          // gemaild is — met "Sla over wie deze e-mail al heeft gehad" pak je de rest gewoon op.
          var bezig = false;

          function toonVoortgang(gedaan, totaal, mislukt) {
            var vak = document.getElementById('arrahma-progress');
            vak.style.display = 'block';
            document.getElementById('arrahma-progress-bar').style.width =
              (totaal ? Math.round((gedaan / totaal) * 100) : 0) + '%';
            document.getElementById('arrahma-progress-text').textContent =
              gedaan + ' van ' + totaal + ' verwerkt' + (mislukt.length ? ' — ' + mislukt.length + ' mislukt' : '');
            var lijst = document.getElementById('arrahma-progress-fouten');
            lijst.innerHTML = mislukt.map(function (m) {
              var li = document.createElement('li');
              li.textContent = m;
              return li.outerHTML;
            }).join('');
          }

          function verstuurBatch(keys, type, dg, skip) {
            var body = new URLSearchParams();
            body.append('action', 'arrahma_send_batch');
            body.append('_ajax_nonce', NONCE);
            body.append('email_type', type);
            body.append('doelgroep', dg);
            if (skip) body.append('skip_sent', '1');
            keys.forEach(function (k) { body.append('recipients[]', k); });
            bijlagen.forEach(function (b) { body.append('bijlagen[]', b.id); });

            return fetch(AJAX_URL, { method: 'POST', credentials: 'same-origin', body: body })
              .then(function (res) { return res.json(); })
              .then(function (json) {
                if (!json || !json.success) throw new Error((json && json.data && json.data.message) || 'Onbekende fout');
                return json.data;
              });
          }

          form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (bezig) return;

            var boxenAan = Array.prototype.slice.call(checkedBoxes());
            if (!boxenAan.length) { alert('Selecteer minimaal één ontvanger.'); return; }

            var type  = form.querySelector('input[name="email_type"]:checked');
            var label = type ? type.dataset.label : 'e-mail';
            var dg    = doelgroep && doelgroep.value ? doelgroep.value : '';
            var dgTxt = dg ? ' (alleen ' + doelgroep.options[doelgroep.selectedIndex].text + ')' : '';
            var skip  = document.getElementById('arrahma-skip-sent');
            var skipJa = skip ? skip.checked : false;

            var bijlTxt = bijlagen.length ? ' met ' + bijlagen.length + ' bijlage(n) (' + bijlagen.map(function (b) { return b.naam; }).join(', ') + ')' : ' zonder bijlagen';
            if (!confirm('Verstuur "' + label + '"' + dgTxt + bijlTxt + ' naar ' + boxenAan.length +
                         ' ontvanger(s)? Dit gebeurt in blokken van ' + BATCH_SIZE + '.')) return;

            var keys    = boxenAan.map(function (cb) { return cb.value; });
            var totaal  = keys.length;
            var gedaan  = 0;
            var mislukt = [];
            var knop    = document.getElementById('arrahma-send-btn');

            bezig = true;
            knop.disabled = true;
            knop.textContent = 'Bezig met versturen…';
            toonVoortgang(0, totaal, mislukt);

            (function volgende() {
              if (!keys.length) {
                bezig = false;
                knop.disabled = false;
                knop.textContent = 'Verstuur naar geselecteerde ontvangers';
                document.getElementById('arrahma-progress-text').textContent =
                  'Klaar: ' + (totaal - mislukt.length) + ' van ' + totaal + ' verwerkt' +
                  (mislukt.length ? ' — ' + mislukt.length + ' mislukt (zie hieronder)' : '') +
                  '. Herlaad de pagina om het verzendlog bij te werken.';
                return;
              }

              var batch = keys.splice(0, BATCH_SIZE);
              verstuurBatch(batch, type ? type.value : '', dg, skipJa)
                .then(function (data) {
                  gedaan += batch.length;
                  if (data.mislukt && data.mislukt.length) mislukt = mislukt.concat(data.mislukt);
                  toonVoortgang(gedaan, totaal, mislukt);
                  volgende();
                })
                .catch(function (err) {
                  // Stoppen, niet doorrazen: bij een serverfout weet je anders niet meer waar je bent.
                  bezig = false;
                  knop.disabled = false;
                  knop.textContent = 'Verstuur naar geselecteerde ontvangers';
                  document.getElementById('arrahma-progress-text').textContent =
                    'Gestopt na ' + gedaan + ' van ' + totaal + ' — ' + err.message +
                    '. Wat al verstuurd is staat in het verzendlog; herlaad en verstuur de rest met "Sla over wie deze e-mail al heeft gehad" aan.';
                });
            })();
          });

          pasTypeToe();
        })();
        </script>

      <?php endif; ?>
    </div>
    <?php
}

// ─────────────────────────────────────────────────────────────
// ADMIN PAGINA: INSTELLINGEN (inschrijvingen open/gesloten)
// ─────────────────────────────────────────────────────────────
function arrahma_settings_page() {
    $notice = '';

    if ( isset( $_POST['arrahma_save_settings'], $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'arrahma_save_settings' ) ) {
        $mode = sanitize_text_field( wp_unslash( $_POST['form_mode'] ?? '' ) );
        if ( in_array( $mode, [ 'open', 'gesloten' ], true ) ) {
            update_option( ARRAHMA_FORM_MODE_OPTION, $mode );
        }
        update_option(
            ARRAHMA_CLOSED_MESSAGE_OPTION,
            sanitize_textarea_field( wp_unslash( $_POST['closed_message'] ?? '' ) )
        );

        // ── Status per lesblok/lesgroep; alleen bekende sleutels en statussen worden bewaard.
        $ingestuurd = (array) ( $_POST['slot_status'] ?? [] );
        $nieuw      = [];
        foreach ( array_keys( arrahma_roster_labels() ) as $key ) {
            $standaard = arrahma_default_slot_status( $key );
            $waarde    = sanitize_text_field( wp_unslash( $ingestuurd[ $key ] ?? $standaard ) );
            $nieuw[ $key ] = isset( arrahma_slot_status_labels()[ $waarde ] ) ? $waarde : $standaard;
        }
        update_option( ARRAHMA_SLOT_STATUS_OPTION, $nieuw );

        // ── Capaciteit per lesblok/lesgroep; leeg of 0 laat de standaardwaarde gelden.
        $caps_in  = (array) ( $_POST['slot_cap'] ?? [] );
        $caps_new = [];
        foreach ( array_keys( arrahma_roster_labels() ) as $key ) {
            $waarde = (int) ( $caps_in[ $key ] ?? 0 );
            if ( $waarde > 0 ) {
                $caps_new[ $key ] = arrahma_clamp_cap( $waarde );
            }
        }
        update_option( ARRAHMA_SLOT_CAP_OPTION, $caps_new );

        $notice = 'Instellingen opgeslagen.';
    }

    $is_open       = arrahma_form_is_open();
    $message       = arrahma_closed_message();
    $slot_statuses = arrahma_slot_statuses();
    $slot_caps     = arrahma_slot_caps();
    ?>
    <div class="wrap">
      <h1 style="display:flex;align-items:center;gap:.5rem">
        <span class="dashicons dashicons-admin-settings" style="font-size:1.5rem;margin-top:3px"></span>
        Instellingen
      </h1>

      <?php if ( $notice ) : ?>
        <div class="notice notice-success is-dismissible"><p><?= esc_html( $notice ) ?></p></div>
      <?php endif; ?>

      <p style="color:#555;max-width:680px">
        Bepaalt of bezoekers zich kunnen inschrijven. De wijziging is direct actief — het formulier
        haalt deze instelling op zodra de pagina wordt geladen.
      </p>

      <div style="background:<?= $is_open ? '#eef7ee' : '#fff4e0' ?>;border:1px solid <?= $is_open ? '#c6e2c6' : '#f0d9a8' ?>;border-radius:10px;padding:.85rem 1.15rem;max-width:680px;margin:1rem 0;font-size:.9rem;color:#444">
        Huidige status:
        <strong style="color:<?= $is_open ? '#2e7d32' : '#a06800' ?>">
          <?= $is_open ? 'Inschrijvingen staan open' : 'Inschrijvingen zijn gesloten' ?>
        </strong>
      </div>

      <form method="post">
        <?php wp_nonce_field( 'arrahma_save_settings' ); ?>

        <div style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:1.25rem 1.5rem;max-width:680px;margin-bottom:1.25rem">
          <h2 style="font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:#2d3a4a;margin:0 0 1rem">Inschrijfformulier</h2>

          <label style="display:block;margin-bottom:.85rem">
            <input type="radio" name="form_mode" value="open" <?= checked( $is_open, true, false ) ?>>
            <strong>Open</strong>
            <span style="color:#888">— bezoekers zien het normale inschrijfformulier.</span>
          </label>

          <label style="display:block">
            <input type="radio" name="form_mode" value="gesloten" <?= checked( $is_open, false, false ) ?>>
            <strong>Gesloten</strong>
            <span style="color:#888">— het formulier wordt verborgen en bezoekers zien onderstaand bericht.</span>
          </label>
        </div>

        <div style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:1.25rem 1.5rem;max-width:680px">
          <h2 style="font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:#2d3a4a;margin:0 0 1rem">Bericht bij gesloten inschrijvingen</h2>
          <textarea name="closed_message" rows="4" class="large-text"><?= esc_textarea( $message ) ?></textarea>
          <p class="description">Laat leeg om de standaardtekst te gebruiken.</p>
        </div>

        <div style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:1.25rem 1.5rem;max-width:860px;margin-top:1.25rem">
          <h2 style="font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:#2d3a4a;margin:0 0 .5rem">Lesblokken &amp; lesgroepen</h2>
          <p style="color:#888;font-size:.85rem;margin:0 0 1rem">
            Per groep instelbaar. <strong>Gesloten</strong> laat de groep wél zien (met dag en tijd) maar maakt hem niet kiesbaar —
            handig als één groep gepauzeerd is terwijl de rest gewoon open blijft. <strong>Verborgen</strong> haalt de groep helemaal van het formulier.
            <strong>Alleen via link</strong> verbergt de groep óók, behalve voor bezoekers die de toegangslink hebben —
            handig voor een groep waar je eerst voor toegelaten moet worden. Zodra je die status kiest en opslaat,
            verschijnt hieronder het stukje dat je achter de URL van de inschrijfpagina plakt.
            Let op: dat is géén beveiliging, iedereen mét de link kan inschrijven.
            De site-brede schakelaar hierboven gaat vóór deze instellingen.
            <br>
            <strong>Max.</strong> is het aantal plaatsen; is dat bereikt, dan krijgt de groep automatisch het label “Vol”
            en kan er niet meer op ingeschreven worden. Leeg of 0 valt terug op de standaard
            (<?= (int) ARRAHMA_ROOSTER_CAP ?> voor kinderen-lesblokken, <?= (int) ARRAHMA_GROEP_CAP ?> voor lesgroepen).
          </p>

          <table class="wp-list-table widefat striped" style="border-radius:8px;overflow:hidden">
            <thead>
              <tr>
                <th>Lesblok / lesgroep</th>
                <th style="width:110px">Ingeschreven</th>
                <th style="width:90px">Max.</th>
                <th style="width:260px">Status</th>
              </tr>
            </thead>
            <tbody>
            <?php
              $groepen  = arrahma_groepen();
              $vorig_ct = null;
              foreach ( arrahma_roster_labels() as $key => $label ) :
                  $is_groep = isset( $groepen[ $key ] );
                  $kop      = $is_groep ? ( arrahma_category_labels()[ $groepen[ $key ]['categorie'] ] ?? '' ) : 'Kinderen — lesblokken';
                  $bezet    = arrahma_rooster_count( $key );
                  $cap      = $slot_caps[ $key ] ?? arrahma_default_cap_for( $key );
                  $vol      = $bezet >= $cap;
                  if ( $kop !== $vorig_ct ) :
                      $vorig_ct = $kop; ?>
                      <tr><th colspan="4" style="background:#f4f6f8;font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;color:#2d3a4a"><?= esc_html( $kop ) ?></th></tr>
                  <?php endif; ?>
                  <tr>
                    <td><?= esc_html( $label ) ?></td>
                    <td>
                      <?= (int) $bezet ?>
                      <?php if ( $vol ) : ?><strong style="color:#a06800">— vol</strong><?php endif; ?>
                    </td>
                    <td>
                      <input type="number" name="slot_cap[<?= esc_attr( $key ) ?>]"
                             value="<?= (int) $cap ?>"
                             min="<?= (int) ARRAHMA_CAP_MIN ?>" max="<?= (int) ARRAHMA_CAP_MAX ?>" step="1"
                             style="width:100%">
                    </td>
                    <td>
                      <select name="slot_status[<?= esc_attr( $key ) ?>]" style="width:100%">
                        <?php foreach ( arrahma_slot_status_labels() as $val => $slabel ) : ?>
                          <option value="<?= esc_attr( $val ) ?>" <?= selected( $slot_statuses[ $key ] ?? arrahma_default_slot_status( $key ), $val, false ) ?>><?= esc_html( $slabel ) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <?php if ( ( $slot_statuses[ $key ] ?? '' ) === 'op_uitnodiging' ) : ?>
                        <p style="margin:.5rem 0 0;font-size:.8rem;color:#666;line-height:1.5">
                          Plak achter de URL van de inschrijfpagina:<br>
                          <code style="user-select:all;background:#f4f6f8;padding:2px 6px;border-radius:4px">?<?= esc_html( ARRAHMA_TOEGANG_PARAM ) ?>=<?= esc_html( $key ) ?></code>
                        </p>
                      <?php endif; ?>
                    </td>
                  </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <p class="submit">
          <button type="submit" name="arrahma_save_settings" value="1" class="button button-primary">Instellingen opslaan</button>
        </p>
      </form>
    </div>
    <?php
}

// ─────────────────────────────────────────────────────────────
// ADMIN PAGINA: OVERZICHT (dashboard)
// ─────────────────────────────────────────────────────────────
function arrahma_dashboard_page() {
    global $wpdb;
    $table = $wpdb->prefix . ARRAHMA_TABLE;
    $rows  = $wpdb->get_results( "SELECT inschrijving_voor AS categorie, niveau, rooster FROM {$table}" );

    $dataset = array_map( function ( $r ) {
        return [ 'categorie' => $r->categorie, 'niveau' => $r->niveau, 'rooster' => $r->rooster ];
    }, $rows );

    $categorie_labels = arrahma_category_labels();
    $niveau_labels     = arrahma_niveau_labels();
    $rooster_labels    = arrahma_roster_labels();

    // Bij welke doelgroep hoort elk lesblok/lesgroep? Alles wat geen lesgroep is, is een kinderen-lesblok.
    $rooster_categorie = [];
    foreach ( array_keys( $rooster_labels ) as $rk ) {
        $rooster_categorie[ $rk ] = arrahma_groepen()[ $rk ]['categorie'] ?? 'kinderen';
    }
    ?>
    <div class="wrap">
      <h1 style="display:flex;align-items:center;gap:.5rem">
        <span class="dashicons dashicons-chart-bar" style="font-size:1.5rem;margin-top:3px"></span>
        Overzicht
      </h1>
      <p style="color:#888;font-size:.85rem;margin:0 0 1.5rem">Klik op een balk om te filteren en te combineren. Klik nogmaals om uit te zetten.</p>

      <style>
        .arrahma-ov-stat-row { display:flex; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap; }
        .arrahma-ov-stat-tile { background:#fff; border:1px solid #e0e0e0; border-radius:10px; padding:1rem 1.5rem; min-width:110px; text-align:center; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .arrahma-ov-stat-tile .n { font-size:2rem; font-weight:700; color:#2d3a4a; line-height:1; }
        .arrahma-ov-stat-tile .l { font-size:.75rem; color:#888; margin-top:.25rem; }

        .arrahma-ov-chips { display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; margin-bottom:1.25rem; min-height:28px; }
        .arrahma-ov-chips .hint { font-size:.78rem; color:#aaa; }
        .arrahma-ov-chip { display:inline-flex; align-items:center; gap:.4rem; background:#2d3a4a; color:#fff; border-radius:50px; padding:.3rem .5rem .3rem .85rem; font-size:.78rem; font-weight:600; }
        .arrahma-ov-chip button { background:rgba(255,255,255,.2); border:none; color:#fff; width:18px; height:18px; border-radius:50%; cursor:pointer; font-size:.7rem; line-height:1; display:flex; align-items:center; justify-content:center; }
        .arrahma-ov-chip button:hover { background:rgba(255,255,255,.35); }
        .arrahma-ov-clear { font-size:.78rem; color:#d32f2f; cursor:pointer; text-decoration:underline; background:none; border:none; }

        .arrahma-ov-section { background:#fff; border:1px solid #e0e0e0; border-radius:10px; padding:1.25rem 1.5rem; margin-bottom:1.25rem; box-shadow:0 1px 4px rgba(0,0,0,.06); max-width:800px; }
        .arrahma-ov-section h2 { font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#2d3a4a; margin:0 0 1rem; }
        .arrahma-ov-bar-row { display:flex; align-items:center; gap:.75rem; padding:.4rem 0; cursor:pointer; border-radius:8px; transition:background .15s; }
        .arrahma-ov-bar-row:hover { background:#f7f8f9; }
        .arrahma-ov-bar-row.active { background:#f4f6f8; }
        .arrahma-ov-bar-label { width:220px; flex-shrink:0; font-size:.84rem; color:#333; display:flex; align-items:center; gap:.4rem; }
        .arrahma-ov-bar-row.active .arrahma-ov-bar-label { font-weight:700; color:#2d3a4a; }
        .arrahma-ov-bar-track { flex:1; background:#eef0f2; border-radius:6px; height:20px; overflow:hidden; }
        .arrahma-ov-bar-fill { background:#6b8299; height:100%; border-radius:6px; transition:width .3s; }
        .arrahma-ov-bar-row.active .arrahma-ov-bar-fill { background:#2d3a4a; }
        .arrahma-ov-bar-fill.full { background:#d32f2f; }
        .arrahma-ov-bar-count { width:90px; flex-shrink:0; text-align:right; font-size:.82rem; color:#555; font-variant-numeric:tabular-nums; }
        .arrahma-ov-vol-badge { background:#fdeaea; color:#d32f2f; font-size:.68rem; font-weight:700; padding:1px 7px; border-radius:50px; text-transform:uppercase; letter-spacing:.03em; }
        .arrahma-ov-rooster-hint { font-size:.8rem; color:#aaa; font-style:italic; }
      </style>

      <div class="arrahma-ov-stat-row">
        <div class="arrahma-ov-stat-tile"><div class="n" id="arrahma-ov-total">–</div><div class="l">Totaal (gefilterd)</div></div>
      </div>

      <div class="arrahma-ov-chips" id="arrahma-ov-chips">
        <span class="hint">Geen filters actief — klik op een balk hieronder</span>
      </div>

      <div class="arrahma-ov-section">
        <h2>Categorie</h2>
        <div id="arrahma-ov-bars-categorie"></div>
      </div>

      <div class="arrahma-ov-section">
        <h2>Niveau</h2>
        <div id="arrahma-ov-bars-niveau"></div>
      </div>

      <div class="arrahma-ov-section" id="arrahma-ov-section-rooster" style="display:none">
        <h2>Lesdagen &amp; lesgroepen — bezetting t.o.v. de ingestelde capaciteit</h2>
        <div id="arrahma-ov-bars-rooster"></div>
      </div>
      <p class="arrahma-ov-rooster-hint" id="arrahma-ov-rooster-hint">Klik op een doelgroep bij Categorie om de bezetting per lesmoment te zien.</p>
    </div>

    <script>
    (function () {
      const ROWS             = <?php echo wp_json_encode( $dataset ); ?>;
      const CATEGORIE_LABELS = <?php echo wp_json_encode( $categorie_labels ); ?>;
      const CATEGORIEEN      = <?php echo wp_json_encode( array_keys( $categorie_labels ) ); ?>;
      const NIVEAU_LABELS    = <?php echo wp_json_encode( $niveau_labels ); ?>;
      const NIVEAUS          = <?php echo wp_json_encode( array_keys( $niveau_labels ) ); ?>;
      const ROOSTER_LABELS   = <?php echo wp_json_encode( $rooster_labels ); ?>;
      const ROOSTERS         = <?php echo wp_json_encode( array_keys( $rooster_labels ) ); ?>;
      const ROOSTER_CAT      = <?php echo wp_json_encode( $rooster_categorie ); ?>;
      const CAPS             = <?php echo wp_json_encode( arrahma_slot_caps() ); ?>;

      const activeFilters = {};

      function matchesFilters(row, excludeDim) {
        return Object.entries(activeFilters).every(([dim, val]) => dim === excludeDim || row[dim] === val);
      }

      function toggleFilter(dim, val) {
        if (activeFilters[dim] === val) delete activeFilters[dim];
        else activeFilters[dim] = val;
        render();
      }
      window.arrahmaOvToggleFilter = toggleFilter;

      function clearAll() {
        for (const k in activeFilters) delete activeFilters[k];
        render();
      }
      window.arrahmaOvClearAll = clearAll;

      function renderChips() {
        const el = document.getElementById('arrahma-ov-chips');
        const entries = Object.entries(activeFilters);
        if (entries.length === 0) {
          el.innerHTML = '<span class="hint">Geen filters actief — klik op een balk hieronder</span>';
          return;
        }
        const labels = { categorie: CATEGORIE_LABELS, niveau: NIVEAU_LABELS, rooster: ROOSTER_LABELS };
        el.innerHTML = entries.map(([dim, val]) =>
          '<span class="arrahma-ov-chip">' + labels[dim][val] + ' <button onclick="arrahmaOvToggleFilter(\'' + dim + '\',\'' + val + '\')">✕</button></span>'
        ).join('') + '<button class="arrahma-ov-clear" onclick="arrahmaOvClearAll()">Wis alles</button>';
      }

      function renderSection(dim, values, labels, containerId, capped) {
        const container = document.getElementById(containerId);
        const counted = values.map(v => ({
          value: v,
          count: ROWS.filter(r => r[dim] === v && matchesFilters(r, dim)).length,
        }));
        const max = Math.max(...counted.map(c => c.count), 1);
        container.innerHTML = counted.map(({ value, count }) => {
          // Bij 'capped' is de noemer de capaciteit van dít lesmoment, anders de hoogste balk.
          const cap = capped ? (CAPS[value] || max) : max;
          const pct = Math.round((count / cap) * 100);
          const isActive = activeFilters[dim] === value;
          const isFull = capped && count >= cap;
          return '<div class="arrahma-ov-bar-row ' + (isActive ? 'active' : '') + '" onclick="arrahmaOvToggleFilter(\'' + dim + '\',\'' + value + '\')">'
            + '<div class="arrahma-ov-bar-label">' + labels[value] + (isFull ? ' <span class="arrahma-ov-vol-badge">Vol</span>' : '') + '</div>'
            + '<div class="arrahma-ov-bar-track"><div class="arrahma-ov-bar-fill ' + (isFull ? 'full' : '') + '" style="width:' + Math.min(pct, 100) + '%"></div></div>'
            + '<div class="arrahma-ov-bar-count">' + count + (capped ? ' / ' + cap : '') + '</div>'
            + '</div>';
        }).join('');
      }

      function render() {
        renderChips();
        renderSection('categorie', CATEGORIEEN, CATEGORIE_LABELS, 'arrahma-ov-bars-categorie', false);
        renderSection('niveau', NIVEAUS, NIVEAU_LABELS, 'arrahma-ov-bars-niveau', false);

        // Alleen de lesmomenten van de gekozen doelgroep tonen — anders staan alle andere
        // groepen op 0 in de lijst.
        const cat        = activeFilters.categorie;
        const catRoosters = cat ? ROOSTERS.filter(k => ROOSTER_CAT[k] === cat) : [];
        const toonRooster = catRoosters.length > 0;

        document.getElementById('arrahma-ov-section-rooster').style.display = toonRooster ? 'block' : 'none';
        document.getElementById('arrahma-ov-rooster-hint').style.display    = toonRooster ? 'none' : 'block';
        if (toonRooster) renderSection('rooster', catRoosters, ROOSTER_LABELS, 'arrahma-ov-bars-rooster', true);

        document.getElementById('arrahma-ov-total').textContent = ROWS.filter(r => matchesFilters(r, null)).length;
      }

      render();
    })();
    </script>
    <?php
}

// ─────────────────────────────────────────────────────────────
// CSV EXPORT
// ─────────────────────────────────────────────────────────────
function arrahma_export_csv() {
    global $wpdb;
    $table   = $wpdb->prefix . ARRAHMA_TABLE;
    $entries = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY datum_inschrijving DESC", ARRAY_A );
    $labels  = arrahma_category_labels();

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="inschrijvingen-' . date( 'Y-m-d' ) . '.csv"' );
    header( 'Pragma: no-cache' );

    $out = fopen( 'php://output', 'w' );
    fprintf( $out, chr(0xEF) . chr(0xBB) . chr(0xBF) ); // UTF-8 BOM for Excel

    fputcsv( $out, [
        'ID', 'Categorie',
        'Voornaam', 'Achternaam', 'Geboortedatum',
        'Telefoon', 'E-mailadres',
        'Adres', 'Postcode', 'Woonplaats',
        'Niveau', 'Lesdagen (voorkeur)', 'Rekeningnummer', 'Naam rekeninghouder', 'Betaalwijze',
        'Contactpersoon anders', 'Voornaam CP', 'Achternaam CP', 'Telefoon CP',
        'Groep-ID', 'Status', 'Datum inschrijving',
    ], ';' );

    foreach ( $entries as $e ) {
        fputcsv( $out, [
            $e['id'],
            $labels[ $e['inschrijving_voor'] ] ?? $e['inschrijving_voor'],
            $e['voornaam'],
            $e['achternaam'],
            $e['geboortedatum'] ?? '',
            $e['telefoon'],
            $e['email'],
            $e['adres'],
            $e['postcode'],
            $e['woonplaats'],
            arrahma_niveau_label( $e['niveau'] ),
            arrahma_roster_label( $e['rooster'] ?? '' ),
            $e['rekeningnummer'],
            $e['naam_rekeninghouder'],
            arrahma_betaalwijze_label( $e['betaalwijze'] ?? '' ),
            $e['cp_anders'] ? 'Ja' : 'Nee',
            $e['cp_voornaam']   ?? '',
            $e['cp_achternaam'] ?? '',
            $e['cp_telefoon']   ?? '',
            $e['groep_id']      ?? '',
            $e['status'],
            $e['datum_inschrijving'],
        ], ';' );
    }

    fclose( $out );
}

// ─────────────────────────────────────────────────────────────
// DOCENTENPORTAAL — aanwezigheid, notities, klassen en lesrooster
// Spec: docs/specs/docenten-aanwezigheid.md
//
// Docenten werken op een gewone websitepagina met de shortcode [arrahma_docenten], nooit in
// wp-admin. Beheerders (manage_options) zijn het bestuur en zien alles. De echte afscherming zit in
// arrahma_mag_eenheid() en arrahma_mag_leerling(); de redirects weg van wp-admin zijn alleen gemak.
// ─────────────────────────────────────────────────────────────
define( 'ARRAHMA_AANWEZIG_TABLE',      'arrahma_aanwezigheid' );
define( 'ARRAHMA_NOTITIE_TABLE',       'arrahma_notities' );
define( 'ARRAHMA_DOCENT_ROLE',         'arrahma_docent' );
define( 'ARRAHMA_DOCENT_CAP',          'arrahma_aanwezigheid' );
define( 'ARRAHMA_KLAS_CAP',            'arrahma_klassen_beheren' ); // coördinator: klassen van eigen blokken beheren
define( 'ARRAHMA_DOCENT_META',         'arrahma_docent_eenheden' );
define( 'ARRAHMA_KLASSEN_OPTION',      'arrahma_klassen' );
define( 'ARRAHMA_VAKANTIES_OPTION',    'arrahma_vakanties' );
define( 'ARRAHMA_LES_UITZ_OPTION',     'arrahma_les_uitzonderingen' );
define( 'ARRAHMA_DOCENTEN_URL_OPTION', 'arrahma_docenten_url' );
define( 'ARRAHMA_SIGNAAL_DREMPEL',     3 );    // keer "afwezig" (zonder bericht) …
define( 'ARRAHMA_SIGNAAL_WEKEN',       8 );    // … binnen zoveel weken → op de signaallijst
define( 'ARRAHMA_ZOEK_DAGEN',          70 );   // zo ver zoeken we naar een vorige/volgende les
define( 'ARRAHMA_MAX_PERIODE_DAGEN',   190 );  // langste periode in het aanwezigheidsoverzicht
define( 'ARRAHMA_NOTITIE_MAX',         2000 ); // tekens per notitie
define( 'ARRAHMA_KLASNAAM_MAX',        60 );

/** Statussen waarmee een inschrijving niet (meer) in een klas zit. */
function arrahma_inactieve_statussen(): array {
    return [ 'afgewezen', 'uitgeschreven' ];
}

// ── Rol en toegang ───────────────────────────────────────────

/** Rol "Docent": alleen lezen + aanwezigheid. Idempotent, draait bij elke versie-update. */
function arrahma_registreer_docent_rol(): void {
    $rol = get_role( ARRAHMA_DOCENT_ROLE );
    if ( ! $rol ) {
        add_role( ARRAHMA_DOCENT_ROLE, 'Docent', [ 'read' => true, ARRAHMA_DOCENT_CAP => true ] );
        return;
    }
    if ( ! $rol->has_cap( ARRAHMA_DOCENT_CAP ) ) {
        $rol->add_cap( ARRAHMA_DOCENT_CAP );
    }
}

/** Beheerders zijn het bestuur: alle groepen, klassen en notities. */
function arrahma_is_bestuur(): bool {
    return current_user_can( 'manage_options' );
}

/**
 * Mag deze gebruiker de klassen van dit blok beheren?
 * Beheerders altijd; een coördinator (docentaccount met ARRAHMA_KLAS_CAP, vaak een gedeeld account)
 * alleen voor blokken waaraan hij gekoppeld is.
 */
function arrahma_mag_klassen( string $slot ): bool {
    if ( arrahma_is_bestuur() ) return true;
    if ( ! current_user_can( ARRAHMA_KLAS_CAP ) ) return false;
    foreach ( arrahma_docent_eenheden( get_current_user_id() ) as $eenheid ) {
        if ( $eenheid['slot'] === $slot ) return true;
    }
    return false;
}

/** Docent zonder beheerrechten — hoort wp-admin niet te zien. */
function arrahma_is_alleen_docent(): bool {
    return is_user_logged_in() && current_user_can( ARRAHMA_DOCENT_CAP ) && ! arrahma_is_bestuur();
}

/** Adres van de docentenpagina; de shortcode legt het vast zodra de pagina één keer is bekeken. */
function arrahma_docenten_url(): string {
    $url = (string) get_option( ARRAHMA_DOCENTEN_URL_OPTION, '' );
    return $url !== '' ? $url : home_url( '/docenten/' );
}

add_filter( 'login_redirect', function ( $redirect_to, $requested, $user ) {
    if ( $user instanceof WP_User && $user->has_cap( ARRAHMA_DOCENT_CAP ) && ! $user->has_cap( 'manage_options' ) ) {
        return arrahma_docenten_url();
    }
    return $redirect_to;
}, 10, 3 );

function arrahma_stuur_docent_weg_uit_admin(): void {
    if ( wp_doing_ajax() || ! arrahma_is_alleen_docent() ) return;
    wp_safe_redirect( arrahma_docenten_url() );
    exit;
}
add_action( 'admin_init', 'arrahma_stuur_docent_weg_uit_admin' );
// Beheerpagina's waar de docent geen recht op heeft, weigert WordPress al vóór admin_init.
add_action( 'admin_page_access_denied', 'arrahma_stuur_docent_weg_uit_admin' );

add_filter( 'show_admin_bar', function ( $show ) {
    return arrahma_is_alleen_docent() ? false : $show;
} );

// ── Klassen en eenheden ──────────────────────────────────────

/** [ klas_id => [ 'slot' => string, 'naam' => string ] ], in aanmaakvolgorde. */
function arrahma_klassen(): array {
    $klassen = get_option( ARRAHMA_KLASSEN_OPTION, [] );
    return is_array( $klassen ) ? $klassen : [];
}

/** Klassen binnen één lesblok/lesgroep: [ klas_id => naam ]. */
function arrahma_klassen_van_slot( string $slot ): array {
    $uit = [];
    foreach ( arrahma_klassen() as $id => $klas ) {
        if ( ( $klas['slot'] ?? '' ) === $slot ) {
            $uit[ $id ] = (string) $klas['naam'];
        }
    }
    return $uit;
}

/** Leesbare naam van een lesblok/lesgroep inclusief doelgroep, bijv. "Broeders 17+ · Zondag 09:30–11:00 — Niveau 1…". */
function arrahma_slot_naam( string $slot ): string {
    $groep = arrahma_groepen()[ $slot ] ?? null;
    $doelgroep = $groep ? arrahma_category_short_label( $groep['categorie'] ) : 'Kinderen';
    return $doelgroep . ' · ' . arrahma_roster_label( $slot );
}

/**
 * Een eenheid is waar een docent aan hangt en waarvoor aanwezigheid wordt bijgehouden:
 * een heel lesblok/lesgroep (sleutel = slot-sleutel) of één klas daarbinnen (sleutel = klas-id).
 * Zonder klassen is het hele blok de klas.
 */
function arrahma_eenheid( string $sleutel ): ?array {
    $slots   = arrahma_roster_labels();
    $klassen = arrahma_klassen();

    if ( isset( $klassen[ $sleutel ] ) ) {
        $slot = (string) $klassen[ $sleutel ]['slot'];
        if ( ! isset( $slots[ $slot ] ) ) return null;
        return [
            'sleutel' => $sleutel,
            'slot'    => $slot,
            'klas'    => $sleutel,
            'label'   => arrahma_slot_naam( $slot ) . ' — ' . $klassen[ $sleutel ]['naam'],
        ];
    }
    if ( isset( $slots[ $sleutel ] ) ) {
        $heeft_klassen = (bool) arrahma_klassen_van_slot( $sleutel );
        return [
            'sleutel' => $sleutel,
            'slot'    => $sleutel,
            'klas'    => '',
            'label'   => arrahma_slot_naam( $sleutel ) . ( $heeft_klassen ? ' — alle klassen' : '' ),
        ];
    }
    return null;
}

/** Alle eenheden: per blok/groep eerst het geheel, dan de klassen. */
function arrahma_alle_eenheden(): array {
    $uit = [];
    foreach ( array_keys( arrahma_roster_labels() ) as $slot ) {
        $uit[ $slot ] = arrahma_eenheid( $slot );
        foreach ( array_keys( arrahma_klassen_van_slot( $slot ) ) as $klas_id ) {
            $eenheid = arrahma_eenheid( $klas_id );
            if ( $eenheid ) $uit[ $klas_id ] = $eenheid;
        }
    }
    return $uit;
}

/** Eenheden waaraan een docent gekoppeld is; verwijderde klassen vallen er vanzelf uit. */
function arrahma_docent_eenheden( int $user_id ): array {
    $sleutels = get_user_meta( $user_id, ARRAHMA_DOCENT_META, true );
    $uit = [];
    foreach ( is_array( $sleutels ) ? $sleutels : [] as $sleutel ) {
        $eenheid = arrahma_eenheid( (string) $sleutel );
        if ( $eenheid ) $uit[ $eenheid['sleutel'] ] = $eenheid;
    }
    return $uit;
}

function arrahma_eenheden_voor_gebruiker(): array {
    if ( arrahma_is_bestuur() ) return arrahma_alle_eenheden();
    if ( ! current_user_can( ARRAHMA_DOCENT_CAP ) ) return [];
    return arrahma_docent_eenheden( get_current_user_id() );
}

function arrahma_mag_eenheid( string $sleutel ): ?array {
    $eenheden = arrahma_eenheden_voor_gebruiker();
    return $eenheden[ $sleutel ] ?? null;
}

/** Actieve leerlingen van een blok/groep, op naam. */
function arrahma_leerlingen_van_slot( string $slot ): array {
    global $wpdb;
    $table = $wpdb->prefix . ARRAHMA_TABLE;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE rooster = %s AND status NOT IN ('afgewezen','uitgeschreven') ORDER BY voornaam, achternaam",
        $slot
    ) );
}

/**
 * Leerlingen van een eenheid in secties [ [ 'titel' => string, 'rijen' => object[] ] ].
 * Wie in een blok met klassen nog niet is ingedeeld, staat onderaan bij elke klas van dat blok,
 * zodat niemand bij het aanwezigheid opnemen tussen wal en schip valt.
 */
function arrahma_eenheid_secties( array $eenheid ): array {
    $rijen   = arrahma_leerlingen_van_slot( $eenheid['slot'] );
    $klassen = arrahma_klassen_van_slot( $eenheid['slot'] );
    if ( ! $klassen ) {
        return [ [ 'titel' => '', 'rijen' => $rijen ] ];
    }

    $per_klas = [];
    $zonder   = [];
    foreach ( $rijen as $rij ) {
        $klas = (string) ( $rij->klas ?? '' );
        if ( isset( $klassen[ $klas ] ) ) {
            $per_klas[ $klas ][] = $rij;
        } else {
            $zonder[] = $rij;
        }
    }

    $secties = [];
    if ( $eenheid['klas'] !== '' ) {
        $secties[] = [ 'titel' => '', 'rijen' => $per_klas[ $eenheid['klas'] ] ?? [] ];
    } else {
        foreach ( $klassen as $id => $naam ) {
            $secties[] = [ 'titel' => $naam, 'rijen' => $per_klas[ $id ] ?? [] ];
        }
    }
    if ( $zonder ) {
        $secties[] = [ 'titel' => 'Nog niet in een klas', 'rijen' => $zonder ];
    }
    return $secties;
}

function arrahma_aantal_in_secties( array $secties ): int {
    $n = 0;
    foreach ( $secties as $sectie ) $n += count( $sectie['rijen'] );
    return $n;
}

/** Mag de huidige gebruiker deze leerling zien en bijwerken? */
function arrahma_mag_leerling( $rij ): bool {
    if ( ! $rij ) return false;
    if ( arrahma_is_bestuur() ) return true;
    if ( ! current_user_can( ARRAHMA_DOCENT_CAP ) ) return false;
    if ( in_array( $rij->status, arrahma_inactieve_statussen(), true ) ) return false;

    $slot    = (string) $rij->rooster;
    $klas    = (string) ( $rij->klas ?? '' );
    $klassen = arrahma_klassen_van_slot( $slot );
    foreach ( arrahma_docent_eenheden( get_current_user_id() ) as $eenheid ) {
        if ( $eenheid['slot'] !== $slot ) continue;
        if ( $eenheid['klas'] === '' || $eenheid['klas'] === $klas || ! isset( $klassen[ $klas ] ) ) {
            return true;
        }
    }
    return false;
}

function arrahma_haal_inschrijving( int $id ) {
    global $wpdb;
    if ( $id <= 0 ) return null;
    $table = $wpdb->prefix . ARRAHMA_TABLE;
    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
}

// ── Lesrooster ───────────────────────────────────────────────

function arrahma_vandaag(): string {
    return current_time( 'Y-m-d' );
}

function arrahma_geldige_datum( string $datum ): bool {
    if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $datum, $m ) ) return false;
    return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
}

function arrahma_verschuif_datum( string $datum, int $dagen ): string {
    return ( new DateTimeImmutable( $datum ) )->modify( sprintf( '%+d days', $dagen ) )->format( 'Y-m-d' );
}

/** "maandag 5 oktober 2026" in de taal van de site. */
function arrahma_datum_lang( string $datum ): string {
    return date_i18n( 'l j F Y', strtotime( $datum . ' 12:00:00' ) );
}

function arrahma_datum_kort( string $datum ): string {
    return date_i18n( 'D j M', strtotime( $datum . ' 12:00:00' ) );
}

/** ISO-weekdagen (1 = maandag) uit de dag-tekst van een blok/groep; "Zaterdag & zondag" geeft [6, 7]. */
function arrahma_slot_weekdagen( string $slot ): array {
    $dag   = strtolower( arrahma_slot_dag_tijd( $slot )['dag'] );
    $namen = [ 'maandag' => 1, 'dinsdag' => 2, 'woensdag' => 3, 'donderdag' => 4, 'vrijdag' => 5, 'zaterdag' => 6, 'zondag' => 7 ];
    $uit   = [];
    foreach ( $namen as $naam => $nummer ) {
        if ( strpos( $dag, $naam ) !== false ) $uit[] = $nummer;
    }
    return $uit;
}

/**
 * [ [ 'naam' => string, 'van' => Y-m-d, 'tot' => Y-m-d, 'slots' => string[] ] ], op begindatum.
 * 'slots' leeg of afwezig = voor alle groepen; anders alleen voor die blokken/lesgroepen.
 */
function arrahma_vakanties(): array {
    $vakanties = get_option( ARRAHMA_VAKANTIES_OPTION, [] );
    $vakanties = is_array( $vakanties ) ? array_values( $vakanties ) : [];
    usort( $vakanties, function ( $a, $b ) { return strcmp( $a['van'], $b['van'] ); } );
    return $vakanties;
}

/** [ [ 'slot' => string, 'datum' => Y-m-d, 'soort' => 'vervalt'|'extra' ] ], op datum. */
function arrahma_les_uitzonderingen(): array {
    $lijst = get_option( ARRAHMA_LES_UITZ_OPTION, [] );
    $lijst = is_array( $lijst ) ? array_values( $lijst ) : [];
    usort( $lijst, function ( $a, $b ) { return strcmp( $a['datum'], $b['datum'] ); } );
    return $lijst;
}

/** Geldt een vakantie voor dit blok? */
function arrahma_vakantie_geldt( array $vakantie, string $slot ): bool {
    return empty( $vakantie['slots'] ) || in_array( $slot, (array) $vakantie['slots'], true );
}

/** Voor wie een vakantie geldt, leesbaar: "Alle groepen", "Kinderen (alle blokken)" of een opsomming. */
function arrahma_vakantie_bereik( array $vakantie ): string {
    if ( empty( $vakantie['slots'] ) ) return 'Alle groepen';
    $per_doelgroep = [];
    foreach ( array_keys( arrahma_roster_labels() ) as $slot ) {
        $per_doelgroep[ arrahma_slot_doelgroep( $slot ) ][] = $slot;
    }
    $delen = [];
    foreach ( $per_doelgroep as $doelgroep => $slots ) {
        $gekozen = array_values( array_intersect( $slots, (array) $vakantie['slots'] ) );
        if ( ! $gekozen ) continue;
        $delen[] = count( $gekozen ) === count( $slots )
            ? $doelgroep . ' (alles)'
            : $doelgroep . ': ' . implode( ', ', array_map( 'arrahma_roster_label', $gekozen ) );
    }
    return implode( ' · ', $delen );
}

/**
 * Is er les? [ 'les' => bool, 'reden' => string ].
 * Volgorde: uitzondering voor dit blok > weekdag > vakantie. Er is geen begin- of einddatum:
 * de kalender loopt het hele jaar door.
 */
function arrahma_lesdag_info( string $slot, string $datum ): array {
    foreach ( arrahma_les_uitzonderingen() as $u ) {
        if ( $u['slot'] === $slot && $u['datum'] === $datum ) {
            return $u['soort'] === 'extra'
                ? [ 'les' => true,  'reden' => 'Extra les' ]
                : [ 'les' => false, 'reden' => 'Deze les vervalt.' ];
        }
    }

    $weekdag = (int) ( new DateTimeImmutable( $datum ) )->format( 'N' );
    if ( ! in_array( $weekdag, arrahma_slot_weekdagen( $slot ), true ) ) {
        return [ 'les' => false, 'reden' => 'Op deze dag heeft deze groep geen les.' ];
    }

    foreach ( arrahma_vakanties() as $vakantie ) {
        if ( $datum >= $vakantie['van'] && $datum <= $vakantie['tot'] && arrahma_vakantie_geldt( $vakantie, $slot ) ) {
            return [ 'les' => false, 'reden' => 'Geen les: ' . $vakantie['naam'] . '.' ];
        }
    }
    return [ 'les' => true, 'reden' => '' ];
}

function arrahma_is_lesdag( string $slot, string $datum ): bool {
    return arrahma_lesdag_info( $slot, $datum )['les'];
}

/** Dichtstbijzijnde les vóór (richting -1) of na (+1) een datum, of null. */
function arrahma_zoek_les( string $slot, string $vanaf, int $richting ): ?string {
    for ( $i = 1; $i <= ARRAHMA_ZOEK_DAGEN; $i++ ) {
        $datum = arrahma_verschuif_datum( $vanaf, $i * $richting );
        if ( arrahma_is_lesdag( $slot, $datum ) ) return $datum;
    }
    return null;
}

/** De les van vandaag, anders de laatst verstreken les. */
function arrahma_standaard_lesdatum( string $slot ): string {
    $vandaag = arrahma_vandaag();
    if ( arrahma_is_lesdag( $slot, $vandaag ) ) return $vandaag;
    return arrahma_zoek_les( $slot, $vandaag, -1 ) ?? $vandaag;
}

function arrahma_lesdata_in_periode( string $slot, string $van, string $tot ): array {
    $uit = [];
    for ( $datum = $van; $datum <= $tot; $datum = arrahma_verschuif_datum( $datum, 1 ) ) {
        if ( arrahma_is_lesdag( $slot, $datum ) ) $uit[] = $datum;
    }
    return $uit;
}

// ── Aanwezigheid ─────────────────────────────────────────────

function arrahma_aanwezig_statussen(): array {
    return [ 'aanwezig' => 'Aanwezig', 'te_laat' => 'Te laat', 'afgemeld' => 'Afgemeld', 'afwezig' => 'Afwezig' ];
}

function arrahma_aanwezig_kleuren(): array {
    return [ 'aanwezig' => '#2e7d32', 'te_laat' => '#9a6a10', 'afgemeld' => '#323f44', 'afwezig' => '#c62828' ];
}

/** Eén letter per status voor het overzicht: A, L, M, X. */
function arrahma_aanwezig_letters(): array {
    return [ 'aanwezig' => 'A', 'te_laat' => 'L', 'afgemeld' => 'M', 'afwezig' => 'X' ];
}

/** [ inschrijving_id => status ] voor één les. */
function arrahma_aanwezigheid_op( string $slot, string $datum ): array {
    global $wpdb;
    $table = $wpdb->prefix . ARRAHMA_AANWEZIG_TABLE;
    $rows  = $wpdb->get_results( $wpdb->prepare(
        "SELECT inschrijving_id, status FROM {$table} WHERE rooster = %s AND lesdatum = %s",
        $slot, $datum
    ) );
    $uit = [];
    foreach ( $rows as $row ) $uit[ (int) $row->inschrijving_id ] = $row->status;
    return $uit;
}

/** Zet of wist (status '') de aanwezigheid van één leerling voor één les. */
function arrahma_zet_aanwezigheid( int $inschrijving_id, string $slot, string $datum, string $status ): bool {
    global $wpdb;
    $table = $wpdb->prefix . ARRAHMA_AANWEZIG_TABLE;

    if ( $status === '' ) {
        return false !== $wpdb->delete( $table, [ 'inschrijving_id' => $inschrijving_id, 'lesdatum' => $datum, 'rooster' => $slot ], [ '%d', '%s', '%s' ] );
    }
    return false !== $wpdb->query( $wpdb->prepare(
        "INSERT INTO {$table} (inschrijving_id, rooster, lesdatum, status, door, gewijzigd_op)
         VALUES (%d, %s, %s, %s, %d, %s)
         ON DUPLICATE KEY UPDATE status = VALUES(status), door = VALUES(door), gewijzigd_op = VALUES(gewijzigd_op)",
        $inschrijving_id, $slot, $datum, $status, get_current_user_id(), current_time( 'mysql' )
    ) );
}

add_action( 'wp_ajax_arrahma_aanwezigheid', 'arrahma_ajax_aanwezigheid' );

function arrahma_ajax_aanwezigheid(): void {
    check_ajax_referer( 'arrahma_aanwezigheid', 'nonce' );

    $id     = absint( $_POST['inschrijving_id'] ?? 0 );
    $datum  = sanitize_text_field( wp_unslash( $_POST['datum'] ?? '' ) );
    $status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
    $rij    = arrahma_haal_inschrijving( $id );

    if ( ! arrahma_mag_leerling( $rij ) ) {
        wp_send_json_error( [ 'bericht' => 'Je hebt geen toegang tot deze leerling.' ], 403 );
    }
    if ( ! arrahma_geldige_datum( $datum ) || ! arrahma_is_lesdag( $rij->rooster, $datum ) ) {
        wp_send_json_error( [ 'bericht' => 'Op deze datum is geen les.' ], 400 );
    }
    if ( $datum > arrahma_vandaag() ) {
        wp_send_json_error( [ 'bericht' => 'Deze les is nog niet geweest.' ], 400 );
    }
    if ( $status !== '' && ! isset( arrahma_aanwezig_statussen()[ $status ] ) ) {
        wp_send_json_error( [ 'bericht' => 'Onbekende status.' ], 400 );
    }
    if ( ! arrahma_zet_aanwezigheid( $id, $rij->rooster, $datum, $status ) ) {
        wp_send_json_error( [ 'bericht' => 'Opslaan mislukt, probeer het opnieuw.' ], 500 );
    }
    wp_send_json_success( [ 'status' => $status ] );
}

// ── Notities ─────────────────────────────────────────────────

function arrahma_notities_van( int $inschrijving_id ): array {
    global $wpdb;
    $table = $wpdb->prefix . ARRAHMA_NOTITIE_TABLE;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE inschrijving_id = %d ORDER BY aangemaakt_op DESC, id DESC",
        $inschrijving_id
    ) );
}

function arrahma_gebruiker_naam( int $user_id ): string {
    $user = $user_id ? get_userdata( $user_id ) : false;
    return $user ? $user->display_name : 'Onbekend';
}

function arrahma_mag_notitie_wijzigen( $notitie ): bool {
    return $notitie && ( arrahma_is_bestuur() || (int) $notitie->auteur === get_current_user_id() );
}

/**
 * Verwerkt notitie-formulieren van de docentenpagina (toevoegen, wijzigen, verwijderen) en stuurt
 * daarna terug naar dezelfde leerling (post/redirect/get, zodat verversen niets dubbel opslaat).
 */
add_action( 'template_redirect', 'arrahma_verwerk_notitie_post' );

function arrahma_verwerk_notitie_post(): void {
    if ( empty( $_POST['arrahma_notitie_actie'] ) || ! is_user_logged_in() ) return;
    global $wpdb;

    $actie    = sanitize_key( wp_unslash( $_POST['arrahma_notitie_actie'] ) );
    $leerling = absint( $_POST['leerling'] ?? 0 );
    $eenheid  = sanitize_key( wp_unslash( $_POST['eenheid'] ?? '' ) );
    $basis    = get_permalink( get_queried_object_id() ) ?: arrahma_docenten_url();
    $terug    = add_query_arg( array_filter( [ 'leerling' => $leerling, 'eenheid' => $eenheid ] ), $basis );
    $klaar    = function ( string $melding ) use ( $terug ) {
        wp_safe_redirect( add_query_arg( 'melding', $melding, $terug ) . '#notities' );
        exit;
    };

    $nonce = sanitize_text_field( wp_unslash( $_POST['_arrahma_nonce'] ?? '' ) );
    if ( ! wp_verify_nonce( $nonce, 'arrahma_notitie_' . $leerling ) ) $klaar( 'verlopen' );
    if ( ! arrahma_mag_leerling( arrahma_haal_inschrijving( $leerling ) ) ) $klaar( 'geen_toegang' );

    $table = $wpdb->prefix . ARRAHMA_NOTITIE_TABLE;
    $tekst = trim( sanitize_textarea_field( wp_unslash( $_POST['tekst'] ?? '' ) ) );
    $tekst = function_exists( 'mb_substr' ) ? mb_substr( $tekst, 0, ARRAHMA_NOTITIE_MAX ) : substr( $tekst, 0, ARRAHMA_NOTITIE_MAX );

    if ( $actie === 'toevoegen' ) {
        if ( $tekst === '' ) $klaar( 'leeg' );
        $ok = $wpdb->insert( $table, [
            'inschrijving_id' => $leerling,
            'tekst'           => $tekst,
            'auteur'          => get_current_user_id(),
            'aangemaakt_op'   => current_time( 'mysql' ),
        ], [ '%d', '%s', '%d', '%s' ] );
        $klaar( $ok ? 'toegevoegd' : 'mislukt' );
    }

    $notitie = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE id = %d AND inschrijving_id = %d",
        absint( $_POST['notitie'] ?? 0 ), $leerling
    ) );
    if ( ! arrahma_mag_notitie_wijzigen( $notitie ) ) $klaar( 'geen_toegang' );

    if ( $actie === 'wijzigen' ) {
        if ( $tekst === '' ) $klaar( 'leeg' );
        $ok = $wpdb->update( $table, [ 'tekst' => $tekst, 'gewijzigd_op' => current_time( 'mysql' ) ], [ 'id' => $notitie->id ], [ '%s', '%s' ], [ '%d' ] );
        $klaar( false !== $ok ? 'gewijzigd' : 'mislukt' );
    }
    if ( $actie === 'verwijderen' ) {
        $ok = $wpdb->delete( $table, [ 'id' => $notitie->id ], [ '%d' ] );
        $klaar( $ok ? 'verwijderd' : 'mislukt' );
    }
    $klaar( 'mislukt' );
}

// ── Docentenpagina (shortcode) ───────────────────────────────
// Ontwerp: artifact "Arrahma Docentenportaal" (zie docs/specs/docenten-aanwezigheid.md).
// Schermen: Mijn groepen · Presentielijst · Kalender · Leerling · Maandoverzicht bestuur.
define( 'ARRAHMA_OPEN_DAGEN', 28 );                               // zo ver terug zoekt "Nog in te vullen"
define( 'ARRAHMA_LOGO_PAD',   '2024/02/Arrahma-Logo-300x213.png' ); // websitelogo in wp-content/uploads

add_shortcode( 'arrahma_docenten', 'arrahma_docenten_shortcode' );

/** Body-klasse op de docentenpagina, zodat de CSS de paginamarges van thema en Elementor kan weghalen. */
add_filter( 'body_class', function ( array $klassen ): array {
    if ( ! is_singular() ) return $klassen;
    $id   = (int) get_queried_object_id();
    $post = get_post( $id );
    $raak = $post && (
        has_shortcode( (string) $post->post_content, 'arrahma_docenten' )
        || strpos( (string) get_post_meta( $id, '_elementor_data', true ), 'arrahma_docenten' ) !== false
        || get_permalink( $id ) === get_option( ARRAHMA_DOCENTEN_URL_OPTION )
    );
    if ( $raak ) $klassen[] = 'arr-docentenpagina';
    return $klassen;
} );

function arrahma_docenten_shortcode(): string {
    if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true ); // persoonlijke inhoud: nooit cachen

    $basis = (string) get_permalink();
    if ( $basis !== '' && $basis !== get_option( ARRAHMA_DOCENTEN_URL_OPTION ) ) {
        update_option( ARRAHMA_DOCENTEN_URL_OPTION, $basis, false );
    }

    $scherm = 'overzicht';
    $GLOBALS['arrahma_hoofd_open'] = false;
    ob_start();
    if ( ! is_user_logged_in() ) {
        $scherm = 'login';
        arrahma_docenten_login( $basis );
    } elseif ( ! current_user_can( ARRAHMA_DOCENT_CAP ) && ! arrahma_is_bestuur() ) {
        arrahma_docenten_kop( [ 'boven' => 'Docenten', 'titel' => 'Geen toegang' ] );
        arrahma_docenten_leeg( 'link', 'Dit is geen docentaccount', 'Neem contact op met het bestuur als je lesgeeft en toegang nodig hebt.',
            wp_logout_url( $basis ), 'Uitloggen' );
    } else {
        $leerling = absint( $_GET['leerling'] ?? 0 );
        $sleutel  = sanitize_key( wp_unslash( $_GET['eenheid'] ?? '' ) );
        $weergave = sanitize_key( wp_unslash( $_GET['weergave'] ?? '' ) );
        $eenheden = arrahma_eenheden_voor_gebruiker();

        if ( ! empty( $_GET['beheer'] ) ) {
            $scherm = 'beheer';
            arrahma_docenten_klassen( $basis, sanitize_key( wp_unslash( $_GET['slot'] ?? '' ) ) );
        } elseif ( $leerling ) {
            $scherm = 'leerling';
            arrahma_docenten_leerling( $basis, $leerling, $sleutel );
        } elseif ( ! empty( $_GET['bestuur'] ) && arrahma_is_bestuur() ) {
            $scherm = 'bestuur';
            arrahma_docenten_bestuur( $basis );
        } elseif ( $sleutel !== '' ) {
            $scherm = $weergave === 'kalender' ? 'kalender' : 'lijst';
            $weergave === 'kalender' ? arrahma_docenten_kalender( $basis, $sleutel ) : arrahma_docenten_presentie( $basis, $sleutel );
        } else {
            arrahma_docenten_overzicht( $basis, $eenheden );
        }
    }
    if ( ! empty( $GLOBALS['arrahma_hoofd_open'] ) ) echo '</div>'; // .arr-hoofd, geopend door de kop
    $inhoud = (string) ob_get_clean();

    return arrahma_docenten_css() . arrahma_docenten_iconen()
        . '<div class="arr-doc scherm-' . esc_attr( $scherm ) . '">' . $inhoud . '</div>';
}

// ── Kleine bouwstenen ────────────────────────────────────────

function arrahma_icoon( string $naam, int $maat = 18 ): string {
    return '<svg class="arr-i" width="' . $maat . '" height="' . $maat . '" aria-hidden="true" focusable="false"><use href="#arr-i-' . esc_attr( $naam ) . '"/></svg>';
}

/** Doelgroep van een blok/groep, bijv. "Kinderen" of "Broeders 17+". */
function arrahma_slot_doelgroep( string $slot ): string {
    $groep = arrahma_groepen()[ $slot ] ?? null;
    return $groep ? arrahma_category_short_label( $groep['categorie'] ) : 'Kinderen';
}

/** Begin- en eindtijd uit "15:30–17:30". */
function arrahma_slot_tijden( string $slot ): array {
    $delen = preg_split( '/\s*[–-]\s*/u', arrahma_slot_dag_tijd( $slot )['tijd'] );
    return [ 'start' => trim( $delen[0] ?? '' ), 'eind' => trim( $delen[1] ?? '' ) ];
}

/** Hoe een eenheid heet op het scherm: bovenschrift (doelgroep), titel (klas/niveau/blok) en regel (dag · tijd). */
function arrahma_eenheid_weergave( array $eenheid ): array {
    $slot = $eenheid['slot'];
    $dt   = arrahma_slot_dag_tijd( $slot );
    if ( $eenheid['klas'] !== '' ) {
        $titel = arrahma_klassen_van_slot( $slot )[ $eenheid['klas'] ] ?? '';
    } elseif ( isset( arrahma_groepen()[ $slot ] ) ) {
        $titel = arrahma_niveau_label( arrahma_groepen()[ $slot ]['niveau'] );
    } else {
        $blok  = arrahma_kinderen_blokken()[ $slot ] ?? [];
        $titel = isset( $blok['blok'] ) ? ucfirst( $blok['blok'] ) : $dt['dag'];
    }
    if ( $eenheid['klas'] === '' && arrahma_klassen_van_slot( $slot ) ) {
        $titel .= ' · alle klassen';
    }
    return [ 'boven' => arrahma_slot_doelgroep( $slot ), 'titel' => $titel, 'sub' => $dt['dag'] . ' · ' . $dt['tijd'] ];
}

function arrahma_initialen( string $naam ): string {
    $uit = '';
    foreach ( preg_split( '/\s+/u', trim( $naam ) ) as $woord ) {
        if ( $woord === '' ) continue;
        $uit .= function_exists( 'mb_substr' ) ? mb_substr( $woord, 0, 1 ) : substr( $woord, 0, 1 );
        if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $uit ) : strlen( $uit ) ) >= 2 ) break;
    }
    return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $uit ) : strtoupper( $uit );
}

function arrahma_datum_titel( string $datum ): string {
    return ucfirst( date_i18n( 'l j F', strtotime( $datum . ' 12:00:00' ) ) );
}

function arrahma_weekdag_kort( string $datum ): string {
    return ucfirst( date_i18n( 'D', strtotime( $datum . ' 12:00:00' ) ) );
}

/** URL naar een scherm van de docentenpagina. */
function arrahma_docenten_link( string $basis, array $args ): string {
    return add_query_arg( array_filter( $args, function ( $v ) { return $v !== '' && $v !== null; } ), $basis );
}

/**
 * Leisteen kop bovenaan elk scherm, zoals de websiteheader.
 * $k: boven, titel, sub, terug_url, terug_label, rechts (html), avatar, na (html onder de titel).
 */
function arrahma_docenten_kop( array $k ): void {
    echo '<header class="arr-kop"><div class="arr-kop-rij">';
    if ( ! empty( $k['terug_url'] ) ) {
        echo '<a class="arr-terug" href="' . esc_url( $k['terug_url'] ) . '">' . arrahma_icoon( 'links', 16 ) . esc_html( $k['terug_label'] ?? 'Terug' ) . '</a>';
    } else {
        echo '<span class="arr-boven">' . esc_html( $k['boven'] ?? '' ) . '</span>';
    }
    echo ! empty( $k['terug_url'] ) ? '<span class="arr-boven">' . esc_html( $k['boven'] ?? '' ) . '</span>' : ( $k['rechts'] ?? '' );
    echo '</div>';

    echo '<div class="arr-kop-titel">';
    if ( ! empty( $k['avatar'] ) ) {
        echo '<span class="arr-av arr-av-groot" aria-hidden="true">' . esc_html( $k['avatar'] ) . '</span>';
    }
    echo '<div><h2>' . esc_html( $k['titel'] ?? '' ) . '</h2>';
    if ( ! empty( $k['sub'] ) ) echo '<p class="arr-kop-sub">' . esc_html( $k['sub'] ) . '</p>';
    echo '</div></div>';
    if ( ! empty( $k['na'] ) ) echo '<div class="arr-kop-na">' . $k['na'] . '</div>';
    echo '</header>';
    // Alles onder de kop komt in één gecentreerde kolom (breedte per scherm, zie CSS --breedte).
    if ( empty( $GLOBALS['arrahma_hoofd_open'] ) ) {
        echo '<div class="arr-hoofd">';
        $GLOBALS['arrahma_hoofd_open'] = true;
    }
}

/** Lijst / Kalender-schakelaar voor een eenheid. */
function arrahma_docenten_wissel( string $basis, string $sleutel, string $actief, string $datum ): string {
    $lijst    = arrahma_docenten_link( $basis, [ 'eenheid' => $sleutel, 'datum' => $datum ] );
    $kalender = arrahma_docenten_link( $basis, [ 'eenheid' => $sleutel, 'weergave' => 'kalender', 'maand' => substr( $datum, 0, 7 ) ] );
    return '<nav class="arr-wissel" aria-label="Weergave">'
        . '<a href="' . esc_url( $lijst ) . '"' . ( $actief === 'lijst' ? ' aria-current="page"' : '' ) . '>' . arrahma_icoon( 'lijst', 16 ) . 'Lijst</a>'
        . '<a href="' . esc_url( $kalender ) . '"' . ( $actief === 'kalender' ? ' aria-current="page"' : '' ) . '>' . arrahma_icoon( 'kal', 16 ) . 'Kalender</a></nav>';
}

/** Knop naar het klassenbeheer; alleen zichtbaar voor wie dit blok mag beheren. */
function arrahma_docenten_klasknop( string $basis, array $eenheid ): string {
    if ( ! arrahma_mag_klassen( $eenheid['slot'] ) ) return '';
    $url = arrahma_docenten_link( $basis, [ 'beheer' => 'klassen', 'slot' => $eenheid['slot'] ] );
    return '<a class="arr-knop arr-knop-licht-op-donker arr-knop-klein" href="' . esc_url( $url ) . '">' . arrahma_icoon( 'lijst', 16 ) . 'Klassen beheren</a>';
}

/** Lege toestand: zegt waarom er niets staat en wat de volgende stap is. */
function arrahma_docenten_leeg( string $icoon, string $titel, string $tekst, string $knop_url = '', string $knop_label = '' ): void {
    echo '<div class="arr-leeg"><span class="arr-leeg-icoon' . ( $icoon === 'klok' ? ' is-leisteen' : '' ) . '">' . arrahma_icoon( $icoon, 34 ) . '</span>'
        . '<h3>' . esc_html( $titel ) . '</h3><p>' . esc_html( $tekst ) . '</p>'
        . ( $knop_url ? '<a class="arr-knop arr-knop-rand" href="' . esc_url( $knop_url ) . '">' . esc_html( $knop_label ) . '</a>' : '' )
        . '</div>';
}

function arrahma_docenten_melding(): void {
    $meldingen = [
        'toegevoegd'   => [ true,  'Notitie opgeslagen.' ],
        'gewijzigd'    => [ true,  'Notitie aangepast.' ],
        'verwijderd'   => [ true,  'Notitie verwijderd.' ],
        'leeg'         => [ false, 'Een notitie kan niet leeg zijn.' ],
        'verlopen'     => [ false, 'De pagina was verlopen. Probeer het opnieuw.' ],
        'geen_toegang' => [ false, 'Je hebt hier geen toegang toe.' ],
        'mislukt'      => [ false, 'Opslaan is mislukt. Probeer het opnieuw.' ],
    ];
    $code = sanitize_key( wp_unslash( $_GET['melding'] ?? '' ) );
    if ( ! isset( $meldingen[ $code ] ) ) return;
    [ $ok, $tekst ] = $meldingen[ $code ];
    echo '<div class="arr-melding' . ( $ok ? ' is-ok' : '' ) . '" role="status">' . esc_html( $tekst ) . '</div>';
}

// ── Voltooiing: hoe ver is de presentie van een les? ─────────

/** [ slot => [ datum => [ inschrijving_id => status ] ] ] voor een periode, optioneel alleen bepaalde blokken. */
function arrahma_aanwezigheid_kaart( string $van, string $tot, array $slots = [] ): array {
    global $wpdb;
    $table = $wpdb->prefix . ARRAHMA_AANWEZIG_TABLE;
    $sql   = "SELECT rooster, lesdatum, inschrijving_id, status FROM {$table} WHERE lesdatum BETWEEN %s AND %s";
    $args  = [ $van, $tot ];
    if ( $slots ) {
        $sql .= ' AND rooster IN (' . implode( ',', array_fill( 0, count( $slots ), '%s' ) ) . ')';
        $args = array_merge( $args, array_values( $slots ) );
    }
    $kaart = [];
    foreach ( $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) as $r ) {
        $kaart[ $r->rooster ][ $r->lesdatum ][ (int) $r->inschrijving_id ] = $r->status;
    }
    return $kaart;
}

/** Ids van de leerlingen die bij een eenheid op de lijst staan (inclusief "nog niet in een klas"). */
function arrahma_eenheid_ids( array $eenheid ): array {
    $ids = [];
    foreach ( arrahma_eenheid_secties( $eenheid ) as $sectie ) {
        foreach ( $sectie['rijen'] as $rij ) $ids[] = (int) $rij->id;
    }
    return $ids;
}

/** Staat van één les: geen (geen leerlingen) | leeg | deels | klaar, met tellingen. */
function arrahma_voltooiing( array $ids, array $gezet ): array {
    $ingevuld = 0;
    $afwezig  = 0;
    $per      = [];
    foreach ( $ids as $id ) {
        if ( ! isset( $gezet[ $id ] ) ) continue;
        $ingevuld++;
        $per[ $gezet[ $id ] ] = ( $per[ $gezet[ $id ] ] ?? 0 ) + 1;
        if ( $gezet[ $id ] === 'afwezig' ) $afwezig++;
    }
    $totaal = count( $ids );
    $staat  = ! $totaal ? 'geen' : ( ! $ingevuld ? 'leeg' : ( $ingevuld < $totaal ? 'deels' : 'klaar' ) );
    return [ 'staat' => $staat, 'ingevuld' => $ingevuld, 'totaal' => $totaal, 'afwezig' => $afwezig, 'per' => $per ];
}

/** Wat een dag is voor een blok: les | vervalt | vakantie | geen, met extra-les en vakantienaam. */
function arrahma_dag_soort( string $slot, string $datum ): array {
    $info = arrahma_lesdag_info( $slot, $datum );
    $extra = $info['les'] && $info['reden'] === 'Extra les';
    $vakantie = '';
    foreach ( arrahma_vakanties() as $v ) {
        if ( $datum >= $v['van'] && $datum <= $v['tot'] && arrahma_vakantie_geldt( $v, $slot ) ) { $vakantie = $v['naam']; break; }
    }
    if ( $info['les'] ) return [ 'soort' => 'les', 'extra' => $extra, 'vakantie' => $vakantie ];
    if ( $info['reden'] === 'Deze les vervalt.' ) return [ 'soort' => 'vervalt', 'extra' => false, 'vakantie' => $vakantie ];
    $weekdag = (int) ( new DateTimeImmutable( $datum ) )->format( 'N' );
    $lesdag  = in_array( $weekdag, arrahma_slot_weekdagen( $slot ), true );
    return [ 'soort' => $vakantie !== '' && $lesdag ? 'vakantie' : 'geen', 'extra' => false, 'vakantie' => $vakantie ];
}

/**
 * Eerste les waarvoor ooit aanwezigheid is ingevuld, per blok: [ slot => Y-m-d ].
 * Er is geen startdatum per groep; zo telt een groep pas "open" lessen vanaf het moment dat hij echt begonnen is.
 */
function arrahma_eerste_lesdata(): array {
    static $cache = null;
    if ( $cache !== null ) return $cache;
    global $wpdb;
    $table = $wpdb->prefix . ARRAHMA_AANWEZIG_TABLE;
    $cache = [];
    foreach ( $wpdb->get_results( "SELECT rooster, MIN(lesdatum) AS eerste FROM {$table} GROUP BY rooster" ) as $r ) {
        $cache[ $r->rooster ] = $r->eerste;
    }
    return $cache;
}

/** Verstreken lessen (tot en met gisteren) die niet compleet zijn ingevuld, sinds de groep begonnen is. */
function arrahma_open_lessen( array $eenheden ): array {
    $tot = arrahma_verschuif_datum( arrahma_vandaag(), -1 );
    $van = arrahma_verschuif_datum( arrahma_vandaag(), -ARRAHMA_OPEN_DAGEN );
    $slots = array_values( array_unique( array_map( function ( $e ) { return $e['slot']; }, $eenheden ) ) );
    if ( ! $slots ) return [];
    $kaart = arrahma_aanwezigheid_kaart( $van, $tot, $slots );

    $open = [];
    $eerste = arrahma_eerste_lesdata();
    foreach ( $eenheden as $sleutel => $eenheid ) {
        $ids   = arrahma_eenheid_ids( $eenheid );
        $start = $eerste[ $eenheid['slot'] ] ?? '';
        if ( ! $ids || $start === '' ) continue;           // nog nooit iets ingevuld: groep is nog niet begonnen
        foreach ( arrahma_lesdata_in_periode( $eenheid['slot'], max( $van, $start ), $tot ) as $datum ) {
            $v = arrahma_voltooiing( $ids, $kaart[ $eenheid['slot'] ][ $datum ] ?? [] );
            if ( $v['staat'] === 'leeg' || $v['staat'] === 'deels' ) {
                $open[] = [ 'sleutel' => $sleutel, 'eenheid' => $eenheid, 'datum' => $datum, 'v' => $v ];
            }
        }
    }
    usort( $open, function ( $a, $b ) { return strcmp( $b['datum'], $a['datum'] ); } );
    return $open;
}

/** Glyph voor de staat van een les in kalender en maandoverzicht. */
function arrahma_glyph( string $staat ): string {
    $naam = [ 'klaar' => 'klaar', 'deels' => 'deels', 'leeg' => 'leeg', 'geen' => 'leeg', 'toekomst' => 'toekomst', 'vervalt' => 'vervalt' ][ $staat ] ?? 'leeg';
    return '<svg class="arr-glyph" width="20" height="20" aria-hidden="true" focusable="false"><use href="#arr-g-' . $naam . '"/></svg>';
}

function arrahma_staat_label( array $v ): string {
    switch ( $v['staat'] ) {
        case 'klaar': return 'compleet';
        case 'deels': return $v['ingevuld'] . ' van ' . $v['totaal'] . ' ingevuld';
        case 'geen':  return 'geen leerlingen';
        default:      return 'niet ingevuld';
    }
}

// ── Scherm: inloggen ─────────────────────────────────────────

function arrahma_logo_url(): string {
    $uploads = wp_upload_dir();
    $pad     = trailingslashit( $uploads['basedir'] ) . ARRAHMA_LOGO_PAD;
    $url     = file_exists( $pad ) ? trailingslashit( $uploads['baseurl'] ) . ARRAHMA_LOGO_PAD : '';
    return (string) apply_filters( 'arrahma_docenten_logo_url', $url );
}

function arrahma_docenten_login( string $basis ): void {
    $logo = arrahma_logo_url();
    echo '<div class="arr-login"><div class="arr-login-kolom"><div class="arr-login-top">';
    echo $logo !== ''
        ? '<img src="' . esc_url( $logo ) . '" width="150" height="107" alt="Arrahma Almere Poort">'
        : '<strong class="arr-woordmerk">ARRAHMA</strong>';
    echo '<div><div class="arr-boven">Docenten</div><p>Log in om de aanwezigheid van je klas bij te houden.</p></div></div>';
    echo '<div class="arr-login-kaart">';
    wp_login_form( [
        'redirect'       => $basis,
        'label_username' => 'E-mailadres of gebruikersnaam',
        'label_password' => 'Wachtwoord',
        'label_remember' => 'Ingelogd blijven op deze telefoon',
        'label_log_in'   => 'Inloggen',
        'remember'       => true,
        'value_remember' => false,
    ] );
    echo '<a class="arr-linkje" href="' . esc_url( wp_lostpassword_url( $basis ) ) . '">Wachtwoord vergeten of nog geen wachtwoord?</a></div></div></div>';
}

// ── Scherm: mijn groepen ─────────────────────────────────────
// Eén lijst van groepen, per doelgroep in inklapbare secties, met per groep één regel en een badge
// (Vandaag / N open / ✓ bij). Bovenaan drie cijfers en filters (Alle · Niet ingevuld · Vandaag).

/** Hoofdgroep voor de secties: kinderen, broeders of zusters. */
function arrahma_slot_hoofdgroep( string $slot ): string {
    $groep = arrahma_groepen()[ $slot ] ?? null;
    if ( ! $groep ) return 'kinderen';
    return strpos( $groep['categorie'], 'broeders' ) === 0 ? 'broeders' : 'zusters';
}

/** "Zaterdag & zondag" → "za & zo". */
function arrahma_dag_kort( string $dag ): string {
    $kort = [ 'maandag' => 'ma', 'dinsdag' => 'di', 'woensdag' => 'wo', 'donderdag' => 'do', 'vrijdag' => 'vr', 'zaterdag' => 'za', 'zondag' => 'zo' ];
    return strtr( strtolower( $dag ), $kort );
}

/** Korte regeltitel binnen een sectie, bijv. "Klas A · ma & wo 15:30" of "17+ · Niveau 1 · zo 09:30". */
function arrahma_eenheid_regel( array $eenheid ): string {
    $slot  = $eenheid['slot'];
    $dt    = arrahma_slot_dag_tijd( $slot );
    $w     = arrahma_eenheid_weergave( $eenheid );
    $titel = explode( ' — ', $w['titel'] )[0];                  // "Niveau 1 — Alif Baa | Basis" → "Niveau 1"
    $wanneer = arrahma_dag_kort( $dt['dag'] ) . ' ' . arrahma_slot_tijden( $slot )['start'];
    $groep = arrahma_groepen()[ $slot ] ?? null;
    $leeftijd = $groep ? trim( str_replace( [ 'Broeders', 'Zusters' ], '', arrahma_category_short_label( $groep['categorie'] ) ) ) : '';
    return implode( ' · ', array_filter( [ $leeftijd, $titel, $wanneer ] ) );
}

function arrahma_docenten_overzicht( string $basis, array $eenheden ): void {
    $user     = wp_get_current_user();
    $voornaam = $user->first_name !== '' ? $user->first_name : $user->display_name;
    $bestuur  = arrahma_is_bestuur();
    $vandaag  = arrahma_vandaag();
    if ( $bestuur ) $eenheden = arrahma_bestuur_eenheden(); // klassen i.p.v. het hele blok, zoals in het maandoverzicht

    // Per eenheid: leerlingen, les vandaag, open lessen en de volgende les.
    $open_per = [];
    foreach ( arrahma_open_lessen( $eenheden ) as $o ) $open_per[ $o['sleutel'] ][] = $o['datum'];
    $items = [];
    foreach ( $eenheden as $sleutel => $eenheid ) {
        $ids = arrahma_eenheid_ids( $eenheid );
        if ( ! $ids && $bestuur ) continue; // bestuur: lege groepen overslaan
        $les_vandaag = arrahma_is_lesdag( $eenheid['slot'], $vandaag );
        $open        = $open_per[ $sleutel ] ?? [];
        sort( $open );
        $items[] = [
            'sleutel'  => $sleutel,
            'eenheid'  => $eenheid,
            'aantal'   => count( $ids ),
            'vandaag'  => $les_vandaag,
            'open'     => $open,
            'volgende' => $les_vandaag ? $vandaag : arrahma_zoek_les( $eenheid['slot'], $vandaag, 1 ),
        ];
    }

    $n_vandaag = count( array_filter( $items, function ( $i ) { return $i['vandaag']; } ) );
    $n_open    = array_sum( array_map( function ( $i ) { return count( $i['open'] ); }, $items ) );
    $g_open    = count( array_filter( $items, function ( $i ) { return (bool) $i['open']; } ) );

    $na = '';
    if ( $items ) {
        $na .= '<div class="arr-samenvatting">'
            . '<div><strong>' . $n_vandaag . '</strong><span>' . ( $n_vandaag === 1 ? 'les' : 'lessen' ) . ' vandaag</span></div>'
            . '<div' . ( $n_open ? ' class="is-rood"' : '' ) . '><strong>' . $n_open . '</strong><span>niet ingevuld</span></div>'
            . '<div><strong>' . count( $items ) . '</strong><span>' . ( count( $items ) === 1 ? 'groep' : 'groepen' ) . '</span></div></div>';
    }
    if ( $bestuur ) {
        $na .= '<a class="arr-knop arr-knop-licht-op-donker" href="' . esc_url( arrahma_docenten_link( $basis, [ 'bestuur' => 1 ] ) ) . '">' . arrahma_icoon( 'kal', 18 ) . 'Maandoverzicht</a>';
    }
    arrahma_docenten_kop( [
        'boven'  => 'Assalamu alaykum, ' . $voornaam,
        'rechts' => '<a class="arr-terug" href="' . esc_url( wp_logout_url( $basis ) ) . '">Uitloggen</a>',
        'titel'  => $bestuur ? 'Alle groepen' : 'Mijn groepen',
        'na'     => $na,
    ] );
    arrahma_docenten_melding();

    if ( ! $items ) {
        arrahma_docenten_leeg( 'link', 'Nog niet gekoppeld', 'Je account is klaar, maar het bestuur heeft je nog niet aan een klas gekoppeld.' );
        return;
    }

    // Volgorde binnen een sectie: vandaag, dan met open lessen, dan op volgende les.
    usort( $items, function ( $a, $b ) {
        if ( $a['vandaag'] !== $b['vandaag'] ) return $a['vandaag'] ? -1 : 1;
        if ( (bool) $a['open'] !== (bool) $b['open'] ) return $a['open'] ? -1 : 1;
        return strcmp( (string) $a['volgende'], (string) $b['volgende'] );
    } );
    $secties = [ 'kinderen' => [], 'broeders' => [], 'zusters' => [] ];
    foreach ( $items as $item ) $secties[ arrahma_slot_hoofdgroep( $item['eenheid']['slot'] ) ][] = $item;
    $namen   = [ 'kinderen' => 'Kinderen', 'broeders' => 'Broeders', 'zusters' => 'Zusters' ];
    $veel    = count( $items ) > 8;

    echo '<div class="arr-filters" role="group" aria-label="Filter groepen" id="arr-filters">'
        . '<button type="button" aria-pressed="true" data-f="alle">Alle</button>'
        . '<button type="button" aria-pressed="false" data-f="open" class="is-rood"' . ( $g_open ? '' : ' disabled' ) . '>Niet ingevuld · ' . $g_open . '</button>'
        . '<button type="button" aria-pressed="false" data-f="vandaag"' . ( $n_vandaag ? '' : ' disabled' ) . '>Vandaag · ' . $n_vandaag . '</button></div>';

    echo '<div class="arr-dg-lijst" id="arr-dg-lijst">';
    foreach ( $secties as $sleutel_sectie => $sectie ) {
        if ( ! $sectie ) continue;
        $s_open  = count( array_filter( $sectie, function ( $i ) { return (bool) $i['open']; } ) );
        $s_nu    = count( array_filter( $sectie, function ( $i ) { return $i['vandaag']; } ) );
        $uitklap = ! $veel || $s_open || $s_nu; // bij veel groepen: rustige secties dicht
        echo '<details class="arr-dg"' . ( $uitklap ? ' open' : '' ) . '><summary><b>' . esc_html( $namen[ $sleutel_sectie ] ) . '</b><span>'
            . count( $sectie ) . ' ' . ( count( $sectie ) === 1 ? 'groep' : 'groepen' )
            . ( $s_open ? ' · <span class="arr-open-tel">' . $s_open . ' open</span>' : '' ) . '</span>' . arrahma_icoon( 'rechts', 18 ) . '</summary>';
        foreach ( $sectie as $item ) {
            if ( $item['vandaag'] ) {
                $url   = arrahma_docenten_link( $basis, [ 'eenheid' => $item['sleutel'], 'datum' => $vandaag ] );
                $badge = '<span class="arr-badge is-vandaag">Vandaag</span>';
            } elseif ( $item['open'] ) {
                $url   = arrahma_docenten_link( $basis, [ 'eenheid' => $item['sleutel'], 'datum' => $item['open'][0] ] ); // oudste open les
                $badge = '<span class="arr-badge is-open">' . count( $item['open'] ) . ' open</span>';
            } else {
                $url   = arrahma_docenten_link( $basis, [ 'eenheid' => $item['sleutel'] ] );
                $badge = $item['aantal'] ? '<span class="arr-badge is-ok">✓ bij</span>' : '<span class="arr-badge">leeg</span>';
            }
            $t        = arrahma_slot_tijden( $item['eenheid']['slot'] );
            $wanneer  = $item['vandaag'] ? 'vandaag ' . $t['start'] : ( $item['volgende'] ? date_i18n( 'D j M', strtotime( $item['volgende'] . ' 12:00:00' ) ) : 'geen les gepland' );
            $label    = arrahma_eenheid_regel( $item['eenheid'] ) . ' — ' . ( $item['vandaag'] ? 'les vandaag' : ( $item['open'] ? count( $item['open'] ) . ' les(sen) niet ingevuld' : 'alles ingevuld' ) );
            echo '<a class="arr-rij-g" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $label ) . '"'
                . ( $item['open'] ? ' data-open="1"' : '' ) . ( $item['vandaag'] ? ' data-vandaag="1"' : '' ) . '>'
                . '<b>' . esc_html( arrahma_eenheid_regel( $item['eenheid'] ) ) . '</b>'
                . '<span class="arr-wanneer">' . esc_html( $wanneer ) . '</span>' . $badge . '</a>';
        }
        echo '</details>';
    }
    echo '</div>';
    ?>
    <script>
    (function () {
      var filters = document.getElementById('arr-filters'), lijst = document.getElementById('arr-dg-lijst');
      if (!filters || !lijst) return;
      filters.addEventListener('click', function (e) {
        var knop = e.target.closest('button');
        if (!knop || knop.disabled) return;
        filters.querySelectorAll('button').forEach(function (b) { b.setAttribute('aria-pressed', String(b === knop)); });
        var f = knop.getAttribute('data-f');
        lijst.querySelectorAll('.arr-dg').forEach(function (dg) {
          var zicht = 0;
          dg.querySelectorAll('.arr-rij-g').forEach(function (r) {
            var toon = f === 'alle' || (f === 'open' && r.hasAttribute('data-open')) || (f === 'vandaag' && r.hasAttribute('data-vandaag'));
            r.hidden = !toon;
            if (toon) zicht++;
          });
          dg.hidden = !zicht;
          if (f !== 'alle') dg.open = true;
        });
      });
    })();
    </script>
    <?php
}

// ── Scherm: presentielijst ───────────────────────────────────

function arrahma_docenten_presentie( string $basis, string $sleutel ): void {
    $eenheid = arrahma_mag_eenheid( $sleutel );
    if ( ! $eenheid ) {
        arrahma_docenten_kop( [ 'boven' => 'Docenten', 'titel' => 'Groep niet gevonden', 'terug_url' => $basis, 'terug_label' => 'Groepen' ] );
        arrahma_docenten_leeg( 'link', 'Geen toegang tot deze groep', 'Deze groep bestaat niet of je bent er niet aan gekoppeld.', $basis, 'Naar mijn groepen' );
        return;
    }

    $slot    = $eenheid['slot'];
    $vandaag = arrahma_vandaag();
    $datum   = sanitize_text_field( wp_unslash( $_GET['datum'] ?? '' ) );
    if ( ! arrahma_geldige_datum( $datum ) ) $datum = arrahma_standaard_lesdatum( $slot );

    $w        = arrahma_eenheid_weergave( $eenheid );
    $info     = arrahma_lesdag_info( $slot, $datum );
    $vorige   = arrahma_zoek_les( $slot, $datum, -1 );
    $volgende = arrahma_zoek_les( $slot, $datum, 1 );
    $link     = function ( string $d ) use ( $basis, $sleutel ) { return arrahma_docenten_link( $basis, [ 'eenheid' => $sleutel, 'datum' => $d ] ); };

    $nav = '<div class="arr-datumnav">'
        . ( $vorige ? '<a class="arr-rond" href="' . esc_url( $link( $vorige ) ) . '" aria-label="Vorige les: ' . esc_attr( arrahma_datum_lang( $vorige ) ) . '">' . arrahma_icoon( 'links', 20 ) . '</a>' : '<span class="arr-rond is-uit" aria-hidden="true">' . arrahma_icoon( 'links', 20 ) . '</span>' )
        . '<div class="arr-datum"><strong>' . esc_html( arrahma_datum_titel( $datum ) ) . '</strong><small>' . esc_html( substr( $datum, 0, 4 ) )
        . ( $datum === $vandaag ? ' <span class="arr-pill">Vandaag</span>' : '' ) . '</small></div>'
        . ( $volgende && $volgende <= $vandaag ? '<a class="arr-rond" href="' . esc_url( $link( $volgende ) ) . '" aria-label="Volgende les: ' . esc_attr( arrahma_datum_lang( $volgende ) ) . '">' . arrahma_icoon( 'rechts', 20 ) . '</a>' : '<span class="arr-rond is-uit" aria-hidden="true">' . arrahma_icoon( 'rechts', 20 ) . '</span>' )
        . '</div>';

    arrahma_docenten_kop( [
        'boven'       => $w['boven'],
        'titel'       => $w['titel'],
        'sub'         => $w['sub'],
        'terug_url'   => $basis,
        'terug_label' => 'Groepen',
        'na'          => arrahma_docenten_wissel( $basis, $sleutel, 'lijst', $datum ) . $nav . arrahma_docenten_klasknop( $basis, $eenheid ),
    ] );

    if ( ! $info['les'] ) {
        $soort = arrahma_dag_soort( $slot, $datum );
        $naar  = $volgende ?: $vorige;
        arrahma_docenten_leeg(
            $soort['soort'] === 'vakantie' ? 'zon' : 'klok',
            $soort['soort'] === 'vakantie' ? $soort['vakantie'] : ( $soort['soort'] === 'vervalt' ? 'Deze les vervalt' : 'Geen les op deze dag' ),
            $info['reden'] . ( $naar ? ' De ' . ( $naar === $volgende ? 'volgende' : 'vorige' ) . ' les is ' . arrahma_datum_lang( $naar ) . '.' : '' ),
            $naar ? $link( $naar ) : '', $naar ? 'Naar ' . date_i18n( 'j F', strtotime( $naar . ' 12:00:00' ) ) : ''
        );
        return;
    }
    if ( $datum > $vandaag ) {
        arrahma_docenten_leeg( 'klok', 'Nog niet begonnen', arrahma_datum_titel( $datum ) . ' is nog niet geweest. Presentie opnemen kan vanaf de dag zelf.',
            $link( arrahma_standaard_lesdatum( $slot ) ), 'Naar de laatste les' );
        return;
    }

    $secties = arrahma_eenheid_secties( $eenheid );
    if ( ! arrahma_aantal_in_secties( $secties ) ) {
        arrahma_docenten_leeg( 'link', 'Nog geen leerlingen', 'Er zitten nog geen leerlingen in deze groep. Het bestuur deelt ze in.' );
        return;
    }

    arrahma_docenten_melding();
    $gezet     = arrahma_aanwezigheid_op( $slot, $datum );
    $statussen = arrahma_aanwezig_statussen();

    echo '<div class="arr-voortgang"><div class="arr-voortgang-rij"><span id="arr-teller"></span><span id="arr-open" class="arr-muted"></span></div>'
        . '<div class="arr-balk"><i id="arr-balk"></i></div><div class="arr-telling" id="arr-telling"></div></div>';
    echo '<div class="arr-kies-balk"><label class="arr-kies-alles"><input type="checkbox" id="arr-alles"> Alles selecteren</label>'
        . '<span class="arr-sub" id="arr-kies-tel"></span></div>';
    echo '<div class="arr-st-legenda" aria-hidden="true"><span></span><span>Leerling</span><span>Aanw.</span><span>Laat</span><span>Afgem.</span><span>Afw.</span></div>';
    echo '<div id="arr-presentie" class="arr-presentie" data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-nonce="'
        . esc_attr( wp_create_nonce( 'arrahma_aanwezigheid' ) ) . '" data-datum="' . esc_attr( $datum ) . '">';

    foreach ( $secties as $sectie ) {
        if ( $sectie['titel'] !== '' ) echo '<h3 class="arr-sectie">' . esc_html( $sectie['titel'] ) . '</h3>';
        if ( ! $sectie['rijen'] ) echo '<p class="arr-sub arr-rij-leeg">Nog geen leerlingen in deze klas.</p>';
        foreach ( $sectie['rijen'] as $rij ) {
            $naam   = trim( $rij->voornaam . ' ' . $rij->achternaam );
            $huidig = $gezet[ (int) $rij->id ] ?? '';
            $detail = arrahma_docenten_link( $basis, [ 'leerling' => (int) $rij->id, 'eenheid' => $sleutel ] );
            echo '<div class="arr-rij" data-id="' . (int) $rij->id . '" data-status="' . esc_attr( $huidig ) . '">'
                . '<label class="arr-kies"><input type="checkbox" class="arr-kies-box" aria-label="Selecteer ' . esc_attr( $naam ) . '"></label>'
                . '<div class="arr-naam"><a href="' . esc_url( $detail ) . '">' . esc_html( $naam ) . '</a><small></small></div>'
                . '<div class="arr-st" role="group" aria-label="Aanwezigheid ' . esc_attr( $naam ) . '">';
            foreach ( $statussen as $waarde => $label ) {
                echo '<button type="button" data-s="' . esc_attr( $waarde ) . '" aria-label="' . esc_attr( $label ) . '" aria-pressed="'
                    . ( $huidig === $waarde ? 'true' : 'false' ) . '">' . arrahma_icoon( $waarde, 18 ) . '<span class="arr-st-tekst">' . esc_html( $label ) . '</span></button>';
            }
            echo '</div></div>';
        }
    }
    echo '</div>';
    echo '<div class="arr-actiebalk" id="arr-actiebalk"><span class="arr-opgeslagen" id="arr-opgeslagen" aria-live="polite">' . arrahma_icoon( 'wolk', 16 ) . '<span>Alles opgeslagen</span></span>'
        . '<div class="arr-bulk" id="arr-bulk" hidden role="region" aria-label="Meerdere leerlingen tegelijk">'
        . '<div class="arr-bulk-kop"><strong id="arr-bulk-tel">0 geselecteerd</strong>'
        . '<button type="button" class="arr-bulk-wis" id="arr-bulk-wis">Selectie wissen</button></div><div class="arr-bulk-knoppen">';
    foreach ( $statussen as $waarde => $label ) {
        echo '<button type="button" class="arr-bulk-knop" data-s="' . esc_attr( $waarde ) . '">' . arrahma_icoon( $waarde, 16 ) . esc_html( $label ) . '</button>';
    }
    echo '<button type="button" class="arr-bulk-knop is-leeg" data-s="">Leegmaken</button></div></div></div>';
    arrahma_docenten_presentie_js();
}

function arrahma_docenten_presentie_js(): void {
    $labels = wp_json_encode( arrahma_aanwezig_statussen() );
    ?>
    <script>
    (function () {
      var root = document.getElementById('arr-presentie');
      if (!root) return;
      var LABEL = <?= $labels ?>, ORDE = Object.keys(LABEL);
      var url = root.getAttribute('data-ajax'), nonce = root.getAttribute('data-nonce'), datum = root.getAttribute('data-datum');
      var rijen = Array.prototype.slice.call(root.querySelectorAll('.arr-rij'));
      var bezig = 0, mislukt = 0;

      function icoon(s) { return '<svg class="arr-i" width="13" height="13" aria-hidden="true"><use href="#arr-i-' + s + '"/></svg>'; }
      function label(rij, fout) {
        var s = rij.getAttribute('data-status'), sm = rij.querySelector('small');
        sm.className = fout ? 'is-fout' : (s ? 't-' + s : 'is-leeg');
        sm.textContent = fout || (s ? LABEL[s] : 'Nog niet ingevuld');
      }
      function tel() {
        var n = 0, per = {};
        rijen.forEach(function (r) { var s = r.getAttribute('data-status'); if (s) { n++; per[s] = (per[s] || 0) + 1; } });
        document.getElementById('arr-teller').innerHTML = '<b>' + n + '</b> van <b>' + rijen.length + '</b> ingevuld';
        document.getElementById('arr-open').textContent = rijen.length - n ? (rijen.length - n) + ' open' : 'compleet';
        document.getElementById('arr-balk').style.width = Math.round(n / Math.max(rijen.length, 1) * 100) + '%';
        document.getElementById('arr-telling').innerHTML = ORDE.filter(function (s) { return per[s]; }).map(function (s) {
          return '<span class="arr-chip c-' + s + '">' + icoon(s) + per[s] + ' ' + LABEL[s].toLowerCase() + '</span>';
        }).join('');
      }
      function melding() {
        var el = document.getElementById('arr-opgeslagen'), tekst = el.querySelector('span');
        el.className = 'arr-opgeslagen' + (mislukt ? ' is-fout' : '');
        tekst.textContent = bezig ? 'Opslaan…' : (mislukt ? 'Niet alles opgeslagen' : 'Alles opgeslagen');
      }
      function toon(rij, status) {
        rij.setAttribute('data-status', status);
        Array.prototype.forEach.call(rij.querySelectorAll('.arr-st button'), function (b) {
          b.setAttribute('aria-pressed', b.getAttribute('data-s') === status ? 'true' : 'false');
        });
        label(rij, ''); tel();
      }
      function fout(bericht) { var e = new Error(bericht); e.eigen = true; return e; }

      function opslaan(rij, status) {
        var vorige = rij.getAttribute('data-status') || '';
        if (rij.classList.contains('is-fout')) { rij.classList.remove('is-fout'); mislukt = Math.max(0, mislukt - 1); }
        toon(rij, status);
        rij.classList.add('is-bezig'); bezig++; melding();

        var fd = new FormData();
        fd.append('action', 'arrahma_aanwezigheid');
        fd.append('nonce', nonce);
        fd.append('inschrijving_id', rij.getAttribute('data-id'));
        fd.append('datum', datum);
        fd.append('status', status);

        return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) {
            return r.json().catch(function () {
              throw fout(r.status === 400 || r.status === 403
                ? 'Je sessie is verlopen. Herlaad de pagina.'
                : 'Opslaan mislukt. Tik opnieuw.');
            });
          })
          .then(function (res) {
            if (!res || !res.success) throw fout(res && res.data && res.data.bericht ? res.data.bericht : 'Opslaan mislukt. Tik opnieuw.');
          })
          .catch(function (e) {
            toon(rij, vorige);
            rij.classList.add('is-fout'); mislukt++;
            label(rij, e && e.eigen ? e.message : 'Geen verbinding — niet opgeslagen. Tik opnieuw.');
          })
          .then(function () { rij.classList.remove('is-bezig'); bezig--; melding(); });
      }

      root.addEventListener('click', function (ev) {
        var knop = ev.target.closest('.arr-st button');
        if (!knop || !root.contains(knop)) return;
        var rij = knop.closest('.arr-rij');
        if (rij.classList.contains('is-bezig')) return;
        // Nog eens tikken op de gekozen status wist hem weer.
        opslaan(rij, knop.getAttribute('aria-pressed') === 'true' ? '' : knop.getAttribute('data-s'));
      });

      // ── Selectie: vinkje per leerling, "Alles selecteren" en een bulkbalk onderaan
      var alles = document.getElementById('arr-alles');
      var bulk = document.getElementById('arr-bulk');
      var bulkTel = document.getElementById('arr-bulk-tel');
      var kiesTel = document.getElementById('arr-kies-tel');
      function vakje(rij) { return rij.querySelector('.arr-kies-box'); }
      function gekozen() { return rijen.filter(function (r) { return vakje(r) && vakje(r).checked; }); }

      function toonSelectie() {
        var n = gekozen().length;
        bulk.hidden = n === 0;
        document.getElementById('arr-actiebalk').classList.toggle('is-bulk', n > 0);
        bulkTel.textContent = n + ' geselecteerd';
        kiesTel.textContent = n ? n + ' van ' + rijen.length + ' geselecteerd' : '';
        alles.checked = n > 0 && n === rijen.length;
        alles.indeterminate = n > 0 && n < rijen.length;
      }

      root.addEventListener('change', function (ev) {
        if (ev.target.classList.contains('arr-kies-box')) toonSelectie();
      });
      alles.addEventListener('change', function () {
        rijen.forEach(function (r) { if (vakje(r)) vakje(r).checked = alles.checked; });
        toonSelectie();
      });
      document.getElementById('arr-bulk-wis').addEventListener('click', function () {
        rijen.forEach(function (r) { if (vakje(r)) vakje(r).checked = false; });
        toonSelectie();
      });

      bulk.addEventListener('click', function (ev) {
        var knop = ev.target.closest('.arr-bulk-knop');
        if (!knop) return;
        var keuze = gekozen();
        if (!keuze.length) return;
        var status = knop.getAttribute('data-s');
        var knoppen = bulk.querySelectorAll('button');
        knoppen.forEach(function (k) { k.disabled = true; });
        keuze.reduce(function (p, r) { return p.then(function () { return opslaan(r, status); }); }, Promise.resolve())
          .then(function () {
            knoppen.forEach(function (k) { k.disabled = false; });
            keuze.forEach(function (r) { if (vakje(r)) vakje(r).checked = false; });
            toonSelectie();
          });
      });

      toonSelectie();

      rijen.forEach(function (r) { label(r, ''); });
      tel(); melding();
    })();
    </script>
    <?php
}

// ── Scherm: klassen beheren (coördinator of bestuur, binnen /docenten) ──

add_action( 'template_redirect', 'arrahma_verwerk_beheer_post' );

/** Verwerkt de klasformulieren van /docenten; dezelfde regels als de beheerpagina in wp-admin. */
function arrahma_verwerk_beheer_post(): void {
    if ( empty( $_POST['arrahma_beheer_actie'] ) || ! is_user_logged_in() ) return;

    $actie = sanitize_key( wp_unslash( $_POST['arrahma_beheer_actie'] ) );
    $slot  = sanitize_key( wp_unslash( $_POST['slot'] ?? '' ) );
    $basis = get_permalink( get_queried_object_id() ) ?: arrahma_docenten_url();
    $terug = arrahma_docenten_link( $basis, [ 'beheer' => 'klassen', 'slot' => $slot ] );
    $nonce = sanitize_text_field( wp_unslash( $_POST['_arrahma_nonce'] ?? '' ) );

    if ( ! arrahma_mag_klassen( $slot ) ) {
        $melding = [ false, 'Je mag de klassen van deze groep niet beheren.' ];
    } elseif ( ! wp_verify_nonce( $nonce, 'arrahma_beheer_' . $slot ) ) {
        $melding = [ false, 'De pagina was verlopen. Probeer het opnieuw.' ];
    } elseif ( ! in_array( $actie, [ 'klas_toevoegen', 'klas_hernoemen', 'klas_verwijderen', 'indeling_opslaan', 'indeling_bulk' ], true ) ) {
        $melding = [ false, 'Onbekende actie.' ];
    } else {
        // Een coördinator mag alleen klassen van zijn eigen blok raken.
        $klas = sanitize_key( wp_unslash( $_POST['klas'] ?? '' ) );
        $melding = ( $klas !== '' && ! isset( arrahma_klassen_van_slot( $slot )[ $klas ] ) )
            ? [ false, 'Deze klas hoort niet bij deze groep.' ]
            : arrahma_klassen_actie( $actie );
    }

    set_transient( 'arrahma_beheer_melding_' . get_current_user_id(), $melding, 60 );
    wp_safe_redirect( $terug );
    exit;
}

function arrahma_docenten_klassen( string $basis, string $slot ): void {
    if ( ! isset( arrahma_roster_labels()[ $slot ] ) || ! arrahma_mag_klassen( $slot ) ) {
        arrahma_docenten_kop( [ 'boven' => 'Klassen', 'titel' => 'Geen toegang', 'terug_url' => $basis, 'terug_label' => 'Groepen' ] );
        arrahma_docenten_leeg( 'link', 'Klassen beheren kan hier niet', 'Deze groep bestaat niet, of je account mag de klassen ervan niet beheren. Vraag het bestuur om coördinatorrechten.', $basis, 'Naar mijn groepen' );
        return;
    }

    $klassen    = arrahma_klassen_van_slot( $slot );
    $leerlingen = arrahma_leerlingen_van_slot( $slot );
    $velden     = function ( string $actie ) use ( $slot ) {
        return '<input type="hidden" name="arrahma_beheer_actie" value="' . esc_attr( $actie ) . '">'
            . '<input type="hidden" name="slot" value="' . esc_attr( $slot ) . '">'
            . '<input type="hidden" name="_arrahma_nonce" value="' . esc_attr( wp_create_nonce( 'arrahma_beheer_' . $slot ) ) . '">';
    };

    arrahma_docenten_kop( [
        'boven'       => arrahma_slot_doelgroep( $slot ),
        'titel'       => 'Klassen',
        'sub'         => arrahma_roster_label( $slot ) . ' · ' . count( $leerlingen ) . ' leerlingen',
        'terug_url'   => arrahma_docenten_link( $basis, [ 'eenheid' => $slot ] ),
        'terug_label' => 'Groep',
    ] );

    $sleutel_melding = 'arrahma_beheer_melding_' . get_current_user_id();
    $melding         = get_transient( $sleutel_melding );
    if ( is_array( $melding ) ) {
        delete_transient( $sleutel_melding );
        echo '<div class="arr-melding' . ( $melding[0] ? ' is-ok' : '' ) . '" role="status">' . esc_html( $melding[1] ) . '</div>';
    }

    echo '<div class="arr-inhoud">';
    echo '<section class="arr-kaart"><h3 class="arr-kaart-kop">Klassen in dit blok</h3>';
    if ( ! $klassen ) {
        echo '<p class="arr-sub">Nog geen klassen. Zonder klassen is het hele blok één klas; maak klassen aan zodra meerdere docenten dit blok verdelen.</p>';
    }
    foreach ( $klassen as $id => $naam ) {
        $aantal = count( array_filter( $leerlingen, function ( $r ) use ( $id ) { return (string) $r->klas === $id; } ) );
        echo '<div class="arr-klasrij">'
            . '<form method="post" class="arr-klas-naam">' . $velden( 'klas_hernoemen' )
            . '<input type="hidden" name="klas" value="' . esc_attr( $id ) . '">'
            . '<label class="arr-sr" for="arr-klas-' . esc_attr( $id ) . '">Naam van de klas</label>'
            . '<input id="arr-klas-' . esc_attr( $id ) . '" type="text" name="naam" value="' . esc_attr( $naam ) . '" maxlength="' . ARRAHMA_KLASNAAM_MAX . '" required>'
            . '<button type="submit" class="arr-knop arr-knop-rand arr-knop-klein">Naam opslaan</button></form>'
            . '<span class="arr-badge">' . (int) $aantal . ' lln</span>'
            . '<form method="post" onsubmit="return confirm(\'Klas verwijderen? De leerlingen komen bij Nog niet in een klas te staan; de aanwezigheid blijft bewaard.\')">' . $velden( 'klas_verwijderen' )
            . '<input type="hidden" name="klas" value="' . esc_attr( $id ) . '">'
            . '<button type="submit" class="arr-knop arr-knop-gevaar arr-knop-klein">Verwijderen</button></form></div>';
    }
    echo '<form method="post" class="arr-klas-nieuw">' . $velden( 'klas_toevoegen' )
        . '<label class="arr-sr" for="arr-klas-nieuw">Naam van de nieuwe klas</label>'
        . '<input id="arr-klas-nieuw" type="text" name="naam" placeholder="Bijv. Klas A" maxlength="' . ARRAHMA_KLASNAAM_MAX . '" required>'
        . '<button type="submit" class="arr-knop arr-knop-goud arr-knop-klein">Klas toevoegen</button></form></section>';

    if ( $klassen && $leerlingen ) {
        // Zelfde patroon als de presentielijst: aanvinken en onderaan in één keer in een klas zetten.
        $per_klas = [ '' => 0 ];
        foreach ( $klassen as $id => $naam ) $per_klas[ $id ] = 0;
        foreach ( $leerlingen as $rij ) {
            $k = isset( $klassen[ (string) $rij->klas ] ) ? (string) $rij->klas : '';
            $per_klas[ $k ]++;
        }

        echo '<section class="arr-kaart"><h3 class="arr-kaart-kop">Leerlingen indelen</h3>';
        echo '<div class="arr-filters" role="group" aria-label="Filter leerlingen" id="arr-klas-filters">'
            . '<button type="button" aria-pressed="true" data-f="alle">Alle · ' . count( $leerlingen ) . '</button>'
            . '<button type="button" aria-pressed="false" class="is-rood" data-f="geen"' . ( $per_klas[''] ? '' : ' disabled' ) . '>Nog niet ingedeeld · ' . (int) $per_klas[''] . '</button>';
        foreach ( $klassen as $id => $naam ) {
            echo '<button type="button" aria-pressed="false" data-f="' . esc_attr( $id ) . '">' . esc_html( $naam ) . ' · ' . (int) $per_klas[ $id ] . '</button>';
        }
        echo '</div>';

        echo '<form method="post" id="arr-indeling">' . $velden( 'indeling_bulk' )
            . '<div class="arr-kies-balk"><label class="arr-kies-alles"><input type="checkbox" id="arr-klas-alles"> Alles selecteren</label>'
            . '<span class="arr-sub" id="arr-klas-tel"></span></div><div id="arr-klas-lijst">';
        foreach ( $leerlingen as $rij ) {
            $naam   = trim( $rij->voornaam . ' ' . $rij->achternaam );
            $huidig = isset( $klassen[ (string) $rij->klas ] ) ? (string) $rij->klas : '';
            echo '<label class="arr-lrij" data-klas="' . esc_attr( $huidig !== '' ? $huidig : 'geen' ) . '">'
                . '<span class="arr-kies"><input type="checkbox" name="leerlingen[]" value="' . (int) $rij->id . '" aria-label="Selecteer ' . esc_attr( $naam ) . '"></span>'
                . '<span class="arr-lnaam"><b>' . esc_html( $naam ) . '</b><span>' . esc_html( arrahma_niveau_label( $rij->niveau ) ) . '</span></span>'
                . '<span class="arr-badge' . ( $huidig !== '' ? ' is-ok' : ' is-open' ) . '">' . esc_html( $huidig !== '' ? $klassen[ $huidig ] : 'Geen klas' ) . '</span></label>';
        }
        echo '</div>';

        // Eén keuzelijst in plaats van een knop per klas: zo blijft de balk even groot bij 2 of bij 20 klassen.
        echo '<div class="arr-bulk arr-klas-bulk" id="arr-klas-bulk" role="region" aria-label="Meerdere leerlingen tegelijk">'
            . '<div class="arr-bulk-kop"><strong id="arr-klas-bulk-tel">0 geselecteerd</strong>'
            . '<button type="button" class="arr-bulk-wis" id="arr-klas-wis">Selectie wissen</button></div>'
            . '<div class="arr-bulk-actie"><label class="arr-sr" for="arr-doel">Verplaatsen naar</label>'
            . '<select id="arr-doel" name="doel"><option value="" selected>Verplaatsen naar…</option>';
        foreach ( $klassen as $id => $naam ) {
            echo '<option value="' . esc_attr( $id ) . '">' . esc_html( $naam ) . '</option>';
        }
        echo '<option value="__geen">Uit de klas halen</option></select>'
            . '<button type="submit" class="arr-bulk-knop is-primair" id="arr-klas-doen" disabled>Verplaatsen</button>'
            . '</div></div></form></section>';
        arrahma_docenten_klassen_js();
    }

    echo '<p class="arr-sub">Docenten koppelen aan een klas doet het bestuur in wp-admin, onder <em>Inschrijvingen → Klassen &amp; docenten</em>.</p></div>';
}

/** Selectie en filters op het klassenscherm; zonder JavaScript blijft alles zichtbaar en werkt het formulier gewoon. */
function arrahma_docenten_klassen_js(): void {
    ?>
    <script>
    (function () {
      var lijst = document.getElementById('arr-klas-lijst');
      var balk = document.getElementById('arr-klas-bulk');
      if (!lijst || !balk) return;
      var alles = document.getElementById('arr-klas-alles');
      var tel = document.getElementById('arr-klas-tel');
      var bulkTel = document.getElementById('arr-klas-bulk-tel');
      var vakjes = Array.prototype.slice.call(lijst.querySelectorAll('input[type=checkbox]'));

      function zichtbaar() { return vakjes.filter(function (v) { return !v.closest('.arr-lrij').hidden; }); }
      function ververs() {
        var zicht = zichtbaar();
        var n = zicht.filter(function (v) { return v.checked; }).length;
        balk.hidden = n === 0;
        bulkTel.textContent = n + ' geselecteerd';
        tel.textContent = n ? n + ' van ' + zicht.length + ' geselecteerd' : zicht.length + ' leerlingen';
        alles.checked = n > 0 && n === zicht.length;
        alles.indeterminate = n > 0 && n < zicht.length;
      }

      var doel = document.getElementById('arr-doel');
      var doen = document.getElementById('arr-klas-doen');
      function knopStand() { doen.disabled = !doel.value; }
      doel.addEventListener('change', knopStand);
      knopStand();

      lijst.addEventListener('change', ververs);
      alles.addEventListener('change', function () {
        zichtbaar().forEach(function (v) { v.checked = alles.checked; });
        ververs();
      });
      document.getElementById('arr-klas-wis').addEventListener('click', function () {
        vakjes.forEach(function (v) { v.checked = false; });
        ververs();
      });

      var filters = document.getElementById('arr-klas-filters');
      if (filters) filters.addEventListener('click', function (ev) {
        var knop = ev.target.closest('button');
        if (!knop || knop.disabled) return;
        filters.querySelectorAll('button').forEach(function (b) { b.setAttribute('aria-pressed', String(b === knop)); });
        var f = knop.getAttribute('data-f');
        lijst.querySelectorAll('.arr-lrij').forEach(function (rij) {
          var toon = f === 'alle' || rij.getAttribute('data-klas') === f;
          rij.hidden = !toon;
          if (!toon) rij.querySelector('input').checked = false;   // verborgen leerlingen niet stiekem meesturen
        });
        ververs();
      });

      ververs();
    })();
    </script>
    <?php
}

// ── Scherm: kalender per groep ───────────────────────────────

/** Maand uit de URL (JJJJ-MM) of de maand van de standaardles. */
function arrahma_maand_uit_request( string $standaard ): string {
    $maand = sanitize_text_field( wp_unslash( $_GET['maand'] ?? '' ) );
    return preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $maand ) ? $maand : substr( $standaard, 0, 7 );
}

function arrahma_maand_verschuif( string $maand, int $stap ): string {
    return ( new DateTimeImmutable( $maand . '-01' ) )->modify( sprintf( '%+d month', $stap ) )->format( 'Y-m' );
}

function arrahma_maand_naam( string $maand ): string {
    return ucfirst( date_i18n( 'F Y', strtotime( $maand . '-15 12:00:00' ) ) );
}

/** Vakanties die in een maand vallen en voor dit blok gelden. */
function arrahma_vakanties_in_maand( string $maand, string $slot ): array {
    $van = $maand . '-01';
    $tot = ( new DateTimeImmutable( $van ) )->format( 'Y-m-t' );
    return array_values( array_filter( arrahma_vakanties(), function ( $v ) use ( $van, $tot, $slot ) {
        return $v['van'] <= $tot && $v['tot'] >= $van && arrahma_vakantie_geldt( $v, $slot );
    } ) );
}

function arrahma_docenten_kalender( string $basis, string $sleutel ): void {
    $eenheid = arrahma_mag_eenheid( $sleutel );
    if ( ! $eenheid ) {
        arrahma_docenten_presentie( $basis, $sleutel ); // toont de nette "geen toegang"-toestand
        return;
    }
    $slot      = $eenheid['slot'];
    $vandaag   = arrahma_vandaag();
    $standaard = arrahma_standaard_lesdatum( $slot );
    $maand     = arrahma_maand_uit_request( $standaard );
    $eerste    = $maand . '-01';
    $laatste   = ( new DateTimeImmutable( $eerste ) )->format( 'Y-m-t' );
    $w         = arrahma_eenheid_weergave( $eenheid );

    arrahma_docenten_kop( [
        'boven'       => $w['boven'],
        'titel'       => $w['titel'],
        'sub'         => $w['sub'],
        'terug_url'   => $basis,
        'terug_label' => 'Groepen',
        'na'          => arrahma_docenten_wissel( $basis, $sleutel, 'kalender', $standaard ) . arrahma_docenten_klasknop( $basis, $eenheid ),
    ] );

    $ids   = arrahma_eenheid_ids( $eenheid );
    $kaart = arrahma_aanwezigheid_kaart( $eerste, $laatste, [ $slot ] )[ $slot ] ?? [];
    $maandlink = function ( string $m ) use ( $basis, $sleutel ) {
        return arrahma_docenten_link( $basis, [ 'eenheid' => $sleutel, 'weergave' => 'kalender', 'maand' => $m ] );
    };

    echo '<div class="arr-kal-layout"><section class="arr-maand" aria-label="' . esc_attr( arrahma_maand_naam( $maand ) ) . '"><div class="arr-maand-kop">'
        . '<a class="arr-klein" href="' . esc_url( $maandlink( arrahma_maand_verschuif( $maand, -1 ) ) ) . '" aria-label="Vorige maand">' . arrahma_icoon( 'links', 18 ) . '</a>'
        . '<strong>' . esc_html( arrahma_maand_naam( $maand ) ) . '</strong>'
        . '<a class="arr-klein" href="' . esc_url( $maandlink( arrahma_maand_verschuif( $maand, 1 ) ) ) . '" aria-label="Volgende maand">' . arrahma_icoon( 'rechts', 18 ) . '</a></div>';

    echo '<div class="arr-raster">';
    foreach ( [ 'Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za', 'Zo' ] as $wd ) echo '<div class="arr-wd">' . $wd . '</div>';
    $leeg = (int) ( new DateTimeImmutable( $eerste ) )->format( 'N' ) - 1;
    echo str_repeat( '<div></div>', $leeg );

    for ( $datum = $eerste; $datum <= $laatste; $datum = arrahma_verschuif_datum( $datum, 1 ) ) {
        $dag   = (int) substr( $datum, 8, 2 );
        $soort = arrahma_dag_soort( $slot, $datum );
        $cls   = 'arr-dag' . ( $datum === $vandaag ? ' is-vandaag' : '' ) . ( $soort['vakantie'] !== '' ? ' is-vak' : '' );
        $num   = '<span class="arr-num">' . $dag . '</span>';

        if ( $soort['soort'] === 'les' && $datum > $vandaag ) {
            echo '<div class="' . $cls . ' is-toekomst' . ( $soort['extra'] ? ' is-extra' : '' ) . '" title="Nog niet geweest">' . $num . arrahma_glyph( 'toekomst' ) . '</div>';
        } elseif ( $soort['soort'] === 'les' ) {
            $v    = arrahma_voltooiing( $ids, $kaart[ $datum ] ?? [] );
            $mini = ( $v['afwezig'] ? '<span class="arr-mini is-rood">' . (int) $v['afwezig'] . ' afw</span>' : '' )
                  . ( $v['totaal'] ? '<span class="arr-dag-teller">' . (int) $v['ingevuld'] . '/' . (int) $v['totaal'] . '</span>' : '' );
            $url  = arrahma_docenten_link( $basis, [ 'eenheid' => $sleutel, 'datum' => $datum ] );
            echo '<a class="' . $cls . ' is-les' . ( $soort['extra'] ? ' is-extra' : '' ) . '" href="' . esc_url( $url ) . '" aria-label="'
                . esc_attr( arrahma_datum_lang( $datum ) . ': ' . arrahma_staat_label( $v ) ) . '">' . $num . arrahma_glyph( $v['staat'] ) . $mini . '</a>';
        } elseif ( $soort['soort'] === 'vervalt' ) {
            echo '<div class="' . $cls . ' is-vervalt" title="Les vervalt">' . $num . arrahma_glyph( 'vervalt' ) . '<span class="arr-mini">vervalt</span></div>';
        } elseif ( $soort['soort'] === 'vakantie' ) {
            echo '<div class="' . $cls . '" title="' . esc_attr( $soort['vakantie'] ) . '">' . $num . '<span class="arr-mini">vakantie</span></div>';
        } else {
            echo '<div class="' . $cls . '">' . $num . '</div>';
        }
    }
    echo '</div>';
    foreach ( arrahma_vakanties_in_maand( $maand, $slot ) as $v ) {
        echo '<p class="arr-vak-label">' . arrahma_icoon( 'zon', 16 ) . esc_html( $v['naam'] . ' · ' . date_i18n( 'j M', strtotime( $v['van'] . ' 12:00:00' ) )
            . ' t/m ' . date_i18n( 'j M', strtotime( $v['tot'] . ' 12:00:00' ) ) ) . '</p>';
    }
    echo '</section><aside class="arr-kal-zij">';

    echo '<div class="arr-klegenda">'
        . '<span>' . arrahma_glyph( 'klaar' ) . 'Compleet</span><span>' . arrahma_glyph( 'deels' ) . 'Deels ingevuld</span>'
        . '<span>' . arrahma_glyph( 'leeg' ) . 'Niet ingevuld</span><span>' . arrahma_glyph( 'toekomst' ) . 'Nog niet geweest</span>'
        . '<span>' . arrahma_glyph( 'vervalt' ) . 'Les vervalt</span><span><i class="arr-vak-staal"></i>Vakantie</span></div>';

    if ( arrahma_is_lesdag( $slot, $standaard ) && $standaard <= $vandaag ) {
        $v   = arrahma_voltooiing( $ids, arrahma_aanwezigheid_op( $slot, $standaard ) );
        $url = arrahma_docenten_link( $basis, [ 'eenheid' => $sleutel, 'datum' => $standaard ] );
        echo '<div class="arr-volgende"><div><b>' . esc_html( arrahma_weekdag_kort( $standaard ) . ' ' . date_i18n( 'j M', strtotime( $standaard . ' 12:00:00' ) )
            . ( $standaard === $vandaag ? ' · vandaag' : ' · laatste les' ) ) . '</b><span>' . esc_html( ucfirst( arrahma_staat_label( $v ) )
            . ( $v['afwezig'] ? ' · ' . $v['afwezig'] . ' afwezig' : '' ) ) . '</span></div>'
            . '<a class="arr-knop arr-knop-goud arr-knop-klein" href="' . esc_url( $url ) . '">' . ( $v['staat'] === 'klaar' ? 'Openen' : 'Verder' ) . '</a></div>';
    }
    echo '</aside></div>';
}

// ── Scherm: leerling ─────────────────────────────────────────

function arrahma_leeftijd( ?string $geboortedatum ): string {
    if ( ! $geboortedatum || ! arrahma_geldige_datum( $geboortedatum ) ) return '';
    $jaren = ( new DateTimeImmutable( $geboortedatum ) )->diff( new DateTimeImmutable( arrahma_vandaag() ) )->y;
    return $jaren . ' jaar';
}

function arrahma_telefoon_link( string $nummer ): string {
    return 'tel:' . preg_replace( '/[^0-9+]/', '', $nummer );
}

function arrahma_docenten_leerling( string $basis, int $id, string $sleutel ): void {
    $rij = arrahma_haal_inschrijving( $id );
    if ( ! arrahma_mag_leerling( $rij ) ) {
        arrahma_docenten_kop( [ 'boven' => 'Docenten', 'titel' => 'Leerling niet gevonden', 'terug_url' => $basis, 'terug_label' => 'Groepen' ] );
        arrahma_docenten_leeg( 'link', 'Geen toegang', 'Deze leerling bestaat niet of zit niet in jouw groep.', $basis, 'Naar mijn groepen' );
        return;
    }

    $naam      = trim( $rij->voornaam . ' ' . $rij->achternaam );
    $slot      = (string) $rij->rooster;
    $eenheid   = $sleutel !== '' ? arrahma_mag_eenheid( $sleutel ) : null;
    $terug     = $eenheid ? arrahma_docenten_link( $basis, [ 'eenheid' => $sleutel ] ) : $basis;
    $klas      = arrahma_klassen_van_slot( $slot )[ (string) ( $rij->klas ?? '' ) ] ?? '';
    $leeftijd  = arrahma_leeftijd( $rij->geboortedatum );
    $dt        = arrahma_slot_dag_tijd( $slot );
    $heeft_cp  = ! empty( $rij->cp_anders ) && ( $rij->cp_voornaam || $rij->cp_telefoon );
    $bel_nr    = $heeft_cp && $rij->cp_telefoon ? $rij->cp_telefoon : $rij->telefoon;
    $bel_label = $heeft_cp && $rij->cp_telefoon && $rij->cp_voornaam ? 'Bel ' . $rij->cp_voornaam : 'Bellen';

    $acties = '<div class="arr-acties">'
        . ( $bel_nr ? '<a class="arr-knop arr-knop-wit" href="' . esc_attr( arrahma_telefoon_link( $bel_nr ) ) . '">' . arrahma_icoon( 'tel', 18 ) . esc_html( $bel_label ) . '</a>' : '' )
        . ( $rij->email ? '<a class="arr-knop arr-knop-glas" href="mailto:' . esc_attr( $rij->email ) . '">' . arrahma_icoon( 'mail', 18 ) . 'Mail</a>' : '' )
        . '</div>';

    arrahma_docenten_kop( [
        'boven'       => arrahma_slot_doelgroep( $slot ) . ' · ' . arrahma_niveau_label( $rij->niveau ),
        'titel'       => $naam,
        'sub'         => implode( ' · ', array_filter( [ $leeftijd, $klas, $dt['dag'] . ' ' . arrahma_slot_tijden( $slot )['start'] ] ) ),
        'terug_url'   => $terug,
        'terug_label' => $eenheid ? arrahma_eenheid_weergave( $eenheid )['titel'] : 'Groepen',
        'avatar'      => arrahma_initialen( $naam ),
        'na'          => $acties,
    ] );
    arrahma_docenten_melding();

    echo '<div class="arr-inhoud arr-leerling-raster">';
    arrahma_docenten_leerling_aanwezigheid( $rij );
    arrahma_docenten_leerling_notities( $rij, $sleutel );

    $gegevens = [];
    if ( $heeft_cp ) {
        $gegevens['Contactpersoon'] = trim( $rij->cp_voornaam . ' ' . $rij->cp_achternaam );
        if ( $rij->cp_telefoon ) $gegevens['Telefoon contactpersoon'] = [ 'tel', $rij->cp_telefoon ];
    }
    if ( $rij->telefoon ) $gegevens['Telefoon'] = [ 'tel', $rij->telefoon ];
    if ( $rij->email )    $gegevens['E-mail']   = [ 'mail', $rij->email ];
    $gegevens['Adres']         = trim( $rij->adres . ', ' . $rij->postcode . ' ' . $rij->woonplaats, ', ' );
    $gegevens['Geboortedatum'] = $rij->geboortedatum ? arrahma_format_date( $rij->geboortedatum ) : '';
    $gegevens['Doelgroep']     = arrahma_category_labels()[ $rij->inschrijving_voor ] ?? $rij->inschrijving_voor;
    $gegevens['Lesmoment']     = arrahma_roster_label( $slot );

    echo '<section class="arr-kaart arr-kaart-contact"><h3 class="arr-kaart-kop">Contact &amp; gegevens</h3><dl class="arr-dl">';
    foreach ( $gegevens as $label => $waarde ) {
        if ( $waarde === '' || $waarde === null ) continue;
        if ( is_array( $waarde ) ) {
            $href = $waarde[0] === 'tel' ? arrahma_telefoon_link( $waarde[1] ) : 'mailto:' . $waarde[1];
            $html = '<a href="' . esc_attr( $href ) . '">' . esc_html( $waarde[1] ) . '</a>';
        } else {
            $html = esc_html( $waarde );
        }
        echo '<dt>' . esc_html( $label ) . '</dt><dd>' . $html . '</dd>';
    }
    echo '</dl></section></div>';
}

function arrahma_docenten_leerling_aanwezigheid( $rij ): void {
    global $wpdb;
    $table = $wpdb->prefix . ARRAHMA_AANWEZIG_TABLE;
    $rows  = $wpdb->get_results( $wpdb->prepare(
        "SELECT lesdatum, status FROM {$table} WHERE inschrijving_id = %d ORDER BY lesdatum DESC",
        (int) $rij->id
    ) );
    $labels  = arrahma_aanwezig_statussen();
    $letters = arrahma_aanwezig_letters();
    $telling = array_fill_keys( array_keys( $labels ), 0 );
    foreach ( $rows as $row ) {
        if ( isset( $telling[ $row->status ] ) ) $telling[ $row->status ]++;
    }

    echo '<section class="arr-kaart arr-kaart-aanw"><h3 class="arr-kaart-kop">Aanwezigheid</h3>';
    if ( ! $rows ) {
        echo '<p class="arr-sub">Nog niets bijgehouden.</p></section>';
        return;
    }
    echo '<div class="arr-telling">';
    foreach ( $telling as $status => $aantal ) {
        echo '<span class="arr-chip c-' . esc_attr( $status ) . '">' . arrahma_icoon( $status, 13 ) . (int) $aantal . ' ' . esc_html( strtolower( $labels[ $status ] ) ) . '</span>';
    }
    echo '</div>';

    $laatste = array_reverse( array_slice( $rows, 0, 8 ) );
    echo '<div class="arr-strip" role="list" aria-label="Laatste ' . count( $laatste ) . ' lessen">';
    foreach ( $laatste as $row ) {
        echo '<i role="listitem" class="c-' . esc_attr( $row->status ) . '" title="' . esc_attr( arrahma_datum_lang( $row->lesdatum ) . ': ' . ( $labels[ $row->status ] ?? $row->status ) ) . '">'
            . esc_html( $letters[ $row->status ] ?? '?' ) . '</i>';
    }
    echo '</div><div class="arr-strip-data"><span>' . esc_html( date_i18n( 'j M', strtotime( $laatste[0]->lesdatum . ' 12:00:00' ) ) ) . '</span><span>laatste '
        . count( $laatste ) . ' lessen</span><span>' . esc_html( date_i18n( 'j M', strtotime( end( $laatste )->lesdatum . ' 12:00:00' ) ) ) . '</span></div>';

    echo '<details class="arr-details"><summary>Alle lessen</summary><ul class="arr-historie">';
    foreach ( $rows as $row ) {
        echo '<li><span>' . esc_html( arrahma_datum_titel( $row->lesdatum ) ) . '</span><span class="arr-chip c-' . esc_attr( $row->status ) . '">'
            . arrahma_icoon( $row->status, 13 ) . esc_html( $labels[ $row->status ] ?? $row->status ) . '</span></li>';
    }
    echo '</ul></details></section>';
}

function arrahma_docenten_leerling_notities( $rij, string $sleutel ): void {
    $id     = (int) $rij->id;
    $velden = function ( string $actie, int $notitie = 0 ) use ( $id, $sleutel ) {
        return '<input type="hidden" name="arrahma_notitie_actie" value="' . esc_attr( $actie ) . '">'
            . '<input type="hidden" name="leerling" value="' . $id . '">'
            . '<input type="hidden" name="eenheid" value="' . esc_attr( $sleutel ) . '">'
            . ( $notitie ? '<input type="hidden" name="notitie" value="' . $notitie . '">' : '' )
            . '<input type="hidden" name="_arrahma_nonce" value="' . esc_attr( wp_create_nonce( 'arrahma_notitie_' . $id ) ) . '">';
    };

    echo '<section class="arr-kaart arr-kaart-notities" id="notities"><h3 class="arr-kaart-kop">Notities</h3>';
    echo '<form method="post" class="arr-notitie-form">' . $velden( 'toevoegen' )
        . '<label class="arr-sr" for="arr-nieuwe-notitie">Nieuwe notitie</label>'
        . '<textarea id="arr-nieuwe-notitie" name="tekst" rows="3" maxlength="' . ARRAHMA_NOTITIE_MAX . '" placeholder="Schrijf een notitie…" required></textarea>'
        . '<div class="arr-notitie-voet"><span class="arr-sub">Zichtbaar voor alle docenten en het bestuur</span><button type="submit" class="arr-knop arr-knop-goud arr-knop-klein">Opslaan</button></div></form>';

    $notities = arrahma_notities_van( $id );
    if ( ! $notities ) echo '<p class="arr-sub">Nog geen notities.</p>';
    foreach ( $notities as $n ) {
        $meta = arrahma_gebruiker_naam( (int) $n->auteur ) . ' · ' . date_i18n( 'j M, H:i', strtotime( $n->aangemaakt_op ) )
              . ( $n->gewijzigd_op ? ' · aangepast' : '' );
        echo '<article class="arr-notitie"><small>' . esc_html( $meta ) . '</small><p>' . nl2br( esc_html( $n->tekst ) ) . '</p>';
        if ( arrahma_mag_notitie_wijzigen( $n ) ) {
            echo '<details class="arr-details"><summary>Aanpassen</summary>'
                . '<form method="post" class="arr-notitie-form">' . $velden( 'wijzigen', (int) $n->id )
                . '<label class="arr-sr" for="arr-notitie-' . (int) $n->id . '">Notitie aanpassen</label>'
                . '<textarea id="arr-notitie-' . (int) $n->id . '" name="tekst" rows="3" maxlength="' . ARRAHMA_NOTITIE_MAX . '" required>' . esc_textarea( $n->tekst ) . '</textarea>'
                . '<div class="arr-notitie-voet"><button type="submit" class="arr-knop arr-knop-goud arr-knop-klein">Opslaan</button></div></form>'
                . '<form method="post" onsubmit="return confirm(\'Deze notitie verwijderen?\')">' . $velden( 'verwijderen', (int) $n->id )
                . '<button type="submit" class="arr-knop arr-knop-gevaar arr-knop-klein">Verwijderen</button></form></details>';
        }
        echo '</article>';
    }
    echo '</section>';
}

// ── Scherm: maandoverzicht bestuur ───────────────────────────

/** Eenheden in het maandoverzicht: per blok met leerlingen óf zijn klassen. */
function arrahma_bestuur_eenheden(): array {
    $aantallen = arrahma_actieve_aantallen_per_slot();
    $uit = [];
    foreach ( array_keys( arrahma_roster_labels() ) as $slot ) {
        if ( empty( $aantallen[ $slot ] ) ) continue;
        $klassen = arrahma_klassen_van_slot( $slot );
        if ( ! $klassen ) {
            $uit[ $slot ] = arrahma_eenheid( $slot );
            continue;
        }
        foreach ( array_keys( $klassen ) as $klas_id ) {
            $eenheid = arrahma_eenheid( $klas_id );
            if ( $eenheid ) $uit[ $klas_id ] = $eenheid;
        }
    }
    return $uit;
}

function arrahma_docenten_bestuur( string $basis ): void {
    $vandaag  = arrahma_vandaag();
    $maand    = arrahma_maand_uit_request( $vandaag );
    $eerste   = $maand . '-01';
    $laatste  = ( new DateTimeImmutable( $eerste ) )->format( 'Y-m-t' );
    $eenheden = arrahma_bestuur_eenheden();
    $kaart    = arrahma_aanwezigheid_kaart( $eerste, $laatste );
    $gestart  = arrahma_eerste_lesdata();
    $link     = function ( string $m ) use ( $basis ) { return arrahma_docenten_link( $basis, [ 'bestuur' => 1, 'maand' => $m ] ); };

    $nav = '<div class="arr-maandnav"><a class="arr-rond" href="' . esc_url( $link( arrahma_maand_verschuif( $maand, -1 ) ) ) . '" aria-label="Vorige maand">' . arrahma_icoon( 'links', 20 ) . '</a>'
        . '<a class="arr-rond" href="' . esc_url( $link( arrahma_maand_verschuif( $maand, 1 ) ) ) . '" aria-label="Volgende maand">' . arrahma_icoon( 'rechts', 20 ) . '</a></div>';
    arrahma_docenten_kop( [
        'boven'       => 'Bestuur · ' . arrahma_maand_naam( $maand ),
        'titel'       => 'Alle groepen',
        'sub'         => 'Klik op een les om de presentielijst te openen.',
        'terug_url'   => $basis,
        'terug_label' => 'Groepen',
        'na'          => $nav,
    ] );

    // Tellingen voor de zijbalk: alleen verstreken lessen van deze maand.
    $lessen = 0; $compleet = 0; $ingevuld = 0; $aanwezig = 0; $open = [];
    $dagen  = [];
    for ( $d = $eerste; $d <= $laatste; $d = arrahma_verschuif_datum( $d, 1 ) ) $dagen[] = $d;

    echo '<div class="arr-bestuur"><div class="arr-tijdlijn-wrap"><table class="arr-tijdlijn"><thead><tr><th class="arr-gl" scope="col">Groep</th>';
    foreach ( $dagen as $d ) {
        $wd = (int) ( new DateTimeImmutable( $d ) )->format( 'N' );
        echo '<th scope="col" class="' . ( $wd >= 6 ? 'is-we' : '' ) . ( $d === $vandaag ? ' is-vandaag' : '' ) . '">'
            . esc_html( date_i18n( 'D', strtotime( $d . ' 12:00:00' ) ) ) . '<br>' . (int) substr( $d, 8, 2 ) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ( $eenheden as $sleutel => $eenheid ) {
        $w   = arrahma_eenheid_weergave( $eenheid );
        $ids = arrahma_eenheid_ids( $eenheid );
        echo '<tr><th scope="row" class="arr-gn"><b>' . esc_html( $w['boven'] . ' · ' . $w['titel'] ) . '</b><span>' . esc_html( $w['sub'] ) . '</span></th>';
        foreach ( $dagen as $d ) {
            $soort = arrahma_dag_soort( $eenheid['slot'], $d );
            $cls   = ( $soort['vakantie'] !== '' ? 'is-vak' : '' ) . ( $d === $vandaag ? ' is-vandaag' : '' );
            $inh   = '';
            if ( $soort['soort'] === 'les' && $d > $vandaag ) {
                $inh = '<span title="' . esc_attr( arrahma_datum_lang( $d ) . ': nog niet geweest' ) . '">' . arrahma_glyph( 'toekomst' ) . '</span>';
            } elseif ( $soort['soort'] === 'les' ) {
                $v = arrahma_voltooiing( $ids, $kaart[ $eenheid['slot'] ][ $d ] ?? [] );
                // Tellen pas vanaf de eerste ingevulde les van dit blok (daarvoor was de groep nog niet begonnen).
                if ( $v['totaal'] && isset( $gestart[ $eenheid['slot'] ] ) && $d >= $gestart[ $eenheid['slot'] ] ) {
                    $lessen++;
                    $ingevuld += $v['ingevuld'];
                    $aanwezig += ( $v['per']['aanwezig'] ?? 0 ) + ( $v['per']['te_laat'] ?? 0 );
                    if ( $v['staat'] === 'klaar' ) {
                        $compleet++;
                    } elseif ( $d < $vandaag ) {
                        $open[] = [ 'w' => $w, 'd' => $d, 'v' => $v, 'sleutel' => $sleutel ];
                    }
                }
                $url = arrahma_docenten_link( $basis, [ 'eenheid' => $sleutel, 'datum' => $d ] );
                $inh = '<a href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $w['titel'] . ', ' . arrahma_datum_lang( $d ) . ': ' . arrahma_staat_label( $v ) ) . '">' . arrahma_glyph( $v['staat'] ) . '</a>';
            } elseif ( $soort['soort'] === 'vervalt' ) {
                $inh = '<span title="Les vervalt">' . arrahma_glyph( 'vervalt' ) . '</span>';
            }
            echo '<td class="' . trim( $cls ) . '">' . $inh . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    // Zijbalk
    echo '<aside class="arr-zij"><div class="arr-cijfers">'
        . '<div class="arr-cijfer"><strong>' . ( $lessen ? round( $compleet / $lessen * 100 ) . '%' : '–' ) . '</strong><span>lessen compleet ingevuld</span></div>'
        . '<div class="arr-cijfer"><strong>' . ( $ingevuld ? round( $aanwezig / $ingevuld * 100 ) . '%' : '–' ) . '</strong><span>aanwezig of te laat</span></div></div>';

    echo '<div class="arr-zij-blok"><h3>Niet ingevuld</h3>';
    usort( $open, function ( $a, $b ) { return strcmp( $b['d'], $a['d'] ); } );
    if ( ! $open ) echo '<p class="arr-sub">Alle verstreken lessen zijn ingevuld.</p>';
    foreach ( array_slice( $open, 0, 8 ) as $o ) {
        $url = arrahma_docenten_link( $basis, [ 'eenheid' => $o['sleutel'], 'datum' => $o['d'] ] );
        echo '<a class="arr-let' . ( $o['v']['staat'] === 'leeg' ? ' is-rood' : '' ) . '" href="' . esc_url( $url ) . '"><b>' . esc_html( $o['w']['boven'] . ' · ' . $o['w']['titel'] ) . '</b>'
            . '<span>' . esc_html( date_i18n( 'D j M', strtotime( $o['d'] . ' 12:00:00' ) ) . ' · ' . arrahma_staat_label( $o['v'] ) ) . '</span></a>';
    }
    echo '</div><div class="arr-zij-blok"><h3>Vaak afwezig zonder bericht</h3>';
    $signaal = arrahma_signaallijst();
    if ( ! $signaal ) echo '<p class="arr-sub">Niemand ' . (int) ARRAHMA_SIGNAAL_DREMPEL . '× of vaker in ' . (int) ARRAHMA_SIGNAAL_WEKEN . ' weken.</p>';
    foreach ( array_slice( $signaal, 0, 8 ) as $r ) {
        $tel = ! empty( $r->cp_anders ) && $r->cp_telefoon ? $r->cp_telefoon : $r->telefoon;
        echo '<a class="arr-let" href="' . esc_url( arrahma_docenten_link( $basis, [ 'leerling' => (int) $r->id ] ) ) . '"><b>'
            . esc_html( trim( $r->voornaam . ' ' . $r->achternaam ) . ' · ' . (int) $r->aantal . '×' ) . '</b><span>'
            . esc_html( arrahma_slot_doelgroep( (string) $r->rooster ) . ' · laatst ' . date_i18n( 'j M', strtotime( $r->laatste . ' 12:00:00' ) ) . ( $tel ? ' · ' . $tel : '' ) ) . '</span></a>';
    }
    echo '</div><p class="arr-sub"><a href="' . esc_url( admin_url( 'admin.php?page=arrahma-aanwezigheid' ) ) . '">Exports en overzicht per groep in wp-admin</a></p></aside></div>';

    echo '<div class="arr-klegenda arr-klegenda-breed">'
        . '<span>' . arrahma_glyph( 'klaar' ) . 'Compleet</span><span>' . arrahma_glyph( 'deels' ) . 'Deels ingevuld</span>'
        . '<span>' . arrahma_glyph( 'leeg' ) . 'Niet ingevuld</span><span>' . arrahma_glyph( 'toekomst' ) . 'Nog niet geweest</span>'
        . '<span>' . arrahma_glyph( 'vervalt' ) . 'Les vervalt</span><span><i class="arr-vak-staal"></i>Vakantie</span></div>';
}

// ── Iconen en huisstijl ──────────────────────────────────────

/** SVG-sprite; elke status heeft een eigen vorm, zodat kleur nooit de enige aanwijzing is. */
function arrahma_docenten_iconen(): string {
    return '<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><defs>'
        . '<symbol id="arr-i-aanwezig" viewBox="0 0 24 24"><path d="M5 12.5l4.2 4.2L19 7" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></symbol>'
        . '<symbol id="arr-i-te_laat" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.2" fill="none" stroke="currentColor" stroke-width="2.2"/><path d="M12 7.5V12l3 2" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></symbol>'
        . '<symbol id="arr-i-afgemeld" viewBox="0 0 24 24"><rect x="3.8" y="6" width="16.4" height="12" rx="2.2" fill="none" stroke="currentColor" stroke-width="2.1"/><path d="M4.5 7l7.5 6 7.5-6" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linejoin="round"/></symbol>'
        . '<symbol id="arr-i-afwezig" viewBox="0 0 24 24"><path d="M7 7l10 10M17 7L7 17" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/></symbol>'
        . '<symbol id="arr-i-links" viewBox="0 0 24 24"><path d="M14.5 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></symbol>'
        . '<symbol id="arr-i-rechts" viewBox="0 0 24 24"><path d="M9.5 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></symbol>'
        . '<symbol id="arr-i-lijst" viewBox="0 0 24 24"><path d="M9 7h11M9 12h11M9 17h11" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/><circle cx="4.5" cy="7" r="1.4" fill="currentColor"/><circle cx="4.5" cy="12" r="1.4" fill="currentColor"/><circle cx="4.5" cy="17" r="1.4" fill="currentColor"/></symbol>'
        . '<symbol id="arr-i-kal" viewBox="0 0 24 24"><rect x="3.5" y="5" width="17" height="15" rx="3" fill="none" stroke="currentColor" stroke-width="2.1"/><path d="M3.5 10h17M8 3v4M16 3v4" stroke="currentColor" stroke-width="2.1" stroke-linecap="round"/></symbol>'
        . '<symbol id="arr-i-tel" viewBox="0 0 24 24"><path d="M6.6 3.8l2.6-.4 1.6 4.2-1.9 1.4a11 11 0 005.5 5.5l1.4-1.9 4.2 1.6-.4 2.6a2 2 0 01-2.1 1.7A15.6 15.6 0 014.9 5.9a2 2 0 011.7-2.1z" fill="currentColor"/></symbol>'
        . '<symbol id="arr-i-mail" viewBox="0 0 24 24"><rect x="3.5" y="6" width="17" height="12" rx="2.2" fill="none" stroke="currentColor" stroke-width="2.1"/><path d="M4.5 7.3l7.5 5.7 7.5-5.7" fill="none" stroke="currentColor" stroke-width="2.1"/></symbol>'
        . '<symbol id="arr-i-wolk" viewBox="0 0 24 24"><path d="M7 18h10a4 4 0 00.6-8A6 6 0 006.2 9.3 4.4 4.4 0 007 18z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M9 13.5l2 2 4-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>'
        . '<symbol id="arr-i-zon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 2.5v2.4M12 19.1v2.4M2.5 12h2.4M19.1 12h2.4M5.3 5.3L7 7M17 17l1.7 1.7M5.3 18.7L7 17M17 7l1.7-1.7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></symbol>'
        . '<symbol id="arr-i-link" viewBox="0 0 24 24"><path d="M10 14a4 4 0 005.7 0l3-3a4 4 0 00-5.7-5.7l-1 1M14 10a4 4 0 00-5.7 0l-3 3a4 4 0 005.7 5.7l1-1" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round"/></symbol>'
        . '<symbol id="arr-i-klok" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 7.5V12l3 2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></symbol>'
        . '<symbol id="arr-g-leeg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8" fill="#fff" stroke="#98a4a9" stroke-width="2"/></symbol>'
        . '<symbol id="arr-g-deels" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8" fill="#fff" stroke="#db9f30" stroke-width="2"/><path d="M12 4a8 8 0 010 16z" fill="#db9f30"/></symbol>'
        . '<symbol id="arr-g-klaar" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" fill="#2e7d32"/><path d="M7.5 12.3l3 3 6-6.3" fill="none" stroke="#fff" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"/></symbol>'
        . '<symbol id="arr-g-toekomst" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8" fill="none" stroke="#b5bfc2" stroke-width="2" stroke-dasharray="2.6 2.6"/></symbol>'
        . '<symbol id="arr-g-vervalt" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8" fill="none" stroke="#b5bfc2" stroke-width="2"/><path d="M6.5 17.5l11-11" stroke="#b5bfc2" stroke-width="2"/></symbol>'
        . '</defs></svg>';
}

function arrahma_docenten_css(): string {
    // Huisstijl van vereniging-arrahma.nl: Open Sans, leisteen #323F44 (header/footer), goud #DB9F30
    // (knoppen), tekst #272727, pilvormige knoppen. Roboto Slab alleen voor datums, zoals de koranteksten op de site.
    // Alles hangt onder .arr-doc, zodat het thema er niet in kan grijpen en andersom.
    // Lettertypen: de site laadt Open Sans en Roboto Slab zelf (Elementor, lokaal); geen Google-verzoek hier.
    return '<style>
.arr-doc{--leisteen:#323f44;--leisteen-diep:#263034;--leisteen-zacht:#e7ebec;--goud:#db9f30;--goud-diep:#a8741a;--goud-zacht:#f8ecd3;
  --inkt:#272727;--muted:#66727a;--lijn:#e2e6e7;--vlak:#fff;--grond:#eef1f1;--groen:#2e7d32;--groen-zacht:#e3f1e4;--rood:#c62828;--rood-zacht:#fbe6e6;
  --sans:"Open Sans",system-ui,-apple-system,"Segoe UI",sans-serif;--slab:"Roboto Slab",Georgia,serif;
  --breedte:640px;width:100%;margin:0;background:var(--grond);color:var(--inkt);font-family:var(--sans);font-size:15px;line-height:1.45;
  min-height:100vh;min-height:100dvh;-webkit-font-smoothing:antialiased}
.arr-doc .arr-hoofd{max-width:var(--breedte);margin:0 auto;padding-bottom:24px}
.arr-doc *{box-sizing:border-box}
.arr-doc a{color:var(--leisteen)}
.arr-doc h2,.arr-doc h3{font-family:var(--sans);letter-spacing:0;text-transform:none}
.arr-doc :focus-visible{outline:3px solid rgba(219,159,48,.6);outline-offset:2px}
.arr-doc .arr-i{flex:none;display:inline-block;vertical-align:middle}
.arr-doc .arr-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.arr-doc .arr-sub{color:var(--muted);font-size:.84rem;margin:0}
.arr-doc .arr-muted{color:var(--muted)}

.arr-doc .arr-kop{background:var(--leisteen-diep);color:#fff;padding:16px max(18px,calc((100% - var(--breedte))/2 + 18px)) 18px;display:grid;gap:12px}
.arr-doc .arr-kop-na{display:grid;gap:12px}
.arr-doc .arr-kop-rij{display:flex;justify-content:space-between;align-items:center;gap:8px;min-height:24px}
.arr-doc .arr-terug{display:inline-flex;align-items:center;gap:4px;color:#cfd6d8;font-size:.84rem;font-weight:600;text-decoration:none}
.arr-doc .arr-terug:hover{color:#fff}
.arr-doc .arr-boven{font-size:.7rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:var(--goud);text-align:right}
.arr-doc .arr-kop-rij>.arr-boven:first-child{text-align:left}
.arr-doc .arr-kop-titel{display:flex;gap:14px;align-items:center}
.arr-doc .arr-kop h2{margin:0;color:#fff;font-size:1.35rem;font-weight:700;line-height:1.2;text-wrap:balance}
.arr-doc .arr-kop-sub{margin:2px 0 0;color:#b9c3c6;font-size:.85rem}
.arr-doc .arr-wissel{display:grid;grid-template-columns:1fr 1fr;background:rgba(255,255,255,.08);border-radius:50px;padding:4px;gap:4px}
.arr-doc .arr-wissel a{display:flex;justify-content:center;align-items:center;gap:6px;min-height:38px;border-radius:50px;font-weight:600;font-size:.86rem;color:#cfd6d8;text-decoration:none}
.arr-doc .arr-wissel a[aria-current]{background:#fff;color:var(--leisteen-diep)}
.arr-doc .arr-datumnav{display:grid;grid-template-columns:44px 1fr 44px;align-items:center;gap:8px}
.arr-doc .arr-maandnav{display:flex;gap:8px}
.arr-doc .arr-rond{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;background:rgba(255,255,255,.1);color:#fff;text-decoration:none}
.arr-doc a.arr-rond:hover{background:var(--goud);color:var(--inkt)}
.arr-doc .arr-rond.is-uit{opacity:.3}
.arr-doc .arr-datum{text-align:center}
.arr-doc .arr-datum strong{display:block;font-family:var(--slab);font-weight:600;font-size:1.15rem}
.arr-doc .arr-datum small{color:#b9c3c6;font-size:.8rem}
.arr-doc .arr-pill{display:inline-block;background:var(--goud);color:var(--inkt);font-size:.68rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;border-radius:50px;padding:2px 8px;vertical-align:1px}

.arr-doc .arr-knop{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:48px;padding:0 22px;border-radius:50px;font:700 .92rem/1.2 var(--sans);cursor:pointer;text-align:center;text-decoration:none;border:2px solid transparent;transition:background-color .15s,color .15s,border-color .15s}
.arr-doc button.arr-knop{appearance:none;-webkit-appearance:none;box-shadow:none;text-transform:none;letter-spacing:0}
.arr-doc .arr-knop-goud{background:var(--goud);border-color:var(--goud);color:#fff}
.arr-doc .arr-knop-goud:hover{background:var(--goud-diep);border-color:var(--goud-diep);color:#fff}
.arr-doc .arr-knop-leisteen{background:var(--leisteen);border-color:var(--leisteen);color:#fff}
.arr-doc .arr-knop-leisteen:hover{background:var(--leisteen-diep)}
.arr-doc .arr-knop-leisteen:disabled{opacity:.5;cursor:wait}
.arr-doc .arr-knop-rand{background:#fff;border:1.5px solid var(--leisteen);color:var(--leisteen)}
.arr-doc .arr-knop-rand:hover{background:var(--leisteen);color:#fff}
.arr-doc .arr-knop-wit{background:#fff;color:var(--leisteen-diep);min-height:44px}
.arr-doc .arr-knop-glas{background:rgba(255,255,255,.1);color:#fff;min-height:44px}
.arr-doc .arr-knop-licht-op-donker{background:rgba(255,255,255,.1);color:#fff;min-height:42px;justify-self:start}
.arr-doc .arr-knop-licht-op-donker:hover{background:var(--goud);color:var(--inkt)}
.arr-doc .arr-knop-gevaar{background:#fff;border:1.5px solid var(--rood);color:var(--rood)}
.arr-doc .arr-knop-gevaar:hover{background:var(--rood);color:#fff}
.arr-doc .arr-knop-klein{min-height:40px;padding:0 16px;font-size:.84rem}
.arr-doc .arr-linkje{color:var(--leisteen);font-weight:600;font-size:.88rem;text-align:center;text-decoration-color:var(--goud);text-underline-offset:3px}

.arr-doc .arr-melding{margin:12px 16px 0;padding:10px 14px;border-radius:14px;background:#fbf3e3;border:1px solid #efd4a0;font-size:.9rem}
.arr-doc .arr-melding.is-ok{background:var(--groen-zacht);border-color:#b5d9b8}
.arr-doc .arr-inhoud{padding:16px;display:grid;gap:12px}

.arr-doc .arr-chip{display:inline-flex;align-items:center;gap:4px;border-radius:50px;padding:2px 9px 2px 6px;font-size:.74rem;font-weight:700;font-variant-numeric:tabular-nums;white-space:nowrap}
.arr-doc .c-aanwezig{background:var(--groen-zacht);color:var(--groen)}
.arr-doc .c-te_laat{background:var(--goud-zacht);color:#7a5410}
.arr-doc .c-afgemeld{background:var(--leisteen-zacht);color:var(--leisteen)}
.arr-doc .c-afwezig{background:var(--rood-zacht);color:var(--rood)}
.arr-doc .c-neutraal{background:var(--grond);color:var(--leisteen)}
.arr-doc .arr-telling{display:flex;gap:6px;flex-wrap:wrap}

/* Inloggen */
.arr-doc .arr-login{background:var(--leisteen-diep);min-height:100vh;min-height:100dvh;display:flex;justify-content:center}
.arr-doc .arr-login-kolom{width:100%;max-width:480px;display:flex;flex-direction:column}
.arr-doc .arr-login-top{padding:44px 28px 30px;display:grid;justify-items:center;gap:16px;text-align:center;color:#fff}
.arr-doc .arr-login-top img{width:150px;height:auto}
.arr-doc .arr-login-top p{margin:6px 0 0;color:#b9c3c6;max-width:30ch}
.arr-doc .arr-woordmerk{font-size:1.8rem;letter-spacing:.08em}
.arr-doc .arr-login-top .arr-boven{text-align:center}
.arr-doc .arr-login-kaart{flex:1;background:var(--grond);border-radius:28px 28px 0 0;padding:26px 22px 32px;display:grid;gap:14px;align-content:start}
.arr-doc .arr-login-kaart form{display:grid;gap:14px;margin:0}
.arr-doc .arr-login-kaart form p{margin:0;display:grid;gap:6px}
.arr-doc .arr-login-kaart label{font-size:.82rem;font-weight:700;color:#4b565b;padding-left:14px}
.arr-doc .arr-login-kaart input[type=text],.arr-doc .arr-login-kaart input[type=password]{width:100%;font:inherit;min-height:50px;padding:0 18px;border:1.5px solid #d5dadb;border-radius:50px;background:#fff;color:var(--inkt)}
.arr-doc .arr-login-kaart input[type=text]:focus,.arr-doc .arr-login-kaart input[type=password]:focus{outline:none;border-color:var(--leisteen);box-shadow:0 0 0 3px rgba(219,159,48,.35)}
.arr-doc .arr-login-kaart .login-remember label{display:flex;align-items:center;gap:10px;font-weight:400;font-size:.88rem;padding-left:6px;color:var(--inkt)}
.arr-doc .arr-login-kaart input[type=checkbox]{width:20px;height:20px;accent-color:var(--leisteen)}
.arr-doc .arr-login-kaart input[type=submit]{appearance:none;-webkit-appearance:none;width:100%;min-height:50px;border-radius:50px;border:2px solid var(--goud);background:var(--goud);color:#fff;font:700 .95rem var(--sans);cursor:pointer}
.arr-doc .arr-login-kaart input[type=submit]:hover{background:var(--goud-diep);border-color:var(--goud-diep)}

/* Mijn groepen */
.arr-doc .arr-samenvatting{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.arr-doc .arr-samenvatting div{background:rgba(255,255,255,.08);border-radius:14px;padding:10px 12px}
.arr-doc .arr-samenvatting strong{display:block;font-family:var(--slab);font-size:1.3rem;color:#fff;font-variant-numeric:tabular-nums}
.arr-doc .arr-samenvatting span{font-size:.72rem;color:#b9c3c6}
.arr-doc .arr-samenvatting .is-rood strong{color:#ff9e9e}
.arr-doc .arr-filters{display:flex;gap:6px;overflow-x:auto;padding:12px 16px 8px;scrollbar-width:none}
.arr-doc .arr-filters button{appearance:none;-webkit-appearance:none;margin:0;box-shadow:none;flex:none;min-height:38px;padding:0 14px;border-radius:50px;border:1.5px solid var(--lijn);background:#fff;font:700 .8rem var(--sans);color:var(--leisteen);cursor:pointer;text-transform:none;letter-spacing:0}
.arr-doc .arr-filters button[aria-pressed=true]{background:var(--leisteen);border-color:var(--leisteen);color:#fff}
.arr-doc .arr-filters button.is-rood[aria-pressed=true]{background:var(--rood);border-color:var(--rood)}
.arr-doc .arr-filters button:disabled{opacity:.45;cursor:default}
.arr-doc .arr-dg{background:var(--vlak);border-top:1px solid var(--lijn);border-bottom:1px solid var(--lijn);margin-top:-1px}
.arr-doc .arr-dg summary{list-style:none;display:flex;align-items:center;gap:10px;padding:14px 16px;cursor:pointer;min-height:48px}
.arr-doc .arr-dg summary::-webkit-details-marker{display:none}
.arr-doc .arr-dg summary b{font-size:.95rem}
.arr-doc .arr-dg summary span{font-size:.78rem;color:var(--muted)}
.arr-doc .arr-open-tel{color:var(--rood)!important;font-weight:800}
.arr-doc .arr-dg summary .arr-i{margin-left:auto;transition:transform .2s;color:var(--muted)}
.arr-doc .arr-dg[open] summary .arr-i{transform:rotate(90deg)}
.arr-doc .arr-rij-g{display:flex;align-items:center;gap:10px;padding:11px 16px;min-height:48px;border-top:1px solid var(--lijn);text-decoration:none;color:var(--inkt);font-size:.88rem}
.arr-doc .arr-rij-g:hover{background:var(--grond)}
.arr-doc .arr-rij-g b{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:600}
.arr-doc .arr-wanneer{color:var(--muted);font-size:.76rem;white-space:nowrap}
.arr-doc .arr-lrij .arr-badge{min-width:86px}
.arr-doc .arr-badge{flex:none;min-width:62px;text-align:center;border-radius:50px;padding:3px 8px;font-size:.72rem;font-weight:800;background:var(--grond);color:var(--muted)}
.arr-doc .arr-badge.is-open{background:var(--rood-zacht);color:var(--rood)}
.arr-doc .arr-badge.is-ok{background:var(--groen-zacht);color:var(--groen)}
.arr-doc .arr-badge.is-vandaag{background:var(--goud);color:var(--inkt)}
.arr-doc .arr-rij-g[hidden],.arr-doc .arr-dg[hidden]{display:none}

/* Presentielijst */
.arr-doc .arr-voortgang{background:var(--vlak);padding:12px 18px;border-bottom:1px solid var(--lijn);display:grid;gap:8px}
.arr-doc .arr-voortgang-rij{display:flex;justify-content:space-between;font-size:.85rem;font-variant-numeric:tabular-nums}
.arr-doc .arr-balk{height:6px;border-radius:6px;background:var(--leisteen-zacht);overflow:hidden}
.arr-doc .arr-balk i{display:block;height:100%;width:0;background:var(--goud);border-radius:6px;transition:width .25s}
.arr-doc .arr-st-legenda{display:grid;grid-template-columns:34px 1fr repeat(4,44px);gap:4px;align-items:center;padding:10px 12px 6px 18px;font-size:.62rem;font-weight:700;color:var(--muted);text-align:center;text-transform:uppercase;letter-spacing:.04em}
.arr-doc .arr-st-legenda span:nth-child(2){text-align:left}
.arr-doc .arr-presentie{background:var(--vlak);border-top:1px solid var(--lijn)}
.arr-doc .arr-sectie{margin:0;font-size:.7rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:var(--goud-diep);padding:14px 18px 6px;background:var(--grond);border-bottom:1px solid var(--lijn)}
.arr-doc .arr-rij-leeg{padding:12px 18px}
.arr-doc .arr-rij{display:flex;align-items:center;gap:10px;padding:8px 12px 8px 18px;border-bottom:1px solid var(--lijn);transition:background-color .15s}
.arr-doc .arr-av{flex:none;width:34px;height:34px;border-radius:50%;display:grid;place-items:center;font-size:.72rem;font-weight:800;color:var(--leisteen);background:var(--leisteen-zacht)}
.arr-doc .arr-av-groot{width:52px;height:52px;font-size:1rem;background:var(--goud);color:var(--inkt)}
.arr-doc .arr-naam{flex:1;min-width:0}
.arr-doc .arr-naam a{display:block;font-weight:600;color:var(--inkt);text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.arr-doc .arr-naam a:hover{text-decoration:underline}
.arr-doc .arr-naam small{display:block;font-size:.74rem;font-weight:600;min-height:1.1em}
.arr-doc .arr-naam small.is-leeg{color:var(--muted);font-weight:400}
.arr-doc .arr-naam small.is-fout{color:var(--rood)}
.arr-doc .arr-naam small.t-aanwezig{color:var(--groen)}
.arr-doc .arr-naam small.t-te_laat{color:#7a5410}
.arr-doc .arr-naam small.t-afgemeld{color:var(--leisteen)}
.arr-doc .arr-naam small.t-afwezig{color:var(--rood)}
.arr-doc .arr-st{display:flex;gap:4px}
.arr-doc .arr-st-tekst{display:none}
.arr-doc .arr-st button{appearance:none;-webkit-appearance:none;margin:0;padding:0;box-shadow:none;width:44px;height:44px;border-radius:50px;display:inline-flex;align-items:center;justify-content:center;gap:6px;font:600 .82rem var(--sans);border:1.5px solid var(--lijn);color:#8a969b;background:#fff;cursor:pointer;transition:background-color .15s,color .15s,border-color .15s}
.arr-doc .arr-st button:hover{border-color:var(--leisteen);color:var(--leisteen);background:#fff}
.arr-doc .arr-st button[aria-pressed=true][data-s=aanwezig]{background:var(--groen);border-color:var(--groen);color:#fff}
.arr-doc .arr-st button[aria-pressed=true][data-s=te_laat]{background:var(--goud);border-color:var(--goud);color:var(--inkt)}
.arr-doc .arr-st button[aria-pressed=true][data-s=afgemeld]{background:var(--leisteen);border-color:var(--leisteen);color:#fff}
.arr-doc .arr-st button[aria-pressed=true][data-s=afwezig]{background:var(--rood);border-color:var(--rood);color:#fff}
.arr-doc .arr-rij.is-bezig .arr-st{opacity:.6}
.arr-doc .arr-rij.is-fout{background:#fff8f8;box-shadow:inset 3px 0 0 var(--rood)}
.arr-doc .arr-actiebalk{position:sticky;bottom:0;z-index:2;padding:14px 16px calc(14px + env(safe-area-inset-bottom,0px));background:linear-gradient(to top,var(--grond) 72%,rgba(238,241,241,0));display:flex;gap:10px;align-items:center}
.arr-doc .arr-opgeslagen{flex:1;display:flex;align-items:center;gap:6px;font-size:.82rem;color:var(--muted)}
.arr-doc .arr-opgeslagen .arr-i{color:var(--groen)}
.arr-doc .arr-opgeslagen.is-fout{color:var(--rood)}
.arr-doc .arr-opgeslagen.is-fout .arr-i{color:var(--rood)}

/* Selectie en bulkbalk */
.arr-doc .arr-kies-balk{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:14px 16px 4px;font-size:.86rem}
.arr-doc .arr-kies-alles{display:inline-flex;align-items:center;gap:10px;font-weight:600;min-height:44px;cursor:pointer}
.arr-doc .arr-kies{display:grid;place-items:center;width:34px;min-height:44px;margin:-8px 0;cursor:pointer}
.arr-doc .arr-kies input,.arr-doc .arr-kies-alles input{width:22px;height:22px;accent-color:var(--leisteen);cursor:pointer}
.arr-doc .arr-bulk[hidden]{display:none}
.arr-doc .arr-bulk{flex:1;display:grid;gap:8px;background:var(--leisteen-diep);color:#fff;border-radius:18px;padding:12px 14px;box-shadow:0 12px 30px -16px rgba(0,0,0,.6)}
.arr-doc .arr-bulk-kop{display:flex;justify-content:space-between;align-items:center;gap:10px;font-size:.88rem}
.arr-doc .arr-bulk-wis{appearance:none;-webkit-appearance:none;background:none;border:0;padding:6px 2px;color:#cfd6d8;font:600 .82rem var(--sans);text-decoration:underline;cursor:pointer}
.arr-doc .arr-bulk-wis:hover{color:#fff}
.arr-doc .arr-bulk-knoppen{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}
.arr-doc button.arr-bulk-knop{appearance:none;-webkit-appearance:none;display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:44px;padding:0 8px;border-radius:50px;border:1.5px solid rgba(255,255,255,.25);background:rgba(255,255,255,.06);color:#fff;font:700 .82rem var(--sans);cursor:pointer;text-transform:none;letter-spacing:0;white-space:nowrap}
.arr-doc button.arr-bulk-knop:hover{background:#fff;color:var(--leisteen-diep);border-color:#fff}
.arr-doc button.arr-bulk-knop:disabled{opacity:.5;cursor:wait}
.arr-doc button.arr-bulk-knop.is-leeg{grid-column:1/-1;border-style:dashed}
.arr-doc .arr-actiebalk.is-bulk{flex-direction:column;align-items:stretch;gap:8px}
.arr-doc .arr-actiebalk.is-bulk .arr-opgeslagen{flex:none}

/* Klassen beheren */
.arr-doc .arr-klasrij{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:10px 0;border-top:1px solid var(--lijn)}
.arr-doc .arr-klasrij .arr-knop{white-space:nowrap}
.arr-doc .arr-klasrij:first-of-type{border-top:0}
.arr-doc .arr-klas-naam{display:flex;gap:8px;flex:1;min-width:220px;margin:0}
.arr-doc .arr-klas-nieuw{display:flex;gap:8px;margin:8px 0 0;flex-wrap:wrap}
.arr-doc .arr-klasrij input[type=text],.arr-doc .arr-klas-nieuw input[type=text]{flex:1;min-width:140px;font:inherit;min-height:44px;padding:0 14px;border:1.5px solid #d5dadb;border-radius:50px;background:#fff;color:var(--inkt)}
.arr-doc .arr-klasrij form,.arr-doc .arr-klas-nieuw{align-items:center}
.arr-doc .arr-lrij{display:flex;align-items:center;gap:10px;padding:8px 0;border-top:1px solid var(--lijn);cursor:pointer}
.arr-doc .arr-lrij:first-child{border-top:0}
.arr-doc .arr-lrij:hover{background:var(--grond)}
.arr-doc .arr-lrij[hidden]{display:none}
.arr-doc .arr-lnaam{flex:1;min-width:0;display:grid}
.arr-doc .arr-lnaam b{font-size:.92rem}
.arr-doc .arr-lnaam span{font-size:.76rem;color:var(--muted)}
.arr-doc #arr-indeling{display:grid;gap:0;margin:0}
.arr-doc .arr-klas-bulk{position:sticky;bottom:12px;z-index:2;margin-top:12px}
.arr-doc .arr-bulk-actie{display:flex;gap:8px;align-items:center}
.arr-doc .arr-bulk-actie select{flex:1;min-width:0;font:inherit;min-height:44px;padding:0 14px;border-radius:50px;border:1.5px solid rgba(255,255,255,.3);background:#fff;color:var(--inkt)}
.arr-doc .arr-bulk-actie select:focus-visible{outline:3px solid rgba(219,159,48,.6);outline-offset:2px}
.arr-doc button.arr-bulk-knop.is-primair{flex:none;background:var(--goud);border-color:var(--goud);color:#fff}
.arr-doc button.arr-bulk-knop.is-primair:hover{background:var(--goud-diep);border-color:var(--goud-diep);color:#fff}
.arr-doc button.arr-bulk-knop:disabled{opacity:.45;cursor:default}
.arr-doc button.arr-bulk-knop.is-primair:disabled{background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.2);color:#cfd6d8}
.arr-doc #arr-klas-filters{padding:0 0 8px}

/* Kalender */
.arr-doc .arr-maand{background:var(--vlak);margin:14px 12px 0;border-radius:22px;padding:14px 10px 12px;border:1px solid var(--lijn)}
.arr-doc .arr-maand-kop{display:flex;justify-content:space-between;align-items:center;padding:0 4px 10px}
.arr-doc .arr-maand-kop strong{font-family:var(--slab);font-weight:600;font-size:1.1rem;color:var(--leisteen)}
.arr-doc .arr-klein{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;background:var(--grond);color:var(--leisteen);text-decoration:none}
.arr-doc .arr-klein:hover{background:var(--leisteen-zacht)}
.arr-doc .arr-raster{display:grid;grid-template-columns:repeat(7,1fr);gap:3px}
.arr-doc .arr-wd{font-size:.66rem;font-weight:800;color:var(--muted);text-align:center;padding-bottom:4px;letter-spacing:.04em}
.arr-doc .arr-dag{min-height:58px;border-radius:12px;display:flex;flex-direction:column;align-items:center;gap:3px;padding-top:6px;font-size:.8rem;font-weight:600;color:#9aa4a8;font-variant-numeric:tabular-nums;position:relative;text-decoration:none}
.arr-doc .arr-dag.is-les{color:var(--inkt);background:var(--grond)}
.arr-doc .arr-dag.is-les:hover{background:var(--leisteen-zacht)}
.arr-doc .arr-dag.is-vak{background:repeating-linear-gradient(135deg,#f3ead7 0 6px,#f8f1e3 6px 12px);color:#a08450}
.arr-doc .arr-dag.is-vandaag{box-shadow:inset 0 0 0 2px var(--goud)}
.arr-doc .arr-dag.is-toekomst{background:#fff;border:1.5px dashed #cdd4d6;color:var(--muted)}
.arr-doc .arr-dag.is-vervalt .arr-num{text-decoration:line-through}
.arr-doc .arr-dag.is-extra::after{content:"extra";position:absolute;top:-6px;right:-2px;background:var(--goud);color:var(--inkt);font-size:.52rem;font-weight:800;border-radius:50px;padding:1px 5px;text-transform:uppercase}
.arr-doc .arr-mini{font-size:.6rem;font-weight:800;line-height:1}
.arr-doc .arr-mini.is-rood{color:var(--rood)}
.arr-doc .arr-dag-teller{display:none;font-size:.66rem;color:var(--muted);font-weight:700}
.arr-doc .arr-vak-label{display:flex;align-items:center;gap:8px;font-size:.76rem;font-weight:700;color:#8a6a2c;margin:10px 6px 0}
.arr-doc .arr-klegenda{display:grid;grid-template-columns:1fr 1fr;gap:8px 12px;margin:12px 16px 0;font-size:.76rem;color:#4b565b}
.arr-doc .arr-klegenda span{display:flex;align-items:center;gap:8px}
.arr-doc .arr-klegenda-breed{grid-template-columns:repeat(auto-fit,minmax(150px,1fr));margin:0 20px 8px}
.arr-doc .arr-vak-staal{display:inline-block;width:18px;height:14px;border-radius:4px;background:repeating-linear-gradient(135deg,#eadcbd 0 4px,#f8f1e3 4px 8px)}
.arr-doc .arr-volgende{margin:14px 12px 0;background:var(--vlak);border-radius:18px;border:1px solid var(--lijn);padding:12px 16px;display:flex;justify-content:space-between;align-items:center;gap:10px}
.arr-doc .arr-volgende div{display:grid}
.arr-doc .arr-volgende span{font-size:.8rem;color:var(--muted)}

/* Leerling */
.arr-doc .arr-acties{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px}
.arr-doc .arr-kaart{background:var(--vlak);border-radius:20px;padding:16px;border:1px solid var(--lijn);display:grid;gap:10px}
.arr-doc .arr-kaart-kop{margin:0;font-size:.7rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:var(--goud-diep)}
.arr-doc .arr-dl{display:grid;grid-template-columns:auto 1fr;gap:6px 14px;margin:0;font-size:.88rem}
.arr-doc .arr-dl dt{color:var(--muted)}
.arr-doc .arr-dl dd{margin:0;font-weight:600;overflow-wrap:anywhere}
.arr-doc .arr-strip{display:flex;gap:4px}
.arr-doc .arr-strip i{flex:1;height:30px;border-radius:8px;display:grid;place-items:center;font-style:normal;font-size:.72rem;font-weight:800}
.arr-doc .arr-strip-data{display:flex;justify-content:space-between;font-size:.66rem;color:var(--muted);margin-top:-4px}
.arr-doc .arr-details summary{cursor:pointer;color:var(--leisteen);font-weight:600;font-size:.86rem}
.arr-doc .arr-historie{list-style:none;margin:8px 0 0;padding:0}
.arr-doc .arr-historie li{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:6px 0;border-top:1px solid var(--lijn);font-size:.86rem}
.arr-doc .arr-notitie-form{display:grid;gap:8px;margin:0}
.arr-doc .arr-notitie-form textarea{width:100%;font:inherit;color:var(--inkt);padding:12px 16px;border:1.5px solid #d5dadb;border-radius:18px;min-height:84px;background:#fff;resize:vertical}
.arr-doc .arr-notitie-form textarea:focus{outline:none;border-color:var(--leisteen);box-shadow:0 0 0 3px rgba(219,159,48,.35)}
.arr-doc .arr-notitie-voet{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
.arr-doc .arr-notitie{display:grid;gap:4px;padding-top:10px;border-top:1px solid var(--lijn)}
.arr-doc .arr-notitie small{color:var(--muted);font-size:.76rem}
.arr-doc .arr-notitie p{margin:0;font-size:.9rem}
.arr-doc .arr-notitie .arr-details{display:grid;gap:8px}
.arr-doc .arr-notitie form+form{margin-top:8px}

/* Lege toestanden */
.arr-doc .arr-leeg{display:grid;justify-items:center;text-align:center;gap:12px;padding:48px 28px}
.arr-doc .arr-leeg-icoon{width:72px;height:72px;border-radius:50%;background:var(--goud-zacht);display:grid;place-items:center;color:var(--goud-diep)}
.arr-doc .arr-leeg-icoon.is-leisteen{background:var(--leisteen-zacht);color:var(--leisteen)}
.arr-doc .arr-leeg h3{margin:0;font-size:1.15rem;color:var(--inkt)}
.arr-doc .arr-leeg p{margin:0;color:var(--muted);max-width:32ch}

/* Maandoverzicht bestuur */
.arr-doc .arr-bestuur{display:grid;grid-template-columns:minmax(0,1fr) 280px;align-items:start}
.arr-doc .arr-tijdlijn-wrap{overflow-x:auto;padding:18px 16px}
.arr-doc .arr-tijdlijn{border-collapse:separate;border-spacing:0 6px;font-size:.8rem;min-width:100%}
.arr-doc .arr-tijdlijn thead th{font-weight:700;color:var(--muted);font-size:.64rem;text-align:center;padding:0 0 4px;font-variant-numeric:tabular-nums;min-width:24px;text-transform:lowercase;background:none;border:0}
.arr-doc .arr-tijdlijn th.arr-gl{text-align:left;font-size:.68rem;letter-spacing:.1em;text-transform:uppercase}
.arr-doc .arr-tijdlijn th.is-we{color:var(--leisteen)}
.arr-doc .arr-tijdlijn thead th.is-vandaag{color:var(--goud-diep)}
.arr-doc .arr-tijdlijn td,.arr-doc .arr-tijdlijn .arr-gn{background:var(--vlak);height:42px;text-align:center;padding:0;border:0;border-top:1px solid var(--lijn);border-bottom:1px solid var(--lijn)}
.arr-doc .arr-tijdlijn .arr-gn{text-align:left;padding:6px 12px;border-left:1px solid var(--lijn);border-radius:12px 0 0 12px;min-width:170px;max-width:210px;position:sticky;left:0;z-index:1;font-weight:400;line-height:1.25}
.arr-doc .arr-gn b{display:block;font-size:.82rem}
.arr-doc .arr-gn span{color:var(--muted);font-size:.7rem}
.arr-doc .arr-tijdlijn td:last-child{border-right:1px solid var(--lijn);border-radius:0 12px 12px 0}
.arr-doc .arr-tijdlijn td.is-vak{background:repeating-linear-gradient(135deg,#f3ead7 0 5px,#f8f1e3 5px 10px)}
.arr-doc .arr-tijdlijn td.is-vandaag{background:var(--goud-zacht)}
.arr-doc .arr-tijdlijn a{display:grid;place-items:center;height:100%;border-radius:8px}
.arr-doc .arr-tijdlijn a:hover{background:var(--leisteen-zacht)}
.arr-doc .arr-zij{background:var(--vlak);border-left:1px solid var(--lijn);padding:20px;display:grid;gap:18px;align-content:start;min-height:100%}
.arr-doc .arr-zij h3{margin:0 0 8px;font-size:.72rem;letter-spacing:.12em;text-transform:uppercase;color:var(--goud-diep)}
.arr-doc .arr-zij-blok{display:grid;gap:8px}
.arr-doc .arr-let{display:grid;gap:2px;padding:10px 12px;border-radius:12px;background:var(--grond);font-size:.8rem;text-decoration:none;color:var(--inkt)}
.arr-doc .arr-let:hover{background:var(--leisteen-zacht)}
.arr-doc .arr-let b{font-size:.84rem}
.arr-doc .arr-let span{color:var(--muted)}
.arr-doc .arr-let.is-rood{background:var(--rood-zacht)}
.arr-doc .arr-cijfers{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.arr-doc .arr-cijfer{background:var(--grond);border-radius:14px;padding:12px}
.arr-doc .arr-cijfer strong{display:block;font-family:var(--slab);font-size:1.5rem;color:var(--leisteen);font-variant-numeric:tabular-nums}
.arr-doc .arr-cijfer span{font-size:.74rem;color:var(--muted)}

@media (max-width:900px){.arr-doc .arr-bestuur{grid-template-columns:1fr}.arr-doc .arr-zij{border-left:0;border-top:1px solid var(--lijn)}}
@media (max-width:380px){.arr-doc .arr-st{gap:2px}.arr-doc .arr-st button{width:40px;height:40px}.arr-doc .arr-st-legenda{grid-template-columns:30px 1fr repeat(4,40px);gap:2px}.arr-doc .arr-rij{padding-left:10px;gap:6px}.arr-doc .arr-kies{width:30px}}
@media (prefers-reduced-motion:reduce){.arr-doc *{transition:none!important}}
/* Schermbreedtes per scherm */
.arr-doc.scherm-overzicht{--breedte:1040px}
.arr-doc.scherm-lijst{--breedte:960px}
.arr-doc.scherm-kalender,.arr-doc.scherm-leerling{--breedte:1120px}
.arr-doc.scherm-bestuur{--breedte:1480px}

/* Tablet en groter: statusknoppen met tekst */
@media (min-width:700px){
  .arr-doc .arr-st{gap:6px}
  .arr-doc .arr-st button{width:auto;min-width:108px;height:42px;padding:0 14px}
  .arr-doc .arr-st-tekst{display:inline}
  .arr-doc .arr-st-legenda{display:none}
  .arr-doc .arr-rij{padding:10px 18px}
  .arr-doc .arr-voortgang{margin:16px 16px 0;border:1px solid var(--lijn);border-radius:18px}
  .arr-doc .arr-presentie{margin:12px 16px 0;border:1px solid var(--lijn);border-radius:18px;overflow:hidden}
  .arr-doc .arr-rij:last-child{border-bottom:0}
  .arr-doc .arr-actiebalk{padding-inline:16px}
  .arr-doc .arr-actiebalk.is-bulk{flex-direction:row;align-items:center}
  .arr-doc .arr-bulk{display:flex;align-items:center;justify-content:space-between;gap:16px}
  .arr-doc .arr-bulk-knoppen{display:flex;flex-wrap:wrap;justify-content:flex-end}
  .arr-doc button.arr-bulk-knop.is-leeg{grid-column:auto}
  .arr-doc .arr-bulk-actie{max-width:460px;margin-left:auto}
}

/* Desktop */
@media (min-width:960px){
  .arr-doc .arr-kop{grid-template-columns:minmax(0,1fr) auto;column-gap:32px;align-items:end;padding-top:22px;padding-bottom:24px}
  .arr-doc .arr-kop-rij{grid-column:1/-1}
  .arr-doc .arr-kop h2{font-size:1.75rem}
  .arr-doc .arr-kop-na{display:flex;align-items:center;gap:16px}
  .arr-doc .arr-kop-na .arr-wissel{width:260px}
  .arr-doc .arr-kop-na .arr-datumnav{width:380px}
  .arr-doc .arr-kop-na .arr-acties{width:340px}
  .arr-doc .arr-inhoud{padding:24px 16px}

  .arr-doc .arr-kop-na .arr-samenvatting{width:420px}
  .arr-doc .arr-filters{padding:20px 16px 12px}
  .arr-doc .arr-dg-lijst{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;padding:0 16px;align-items:start}
  .arr-doc .arr-dg{border:1px solid var(--lijn);border-radius:18px;overflow:hidden;margin:0}

  .arr-doc .arr-kal-layout{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:20px;padding:24px 16px 0;align-items:start}
  .arr-doc .arr-kal-layout .arr-maand{margin:0;padding:18px 16px}
  .arr-doc .arr-kal-zij{display:grid;gap:14px}
  .arr-doc .arr-kal-zij .arr-klegenda{grid-template-columns:1fr;margin:0;background:var(--vlak);border:1px solid var(--lijn);border-radius:18px;padding:16px}
  .arr-doc .arr-kal-zij .arr-volgende{margin:0}
  .arr-doc .arr-raster{gap:6px}
  .arr-doc .arr-dag{min-height:96px;padding:10px 8px;align-items:flex-start;font-size:.95rem;gap:6px}
  .arr-doc .arr-dag .arr-glyph{width:24px;height:24px}
  .arr-doc .arr-dag-teller{display:block}
  .arr-doc .arr-mini{font-size:.7rem}
  .arr-doc .arr-wd{font-size:.72rem;text-align:left;padding-left:8px}

  .arr-doc .arr-leerling-raster{grid-template-columns:minmax(0,1fr) minmax(0,1fr);grid-template-rows:auto 1fr;align-items:start;gap:18px}
  .arr-doc .arr-kaart-aanw{grid-column:1;grid-row:1}
  .arr-doc .arr-kaart-contact{grid-column:1;grid-row:2}
  .arr-doc .arr-kaart-notities{grid-column:2;grid-row:1/span 2}

  .arr-doc .arr-login-kolom{justify-content:center;padding:40px 0}
  .arr-doc .arr-login-kaart{flex:none;border-radius:28px;box-shadow:0 30px 60px -30px rgba(0,0,0,.6)}
}

/* De docentenpagina vult het hele scherm: geen marges of maximale breedte van Elementor eromheen. */
body.arr-docentenpagina{margin:0;background:#eef1f1}
body.arr-docentenpagina :is(.elementor-section,.elementor-container,.elementor-column,.elementor-widget-wrap,.elementor-widget-container,.e-con,.e-con-inner),
:is(.elementor-section,.elementor-container,.elementor-column,.elementor-widget-wrap,.elementor-widget-container,.e-con,.e-con-inner):has(.arr-doc){
  padding:0!important;margin:0!important;max-width:none!important;width:100%!important;gap:0!important;
  --padding-top:0px;--padding-right:0px;--padding-bottom:0px;--padding-left:0px;--gap:0px;--row-gap:0px;--column-gap:0px;--content-width:100%}
body.arr-docentenpagina .elementor-widget:not(:last-child){margin-bottom:0!important}
</style>';
}

// ── Beheer: klassen, lesrooster en docenten ──────────────────

add_action( 'admin_menu', function () {
    add_submenu_page( 'arrahma-inschrijvingen', 'Klassen & docenten', 'Klassen & docenten', 'manage_options', 'arrahma-klassen', 'arrahma_klassen_page' );
    add_submenu_page( 'arrahma-inschrijvingen', 'Aanwezigheid', 'Aanwezigheid', 'manage_options', 'arrahma-aanwezigheid', 'arrahma_aanwezigheid_page' );
}, 20 );

/** Aantal actieve leerlingen per blok/groep. */
function arrahma_actieve_aantallen_per_slot(): array {
    global $wpdb;
    $table = $wpdb->prefix . ARRAHMA_TABLE;
    $rows  = $wpdb->get_results( "SELECT rooster, COUNT(*) AS cnt FROM {$table} WHERE rooster != '' AND status NOT IN ('afgewezen','uitgeschreven') GROUP BY rooster" );
    $uit   = [];
    foreach ( $rows as $row ) $uit[ $row->rooster ] = (int) $row->cnt;
    return $uit;
}

/** Verwerkt één beheeractie op de Klassen-pagina en geeft een melding terug: [ ok, tekst ]. */
function arrahma_klassen_actie( string $actie ): array {
    global $wpdb;
    $table   = $wpdb->prefix . ARRAHMA_TABLE;
    $slots   = arrahma_roster_labels();
    $klassen = arrahma_klassen();
    $post    = function ( string $veld ): string { return trim( sanitize_text_field( wp_unslash( $_POST[ $veld ] ?? '' ) ) ); };
    $naam    = function ( string $veld ) use ( $post ): string {
        $n = $post( $veld );
        return function_exists( 'mb_substr' ) ? mb_substr( $n, 0, ARRAHMA_KLASNAAM_MAX ) : substr( $n, 0, ARRAHMA_KLASNAAM_MAX );
    };

    switch ( $actie ) {
        case 'klas_toevoegen':
            $slot = $post( 'slot' );
            $n    = $naam( 'naam' );
            if ( ! isset( $slots[ $slot ] ) || $n === '' ) return [ false, 'Kies een groep en vul een naam in.' ];
            $id = 'k' . substr( md5( uniqid( '', true ) ), 0, 10 );
            $klassen[ $id ] = [ 'slot' => $slot, 'naam' => $n ];
            update_option( ARRAHMA_KLASSEN_OPTION, $klassen, false );
            return [ true, 'Klas "' . $n . '" toegevoegd. Deel de leerlingen hieronder in.' ];

        case 'klas_hernoemen':
            $id = $post( 'klas' );
            $n  = $naam( 'naam' );
            if ( ! isset( $klassen[ $id ] ) || $n === '' ) return [ false, 'Vul een naam in.' ];
            $klassen[ $id ]['naam'] = $n;
            update_option( ARRAHMA_KLASSEN_OPTION, $klassen, false );
            return [ true, 'Klas hernoemd.' ];

        case 'klas_verwijderen':
            $id = $post( 'klas' );
            if ( ! isset( $klassen[ $id ] ) ) return [ false, 'Klas niet gevonden.' ];
            unset( $klassen[ $id ] );
            update_option( ARRAHMA_KLASSEN_OPTION, $klassen, false );
            $wpdb->update( $table, [ 'klas' => '' ], [ 'klas' => $id ], [ '%s' ], [ '%s' ] );
            return [ true, 'Klas verwijderd. De leerlingen staan weer bij "Nog niet in een klas"; de aanwezigheid blijft bewaard.' ];

        case 'indeling_opslaan':
            $slot      = $post( 'slot' );
            $geldig    = arrahma_klassen_van_slot( $slot );
            $indeling  = isset( $_POST['indeling'] ) && is_array( $_POST['indeling'] ) ? wp_unslash( $_POST['indeling'] ) : [];
            $in_slot   = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE rooster = %s", $slot ) ) );
            $gewijzigd = 0;
            foreach ( $indeling as $id => $klas ) {
                $id   = (int) $id;
                $klas = sanitize_key( $klas );
                if ( ! in_array( $id, $in_slot, true ) || ( $klas !== '' && ! isset( $geldig[ $klas ] ) ) ) continue;
                $gewijzigd += (int) $wpdb->update( $table, [ 'klas' => $klas ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
            }
            return [ true, 'Indeling opgeslagen (' . $gewijzigd . ' gewijzigd).' ];

        case 'indeling_bulk':
            $slot    = $post( 'slot' );
            $geldig  = arrahma_klassen_van_slot( $slot );
            $doel    = sanitize_key( wp_unslash( $_POST['doel'] ?? '' ) );
            if ( $doel === '' ) return [ false, 'Kies eerst een klas.' ];
            $doel    = $doel === '__geen' ? '' : $doel;      // "Uit de klas halen"
            if ( $doel !== '' && ! isset( $geldig[ $doel ] ) ) return [ false, 'Onbekende klas.' ];
            $ids     = array_filter( array_map( 'absint', (array) ( $_POST['leerlingen'] ?? [] ) ) );
            if ( ! $ids ) return [ false, 'Selecteer eerst leerlingen.' ];
            $in_slot = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE rooster = %s", $slot ) ) );
            $gedaan  = 0;
            foreach ( $ids as $id ) {
                if ( ! in_array( (int) $id, $in_slot, true ) ) continue;
                $wpdb->update( $table, [ 'klas' => $doel ], [ 'id' => (int) $id ], [ '%s' ], [ '%d' ] );
                $gedaan++;
            }
            return [ true, $gedaan . ' leerling' . ( $gedaan === 1 ? '' : 'en' ) . ( $doel !== '' ? ' in ' . $geldig[ $doel ] . ' gezet.' : ' uit de klas gehaald.' ) ];

        case 'vakantie_toevoegen':
            $n   = $naam( 'naam' );
            $van = $post( 'van' );
            $tot = $post( 'tot' );
            if ( $n === '' || ! arrahma_geldige_datum( $van ) || ! arrahma_geldige_datum( $tot ) || $van > $tot ) {
                return [ false, 'Vul een naam in en een geldige periode (van vóór tot).' ];
            }
            $slots = [];
            if ( $post( 'bereik' ) === 'gekozen' ) {
                $gekozen = isset( $_POST['vakantie_slots'] ) && is_array( $_POST['vakantie_slots'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['vakantie_slots'] ) ) : [];
                $slots   = array_values( array_intersect( array_keys( arrahma_roster_labels() ), $gekozen ) );
                if ( ! $slots ) return [ false, 'Kies minstens één groep, of kies "Alle groepen".' ];
            }
            $vakanties   = arrahma_vakanties();
            $vakanties[] = [ 'naam' => $n, 'van' => $van, 'tot' => $tot, 'slots' => $slots ];
            update_option( ARRAHMA_VAKANTIES_OPTION, $vakanties, false );
            return [ true, 'Vakantie toegevoegd voor: ' . arrahma_vakantie_bereik( end( $vakanties ) ) . '.' ];

        case 'vakantie_verwijderen':
            $vakanties = arrahma_vakanties();
            unset( $vakanties[ absint( $_POST['index'] ?? -1 ) ] );
            update_option( ARRAHMA_VAKANTIES_OPTION, array_values( $vakanties ), false );
            return [ true, 'Vakantie verwijderd.' ];

        case 'uitzondering_toevoegen':
            $slot  = $post( 'slot' );
            $datum = $post( 'datum' );
            $soort = $post( 'soort' ) === 'extra' ? 'extra' : 'vervalt';
            if ( ! isset( $slots[ $slot ] ) || ! arrahma_geldige_datum( $datum ) ) return [ false, 'Kies een groep en een geldige datum.' ];
            $lijst = array_values( array_filter( arrahma_les_uitzonderingen(), function ( $u ) use ( $slot, $datum ) {
                return ! ( $u['slot'] === $slot && $u['datum'] === $datum ); // één uitzondering per groep per dag
            } ) );
            $lijst[] = [ 'slot' => $slot, 'datum' => $datum, 'soort' => $soort ];
            update_option( ARRAHMA_LES_UITZ_OPTION, $lijst, false );
            return [ true, $soort === 'extra' ? 'Extra les toegevoegd.' : 'Les laten vervallen.' ];

        case 'uitzondering_verwijderen':
            $lijst = arrahma_les_uitzonderingen();
            unset( $lijst[ absint( $_POST['index'] ?? -1 ) ] );
            update_option( ARRAHMA_LES_UITZ_OPTION, array_values( $lijst ), false );
            return [ true, 'Uitzondering verwijderd.' ];

        case 'docenten_opslaan':
            $invoer = isset( $_POST['eenheden'] ) && is_array( $_POST['eenheden'] ) ? wp_unslash( $_POST['eenheden'] ) : [];
            $geldig = arrahma_alle_eenheden();
            $coord = isset( $_POST['coordinator'] ) && is_array( $_POST['coordinator'] ) ? array_map( 'absint', array_keys( $_POST['coordinator'] ) ) : [];
            foreach ( get_users( [ 'role' => ARRAHMA_DOCENT_ROLE, 'fields' => 'ID' ] ) as $user_id ) {
                $gekozen = isset( $invoer[ $user_id ] ) && is_array( $invoer[ $user_id ] ) ? array_map( 'sanitize_key', $invoer[ $user_id ] ) : [];
                $gekozen = array_values( array_filter( $gekozen, function ( $k ) use ( $geldig ) { return isset( $geldig[ $k ] ); } ) );
                update_user_meta( (int) $user_id, ARRAHMA_DOCENT_META, $gekozen );

                // Coördinator: mag klassen van zijn eigen blokken maken en indelen (vaak een gedeeld account).
                $gebruiker = new WP_User( (int) $user_id );
                in_array( (int) $user_id, $coord, true ) ? $gebruiker->add_cap( ARRAHMA_KLAS_CAP ) : $gebruiker->remove_cap( ARRAHMA_KLAS_CAP );
            }
            return [ true, 'Koppelingen van docenten opgeslagen.' ];
    }
    return [ false, 'Onbekende actie.' ];
}

function arrahma_klassen_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $tabs = [ 'klassen' => 'Klassen', 'docenten' => 'Docenten', 'rooster' => 'Lesrooster & vakanties' ];
    $tab  = sanitize_key( $_GET['tab'] ?? 'klassen' );
    if ( ! isset( $tabs[ $tab ] ) ) $tab = 'klassen';

    $melding = null;
    if ( isset( $_POST['arrahma_klassen_actie'] ) && check_admin_referer( 'arrahma_klassen' ) ) {
        $melding = arrahma_klassen_actie( sanitize_key( wp_unslash( $_POST['arrahma_klassen_actie'] ) ) );
    }
    ?>
    <div class="wrap">
      <h1>Klassen &amp; docenten</h1>
      <p style="color:#666;max-width:760px">Docenten houden de aanwezigheid bij op
        <a href="<?= esc_url( arrahma_docenten_url() ) ?>" target="_blank"><?= esc_html( arrahma_docenten_url() ) ?></a>
        — een gewone pagina van de website met de shortcode <code>[arrahma_docenten]</code>. Ze komen niet in dit beheerscherm.</p>
      <?php if ( ! get_option( ARRAHMA_DOCENTEN_URL_OPTION ) ) : ?>
        <div class="notice notice-warning"><p>De docentenpagina is nog niet gevonden. Maak een pagina (bijv. "Docenten", adres <code>/docenten</code>) met alleen de shortcode <code>[arrahma_docenten]</code> en bekijk hem één keer.</p></div>
      <?php endif; ?>
      <?php if ( $melding ) : ?>
        <div class="notice notice-<?= $melding[0] ? 'success' : 'error' ?> is-dismissible"><p><?= esc_html( $melding[1] ) ?></p></div>
      <?php endif; ?>
      <nav class="nav-tab-wrapper">
        <?php foreach ( $tabs as $sleutel => $label ) : ?>
          <a href="<?= esc_url( admin_url( 'admin.php?page=arrahma-klassen&tab=' . $sleutel ) ) ?>" class="nav-tab <?= $tab === $sleutel ? 'nav-tab-active' : '' ?>"><?= esc_html( $label ) ?></a>
        <?php endforeach; ?>
      </nav>
      <div style="max-width:980px;margin-top:1rem">
        <?php
        if ( $tab === 'klassen' ) arrahma_klassen_tab_klassen();
        if ( $tab === 'docenten' ) arrahma_klassen_tab_docenten();
        if ( $tab === 'rooster' ) arrahma_klassen_tab_rooster();
        ?>
      </div>
    </div>
    <?php
}

/** Opent een beheerformulier met nonce en actie. */
function arrahma_klassen_form_open( string $actie, string $extra = '' ): string {
    return '<form method="post"' . $extra . '>' . wp_nonce_field( 'arrahma_klassen', '_wpnonce', true, false )
        . '<input type="hidden" name="arrahma_klassen_actie" value="' . esc_attr( $actie ) . '">';
}

function arrahma_klassen_tab_klassen(): void {
    $aantal = arrahma_actieve_aantallen_per_slot();
    $kaart  = 'background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:1rem 1.25rem;margin-bottom:1rem';

    echo '<p style="color:#666">Zonder klassen is het hele lesblok één klas. Maak klassen aan als een blok over meerdere docenten wordt verdeeld, en deel daarna de leerlingen in.</p>';

    foreach ( array_keys( arrahma_roster_labels() ) as $slot ) {
        $klassen = arrahma_klassen_van_slot( $slot );
        echo '<div style="' . $kaart . '"><h2 style="margin:0 0 .5rem;font-size:1.05rem">' . esc_html( arrahma_slot_naam( $slot ) )
            . ' <span style="color:#888;font-weight:400">· ' . (int) ( $aantal[ $slot ] ?? 0 ) . ' leerlingen</span></h2>';

        foreach ( $klassen as $id => $naam ) {
            echo '<div style="display:flex;gap:.5rem;align-items:center;margin:.3rem 0">'
                . arrahma_klassen_form_open( 'klas_hernoemen', ' style="display:flex;gap:.4rem"' )
                . '<input type="hidden" name="klas" value="' . esc_attr( $id ) . '">'
                . '<input type="text" name="naam" value="' . esc_attr( $naam ) . '" maxlength="' . ARRAHMA_KLASNAAM_MAX . '" required>'
                . '<button class="button">Hernoemen</button></form>'
                . arrahma_klassen_form_open( 'klas_verwijderen', ' onsubmit="return confirm(\'Klas verwijderen? De leerlingen komen weer bij Nog niet in een klas.\')"' )
                . '<input type="hidden" name="klas" value="' . esc_attr( $id ) . '">'
                . '<button class="button" style="color:#d32f2f;border-color:#d32f2f">Verwijderen</button></form></div>';
        }

        echo arrahma_klassen_form_open( 'klas_toevoegen', ' style="display:flex;gap:.4rem;margin:.5rem 0"' )
            . '<input type="hidden" name="slot" value="' . esc_attr( $slot ) . '">'
            . '<input type="text" name="naam" placeholder="Naam nieuwe klas, bijv. Klas A" maxlength="' . ARRAHMA_KLASNAAM_MAX . '" required>'
            . '<button class="button">Klas toevoegen</button></form>';

        if ( $klassen ) {
            $leerlingen = arrahma_leerlingen_van_slot( $slot );
            echo '<details' . ( $leerlingen ? ' open' : '' ) . '><summary style="cursor:pointer;margin:.5rem 0">Leerlingen indelen</summary>';
            if ( ! $leerlingen ) {
                echo '<p style="color:#888">Nog geen leerlingen in deze groep.</p>';
            } else {
                echo arrahma_klassen_form_open( 'indeling_opslaan' ) . '<input type="hidden" name="slot" value="' . esc_attr( $slot ) . '">'
                    . '<table class="widefat striped" style="max-width:640px"><tbody>';
                foreach ( $leerlingen as $rij ) {
                    $huidig = isset( $klassen[ (string) $rij->klas ] ) ? (string) $rij->klas : '';
                    echo '<tr><td>' . esc_html( trim( $rij->voornaam . ' ' . $rij->achternaam ) )
                        . '<br><small style="color:#888">' . esc_html( arrahma_niveau_label( $rij->niveau ) ) . '</small></td><td>'
                        . '<select name="indeling[' . (int) $rij->id . ']"><option value="">— nog niet in een klas —</option>';
                    foreach ( $klassen as $id => $naam ) {
                        echo '<option value="' . esc_attr( $id ) . '"' . selected( $huidig, $id, false ) . '>' . esc_html( $naam ) . '</option>';
                    }
                    echo '</select></td></tr>';
                }
                echo '</tbody></table><p><button class="button button-primary">Indeling opslaan</button></p></form>';
            }
            echo '</details>';
        }
        echo '</div>';
    }
}

function arrahma_klassen_tab_docenten(): void {
    $docenten = get_users( [ 'role' => ARRAHMA_DOCENT_ROLE, 'orderby' => 'display_name' ] );
    $eenheden = arrahma_alle_eenheden();

    echo '<p style="color:#666;max-width:760px">Een docent toevoegen: <a href="' . esc_url( admin_url( 'user-new.php' ) ) . '">Gebruikers → Nieuwe gebruiker</a>, '
        . 'kies bij <strong>Rol</strong> "Docent" en laat WordPress de inlogmail sturen. Koppel de docent daarna hier aan een of meer groepen of klassen. '
        . 'Stopt iemand, zet dan de rol op "Geen rol voor deze site" of verwijder het account. '
        . 'Een <strong>coördinator</strong> is gewoon zo\'n account (mag ook een gedeeld account zijn) met het vinkje hieronder aan: die kan op /docenten zelf de klassen van zijn groepen regelen.</p>';

    if ( ! $docenten ) {
        echo '<p><em>Er zijn nog geen docenten.</em></p>';
        return;
    }

    echo arrahma_klassen_form_open( 'docenten_opslaan' );
    foreach ( $docenten as $docent ) {
        $gekozen = array_keys( arrahma_docent_eenheden( (int) $docent->ID ) );
        echo '<fieldset style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:1rem 1.25rem;margin-bottom:1rem">'
            . '<legend style="font-weight:600;padding:0 .35rem">' . esc_html( $docent->display_name ) . ' <span style="color:#888;font-weight:400">' . esc_html( $docent->user_email ) . '</span></legend>'
            . '<label style="display:block;margin-bottom:.6rem"><input type="checkbox" name="coordinator[' . (int) $docent->ID . ']" value="1"'
            . checked( user_can( $docent, ARRAHMA_KLAS_CAP ), true, false ) . '> <strong>Coördinator</strong> '
            . '<span style="color:#888">— mag op /docenten zelf klassen aanmaken, hernoemen en leerlingen indelen, voor de groepen hieronder.</span></label>'
            . '<input type="hidden" name="eenheden[' . (int) $docent->ID . '][]" value="">'
            . '<div style="columns:2 320px;column-gap:1.5rem">';
        foreach ( $eenheden as $sleutel => $eenheid ) {
            $inspring = $eenheid['klas'] !== '' ? 'margin-left:1.4rem;' : 'margin-top:.35rem;';
            echo '<label style="display:block;break-inside:avoid;' . $inspring . '"><input type="checkbox" name="eenheden[' . (int) $docent->ID . '][]" value="' . esc_attr( $sleutel ) . '"'
                . checked( in_array( $sleutel, $gekozen, true ), true, false ) . '> ' . esc_html( $eenheid['label'] ) . '</label>';
        }
        echo '</div></fieldset>';
    }
    echo '<p><button class="button button-primary">Koppelingen opslaan</button></p></form>';
}

function arrahma_klassen_tab_rooster(): void {
    $slots = arrahma_roster_labels();
    $kaart = 'background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:1rem 1.25rem;margin-bottom:1rem';

    echo '<p style="color:#666;max-width:760px">Lesdagen volgen vanzelf uit de dag van elke groep, het hele jaar door. Hier haal je vakanties eruit, '
        . 'laat je een losse les vervallen of voeg je een extra les toe.</p>';

    // ── Vakanties
    echo '<div style="' . $kaart . '"><h2 style="margin-top:0;font-size:1.05rem">Vakanties</h2>'
        . '<p style="color:#666;margin-top:0">Een vakantie geldt voor alle groepen, of alleen voor de blokken die je aanvinkt — bijvoorbeeld alleen de kinderen, of alleen de weekendblokken.</p>';
    $vakanties = arrahma_vakanties();
    if ( $vakanties ) {
        echo '<table class="widefat striped"><tbody>';
        foreach ( $vakanties as $i => $v ) {
            echo '<tr><td><strong>' . esc_html( $v['naam'] ) . '</strong></td><td style="white-space:nowrap">' . esc_html( arrahma_format_date( $v['van'] ) . ' t/m ' . arrahma_format_date( $v['tot'] ) ) . '</td>'
                . '<td style="color:#555">' . esc_html( arrahma_vakantie_bereik( $v ) ) . '</td><td style="text-align:right">'
                . arrahma_klassen_form_open( 'vakantie_verwijderen' ) . '<input type="hidden" name="index" value="' . (int) $i . '"><button class="button-link" style="color:#d32f2f">Verwijderen</button></form></td></tr>';
        }
        echo '</tbody></table>';
    }
    echo arrahma_klassen_form_open( 'vakantie_toevoegen', ' id="arrahma-vakantie-form" style="margin-top:1rem;display:grid;gap:.75rem"' )
        . '<div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:end">'
        . '<label>Naam<br><input type="text" name="naam" placeholder="Herfstvakantie" required></label>'
        . '<label>Van<br><input type="date" name="van" required></label>'
        . '<label>Tot en met<br><input type="date" name="tot" required></label></div>'
        . '<fieldset style="border:1px solid #e0e0e0;border-radius:8px;padding:.75rem 1rem"><legend style="padding:0 .35rem;font-weight:600">Voor wie?</legend>'
        . '<label style="margin-right:1.25rem"><input type="radio" name="bereik" value="alle" checked> Alle groepen</label>'
        . '<label><input type="radio" name="bereik" value="gekozen"> Alleen gekozen groepen</label>'
        . '<div id="arrahma-vakantie-slots" hidden><div style="margin-top:.75rem;display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:.75rem 1.5rem">';
    $per_doelgroep = [];
    foreach ( array_keys( $slots ) as $slot ) $per_doelgroep[ arrahma_slot_doelgroep( $slot ) ][] = $slot;
    foreach ( $per_doelgroep as $doelgroep => $groep_slots ) {
        echo '<div><label style="display:block;font-weight:600;margin-bottom:.25rem"><input type="checkbox" class="arrahma-vak-alles"> ' . esc_html( $doelgroep ) . ' — alles</label>';
        foreach ( $groep_slots as $slot ) {
            echo '<label style="display:block;margin-left:1.4rem"><input type="checkbox" name="vakantie_slots[]" value="' . esc_attr( $slot ) . '"> ' . esc_html( arrahma_roster_label( $slot ) ) . '</label>';
        }
        echo '</div>';
    }
    echo '</div></div></fieldset><p style="margin:0"><button class="button">Vakantie toevoegen</button></p></form></div>';
    ?>
    <script>
    (function () {
      var form = document.getElementById('arrahma-vakantie-form');
      if (!form) return;
      var lijst = document.getElementById('arrahma-vakantie-slots');
      form.querySelectorAll('input[name="bereik"]').forEach(function (r) {
        r.addEventListener('change', function () { lijst.hidden = form.querySelector('input[name="bereik"]:checked').value !== 'gekozen'; });
      });
      // "Doelgroep — alles" vinkt alle blokken van die doelgroep aan of uit, en volgt ze terug.
      lijst.querySelectorAll('.arrahma-vak-alles').forEach(function (alles) {
        var blok = alles.closest('div'), items = blok.querySelectorAll('input[name="vakantie_slots[]"]');
        alles.addEventListener('change', function () { items.forEach(function (i) { i.checked = alles.checked; }); });
        items.forEach(function (i) { i.addEventListener('change', function () {
          var aan = Array.prototype.filter.call(items, function (x) { return x.checked; }).length;
          alles.checked = aan === items.length; alles.indeterminate = aan > 0 && aan < items.length;
        }); });
      });
    })();
    </script>
    <?php

    // ── Uitzonderingen per groep
    echo '<div style="' . $kaart . '"><h2 style="margin-top:0;font-size:1.05rem">Losse les laten vervallen of extra les</h2>';
    $lijst = arrahma_les_uitzonderingen();
    if ( $lijst ) {
        echo '<table class="widefat striped"><tbody>';
        foreach ( $lijst as $i => $u ) {
            echo '<tr><td>' . esc_html( arrahma_datum_lang( $u['datum'] ) ) . '</td><td>' . esc_html( arrahma_slot_naam( $u['slot'] ) ) . '</td><td>'
                . ( $u['soort'] === 'extra' ? 'Extra les' : 'Vervalt' ) . '</td><td style="text-align:right">'
                . arrahma_klassen_form_open( 'uitzondering_verwijderen' ) . '<input type="hidden" name="index" value="' . (int) $i . '"><button class="button-link" style="color:#d32f2f">Verwijderen</button></form></td></tr>';
        }
        echo '</tbody></table>';
    }
    echo arrahma_klassen_form_open( 'uitzondering_toevoegen', ' style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:end;margin-top:.75rem"' )
        . '<label>Groep<br><select name="slot" required><option value="">— kies —</option>';
    foreach ( array_keys( $slots ) as $slot ) {
        echo '<option value="' . esc_attr( $slot ) . '">' . esc_html( arrahma_slot_naam( $slot ) ) . '</option>';
    }
    echo '</select></label><label>Datum<br><input type="date" name="datum" required></label>'
        . '<label>Soort<br><select name="soort"><option value="vervalt">Les vervalt</option><option value="extra">Extra les</option></select></label>'
        . '<button class="button">Toevoegen</button></form></div>';

    // ── Controle: eerstvolgende lessen per groep
    echo '<div style="' . $kaart . '"><h2 style="margin-top:0;font-size:1.05rem">Eerstvolgende lessen</h2><table class="widefat striped"><tbody>';
    foreach ( array_keys( $slots ) as $slot ) {
        $data  = [];
        $datum = arrahma_verschuif_datum( arrahma_vandaag(), -1 );
        for ( $i = 0; $i < 3; $i++ ) {
            $datum = arrahma_zoek_les( $slot, $datum, 1 );
            if ( ! $datum ) break;
            $data[] = arrahma_datum_kort( $datum );
        }
        echo '<tr><td>' . esc_html( arrahma_slot_naam( $slot ) ) . '</td><td>' . esc_html( $data ? implode( ', ', $data ) : 'geen les gevonden' ) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

// ── Beheer: aanwezigheidsoverzicht en export ─────────────────

/** Periode uit de URL, standaard de afgelopen ARRAHMA_SIGNAAL_WEKEN weken. */
function arrahma_periode_uit_request(): array {
    $tot = sanitize_text_field( wp_unslash( $_GET['tot'] ?? '' ) );
    $van = sanitize_text_field( wp_unslash( $_GET['van'] ?? '' ) );
    if ( ! arrahma_geldige_datum( $tot ) ) $tot = arrahma_vandaag();
    if ( ! arrahma_geldige_datum( $van ) ) $van = arrahma_verschuif_datum( $tot, -7 * ARRAHMA_SIGNAAL_WEKEN );
    if ( $van > $tot ) [ $van, $tot ] = [ $tot, $van ];
    return [ $van, $tot ];
}

add_action( 'admin_init', function () {
    if ( ( $_GET['page'] ?? '' ) !== 'arrahma-aanwezigheid' || ! current_user_can( 'manage_options' ) ) return;
    if ( ! isset( $_GET['export'], $_GET['_wpnonce'] ) ) return;
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'arrahma_export_aanwezigheid' ) ) return;

    $soort = sanitize_key( $_GET['export'] );
    if ( $soort === 'aanwezigheid' ) {
        $alles = ! empty( $_GET['alles'] );
        [ $van, $tot ] = arrahma_periode_uit_request();
        arrahma_export_aanwezigheid_csv( $alles ? '' : $van, $alles ? '' : $tot );
        exit;
    }
    if ( $soort === 'notities' ) {
        arrahma_export_notities_csv();
        exit;
    }
} );

function arrahma_csv_start( string $naam ) {
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $naam . '-' . date( 'Y-m-d' ) . '.csv"' );
    header( 'Pragma: no-cache' );
    $out = fopen( 'php://output', 'w' );
    fprintf( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) ); // UTF-8 BOM voor Excel
    return $out;
}

function arrahma_export_aanwezigheid_csv( string $van, string $tot ): void {
    global $wpdb;
    $a     = $wpdb->prefix . ARRAHMA_AANWEZIG_TABLE;
    $i     = $wpdb->prefix . ARRAHMA_TABLE;
    $where = $van !== '' ? $wpdb->prepare( 'WHERE a.lesdatum BETWEEN %s AND %s', $van, $tot ) : '';
    $rows  = $wpdb->get_results(
        "SELECT a.*, i.voornaam, i.achternaam, i.inschrijving_voor, i.klas, i.rooster AS rooster_nu
         FROM {$a} a LEFT JOIN {$i} i ON i.id = a.inschrijving_id {$where}
         ORDER BY a.lesdatum, a.rooster, i.voornaam, i.achternaam"
    );
    $labels = arrahma_aanwezig_statussen();
    $cats   = arrahma_category_labels();

    $out = arrahma_csv_start( 'aanwezigheid' );
    fputcsv( $out, [ 'Lesdatum', 'Groep', 'Klas (nu)', 'Voornaam', 'Achternaam', 'Doelgroep', 'Status', 'Ingevuld door', 'Gewijzigd op' ], ';' );
    foreach ( $rows as $r ) {
        $klas = $r->rooster_nu === $r->rooster ? ( arrahma_klassen_van_slot( (string) $r->rooster )[ (string) $r->klas ] ?? '' ) : '';
        fputcsv( $out, [
            $r->lesdatum,
            arrahma_roster_label( $r->rooster ),
            $klas,
            $r->voornaam ?? '(verwijderd)',
            $r->achternaam ?? '',
            $cats[ $r->inschrijving_voor ?? '' ] ?? '',
            $labels[ $r->status ] ?? $r->status,
            arrahma_gebruiker_naam( (int) $r->door ),
            $r->gewijzigd_op,
        ], ';' );
    }
    fclose( $out );
}

function arrahma_export_notities_csv(): void {
    global $wpdb;
    $n    = $wpdb->prefix . ARRAHMA_NOTITIE_TABLE;
    $i    = $wpdb->prefix . ARRAHMA_TABLE;
    $rows = $wpdb->get_results(
        "SELECT n.*, i.voornaam, i.achternaam, i.rooster FROM {$n} n LEFT JOIN {$i} i ON i.id = n.inschrijving_id
         ORDER BY n.aangemaakt_op"
    );

    $out = arrahma_csv_start( 'notities' );
    fputcsv( $out, [ 'Datum', 'Voornaam', 'Achternaam', 'Groep', 'Auteur', 'Notitie', 'Aangepast op' ], ';' );
    foreach ( $rows as $r ) {
        fputcsv( $out, [
            $r->aangemaakt_op,
            $r->voornaam ?? '(verwijderd)',
            $r->achternaam ?? '',
            arrahma_roster_label( (string) ( $r->rooster ?? '' ) ),
            arrahma_gebruiker_naam( (int) $r->auteur ),
            $r->tekst,
            $r->gewijzigd_op ?? '',
        ], ';' );
    }
    fclose( $out );
}

/** Leerlingen met vaak "afwezig" (zonder bericht) in de afgelopen weken. */
function arrahma_signaallijst(): array {
    global $wpdb;
    $a = $wpdb->prefix . ARRAHMA_AANWEZIG_TABLE;
    $i = $wpdb->prefix . ARRAHMA_TABLE;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT i.*, s.aantal, s.laatste
         FROM ( SELECT inschrijving_id, COUNT(*) AS aantal, MAX(lesdatum) AS laatste
                FROM {$a} WHERE status = 'afwezig' AND lesdatum >= %s
                GROUP BY inschrijving_id HAVING COUNT(*) >= %d ) s
         JOIN {$i} i ON i.id = s.inschrijving_id
         WHERE i.status NOT IN ('afgewezen','uitgeschreven')
         ORDER BY s.aantal DESC, s.laatste DESC",
        arrahma_verschuif_datum( arrahma_vandaag(), -7 * ARRAHMA_SIGNAAL_WEKEN ),
        ARRAHMA_SIGNAAL_DREMPEL
    ) );
}

function arrahma_aanwezigheid_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $kaart = 'background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:1rem 1.25rem;margin-bottom:1rem';
    [ $van, $tot ] = arrahma_periode_uit_request();
    $export = function ( array $args ) {
        return wp_nonce_url( add_query_arg( $args, admin_url( 'admin.php?page=arrahma-aanwezigheid' ) ), 'arrahma_export_aanwezigheid' );
    };
    ?>
    <div class="wrap" style="max-width:1200px">
      <h1>Aanwezigheid</h1>
      <p><a class="button" href="<?= esc_url( arrahma_docenten_url() ) ?>" target="_blank">Docentenpagina openen</a>
         <a class="button" href="<?= esc_url( $export( [ 'export' => 'aanwezigheid', 'van' => $van, 'tot' => $tot ] ) ) ?>">Export aanwezigheid (deze periode)</a>
         <a class="button" href="<?= esc_url( $export( [ 'export' => 'aanwezigheid', 'alles' => 1 ] ) ) ?>">Export aanwezigheid (alles)</a>
         <a class="button" href="<?= esc_url( $export( [ 'export' => 'notities' ] ) ) ?>">Export notities</a></p>

      <div style="<?= $kaart ?>">
        <h2 style="margin-top:0;font-size:1.05rem">Signaallijst — <?= (int) ARRAHMA_SIGNAAL_DREMPEL ?>× of vaker afwezig zonder bericht in de afgelopen <?= (int) ARRAHMA_SIGNAAL_WEKEN ?> weken</h2>
        <?php arrahma_render_signaallijst(); ?>
      </div>

      <div style="<?= $kaart ?>">
        <h2 style="margin-top:0;font-size:1.05rem">Per groep</h2>
        <?php arrahma_render_aanwezigheid_matrix( $van, $tot ); ?>
      </div>
    </div>
    <?php
}

function arrahma_render_signaallijst(): void {
    $lijst = arrahma_signaallijst();
    if ( ! $lijst ) {
        echo '<p style="color:#888">Niemand op de lijst.</p>';
        return;
    }
    echo '<table class="widefat striped"><thead><tr><th>Leerling</th><th>Groep</th><th>Afwezig</th><th>Laatst</th><th>Bellen</th></tr></thead><tbody>';
    foreach ( $lijst as $r ) {
        $detail = add_query_arg( 'leerling', (int) $r->id, arrahma_docenten_url() );
        $bellen = $r->telefoon;
        if ( ! empty( $r->cp_anders ) && $r->cp_telefoon ) {
            $bellen = trim( $r->cp_voornaam . ' ' . $r->cp_achternaam ) . ': ' . $r->cp_telefoon . ( $r->telefoon ? ' · ' . $r->telefoon : '' );
        }
        echo '<tr><td><a href="' . esc_url( $detail ) . '" target="_blank">' . esc_html( trim( $r->voornaam . ' ' . $r->achternaam ) ) . '</a></td>'
            . '<td>' . esc_html( arrahma_slot_naam( (string) $r->rooster ) ) . '</td>'
            . '<td><strong>' . (int) $r->aantal . '×</strong></td>'
            . '<td>' . esc_html( arrahma_format_date( $r->laatste ) ) . '</td>'
            . '<td>' . esc_html( $bellen ) . '</td></tr>';
    }
    echo '</tbody></table>';
}

function arrahma_render_aanwezigheid_matrix( string $van, string $tot ): void {
    global $wpdb;
    $eenheden = arrahma_alle_eenheden();
    $sleutel  = sanitize_key( $_GET['eenheid'] ?? '' );
    ?>
    <form method="get" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:end;margin-bottom:1rem">
      <input type="hidden" name="page" value="arrahma-aanwezigheid">
      <label>Groep<br><select name="eenheid"><option value="">— kies —</option>
        <?php foreach ( $eenheden as $k => $e ) : ?>
          <option value="<?= esc_attr( $k ) ?>" <?= selected( $sleutel, $k, false ) ?>><?= esc_html( $e['label'] ) ?></option>
        <?php endforeach; ?>
      </select></label>
      <label>Van<br><input type="date" name="van" value="<?= esc_attr( $van ) ?>"></label>
      <label>Tot en met<br><input type="date" name="tot" value="<?= esc_attr( $tot ) ?>"></label>
      <button class="button button-primary">Toon</button>
    </form>
    <?php
    $eenheid = $eenheden[ $sleutel ] ?? null;
    if ( ! $eenheid ) return;

    if ( arrahma_verschuif_datum( $van, ARRAHMA_MAX_PERIODE_DAGEN ) < $tot ) {
        $van = arrahma_verschuif_datum( $tot, -ARRAHMA_MAX_PERIODE_DAGEN );
        echo '<p style="color:#888">Periode ingekort tot ' . (int) ARRAHMA_MAX_PERIODE_DAGEN . ' dagen, vanaf ' . esc_html( arrahma_format_date( $van ) ) . '. Gebruik de export voor langere periodes.</p>';
    }

    $slot    = $eenheid['slot'];
    $data    = arrahma_lesdata_in_periode( $slot, $van, $tot );
    $secties = arrahma_eenheid_secties( $eenheid );
    $table   = $wpdb->prefix . ARRAHMA_AANWEZIG_TABLE;
    $rows    = $wpdb->get_results( $wpdb->prepare(
        "SELECT inschrijving_id, lesdatum, status FROM {$table} WHERE rooster = %s AND lesdatum BETWEEN %s AND %s",
        $slot, $van, $tot
    ) );
    $raster = [];
    foreach ( $rows as $r ) $raster[ (int) $r->inschrijving_id ][ $r->lesdatum ] = $r->status;

    $letters = arrahma_aanwezig_letters();
    $kleuren = arrahma_aanwezig_kleuren();
    $labels  = arrahma_aanwezig_statussen();

    if ( ! $data ) {
        echo '<p style="color:#888">Geen lessen in deze periode.</p>';
        return;
    }

    echo '<p style="color:#666">';
    foreach ( $letters as $status => $letter ) {
        echo '<strong style="color:' . esc_attr( $kleuren[ $status ] ) . '">' . esc_html( $letter ) . '</strong> ' . esc_html( $labels[ $status ] ) . ' &nbsp; ';
    }
    echo '· niet ingevuld</p><div style="overflow-x:auto"><table class="widefat striped" style="width:auto;white-space:nowrap"><thead><tr><th>Leerling</th>';
    foreach ( $data as $d ) {
        echo '<th style="text-align:center;font-size:.75rem">' . esc_html( arrahma_datum_kort( $d ) ) . '</th>';
    }
    foreach ( $letters as $status => $letter ) {
        echo '<th style="text-align:center;color:' . esc_attr( $kleuren[ $status ] ) . '">' . esc_html( $letter ) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ( $secties as $sectie ) {
        if ( $sectie['titel'] !== '' ) {
            echo '<tr><td colspan="' . ( count( $data ) + 5 ) . '" style="font-weight:600;background:#f4f6f8">' . esc_html( $sectie['titel'] ) . '</td></tr>';
        }
        foreach ( $sectie['rijen'] as $rij ) {
            $telling = array_fill_keys( array_keys( $letters ), 0 );
            echo '<tr><td>' . esc_html( trim( $rij->voornaam . ' ' . $rij->achternaam ) ) . '</td>';
            foreach ( $data as $d ) {
                $status = $raster[ (int) $rij->id ][ $d ] ?? '';
                if ( isset( $telling[ $status ] ) ) $telling[ $status ]++;
                echo $status !== ''
                    ? '<td style="text-align:center;font-weight:700;color:' . esc_attr( $kleuren[ $status ] ?? '#888' ) . '" title="' . esc_attr( $labels[ $status ] ?? $status ) . '">' . esc_html( $letters[ $status ] ?? '?' ) . '</td>'
                    : '<td style="text-align:center;color:#ccc">·</td>';
            }
            foreach ( $telling as $aantal ) echo '<td style="text-align:center">' . (int) $aantal . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table></div>';
}
