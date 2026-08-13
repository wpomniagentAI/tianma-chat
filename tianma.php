<?php
/**
 * Plugin Name: 天马行空 WP 智能体
 * Plugin URI:  https://github.com/wpomniagentAI/tianma-chat
 * Description: 内置 AI 智能体助手：连接外部大模型 API 与内置向量记忆库，用自然语言完成 WordPress 全站管理、开发与维护任务。自学习，越用越聪明。
 * Version:     1.7.0
 * Author:      天马（GitHub wpomniagentAI/tianma-chat · QQ 3908349048）
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tianma
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'TIANMA_VERSION', '1.7.0' );
define( 'TIANMA_PATH', plugin_dir_path( __FILE__ ) );
define( 'TIANMA_URL', plugin_dir_url( __FILE__ ) );
define( 'TIANMA_DB_VERSION', '1.5.1' );

require_once TIANMA_PATH . 'includes/class-tianma.php';
require_once TIANMA_PATH . 'includes/class-settings.php';
require_once TIANMA_PATH . 'includes/class-llm.php';
require_once TIANMA_PATH . 'includes/class-tools.php';
require_once TIANMA_PATH . 'includes/class-memory.php';
require_once TIANMA_PATH . 'includes/class-skill.php';
require_once TIANMA_PATH . 'includes/class-role.php';
require_once TIANMA_PATH . 'includes/class-collab.php';
require_once TIANMA_PATH . 'includes/class-conversation.php';
require_once TIANMA_PATH . 'includes/class-usage.php';
require_once TIANMA_PATH . 'includes/class-audit.php';
require_once TIANMA_PATH . 'includes/class-confirm.php';
require_once TIANMA_PATH . 'includes/class-agent.php';
require_once TIANMA_PATH . 'includes/class-schedule.php';
require_once TIANMA_PATH . 'includes/class-worklog.php';
// 广告组件软加载（防删设计）：文件被删除时不致命崩溃全站，
// 由 Tianma_Core::ad_guard() 检测缺失并自动停用插件（必须恢复原版文件才能再用）
if ( file_exists( TIANMA_PATH . 'includes/class-ad.php' ) ) {
	require_once TIANMA_PATH . 'includes/class-ad.php';
}

register_activation_hook( __FILE__, array( 'Tianma_Core', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Tianma_Core', 'deactivate' ) );

function tianma() {
	return Tianma_Core::instance();
}

tianma();
