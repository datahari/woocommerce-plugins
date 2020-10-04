<?php
// määritetään aukioloajat
define( "STORE_OPENING_HOURS", array(
    "Mon" => "09:00-20:00",
    "Tue" => "09:00-20:00",
    "Wed" => "09:00-20:00",
    "Thu" => "09:00-20:00",
    "Fri" => "09:00-20:00",
    "Sat" => "14:00-20:00",
    "Sun" => false, // suljettu sunnuntaina
) );

// asetetaan aikavyöhyke (http://php.net/manual/en/timezones.php)
date_default_timezone_set( 'Europe/Helsinki' );

// palauttaa tietyn viikonpäivän aukioloajat tai false,
// jos kauppa ei ole kyseisenä päivänä ollenkaan auki
// esim. opening_hours( "Tue" );
function opening_hours( $day ) {
    if( ! isset( STORE_OPENING_HOURS[$day] ) ) {
        return false;
    }

    $opening_hours = STORE_OPENING_HOURS[$day];

    if( false === $opening_hours ) {
        return false;
    }

    $hours = explode( "-", $opening_hours );
    
    // lisätään etunollat, jotta aikojen vertailu toimii oikein
    $from = str_pad( $hours[0], 5, "0", STR_PAD_LEFT );
    $to   = str_pad( $hours[1], 5, "0", STR_PAD_LEFT );

    return array(
        'day'  => $day,
        'from' => $from,
        'to'   => $to,
    );
}

// palauttaa aukioloajan seuraavalta päivältä, kun kauppa on auki
function next_opening_hours( $timestamp = null ) {
    if( null === $timestamp ) {
        $timestamp = time();
    }
    
    $opening_hours = STORE_OPENING_HOURS;

    // haetaan huomisen viikonpäivä
    $tomorrow = strtotime( "tomorrow", $timestamp );
    $day_tomorrow = date( "D", $tomorrow );

    $count = 0;
    // etsitään seuraava päivä, kun kauppa on auki
    while( false === opening_hours( $day_tomorrow ) ) {
        $tomorrow = strtotime( "tomorrow", $tomorrow );
        $day_tomorrow = date( "D", $tomorrow );

        // poistutaan while-loopista jos kauppa ei ole koskaan auki
        if( $count++ > 7 ) {
            return false;
        }
    }

    return opening_hours( $day_tomorrow );
}

// palauttaa true / false sen mukaan onko kauppa auki vai ei tiettynä päivänä / kellonaikana
function store_is_open( $timestamp = null ) {
    if( null === $timestamp ) {
        $timestamp = time();
    }
    
    $day = date( "D", $timestamp );	
    $opening = opening_hours( $day );

    if( false === $opening ) {
        return false;
    }

    $time = date( "H:i", $timestamp );

    return ( $time >= $opening['from'] && $time <= $opening['to'] );
}

// estä ostaminen kun kauppa on suljettu
add_filter( 'woocommerce_variation_is_purchasable', 'disable_purchases_in_shop', 10, 2 );
add_filter( 'woocommerce_is_purchasable', 'disable_purchases_in_shop', 10, 2 );
function disable_purchases_in_shop( $purchasable, $product ) {
    if( ! store_is_open() ) {
        return false;
    }
    return $purchasable;
}

// lisää ilmoitus kauppaan, kun se on kiinni
add_action( 'template_redirect', 'shop_opening_hours_notice' );
function shop_opening_hours_notice() {
    // näytetään ilmoitus vain verkkokaupan sivuilla
    if( ! is_cart() && ! is_checkout() && ! is_woocommerce() ) {
        return false;
    }
    if ( ! store_is_open() ) {
        $open_today = opening_hours( date( "D" ) );

        if( false === $open_today ) {
            // englanninkielisten lyhenteiden muunnostaulukko suomenkielisiin viikonpäiviin
            $day_names = array(
                "Mon" => "maanantaina",
                "Tue" => "tiistaina",
                "Wed" => "keskiviikkona",
                "Thu" => "torstaina",
                "Fri" => "perjantaina",
                "Sat" => "lauantaina",
                "Sun" => "sunnuntaina",
            );

            // jos kauppa ei ole tänään auki ollenkaan, kerrotaan,
            // minä päivänä ja mihin kellonaikaan se aukeaa
            $open_next = next_opening_hours();
            
            if( false === $open_next ) {
                // jos kauppa ei ole koskaan auki
                $notice    = __( "Verkkokauppamme on suljettu" );	
            } else {
                $notice    = __( "Verkkokauppamme aukeaa %s klo %s" );
                $day_name  = $day_names[$open_next['day']];
                $notice    = sprintf( $notice, $day_name, $open_next['from'] );
            }

            wc_add_notice( $notice, 'error' );
        } else {
            // jos kauppa ei ole tänään vielä auki, kerrotaan, milloin se aukeaa
            $notice = __( "Verkkokauppamme aukeaa kello %s - Voit tilata tuotteita verkkokaupastamme klo %s - %s" );
            $notice = sprintf( $notice, $open_today['from'], $open_today['from'], $open_today['to'] );

            wc_add_notice( $notice, 'error' );
        }
    }
}
