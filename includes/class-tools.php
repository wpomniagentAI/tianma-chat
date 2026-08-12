<?php
/**
 * 工具注册表与调度器
 *
 * 每个工具 = 名称 + 描述 + 参数 Schema + 风险等级 + 回调。
 * 模型通过 Function Calling 触发工具，本类负责分发与安全校验。
 *
 * @package Tianma
 */

defined( 'ABSPATH' ) || exit;

class Tianma_Tools {

	/** 工具定义表（惰性构建） */
	private static $registry = null;

	public static function init() {
		if ( null !== self::$registry ) {
			return;
		}
		require_once TIANMA_PATH . 'includes/class-tools-content.php';
		require_once TIANMA_PATH . 'includes/class-tools-file.php';
		require_once TIANMA_PATH . 'includes/class-tools-plugin.php';
		require_once TIANMA_PATH . 'includes/class-tools-system.php';
		require_once TIANMA_PATH . 'includes/class-tools-agent.php';
		require_once TIANMA_PATH . 'includes/class-tools-tianma.php';

		$t = array();

		/* ---------------- 内容管理 ---------------- */
		$t['get_site_info'] = array( 'description' => '获取站点基本信息（名称、地址、版本、主题、插件数、文章数等）', 'risk' => 'low', 'params' => array(), 'required' => array(), 'cb' => array( 'Tianma_Tools_Content', 'get_site_info' ) );
		$t['get_current_time'] = array( 'description' => '获取当前日期时间（站点本地时区）、星期几与时间戳。用于安排定时任务、设置文章定时发布、判断时间相关逻辑', 'risk' => 'low', 'params' => array(), 'required' => array(), 'cb' => array( 'Tianma_Tools_System', 'get_current_time' ) );
		$t['list_posts'] = array( 'description' => '列出文章列表', 'risk' => 'low', 'params' => array( 'number' => array( 'type' => 'integer', 'description' => '数量，默认 10' ), 'status' => array( 'type' => 'string', 'description' => '状态：publish/draft/pending/trash' ), 'search' => array( 'type' => 'string', 'description' => '关键词搜索' ) ), 'required' => array(), 'cb' => array( 'Tianma_Tools_Content', 'list_posts' ) );
		$t['get_post'] = array( 'description' => '获取单篇文章详情', 'risk' => 'low', 'params' => array( 'post_id' => array( 'type' => 'integer', 'description' => '文章 ID' ) ), 'required' => array( 'post_id' ), 'cb' => array( 'Tianma_Tools_Content', 'get_post' ) );
		$t['create_post'] = array( 'description' => '创建一篇新文章', 'risk' => 'low', 'params' => array( 'title' => array( 'type' => 'string', 'description' => '文章标题' ), 'content' => array( 'type' => 'string', 'description' => '正文内容（支持 HTML）' ), 'status' => array( 'type' => 'string', 'description' => 'draft（草稿）或 publish（直接发布）' ), 'category_id' => array( 'type' => 'integer', 'description' => '分类 ID' ), 'tags' => array( 'type' => 'string', 'description' => '标签，逗号分隔' ) ), 'required' => array( 'title' ), 'cb' => array( 'Tianma_Tools_Content', 'create_post' ) );
		$t['update_post'] = array( 'description' => '更新文章（标题/正文/状态）', 'risk' => 'low', 'params' => array( 'post_id' => array( 'type' => 'integer', 'description' => '文章 ID' ), 'title' => array( 'type' => 'string' ), 'content' => array( 'type' => 'string' ), 'status' => array( 'type' => 'string' ) ), 'required' => array( 'post_id' ), 'cb' => array( 'Tianma_Tools_Content', 'update_post' ) );
		$t['delete_post'] = array( 'description' => '永久删除一篇文章（高危）', 'risk' => 'high', 'params' => array( 'post_id' => array( 'type' => 'integer', 'description' => '文章 ID' ) ), 'required' => array( 'post_id' ), 'cb' => array( 'Tianma_Tools_Content', 'delete_post' ) );
		$t['create_page'] = array( 'description' => '创建新页面', 'risk' => 'low', 'params' => array( 'title' => array( 'type' => 'string' ), 'content' => array( 'type' => 'string' ), 'status' => array( 'type' => 'string', 'description' => 'draft 或 publish' ) ), 'required' => array( 'title' ), 'cb' => array( 'Tianma_Tools_Content', 'create_page' ) );
		$t['update_page'] = array( 'description' => '更新页面', 'risk' => 'low', 'params' => array( 'page_id' => array( 'type' => 'integer' ), 'title' => array( 'type' => 'string' ), 'content' => array( 'type' => 'string' ), 'status' => array( 'type' => 'string' ) ), 'required' => array( 'page_id' ), 'cb' => array( 'Tianma_Tools_Content', 'update_page' ) );
		$t['delete_page'] = array( 'description' => '永久删除页面（高危）', 'risk' => 'high', 'params' => array( 'page_id' => array( 'type' => 'integer' ) ), 'required' => array( 'page_id' ), 'cb' => array( 'Tianma_Tools_Content', 'delete_page' ) );
		$t['list_pages'] = array( 'description' => '列出页面', 'risk' => 'low', 'params' => array( 'number' => array( 'type' => 'integer', 'description' => '数量，默认 10' ) ), 'required' => array(), 'cb' => array( 'Tianma_Tools_Content', 'list_pages' ) );
		$t['list_media'] = array( 'description' => '列出媒体库文件', 'risk' => 'low', 'params' => array( 'number' => array( 'type' => 'integer', 'description' => '数量，默认 10' ), 'search' => array( 'type' => 'string' ) ), 'required' => array(), 'cb' => array( 'Tianma_Tools_Content', 'list_media' ) );
		$t['upload_media'] = array( 'description' => '从远程 URL 上传图片/文件到媒体库', 'risk' => 'low', 'params' => array( 'url' => array( 'type' => 'string', 'description' => '文件远程地址' ) ), 'required' => array( 'url' ), 'cb' => array( 'Tianma_Tools_Content', 'upload_media' ) );
		$t['create_category'] = array( 'description' => '创建文章分类', 'risk' => 'low', 'params' => array( 'name' => array( 'type' => 'string' ), 'parent_id' => array( 'type' => 'integer' ) ), 'required' => array( 'name' ), 'cb' => array( 'Tianma_Tools_Content', 'create_category' ) );
		$t['list_categories'] = array( 'description' => '列出全部分类', 'risk' => 'low', 'params' => array(), 'required' => array(), 'cb' => array( 'Tianma_Tools_Content', 'list_categories' ) );
		$t['generate_image'] = array( 'description' => '用 AI 生成图片（调用智谱 CogView 生图模型），返回图片 URL。参数：prompt（图片内容描述，必填）、size（可选：1024x1024 / 1024x1536 / 1536x1024）', 'risk' => 'low', 'params' => array( 'prompt' => array( 'type' => 'string', 'description' => '图片内容描述（中文即可）' ), 'size' => array( 'type' => 'string', 'description' => '尺寸，默认 1024x1024' ) ), 'required' => array( 'prompt' ), 'cb' => array( 'Tianma_Tools_Content', 'generate_image' ) );

		/* ---------------- 文件与代码 ---------------- */
		$t['file_list'] = array( 'description' => '列出指定目录内容。相对路径基于站点根目录（如 wp-content/plugins），也支持绝对路径读取任意目录（如 C:/Users、D:/、/etc）。path 缺省时列出站点根目录', 'risk' => 'low', 'params' => array( 'path' => array( 'type' => 'string', 'description' => '目录路径（相对或绝对），可省略' ) ), 'required' => array(), 'cb' => array( 'Tianma_Tools_File', 'file_list' ) );
		$t['file_read'] = array( 'description' => '读取文件内容（文本文件，限 500KB）。支持相对路径（站点内）或绝对路径（任意位置）。站点常见路径：wp-content/plugins/<插件名>/、wp-content/themes/<主题名>/、wp-content/uploads/、wp-config.php', 'risk' => 'low', 'params' => array( 'path' => array( 'type' => 'string', 'description' => '要读取的文件路径（相对或绝对）' ) ), 'required' => array(), 'cb' => array( 'Tianma_Tools_File', 'file_read' ) );
		$t['file_write'] = array( 'description' => '写入/覆盖文件内容（新文件或修改已有文件，会自动备份原文件）', 'risk' => 'low', 'params' => array( 'path' => array( 'type' => 'string' ), 'content' => array( 'type' => 'string' ), 'mode' => array( 'type' => 'string', 'description' => 'write 覆盖 或 append 追加' ) ), 'required' => array( 'path', 'content' ), 'cb' => array( 'Tianma_Tools_File', 'file_write' ) );
		$t['file_delete'] = array( 'description' => '删除文件（高危）', 'risk' => 'high', 'params' => array( 'path' => array( 'type' => 'string' ) ), 'required' => array( 'path' ), 'cb' => array( 'Tianma_Tools_File', 'file_delete' ) );
		$t['file_rename'] = array( 'description' => '重命名文件', 'risk' => 'low', 'params' => array( 'path' => array( 'type' => 'string' ), 'new_name' => array( 'type' => 'string' ) ), 'required' => array( 'path', 'new_name' ), 'cb' => array( 'Tianma_Tools_File', 'file_rename' ) );
		$t['file_create_dir'] = array( 'description' => '创建目录', 'risk' => 'low', 'params' => array( 'path' => array( 'type' => 'string' ) ), 'required' => array( 'path' ), 'cb' => array( 'Tianma_Tools_File', 'file_create_dir' ) );
		$t['validate_php'] = array( 'description' => 'PHP 语法检查（检查文件是否存在语法错误）', 'risk' => 'low', 'params' => array( 'path' => array( 'type' => 'string' ) ), 'required' => array( 'path' ), 'cb' => array( 'Tianma_Tools_File', 'validate_php' ) );

		/* ---------------- 主题与插件 ---------------- */
		$t['plugin_list'] = array( 'description' => '列出已安装插件及状态', 'risk' => 'low', 'params' => array( 'status' => array( 'type' => 'string', 'description' => 'active 或 inactive' ) ), 'required' => array(), 'cb' => array( 'Tianma_Tools_Plugin', 'plugin_list' ) );
		$t['plugin_activate'] = array( 'description' => '启用插件', 'risk' => 'low', 'params' => array( 'slug' => array( 'type' => 'string', 'description' => '插件目录名，如 tianma' ) ), 'required' => array( 'slug' ), 'cb' => array( 'Tianma_Tools_Plugin', 'plugin_activate' ) );
		$t['plugin_deactivate'] = array( 'description' => '停用插件', 'risk' => 'low', 'params' => array( 'slug' => array( 'type' => 'string' ) ), 'required' => array( 'slug' ), 'cb' => array( 'Tianma_Tools_Plugin', 'plugin_deactivate' ) );
		$t['plugin_delete'] = array( 'description' => '删除插件（高危）', 'risk' => 'high', 'params' => array( 'slug' => array( 'type' => 'string' ) ), 'required' => array( 'slug' ), 'cb' => array( 'Tianma_Tools_Plugin', 'plugin_delete' ) );
		$t['plugin_install'] = array( 'description' => '从 zip 链接安装插件（高危）', 'risk' => 'high', 'params' => array( 'url' => array( 'type' => 'string', 'description' => '插件 zip 下载地址' ) ), 'required' => array( 'url' ), 'cb' => array( 'Tianma_Tools_Plugin', 'plugin_install' ) );
		$t['theme_list'] = array( 'description' => '列出已安装主题', 'risk' => 'low', 'params' => array(), 'required' => array(), 'cb' => array( 'Tianma_Tools_Plugin', 'theme_list' ) );
		$t['theme_activate'] = array( 'description' => '切换启用主题', 'risk' => 'low', 'params' => array( 'slug' => array( 'type' => 'string', 'description' => '主题目录名' ) ), 'required' => array( 'slug' ), 'cb' => array( 'Tianma_Tools_Plugin', 'theme_activate' ) );
		$t['theme_delete'] = array( 'description' => '删除主题（高危）', 'risk' => 'high', 'params' => array( 'slug' => array( 'type' => 'string' ) ), 'required' => array( 'slug' ), 'cb' => array( 'Tianma_Tools_Plugin', 'theme_delete' ) );
		$t['create_plugin_skel'] = array( 'description' => '创建一个最小可用的插件骨架（含主文件与 readme）', 'risk' => 'low', 'params' => array( 'name' => array( 'type' => 'string', 'description' => '插件显示名称' ), 'slug' => array( 'type' => 'string', 'description' => '目录名，默认按名称生成' ) ), 'required' => array( 'name' ), 'cb' => array( 'Tianma_Tools_Plugin', 'create_plugin_skel' ) );
		$t['create_theme_skel'] = array( 'description' => '创建一个最小可用的主题骨架（style.css + index.php + functions.php）', 'risk' => 'low', 'params' => array( 'name' => array( 'type' => 'string', 'description' => '主题名称' ), 'slug' => array( 'type' => 'string', 'description' => '目录名，默认按名称生成' ) ), 'required' => array( 'name' ), 'cb' => array( 'Tianma_Tools_Plugin', 'create_theme_skel' ) );

		/* ---------------- 系统与维护 ---------------- */
		$t['get_settings'] = array( 'description' => '查看站点关键设置（标题、副标题、时区、永久链接等）', 'risk' => 'low', 'params' => array(), 'required' => array(), 'cb' => array( 'Tianma_Tools_System', 'get_settings' ) );
		$t['update_settings'] = array( 'description' => '修改站点设置（高危），支持 key: blogname/blogdescription/timezone_string/posts_per_page', 'risk' => 'high', 'params' => array( 'key' => array( 'type' => 'string' ), 'value' => array( 'type' => 'string' ) ), 'required' => array( 'key', 'value' ), 'cb' => array( 'Tianma_Tools_System', 'update_settings' ) );
		$t['user_list'] = array( 'description' => '列出用户', 'risk' => 'low', 'params' => array( 'number' => array( 'type' => 'integer' ), 'role' => array( 'type' => 'string' ) ), 'required' => array(), 'cb' => array( 'Tianma_Tools_System', 'user_list' ) );
		$t['user_create'] = array( 'description' => '创建新用户（高危）', 'risk' => 'high', 'params' => array( 'username' => array( 'type' => 'string' ), 'email' => array( 'type' => 'string' ), 'password' => array( 'type' => 'string' ), 'role' => array( 'type' => 'string', 'description' => 'administrator/editor/author/subscriber' ) ), 'required' => array( 'username', 'email' ), 'cb' => array( 'Tianma_Tools_System', 'user_create' ) );
		$t['site_backup'] = array( 'description' => '备份数据库为 SQL 文件（保存到 wp-content/uploads/tianma-backups/）', 'risk' => 'low', 'params' => array( 'note' => array( 'type' => 'string', 'description' => '备注' ) ), 'required' => array(), 'cb' => array( 'Tianma_Tools_System', 'site_backup' ) );
		$t['clear_cache'] = array( 'description' => '清理 WordPress 对象缓存与瞬态缓存', 'risk' => 'low', 'params' => array(), 'required' => array(), 'cb' => array( 'Tianma_Tools_System', 'clear_cache' ) );
		$t['wp_update_check'] = array( 'description' => '检查 WordPress 核心、插件、主题是否有更新', 'risk' => 'low', 'params' => array(), 'required' => array(), 'cb' => array( 'Tianma_Tools_System', 'wp_update_check' ) );
		$t['get_error_log'] = array( 'description' => '读取 WordPress 调试日志尾部内容（wp-content/debug.log）', 'risk' => 'low', 'params' => array( 'lines' => array( 'type' => 'integer', 'description' => '读取行数，默认 20' ) ), 'required' => array(), 'cb' => array( 'Tianma_Tools_System', 'get_error_log' ) );
		$t['read_only_db_query'] = array( 'description' => '对数据库执行只读 SQL 查询（仅允许 SELECT，高危）', 'risk' => 'high', 'params' => array( 'query' => array( 'type' => 'string', 'description' => 'SELECT 语句' ) ), 'required' => array( 'query' ), 'cb' => array( 'Tianma_Tools_System', 'read_only_db_query' ) );
		$t['list_options'] = array( 'description' => '列出站点选项（wp_options），可按关键词搜索，用于查看插件/主题/核心配置项', 'risk' => 'low', 'params' => array( 'search' => array( 'type' => 'string', 'description' => '关键词，如插件前缀 wpsc 或 wp_super_cache' ) ), 'required' => array(), 'cb' => array( 'Tianma_Tools_System', 'list_options' ) );
		$t['get_option'] = array( 'description' => '读取指定站点选项的值（wp_options）', 'risk' => 'low', 'params' => array( 'name' => array( 'type' => 'string', 'description' => '选项名，如 wp_super_cache_enabled' ) ), 'required' => array( 'name' ), 'cb' => array( 'Tianma_Tools_System', 'get_option' ) );
		$t['set_option'] = array( 'description' => '修改站点选项的值（高危，可配置任意插件/站点设置；修改前自动备份）', 'risk' => 'high', 'params' => array( 'name' => array( 'type' => 'string', 'description' => '选项名' ), 'value' => array( 'type' => 'string', 'description' => '新值，JSON 格式（支持 true/false/数字/数组/字符串）' ) ), 'required' => array( 'name', 'value' ), 'cb' => array( 'Tianma_Tools_System', 'set_option' ) );

		/* ---------------- Agent 生态（角色 / 技能 / 协作） ---------------- */
		$t['list_roles'] = array( 'description' => '列出所有能力角色（名称、职责、状态、工具数，以及每个角色的模型配置：独立模型 or 全局模型、provider、model、是否已配 Key）。查询任何角色的信息（包括模型配置）直接用它，无需读代码文件', 'risk' => 'low', 'params' => array(), 'required' => array(), 'cb' => array( 'Tianma_Tools_Agent', 'list_roles' ) );
		$t['create_role'] = array( 'description' => '创建新的能力角色（自定义职责、行为风格、可用工具）。角色类型 role_type：chat 聊天型（默认，对话+工具完成任务）或 functional 功能性（绑定一个输出工具 bind_tool，AI 调用时直接执行该工具输出结果，不做对话，如生图角色绑 generate_image）。可选的独立模型配置 llm_provider/llm_base_url/llm_api_key/llm_model（不传则用全局模型），创建后即可用于多角色协作', 'risk' => 'low', 'params' => array( 'name' => array( 'type' => 'string', 'description' => '角色名称' ), 'description' => array( 'type' => 'string', 'description' => '职责描述' ), 'system_prompt' => array( 'type' => 'string', 'description' => '角色行为风格指令（可选，功能性角色可留空）' ), 'tools' => array( 'type' => 'array', 'description' => '允许使用的工具名列表，空表示全部（功能性角色用 bind_tool，无需配 tools）', 'items' => array( 'type' => 'string' ) ), 'role_type' => array( 'type' => 'string', 'description' => 'chat 聊天型 / functional 功能性（直接输出，不对话）' ), 'bind_tool' => array( 'type' => 'string', 'description' => '功能性角色绑定的输出工具名，如 generate_image（仅 role_type=functional 时使用）' ), 'llm_provider' => array( 'type' => 'string', 'description' => '独立模型服务商（可选）' ), 'llm_model' => array( 'type' => 'string', 'description' => '独立模型名（可选）' ), 'llm_api_key' => array( 'type' => 'string', 'description' => '独立模型 Key（可选）' ) ), 'required' => array( 'name', 'description' ), 'cb' => array( 'Tianma_Tools_Agent', 'create_role' ) );
		$t['update_role'] = array( 'description' => '更新角色信息（含独立模型配置：传 llm_provider/llm_model/llm_api_key 设置独立模型，传 clear_llm=true 清除独立模型回到全局；功能性角色可设置 role_type=functional 与 bind_tool）', 'risk' => 'low', 'params' => array( 'role_id' => array( 'type' => 'integer' ), 'name' => array( 'type' => 'string' ), 'description' => array( 'type' => 'string' ), 'system_prompt' => array( 'type' => 'string' ), 'tools' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ), 'role_type' => array( 'type' => 'string', 'description' => 'chat / functional' ), 'bind_tool' => array( 'type' => 'string', 'description' => '功能性角色绑定的输出工具名' ), 'status' => array( 'type' => 'string' ), 'llm_provider' => array( 'type' => 'string' ), 'llm_model' => array( 'type' => 'string' ), 'llm_api_key' => array( 'type' => 'string' ), 'clear_llm' => array( 'type' => 'boolean' ) ), 'required' => array( 'role_id' ), 'cb' => array( 'Tianma_Tools_Agent', 'update_role' ) );
		$t['delete_role'] = array( 'description' => '删除自定义角色（高危，内置角色不可删）', 'risk' => 'high', 'params' => array( 'role_id' => array( 'type' => 'integer' ) ), 'required' => array( 'role_id' ), 'cb' => array( 'Tianma_Tools_Agent', 'delete_role' ) );
		$t['list_skills'] = array( 'description' => '列出技能库中的全部技能（工具序列、来源、使用次数）', 'risk' => 'low', 'params' => array(), 'required' => array(), 'cb' => array( 'Tianma_Tools_Agent', 'list_skills' ) );
		$t['create_skill'] = array( 'description' => '把一套工具操作流程固化为新技能加入能力库（技能可在后续任务中复用）', 'risk' => 'low', 'params' => array( 'name' => array( 'type' => 'string', 'description' => '技能名称' ), 'description' => array( 'type' => 'string', 'description' => '技能说明与适用场景' ), 'tools' => array( 'type' => 'array', 'description' => '技能包含的工具序列', 'items' => array( 'type' => 'string' ) ), 'trigger' => array( 'type' => 'string', 'description' => '触发场景关键词（可选）' ) ), 'required' => array( 'name', 'description', 'tools' ), 'cb' => array( 'Tianma_Tools_Agent', 'create_skill' ) );
		$t['disable_skill'] = array( 'description' => '启用或停用技能库中的技能', 'risk' => 'low', 'params' => array( 'skill_id' => array( 'type' => 'integer' ), 'status' => array( 'type' => 'string', 'description' => 'active 或 disabled' ) ), 'required' => array( 'skill_id' ), 'cb' => array( 'Tianma_Tools_Agent', 'disable_skill' ) );
		$t['invoke_role'] = array( 'description' => '轻量调用一个角色直接执行任务（不走完整协作编排，快速出结果）。功能性角色（如生图助手）→ 直接执行绑定工具输出；聊天型角色 → 以该角色身份执行任务。参数：role（角色名，可用 list_roles 查看）、task（任务描述）。单角色任务用这个；多角色分工才用 start_collaboration', 'risk' => 'low', 'params' => array( 'role' => array( 'type' => 'string', 'description' => '角色名称' ), 'task' => array( 'type' => 'string', 'description' => '要该角色执行的任务描述' ) ), 'required' => array( 'role', 'task' ), 'cb' => array( 'Tianma_Tools_Agent', 'invoke_role' ) );
		$t['start_collaboration'] = array( 'description' => '发起多角色协作：将复杂任务拆分为多个角色阶段执行（高危，执行前会请求确认；批准后协作内部工具将直接执行）。参数：task（任务描述，可省略）、plan（角色分工数组，每项含 role 角色名与 objective 目标）；也可直接用 roles 传角色名列表（如 ["生图助手","内容运营"]）。仅当任务确实需要多个角色分工时才使用；单角色任务请用轻量的 invoke_role', 'risk' => 'high', 'params' => array( 'task' => array( 'type' => 'string', 'description' => '协作任务描述（可选，缺省用 plan 或 roles 推导）' ), 'plan' => array( 'type' => 'array', 'description' => '角色分工计划，每项含 role（角色名）与 objective（该角色的目标）', 'items' => array( 'type' => 'object' ) ), 'roles' => array( 'type' => 'array', 'description' => '参与协作的角色名列表（可选，与 plan 二选一，优先使用）', 'items' => array( 'type' => 'string' ) ) ), 'required' => array(), 'cb' => array( 'Tianma_Tools_Agent', 'start_collaboration' ) );

