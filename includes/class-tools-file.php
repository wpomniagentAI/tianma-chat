<?php
/**
 * 文件与代码工具：读写 / 删除 / 重命名 / 语法检查
 * 所有路径必须位于站点根目录内（Tianma_Tools::resolve_safe_path 校验）
 *
 * @package Tianma
 */

defined( 'ABSPATH' ) || exit;

class Tianma_Tools_File {

	public static function file_list( $args ) {
		$raw  = isset( $args['path'] ) ? $args['path'] : '';
		$raw  = trim( (string) $raw );
		// 空参数 → 默认列出站点根目录（防模型空参死循环）
		if ( '' === $raw ) {
			$path = wp_normalize_path( ABSPATH );
		} else {
			$path = Tianma_Tools::resolve_read_path( $raw );
			if ( false === $path ) {
				return array( 'ok' => false, 'message' => '路径无效。请提供 path 参数，如 wp-content/plugins 或 C:/Users' );
			}
		}
		if ( ! is_dir( $path ) ) {
			return array( 'ok' => false, 'message' => '目录不存在：' . $path . '（请检查路径拼写，相对路径基于站点根目录）' );
		}

		$items   = array();
		$entries = @scandir( $path );
		if ( ! is_array( $entries ) ) {
			return array( 'ok' => false, 'message' => '目录读取失败（可能没有权限）：' . $path );
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$full  = $path . '/' . $entry;
			$items[] = array(
				'名称' => $entry,
				'类型' => is_dir( $full ) ? '目录' : '文件',
				'大小' => is_file( $full ) ? size_format( filesize( $full ) ) : '-',
			);
		}
		return array( 'ok' => true, 'message' => '目录共 ' . count( $items ) . ' 项', 'data' => $items );
	}

