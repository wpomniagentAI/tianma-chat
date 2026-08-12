<?php
/**
 * 定时任务：让 Agent 可以自主创建周期性的自动化任务
 *
 * 核心机制：
 * - 任务表 tianma_schedules 存储任务（指令、周期、授权策略）
 * - 基于 WordPress wp-cron（wp_schedule_single_event）调度，到点自动唤醒
 * - 唤醒后以「无头模式」执行 Agent：无需用户在线、跳过交互确认，
 *   高危操作按任务创建时的授权策略处理（授权则执行 / 未授权则跳过）
 * - 执行结果（状态/总结/步骤/用量）记录到任务，可在管理页查看
 *
 * 免访问说明：wp-cron 需要站点被访问才触发（伪 cron）。
 * 真正免访问需在服务器配置系统计划任务定期调用 wp-cron.php，
 * 详见 README「定时任务」章节与本地 tick-cron.php 辅助脚本。
 *
 * @package Tianma
 */

defined( 'ABSPATH' ) || exit;

class Tianma_Schedule {

	const HOOK = 'tianma_schedule_run';

	/** 周期类型 → 中文名 */
	public static function interval_labels() {
		return array(
			'hourly'   => '每小时',
			'twicedaily' => '每 12 小时',
			'daily'    => '每天',
			'weekly'   => '每周',
			'monthly'  => '每月（1 日）',
			'minutes'  => '每隔 N 分钟',
		);
	}

	/* ---------------------------------------------------------------------
	 * CRUD
	 * ------------------------------------------------------------------- */

