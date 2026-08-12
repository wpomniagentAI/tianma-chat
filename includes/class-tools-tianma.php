<?php
/**
 * 插件自管理工具：让 AI 直接管理天马插件自身的能力
 *
 * 记忆库 / 审计日志 / Token 用量 / 模型配置 / 技能删除。
 * 这些能力之前只能读源码或去后台手动操作，现在全部可被 AI 按需调用。
 *
 * @package Tianma
 */

defined( 'ABSPATH' ) || exit;

class Tianma_Tools_Tianma {

	/* ---------------- 工作日志 ---------------- */

	public static function worklog_read( $args ) {
		$day  = isset( $args['day'] ) ? (string) $args['day'] : '';
		if ( '' !== trim( $day ) ) {
			$text = Tianma_Worklog::get( $day );
			return array(
				'ok'      => true,
				'message' => '【' . Tianma_Worklog::normalize_day( $day ) . '】工作日志：',
				'data'    => array( 'day' => Tianma_Worklog::normalize_day( $day ), 'content' => $text, 'empty' => ( '' === $text ) ),
			);
		}
		$days = isset( $args['days'] ) ? (int) $args['days'] : 7;
		$days = max( 1, min( 30, $days ) );
		$text = Tianma_Worklog::latest_text( $days );
		return array(
			'ok'      => true,
			'message' => '最近 ' . $days . ' 天工作日志：',
			'data'    => array( 'days' => $days, 'content' => $text ),
		);
	}

	public static function worklog_append( $args ) {
		$content = isset( $args['content'] ) ? (string) $args['content'] : '';
		$day     = isset( $args['day'] ) ? (string) $args['day'] : '';
		return Tianma_Worklog::append( $content, $day );
	}

	public static function worklog_update( $args ) {
		$day     = isset( $args['day'] ) ? (string) $args['day'] : '';
		$content = isset( $args['content'] ) ? (string) $args['content'] : '';
		return Tianma_Worklog::update( $day, $content );
	}

	public static function worklog_delete( $args ) {
		$day = isset( $args['day'] ) ? (string) $args['day'] : '';
		return Tianma_Worklog::delete( $day );
	}

	/* ---------------- 记忆库 ---------------- */

	public static function memory_list( $args ) {
		$memory = new Tianma_Memory();
		$rows   = $memory->list_all( 50 );
		if ( ! $rows ) {
			return array( 'ok' => true, 'message' => '记忆库为空', 'data' => array() );
		}
		$type_map = array(
			'episodic'   => '过往任务',
			'semantic'   => '知识经验',
			'procedural' => '最佳技能',
		);
		$items = array();
		foreach ( $rows as $m ) {
			$items[] = array(
				'id'        => (int) $m->id,
				'类型'      => isset( $type_map[ $m->m_type ] ) ? $type_map[ $m->m_type ] : $m->m_type,
				'标题'      => $m->title,
				'内容预览'  => mb_substr( $m->preview, 0, 120 ),
				'权重'      => (float) $m->weight,
				'命中次数'  => (int) $m->hit_count,
				'创建时间'  => $m->created_at,
			);
		}
		return array( 'ok' => true, 'message' => '记忆库共 ' . count( $items ) . ' 条记忆（最多展示 50 条）', 'data' => $items );
	}

