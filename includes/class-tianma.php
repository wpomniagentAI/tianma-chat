<?php
/**
 * 天马行空 WP 智能体 - 核心类
 *
 * @package Tianma
 */

defined( 'ABSPATH' ) || exit;

class Tianma_Core {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		add_action( 'admin_init', array( $this, 'ad_guard' ), 5 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// 定时任务：wp-cron 回调 + 启动时同步调度
		add_action( 'tianma_schedule_run', array( 'Tianma_Schedule', 'cron_hook' ), 10, 1 );
		add_action( 'init', array( 'Tianma_Schedule', 'sync_cron' ), 20 );
	}

	/**
	 * 广告组件完整性守护（开源插件盈利点，绑定核心逻辑，防删除/防篡改）：
	 * 广告组件（Tianma_Ad）缺失或被篡改 → 自动停用整个插件，必须恢复原版文件才能再用（不可逆）。
	 */
	public function ad_guard() {
		if ( class_exists( 'Tianma_Ad' ) && Tianma_Ad::verify() ) {
			return;
		}
		// 组件缺失或校验失败：停用插件 + 提示
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin = plugin_basename( TIANMA_PATH . 'tianma.php' );
		if ( is_plugin_active( $plugin ) ) {
			deactivate_plugins( $plugin );
		}
		add_action( 'admin_notices', array( $this, 'ad_guard_notice' ) );
	}

	/** 广告组件校验失败提示 */
	public function ad_guard_notice() {
		echo '<div class="notice notice-error"><p><strong>天马行空 WP 智能体</strong>：插件核心组件校验失败（内置广告组件缺失或被篡改），插件已自动停用。请恢复原版插件文件后重新激活。</p></div>';
	}

	/* ---------------------------------------------------------------------
	 * 安装 / 卸载
	 * ------------------------------------------------------------------- */

	public static function activate() {
		self::create_tables();
		update_option( 'tianma_db_version', TIANMA_DB_VERSION );
		flush_rewrite_rules();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'tianma_daily_distill' );
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix;

		$tables = array();

