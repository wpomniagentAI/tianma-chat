<?php
/**
 * 卸载时清理数据表
 *
 * @package Tianma
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$prefix = $wpdb->prefix;

$tables = array(
	'tianma_tasks', 'tianma_memories', 'tianma_feedback', 'tianma_confirmations',
	'tianma_audit', 'tianma_skills', 'tianma_roles', 'tianma_conversations',
	'tianma_messages', 'tianma_usage', 'tianma_schedules', 'tianma_custom_tools',
	'tianma_worklogs',
);
foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$prefix}{$table}" );
}

delete_option( 'tianma_settings' );
delete_option( 'tianma_db_version' );
