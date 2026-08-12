<?php
/**
 * Token 用量统计
 *
 * 记录每次 LLM 调用的 token 消耗，支持累计 / 今日 / 会话维度统计。
 *
 * @package Tianma
 */

defined( 'ABSPATH' ) || exit;

class Tianma_Usage {

	public static function log( $user_id, $conv_id, $prompt_tokens, $completion_tokens ) {
		global $wpdb;
		$prompt = (int) $prompt_tokens;
		$comp   = (int) $completion_tokens;
		if ( $prompt + $comp <= 0 ) {
			return;
		}
		$wpdb->insert(
			$wpdb->prefix . 'tianma_usage',
			array(
				'user_id'           => (int) $user_id,
				'conversation_id'   => (int) $conv_id,
				'prompt_tokens'     => $prompt,
				'completion_tokens' => $comp,
				'total_tokens'      => $prompt + $comp,
				'created_at'        => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%d', '%d', '%s' )
		);
	}

	/** 汇总统计 */
	public static function stats( $user_id, $conv_id = 0 ) {
		global $wpdb;
		$t = $wpdb->prefix . 'tianma_usage';

		$total = $wpdb->get_row( $wpdb->prepare(
			"SELECT COALESCE(SUM(prompt_tokens),0) AS prompt_tokens,
			        COALESCE(SUM(completion_tokens),0) AS completion_tokens,
			        COALESCE(SUM(total_tokens),0) AS total_tokens,
			        COUNT(*) AS calls
			 FROM {$t} WHERE user_id = %d",
			(int) $user_id
		) );

		$today = $wpdb->get_row( $wpdb->prepare(
			"SELECT COALESCE(SUM(prompt_tokens),0) AS prompt_tokens,
			        COALESCE(SUM(completion_tokens),0) AS completion_tokens,
			        COALESCE(SUM(total_tokens),0) AS total_tokens
			 FROM {$t} WHERE user_id = %d AND DATE(created_at) = CURDATE()",
			(int) $user_id
		) );

		$conv = $conv_id ? $wpdb->get_row( $wpdb->prepare(
			"SELECT COALESCE(SUM(prompt_tokens),0) AS prompt_tokens,
			        COALESCE(SUM(completion_tokens),0) AS completion_tokens,
			        COALESCE(SUM(total_tokens),0) AS total_tokens
			 FROM {$t} WHERE user_id = %d AND conversation_id = %d",
			(int) $user_id,
			(int) $conv_id
		) ) : null;

		// 最近一次调用的用量（"当前"消耗）
		$last = $wpdb->get_row( $wpdb->prepare(
			"SELECT prompt_tokens, completion_tokens, total_tokens FROM {$t}
			 WHERE user_id = %d ORDER BY id DESC LIMIT 1",
			(int) $user_id
		) );

		$map = function ( $r ) {
			return array(
				'prompt_tokens'     => (int) ( $r->prompt_tokens ?? 0 ),
				'completion_tokens' => (int) ( $r->completion_tokens ?? 0 ),
				'total_tokens'      => (int) ( $r->total_tokens ?? 0 ),
			);
		};

		return array(
			'total' => $map( $total ),
			'today' => $map( $today ),
			'conversation' => $conv ? $map( $conv ) : null,
			'last_call'    => $last ? array(
				'prompt_tokens'     => (int) $last->prompt_tokens,
				'completion_tokens' => (int) $last->completion_tokens,
				'total_tokens'      => (int) $last->total_tokens,
			) : null,
			'calls' => (int) ( $total->calls ?? 0 ),
		);
	}
}
