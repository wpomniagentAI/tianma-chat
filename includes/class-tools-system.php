<?php
/**
 * 系统与维护工具：设置 / 用户 / 备份 / 缓存 / 更新 / 日志 / 只读查询
 *
 * @package Tianma
 */

defined( 'ABSPATH' ) || exit;

class Tianma_Tools_System {

	public static function get_current_time( $args ) {
		$dt = current_datetime();
		$weekday_cn = array( '周日', '周一', '周二', '周三', '周四', '周五', '周六' );
		$gmt_offset = (float) get_option( 'gmt_offset', 0 );
		$tz_name = $dt->getTimezone() ? $dt->getTimezone()->getName() : ( 'UTC' . ( $gmt_offset >= 0 ? '+' : '' ) . $gmt_offset );
		return array(
			'ok' => true,
			'message' => '当前时间：' . $dt->format( 'Y-m-d H:i:s' ) . '（站点时区 ' . $tz_name . '，' . $weekday_cn[ (int) $dt->format( 'w' ) ] . '）',
			'data' => array(
				'datetime'  => $dt->format( 'Y-m-d H:i:s' ),
				'date'      => $dt->format( 'Y-m-d' ),
				'time'      => $dt->format( 'H:i:s' ),
				'weekday'   => $dt->format( 'l' ),
				'weekday_cn' => $weekday_cn[ (int) $dt->format( 'w' ) ],
				'timestamp' => $dt->getTimestamp(),
				'timezone'  => $tz_name,
				'gmt_offset_hours' => $gmt_offset,
			),
		);
	}

	public static function get_settings( $args ) {
		global $wpdb;
		return array(
			'ok' => true,
			'message' => '站点设置如下',
			'data' => array(
				'站点标题'   => get_option( 'blogname' ),
				'副标题'     => get_option( 'blogdescription' ),
				'站点地址'   => home_url(),
				'管理员邮箱' => get_option( 'admin_email' ),
				'语言'       => get_locale(),
				'时区'       => get_option( 'timezone_string' ) ?: 'UTC+' . (float) get_option( 'gmt_offset', 0 ),
				'永久链接结构' => get_option( 'permalink_structure' ) ?: '（朴素链接）',
				'每页文章数'  => get_option( 'posts_per_page' ),
				'数据库名'   => $wpdb->dbname,
				'数据表数量' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()' ),
			),
		);
	}

	public static function update_settings( $args ) {
		$key   = sanitize_key( $args['key'] );
		$value = sanitize_text_field( $args['value'] );

		$allowed = array(
			'blogname'         => '站点标题',
			'blogdescription'  => '副标题',
			'timezone_string'  => '时区',
			'posts_per_page'   => '每页文章数',
		);
		if ( ! isset( $allowed[ $key ] ) ) {
			return array( 'ok' => false, 'message' => '不支持的设置项（允许：' . implode( ', ', array_keys( $allowed ) ) . '）' );
		}

		update_option( $key, $value );
		return array( 'ok' => true, 'message' => '已更新「' . $allowed[ $key ] . '」为：' . $value );
	}

	public static function user_list( $args ) {
		$number = isset( $args['number'] ) ? (int) $args['number'] : 20;
		$number = min( 100, max( 1, $number ) );

		$query = array( 'number' => $number, 'orderby' => 'ID', 'order' => 'ASC' );
		if ( ! empty( $args['role'] ) ) {
			$query['role'] = sanitize_key( $args['role'] );
		}
		$users = get_users( $query );
		$items = array();
		foreach ( $users as $u ) {
			$items[] = array(
				'ID'       => $u->ID,
				'用户名'   => $u->user_login,
				'显示名'   => $u->display_name,
				'邮箱'     => $u->user_email,
				'角色'     => implode( ', ', $u->roles ),
				'文章数'   => (int) count_user_posts( $u->ID ),
			);
		}
		return array( 'ok' => true, 'message' => '共 ' . count( $items ) . ' 个用户', 'data' => $items );
	}

	public static function user_create( $args ) {
		$username = sanitize_user( $args['username'], true );
		$email    = sanitize_email( $args['email'] );
		if ( '' === $username || '' === $email ) {
			return array( 'ok' => false, 'message' => '用户名与邮箱必填' );
		}
		if ( username_exists( $username ) || email_exists( $email ) ) {
			return array( 'ok' => false, 'message' => '用户名或邮箱已被占用' );
		}

		$password = isset( $args['password'] ) && '' !== $args['password'] ? $args['password'] : wp_generate_password( 16, true );
		$role     = isset( $args['role'] ) && in_array( $args['role'], array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ), true ) ? $args['role'] : 'subscriber';