	public static function memory_clear( $args ) {
		global $wpdb;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tianma_memories" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}tianma_memories" );
		return array( 'ok' => true, 'message' => '记忆库已清空（共删除 ' . $count . ' 条记忆）。任务经验将从零重新积累。' );
	}

	/* ---------------- 审计日志 ---------------- */

	public static function audit_log( $args ) {
		$limit = min( 100, max( 1, (int) ( isset( $args['limit'] ) ? $args['limit'] : 30 ) ) );
		$rows  = Tianma_Audit::recent( $limit );
		if ( ! $rows ) {
			return array( 'ok' => true, 'message' => '暂无审计记录', 'data' => array() );
		}
		$items = array();
		foreach ( $rows as $r ) {
			$detail = json_decode( $r->detail, true );
			$items[] = array(
				'id'         => (int) $r->id,
				'操作'       => $r->action,
				'用户'       => (int) $r->user_id,
				'是否成功'   => ! empty( $detail['ok'] ) ? '是' : '否',
				'参数'       => isset( $detail['args'] ) ? wp_json_encode( $detail['args'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '',
				'结果'       => isset( $detail['message'] ) ? mb_substr( $detail['message'], 0, 120 ) : '',
				'时间'       => $r->created_at,
			);
		}
		return array( 'ok' => true, 'message' => '最近 ' . count( $items ) . ' 条审计记录（含所有工具操作）', 'data' => $items );
	}

	/* ---------------- Token 用量 ---------------- */

	public static function usage_stats( $args ) {
		$stats = Tianma_Usage::stats( 0 );
		$conv  = isset( $args['conversation_id'] ) ? (int) $args['conversation_id'] : 0;
		if ( ! is_array( $stats ) ) {
			$stats = array();
		}
		$out = array(
			'累计token'    => isset( $stats['total']->total_tokens ) ? (int) $stats['total']->total_tokens : 0,
			'今日token'    => isset( $stats['today']->total_tokens ) ? (int) $stats['today']->total_tokens : 0,
			'累计调用次数'  => isset( $stats['total']->calls ) ? (int) $stats['total']->calls : 0,
		);
		if ( $conv ) {
			$c = Tianma_Usage::stats( 0, $conv );
			$out['该会话token'] = isset( $c['conv']->total_tokens ) ? (int) $c['conv']->total_tokens : 0;
		}
		return array( 'ok' => true, 'message' => 'Token 用量统计：累计 ' . $out['累计token'] . ' tok，今日 ' . $out['今日token'] . ' tok，调用 ' . $out['累计调用次数'] . ' 次', 'data' => $out );
	}

	/* ---------------- 模型配置 ---------------- */

	public static function llm_info( $args ) {
		$s = Tianma_Settings::get();
		$presets = Tianma_Settings::presets();
		$label   = isset( $presets[ $s['provider'] ]['label'] ) ? $presets[ $s['provider'] ]['label'] : $s['provider'];
		return array(
			'ok' => true,
			'message' => '当前模型：' . $label . ' · ' . $s['model'] . '（' . ( '' !== $s['api_key'] ? '已配 Key' : '未配 Key' ) . '）',
			'data' => array(
				'服务商'        => $label,
				'provider'      => $s['provider'],
				'模型'          => $s['model'],
				'api地址'       => $s['base_url'],
				'已配Key'       => '' !== $s['api_key'],
				'嵌入模型'      => $s['embedding_model'],
				'确认策略'      => array( 'auto' => '全部自动', 'high' => '高危需确认', 'all' => '全部确认' )[ $s['confirm_mode'] ],
				'记忆系统'      => $s['memory_enabled'] ? '开启' : '关闭',
				'最大步数'      => (int) $s['max_steps'],
			),
		);
	}

	public static function llm_switch( $args ) {
		$s = Tianma_Settings::get();
		$fields = array();
		if ( isset( $args['provider'] ) ) {
			$fields['provider'] = sanitize_key( $args['provider'] );
		}
		if ( isset( $args['base_url'] ) ) {
			$fields['base_url'] = esc_url_raw( $args['base_url'] );
		}
		if ( isset( $args['model'] ) ) {
			$fields['model'] = sanitize_text_field( $args['model'] );
		}
		if ( isset( $args['api_key'] ) && '' !== trim( (string) $args['api_key'] ) ) {
			$fields['api_key'] = sanitize_text_field( $args['api_key'] );
		}
		if ( ! $fields ) {
			return array( 'ok' => false, 'message' => '没有需要更新的模型配置字段（provider/base_url/model/api_key）' );
		}
		// 切换服务商由 Tianma_Settings::update 统一处理：
		// 自动归档当前服务商配置、加载目标服务商已保存的配置（无则预设填充），用户保存的配置永不被覆盖
		Tianma_Settings::update( $fields );
		$s2 = Tianma_Settings::get();
		return array(
			'ok' => true,
			'message' => '模型已切换：' . $s2['model'] . '（Key 保留，后续对话使用新模型）',
			'data' => array( 'provider' => $s2['provider'], 'model' => $s2['model'], 'has_key' => '' !== $s2['api_key'] ),
		);
	}

	/* ---------------- 技能删除 ---------------- */

	public static function delete_skill( $args ) {
		$id = (int) ( isset( $args['skill_id'] ) ? $args['skill_id'] : 0 );
		$s  = Tianma_Skill::get( $id );
		if ( ! $s ) {
			return array( 'ok' => false, 'message' => '技能不存在' );
		}
		Tianma_Skill::delete( $id );
		return array( 'ok' => true, 'message' => '技能「' . $s->name . '」已从能力库删除' );
	}

	/* ---------------- 能力自我进化（AI 自建工具） ---------------- */

	/**
	 * AI 自我进化核心：当 AI 发现自己缺少某能力时，
	 * 可用本工具编写 PHP 实现代码，动态注册一个新工具，立即可用。
	 * php_code 是一个函数体（接收 $args 数组，返回 array(ok,message,data)）。
	 */
	public static function create_tool( $args ) {
		global $wpdb;

		$name  = isset( $args['name'] ) ? sanitize_key( $args['name'] ) : '';
		$desc  = trim( (string) ( isset( $args['description'] ) ? $args['description'] : '' ) );
		$code  = trim( (string) ( isset( $args['php_code'] ) ? $args['php_code'] : '' ) );
		$params = isset( $args['params'] ) && is_array( $args['params'] ) ? $args['params'] : array();
		$required = isset( $args['required'] ) && is_array( $args['required'] ) ? array_values( array_filter( $args['required'], 'is_string' ) ) : array();
		$risk  = isset( $args['risk'] ) ? $args['risk'] : 'low';
		$risk  = in_array( $risk, array( 'low', 'high' ), true ) ? $risk : 'low';

		if ( ! preg_match( '/^[a-z][a-z0-9_]{2,49}$/', $name ) ) {
			return array( 'ok' => false, 'message' => '工具名不合法：需小写字母开头、3~50 位（字母/数字/下划线），如 weather_query' );
		}
		if ( '' === $desc ) {
			return array( 'ok' => false, 'message' => '请提供工具描述 description（模型看到后决定何时调用）' );
		}
		if ( '' === $code ) {
			return array( 'ok' => false, 'message' => '请提供 php_code 实现（一个 PHP 函数体，接收 $args 返回 array(ok,message,data)）' );
		}
		if ( Tianma_Tools::names() && in_array( $name, Tianma_Tools::names(), true ) ) {
			return array( 'ok' => false, 'message' => '工具名「' . $name . '」已存在，请换一个名字' );
		}

		// 语法与试运行校验
		try {
			$fn = eval( 'return function($args){' . $code . '};' );
			if ( ! $fn instanceof Closure ) {
				return array( 'ok' => false, 'message' => 'php_code 未能编译为函数，请检查语法' );
			}
			$trial = $fn( array() );
			if ( ! is_array( $trial ) || ! isset( $trial['ok'] ) ) {
				return array( 'ok' => false, 'message' => 'php_code 实现必须返回 array(ok, message, data?)' );
			}
		} catch ( Throwable $e ) {
			return array( 'ok' => false, 'message' => 'php_code 校验失败：' . $e->getMessage() . '（请修正后重试）' );
		}

		$params['_required'] = $required;
		$wpdb->insert(
			$wpdb->prefix . 'tianma_custom_tools',
			array(
				'name'        => $name,
				'description' => mb_substr( $desc, 0, 500 ),
				'params'      => wp_json_encode( $params, JSON_UNESCAPED_UNICODE ),
				'php_code'    => $code,
				'risk'        => $risk,
				'status'      => 'active',
				'created_by'  => isset( $args['_user_id'] ) ? (int) $args['_user_id'] : 0,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		Tianma_Tools::reset(); // 强制下次加载新工具
		return array(
			'ok' => true,
			'message' => '✅ 新能力「' . $name . '」已创建并立即可用！' . $desc . '（风险等级：' . $risk . '）。下一轮对话起，你就能直接调用它。',
			'data' => array( 'name' => $name, 'risk' => $risk ),
		);
	}

	public static function list_tools( $args ) {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT name, description, risk, status, created_at FROM {$wpdb->prefix}tianma_custom_tools ORDER BY id ASC" );
		if ( ! $rows ) {
			return array( 'ok' => true, 'message' => '当前没有 AI 自建的自定义工具', 'data' => array() );
		}
		$items = array();
		foreach ( $rows as $r ) {
			$items[] = array(
				'名称'    => $r->name,
				'描述'    => mb_substr( $r->description, 0, 80 ),
				'风险'    => $r->risk,
				'状态'    => 'active' === $r->status ? '启用' : '停用',
				'创建时间' => $r->created_at,
			);
		}
		return array( 'ok' => true, 'message' => '共 ' . count( $items ) . ' 个 AI 自建工具', 'data' => $items );
	}

	public static function delete_tool( $args ) {
		global $wpdb;
		$name = isset( $args['name'] ) ? sanitize_key( $args['name'] ) : '';
		if ( '' === $name ) {
			return array( 'ok' => false, 'message' => '请提供要删除的工具 name' );
		}
		$del = $wpdb->delete( $wpdb->prefix . 'tianma_custom_tools', array( 'name' => $name ), array( '%s' ) );
		if ( ! $del ) {
			return array( 'ok' => false, 'message' => '自定义工具「' . $name . '」不存在' );
		}
		Tianma_Tools::reset();
		return array( 'ok' => true, 'message' => '自定义工具「' . $name . '」已删除' );
	}
}