		/* ---------------- 定时任务 ---------------- */
		$t['schedule_list'] = array( 'description' => '列出全部定时任务（名称、周期、下次/上次执行时间、状态）', 'risk' => 'low', 'params' => array(), 'required' => array(), 'cb' => array( 'Tianma_Tools_Agent', 'schedule_list' ) );
		$t['schedule_create'] = array( 'description' => '创建定时任务：让 Agent 在指定周期自动执行一段指令（如「每天备份数据库」「每周一发布一篇文章」），无需用户在线即可触发', 'risk' => 'low', 'params' => array( 'name' => array( 'type' => 'string', 'description' => '任务名称' ), 'prompt' => array( 'type' => 'string', 'description' => '到点自动执行的任务指令（自然语言）' ), 'interval_type' => array( 'type' => 'string', 'description' => '周期：hourly 每小时 / twicedaily 每12小时 / daily 每天 / weekly 每周 / monthly 每月 / minutes 每隔N分钟', 'enum' => array( 'hourly', 'twicedaily', 'daily', 'weekly', 'monthly', 'minutes' ) ), 'at_time' => array( 'type' => 'string', 'description' => 'daily/weekly/monthly 时的执行时刻 HH:MM，如 09:30' ), 'day_of_week' => array( 'type' => 'integer', 'description' => 'weekly 时的星期：0=周日 1=周一 … 6=周六' ), 'interval_minutes' => array( 'type' => 'integer', 'description' => 'interval_type=minutes 时的间隔分钟数' ), 'auto_high' => array( 'type' => 'boolean', 'description' => '是否允许定时执行时自动执行危险操作（删除/覆盖等），默认 false 遇到危险操作自动跳过' ) ), 'required' => array( 'name', 'prompt' ), 'cb' => array( 'Tianma_Tools_Agent', 'schedule_create' ) );
		$t['schedule_update'] = array( 'description' => '更新定时任务（改周期/时间/指令/暂停或恢复），传入要修改的字段即可', 'risk' => 'low', 'params' => array( 'schedule_id' => array( 'type' => 'integer', 'description' => '任务 ID' ), 'name' => array( 'type' => 'string' ), 'prompt' => array( 'type' => 'string' ), 'interval_type' => array( 'type' => 'string', 'enum' => array( 'hourly', 'twicedaily', 'daily', 'weekly', 'monthly', 'minutes' ) ), 'at_time' => array( 'type' => 'string' ), 'day_of_week' => array( 'type' => 'integer' ), 'interval_minutes' => array( 'type' => 'integer' ), 'auto_high' => array( 'type' => 'boolean' ), 'status' => array( 'type' => 'string', 'enum' => array( 'active', 'paused' ) ) ), 'required' => array( 'schedule_id' ), 'cb' => array( 'Tianma_Tools_Agent', 'schedule_update' ) );
		$t['schedule_delete'] = array( 'description' => '删除定时任务（高危）', 'risk' => 'high', 'params' => array( 'schedule_id' => array( 'type' => 'integer' ) ), 'required' => array( 'schedule_id' ), 'cb' => array( 'Tianma_Tools_Agent', 'schedule_delete' ) );
		$t['schedule_run_now'] = array( 'description' => '立即手动执行一次定时任务（等价于模拟到点触发，会真实执行任务指令）', 'risk' => 'low', 'params' => array( 'schedule_id' => array( 'type' => 'integer' ) ), 'required' => array( 'schedule_id' ), 'cb' => array( 'Tianma_Tools_Agent', 'schedule_run_now' ) );

