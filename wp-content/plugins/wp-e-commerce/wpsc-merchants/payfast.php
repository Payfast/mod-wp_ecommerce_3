<?php
/*
  Plugin Name: payfast.php
  Plugin URI: www.payfast.co.za
  Description: This plugin enables WP e-Commerce to interact with the Payfast payment gateway
  Version: 1.4.0
  Author: Jonathan Smit
  License: GPL3
   
  Copyright (c) 2010-2011 PayFast (Pty) Ltd
 */
 /** 
 * This payment module is free software; you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published
 * by the Free Software Foundation; either version 3 of the License, or (at
 * your option) any later version.
 * 
 * This payment module is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 * or FITNESS FOR A PARTICULAR PURPOSE. See the GNU Lesser General Public
 * License for more details.
 * 
 * @author     Jonathan Smit
 * @copyright  2010-2012 PayFast (Pty) Ltd
 * @license    http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link       http://www.payfast.co.za/help/wp_e-commerce
 */

// Set gateway variables for WP e-Commerce
$nzshpcrt_gateways[$num]['name'] = 'PayFast';
$nzshpcrt_gateways[$num]['internalname'] = 'payfast';
$nzshpcrt_gateways[$num]['function'] = 'gateway_payfast';
$nzshpcrt_gateways[$num]['form'] = "form_payfast";
$nzshpcrt_gateways[$num]['submit_function'] = "submit_payfast";
$nzshpcrt_gateways[$num]['payment_type'] = "payfast";
$nzshpcrt_gateways[$num]['supported_currencies']['currency_list'] = array( 'ZAR' );
$nzshpcrt_gateways[$num]['supported_currencies']['option_name'] = 'payfast_currcode';

// Include the PayFast common file
define( 'PF_DEBUG', ( get_option('payfast_debug') == 1  ? true : false ) );
require_once( 'payfast_common.inc' );

// {{{ gateway_payfast()
/**
 * gateway_payfast()
 *
 * Create the form/information which is submitted to the gateway.
 *
 * @param mixed $sep Separator
 * @param mixed $sessionid Session ID for user session
 * @return
 */
