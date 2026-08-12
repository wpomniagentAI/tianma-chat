<?php
/**
 * 能力角色库：为智能体定义不同的专业角色
 *
 * 每个角色：名称 + 职责描述 + 行为风格（system prompt）+ 工具白名单。
 * 多角色协作时，每个角色在自己的上下文中独立执行子任务。
 *
 * @package Tianma
 */

defined( 'ABSPATH' ) || exit;

class Tianma_Role {

	/** 内置角色模板 */
	public static function builtins() {
		return array(
			array(
				'name'         => '全能管家',
				'description'  => '默认角色。协调全局，处理日常管理与综合任务',
				'system_prompt'=> '',
				'tools'        => array(),
				'is_builtin'   => 1,
			),
			array(
				'name'         => '内容运营',
				'description'  => '擅长文章/页面创作、发布节奏规划、分类标签管理、媒体组织',
				'system_prompt'=> '你是站点的内容运营专家。输出内容应符合站点调性，结构清晰、标题有吸引力；发布前确认用户意图；可主动建议内容规划。',
				'tools'        => array( 'list_posts', 'get_post', 'create_post', 'update_post', 'create_page', 'update_page', 'list_pages', 'list_media', 'upload_media', 'create_category', 'list_categories', 'get_site_info' ),
				'is_builtin'   => 1,
			),
			array(
				'name'         => '代码开发者',
				'description'  => '擅长主题/插件开发、代码编写与修改、语法检查、文件管理',
				'system_prompt'=> '你是资深 WordPress 开发者。遵循 WordPress 编码规范；修改文件前先读取原文；PHP 代码注意安全与兼容性；完成后建议做语法检查。',
				'tools'        => array( 'file_list', 'file_read', 'file_write', 'file_rename', 'file_create_dir', 'validate_php', 'create_plugin_skel', 'create_theme_skel', 'plugin_list', 'theme_list' ),
				'is_builtin'   => 1,
			),
			array(
				'name'         => 'SEO 优化师',
				'description'  => '擅长站点 SEO 检查、内容优化建议、关键词与元信息策略',
				'system_prompt'=> '你是 SEO 专家。分析站点结构与内容时关注标题、描述、链接结构、图片 alt、内容质量；给出可执行的中文优化建议。',
				'tools'        => array( 'get_site_info', 'get_settings', 'list_posts', 'get_post', 'list_pages', 'list_media', 'read_only_db_query' ),
				'is_builtin'   => 1,
			),
			array(
				'name'         => '安全审计员',
				'description'  => '擅长安全检查：用户权限、插件风险、日志异常、数据库健康',
				'system_prompt'=> '你是 WordPress 安全审计专家。审查用户角色分配、启用插件风险、debug 日志异常；发现风险时明确指出并给出修复步骤。',
				'tools'        => array( 'user_list', 'plugin_list', 'theme_list', 'get_error_log', 'get_settings', 'get_site_info', 'read_only_db_query' ),
				'is_builtin'   => 1,
			),
			array(
				'name'         => '数据分析师',
				'description'  => '擅长用数据库查询分析站点数据、统计内容与用户情况',
				'system_prompt'=> '你是站点数据分析师。用只读 SQL 查询分析数据，注意表前缀（当前为 wp_），输出清晰的统计结论。',
				'tools'        => array( 'read_only_db_query', 'get_site_info', 'list_posts', 'list_media', 'user_list' ),
				'is_builtin'   => 1,
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * CRUD
	 * ------------------------------------------------------------------- */

	public static function create( $name, $description, $system_prompt = '', $tools = array(), $is_builtin = 0, $llm = array(), $meta = array() ) {
		global $wpdb;

		$name = mb_substr( sanitize_text_field( $name ), 0, 60 );
		if ( '' === $name ) {
			return new WP_Error( 'tianma_role', '角色名称不能为空' );
		}

		$role_type = isset( $meta['role_type'] ) && 'functional' === $meta['role_type'] ? 'functional' : 'chat';
		$bind_tool = isset( $meta['bind_tool'] ) ? sanitize_key( $meta['bind_tool'] ) : '';

		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}tianma_roles WHERE name = %s", $name ) );
		if ( $exists ) {
			return new WP_Error( 'tianma_role', '角色「' . $name . '」已存在' );
		}

		$wpdb->insert(
			$wpdb->prefix . 'tianma_roles',
			array(
				'name'          => $name,
				'description'   => mb_substr( sanitize_textarea_field( $description ), 0, 500 ),
				'system_prompt' => sanitize_textarea_field( $system_prompt ),
				'tools'         => wp_json_encode( array_values( array_filter( (array) $tools, 'is_string' ) ) ),
				'role_type'     => $role_type,
				'bind_tool'     => $bind_tool,
				'llm_provider'  => isset( $llm['provider'] ) ? sanitize_key( $llm['provider'] ) : '',
				'llm_base_url'  => isset( $llm['base_url'] ) ? esc_url_raw( $llm['base_url'] ) : '',
				'llm_api_key'   => isset( $llm['api_key'] ) ? sanitize_text_field( $llm['api_key'] ) : '',
				'llm_model'     => isset( $llm['model'] ) ? sanitize_text_field( $llm['model'] ) : '',
				'is_builtin'    => $is_builtin ? 1 : 0,
				'status'        => 'active',
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	public static function update( $id, $fields ) {
		global $wpdb;
		$clean = array();
		if ( isset( $fields['name'] ) ) {
			$clean['name'] = mb_substr( sanitize_text_field( $fields['name'] ), 0, 60 );
		}
		if ( isset( $fields['description'] ) ) {
			$clean['description'] = mb_substr( sanitize_textarea_field( $fields['description'] ), 0, 500 );
		}
		if ( isset( $fields['system_prompt'] ) ) {
			$clean['system_prompt'] = sanitize_textarea_field( $fields['system_prompt'] );
		}
		if ( isset( $fields['tools'] ) && is_array( $fields['tools'] ) ) {
			$clean['tools'] = wp_json_encode( array_values( array_filter( $fields['tools'], 'is_string' ) ) );
		}
		if ( isset( $fields['role_type'] ) ) {
			$clean['role_type'] = in_array( $fields['role_type'], array( 'chat', 'functional' ), true ) ? $fields['role_type'] : 'chat';
		}
		if ( isset( $fields['bind_tool'] ) ) {
			$clean['bind_tool'] = sanitize_key( (string) $fields['bind_tool'] );
		}
		if ( isset( $fields['status'] ) ) {
			$clean['status'] = in_array( $fields['status'], array( 'active', 'inactive' ), true ) ? $fields['status'] : 'active';
		}
		// 独立模型配置（llm 为数组或 null=清除）
		if ( array_key_exists( 'llm', $fields ) ) {
			$llm = is_array( $fields['llm'] ) ? $fields['llm'] : array();
			$clean['llm_provider'] = isset( $llm['provider'] ) ? sanitize_key( $llm['provider'] ) : '';
			$clean['llm_base_url'] = isset( $llm['base_url'] ) ? esc_url_raw( $llm['base_url'] ) : '';
			$clean['llm_api_key']  = isset( $llm['api_key'] ) ? sanitize_text_field( $llm['api_key'] ) : '';
			$clean['llm_model']    = isset( $llm['model'] ) ? sanitize_text_field( $llm['model'] ) : '';
		}
		if ( ! $clean ) {
			return false;
		}
		return $wpdb->update( $wpdb->prefix . 'tianma_roles', $clean, array( 'id' => (int) $id ) );
	}

	public static function delete( $id ) {
		global $wpdb;
		return $wpdb->delete( $wpdb->prefix . 'tianma_roles', array( 'id' => (int) $id ), array( '%d' ) );
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tianma_roles WHERE id = %d", $id ) );
		if ( $row ) {
			self::hydrate( $row );
		}
		return $row;
	}

	public static function get_by_name( $name ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tianma_roles WHERE name = %s", $name ) );
		if ( $row ) {
			self::hydrate( $row );
		}
		return $row;
	}

	public static function list_all( $status = 'active' ) {
		global $wpdb;
		$sql = "SELECT * FROM {$wpdb->prefix}tianma_roles";
		if ( $status ) {
			$sql .= $wpdb->prepare( ' WHERE status = %s', $status );
		}
		$sql .= ' ORDER BY is_builtin DESC, id ASC';
		$rows = $wpdb->get_results( $sql );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		foreach ( $rows as $row ) {
			self::hydrate( $row );
		}
		return $rows;
	}

	/** 组装 tools 数组与 llm 配置对象 */
	private static function hydrate( $row ) {
		$row->tools = json_decode( $row->tools, true );
		$row->llm   = (object) array(
			'provider' => $row->llm_provider,
			'base_url' => $row->llm_base_url,
			'api_key'  => $row->llm_api_key,
			'model'    => $row->llm_model,
		);
		$row->has_own_llm = ( '' !== $row->llm_api_key );
		return $row;
	}

	/** 该角色是否配置了独立模型 */
	public static function has_own_llm( $role ) {
		return ! empty( $role->llm_api_key );
	}

	/** 初始化内置角色（安装/升级时调用） */
	public static function seed_builtins() {
		global $wpdb;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tianma_roles" );
		if ( $count > 0 ) {
			return;
		}
		foreach ( self::builtins() as $r ) {
			self::create( $r['name'], $r['description'], $r['system_prompt'], $r['tools'], $r['is_builtin'] );
		}
	}

	/** 注入 Agent 上下文 */
	public static function context_prompt( $limit = 6 ) {
		$roles = self::list_all( 'active' );
		if ( ! $roles ) {
			return '';
		}
		$roles = array_slice( $roles, 0, $limit );
		$out   = "【可用角色】需要专业分工或多角色协作时可引用这些角色：\n";
		foreach ( $roles as $r ) {
			$out .= "- 角色「{$r->name}」：{$r->description}\n";
		}
		$out .= "（发起协作时，使用 start_collaboration 工具并在 plan 中引用角色名）\n";
		return $out;
	}

	/**
	 * 按用户消息匹配最可能相关的角色（按名称+职责描述的关键词命中数排序）。
	 * 返回 array of array{ role: object, hits: int }，最多 $limit 个。
	 * 纯职责匹配，不涉及任何模型名规则。
	 */
	public static function match_for_message( $message, $limit = 2 ) {
		$roles = self::list_all( 'active' );
		if ( ! $roles ) {
			return array();
		}
		$text = mb_strtolower( trim( (string) $message ) );
		if ( '' === $text ) {
			return array();
		}

		// 提取消息中的中文 2~4 字滑动片段（n-gram），跳过常见停用词
		$stop = array( '的', '了', '吗', '呢', '吧', '呀', '哦', '啊', '我', '你', '他', '她', '它', '们', '帮', '请', '把', '要', '想', '会', '能', '可以', '需要', '一下', '一个', '这个', '那个', '什么', '怎么', '如何', '是否', '为', '给', '用', '让', '和', '与', '或', '在', '是', '有', '不', '别', '就', '都', '也', '还', '很', '太', '真', '最' );
		$len  = mb_strlen( $text );
		$grams = array();
		for ( $n = 4; $n >= 2; $n-- ) {
			for ( $i = 0; $i + $n <= $len; $i++ ) {
				$g = mb_substr( $text, $i, $n );
				if ( in_array( $g, $stop, true ) ) {
					continue;
				}
				$grams[ $g ] = true;
			}
		}
		if ( ! $grams ) {
			return array();
		}

		$scored = array();
		foreach ( $roles as $r ) {
			$hay  = mb_strtolower( $r->name . ' ' . $r->description . ' ' . ( $r->system_prompt ? $r->system_prompt : '' ) );
			$hits = 0;
			foreach ( array_keys( $grams ) as $g ) {
				if ( false !== mb_strpos( $hay, $g ) ) {
					$hits++;
				}
			}
			if ( $hits > 0 ) {
				$scored[] = array( 'role' => $r, 'hits' => $hits );
			}
		}
		usort( $scored, function ( $a, $b ) {
			return $b['hits'] <=> $a['hits'];
		} );
		return array_slice( $scored, 0, $limit );
	}
}