		/* ---------------- 工作日志（跨对话持久记录） ---------------- */
		$t['worklog_read'] = array( 'description' => '查看工作日志（跨对话持久记录插件全程的关键进展，按天保存）。不带 day 参数时返回最近 7 天日志；用户问「今天做了什么 / 最近进展 / 上次任务」时先用它。', 'risk' => 'low', 'params' => array( 'day' => array( 'type' => 'string', 'description' => '日期 YYYY-MM-DD，不传则返回最近 7 天' ), 'days' => array( 'type' => 'integer', 'description' => '返回最近 N 天（仅当 day 为空时生效，默认 7）' ) ), 'required' => array(), 'cb' => array( 'Tianma_Tools_Tianma', 'worklog_read' ) );
		$t['worklog_append'] = array( 'description' => '在工作日志中追加一段记录（自动带时间戳）。用于记录重要结论、决策、待办、关键进展；任务完成后系统会自动记录，本工具用于补充说明。day 不传则记到今天。', 'risk' => 'low', 'params' => array( 'content' => array( 'type' => 'string', 'description' => '要追加的日志内容（支持多行）' ), 'day' => array( 'type' => 'string', 'description' => '日期 YYYY-MM-DD（可选，默认今天）' ) ), 'required' => array( 'content' ), 'cb' => array( 'Tianma_Tools_Tianma', 'worklog_append' ) );
		$t['worklog_update'] = array( 'description' => '整体重写某天的工作日志（覆盖当天全部内容）。修改日志时用：先 worklog_read 查看原文，再整体重写；需保留的历史行要原样带回。', 'risk' => 'low', 'params' => array( 'day' => array( 'type' => 'string', 'description' => '日期 YYYY-MM-DD' ), 'content' => array( 'type' => 'string', 'description' => '重写后的完整日志内容（多行）' ) ), 'required' => array( 'day', 'content' ), 'cb' => array( 'Tianma_Tools_Tianma', 'worklog_update' ) );
		$t['worklog_delete'] = array( 'description' => '删除某天的工作日志（高危，不可恢复）', 'risk' => 'high', 'params' => array( 'day' => array( 'type' => 'string', 'description' => '日期 YYYY-MM-DD' ) ), 'required' => array( 'day' ), 'cb' => array( 'Tianma_Tools_Tianma', 'worklog_delete' ) );

