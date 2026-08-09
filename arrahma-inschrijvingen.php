<?php
/**
 * Plugin Name: Arrahma Inschrijvingen
 * Description: Slaat lesaanmeldingen op in de database en toont ze in een overzichtspagina met CSV-export.
 * Version:     1.7.0
 * Author:      Vereniging Arrahma
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ARRAHMA_TABLE',       'arrahma_inschrijvingen' );
define( 'ARRAHMA_VERSION',     '1.7.0' );
define( 'ARRAHMA_ROOSTER_CAP', 30 ); // max. aantal inschrijvingen per lesdagen-tijdslot (categorie 'kinderen')

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

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

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
function arrahma_category_labels(): array {
    return [
        'kinderen'         => 'Kinderen (6–11)',
        'tieners_broeders' => 'Tieners — Broeders (12–17)',
        'tieners_zusters'  => 'Tieners — Zusters (12–17)',
        'zusters_18plus'   => 'Zusters 18+',
        'broeders_18plus'  => 'Broeders 18+',
    ];
}

function arrahma_niveau_labels(): array {
    return [
        'instroom'  => 'Instroomniveau',
        'basis'     => 'Basisniveau',
        'gevorderd' => 'Gevorderd niveau',
    ];
}

function arrahma_roster_labels(): array {
    return [
        'za_zo_blok1' => 'Zaterdag & zondag — 09:00–11:00 (blok 1)',
        'za_zo_blok2' => 'Zaterdag & zondag — 11:15–13:15 (blok 2)',
        'za_zo_blok3' => 'Zaterdag & zondag — 13:30–15:30 (blok 3)',
        'ma_wo'       => 'Maandag & woensdag — 15:30–17:30',
        'di_do'       => 'Dinsdag & donderdag — 15:30–17:30',
    ];
}

/** Human-readable niveau label, with graceful fallback for legacy values. */
function arrahma_niveau_label( string $value ): string {
    return arrahma_niveau_labels()[ $value ] ?? ( $value !== '' ? ucfirst( $value ) : '—' );
}