	public static function file_read( $args ) {
		$raw = isset( $args['path'] ) ? $args['path'] : '';
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return array( 'ok' => false, 'message' => '请提供要读取的文件 path，如 wp-config.php、wp-content/plugins/tianma/tianma.php，或绝对路径 C:/xxx/file.txt' );
		}
		$path = Tianma_Tools::resolve_read_path( $raw );
		if ( false === $path ) {
			return array( 'ok' => false, 'message' => '路径无效。请提供 path 参数，如 wp-content/plugins/tianma/tianma.php' );
		}
		if ( ! is_file( $path ) ) {
			return array( 'ok' => false, 'message' => '文件不存在：' . $path . '（可用 file_list 列出目录查看实际文件名，注意路径分段，如 wp-content/plugins/tianma/includes/class-settings.php）' );
		}
		if ( filesize( $path ) > 512 * 1024 ) {
			return array( 'ok' => false, 'message' => '文件超过 512KB，拒绝读取' );
		}
		$content = file_get_contents( $path );
		return array(
			'ok' => true,
			'message' => '已读取文件',
			'data' => array( 'path' => $path, 'content' => $content, '内容' => $content ),
		);
	}

	public static function file_write( $args ) {
		$path = Tianma_Tools::resolve_safe_path( isset( $args['path'] ) ? $args['path'] : '' );
		if ( false === $path ) {
			return array( 'ok' => false, 'message' => '路径非法或超出站点根目录' );
		}

		// 目标父目录必须存在
		if ( ! is_dir( dirname( $path ) ) ) {
			return array( 'ok' => false, 'message' => '父目录不存在：' . dirname( $path ) );
		}

		$mode    = isset( $args['mode'] ) && 'append' === $args['mode'] ? 'append' : 'write';
		$content = isset( $args['content'] ) ? $args['content'] : '';

		// 覆盖已有文件前先备份
		if ( 'write' === $mode && is_file( $path ) ) {
			$backup_dir = wp_upload_dir()['basedir'] . '/tianma-backups/files';
			wp_mkdir_p( $backup_dir );
			$stamp = date( 'Ymd-His' );
			copy( $path, $backup_dir . '/' . basename( $path ) . '.' . $stamp . '.bak' );
		}

		$flags = ( 'append' === $mode ) ? FILE_APPEND : 0;
		$ok    = file_put_contents( $path, $content, $flags );

		if ( false === $ok ) {
			return array( 'ok' => false, 'message' => '写入失败（检查文件权限）' );
		}

		// 返回路径与内容，供前端渲染「可编辑文件卡片」（可视化修改）
		$preview = mb_substr( $content, 0, 2000 );
		return array(
			'ok'      => true,
			'message' => '文件已' . ( 'append' === $mode ? '追加写入' : '写入' ) . '：' . $path . '（' . $ok . ' 字节）',
			'data'    => array(
				'path'    => $path,
				'content' => $content,
				'preview' => $preview,
				'mode'    => $mode,
			),
		);
	}

	public static function file_delete( $args ) {
		$path = Tianma_Tools::resolve_safe_path( isset( $args['path'] ) ? $args['path'] : '' );
		if ( false === $path ) {
			return array( 'ok' => false, 'message' => '路径非法或超出站点根目录' );
		}
		if ( ! is_file( $path ) ) {
			return array( 'ok' => false, 'message' => '文件不存在' );
		}
		// 删除前备份
		$backup_dir = wp_upload_dir()['basedir'] . '/tianma-backups/files';
		wp_mkdir_p( $backup_dir );
		$stamp = date( 'Ymd-His' );
		copy( $path, $backup_dir . '/' . basename( $path ) . '.' . $stamp . '.del.bak' );

		$ok = unlink( $path );
		if ( ! $ok ) {
			return array( 'ok' => false, 'message' => '删除失败（检查文件权限）' );
		}
		return array( 'ok' => true, 'message' => '文件已删除（原文件已备份至 tianma-backups/files/）' );
	}

	public static function file_rename( $args ) {
		$path    = Tianma_Tools::resolve_safe_path( isset( $args['path'] ) ? $args['path'] : '' );
		$new_name = isset( $args['new_name'] ) ? trim( $args['new_name'] ) : '';
		if ( false === $path ) {
			return array( 'ok' => false, 'message' => '路径非法或超出站点根目录' );
		}
		if ( '' === $new_name || preg_match( '#[/\\\\:]#', $new_name ) ) {
			return array( 'ok' => false, 'message' => '新文件名无效' );
		}
		if ( ! file_exists( $path ) ) {
			return array( 'ok' => false, 'message' => '文件不存在' );
		}

		$dest = dirname( $path ) . '/' . $new_name;
		if ( file_exists( $dest ) ) {
			return array( 'ok' => false, 'message' => '目标文件已存在' );
		}

		$ok = rename( $path, $dest );
		if ( ! $ok ) {
			return array( 'ok' => false, 'message' => '重命名失败' );
		}
		return array( 'ok' => true, 'message' => '已重命名为：' . $new_name );
	}

	public static function file_create_dir( $args ) {
		$path = Tianma_Tools::resolve_safe_path( isset( $args['path'] ) ? $args['path'] : '' );
		if ( false === $path ) {
			return array( 'ok' => false, 'message' => '路径非法或超出站点根目录' );
		}
		if ( is_dir( $path ) ) {
			return array( 'ok' => false, 'message' => '目录已存在' );
		}
		$ok = wp_mkdir_p( $path );
		if ( ! $ok ) {
			return array( 'ok' => false, 'message' => '创建目录失败' );
		}
		return array( 'ok' => true, 'message' => '目录已创建：' . $path );
	}

	public static function validate_php( $args ) {
		$path = Tianma_Tools::resolve_safe_path( isset( $args['path'] ) ? $args['path'] : '' );
		if ( false === $path ) {
			return array( 'ok' => false, 'message' => '路径非法或超出站点根目录' );
		}
		if ( ! is_file( $path ) ) {
			return array( 'ok' => false, 'message' => '文件不存在' );
		}

		$code = file_get_contents( $path );
		try {
			token_get_all( $code, TOKEN_PARSE );
			return array( 'ok' => true, 'message' => 'PHP 语法检查通过，无语法错误' );
		} catch ( ParseError $e ) {
			return array( 'ok' => false, 'message' => '语法错误：' . $e->getMessage() );
		}
	}
}