		/* ---------------- 插件自管理（天马自身能力） ---------------- */
		$t['llm_info'] = array( 'description' => '查看天马当前使用的模型配置（服务商、模型、API 地址、是否配 Key、嵌入模型、确认策略、记忆开关等）', 'risk' => 'low', 'params' => array(), 'required' => array(), 'cb' => array( 'Tianma_Tools_Tianma', 'llm_info' ) );
		$t['memory_list'] = array( 'description' => '查看天马记忆库（自学习沉淀的经验：标题、内容预览、权重、命中次数）', 'risk' => 'low', 'params' => array(), 'required' => array(), 'cb' => array( 'Tianma_Tools_Tianma', 'memory_list' ) );
		$t['memory_clear'] = array( 'description' => '清空天马全部记忆（高危，不可恢复；清空后自学习经验从零积累）', 'risk' => 'high', 'params' => array(), 'required' => array(), 'cb' => array( 'Tianma_Tools_Tianma', 'memory_clear' ) );
		$t['audit_log'] = array( 'description' => '查看天马最近的操作审计日志（所有工具调用的记录：操作、参数、结果、时间）', 'risk' => 'low', 'params' => array( 'limit' => array( 'type' => 'integer', 'description' => '条数，默认 30，最大 100' ) ), 'required' => array(), 'cb' => array( 'Tianma_Tools_Tianma', 'audit_log' ) );
		$t['usage_stats'] = array( 'description' => '查看 Token 用量统计（累计/今日消耗与调用次数）', 'risk' => 'low', 'params' => array( 'conversation_id' => array( 'type' => 'integer', 'description' => '可选，指定会话的用量' ) ), 'required' => array(), 'cb' => array( 'Tianma_Tools_Tianma', 'usage_stats' ) );
		$t['llm_switch'] = array( 'description' => '切换全局模型（服务商/模型，Key 自动保留）。传 provider 时会自动带出该服务商预设的 API 地址与默认模型；也可单独传 base_url/model/api_key', 'risk' => 'low', 'params' => array( 'provider' => array( 'type' => 'string', 'description' => '服务商：deepseek/zhipu/qwen/hunyuan/kimi/ernie/openai/gemini/claude/custom' ), 'base_url' => array( 'type' => 'string', 'description' => 'API 地址（可选）' ), 'model' => array( 'type' => 'string', 'description' => '模型名（可选）' ), 'api_key' => array( 'type' => 'string', 'description' => 'API Key（可选，不传则保留原 Key）' ) ), 'required' => array(), 'cb' => array( 'Tianma_Tools_Tianma', 'llm_switch' ) );
		$t['delete_skill'] = array( 'description' => '从能力库永久删除一个技能（高危）', 'risk' => 'high', 'params' => array( 'skill_id' => array( 'type' => 'integer' ) ), 'required' => array( 'skill_id' ), 'cb' => array( 'Tianma_Tools_Tianma', 'delete_skill' ) );
		$t['create_tool'] = array( 'description' => '【能力自我进化】当你发现缺少完成任务所需的能力时，用本工具编写 PHP 实现代码，动态注册一个新工具（立即可用）。php_code 是一个函数体：接收 $args 数组，返回 array(ok, message, data)。需先向用户说明方案并获同意（本工具为高危操作）', 'risk' => 'high', 'params' => array( 'name' => array( 'type' => 'string', 'description' => '工具名（小写字母开头，3-50位，如 weather_query）' ), 'description' => array( 'type' => 'string', 'description' => '工具描述（模型何时调用它）' ), 'php_code' => array( 'type' => 'string', 'description' => 'PHP 函数体实现，示例：\$url=\$args[\"city\"]??\"北京\"; return array(\"ok\"=>true,\"message\"=>\"天气：晴 25°C\",\"data\"=>array(\"city\"=>\$url));' ), 'params' => array( 'type' => 'object', 'description' => '参数 schema（OpenAI 格式 properties，如 {"city":{"type":"string","description":"城市"}}）' ), 'required' => array( 'type' => 'array', 'description' => '必填参数名列表（可选）', 'items' => array( 'type' => 'string' ) ), 'risk' => array( 'type' => 'string', 'enum' => array( 'low', 'high' ) ) ), 'required' => array( 'name', 'description', 'php_code' ), 'cb' => array( 'Tianma_Tools_Tianma', 'create_tool' ) );
		$t['list_tools'] = array( 'description' => '查看 AI 自建的自定义工具列表', 'risk' => 'low', 'params' => array(), 'required' => array(), 'cb' => array( 'Tianma_Tools_Tianma', 'list_tools' ) );
		$t['delete_tool'] = array( 'description' => '删除一个 AI 自建的自定义工具（高危）', 'risk' => 'high', 'params' => array( 'name' => array( 'type' => 'string' ) ), 'required' => array( 'name' ), 'cb' => array( 'Tianma_Tools_Tianma', 'delete_tool' ) );