/** Human-readable roster label, with fallback to the raw stored value. */
function arrahma_roster_label( string $value ): string {
    return arrahma_roster_labels()[ $value ] ?? ( $value !== '' ? $value : '—' );
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
      return 'lessen@vereniging-arrahma.nl';
    case 'zusters_18plus':
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
} );

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
    if ( $voor === 'kinderen' && $insert['rooster'] !== '' ) {
        $huidig = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE rooster = %s", $insert['rooster']
        ) );
        if ( $huidig >= ARRAHMA_ROOSTER_CAP ) {
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
        $huidig = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE rooster = %s", $rooster_waarde
        ) );
        if ( $huidig + $aantal_gevraagd > ARRAHMA_ROOSTER_CAP ) {
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
function arrahma_confirmation_details_html( array $rows ): string {
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

        $titel = $meer ? 'Ingeschrevene ' . $i : 'Gegevens ingeschrevene';
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

/**
 * Verstuurt de bevestigingsmail met een overzicht van de ingevulde gegevens.
 * Werkt voor één inschrijving én voor een gezin (meerdere rijen, één ouder).
 * Wordt gebruikt bij inschrijving én bij handmatig opnieuw versturen vanuit de admin.
 */
function arrahma_send_confirmation_email( string $email, array $rows, string $subject_prefix = '' ): void {
    if ( empty( $rows ) || ! $email ) return;

    $rows  = array_values( $rows );
    $first = arrahma_row_to_array( $rows[0] );
    $count = count( $rows );

    $aanhef = ! empty( $first['cp_anders'] ) ? ( $first['cp_voornaam'] ?? '' ) : ( $first['voornaam'] ?? '' );
    $aanhef = trim( $aanhef );

    $intro = $count === 1
        ? 'Bedankt voor je aanmelding. Hieronder vind je een bevestiging van de inschrijving.'
        : 'Bedankt voor je aanmelding. Hieronder vind je een bevestiging van de inschrijving van ' . $count . ' kinderen.';

    $ingedeeld = $count === 1 ? 'je definitief bent ingedeeld' : 'de kinderen definitief zijn ingedeeld';

    $inner_html = '
      <h2 style="margin:0 0 20px;font-size:22px;font-weight:700;color:#1a1a1a;">As-salāmu ʿalaykum' . ( $aanhef ? ' ' . esc_html( $aanhef ) : '' ) . ',</h2>

      <p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#444;">' . esc_html( $intro ) . '</p>

      <p style="margin:0 0 8px;font-size:15px;line-height:1.7;color:#444;">
        Afhankelijk van de beschikbaarheid wordt er contact met je opgenomen. Nadat ' . $ingedeeld . ' in een klas zal ' . arrahma_incasso_zin( $first['betaalwijze'] ?? 'maandelijks' ) . '.
      </p>

      ' . arrahma_confirmation_details_html( $rows ) . '

      <div style="border-left:3px solid #2d3a4a;padding:14px 18px;background:#f4f6f8;border-radius:0 6px 6px 0;margin:28px 0;">
        <p style="margin:0;font-size:13px;color:#555;line-height:1.6;">
          <strong style="color:#1a1a1a;">Controleer de gegevens hierboven.</strong><br>
          Klopt er iets niet of wil je iets wijzigen? Laat het ons weten via
          <a href="mailto:info@vereniging-arrahma.nl" style="color:#2d3a4a;font-weight:600;text-decoration:none;">info@vereniging-arrahma.nl</a>.
        </p>
      </div>

      <p style="margin:0;font-size:14px;color:#666;">
        Wassalāmu ʿalaykum wa raḥmatullāhi wa barakātuh,<br>
        <strong style="color:#1a1a1a;">Vereniging Arrahma</strong>
      </p>';

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: Vereniging Arrahma <oudercomite@vereniging-arrahma.nl>',
    ];

    wp_mail( $email, $subject_prefix . 'Bevestiging inschrijving — Vereniging Arrahma', arrahma_email_wrap( $inner_html ), $headers );
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

/** Bouwt een vooraf ingevulde Google Form-link voor één ouder. */
function arrahma_ouderavond_prefill_url( string $email, array $names ): string {
    return 'https://docs.google.com/forms/d/e/' . ARRAHMA_OUDERAVOND_FORM_ID . '/viewform'
        . '?usp=pp_url'
        . '&entry.' . ARRAHMA_OUDERAVOND_ENTRY_EMAIL . '=' . rawurlencode( $email )
        . '&entry.' . ARRAHMA_OUDERAVOND_ENTRY_KIND  . '=' . rawurlencode( implode( ', ', $names ) );
}

function arrahma_send_ouderavond_email( string $email, array $names, string $subject_prefix = '' ): void {
    $namen_html = esc_html( implode( ', ', $names ) );
    $form_url   = arrahma_ouderavond_prefill_url( $email, $names );

    $inner_html = '
      <h2 style="margin:0 0 20px;font-size:22px;font-weight:700;color:#1a1a1a;">As-salāmu ʿalaykum,</h2>

      <p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#444;">
        Dit bericht is voor de ouder/verzorger van <strong>' . $namen_html . '</strong>.
      </p>

      <p style="margin:0 0 28px;font-size:15px;line-height:1.7;color:#444;">
        Voorafgaand aan het nieuwe schooljaar organiseren wij twee ouderavonden. Geef via onderstaande knop aan welke avond je kunt bijwonen.
      </p>

      <div style="text-align:center;margin-bottom:28px;">
        <a href="' . esc_url( $form_url ) . '" style="display:inline-block;background:#2d3a4a;color:#ffffff;text-decoration:none;font-size:13px;font-weight:700;letter-spacing:0.05em;text-transform:uppercase;padding:12px 28px;border-radius:50px;">
          Kies je ouderavond &rarr;
        </a>
      </div>

      <div style="border-left:3px solid #2d3a4a;padding:14px 18px;background:#f4f6f8;border-radius:0 6px 6px 0;margin-bottom:28px;">
        <p style="margin:0;font-size:13px;color:#555;line-height:1.6;">
          Heb je vragen? Neem dan contact op via
          <a href="mailto:info@vereniging-arrahma.nl" style="color:#2d3a4a;font-weight:600;text-decoration:none;">info@vereniging-arrahma.nl</a>.
        </p>
      </div>

      <p style="margin:0;font-size:14px;color:#666;">
        Wassalāmu ʿalaykum wa raḥmatullāhi wa barakātuh,<br>
        <strong style="color:#1a1a1a;">Vereniging Arrahma</strong>
      </p>';

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: Vereniging Arrahma <oudercomite@vereniging-arrahma.nl>',
    ];

    wp_mail( $email, $subject_prefix . 'Ouderavond Vereniging Arrahma — kies je moment', arrahma_email_wrap( $inner_html ), $headers );
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
        'Overzicht',
        'Overzicht',
        'manage_options',
        'arrahma-dashboard',
        'arrahma_dashboard_page'
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

/** Bewerkformulier voor één inschrijving (GET toont, POST slaat op). */
function arrahma_render_edit_form( int $id ) {
    global $wpdb;
    $table = $wpdb->prefix . ARRAHMA_TABLE;
    $terug = admin_url( 'admin.php?page=arrahma-inschrijvingen' );

    $entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
    if ( ! $entry ) {
        echo '<div class="wrap"><h1>Inschrijving bewerken</h1>'
           . '<div class="notice notice-error"><p>Inschrijving niet gevonden.</p></div>'
           . '<a href="' . esc_url( $terug ) . '" class="button">&larr; Terug naar overzicht</a></div>';
        return;
    }

    $values  = arrahma_row_to_array( $entry );
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
            if ( $bezet >= ARRAHMA_ROOSTER_CAP ) {
                $errors[] = sprintf(
                    'Dit tijdslot zit vol (%d/%d) — %s. Kies een ander tijdslot.',
                    $bezet,
                    ARRAHMA_ROOSTER_CAP,
                    arrahma_roster_label( $values['rooster'] )
                );
            }
        }

        if ( empty( $errors ) ) {
            $update  = [];
            $formats = [];
            foreach ( arrahma_editable_fields() as $f ) {
                $update[ $f ] = ( $f === 'geboortedatum' ) ? ( $values[ $f ] ?: null ) : $values[ $f ];
                $formats[]    = ( $f === 'cp_anders' ) ? '%d' : '%s';
            }

            if ( $wpdb->update( $table, $update, [ 'id' => $id ], $formats, [ '%d' ] ) === false ) {
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
        <span class="dashicons dashicons-edit" style="font-size:1.5rem;margin-top:3px"></span>
        Inschrijving bewerken <span style="color:#999;font-weight:400">#<?= (int) $id ?></span>
      </h1>

      <?php if ( $notice ) : ?>
        <div class="notice notice-success is-dismissible"><p><?= esc_html( $notice ) ?></p></div>
      <?php endif; ?>

      <?php if ( ! empty( $errors ) ) : ?>
        <div class="notice notice-error"><p><strong>Niet opgeslagen:</strong></p><ul style="margin:0 0 .5rem 1.25rem;list-style:disc">
          <?php foreach ( $errors as $e ) : ?><li><?= esc_html( $e ) ?></li><?php endforeach; ?>
        </ul></div>
      <?php endif; ?>

      <p><a href="<?= esc_url( $terug ) ?>" class="button">&larr; Terug naar overzicht</a></p>

      <form method="post">
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
                    $vol        = $bezet_excl >= ARRAHMA_ROOSTER_CAP;
                ?>
                  <option value="<?= esc_attr( $val ) ?>"
                          <?= selected( $values['rooster'], $val, false ) ?>
                          <?= ( $vol && ! $is_huidig ) ? 'disabled' : '' ?>>
                    <?= esc_html( $label ) ?> — <?= (int) $bezet_disp ?>/<?= (int) ARRAHMA_ROOSTER_CAP ?><?= $vol && ! $is_huidig ? ' (vol)' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="description">Volle tijdsloten (<?= (int) ARRAHMA_ROOSTER_CAP ?> leerlingen) kunnen niet gekozen worden.</p>
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

        <p style="color:#888;font-size:.85rem">
          Ingeschreven op <?= esc_html( date_i18n( 'd-m-Y H:i', strtotime( $values['datum_inschrijving'] ) ) ) ?><?php if ( ! empty( $values['groep_id'] ) ) : ?> · onderdeel van een gezinsinschrijving<?php endif; ?>
        </p>

        <p class="submit">
          <button type="submit" name="arrahma_save_entry" value="1" class="button button-primary">Wijzigingen opslaan</button>
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

    // ── Bewerkformulier in plaats van de tabel
    if ( isset( $_GET['edit_entry'] ) ) {
        arrahma_render_edit_form( intval( $_GET['edit_entry'] ) );
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
        <a href="<?= esc_url( $export_url ) ?>" class="button button-secondary" style="display:flex;align-items:center;gap:.35rem">
          <span class="dashicons dashicons-download" style="margin-top:3px"></span> Exporteer als CSV
        </a>
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

    $types = [
        'ouderavond'  => 'Ouderavond-uitnodiging',
        'bevestiging' => 'Bevestiging inschrijving',
    ];

    // ── Versturen (na bevestiging via nonce-form)
    if (
        isset( $_POST['arrahma_send_emails'], $_POST['_wpnonce'] ) &&
        wp_verify_nonce( $_POST['_wpnonce'], 'arrahma_send_emails' )
    ) {
        $type     = sanitize_text_field( wp_unslash( $_POST['email_type'] ?? '' ) );
        $selected = array_map( 'sanitize_text_field', (array) ( $_POST['recipients'] ?? [] ) );

        if ( ! isset( $types[ $type ] ) ) {
            $result = [ 'error' => 'Kies een geldig e-mailtype.' ];
        } elseif ( empty( $selected ) ) {
            $result = [ 'error' => 'Selecteer minimaal één ouder.' ];
        } else {
            $sent = 0;
            foreach ( $selected as $key ) {
                $key = strtolower( $key );
                if ( ! isset( $recipients[ $key ] ) ) continue;
                $r = $recipients[ $key ];

                if ( $type === 'ouderavond' ) {
                    arrahma_send_ouderavond_email( $r['email'], $r['names'] );
                } else {
                    arrahma_send_confirmation_email( $r['email'], $r['rows'] );
                }
                $sent++;
            }
            $result = [ 'sent' => $sent, 'label' => $types[ $type ] ];
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
        } elseif ( ! is_email( $test_email ) ) {
            $test_result = [ 'error' => 'Vul een geldig e-mailadres in.' ];
        } else {
            $sample = arrahma_sample_row();
            $names  = [ trim( $sample->voornaam . ' ' . $sample->achternaam ) ];

            if ( $test_type === 'ouderavond' ) {
                arrahma_send_ouderavond_email( $test_email, $names, '[TEST] ' );
            } else {
                arrahma_send_confirmation_email( $test_email, [ $sample ], '[TEST] ' );
            }
            $test_result = [ 'sent_to' => $test_email, 'label' => $types[ $test_type ] ];
        }
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
          <?= esc_html( $result['label'] ) ?> verstuurd naar <?= (int) $result['sent'] ?> ouder<?= $result['sent'] !== 1 ? 's' : '' ?>.
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
          <button type="submit" name="arrahma_send_test" value="ouderavond" class="button">Test: Ouderavond-uitnodiging</button>
          <button type="submit" name="arrahma_send_test" value="bevestiging" class="button">Test: Bevestiging inschrijving</button>
        </form>
      </div>

      <p style="color:#555;max-width:680px">
        Verstuurt één e-mail per geselecteerde ouder (gegroepeerd op e-mailadres, dus een gezin krijgt één e-mail voor al hun kinderen).
        Er wordt niet bijgehouden wie al een e-mail kreeg — opnieuw versturen stuurt de e-mail nogmaals naar iedereen die je aanvinkt.
      </p>

      <?php if ( empty( $recipients ) ) : ?>
        <p style="color:#999">Geen inschrijvingen met een e-mailadres gevonden.</p>
      <?php else : ?>

        <form method="post" id="arrahma-email-form">
          <?php wp_nonce_field( 'arrahma_send_emails' ); ?>

          <h2 style="font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:#2d3a4a;margin:1.5rem 0 .5rem">Welke e-mail?</h2>
          <fieldset style="margin-bottom:1.25rem">
            <label style="display:block;margin-bottom:.4rem">
              <input type="radio" name="email_type" value="ouderavond" data-label="Ouderavond-uitnodiging" checked>
              <strong>Ouderavond-uitnodiging</strong>
              <span style="color:#888">— link naar het ouderavond-formulier, vooraf ingevuld met e-mailadres en kindnamen.</span>
            </label>
            <label style="display:block">
              <input type="radio" name="email_type" value="bevestiging" data-label="Bevestiging inschrijving">
              <strong>Bevestiging inschrijving</strong>
              <span style="color:#888">— overzicht van de ingevulde gegevens, zodat ouders ze kunnen controleren.</span>
            </label>
          </fieldset>

          <h2 style="font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:#2d3a4a;margin:1.5rem 0 .5rem">Naar wie?</h2>
          <table class="wp-list-table widefat fixed striped" style="max-width:800px;border-radius:8px;overflow:hidden">
            <thead>
              <tr>
                <td class="check-column" style="width:2.5rem;padding:8px 0 8px 10px">
                  <input type="checkbox" id="arrahma-cb-all" title="Alles selecteren">
                </td>
                <th>E-mailadres</th>
                <th>Kind(eren)</th>
                <th style="width:80px">Aantal</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ( $recipients as $key => $r ) : ?>
                <tr>
                  <th scope="row" class="check-column" style="padding:8px 0 8px 10px">
                    <input type="checkbox" name="recipients[]" value="<?= esc_attr( $key ) ?>">
                  </th>
                  <td><?= esc_html( $r['email'] ) ?></td>
                  <td><?= esc_html( implode( ', ', $r['names'] ) ) ?></td>
                  <td><?= count( $r['names'] ) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <p class="submit">
            <button type="submit" name="arrahma_send_emails" value="1" class="button button-primary">
              Verstuur naar geselecteerde ouders
            </button>
            <span id="arrahma-selected-count" style="margin-left:.75rem;color:#888;font-size:.85rem">0 geselecteerd</span>
          </p>
        </form>

        <script>
        (function () {
          var form = document.getElementById('arrahma-email-form');
          if (!form) return;

          var all     = document.getElementById('arrahma-cb-all');
          var counter = document.getElementById('arrahma-selected-count');

          function boxes() { return form.querySelectorAll('input[name="recipients[]"]'); }
          function checkedBoxes() { return form.querySelectorAll('input[name="recipients[]"]:checked'); }

          function updateCount() {
            var n = checkedBoxes().length;
            counter.textContent = n + ' geselecteerd';
            all.checked = (n > 0 && n === boxes().length);
            all.indeterminate = (n > 0 && n < boxes().length);
          }

          all.addEventListener('change', function () {
            var state = this.checked;
            boxes().forEach(function (cb) { cb.checked = state; });
            updateCount();
          });

          boxes().forEach(function (cb) { cb.addEventListener('change', updateCount); });

          form.addEventListener('submit', function (e) {
            var n = checkedBoxes().length;
            if (!n) {
              e.preventDefault();
              alert('Selecteer minimaal één ouder.');
              return;
            }
            var type = form.querySelector('input[name="email_type"]:checked');
            var label = type ? type.dataset.label : 'e-mail';
            if (!confirm('Verstuur "' + label + '" naar ' + n + ' ouder(s)? Dit kan niet ongedaan worden gemaakt.')) {
              e.preventDefault();
            }
          });

          updateCount();
        })();
        </script>

      <?php endif; ?>
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
        <h2>Lesdagen (rooster) — capaciteit <?= (int) ARRAHMA_ROOSTER_CAP ?> per tijdslot</h2>
        <div id="arrahma-ov-bars-rooster"></div>
      </div>
      <p class="arrahma-ov-rooster-hint" id="arrahma-ov-rooster-hint">Klik op "Kinderen" bij Categorie om de lesdagen-bezetting te zien.</p>
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
      const CAP              = <?php echo (int) ARRAHMA_ROOSTER_CAP; ?>;

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
          const pct = Math.round((count / (capped ? CAP : max)) * 100);
          const isActive = activeFilters[dim] === value;
          const isFull = capped && count >= CAP;
          return '<div class="arrahma-ov-bar-row ' + (isActive ? 'active' : '') + '" onclick="arrahmaOvToggleFilter(\'' + dim + '\',\'' + value + '\')">'
            + '<div class="arrahma-ov-bar-label">' + labels[value] + (isFull ? ' <span class="arrahma-ov-vol-badge">Vol</span>' : '') + '</div>'
            + '<div class="arrahma-ov-bar-track"><div class="arrahma-ov-bar-fill ' + (isFull ? 'full' : '') + '" style="width:' + Math.min(pct, 100) + '%"></div></div>'
            + '<div class="arrahma-ov-bar-count">' + count + (capped ? ' / ' + CAP : '') + '</div>'
            + '</div>';
        }).join('');
      }

      function render() {
        renderChips();
        renderSection('categorie', CATEGORIEEN, CATEGORIE_LABELS, 'arrahma-ov-bars-categorie', false);
        renderSection('niveau', NIVEAUS, NIVEAU_LABELS, 'arrahma-ov-bars-niveau', false);

        const kinderenActive = activeFilters.categorie === 'kinderen';
        document.getElementById('arrahma-ov-section-rooster').style.display = kinderenActive ? 'block' : 'none';
        document.getElementById('arrahma-ov-rooster-hint').style.display = kinderenActive ? 'none' : 'block';
        if (kinderenActive) renderSection('rooster', ROOSTERS, ROOSTER_LABELS, 'arrahma-ov-bars-rooster', true);

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
