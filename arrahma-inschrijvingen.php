<?php
/**
 * Plugin Name: Arrahma Inschrijvingen
 * Description: Slaat lesaanmeldingen op in de database en toont ze in een overzichtspagina met CSV-export.
 * Version:     1.16.0
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
define( 'ARRAHMA_VERSION',     '1.16.0' );

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

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    dbDelta( $log_sql );

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

    $rows = $wpdb->get_results( "SELECT rooster, COUNT(*) as cnt FROM {$table} WHERE rooster != '' GROUP BY rooster" );
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

/** Verstuurt een HTML-e-mail met de vaste afzender. */
function arrahma_wp_mail( string $to, string $subject, string $html ): bool {
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: Vereniging Arrahma <oudercomite@vereniging-arrahma.nl>',
    ];
    // WordPress zet emoji in e-mails om naar afbeeldingen op s.w.org; veel mailprogramma's blokkeren
    // die, waardoor 📅 en 🕐 verdwijnen. Voor onze e-mails laten we de tekens gewoon staan.
    $had_filter = remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
    $ok         = wp_mail( $to, $subject, $html, $headers );
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
function arrahma_send_template_email( string $key, string $email, array $rows, string $subject_prefix = '', array $extra = [] ): bool {
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
    return arrahma_wp_mail( $email, $subject_prefix . $r['onderwerp'], arrahma_email_wrap( $r['html'] ) );
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
    $rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE email != '' ORDER BY email, id" );

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
function arrahma_send_to_recipient( array $recipient, string $type, string $doelgroep, bool $skip_sent ): string {
    $rows = arrahma_rows_for_email_type( $recipient['rows'], $type, $doelgroep );
    if ( empty( $rows ) ) return 'overgeslagen';

    if ( $skip_sent && isset( arrahma_sent_log( $type )[ strtolower( $recipient['email'] ) ] ) ) {
        return 'overgeslagen';
    }

    $ok = arrahma_send_template_email( $type, $recipient['email'], $rows, '', [ 'doelgroep' => $doelgroep ] );

    arrahma_log_email( $recipient['email'], $type, $doelgroep, count( $rows ), $ok );
    return $ok ? 'verstuurd' : 'mislukt';
}

/**
 * Verstuurt één batch. De browser roept dit herhaald aan, telkens met de volgende ARRAHMA_BATCH_SIZE
 * ontvangers, zodat er nooit één verzoek is dat langer duurt dan een krappe max_execution_time.
 */
add_action( 'wp_ajax_arrahma_send_batch', 'arrahma_ajax_send_batch' );

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

    $recipients = arrahma_email_recipients();
    $resultaat  = [ 'verstuurd' => 0, 'overgeslagen' => 0, 'mislukt' => [] ];

    foreach ( $keys as $key ) {
        $key = strtolower( $key );
        if ( ! isset( $recipients[ $key ] ) ) { $resultaat['overgeslagen']++; continue; }

        $status = arrahma_send_to_recipient( $recipients[ $key ], $type, $doelgroep, $skip_sent );
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
        "SELECT COUNT(*) FROM {$table} WHERE rooster = %s AND id != %d",
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
        if ( ! in_array( $values['status'], [ 'nieuw', 'verwerkt', 'afgewezen' ], true ) ) {
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
                <?php foreach ( [ 'nieuw', 'verwerkt', 'afgewezen' ] as $s ) : ?>
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
        if ( in_array( $status, [ 'nieuw', 'verwerkt', 'afgewezen' ], true ) ) {
            $wpdb->update( $table, [ 'status' => $status ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
            echo '<div class="notice notice-success is-dismissible"><p>Status bijgewerkt.</p></div>';
        }
    }

    // ── Filter
    $filter = isset( $_GET['status_filter'] ) ? sanitize_text_field( $_GET['status_filter'] ) : '';
    $where  = $filter ? $wpdb->prepare( 'WHERE status = %s', $filter ) : '';

    $entries = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY datum_inschrijving DESC" );

    $totals = $wpdb->get_results( "SELECT status, COUNT(*) as cnt FROM {$table} GROUP BY status" );
    $counts = [ 'nieuw' => 0, 'verwerkt' => 0, 'afgewezen' => 0, 'totaal' => 0 ];
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
          <?php foreach ( [ '' => 'Alle', 'nieuw' => 'Nieuw', 'verwerkt' => 'Verwerkt', 'afgewezen' => 'Afgewezen' ] as $val => $lbl ) :
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
                    <?php foreach ( [ 'nieuw', 'verwerkt', 'afgewezen' ] as $s ) : ?>
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

                $status = arrahma_send_to_recipient( $recipients[ $key ], $type, $doelgroep, $skip_sent );
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
        } else {
            arrahma_send_template_email( $test_type, $test_email, [ arrahma_sample_row() ], '[TEST] ' );
            $test_result = [ 'sent_to' => $test_email, 'label' => $types[ $test_type ] ];
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
          Testmail "<?= esc_html( $test_result['label'] ) ?>" verstuurd naar <?= esc_html( $test_result['sent_to'] ) ?> (met voorbeeldgegevens van de meest recente inschrijving).
        </p></div>
      <?php endif; ?>

      <div style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:1.25rem 1.5rem;max-width:680px;margin-bottom:1.5rem">
        <h2 style="font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:#2d3a4a;margin:0 0 .5rem">Testmail versturen</h2>
        <p style="color:#888;font-size:.85rem;margin:0 0 1rem">
          Stuurt één van de twee e-mails naar een adres naar keuze, gevuld met de gegevens van de meest recente inschrijving (onderwerp krijgt een "[TEST]" voorvoegsel). Handig om het uiterlijk te controleren zonder een echte ouder te mailen.
        </p>
        <form method="post" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center">
          <?php wp_nonce_field( 'arrahma_send_test', '_wpnonce_test' ); ?>
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

            if (!confirm('Verstuur "' + label + '"' + dgTxt + ' naar ' + boxenAan.length +
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