		$tables[] = "CREATE TABLE {$prefix}tianma_tasks (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			summary TEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'done',
			step_count INT NOT NULL DEFAULT 0,
			tool_count INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id)
		) $charset;";

		$tables[] = "CREATE TABLE {$prefix}tianma_memories (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			m_type VARCHAR(20) NOT NULL DEFAULT 'semantic',
			title VARCHAR(255) NOT NULL,
			content LONGTEXT NOT NULL,
			embedding LONGTEXT NULL,
			meta LONGTEXT NULL,
			weight DECIMAL(5,2) NOT NULL DEFAULT 1.00,
			hit_count INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY m_type (m_type)
		) $charset;";

		$tables[] = "CREATE TABLE {$prefix}tianma_feedback (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			task_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			rating INT NOT NULL DEFAULT 0,
			note TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id)
		) $charset;";

		$tables[] = "CREATE TABLE {$prefix}tianma_confirmations (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			confirm_hash VARCHAR(64) NOT NULL,
			payload LONGTEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY confirm_hash (confirm_hash)
		) $charset;";

		$tables[] = "CREATE TABLE {$prefix}tianma_audit (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			action VARCHAR(100) NOT NULL,
			detail LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id)
		) $charset;";

		$tables[] = "CREATE TABLE {$prefix}tianma_skills (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(120) NOT NULL,
			description TEXT NULL,
			tools TEXT NULL,
			trigger_text VARCHAR(200) NOT NULL DEFAULT '',
			source VARCHAR(10) NOT NULL DEFAULT 'manual',
			usage_count INT NOT NULL DEFAULT 0,
			status VARCHAR(10) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status (status)
		) $charset;";

		$tables[] = "CREATE TABLE {$prefix}tianma_roles (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(60) NOT NULL,
			description TEXT NULL,
			system_prompt TEXT NULL,
			tools TEXT NULL,
			role_type VARCHAR(10) NOT NULL DEFAULT 'chat',
			bind_tool VARCHAR(60) NOT NULL DEFAULT '',
			llm_provider VARCHAR(20) NOT NULL DEFAULT '',
			llm_base_url VARCHAR(255) NOT NULL DEFAULT '',
			llm_api_key VARCHAR(255) NOT NULL DEFAULT '',
			llm_model VARCHAR(100) NOT NULL DEFAULT '',
			is_builtin TINYINT(1) NOT NULL DEFAULT 0,
			status VARCHAR(10) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status (status)
		) $charset;";

		$tables[] = "CREATE TABLE {$prefix}tianma_conversations (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			title VARCHAR(120) NOT NULL DEFAULT '新对话',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id)
		) $charset;";

		$tables[] = "CREATE TABLE {$prefix}tianma_messages (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			role VARCHAR(20) NOT NULL DEFAULT 'user',
			content LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY conversation_id (conversation_id)
		) $charset;";

		$tables[] = "CREATE TABLE {$prefix}tianma_usage (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			conversation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			prompt_tokens INT NOT NULL DEFAULT 0,
			completion_tokens INT NOT NULL DEFAULT 0,
			total_tokens INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY conversation_id (conversation_id)
		) $charset;";

		$tables[] = "CREATE TABLE {$prefix}tianma_schedules (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(120) NOT NULL,
			prompt TEXT NOT NULL,
			interval_type VARCHAR(20) NOT NULL DEFAULT 'daily',
			interval_minutes INT NOT NULL DEFAULT 60,
			at_time VARCHAR(5) NOT NULL DEFAULT '09:00',
			day_of_week INT NOT NULL DEFAULT 1,
			auto_high TINYINT(1) NOT NULL DEFAULT 0,
			status VARCHAR(10) NOT NULL DEFAULT 'active',
			next_run DATETIME NULL,
			last_run DATETIME NULL,
			last_result LONGTEXT NULL,
			run_count INT NOT NULL DEFAULT 0,
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status (status)
		) $charset;";

		$tables[] = "CREATE TABLE {$prefix}tianma_custom_tools (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(60) NOT NULL,
			description TEXT NULL,
			params LONGTEXT NULL,
			php_code LONGTEXT NOT NULL,
			risk VARCHAR(10) NOT NULL DEFAULT 'low',
			status VARCHAR(10) NOT NULL DEFAULT 'active',
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY name (name)
		) $charset;";

		$tables[] = "CREATE TABLE {$prefix}tianma_worklogs (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			log_date DATE NOT NULL,
			content LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY log_date (log_date)
		) $charset;";

		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}

		// 内置角色种子
		if ( class_exists( 'Tianma_Role' ) ) {
			Tianma_Role::seed_builtins();
		}
	}

	/** 版本升级：补建新表 / 新增列 / 种子数据（dbDelta 幂等） */
	public function maybe_upgrade() {
		if ( get_option( 'tianma_db_version' ) === TIANMA_DB_VERSION ) {
			return;
		}
		self::create_tables();
		self::upgrade_columns();
		update_option( 'tianma_db_version', TIANMA_DB_VERSION );
	}

	/** 为已存在的表补充新增列（dbDelta 不支持加列，需手动 ALTER） */
	private static function upgrade_columns() {
		global $wpdb;

		$cols = $wpdb->get_col( "DESC {$wpdb->prefix}tianma_roles" );
		if ( $cols && ! in_array( 'llm_provider', $cols, true ) ) {
			$wpdb->query(
				"ALTER TABLE {$wpdb->prefix}tianma_roles
				 ADD llm_provider VARCHAR(20) NOT NULL DEFAULT '' AFTER tools,
				 ADD llm_base_url VARCHAR(255) NOT NULL DEFAULT '' AFTER llm_provider,
				 ADD llm_api_key VARCHAR(255) NOT NULL DEFAULT '' AFTER llm_base_url,
				 ADD llm_model VARCHAR(100) NOT NULL DEFAULT '' AFTER llm_api_key"
			);
		}
		// 角色类型（chat 聊天型 / functional 功能性）与绑定输出工具
		$cols = $wpdb->get_col( "DESC {$wpdb->prefix}tianma_roles" );
		if ( $cols && ! in_array( 'role_type', $cols, true ) ) {
			$wpdb->query(
				"ALTER TABLE {$wpdb->prefix}tianma_roles
				 ADD role_type VARCHAR(10) NOT NULL DEFAULT 'chat' AFTER tools,
				 ADD bind_tool VARCHAR(60) NOT NULL DEFAULT '' AFTER role_type"
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * 初始化
	 * ------------------------------------------------------------------- */

	public function init() {
		load_plugin_textdomain( 'tianma', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/* ---------------------------------------------------------------------
	 * 后台菜单
	 * ------------------------------------------------------------------- */

	public function admin_menu() {
		$cap = 'manage_options';

		add_menu_page(
			__( '天马行空 WP 智能体', 'tianma' ),
			__( '天马智能体', 'tianma' ),
			$cap,
			'tianma-chat',
			array( $this, 'render_chat_page' ),
			'dashicons-superhero',
			3
		);

		add_submenu_page(
			'tianma-chat',
			__( '智能体对话', 'tianma' ),
			__( '对话', 'tianma' ),
			$cap,
			'tianma-chat',
			array( $this, 'render_chat_page' )
		);

		add_submenu_page(
			'tianma-chat',
			__( '模型设置', 'tianma' ),
			__( '模型设置', 'tianma' ),
			$cap,
			'tianma-settings',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'tianma-chat',
			__( '角色与技能', 'tianma' ),
			__( '角色与技能', 'tianma' ),
			$cap,
			'tianma-roles',
			array( $this, 'render_roles_page' )
		);

		add_submenu_page(
			'tianma-chat',
			__( '定时任务', 'tianma' ),
			__( '定时任务', 'tianma' ),
			$cap,
			'tianma-schedules',
			array( $this, 'render_schedules_page' )
		);
	}

	public function render_chat_page() {
		// 广告位（开源插件盈利点）绑定核心渲染：组件缺失/被篡改/占位符被移除 → 聊天页拒绝渲染
		// 防别人分发后删除广告：动广告位 = 页面不可用，必须恢复原版文件
		if ( ! class_exists( 'Tianma_Ad' ) ) {
			wp_die( '天马插件核心组件缺失（Tianma_Ad），请恢复原版插件文件后使用。', '组件缺失', array( 'response' => 500 ) );
		}
		if ( ! Tianma_Ad::verify() ) {
			wp_die( '天马插件广告组件数据校验失败（可能被篡改），请恢复原版插件文件后使用。', '组件校验失败', array( 'response' => 500 ) );
		}
		ob_start();
		include TIANMA_PATH . 'admin/templates/page-chat.php';
		$html = ob_get_clean();
		if ( false === strpos( (string) $html, '<!--TIANMA_AD-->' ) || false === strpos( (string) $html, '<!--TIANMA_AD_BANNER-->' ) ) {
			wp_die( '天马插件页面组件异常（广告位被移除），请恢复原版插件文件后使用。', '组件异常', array( 'response' => 500 ) );
		}
		$html = str_replace( '<!--TIANMA_AD_BANNER-->', Tianma_Ad::render_banner(), $html );
		echo str_replace( '<!--TIANMA_AD-->', Tianma_Ad::render(), $html );
	}

	public function render_settings_page() {
		include TIANMA_PATH . 'admin/templates/page-settings.php';
	}

	public function render_roles_page() {
		include TIANMA_PATH . 'admin/templates/page-roles.php';
	}

	public function render_schedules_page() {
		include TIANMA_PATH . 'admin/templates/page-schedules.php';
	}

	/* ---------------------------------------------------------------------
	 * 资源
	 * ------------------------------------------------------------------- */

	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'tianma' ) ) {
			return;
		}
		$screen = get_current_screen();
		$page   = $screen ? $screen->id : '';

		wp_enqueue_style( 'tianma-chat', TIANMA_URL . 'admin/css/chat.css', array(), TIANMA_VERSION );

		if ( false !== strpos( $page, 'tianma-roles' ) ) {
			wp_enqueue_script( 'tianma-roles', TIANMA_URL . 'admin/js/roles.js', array(), TIANMA_VERSION, true );
			wp_localize_script( 'tianma-roles', 'TIANMA', array(
				'api'        => esc_url_raw( rest_url( 'tianma/v1/' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'all_tools'  => Tianma_Tools::names(),
				'presets'    => Tianma_Settings::presets(),
				'i18n'       => array(
					'confirm_delete_role'   => __( '确定删除该角色？', 'tianma' ),
					'confirm_delete_skill'  => __( '确定删除该技能？', 'tianma' ),
					'saved'                 => __( '已保存', 'tianma' ),
				),
			) );
		}

		if ( false !== strpos( $page, 'tianma-schedules' ) ) {
			wp_enqueue_script( 'tianma-schedules', TIANMA_URL . 'admin/js/schedules.js', array(), TIANMA_VERSION, true );
			wp_localize_script( 'tianma-schedules', 'TIANMA', array(
				'api'   => esc_url_raw( rest_url( 'tianma/v1/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => array(
					'confirm_delete' => __( '确定删除该定时任务？删除后不可恢复。', 'tianma' ),
					'confirm_run'    => __( '立即执行该任务？将真实执行任务指令（可能产生实际效果）。', 'tianma' ),
					'saved'          => __( '已保存', 'tianma' ),
					'running'        => __( '执行中，请稍候…', 'tianma' ),
				),
			) );
		}

		if ( false !== strpos( $page, 'tianma-settings' ) ) {
			wp_enqueue_script( 'tianma-settings', TIANMA_URL . 'admin/js/settings.js', array(), TIANMA_VERSION, true );
			wp_localize_script( 'tianma-settings', 'TIANMA', array(
				'api'   => esc_url_raw( rest_url( 'tianma/v1/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'today' => current_time( 'Y-m-d' ),
				'i18n'  => array(
					'confirm_delete' => __( '确定删除这一天的工作日志？删除后不可恢复。', 'tianma' ),
					'saved'          => __( '已保存', 'tianma' ),
					'load_failed'    => __( '加载工作日志失败', 'tianma' ),
					'save_failed'    => __( '保存失败，请重试', 'tianma' ),
					'empty_confirm'  => __( '内容为空将清空该天日志，确定保存？', 'tianma' ),
				),
			) );
		}

		if ( false !== strpos( $page, 'tianma-chat' ) ) {
			wp_enqueue_script( 'tianma-chat', TIANMA_URL . 'admin/js/chat.js', array(), TIANMA_VERSION, true );
			wp_localize_script( 'tianma-chat', 'TIANMA', array(
				'api'        => esc_url_raw( rest_url( 'tianma/v1/' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'i18n'       => array(
					'thinking'  => __( '天马思考中…', 'tianma' ),
					'error'     => __( '请求失败，请稍后重试', 'tianma' ),
					'confirm'   => __( '此操作需要你的确认', 'tianma' ),
					'approve'   => __( '允许执行', 'tianma' ),
					'reject'    => __( '拒绝', 'tianma' ),
					'new_chat'  => __( '新对话', 'tianma' ),
					'placeholder' => __( '例如：帮我发布一篇介绍网站的文章，并创建分类「新闻」…', 'tianma' ),
				),
			) );
		}
	}

	/* ---------------------------------------------------------------------
	 * REST API
	 * ------------------------------------------------------------------- */

	public function register_routes() {
		register_rest_route( 'tianma/v1', '/chat', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'api_chat' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'tianma/v1', '/confirm', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'api_confirm' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'tianma/v1', '/history', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'api_history' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'tianma/v1', '/feedback', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'api_feedback' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'tianma/v1', '/test-llm', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'api_test_llm' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'tianma/v1', '/memories', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'api_memories' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'tianma/v1', '/memories', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'api_memories_clear' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'tianma/v1', '/roles', array(
			'methods'             => array( 'GET', 'POST' ),
			'callback'            => array( $this, 'api_roles' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'tianma/v1', '/roles/(?P<id>\d+)', array(
			'methods'             => array( 'POST', 'DELETE' ),
			'callback'            => array( $this, 'api_role_item' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'tianma/v1', '/skills', array(
			'methods'             => array( 'GET', 'POST' ),
			'callback'            => array( $this, 'api_skills' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'tianma/v1', '/skills/(?P<id>\d+)', array(
			'methods'             => array( 'POST', 'DELETE' ),
			'callback'            => array( $this, 'api_skill_item' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'tianma/v1', '/conversations', array(
			'methods'             => array( 'GET', 'POST' ),
			'callback'            => array( $this, 'api_conversations' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'tianma/v1', '/conversations/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'api_conversation_delete' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'tianma/v1', '/conversations/(?P<id>\d+)/messages', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'api_conversation_messages' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'tianma/v1', '/conversations/(?P<id>\d+)/clear', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'api_conversation_clear' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'tianma/v1', '/conversations/clear-all', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'api_conversations_clear_all' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'tianma/v1', '/usage', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'api_usage' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'tianma/v1', '/file-save', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'api_file_save' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'tianma/v1', '/settings', array(
			'methods'             => array( 'GET', 'POST' ),
			'callback'            => array( $this, 'api_settings' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'tianma/v1', '/schedules', array(
			'methods'             => array( 'GET', 'POST' ),
			'callback'            => array( $this, 'api_schedules' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'tianma/v1', '/schedules/(?P<id>\d+)', array(
			'methods'             => array( 'POST', 'DELETE' ),
			'callback'            => array( $this, 'api_schedule_item' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'tianma/v1', '/schedules/(?P<id>\d+)/run', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'api_schedule_run' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'tianma/v1', '/worklogs', array(
			'methods'             => array( 'GET', 'POST' ),
			'callback'            => array( $this, 'api_worklogs' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'tianma/v1', '/worklogs/(?P<day>\d{4}-\d{2}-\d{2})', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'api_worklog_delete' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );
	}

	public function check_permission() {
		return current_user_can( 'manage_options' );
	}

	public function api_chat( $request ) {
		$message   = sanitize_text_field( (string) $request->get_param( 'message' ) );
		$history   = $this->clean_history( $request->get_param( 'history' ) );
		$conv_id   = (int) $request->get_param( 'conversation_id' );
		$user_id   = get_current_user_id();

		if ( '' === $message ) {
			return new WP_Error( 'tianma_empty', '消息不能为空', array( 'status' => 400 ) );
		}

		// 会话归属校验与消息入库
		$conv = $conv_id ? Tianma_Conversation::get( $conv_id, $user_id ) : null;
		if ( $conv_id && ! $conv ) {
			return new WP_Error( 'tianma_conv', '会话不存在或无权访问', array( 'status' => 403 ) );
		}
		if ( $conv ) {
			$msg_count = $conv_id;
			Tianma_Conversation::add_message( $conv_id, 'user', $message );
			// 首条消息自动命名
			$first = (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare(
				"SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}tianma_messages WHERE conversation_id = %d",
				$conv_id
			) );
			if ( 1 === $first ) {
				Tianma_Conversation::autotitle( $conv_id, $message );
			}
		}

		$auto_confirm = $this->parse_auto_confirm( $request );
		$role_id      = (int) $request->get_param( 'role_id' );

		if ( (bool) $request->get_param( 'stream' ) ) {
			$this->stream_chat( $message, $history, $user_id, $conv, $auto_confirm, $role_id );
			return null; // 流式输出后已终止
		}

		$agent = new Tianma_Agent();
		$agent->auto_confirm = $this->parse_auto_confirm( $request );
		if ( $role_id ) {
			$agent->role = Tianma_Role::get( $role_id );
		}
		$res   = $agent->run( $message, $history, $user_id );

		// 非流式：保存助手回复 + 记录 token 用量
		if ( $conv && isset( $res['text'] ) && '' !== trim( (string) $res['text'] ) ) {
			Tianma_Conversation::add_message( $conv_id, 'assistant', $res['text'] );
		}
		if ( isset( $res['usage'] ) && is_array( $res['usage'] ) ) {
			Tianma_Usage::log( $user_id, $conv_id, $res['usage']['prompt_tokens'], $res['usage']['completion_tokens'] );
		}

		return rest_ensure_response( $res );
	}

	/** 解析聊天页「免授权执行」开关：true=直行 / false=严格确认 / null=按全局策略 */
	private function parse_auto_confirm( $request ) {
		$v = $request->get_param( 'auto_confirm' );
		if ( null === $v ) {
			return null;
		}
		return (bool) $v;
	}

	/** 清洗对话历史：history 为 [{role, content}] 对象数组，逐项安全处理 */
	private function clean_history( $history ) {
		if ( ! is_array( $history ) ) {
			return array();
		}
		$clean = array();
		foreach ( $history as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$role    = ( isset( $item['role'] ) && 'assistant' === $item['role'] ) ? 'assistant' : 'user';
			$content = isset( $item['content'] ) ? sanitize_text_field( (string) $item['content'] ) : '';
			if ( '' !== $content ) {
				$clean[] = array( 'role' => $role, 'content' => mb_substr( $content, 0, 4000 ) );
			}
		}
		return $clean;
	}

	/** SSE 流式对话输出（输出后直接终止） */
	private function stream_chat( $message, $history, $user_id, $conv, $auto_confirm = null, $role_id = 0 ) {
		// 彻底关闭所有输出缓冲，确保 SSE 实时推送
		ini_set( 'output_buffering', '0' );
		ini_set( 'zlib.output_compression', '0' );
		ini_set( 'implicit_flush', '1' );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		header( 'Content-Type: text/event-stream; charset=UTF-8' );
		header( 'Cache-Control: no-cache' );
		header( 'X-Accel-Buffering: no' );
		header( 'Connection: keep-alive' );

		$emitter = function ( $event, $payload ) {
			echo "event: {$event}\n";
			echo 'data: ' . wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ) . "\n\n";
			ob_flush();
			flush();
		};

		$agent = new Tianma_Agent();
		$agent->auto_confirm = $auto_confirm;
		if ( $role_id ) {
			$agent->role = Tianma_Role::get( $role_id );
		}
		$done  = $agent->run_stream( $message, $history, $user_id, $emitter );

		// 流式结束：保存助手回复 + 记录 token 用量
		if ( $conv && isset( $done['text'] ) && '' !== trim( (string) $done['text'] ) ) {
			Tianma_Conversation::add_message( (int) $conv->id, 'assistant', $done['text'] );
		}
		if ( isset( $done['usage'] ) && is_array( $done['usage'] ) ) {
			Tianma_Usage::log( $user_id, (int) ( $conv ? $conv->id : 0 ), $done['usage']['prompt_tokens'], $done['usage']['completion_tokens'] );
		}

		echo "event: end\ndata: {}\n\n";
		flush();
		die();
	}

	public function api_confirm( $request ) {
		$hash   = sanitize_key( (string) $request->get_param( 'confirm_id' ) );
		$approve = (bool) $request->get_param( 'approve' );

		if ( '' === $hash ) {
			return new WP_Error( 'tianma_bad_confirm', '缺少确认标识', array( 'status' => 400 ) );
		}

		$confirm = new Tianma_Confirm();
		$agent   = new Tianma_Agent();
		$res     = $agent->resume_after_confirm( $hash, $approve, get_current_user_id() );

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return rest_ensure_response( $res );
	}

	public function api_history( $request ) {
		global $wpdb;
		$limit = min( 50, max( 1, (int) $request->get_param( 'limit' ) ?: 20 ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, summary, status, step_count, tool_count, created_at
				 FROM {$wpdb->prefix}tianma_tasks
				 WHERE user_id = %d
				 ORDER BY id DESC LIMIT %d",
				get_current_user_id(),
				$limit
			)
		);
		return rest_ensure_response( array( 'items' => $rows ) );
	}

	public function api_feedback( $request ) {
		$task_id = (int) $request->get_param( 'task_id' );
		$rating  = (int) $request->get_param( 'rating' );
		$note    = sanitize_text_field( (string) $request->get_param( 'note' ) );

		if ( ! $task_id || $rating < 1 || $rating > 5 ) {
			return new WP_Error( 'tianma_bad_feedback', '参数错误', array( 'status' => 400 ) );
		}

		$memory = new Tianma_Memory();
		$memory->apply_feedback( $task_id, get_current_user_id(), $rating, $note );

		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function api_test_llm( $request ) {
		$provider = sanitize_key( (string) $request->get_param( 'provider' ) );
		$base_url = esc_url_raw( (string) $request->get_param( 'base_url' ) );
		$api_key  = sanitize_text_field( (string) $request->get_param( 'api_key' ) );
		$model    = sanitize_text_field( (string) $request->get_param( 'model' ) );

		// 掩码/空 Key → 用当前已保存的 Key 测试（设置页打码回显后也能正常测试连接）
		if ( '' === $api_key || false !== strpos( $api_key, '•' ) ) {
			$saved = Tianma_Settings::get();
			$api_key = $saved['api_key'];
			if ( '' === $base_url ) { $base_url = $saved['base_url']; }
			if ( '' === $model ) { $model = $saved['model']; }
			if ( '' === $provider ) { $provider = $saved['provider']; }
		}

		$llm = new Tianma_LLM();
		$ok  = $llm->test_connection( $provider, $base_url, $api_key, $model );

		return rest_ensure_response( array(
			'ok'      => $ok,
			'message' => $ok ? '连接成功，模型可用' : '连接失败，请检查配置',
		) );
	}

	public function api_memories() {
		$memory = new Tianma_Memory();
		$items  = $memory->list_all( 100 );
		return rest_ensure_response( array( 'items' => $items ) );
	}

	public function api_memories_clear() {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}tianma_memories" );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* ---------- 角色 ---------- */

	public function api_roles( $request ) {
		$method = $request->get_method();

		if ( 'GET' === $method ) {
			return rest_ensure_response( array( 'items' => Tianma_Role::list_all( '' ) ) );
		}

		// POST：新建
		$llm = $request->get_param( 'llm' );
		$res = Tianma_Role::create(
			sanitize_text_field( (string) $request->get_param( 'name' ) ),
			sanitize_textarea_field( (string) $request->get_param( 'description' ) ),
			sanitize_textarea_field( (string) $request->get_param( 'system_prompt' ) ),
			(array) $request->get_param( 'tools' ),
			0,
			is_array( $llm ) ? $llm : array(),
			array(
				'role_type' => sanitize_key( (string) $request->get_param( 'role_type' ) ),
				'bind_tool' => sanitize_key( (string) $request->get_param( 'bind_tool' ) ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'tianma_role', $res->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'id' => $res ) );
	}

	public function api_role_item( $request ) {
		$id   = (int) $request['id'];
		$role = Tianma_Role::get( $id );
		if ( ! $role ) {
			return new WP_Error( 'tianma_role', '角色不存在', array( 'status' => 404 ) );
		}

		if ( 'DELETE' === $request->get_method() ) {
			if ( $role->is_builtin ) {
				return new WP_Error( 'tianma_role', '内置角色不可删除', array( 'status' => 400 ) );
			}
			Tianma_Role::delete( $id );
			return rest_ensure_response( array( 'ok' => true ) );
		}

		// POST：更新
		$fields = array();
		if ( null !== $request->get_param( 'name' ) ) { $fields['name'] = $request->get_param( 'name' ); }
		if ( null !== $request->get_param( 'description' ) ) { $fields['description'] = $request->get_param( 'description' ); }
		if ( null !== $request->get_param( 'system_prompt' ) ) { $fields['system_prompt'] = $request->get_param( 'system_prompt' ); }
		if ( null !== $request->get_param( 'tools' ) ) { $fields['tools'] = (array) $request->get_param( 'tools' ); }
		if ( null !== $request->get_param( 'role_type' ) ) { $fields['role_type'] = $request->get_param( 'role_type' ); }
		if ( null !== $request->get_param( 'bind_tool' ) ) { $fields['bind_tool'] = $request->get_param( 'bind_tool' ); }
		if ( null !== $request->get_param( 'status' ) ) { $fields['status'] = $request->get_param( 'status' ); }
		if ( $request->has_param( 'llm' ) ) { $fields['llm'] = $request->get_param( 'llm' ); }
		Tianma_Role::update( $id, $fields );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* ---------- 技能 ---------- */

	public function api_skills( $request ) {
		$method = $request->get_method();

		if ( 'GET' === $method ) {
			return rest_ensure_response( array( 'items' => Tianma_Skill::list_all( '' ) ) );
		}

		// POST：新建
		$res = Tianma_Skill::create(
			sanitize_text_field( (string) $request->get_param( 'name' ) ),
			sanitize_textarea_field( (string) $request->get_param( 'description' ) ),
			(array) $request->get_param( 'tools' ),
			sanitize_text_field( (string) $request->get_param( 'trigger' ) ),
			'manual'
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'tianma_skill', $res->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'id' => $res ) );
	}

	public function api_skill_item( $request ) {
		$id    = (int) $request['id'];
		$skill = Tianma_Skill::get( $id );
		if ( ! $skill ) {
			return new WP_Error( 'tianma_skill', '技能不存在', array( 'status' => 404 ) );
		}

		if ( 'DELETE' === $request->get_method() ) {
			Tianma_Skill::delete( $id );
			return rest_ensure_response( array( 'ok' => true ) );
		}

		$fields = array();
		if ( null !== $request->get_param( 'status' ) ) { $fields['status'] = $request->get_param( 'status' ); }
		if ( null !== $request->get_param( 'name' ) ) { $fields['name'] = $request->get_param( 'name' ); }
		if ( null !== $request->get_param( 'description' ) ) { $fields['description'] = $request->get_param( 'description' ); }
		if ( null !== $request->get_param( 'tools' ) ) { $fields['tools'] = (array) $request->get_param( 'tools' ); }
		Tianma_Skill::update( $id, $fields );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* ---------- 会话 ---------- */

	public function api_conversations( $request ) {
		$user_id = get_current_user_id();

		if ( 'GET' === $request->get_method() ) {
			return rest_ensure_response( array( 'items' => Tianma_Conversation::list_all( $user_id ) ) );
		}

		// POST：新建会话
		$title = (string) $request->get_param( 'title' );
		$id    = Tianma_Conversation::create( $user_id, $title );
		return rest_ensure_response( array( 'ok' => true, 'id' => $id, 'title' => '新对话' ) );
	}

	public function api_conversation_messages( $request ) {
		$msgs = Tianma_Conversation::get_messages( (int) $request['id'], get_current_user_id() );
		if ( null === $msgs ) {
			return new WP_Error( 'tianma_conv', '会话不存在或无权访问', array( 'status' => 403 ) );
		}
		return rest_ensure_response( array( 'items' => $msgs ) );
	}

	public function api_conversation_delete( $request ) {
		$ok = Tianma_Conversation::delete( (int) $request['id'], get_current_user_id() );
		if ( ! $ok ) {
			return new WP_Error( 'tianma_conv', '会话不存在或无权访问', array( 'status' => 403 ) );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function api_conversation_clear( $request ) {
		$ok = Tianma_Conversation::clear_messages( (int) $request['id'], get_current_user_id() );
		if ( ! $ok ) {
			return new WP_Error( 'tianma_conv', '会话不存在或无权访问', array( 'status' => 403 ) );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function api_conversations_clear_all( $request ) {
		Tianma_Conversation::clear_all( get_current_user_id() );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* ---------- Token 用量 ---------- */

	public function api_usage( $request ) {
		$user_id = get_current_user_id();
		$conv_id = (int) $request->get_param( 'conversation_id' );
		return rest_ensure_response( Tianma_Usage::stats( $user_id, $conv_id ) );
	}

	/** 可视化编辑保存：用户在前端编辑器手动保存文件（站点内路径 + 备份 + 审计） */
	public function api_file_save( $request ) {
		$path    = sanitize_text_field( (string) $request->get_param( 'path' ) );
		$content = (string) $request->get_param( 'content' );
		if ( '' === $path ) {
			return new WP_Error( 'tianma_file', '缺少文件路径', array( 'status' => 400 ) );
		}

		$real = Tianma_Tools::resolve_safe_path( $path ); // 写路径：必须位于站点根目录内
		if ( false === $real ) {
			return new WP_Error( 'tianma_file', '路径非法或超出站点根目录', array( 'status' => 400 ) );
		}
		if ( ! is_file( $real ) ) {
			return new WP_Error( 'tianma_file', '文件不存在：' . $real, array( 'status' => 400 ) );
		}
		if ( ! is_writable( $real ) && ! is_writable( dirname( $real ) ) ) {
			return new WP_Error( 'tianma_file', '文件不可写（检查权限）', array( 'status' => 400 ) );
		}

		// 备份原文件
		$backup_dir = wp_upload_dir()['basedir'] . '/tianma-backups/files';
		wp_mkdir_p( $backup_dir );
		$stamp = date( 'Ymd-His' );
		copy( $real, $backup_dir . '/' . basename( $real ) . '.' . $stamp . '.bak' );

		$ok = file_put_contents( $real, $content );
		if ( false === $ok ) {
			return new WP_Error( 'tianma_file', '保存失败（检查文件权限）', array( 'status' => 500 ) );
		}

		Tianma_Audit::log( 'file_save_manual', array( 'path' => $real, 'bytes' => $ok ), get_current_user_id() );

		// 若是 PHP 文件，顺手语法检查提示
		$lint = '';
		if ( preg_match( '/\.php$/i', $real ) && class_exists( 'Tianma_Tools_File' ) ) {
			$lint_res = Tianma_Tools::execute( 'validate_php', array( 'path' => $real ) );
			if ( ! $lint_res['ok'] ) {
				$lint = ' ⚠ PHP 语法检查未通过：' . mb_substr( $lint_res['message'], 0, 200 );
			}
		}

		return rest_ensure_response( array(
			'ok'      => true,
			'message' => '已保存：' . $real . '（' . $ok . ' 字节）' . $lint,
			'backup'  => $backup_dir . '/' . basename( $real ) . '.' . $stamp . '.bak',
		) );
	}

	/* ---------- 设置（聊天页模型切换用） ---------- */

	public function api_settings( $request ) {
		if ( 'GET' === $request->get_method() ) {
			$s = Tianma_Settings::get();
			return rest_ensure_response( array(
				'settings' => array(
					'provider'      => $s['provider'],
					'base_url'      => $s['base_url'],
					'model'         => $s['model'],
					'has_key'       => '' !== $s['api_key'],
					'key_masked'    => '' === $s['api_key'] ? '' : substr( $s['api_key'], 0, 4 ) . '••••' . substr( $s['api_key'], -4 ),
					'embedding_model' => $s['embedding_model'],
					'confirm_mode'  => $s['confirm_mode'],
					'mock_mode'     => $s['mock_mode'],
				),
				'presets' => Tianma_Settings::presets(),
			) );
		}

		// POST：部分更新（provider/base_url/model 等；不传 api_key 则保留原 Key）
		$data = $request->get_params();

		// 切换 provider 时【不】自动改写 base_url：用户手动设置的地址原样保留（持久化优先），
		// 只有用户显式传 base_url 才会更新，防止预设信息覆盖用户自定义配置
		$saved = Tianma_Settings::update( $data );
		return rest_ensure_response( array(
			'ok'       => true,
			'settings' => array(
				'provider'   => $saved['provider'],
				'model'      => $saved['model'],
				'has_key'    => '' !== $saved['api_key'],
				'key_masked' => '' === $saved['api_key'] ? '' : substr( $saved['api_key'], 0, 4 ) . '••••' . substr( $saved['api_key'], -4 ),
			),
		) );
	}

	/* ---------- 定时任务 ---------- */

	public function api_schedules( $request ) {
		if ( 'GET' === $request->get_method() ) {
			$items = array();
			foreach ( Tianma_Schedule::list_all() as $t ) {
				$items[] = self::schedule_row( $t );
			}
			return rest_ensure_response( array( 'items' => $items, 'intervals' => Tianma_Schedule::interval_labels() ) );
		}

		// POST：创建
		$id = Tianma_Schedule::create( $request->get_params(), get_current_user_id() );
		if ( is_wp_error( $id ) ) {
			return new WP_Error( 'tianma_schedule', $id->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'id' => $id, 'item' => self::schedule_row( Tianma_Schedule::get( $id ) ) ) );
	}

	public function api_schedule_item( $request ) {
		$id = (int) $request['id'];
		$t  = Tianma_Schedule::get( $id );
		if ( ! $t ) {
			return new WP_Error( 'tianma_schedule', '定时任务不存在', array( 'status' => 404 ) );
		}

		if ( 'DELETE' === $request->get_method() ) {
			Tianma_Schedule::delete( $id );
			return rest_ensure_response( array( 'ok' => true ) );
		}

		// POST：更新
		$fields = array();
		foreach ( array( 'name', 'prompt', 'interval_type', 'at_time', 'day_of_week', 'interval_minutes', 'auto_high', 'status' ) as $k ) {
			$v = $request->get_param( $k );
			if ( null !== $v ) {
				$fields[ $k ] = $v;
			}
		}
		Tianma_Schedule::update( $id, $fields );
		return rest_ensure_response( array( 'ok' => true, 'item' => self::schedule_row( Tianma_Schedule::get( $id ) ) ) );
	}

	public function api_schedule_run( $request ) {
		$id = (int) $request['id'];
		$t  = Tianma_Schedule::get( $id );
		if ( ! $t ) {
			return new WP_Error( 'tianma_schedule', '定时任务不存在', array( 'status' => 404 ) );
		}
		$res = Tianma_Schedule::run( $id, 'manual' );
		return rest_ensure_response( array( 'ok' => true, 'result' => $res, 'item' => self::schedule_row( Tianma_Schedule::get( $id ) ) ) );
	}

	/** 工作日志：GET 列表 / POST 保存（day 日期 YYYY-MM-DD；mode=append 追加 / save 整体覆盖） */
	public function api_worklogs( $request ) {
		if ( 'GET' === $request->get_method() ) {
			$limit = min( 200, max( 1, (int) $request->get_param( 'limit' ) ?: 30 ) );
			return rest_ensure_response( array( 'items' => Tianma_Worklog::list( $limit ) ) );
		}

		$day     = Tianma_Worklog::normalize_day( (string) $request->get_param( 'day' ) );
		$content = (string) $request->get_param( 'content' );
		$mode    = 'append' === (string) $request->get_param( 'mode' ) ? 'append' : 'save';

		if ( 'append' === $mode ) {
			$res = Tianma_Worklog::append( $content, $day );
		} else {
			$res = Tianma_Worklog::update( $day, $content );
		}
		$res['item'] = null;
		foreach ( Tianma_Worklog::list( 30 ) as $row ) {
			if ( $row['log_date'] === $day ) {
				$res['item'] = $row;
				break;
			}
		}
		return rest_ensure_response( $res );
	}

	public function api_worklog_delete( $request ) {
		$day = Tianma_Worklog::normalize_day( (string) $request['day'] );
		return rest_ensure_response( Tianma_Worklog::delete( $day ) );
	}
}