	public static function create( $data, $user_id = 0 ) {
		global $wpdb;

		$name  = mb_substr( sanitize_text_field( (string) ( isset( $data['name'] ) ? $data['name'] : '' ) ), 0, 120 );
		$prompt = trim( (string) ( isset( $data['prompt'] ) ? $data['prompt'] : '' ) );
		if ( '' === $name || '' === $prompt ) {
			return new WP_Error( 'tianma_schedule', '任务名称与执行指令必填' );
		}

		$interval = self::clean_interval( isset( $data['interval_type'] ) ? $data['interval_type'] : 'daily' );
		$at_time  = self::clean_time( isset( $data['at_time'] ) ? $data['at_time'] : '09:00' );
		$dow      = max( 0, min( 6, (int) ( isset( $data['day_of_week'] ) ? $data['day_of_week'] : 1 ) ) );
		$minutes  = max( 1, min( 10080, (int) ( isset( $data['interval_minutes'] ) ? $data['interval_minutes'] : 60 ) ) );
		$auto_high = empty( $data['auto_high'] ) ? 0 : 1;
		$status    = ( isset( $data['status'] ) && 'paused' === $data['status'] ) ? 'paused' : 'active';

		$next = self::next_run_time( $interval, $at_time, $dow, $minutes );

		$wpdb->insert(
			$wpdb->prefix . 'tianma_schedules',
			array(
				'name'             => $name,
				'prompt'           => mb_substr( $prompt, 0, 4000 ),
				'interval_type'    => $interval,
				'interval_minutes' => $minutes,
				'at_time'          => $at_time,
				'day_of_week'      => $dow,
				'auto_high'        => $auto_high,
				'status'           => $status,
				'next_run'         => gmdate( 'Y-m-d H:i:s', $next + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ),
				'created_by'       => (int) $user_id,
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		$id = (int) $wpdb->insert_id;
		if ( $id && 'active' === $status ) {
			self::schedule_event( $id );
		}
		return $id;
	}

	public static function update( $id, $fields ) {
		global $wpdb;
		$clean = array();

		if ( isset( $fields['name'] ) ) {
			$clean['name'] = mb_substr( sanitize_text_field( $fields['name'] ), 0, 120 );
		}
		if ( isset( $fields['prompt'] ) ) {
			$clean['prompt'] = mb_substr( trim( (string) $fields['prompt'] ), 0, 4000 );
		}
		if ( isset( $fields['interval_type'] ) ) {
			$clean['interval_type'] = self::clean_interval( $fields['interval_type'] );
		}
		if ( isset( $fields['at_time'] ) ) {
			$clean['at_time'] = self::clean_time( $fields['at_time'] );
		}
		if ( isset( $fields['day_of_week'] ) ) {
			$clean['day_of_week'] = max( 0, min( 6, (int) $fields['day_of_week'] ) );
		}
		if ( isset( $fields['interval_minutes'] ) ) {
			$clean['interval_minutes'] = max( 1, min( 10080, (int) $fields['interval_minutes'] ) );
		}
		if ( isset( $fields['auto_high'] ) ) {
			$clean['auto_high'] = empty( $fields['auto_high'] ) ? 0 : 1;
		}
		if ( isset( $fields['status'] ) ) {
			$clean['status'] = in_array( $fields['status'], array( 'active', 'paused' ), true ) ? $fields['status'] : 'active';
		}

		if ( ! $clean ) {
			return false;
		}
		$clean['updated_at'] = current_time( 'mysql' );

		$ok = $wpdb->update( $wpdb->prefix . 'tianma_schedules', $clean, array( 'id' => (int) $id ) );

		// 重新调度：先清旧事件，再按新配置注册
		wp_clear_scheduled_hook( self::HOOK, array( (int) $id ) );
		$task = self::get( $id );
		if ( $task && 'active' === $task->status ) {
			$next = self::next_run_time( $task->interval_type, $task->at_time, $task->day_of_week, $task->interval_minutes );
			self::update_next_run( $id, $next );
			self::schedule_event( $id );
		}

		return $ok;
	}

	public static function delete( $id ) {
		global $wpdb;
		wp_clear_scheduled_hook( self::HOOK, array( (int) $id ) );
		return $wpdb->delete( $wpdb->prefix . 'tianma_schedules', array( 'id' => (int) $id ), array( '%d' ) );
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tianma_schedules WHERE id = %d", (int) $id ) );
	}

	public static function list_all( $status = '' ) {
		global $wpdb;
		$sql = "SELECT * FROM {$wpdb->prefix}tianma_schedules";
		if ( $status ) {
			$sql .= $wpdb->prepare( ' WHERE status = %s', $status );
		}
		$sql .= ' ORDER BY id DESC';
		$rows = $wpdb->get_results( $sql );
		return is_array( $rows ) ? $rows : array();
	}

	/* ---------------------------------------------------------------------
	 * 调度计算
	 * ------------------------------------------------------------------- */

	public static function clean_interval( $type ) {
		$labels = self::interval_labels();
		return isset( $labels[ $type ] ) ? $type : 'daily';
	}

	public static function clean_time( $time ) {
		$time = (string) $time;
		if ( preg_match( '#^([01]?\d|2[0-3]):([0-5]\d)$#', $time ) ) {
			list( $h, $m ) = explode( ':', $time );
			return sprintf( '%02d:%02d', (int) $h, (int) $m );
		}
		return '09:00';
	}

	/** 计算下一次运行时间戳（返回真实 UTC 时间戳，存储时再转本地时间显示） */
	public static function next_run_time( $interval, $at_time = '09:00', $dow = 1, $minutes = 60 ) {
		$now = time(); // 真实 UTC 时间戳
		$at  = self::clean_time( $at_time );
		$hm  = explode( ':', $at );

		switch ( $interval ) {
			case 'hourly':
				return $now + HOUR_IN_SECONDS;
			case 'twicedaily':
				return $now + 12 * HOUR_IN_SECONDS;
			case 'minutes':
				return $now + max( 1, (int) $minutes ) * MINUTE_IN_SECONDS;
			case 'weekly':
				$ts = self::next_weekday_time( (int) $dow, (int) $hm[0], (int) $hm[1], $now );
				return $ts;
			case 'monthly':
				// 每月 1 日 at_time
				$ts = mktime( (int) $hm[0], (int) $hm[1], 0, (int) gmdate( 'n', $now + get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) + 1, 1, (int) gmdate( 'Y', $now + get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
				return $ts - get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
			case 'daily':
			default:
				$today = mktime( (int) $hm[0], (int) $hm[1], 0, (int) gmdate( 'n', $now + get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ), (int) gmdate( 'j', $now + get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ), (int) gmdate( 'Y', $now + get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
				$today = $today - get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
				if ( $today <= $now ) {
					$today += DAY_IN_SECONDS;
				}
				return $today;
		}
	}

	/** 下一个星期几（0=周日..6=周六）的 HH:MM */
	private static function next_weekday_time( $target_dow, $hour, $minute, $now ) {
		$tz_offset = get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
		$local_now = $now + $tz_offset;
		$today_dow = (int) gmdate( 'w', $local_now );

		$diff = $target_dow - $today_dow;
		if ( $diff < 0 ) {
			$diff += 7;
		}
		$candidate = mktime( $hour, $minute, 0, (int) gmdate( 'n', $local_now ), (int) gmdate( 'j', $local_now ) + $diff, (int) gmdate( 'Y', $local_now ) );
		if ( $candidate <= $local_now ) {
			$candidate += 7 * DAY_IN_SECONDS;
		}
		return $candidate - $tz_offset;
	}

	private static function update_next_run( $id, $ts ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'tianma_schedules',
			array( 'next_run' => gmdate( 'Y-m-d H:i:s', $ts + get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ),
			array( 'id' => (int) $id )
		);
	}

	/** 注册下一次 wp-cron 事件 */
	public static function schedule_event( $id ) {
		$task = self::get( $id );
		if ( ! $task || 'active' !== $task->status ) {
			return;
		}
		$next = self::next_run_time( $task->interval_type, $task->at_time, $task->day_of_week, $task->interval_minutes );
		wp_clear_scheduled_hook( self::HOOK, array( (int) $id ) );
		wp_schedule_single_event( $next, self::HOOK, array( (int) $id ) );
	}

	/** 全量同步：确保所有 active 任务都有 cron 事件（启动时/任务变更后调用） */
	public static function sync_cron() {
		foreach ( self::list_all( 'active' ) as $task ) {
			if ( ! wp_next_scheduled( self::HOOK, array( (int) $task->id ) ) ) {
				self::schedule_event( (int) $task->id );
			}
		}
		// 清理已暂停/已删除任务的残留事件（遍历 cron 数组）
		$cron = _get_cron_array();
		if ( is_array( $cron ) ) {
			foreach ( $cron as $events ) {
				if ( ! is_array( $events ) || empty( $events[ self::HOOK ] ) || ! is_array( $events[ self::HOOK ] ) ) {
					continue;
				}
				foreach ( $events[ self::HOOK ] as $data ) {
					$tid  = isset( $data['args'][0] ) ? (int) $data['args'][0] : 0;
					$task = $tid ? self::get( $tid ) : null;
					if ( ! $task || 'active' !== $task->status ) {
						wp_clear_scheduled_hook( self::HOOK, array( $tid ) );
					}
				}
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * 执行
	 * ------------------------------------------------------------------- */

	/** wp-cron 回调：免访问自动执行 */
	public static function cron_hook( $id ) {
		self::run( (int) $id, 'cron' );
	}

	/** 执行定时任务（cron 触发或手动立即执行） */
	public static function run( $id, $trigger = 'manual' ) {
		global $wpdb;

		$task = self::get( $id );
		if ( ! $task ) {
			return array( 'ok' => false, 'message' => '任务不存在' );
		}
		if ( 'active' !== $task->status && 'manual' !== $trigger ) {
			return array( 'ok' => false, 'message' => '任务已暂停' );
		}

		@set_time_limit( 300 );
		ini_set( 'max_execution_time', '300' );

		// 标记开始
		$wpdb->update(
			$wpdb->prefix . 'tianma_schedules',
			array( 'last_run' => current_time( 'mysql' ) ),
			array( 'id' => (int) $id )
		);

		// 无头模式执行 Agent：不交互确认；高危按任务授权策略
		$agent = new Tianma_Agent();
		$agent->headless  = true;
		$agent->auto_high = (bool) $task->auto_high;

		$res = $agent->run( $task->prompt, array(), (int) $task->created_by );

		$status = isset( $res['status'] ) ? $res['status'] : 'error';
		$text   = isset( $res['text'] ) ? $res['text'] : '';
		$steps  = isset( $res['steps'] ) ? $res['steps'] : array();
		$usage  = isset( $res['usage'] ) ? $res['usage'] : array();

		if ( 'done' === $status && '' === $text && $steps ) {
			$text = '任务已执行，共 ' . count( $steps ) . ' 步工具操作。';
		}
		if ( 'error' === $status ) {
			$text = isset( $res['error'] ) ? $res['error'] : '执行失败';
		}
		if ( 'needs_confirmation' === $status ) {
			// 理论上无头模式不会出现；兜底处理
			$text = '任务触发了需要人工确认的操作，本次执行已终止，请手动处理。';
			$status = 'skipped';
		}

		$record = array(
			'ok'      => 'done' === $status || 'skipped' === $status,
			'status'  => $status,
			'trigger' => $trigger,
			'text'    => mb_substr( $text, 0, 2000 ),
			'steps'   => array_slice( $steps, 0, 30 ),
			'usage'   => $usage,
			'time'    => current_time( 'mysql' ),
		);

		// 更新任务记录
		$next = self::next_run_time( $task->interval_type, $task->at_time, $task->day_of_week, $task->interval_minutes );
		$wpdb->update(
			$wpdb->prefix . 'tianma_schedules',
			array(
				'last_result' => wp_json_encode( $record, JSON_UNESCAPED_UNICODE ),
				'run_count'   => (int) $task->run_count + 1,
				'next_run'    => gmdate( 'Y-m-d H:i:s', $next + get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ),
			),
			array( 'id' => (int) $id )
		);

		// 重新调度下一次
		if ( 'active' === $task->status ) {
			self::schedule_event( (int) $id );
		}

		return $record;
	}

	/** 读取最后一条执行结果（供 API/前端展示） */
	public static function last_result( $id ) {
		$task = self::get( $id );
		if ( ! $task || ! $task->last_result ) {
			return null;
		}
		$r = json_decode( $task->last_result, true );
		return is_array( $r ) ? $r : null;
	}

	/** 周期中文描述 */
	public static function describe_interval( $task ) {
		$labels = self::interval_labels();
		$base   = isset( $labels[ $task->interval_type ] ) ? $labels[ $task->interval_type ] : $task->interval_type;
		switch ( $task->interval_type ) {
			case 'minutes':
				return '每 ' . (int) $task->interval_minutes . ' 分钟';
			case 'daily':
				return '每天 ' . $task->at_time;
			case 'weekly':
				$days = array( '周日', '周一', '周二', '周三', '周四', '周五', '周六' );
				return '每周' . $days[ (int) $task->day_of_week ] . ' ' . $task->at_time;
			case 'monthly':
				return '每月 1 日 ' . $task->at_time;
			default:
				return $base;
		}
	}
}
