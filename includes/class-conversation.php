<?php
/**
 * 会话管理：微信式聊天记录持久化
 *
 * - tianma_conversations：会话（标题/归属/时间）
 * - tianma_messages：消息（角色/内容）
 *
 * @package Tianma
 */

defined( 'ABSPATH' ) || exit;

class Tianma_Conversation {

	/* ---------------------------------------------------------------------
	 * 会话 CRUD
	 * ------------------------------------------------------------------- */

	public static function create( $user_id, $title = '' ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert(
			$wpdb->prefix . 'tianma_conversations',
			array(
				'user_id'    => (int) $user_id,
				'title'      => '' === $title ? '新对话' : mb_substr( sanitize_text_field( $title ), 0, 120 ),
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	public static function get( $id, $user_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}tianma_conversations WHERE id = %d AND user_id = %d",
			(int) $id,
			(int) $user_id
		) );
	}

	public static function list_all( $user_id, $limit = 50 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT c.*,
				(SELECT COUNT(*) FROM {$wpdb->prefix}tianma_messages m WHERE m.conversation_id = c.id) AS msg_count,
				(SELECT m.content FROM {$wpdb->prefix}tianma_messages m
				 WHERE m.conversation_id = c.id AND m.role = 'assistant' ORDER BY m.id DESC LIMIT 1) AS last_reply
			 FROM {$wpdb->prefix}tianma_conversations c
			 WHERE c.user_id = %d
			 ORDER BY c.updated_at DESC LIMIT %d",
			(int) $user_id,
			$limit
		) );
	}

	public static function delete( $id, $user_id ) {
		global $wpdb;
		$conv = self::get( $id, $user_id );
		if ( ! $conv ) {
			return false;
		}
		$wpdb->delete( $wpdb->prefix . 'tianma_messages', array( 'conversation_id' => (int) $id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'tianma_conversations', array( 'id' => (int) $id ), array( '%d' ) );
		return true;
	}

	/** 清空单个会话的消息（保留会话，标题重置） */
	public static function clear_messages( $id, $user_id ) {
		global $wpdb;
		$conv = self::get( $id, $user_id );
		if ( ! $conv ) {
			return false;
		}
		$wpdb->delete( $wpdb->prefix . 'tianma_messages', array( 'conversation_id' => (int) $id ), array( '%d' ) );
		$wpdb->update(
			$wpdb->prefix . 'tianma_conversations',
			array( 'title' => '新对话', 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return true;
	}

	/** 清空用户全部会话 */
	public static function clear_all( $user_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"DELETE m FROM {$wpdb->prefix}tianma_messages m
			 INNER JOIN {$wpdb->prefix}tianma_conversations c ON c.id = m.conversation_id
			 WHERE c.user_id = %d",
			(int) $user_id
		) );
		$wpdb->delete( $wpdb->prefix . 'tianma_conversations', array( 'user_id' => (int) $user_id ), array( '%d' ) );
		return true;
	}

	public static function touch( $id ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'tianma_conversations',
			array( 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/** 首条消息自动命名会话标题 */
	public static function autotitle( $id, $first_message ) {
		global $wpdb;
		$title = mb_substr( trim( preg_replace( '/\s+/u', ' ', $first_message ) ), 0, 20 );
		if ( '' === $title ) {
			$title = '新对话';
		}
		$wpdb->update(
			$wpdb->prefix . 'tianma_conversations',
			array( 'title' => $title ),
			array( 'id' => (int) $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/* ---------------------------------------------------------------------
	 * 消息
	 * ------------------------------------------------------------------- */

	public static function add_message( $conversation_id, $role, $content ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'tianma_messages',
			array(
				'conversation_id' => (int) $conversation_id,
				'role'            => in_array( $role, array( 'user', 'assistant' ), true ) ? $role : 'user',
				'content'         => $content,
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
		self::touch( $conversation_id );
		return (int) $wpdb->insert_id;
	}

	public static function get_messages( $conversation_id, $user_id, $limit = 200 ) {
		global $wpdb;
		$conv = self::get( $conversation_id, $user_id );
		if ( ! $conv ) {
			return null;
		}
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, role, content, created_at FROM {$wpdb->prefix}tianma_messages
			 WHERE conversation_id = %d ORDER BY id ASC LIMIT %d",
			(int) $conversation_id,
			$limit
		) );
	}
}
