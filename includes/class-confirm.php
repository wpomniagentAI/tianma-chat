<?php
/**
 * 高危操作确认机制
 *
 * Agent 遇到高危工具时，不直接执行，而是挂起上下文并生成确认码；
 * 前端展示确认卡片，用户批准后通过确认码续跑 Agent 循环。
 *
 * @package Tianma
 */

defined( 'ABSPATH' ) || exit;

class Tianma_Confirm {

	/**
	 * 挂起一个待确认的高危工具调用
	 *
	 * @param array $payload 挂起上下文（messages 快照、工具调用信息等）
	 * @return array { confirm_id, tool_name, summary }
	 */
	public static function create( $payload ) {
		global $wpdb;

		$hash = wp_generate_password( 32, false, false );

		$wpdb->insert(
			$wpdb->prefix . 'tianma_confirmations',
			array(
				'confirm_hash' => $hash,
				'payload'      => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
				'status'       => 'pending',
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		return array(
			'confirm_id' => $hash,
			'tool_name'  => isset( $payload['tool_name'] ) ? $payload['tool_name'] : '',
			'summary'    => isset( $payload['summary'] ) ? $payload['summary'] : '高危操作',
			'args'       => isset( $payload['tool_args'] ) ? $payload['tool_args'] : array(),
		);
	}

	/** 读取挂起请求 */
	public static function get( $hash ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}tianma_confirmations WHERE confirm_hash = %s AND status = 'pending'",
				$hash
			)
		);
		if ( ! $row ) {
			return null;
		}
		return array(
			'id'      => (int) $row->id,
			'hash'    => $row->confirm_hash,
			'payload' => json_decode( $row->payload, true ),
		);
	}

	/** 标记已处理 */
	public static function resolve( $hash, $status ) {
		global $wpdb;
		return $wpdb->update(
			$wpdb->prefix . 'tianma_confirmations',
			array( 'status' => $status ),
			array( 'confirm_hash' => $hash ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/** 清理过期的挂起请求（>2 小时） */
	public static function cleanup_old() {
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->prefix}tianma_confirmations WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)"
		);
	}
}
