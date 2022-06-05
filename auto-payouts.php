<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://www.fiverr.com/junaidzx90
 * @since             1.0.0
 * @package           Auto_Payouts
 *
 * @wordpress-plugin
 * Plugin Name:       Auto-payouts
 * Plugin URI:        https://www.fiverr.com
 * Description:       This is a short description of what the plugin does. It's displayed in the WordPress admin area.
 * Version:           1.0.0
 * Author:            Developer Junayed
 * Author URI:        https://www.fiverr.com/junaidzx90
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       auto-payouts
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'AUTO_PAYOUTS_VERSION', '1.0.0' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-auto-payouts-activator.php
 */
function activate_auto_payouts() {
	
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-auto-payouts-deactivator.php
 */
function deactivate_auto_payouts() {
	wp_clear_scheduled_hook('payouts_auto_paid');
}

register_activation_hook( __FILE__, 'activate_auto_payouts' );
register_deactivation_hook( __FILE__, 'deactivate_auto_payouts' );

// wp cron schedules
add_filter( 'cron_schedules', 'payouts_updater_twice_daily' );
function payouts_updater_twice_daily( $schedules ) {
	// Adds once weekly to the existing schedules.
	$schedules['twice_daily'] = array(
		'interval' => 12 * HOUR_IN_SECONDS,
		'display'  => __( 'Twice daily' ),
	);
	return $schedules;
}

if ( ! wp_next_scheduled( 'payouts_auto_paid' ) ) {
	wp_schedule_event( time(), 'twice_daily', 'payouts_auto_paid');
}

function wcusage_adding_activity($event_id, $event, $info) {
    $enable_activity_log = wcusage_get_setting_value('wcusage_enable_activity_log', '1');
    if($enable_activity_log) {

  		$event_id = sanitize_text_field($event_id);
  		$event = sanitize_text_field($event);

      	global $wpdb;
  		$table_name = $wpdb->prefix . 'wcusage_activity';

  		$wpdb->insert(
  			$table_name,
  			array(
  				'event_id' => $event_id,
  				'event' => $event,
				'user_id' => get_current_user_id(),
				'date' => current_time( 'mysql' ),
				'info' => $info,
  			)
  		);
  		$last_id = $wpdb->insert_id;
  		return $last_id;
    } else {
      return 0;
    }
}

add_action( "init", function(){
	global $wpdb;
	$lists = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}wcusage_payouts WHERE status = 'paid'");
	if($lists && !is_wp_error( $lists )){
		foreach($lists as $list){
			$userid = $list->userid;
			$couponid = $list->couponid;

			$currentpending1 = get_post_meta( $couponid, 'wcu_text_pending_payment_commission', true );
			
			if($currentpending1 && $currentpending1 > 0) { 
				update_post_meta( $couponid, 'wcu_text_pending_payment_commission', 0 );
			}

			$currentpending = get_user_meta( $userid, 'wcu_ml_pending_commission', true );
			if($currentpending && $currentpending > 0) { 
				update_user_meta( $userid, 'wcu_ml_pending_commission', 0 );
			}
		}
	}
} );

// Cron functionality
add_action( 'payouts_auto_paid', 'check_payouts_status' );
function check_payouts_status(){
	global $wpdb;
	try {
		$lists = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}wcusage_payouts WHERE status = 'pending'");
		if($lists && !is_wp_error( $lists )){
			foreach($lists as $list){
				$userid = $list->userid;
				$amount = $list->amount;
				$couponid = $list->couponid;

				$table_name = $wpdb->prefix . 'wcusage_payouts';
				$data = [ 'status' => 'paid' ];
				$where = [ 'id' => $list->id ];
				$wpdb->update( $table_name, $data, $where );

				$data2 = [ 'datepaid' => date('Y-m-d H:i:s') ];
				$where2 = [ 'id' => $list->id ];
				$wpdb->update( $table_name, $data2, $where2 );

				if($couponid) {
					$currentpending = get_post_meta( $couponid, 'wcu_text_pending_payment_commission', true );
					if(!$currentpending) { $currentpending = 0; }
						$newpending = $currentpending - $amount;
					if($newpending < 0) { $newpending = 0; }
						update_post_meta( $couponid, 'wcu_text_pending_payment_commission', $newpending );
				} else {
					if($userid) {
						$currentpending = get_user_meta( $userid, 'wcu_ml_pending_commission', true );
						if(!$currentpending) { $currentpending = 0; }
							$newpending = $currentpending - $amount;
						if($newpending < 0) { $newpending = 0; }
						update_user_meta( $userid, 'wcu_ml_pending_commission', $newpending );
					}
				}

				$activity_log = wcusage_adding_activity($id, 'payout_paid', $amount);
				  
				wcusage_credit_create_payout($amount, $userid);
			}
		}
	} catch (\Throwable $th) {
		//throw $th;
	}
}
