<?php
/**
 * 工作日志（Worklog）
 *
 * 按天一条记录插件全程的关键进展，跨对话持久保存：
 * - Agent 每次任务结束后自动追加一行摘要（auto_log）
 * - AI 可通过 worklog_read / worklog_append / worklog_update / worklog_delete 读取与调整
 * - 用户可在「模型设置 → 工作日志」区块手动编辑
 *
 * @package Tianma
 */

defined( 'ABSPATH' ) || exit;

class Tianma_Worklog {

	/** 读取某天日志内容（默认今天）。无记录返回空字符串 */
	public static function get( $day = null ) {
		global $wpdb;
		$day = self::normalize_day( $day );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}tianma_worklogs WHERE log_date = %s",
			$day
		) );
		return $row ? $row->content : '';
	}

	/** 最近 N 天日志列表（日期倒序），每条含 id/log_date/content/updated_at/预览 */
	public static function list( $limit = 30 ) {
		global $wpdb;
		$limit = max( 1, min( 200, (int) $limit ) );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, log_date, content, updated_at, created_at
			 FROM {$wpdb->prefix}tianma_worklogs
			 ORDER BY log_date DESC LIMIT %d",
			$limit
		) );
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'id'         => (int) $r->id,
				'log_date'   => $r->log_date,
				'content'    => (string) $r->content,
				'preview'    => self::preview( (string) $r->content, 140 ),
				'line_count' => self::line_count( (string) $r->content ),
				'updated_at' => $r->updated_at,
			);
		}
		return $out;
	}

	/** 追加内容到某天日志（默认今天）。内容为空则忽略；自动在行首加时间戳 */
	public static function append( $content, $day = null, $stamp = true ) {
		$content = trim( (string) $content );
		if ( '' === $content ) {
			return array( 'ok' => false, 'message' => '日志内容不能为空' );
		}
		$day    = self::normalize_day( $day );
		$stamp  = $stamp ? '[' . current_time( 'H:i' ) . '] ' : '';
		$add    = $stamp . self::indent_lines( $content );
		$exist  = self::get( $day );

		if ( '' !== $exist ) {
			$new = rtrim( $exist, "\n" ) . "\n" . $add . "\n";
			self::save_raw( $day, $new, false );
		} else {
			self::save_raw( $day, $add . "\n", true );
		}
		return array( 'ok' => true, 'message' => '已记录到 ' . $day . ' 的工作日志' );
	}

	/** 整体重写某天日志（upsert）。空内容表示清空该天 */
	public static function update( $day, $content ) {
		$day     = self::normalize_day( $day );
		$content = trim( (string) $content );
		$ok      = self::save_raw( $day, $content, ( '' === self::get( $day ) ) );
		return array( 'ok' => true, 'message' => $ok ? '已保存 ' . $day . ' 的工作日志' : '已更新 ' . $day . ' 的工作日志' );
	}

	/** 删除某天日志 */
	public static function delete( $day ) {
		global $wpdb;
		$day = self::normalize_day( $day );
		$res = $wpdb->delete( $wpdb->prefix . 'tianma_worklogs', array( 'log_date' => $day ), array( '%s' ) );
		return array( 'ok' => true, 'message' => ( $res ? '已删除 ' . $day . ' 的工作日志' : $day . ' 没有工作日志' ) );
	}

	/** Agent 任务结束自动沉淀一行摘要（仅执行过工具的任务才记，纯聊天不记；演示模式不记） */
	public static function auto_log( $summary, $status, $steps ) {
		$settings = Tianma_Settings::get();
		if ( ! empty( $settings['mock_mode'] ) ) {
			return null;
		}
		if ( ! in_array( $status, array( 'done', 'error' ), true ) ) {
			return null;
		}
		$steps = is_array( $steps ) ? $steps : array();
		if ( count( $steps ) < 1 ) {
			return null; // 纯聊天：不沉淀
		}
		$summary = trim( (string) $summary );
		if ( '' === $summary || '任务' === $summary ) {
			return null;
		}
		$tools = array();
		foreach ( $steps as $s ) {
			if ( is_array( $s ) && isset( $s['tool'] ) && ! in_array( $s['tool'], $tools, true ) ) {
				$tools[] = $s['tool'];
			}
		}
		$icon = 'done' === $status ? '✅' : '❌';
		$line = $icon . ' ' . mb_substr( $summary, 0, 120 );
		if ( count( $steps ) ) {
			$line .= '（' . count( $steps ) . ' 步 / ' . count( $tools ) . ' 工具：' . implode( ', ', array_slice( $tools, 0, 8 ) ) . ( count( $tools ) > 8 ? ' 等' : '' ) . '）';
		}
		return self::append( $line, null, true );
	}

	/** 拼接最近 N 天日志文本（供 AI 查看，按日期倒序） */
	public static function latest_text( $days = 7 ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT log_date, content FROM {$wpdb->prefix}tianma_worklogs
			 WHERE log_date >= %s ORDER BY log_date DESC",
			gmdate( 'Y-m-d', strtotime( '-' . (int) $days . ' days', current_time( 'timestamp' ) ) )
		) );
		if ( ! $rows ) {
			return '（暂无工作日志）';
		}
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = "【{$r->log_date}】\n" . trim( (string) $r->content );
		}
		return implode( "\n\n", $out );
	}

	/* ---------------------------------------------------------------------
	 * 内部
	 * ------------------------------------------------------------------- */

	private static function save_raw( $day, $content, $is_new ) {
		global $wpdb;
		if ( $is_new ) {
			return (bool) $wpdb->insert(
				$wpdb->prefix . 'tianma_worklogs',
				array(
					'log_date'   => $day,
					'content'    => $content,
					'created_at' => current_time( 'mysql' ),
					'updated_at' => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s' )
			);
		}
		return (bool) $wpdb->update(
			$wpdb->prefix . 'tianma_worklogs',
			array(
				'content'    => $content,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'log_date' => $day ),
			array( '%s', '%s' ),
			array( '%s' )
		);
	}

	/** 规范化日期：null/空 → 今天；非法 → 今天 */
	public static function normalize_day( $day ) {
		$day = trim( (string) $day );
		if ( '' === $day ) {
			return current_time( 'Y-m-d' );
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
			$ts = strtotime( $day );
			if ( $ts ) {
				return date( 'Y-m-d', $ts );
			}
		}
		return current_time( 'Y-m-d' );
	}

	/** 多行内容缩进（追加时与时间戳对齐） */
	private static function indent_lines( $content ) {
		$lines = preg_split( '/\r\n|\r|\n/', $content );
		$out   = array();
		foreach ( $lines as $i => $line ) {
			if ( 0 === $i ) {
				$out[] = trim( $line );
			} else {
				$out[] = '  ' . trim( $line );
			}
		}
		return implode( "\n", $out );
	}

	private static function preview( $content, $len ) {
		$content = trim( (string) $content );
		if ( '' === $content ) {
			return '';
		}
		$first = preg_split( '/\r\n|\r|\n/', $content );
		$txt   = trim( (string) $first[0] );
		return mb_strlen( $txt ) > $len ? mb_substr( $txt, 0, $len ) . '…' : $txt;
	}

	private static function line_count( $content ) {
		return count( preg_split( '/\r\n|\r|\n/', trim( (string) $content ) ) );
	}
}
