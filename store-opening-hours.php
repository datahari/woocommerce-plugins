<?php
// määritetään aukioloajat
define( "STORE_OPENING_HOURS", array(
    "Mon" => "09:00-20:00",
    "Tue" => "09:00-11:00,12:00-15:00", // tiistaina auki 9-11 ja 12-15
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

    $opening_hours = explode( ",", $opening_hours );
    
    $hour_list = [];
    
    // käydään läpi päivän kaikki aukioloajat
    foreach( $opening_hours as $time_slot ) {
        $hours = explode( "-", trim( $time_slot ) );

        // lisätään etunollat, jotta aikojen vertailu toimii oikein
        $from = str_pad( $hours[0], 5, "0", STR_PAD_LEFT );
        $to   = str_pad( $hours[1], 5, "0", STR_PAD_LEFT );

        $hour_list[] = array(
            'day'  => $day,
            'from' => $from,
            'to'   => $to,
        );
    }
    
    // palautetaan lista päivän aukioloajoista
    return $hour_list;
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
    $opening_hours = opening_hours( $day );

    if( false === $opening_hours ) {
        return false;
    }
    
    // jos nykyinen kellonaika on jonkin aukioloajan sisällä, palautetaan true
    foreach( $opening_hours as $time_slot ) {
        $time = date( "H:i", $timestamp );
        $is_open = ( $time >= $time_slot['from'] && $time <= $time_slot['to'] );
        
        if( $is_open ) {
            return true;
        }
    }
    
    // jos aukioloaikoja ei löytynyt, palautetaan false
    return false;
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
                $day_name  = $day_names[$open_next[0]['day']];
                $notice    = sprintf( $notice, $day_name, $open_next[0]['from'] );
            }

            wc_add_notice( $notice, 'error' );
        } else {
            $next_open_time = "";
            $open_times_readable = array();
            
            // haetaan tämän päivän seuraava aukioloaika
            foreach( $open_today as $time_slot ) {
                if( ! $next_open_time && $time_slot['from'] > date( "H:i" ) ) {
                    $next_open_time = $time_slot['from'];
                }
                
                // tehdään selkokielinen lista aukioloajoista
                $open_times_readable[] = $time_slot['from'] . " - " . $time_slot['to'];
            }
            
            // erotellaan aukioloajat pilkulla
            $open_times_readable = implode( ", ", $open_times_readable );
            
            // lisätään viimeisen pilkun tilalle "ja"
            $open_times_readable = preg_replace( '/, ([0-9: -]+)$/', ' ja $1', $open_times_readable );
            
            // jos kauppa ei ole tänään vielä auki, kerrotaan, milloin se aukeaa
            $notice = __( "Verkkokauppamme aukeaa kello %s - Voit tilata tuotteita verkkokaupastamme klo %s" );
            $notice = sprintf( $notice, $next_open_time, $open_times_readable );

            wc_add_notice( $notice, 'error' );
        }
    }
}