function gateway_payfast( $sep, $sessionid )
{
    // Variable declaration
    global $wpdb, $wpsc_cart;
    $pfAmount = 0;
    $pfDescription = '';
    $pfOutput = '';

    // Get purchase log
    $sql =
        "SELECT *
        FROM `". WPSC_TABLE_PURCHASE_LOGS ."`
        WHERE `sessionid` = ". $sessionid ."
        LIMIT 1";
    $purchase = $wpdb->get_row( $sql, ARRAY_A ) ;

    if( $purchase['totalprice'] == 0 )
    {
    	header( "Location: ". get_option( 'transact_url' ) . $sep ."sessionid=". $sessionid );
    	exit();
    }

    // Get cart contents
    $sql =
        "SELECT *
        FROM `". WPSC_TABLE_CART_CONTENTS ."`
        WHERE `purchaseid` = '". $purchase['id'] ."'";
    $cart = $wpdb->get_results( $sql, ARRAY_A ) ;

    // Lookup the currency codes and local price
    $sql =
        "SELECT `code`
        FROM `". WPSC_TABLE_CURRENCY_LIST ."`
        WHERE `id` = '". get_option( 'currency_type' ) ."'
        LIMIT 1";
    $local_curr_code = $wpdb->get_var( $sql );
    $pf_curr_code = get_option( 'payfast_currcode' );

    // Set default currency
    if( $pf_curr_code == '' )
    	$pf_curr_code = 'ZAR';

    // Convert from the currency of the users shopping cart to the currency
    // which the user has specified in their payfast preferences.
    $curr = new CURRENCYCONVERTER();

	$total = $wpsc_cart->calculate_total_price();
	$discount = $wpsc_cart->coupons_amount;

	// If PayFast currency differs from local currency
    if( $pf_curr_code != $local_curr_code )
    {
        pflog( 'Currency conversion required' );
        $pfAmount = $curr->convert( $total, $pf_curr_code, $local_curr_code );
	}
	// Else, if currencies are the same
    else
    {
        pflog( 'NO Currency conversion required' );
        $pfAmount = $total;

        // Create description from cart contents
        foreach( $cart as $cartItem )
        {
            $itemPrice = round( $cartItem['price'] * ( 100 + $cartItem['gst'] ) / 100, 2 );
            $itemShippingPrice = $cartItem['pnp'];    // No tax charged on shipping
            $itemPriceTotal = ( $itemPrice + $itemShippingPrice ) * $cartItem['quantity'] ;
            $pfDescription .= $cartItem['quantity'] .' x '. $cartItem['name'] .
                ' @ '. number_format( $itemPrice, 2, '.', ',' ) .'ea'.
                ' (Shipping = '. number_format( $itemShippingPrice, 2, '.', ',' ) .'ea)'.
                ' = '. number_format( $itemPriceTotal, 2, '.', ',' ) .';';
        }

        $pfDescription .= ' Base shipping = '. number_format( $purchase['base_shipping'], 2, '.', ',' ) .';';

        if( $discount > 0 )
            $pfDescription .= ' Discount = '. number_format( $discount, 2, '.', ',' ) .';';

        $pfDescription .= ' Total = '. number_format( $pfAmount, 2, '.', ',' );
	}

    // Use appropriate merchant identifiers
    // Live
    if( get_option('payfast_server') == 'LIVE' )
    {
        $merchantId = get_option( 'payfast_merchant_id' ); 
        $merchantKey = get_option( 'payfast_merchant_key' );
        $payfast_url = 'https://www.payfast.co.za/eng/process';
    }
    // Sandbox
    else
    {
        $merchantId = '10000100';
        $merchantKey = '46f0cd694581a'; 
        $payfast_url = 'https://sandbox.payfast.co.za/eng/process';
    }
    
    // Create URLs
    $returnUrl = get_option( 'transact_url' ) . $sep ."sessionid=". $sessionid ."&gateway=payfast";
    $cancelUrl = get_option( 'transact_url' );
    $notifyUrl = get_option( 'siteurl' ) .'/?itn_request=true';

    // Construct variables for post
    $data = array(
        // Merchant details
        'merchant_id' => $merchantId,
        'merchant_key' => $merchantKey,
        'return_url' => $returnUrl,
        'cancel_url' => $cancelUrl,
        'notify_url' => $notifyUrl,

        // Item details
    	'item_name' => get_option( 'blogname' ) .' purchase, Order #'. $purchase['id'],
    	'item_description' => $pfDescription,
    	'amount' => number_format( sprintf( "%01.2f", $pfAmount ), 2, '.', '' ),
        'm_payment_id' => $purchase['id'],
        'currency_code' => $pf_curr_code,
        'custom_str1' => $sessionid,
        
        // Other details
        'user_agent' => PF_USER_AGENT,
        );

    // Buyer details
    if( !empty( $_POST['collected_data'][get_option('payfast_form_name_first')] ) )
        $data['name_first'] = $_POST['collected_data'][get_option('payfast_form_name_first')];

    if( !empty( $_POST['collected_data'][get_option('payfast_form_name_last')] ) )
        $data['name_last'] = $_POST['collected_data'][get_option('payfast_form_name_last')];

    $email_data = $wpdb->get_results(
        "SELECT `id`, `type`
        FROM `".WPSC_TABLE_CHECKOUT_FORMS."`
        WHERE `type` IN ('email') AND `active` = '1'", ARRAY_A );

    foreach( (array)$email_data as $email )
    	$data['email_address'] = $_POST['collected_data'][$email['id']];

    if( !empty( $_POST['collected_data'][get_option('email_form_field')] ) && ( $data['email'] == null ) )
    	$data['email_address'] = $_POST['collected_data'][get_option('email_form_field')];

    // Create output string
    foreach( $data as $key => $val )
        $pfOutput .= $key .'='. urlencode( $val ) .'&';

    // Remove last ampersand
    $pfOutput = substr( $pfOutput, 0, -1 );

    // Display debugging information (if in debug mode)
	if( PF_DEBUG || ( defined( 'WPSC_ADD_DEBUG_PAGE' ) && ( WPSC_ADD_DEBUG_PAGE == true ) ) )
    {
      	echo "<a href='". $payfast_url ."?". $pfOutput ."'>Test the URL here</a>";
      	echo "<pre>". print_r( $data, true ) ."</pre>";
      	exit();
	}

    // Send to PayFast (GET)
    header( "Location: ". $payfast_url ."?". $pfOutput );
    exit();
}
// }}}
// {{{ nzshpcrt_payfast_itn()
/**
 * nzshpcrt_payfast_itn()
 *
 * Handle ITN callback from PayFast
 *
 * @return
 */
function nzshpcrt_payfast_itn()
{
    // Check if this is an ITN request
    // Has to be done like this (as opposed to "exit" as processing needs
    // to continue after this check.
    if( ( $_GET['itn_request'] == 'true' ) )
    {
        // Variable Initialization
        global $wpdb;
        $pfError = false;
        $pfErrMsg = '';
        $pfDone = false;
        $pfData = array();
        $pfHost = ( ( get_option( 'payfast_server' ) == 'LIVE' ) ? 'www' : 'sandbox' ) . '.payfast.co.za';
        $pfOrderId = '';
        $pfParamString = '';
        
        // Set debug email address
        if( get_option( 'payfast_debug_email' ) != '' )
            $pfDebugEmail = get_option( 'payfast_debug_email' );
        elseif( get_option( 'purch_log_email' ) != '' )
            $pfDebugEmail = get_option( 'purch_log_email' );
        else
            $pfDebugEmail = get_option( 'admin_email' );

        pflog( 'PayFast ITN call received' );
        
        //// Notify PayFast that information has been received
        if( !$pfError && !$pfDone )
        {
            header( 'HTTP/1.0 200 OK' );
            flush();
        }

        //// Get data sent by PayFast
        if( !$pfError && !$pfDone )
        {
            pflog( 'Get posted data' );
        
            // Posted variables from ITN
            $pfData = pfGetData();
        
            pflog( 'PayFast Data: '. print_r( $pfData, true ) );
        
            if( $pfData === false )
            {
                $pfError = true;
                $pfErrMsg = PF_ERR_BAD_ACCESS;
            }
        }

        //// Verify security signature
        if( !$pfError && !$pfDone )
        {
            pflog( 'Verify security signature' );
        
            // If signature different, log for debugging
            if( !pfValidSignature( $pfData, $pfParamString ) )
            {
                $pfError = true;
                $pfErrMsg = PF_ERR_INVALID_SIGNATURE;
            }
        }

        //// Verify source IP (If not in debug mode)
        if( !$pfError && !$pfDone && !PF_DEBUG )
        {
            pflog( 'Verify source IP' );
        
            if( !pfValidIP( $_SERVER['REMOTE_ADDR'] ) )
            {
                $pfError = true;
                $pfErrMsg = PF_ERR_BAD_SOURCE_IP;
            }
        }

        //// Get internal order and verify it hasn't already been processed
        if( !$pfError && !$pfDone )
        {
            // Get order data
            $sql =
                "SELECT * FROM `". WPSC_TABLE_PURCHASE_LOGS ."`
                WHERE `id` = ". $pfData['m_payment_id'] ."
                LIMIT 1";
            $purchase = $wpdb->get_row( $sql, ARRAY_A );

            pflog( "Purchase:\n". print_r( $purchase, true )  );

            // Check if order has already been processed
            // It has been "processed" if it has a status above "Order Received"
            if( $purchase['processed'] > get_option( 'payfast_pending_status' ) )
            {
                pflog( "Order has already been processed" );
                $pfDone = true;
            }
        }

        //// Verify data received
        if( !$pfError )
        {
            pflog( 'Verify data received' );
        
            $pfValid = pfValidData( $pfHost, $pfParamString );
        
            if( !$pfValid )
            {
                $pfError = true;
                $pfErrMsg = PF_ERR_BAD_ACCESS;
            }
        }
            
        //// Check data against internal order
        if( !$pfError && !$pfDone )
        {
            pflog( 'Check data against internal order' );

            // Check order amount
            if( !pfAmountsEqual( $pfData['amount_gross'], $purchase['totalprice'] ) )
            {
                $pfError = true;
                $pfErrMsg = PF_ERR_AMOUNT_MISMATCH;
            }
            // Check session ID
            elseif( strcasecmp( $pfData['custom_str1'], $purchase['sessionid'] ) != 0 )
            {
                $pfError = true;
                $pfErrMsg = PF_ERR_SESSIONID_MISMATCH;
            }
        }

        //// Check status and update order
        if( !$pfError && !$pfDone )
        {
            pflog( 'Check status and update order' );

            $sessionid = $pfData['custom_str1'];
            $transaction_id = $pfData['pf_payment_id'];
            $vendor_name = get_option( 'blogname');
            $vendor_url = get_option( 'siteurl');

    		switch( $pfData['payment_status'] )
            {
                case 'COMPLETE':
                    pflog( '- Complete' );

                    // Update the purchase status
					$data = array(
						'processed' => get_option( 'payfast_complete_status'),
						'transactid' => $transaction_id,
						'date' => time(),
					);

					wpsc_update_purchase_log_details( $sessionid, $data, 'sessionid' );
        			transaction_results( $sessionid, false, $transaction_id );

                    if( PF_DEBUG )
                    {
                        $subject = "PayFast ITN on your site";
                        $body =
                            "Hi,\n\n".
                            "A PayFast transaction has been completed on your website\n".
                            "------------------------------------------------------------\n".
                            "Site: ". $vendor_name ." (". $vendor_url .")\n".
                            "Purchase ID: ". $pfData['m_payment_id'] ."\n".
                            "PayFast Transaction ID: ". $pfData['pf_payment_id'] ."\n".
                            "PayFast Payment Status: ". $pfData['payment_status'] ."\n".
                            "Order Status Code: ". $d['order_status'];
                        mail( $pfDebugEmail, $subject, $body );
                    }
                    break;

    			case 'FAILED':
                    pflog( '- Failed' );

                    // If payment fails, delete the purchase log
        			$sql =
                        "SELECT * FROM `". WPSC_TABLE_CART_CONTENTS ."`
                        WHERE `purchaseid`='". $pfData['m_payment_id'] ."'";
        			$cart_content = $wpdb->get_results( $sql, ARRAY_A );
        			foreach( (array)$cart_content as $cart_item )
        				$cart_item_variations = $wpdb->query(
                            "DELETE FROM `". WPSC_TABLE_CART_ITEM_VARIATIONS ."`
                            WHERE `cart_id` = '". $cart_item['id'] ."'", ARRAY_A );

                    $wpdb->query( "DELETE FROM `". WPSC_TABLE_CART_CONTENTS ."` WHERE `purchaseid`='". $pfData['m_payment_id'] ."'" );
        			$wpdb->query( "DELETE FROM `". WPSC_TABLE_SUBMITED_FORM_DATA ."` WHERE `log_id` IN ('". $pfData['m_payment_id'] ."')" );
        			$wpdb->query( "DELETE FROM `". WPSC_TABLE_PURCHASE_LOGS ."` WHERE `id`='". $pfData['m_payment_id'] ."' LIMIT 1" );

                    $subject = "PayFast ITN Transaction on your site";
                    $body =
                        "Hi,\n\n".
                        "A failed PayFast transaction on your website requires attention\n".
                        "------------------------------------------------------------\n".
                        "Site: ". $vendor_name ." (". $vendor_url .")\n".
                        "Purchase ID: ". $purchase['id'] ."\n".
                        "User ID: ". $purchase['user_ID'] ."\n".
                        "PayFast Transaction ID: ". $pfData['pf_payment_id'] ."\n".
                        "PayFast Payment Status: ". $pfData['payment_status'];
                    mail( $pfDebugEmail, $subject, $body );
        			break;

    			case 'PENDING':
                    pflog( '- Pending' );

                    // Need to wait for "Completed" before processing
        			$sql =
                        "UPDATE `". WPSC_TABLE_PURCHASE_LOGS ."`
                        SET `transactid` = '". $transaction_id ."', `date` = '". time() ."'
                        WHERE `sessionid` = ". $sessionid ."
                        LIMIT 1";
        			$wpdb->query($sql) ;
        			break;

    			default:
                    // If unknown status, do nothing (safest course of action)
    			break;
            }
        }


        // If an error occurred
        if( $pfError )
        {
            pflog( 'Error occurred: '. $pfErrMsg );
            pflog( 'Sending email notification' );

             // Send an email
            $subject = "PayFast ITN error: ". $pfErrMsg;
            $body =
                "Hi,\n\n".
                "An invalid PayFast transaction on your website requires attention\n".
                "------------------------------------------------------------\n".
                "Site: ". $vendor_name ." (". $vendor_url .")\n".
                "Remote IP Address: ".$_SERVER['REMOTE_ADDR']."\n".
                "Remote host name: ". gethostbyaddr( $_SERVER['REMOTE_ADDR'] ) ."\n".
                "Purchase ID: ". $purchase['id'] ."\n".
                "User ID: ". $purchase['user_ID'] ."\n";
            if( isset( $pfData['pf_payment_id'] ) )
                $body .= "PayFast Transaction ID: ". $pfData['pf_payment_id'] ."\n";
            if( isset( $pfData['payment_status'] ) )
                $body .= "PayFast Payment Status: ". $pfData['payment_status'] ."\n";
            $body .=
                "\nError: ". $pfErrMsg ."\n";

            switch( $pfErrMsg )
            {
                case PF_ERR_AMOUNT_MISMATCH:
                    $body .=
                        "Value received : ". $pfData['amount_gross'] ."\n".
                        "Value should be: ". $purchase['totalprice'];
                    break;

                case PF_ERR_ORDER_ID_MISMATCH:
                    $body .=
                        "Value received : ". $pfData['m_payment_id'] ."\n".
                        "Value should be: ". $purchase['id'];
                    break;

                case PF_ERR_SESSION_ID_MISMATCH:
                    $body .=
                        "Value received : ". $pfData['custom_str1'] ."\n".
                        "Value should be: ". $purchase['sessionid'];
                    break;

                // For all other errors there is no need to add additional information
                default:
                    break;
            }

            mail( $pfDebugEmail, $subject, $body );
        }

        // Close log
        pflog( '', true );
    	exit();
   	}
}
// }}}
// {{{ submit_payfast()
/**
 * submit_payfast()
 *
 * Updates the options submitted by the config form (function "form_payfast"}
 *
 * @return
 */
function submit_payfast()
{
    if( isset( $_POST['payfast_server'] ) )
        update_option( 'payfast_server', $_POST['payfast_server'] );

    if( isset( $_POST['payfast_merchant_id'] ) )
        update_option( 'payfast_merchant_id', $_POST['payfast_merchant_id'] );

    if( isset( $_POST['payfast_merchant_key'] ) )
        update_option( 'payfast_merchant_key', $_POST['payfast_merchant_key'] );

    if( isset( $_POST['payfast_currcode'] ) && !empty( $_POST['payfast_currcode'] ) )
        update_option( 'payfast_currcode', $_POST['payfast_currcode'] );


    if( isset( $_POST['payfast_pending_status'] )  )
        update_option( 'payfast_pending_status', (int)$_POST['payfast_pending_status'] );

    if( isset( $_POST['payfast_complete_status'] )  )
        update_option( 'payfast_complete_status', (int)$_POST['payfast_complete_status'] );


    if( isset( $_POST['payfast_debug'] ) )
        update_option( 'payfast_debug', (int)$_POST['payfast_debug'] );

    if( isset( $_POST['payfast_debug_email'] ) )
        update_option( 'payfast_debug_email', $_POST['payfast_debug_email'] );

    foreach( (array)$_POST['payfast_form'] as $form => $value )
        update_option( ( 'payfast_form_'.$form ), $value );

    return( true );
}
// }}}
// {{{ form_payfast()
/**
 * form_payfast()
 *
 * Displays the configuration form for the admin area.
 *
 * @return
 */
function form_payfast()
{
    // Variable declaration
    global $wpdb, $wpsc_gateways;

    // Set defaults
    $options = array();
    $options['server'] = ( get_option( 'payfast_server' ) != '' ) ?
        get_option( 'payfast_server' ) : 'TEST';
    $options['merchant_id'] = ( get_option( 'payfast_merchant_id' ) != '' ) ?
        get_option( 'payfast_merchant_id' ) : '10000100';
    $options['merchant_key'] = ( get_option( 'payfast_merchant_key' ) != '' ) ?
        get_option( 'payfast_merchant_key' ) : '46f0cd694581a';

    $options['pending_status'] = ( get_option( 'payfast_pending_status' ) != '' ) ?
        get_option( 'payfast_pending_status' ) : 1;
    $options['complete_status'] = ( get_option( 'payfast_complete_status' ) != '' ) ?
        get_option( 'payfast_complete_status' ) : 3;

    $options['debug'] = ( (int)get_option( 'payfast_debug' ) != '' ) ?
        get_option( 'payfast_debug' ) : 0;
    $options['debug_email'] = ( get_option( 'payfast_debug_email' ) != '' ) ?
        get_option( 'payfast_debug_email' ) : '';

    $options['form_name_first'] = ( get_option( 'payfast_form_name_first' ) != '' ) ?
        get_option( 'payfast_form_name_first' ) : 2;
    $options['form_name_last'] = ( get_option( 'payfast_form_name_last' ) != '' ) ?
        get_option( 'payfast_form_name_last' ) : 3;

    // Generate output
    $output = '
        <tr>
      	  <td colspan="2">
      	    <span  class="wpscsmall description">
            Please <a href="https://www.payfast.co.za/user/register" target="_blank">register</a> on
            <a href="https://www.payfast.co.za" target="_blank">PayFast</a> to use this module.
            Your <em>Merchant ID</em> and <em>Merchant Key</em> are available on your
            <a href="http://www.payfast.co.za/acc/integration" target="_blank">Integration page</a> on the PayFast website.</span>
      	  </td>
        </tr>
        <tr>
          <td>Server:</td>
          <td>
            <input type="radio" value="LIVE" name="payfast_server" id="payfast_server1" '. ( $options['server'] == 'LIVE' ? 'checked' : '' ) .' />
              <label for="payfast_server1">Live</label>&nbsp;
            <input type="radio" value="TEST" name="payfast_server" id="payfast_server2" '. ( $options['server'] == 'TEST' ? 'checked' : '' ) .' />
              <label for="payfast_server2">Test</label>
         </td>
        </tr>
        <tr>
          <td>Merchant ID:</td>
          <td>
            <input type="text" size="40" value="'. $options['merchant_id'] .'" name="payfast_merchant_id" />
          </td>
        </tr>
        <tr>
          <td>Merchant Key:</td>
          <td>
            <input type="text" size="40" value="'. $options['merchant_key'] .'" name="payfast_merchant_key" /> <br />
          </td>
        </tr>'."\n";

    // Get list of purchase statuses
    global $wpsc_purchlog_statuses;
    pflog( "Purchase Statuses:\n". print_r( $wpsc_purchlog_statuses, true ) );

    $output .= '
        <tr>
          <td>Status for Pending Payments:</td>
          <td>
            <select name="payfast_pending_status">';

    foreach( $wpsc_purchlog_statuses as $status )
        $output .= '<option value="'. $status['order'] .'" '. ( ( $status['order'] == $options['pending_status'] ) ? 'selected' : '' ) .'>'. $status['label'] .'</option>';

    $output .= '
            </select>
          </td>
        </tr>
        <tr>
          <td>Status for Successful Payments:</td>
          <td>
            <select name="payfast_complete_status">';

    foreach( $wpsc_purchlog_statuses as $status )
        $output .= '<option value="'. $status['order'] .'" '. ( ( $status['order'] == $options['complete_status'] ) ? 'selected' : '' ) .'>'. $status['label'] .'</option>';

    $output .= '
            </select>
          </td>
        </tr>
        <tr>
          <td>Debugging:</td>
          <td>
            <input type="radio" value="1" name="payfast_debug" id="payfast_debug1" '. ( $options['debug'] == 1 ? 'checked' : '' ) .' />
              <label for="payfast_debug1">On</label>&nbsp;
            <input type="radio" value="0" name="payfast_debug" id="payfast_debug2" '. ( $options['debug'] == 0 ? 'checked' : '' ) .' />
              <label for="payfast_debug2">Off</label>
         </td>
        </tr>
        <tr>
          <td>Debug Email:</td>
          <td>
            <input type="text" size="40" name="payfast_debug_email" value="'. $options['debug_email'] .'" />
          </td>
        </tr>';

    // Get current store currency
    $sql =
        "SELECT `code`, `currency`
        FROM `". WPSC_TABLE_CURRENCY_LIST ."`
        WHERE `id` IN ('". absint( get_option( 'currency_type' ) ) ."')";
    $store_curr_data = $wpdb->get_row( $sql, ARRAY_A );

    $current_curr = get_option( 'payfast_currcode' );

    if( empty( $current_curr ) && in_array( $store_curr_data['code'], $wpsc_gateways['payfast']['supported_currencies']['currency_list'] ) )
    {
        update_option( 'payfast_currcode', $store_curr_data['code'] );
        $current_curr = $store_curr_data['code'];
    }

	if( $current_curr != $store_curr_data['code'] )
    {
		$output .= '
            <tr>
                <td colspan="2"><br></td>
            </tr>
            <tr>
                <td colspan="2"><strong class="form_group">Currency Converter</td>
            </tr>
            <tr>
        		<td colspan="2">Your website uses <strong>'. $store_curr_data['currency'] .'</strong>.
                    This currency is not supported by PayFast, please select a currency using the drop
                    down menu below. Buyers on your site will still pay in your local currency however
                    we will send the order through to PayFast using the currency below:</td>
            </tr>
            <tr>
                <td>Select Currency:</td>
                <td>
                    <select name="payfast_currcode">';

		$pf_curr_list = $wpsc_gateways['payfast']['supported_currencies']['currency_list'];

        $sql =
            "SELECT DISTINCT `code`, `currency`
            FROM `". WPSC_TABLE_CURRENCY_LIST ."`
            WHERE `code` IN ('". implode( "','", $pf_curr_list )."')";
		$curr_list = $wpdb->get_results( $sql, ARRAY_A );

		foreach( $curr_list as $curr_item )
        {
			$output .= "<option value='". $curr_item['code'] ."' ".
                ( $current_curr == $curr_item['code'] ? 'selected' : '' ) .">". $curr_item['currency'] ."</option>";
		}

        $output .=
            '   </select>
                </td>
            </tr>';
	}

    $output .= '
        <tr class="update_gateway" >
        	<td colspan="2">
        		<div class="submit">
        		<input type="submit" value="'. TXT_WPSC_UPDATE_BUTTON .'" name="updateoption"/>
        	</div>
        	</td>
        </tr>

        <tr class="firstrowth">
        	<td style="border-bottom: medium none;" colspan="2">
        		<strong class="form_group">Fields Sent to PayFast</strong>
        	</td>
        </tr>

        <tr>
            <td>First Name Field</td>
            <td>
              <select name="payfast_form[name_first]">
              '. nzshpcrt_form_field_list( $options['form_name_first'] ) .'
              </select>
            </td>
        </tr>
        <tr>
            <td>Last Name Field</td>
            <td>
              <select name="payfast_form[name_last]">
              '. nzshpcrt_form_field_list( $options['form_name_last'] ) .'
              </select>
            </td>
        </tr>';

    return $output;
}
// }}}

// Add ITN check to WordPress init
add_action( 'init', 'nzshpcrt_payfast_itn' );
?>