		/* ---------------- AI 自建自定义工具（能力自我进化） ---------------- */
		self::load_custom_tools( $t );

		self::$registry = $t;
	}

	/** 加载 AI 通过 create_tool 自建的自定义工具（tianma_custom_tools 表） */
	private static function load_custom_tools( &$t ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}tianma_custom_tools WHERE status = 'active' ORDER BY id ASC"
		);
		if ( ! $rows ) {
			return;
		}
		foreach ( $rows as $ct ) {
			$params = json_decode( (string) $ct->params, true );
			$params = is_array( $params ) ? $params : array();
			$required = isset( $params['_required'] ) && is_array( $params['_required'] ) ? $params['_required'] : array();
			unset( $params['_required'] );
			if ( empty( $params ) ) {
				$params = (object) array();
			}
			try {
				$fn = eval( 'return function($args){' . (string) $ct->php_code . '};' );
				if ( $fn instanceof Closure ) {
					$t[ $ct->name ] = array(
						'description' => $ct->description,
						'risk'        => in_array( $ct->risk, array( 'low', 'high' ), true ) ? $ct->risk : 'low',
						'params'      => $params,
						'required'    => $required,
						'cb'          => $fn,
						'custom'      => true,
					);
				}
			} catch ( Throwable $e ) {
				// 跳过编译失败的坏工具
				continue;
			}
		}
	}

	/** 重置注册表（create_tool 后调用，强制下次 init 重新加载） */
	public static function reset() {
		self::$registry = null;
	}

	/** 获取工具 Schema 列表（传给 LLM） */
	public static function schemas() {
		self::init();
		$out = array();
		foreach ( self::$registry as $name => $def ) {
			$props = $def['params'];
			if ( empty( $props ) ) {
				$props = (object) array();
			}
			$out[] = array(
				'type' => 'function',
				'function' => array(
					'name'        => $name,
					'description' => $def['description'] . ( 'high' === $def['risk'] ? '【危险操作，执行前会请求用户确认】' : '' ),
					'parameters'  => array(
						'type'       => 'object',
						'properties' => $props,
						'required'   => $def['required'],
					),
				),
			);
		}
		return $out;
	}

	/** 工具风险等级 */
	public static function risk( $name ) {
		self::init();
		return isset( self::$registry[ $name ]['risk'] ) ? self::$registry[ $name ]['risk'] : 'low';
	}

	/** 是否只读（查询类）工具：无副作用，允许重复调用，不参与熔断 */
	public static function is_read_only( $name ) {
		$read = array(
			'get_site_info', 'get_current_time', 'list_posts', 'get_post', 'list_pages', 'list_media',
			'list_categories', 'get_settings', 'get_error_log', 'plugin_list', 'theme_list',
			'list_roles', 'list_skills', 'user_list', 'file_list', 'file_read',
			'list_options', 'get_option', 'read_only_db_query',
			'llm_info', 'memory_list', 'audit_log', 'usage_stats', 'schedule_list',
			'worklog_read',
		);
		return in_array( $name, $read, true );
	}

	/** 执行工具。返回 array( ok, message, data ) */
	public static function execute( $name, $args ) {
		self::init();
		if ( ! isset( self::$registry[ $name ] ) ) {
			return array( 'ok' => false, 'message' => '未知工具：' . $name );
		}
		$def   = self::$registry[ $name ];
		$args  = is_array( $args ) ? $args : array();
		$args  = self::normalize_args( $name, $def, $args );

		// 必填参数校验（宽容：数组/数字/布尔视为有效，仅拦截空字符串与 null）
		foreach ( $def['required'] as $req ) {
			if ( ! array_key_exists( $req, $args ) ) {
				return array( 'ok' => false, 'message' => "缺少必填参数：{$req}" . self::arg_hint( $name, $req ) );
			}
			$val = $args[ $req ];
			if ( is_string( $val ) && '' === trim( $val ) ) {
				return array( 'ok' => false, 'message' => "必填参数「{$req}」不能为空" );
			}
			if ( null === $val ) {
				return array( 'ok' => false, 'message' => "必填参数：{$req}" );
			}
		}

		try {
			$res = call_user_func( $def['cb'], $args );
			return is_array( $res ) ? $res : array( 'ok' => true, 'message' => (string) $res );
		} catch ( Throwable $e ) {
			return array( 'ok' => false, 'message' => '工具执行异常：' . $e->getMessage() );
		}
	}

	/**
	 * 参数键名归一：容忍模型的常见键名偏差。
	 * - xxx_id 类必填参数：兼容 id / xxx / xxxID 写法（如 role → role_id）
	 * - 键名拼写错误：levenshtein ≤ 2 的键自动对齐到必填参数
	 */
	private static function normalize_args( $name, $def, $args ) {
		// 1) 已知别名映射
		$alias = array(
			'role'      => 'role_id',
			'roleid'    => 'role_id',
			'skill'     => 'skill_id',
			'skillid'   => 'skill_id',
			'schedule'  => 'schedule_id',
			'scheduleid'=> 'schedule_id',
			'post'      => 'post_id',
			'postid'    => 'post_id',
			'page'      => 'page_id',
			'pageid'    => 'page_id',
		);
		foreach ( $alias as $from => $to ) {
			if ( isset( $args[ $from ] ) && ! isset( $args[ $to ] ) ) {
				$args[ $to ] = $args[ $from ];
			}
		}

		// 通用 id → <工具主体>_id（如 update_role 收到 id 时视为 role_id）
		foreach ( $def['required'] as $req ) {
			if ( preg_match( '/^(.+)_id$/', $req, $m ) && isset( $args['id'] ) && ! isset( $args[ $req ] ) ) {
				$args[ $req ] = $args['id'];
			}
		}

		// 2) 必填参数缺失时，用编辑距离模糊匹配补位（容忍 llmvider→llm_provider 这类拼错）
		foreach ( $def['required'] as $req ) {
			if ( isset( $args[ $req ] ) ) {
				continue;
			}
			$best_key = null;
			$best_d   = 3; // 距离阈值
			foreach ( array_keys( $args ) as $k ) {
				if ( ! is_string( $k ) || '' === $k ) {
					continue;
				}
				$d = levenshtein( strtolower( $k ), strtolower( $req ) );
				if ( $d < $best_d ) {
					$best_d   = $d;
					$best_key = $k;
				}
			}
			if ( $best_key ) {
				$args[ $req ] = $args[ $best_key ];
			}
		}

		return $args;
	}

	/** 必填参数缺失时的用法提示 */
	private static function arg_hint( $name, $req ) {
		switch ( $req ) {
			case 'role_id':
				return '。请先用 list_roles 查看角色 ID，再传 role_id（数字）';
			case 'skill_id':
				return '。请先用 list_skills 查看技能 ID，再传 skill_id（数字）';
			case 'schedule_id':
				return '。请先用 schedule_list 查看任务 ID，再传 schedule_id（数字）';
			case 'post_id':
			case 'page_id':
				return '。请先用 list_posts/list_pages 查看 ID，再传 ' . $req . '（数字）';
			default:
				return '';
		}
	}

	/** 工具名清单（供前端展示能力） */
	public static function names() {
		self::init();
		return array_keys( self::$registry );
	}

	/* ---------------------------------------------------------------------
	 * 工具分组：按需加载，降低每轮 prompt token 开销
	 * ------------------------------------------------------------------- */

	/** 默认注入的核心工具（通用查询类，无需加载组即可使用） */
	public static function core_names() {
		return array(
			'get_site_info', 'get_current_time', 'list_posts', 'get_post', 'list_pages', 'list_media',
			'list_categories', 'get_settings', 'get_error_log', 'plugin_list',
			'theme_list', 			'list_roles', 'list_skills', 'user_list',
			'file_list', 'file_read', 'list_options', 'get_option',
			'llm_info', 'worklog_read', 'worklog_append',
		);
	}

	/** 专业工具组定义 */
	public static function groups() {
		return array(
			'content' => array(
				'label' => '内容管理',
				'tools' => array( 'create_post', 'update_post', 'delete_post', 'create_page', 'update_page', 'delete_page', 'upload_media', 'create_category', 'generate_image' ),
			),
			'file' => array(
				'label' => '文件与代码',
				'tools' => array( 'file_write', 'file_delete', 'file_rename', 'file_create_dir', 'validate_php' ),
			),
			'plugin' => array(
				'label' => '主题与插件',
				'tools' => array( 'plugin_activate', 'plugin_deactivate', 'plugin_delete', 'plugin_install', 'theme_activate', 'theme_delete', 'create_plugin_skel', 'create_theme_skel' ),
			),
			'system' => array(
				'label' => '系统与维护',
				'tools' => array( 'update_settings', 'user_create', 'site_backup', 'clear_cache', 'wp_update_check', 'read_only_db_query', 'set_option' ),
			),
			'agent' => array(
				'label' => '角色技能与协作',
				'tools' => array( 'create_role', 'update_role', 'delete_role', 'invoke_role', 'create_skill', 'disable_skill', 'start_collaboration', 'schedule_list', 'schedule_create', 'schedule_update', 'schedule_delete', 'schedule_run_now' ),
			),
			'tianma' => array(
				'label' => '插件自管理',
				'tools' => array( 'memory_list', 'memory_clear', 'audit_log', 'usage_stats', 'llm_switch', 'delete_skill', 'create_tool', 'list_tools', 'delete_tool', 'worklog_update', 'worklog_delete' ),
			),
		);
	}

	public static function group_names( $group ) {
		$g = self::groups();
		return isset( $g[ $group ]['tools'] ) ? $g[ $group ]['tools'] : array();
	}

	public static function group_label( $group ) {
		$g = self::groups();
		return isset( $g[ $group ]['label'] ) ? $g[ $group ]['label'] : $group;
	}

	public static function valid_group( $group ) {
		return isset( self::groups()[ $group ] );
	}

	/** 组切换工具的 Schema（供模型声明加载某组） */
	public static function group_use_schema() {
		return array(
			'type' => 'function',
			'function' => array(
				'name'        => 'use_tool_group',
				'description' => '加载专业工具组，加载后即可使用该组内的专业工具。可选组：content（内容管理：创建/编辑/删除文章页面、上传媒体等）、file（文件与代码：写入/删除/重命名文件、PHP语法检查等）、plugin（主题与插件：启用/停用/删除/安装、创建骨架等）、system（系统与维护：改设置、建用户、备份、清缓存、SQL查询等）、agent（角色技能与协作：创建角色/技能、多角色协作、创建定时任务等）、tianma（插件自管理：查看/清空记忆库、审计日志、Token用量、切换模型、删除技能、重写/删除工作日志等）。',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'group' => array( 'type' => 'string', 'enum' => array( 'content', 'file', 'plugin', 'system', 'agent' ), 'description' => '要加载的工具组' ),
					),
					'required'   => array( 'group' ),
				),
			),
		);
	}

	/** 按工具名列表构建 Schema（不存在的名字自动跳过） */
	public static function schemas_for_names( $names ) {
		self::init();
		$out = array();
		foreach ( (array) $names as $name ) {
			if ( ! isset( self::$registry[ $name ] ) ) {
				continue;
			}
			$def = self::$registry[ $name ];
			$props = $def['params'];
			if ( empty( $props ) ) {
				$props = (object) array();
			}
			$out[] = array(
				'type' => 'function',
				'function' => array(
					'name'        => $name,
					'description' => $def['description'] . ( 'high' === $def['risk'] ? '【危险操作，执行前会请求用户确认】' : '' ),
					'parameters'  => array(
						'type'       => 'object',
						'properties' => $props,
						'required'   => $def['required'],
					),
				),
			);
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * 下载 URL 安全校验（SSRF 防护）
	 * ------------------------------------------------------------------- */

	public static function validate_download_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return 'URL 无效（仅支持 http/https）';
		}
		$host = strtolower( rtrim( (string) parse_url( $url, PHP_URL_HOST ), '.' ) );
		if ( '' === $host ) {
			return 'URL 无效';
		}
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return '不允许下载本机/回环地址';
		}
		// 解析 IP，拦截内网与保留地址
		$ip = gethostbyname( $host );
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) && false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return '不允许下载内网或保留地址（' . $host . ' → ' . $ip . '）';
		}
		return true;
	}

	/* ---------------------------------------------------------------------
	 * 安全工具：路径校验
	 * ------------------------------------------------------------------- */

	/**
	 * 宽松路径解析（只读场景：file_list / file_read）。
	 * 相对路径基于站点根目录；绝对路径（含其他盘符/系统目录）直接使用，
	 * 不限制在站点内——允许 Agent 读取任意目录与文件。
	 */
	public static function resolve_read_path( $path ) {
		$path = trim( (string) $path );
		if ( '' === $path ) {
			return false;
		}
		if ( self::is_abs_path( $path ) ) {
			return wp_normalize_path( $path );
		}
		return wp_normalize_path( ABSPATH . $path );
	}

	public static function resolve_safe_path( $path ) {
		$root = wp_normalize_path( ABSPATH );
		$path = trim( (string) $path );

		if ( '' === $path ) {
			return false;
		}

		if ( ! self::is_abs_path( $path ) ) {
			$path = $root . $path;
		}
		$path = wp_normalize_path( $path );

		$real = realpath( $path );
		if ( false === $real ) {
			// 目标可能尚不存在：用其已存在的最近父目录解析
			$dir = realpath( dirname( $path ) );
			if ( false === $dir ) {
				return false;
			}
			$real = wp_normalize_path( $dir ) . '/' . basename( $path );
		} else {
			$real = wp_normalize_path( $real );
		}

		$root_norm = rtrim( strtolower( $root ), '/' );
		$real_norm = strtolower( $real );

		if ( $real_norm === $root_norm ) {
			return $real;
		}
		if ( 0 === strpos( $real_norm, $root_norm . '/' ) ) {
			return $real;
		}
		return false;
	}

	private static function is_abs_path( $path ) {
		if ( '/' === $path[0] ) {
			return true;
		}
		if ( preg_match( '#^[A-Za-z]:[\\\\/]#', $path ) ) {
			return true;
		}
		return false;
	}
}
