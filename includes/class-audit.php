<?php
/**
 * 审计日志：记录所有工具操作
 *
 * @package Tianma
 */

defined( 'ABSPATH' ) || exit;

class Tianma_Audit {

	public static function log( $action, $detail = array(), $user_id = 0 ) {
		global $wpdb;
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		$wpdb->insert(
			$wpdb->prefix . 'tianma_audit',
			array(
				'user_id'    => $user_id,
				'action'     => sanitize_text_field( $action ),
				'detail'     => wp_json_encode( $detail, JSON_UNESCAPED_UNICODE ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}

	public static function recent( $limit = 50 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}tianma_audit ORDER BY id DESC LIMIT %d",
				$limit
			)
		);
	}
}