		$user_id = wp_insert_user( array(
			'user_login' => $username,
			'user_email' => $email,
			'user_pass'  => $password,
			'role'       => $role,
			'display_name' => $username,
		) );
		if ( is_wp_error( $user_id ) ) {
			return array( 'ok' => false, 'message' => '创建失败：' . $user_id->get_error_message() );
		}
		// 出于安全考虑，不向模型返回明文密码（用户可通过后台「用户」页面管理密码）
		return array(
			'ok' => true,
			'message' => '用户「' . $username . '」创建成功（角色：' . $role . '，邮箱：' . $email . '）。密码已设置，请到后台「用户」页面为告知或重置密码。',
			'data' => array( 'user_id' => $user_id ),
		);
	}

	public static function site_backup( $args ) {
		global $wpdb;
		$backup_dir = wp_upload_dir()['basedir'] . '/tianma-backups/db';
		wp_mkdir_p( $backup_dir );
		$stamp = date( 'Ymd-His' );
		$note  = isset( $args['note'] ) ? sanitize_text_field( $args['note'] ) : '';

		$file = $backup_dir . '/backup-' . $stamp . '.sql';
		$fp   = fopen( $file, 'w' );
		if ( ! $fp ) {
			return array( 'ok' => false, 'message' => '无法创建备份文件' );
		}

		fwrite( $fp, "-- Tianma backup {$stamp} {$note}\n-- Host: {$wpdb->dbhost} DB: {$wpdb->dbname}\n\n" );

		$tables = $wpdb->get_col( "SHOW TABLES" );
		foreach ( $tables as $table ) {
			$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
			fwrite( $fp, "DROP TABLE IF EXISTS `{$table}`;\n" . ( isset( $create[1] ) ? $create[1] : '' ) . ";\n\n" );

			$rows = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A );
			foreach ( $rows as $row ) {
				$vals = array();
				foreach ( $row as $v ) {
					$vals[] = null === $v ? 'NULL' : "'" . esc_sql( $v ) . "'";
				}
				fwrite( $fp, "INSERT INTO `{$table}` VALUES (" . implode( ',', $vals ) . ");\n" );
			}
			fwrite( $fp, "\n" );
		}
		fclose( $fp );

		$size = size_format( filesize( $file ) );
		return array( 'ok' => true, 'message' => '数据库备份完成：' . $file . '（' . $size . '）', 'data' => array( '文件' => $file ) );
	}

	public static function clear_cache( $args ) {
		wp_cache_flush();
		global $wpdb;
		$deleted = $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'" );
		wp_cache_flush();
		return array( 'ok' => true, 'message' => '缓存已清理（删除瞬态 ' . (int) $deleted . ' 条）' );
	}

	public static function wp_update_check( $args ) {
		// 强制刷新更新数据
		set_site_transient( 'update_core', null );
		set_site_transient( 'update_plugins', null );
		set_site_transient( 'update_themes', null );
		wp_version_check();
		wp_update_plugins();
		wp_update_themes();

		$out = array();

		$core = get_site_transient( 'update_core' );
		if ( ! empty( $core->updates ) ) {
			foreach ( $core->updates as $u ) {
				if ( 'upgrade' === $u->response ) {
					$out[] = '核心更新：当前 ' . get_bloginfo( 'version' ) . ' → ' . $u->current;
				}
			}
		}

		$plugins = get_site_transient( 'update_plugins' );
		if ( ! empty( $plugins->response ) ) {
			foreach ( $plugins->response as $file => $p ) {
				$out[] = '插件更新：' . $file . ' → ' . $p->new_version;
			}
		}

		$themes = get_site_transient( 'update_themes' );
		if ( ! empty( $themes->response ) ) {
			foreach ( $themes->response as $slug => $t ) {
				$out[] = '主题更新：' . $slug . ' → ' . $t['new_version'];
			}
		}

		if ( ! $out ) {
			$out[] = '所有组件均为最新版本，无需更新';
		}

		return array( 'ok' => true, 'message' => '更新检查完成', 'data' => $out );
	}

	public static function get_error_log( $args ) {
		$lines = isset( $args['lines'] ) ? (int) $args['lines'] : 20;
		$lines = min( 100, max( 1, $lines ) );

		$log_file = WP_CONTENT_DIR . '/debug.log';
		if ( ! file_exists( $log_file ) ) {
			return array( 'ok' => true, 'message' => '暂无调试日志（debug.log 不存在，可能未开启 WP_DEBUG_LOG）', 'data' => array() );
		}

		$content = file_get_contents( $log_file );
		$rows    = array_filter( explode( "\n", $content ) );
		$tail    = array_slice( array_values( $rows ), -$lines );

		return array( 'ok' => true, 'message' => 'debug.log 末尾 ' . count( $tail ) . ' 行', 'data' => $tail );
	}

	public static function read_only_db_query( $args ) {
		global $wpdb;
		$query = trim( (string) $args['query'] );
		if ( '' === $query ) {
			return array( 'ok' => false, 'message' => '查询语句为空' );
		}
		// 只允许 SELECT / SHOW / DESCRIBE / EXPLAIN
		if ( ! preg_match( '#^(select|show|describe|explain)\b#i', $query ) ) {
			return array( 'ok' => false, 'message' => '仅允许只读查询（SELECT/SHOW/DESCRIBE/EXPLAIN）' );
		}

		$results = $wpdb->get_results( $query, ARRAY_A );
		if ( null === $results ) {
			return array( 'ok' => false, 'message' => '查询失败：' . $wpdb->last_error );
		}
		if ( ! $results ) {
			return array( 'ok' => true, 'message' => '查询完成，无结果', 'data' => array() );
		}

		// 限制返回行数
		$results = array_slice( $results, 0, 100 );
		return array( 'ok' => true, 'message' => '查询完成，返回 ' . count( $results ) . ' 行', 'data' => $results );
	}

	/* ---------------------------------------------------------------------
	 * 通用选项管理：可配置任意插件/站点设置（绝大多数插件配置存于 wp_options）
	 * ------------------------------------------------------------------- */

	public static function list_options( $args ) {
		global $wpdb;
		$search = isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT option_name, LENGTH(option_value) AS size, LEFT(option_value, 150) AS preview
				 FROM {$wpdb->options} WHERE option_name LIKE %s
				 ORDER BY option_name ASC LIMIT 60",
				$like
			) );
		} else {
			$rows = $wpdb->get_results(
				"SELECT option_name, LENGTH(option_value) AS size, LEFT(option_value, 150) AS preview
				 FROM {$wpdb->options} ORDER BY option_name ASC LIMIT 60"
			);
		}

		$items = array();
		foreach ( $rows as $r ) {
			$items[] = array(
				'选项名'  => $r->option_name,
				'大小'    => (int) $r->size . ' B',
				'预览'    => $r->preview,
			);
		}
		return array( 'ok' => true, 'message' => '找到 ' . count( $items ) . ' 个选项' . ( '' !== $search ? "（关键词：{$search}）" : '' ), 'data' => $items );
	}

	public static function get_option( $args ) {
		$name = sanitize_text_field( isset( $args['name'] ) ? $args['name'] : '' );
		if ( '' === $name ) {
			return array( 'ok' => false, 'message' => '缺少选项名' );
		}
		$value = get_option( $name );
		if ( false === $value ) {
			return array( 'ok' => false, 'message' => '选项不存在：' . $name );
		}
		return array(
			'ok' => true,
			'message' => '已读取选项：' . $name,
			'data' => array( '选项名' => $name, '值' => is_string( $value ) ? $value : wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) ),
		);
	}

	public static function set_option( $args ) {
		$name = sanitize_text_field( isset( $args['name'] ) ? $args['name'] : '' );
		$raw  = isset( $args['value'] ) ? (string) $args['value'] : '';
		if ( '' === $name ) {
			return array( 'ok' => false, 'message' => '缺少选项名' );
		}

		// 值解析：优先 JSON（支持数组/布尔/数字），否则按字符串
		$decoded = json_decode( $raw, true );
		if ( JSON_ERROR_NONE === json_last_error() ) {
			$value = $decoded;
		} else {
			$value = $raw;
		}

		// 覆盖前备份旧值
		$old = get_option( $name );
		if ( false !== $old ) {
			set_transient( 'tianma_opt_backup_' . md5( $name ), $old, DAY_IN_SECONDS );
		}

		update_option( $name, $value );
		return array(
			'ok' => true,
			'message' => '选项已更新：' . $name . ' = ' . ( is_string( $value ) ? mb_substr( $value, 0, 120 ) : wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) ),
			'data' => array( '选项名' => $name ),
		);
	}
}
