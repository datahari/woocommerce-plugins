<?php
add_action( 'woocommerce_checkout_process', 'dh_validate_old_order_field' );
add_action( 'woocommerce_thankyou', 'dh_combine_orders', 10, 1 );
add_action( 'woocommerce_checkout_update_order_meta', 'dh_update_order_meta' );
add_action( 'woocommerce_before_order_notes', 'dh_add_checkout_old_order_field' );

// save number of desired old order to new post metadata
function dh_update_order_meta( $order_id )  {
	if ( isset( $_POST['old-order-number'] ) ) {
		update_post_meta( $order_id, '_dh_old_order_number', sanitize_text_field( $_POST['old-order-number'] ) );
	}
}

// add field for previous order number to checkout form
function dh_add_checkout_old_order_field( $checkout ) { 
	woocommerce_form_field( 'old-order-number', array(        
		'type' => 'text',        
		'class' => array( 'form-row-wide' ),        
		'label' => __( 'Lisää tuotteet edelliseen tilaukseen' ),
		'description' => __( 'Syötä avoimen tilauksen numero, jos haluat lisätä tuotteet aiempaan tilaukseen.' ),
		'placeholder' => 'Avoimen tilauksen numero',        
		'required' => false,        
		'default' => '',        
	), $checkout->get_value( 'old-order-number' ) );
}

// check if the order number the user gave is valid
function dh_validate_old_order_field() {    
	$order_number = trim( rtrim( $_POST['old-order-number'] ) );
	
	if( empty( $order_number ) ) {
		return;
	}
	
	if ( ! dh_can_add_to_order( $order_number ) ) {
		wc_add_notice( __( 'Tähän tilaukseen ei voi lisätä enää tuotteita. Syötä toinen tilausnumero tai jätä tyhjäksi.' ), 'error' );
	}
}

// check if an order can be added to another one
function dh_can_add_to_order( $old_order_id, $new_order_id = null ) {
	$old_order = wc_get_order( $old_order_id );
	if( ! $old_order ) {
		return false;
	}
	
	// if old order is not in processing status
	if( "processing" !== $old_order->get_status() ) {
		return false;
	}
	
	// if we are only checking status of old order
	if( null === $new_order_id ) {
		return true;
	}
	
	$new_order = wc_get_order( $new_order_id );
	if( ! $new_order ) {
		return false;
	}
	
	// check if billing information matches
	$old_order_billing = serialize( $old_order->get_address( 'billing' ) );
	$new_order_billing = serialize( $new_order->get_address( 'billing' ) );
	
	if( $old_order_billing != $new_order_billing ) {
		return false;
	}
	
	// check if shipping information matches
	$old_order_shipping = serialize( $old_order->get_address( 'shipping' ) );
	$new_order_shipping = serialize( $new_order->get_address( 'shipping' ) );
	
	if( $old_order_shipping != $new_order_shipping ) {
		return false;
	}
	
	// if everything is ok, we can combine these orders
	return true;
}

function dh_combine_orders( $new_order_id ) {
	global $wpdb;
	
	$new_order = wc_get_order( $new_order_id );
	
	$previous_order_id = get_post_meta( $new_order_id, '_dh_old_order_number', true );
	$previous_order = wc_get_order( $previous_order_id );
	
	if( ! dh_can_add_to_order( $previous_order_id, $new_order_id ) ) {
		return false;
	}
	
	// move order items from new order to old order
	$wpdb->update( 
		$wpdb->prefix . "woocommerce_order_items", 
		['order_id' => $previous_order_id], 
		['order_id' => $new_order_id], 
		['%d'], 
		['%d']
	);
	
	// recalculate totals of old order
	$previous_order->calculate_totals( true );
	
	// add note to old order
	$previous_order->add_order_note( __( 'Yhdistetty asiakkaan uusi tilaus tähän tilaukseen', 'dh_combine_orders' ) );

	// delete new order
	wp_delete_post( $new_order_id );
}
