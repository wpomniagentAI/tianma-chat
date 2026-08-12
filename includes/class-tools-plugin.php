<?php
/**
 * 主题与插件工具：安装 / 启用 / 停用 / 删除 / 创建骨架
 *
 * @package Tianma
 */

defined( 'ABSPATH' ) || exit;

class Tianma_Tools_Plugin {

	public static function plugin_list( $args ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		$active  = (array) get_option( 'active_plugins', array() );
		$status  = isset( $args['status'] ) ? $args['status'] : '';

		$items = array();
		foreach ( $plugins as $file => $info ) {
			$is_active = in_array( $file, $active, true ) || ( is_multisite() && in_array( $file, (array) get_site_option( 'active_sitewide_plugins', array() ), true ) );
			if ( 'active' === $status && ! $is_active ) {
				continue;
			}
			if ( 'inactive' === $status && $is_active ) {
				continue;
			}
			$items[] = array(
				'名称'    => $info['Name'],
				'slug'    => dirname( $file ),
				'状态'    => $is_active ? '已启用' : '已停用',
				'版本'    => $info['Version'],
				'文件'    => $file,
			);
		}
		return array( 'ok' => true, 'message' => '共 ' . count( $items ) . ' 个插件', 'data' => $items );
	}

	private static function plugin_file_from_slug( $slug ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		foreach ( $plugins as $file => $info ) {
			if ( dirname( $file ) === $slug ) {
				return $file;
			}
		}
		return null;
	}

	public static function plugin_activate( $args ) {
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$slug = sanitize_file_name( $args['slug'] );
		$file = self::plugin_file_from_slug( $slug );
		if ( null === $file ) {
			return array( 'ok' => false, 'message' => '插件不存在：' . $slug );
		}
		$res = activate_plugin( $file );
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'message' => '启用失败：' . $res->get_error_message() );
		}
		return array( 'ok' => true, 'message' => '插件「' . $slug . '」已启用' );
	}

	public static function plugin_deactivate( $args ) {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$slug = sanitize_file_name( $args['slug'] );
		$file = self::plugin_file_from_slug( $slug );
		if ( null === $file ) {
			return array( 'ok' => false, 'message' => '插件不存在：' . $slug );
		}
		deactivate_plugins( $file );
		return array( 'ok' => true, 'message' => '插件「' . $slug . '」已停用' );
	}

	public static function plugin_delete( $args ) {
		if ( ! function_exists( 'delete_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$slug = sanitize_file_name( $args['slug'] );
		$file = self::plugin_file_from_slug( $slug );
		if ( null === $file ) {
			return array( 'ok' => false, 'message' => '插件不存在：' . $slug );
		}
		// 先停用再删除
		deactivate_plugins( $file );
		$res = delete_plugins( array( $file ) );
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'message' => '删除失败：' . $res->get_error_message() );
		}
		return array( 'ok' => true, 'message' => '插件「' . $slug . '」已删除' );
	}

	public static function plugin_install( $args ) {
		$url = esc_url_raw( $args['url'] );
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return array( 'ok' => false, 'message' => 'zip 链接无效' );
		}

		// SSRF 防护：禁止下载本机/内网地址
		$safe = Tianma_Tools::validate_download_url( $url );
		if ( true !== $safe ) {
			return array( 'ok' => false, 'message' => $safe );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
		$res      = $upgrader->install( $url );

		if ( true !== $res ) {
			$msg = is_wp_error( $res ) ? $res->get_error_message() : '安装失败';
			return array( 'ok' => false, 'message' => $msg );
		}
		return array( 'ok' => true, 'message' => '插件已安装（可在「插件列表」中查看并启用）' );
	}

	public static function theme_list( $args ) {
		$themes = wp_get_themes();
		$items  = array();
		foreach ( $themes as $slug => $theme ) {
			$items[] = array(
				'名称'    => $theme->get( 'Name' ),
				'slug'    => $slug,
				'状态'    => ( get_template() === $slug ) ? '当前使用' : '未启用',
				'版本'    => $theme->get( 'Version' ),
			);
		}
		return array( 'ok' => true, 'message' => '共 ' . count( $items ) . ' 个主题', 'data' => $items );
	}

	public static function theme_activate( $args ) {
		$slug = sanitize_file_name( $args['slug'] );
		if ( ! wp_get_theme( $slug )->exists() ) {
			return array( 'ok' => false, 'message' => '主题不存在：' . $slug );
		}
		switch_theme( $slug );
		return array( 'ok' => true, 'message' => '主题已切换为「' . $slug . '」' );
	}

	public static function theme_delete( $args ) {
		$slug = sanitize_file_name( $args['slug'] );
		if ( get_template() === $slug || get_stylesheet() === $slug ) {
			return array( 'ok' => false, 'message' => '不能删除当前正在使用的主题' );
		}
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return array( 'ok' => false, 'message' => '主题不存在：' . $slug );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$dir = $theme->get_stylesheet_directory();

		// 备份后删除
		$backup_dir = wp_upload_dir()['basedir'] . '/tianma-backups/themes';
		wp_mkdir_p( $backup_dir );
		self::copy_dir( $dir, $backup_dir . '/' . $slug . '-' . date( 'Ymd-His' ) );

		$wp_filesystem = self::fs();
		$ok = $wp_filesystem && $wp_filesystem->delete( $dir, true );
		if ( ! $ok && ! self::rmdir_recursive( $dir ) ) {
			return array( 'ok' => false, 'message' => '删除失败（检查权限）' );
		}
		return array( 'ok' => true, 'message' => '主题「' . $slug . '」已删除（已备份）' );
	}

	private static function fs() {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		return $wp_filesystem;
	}

	private static function copy_dir( $src, $dst ) {
		if ( ! is_dir( $src ) ) {
			return;
		}
		@mkdir( $dst, 0755, true );
		foreach ( scandir( $src ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$s = $src . '/' . $entry;
			$d = $dst . '/' . $entry;
			if ( is_dir( $s ) ) {
				self::copy_dir( $s, $d );
			} else {
				copy( $s, $d );
			}
		}
	}

	private static function rmdir_recursive( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		$items = scandir( $dir );
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				self::rmdir_recursive( $path );
			} else {
				@unlink( $path );
			}
		}
		return @rmdir( $dir );
	}

	/* ---------------------------------------------------------------------
	 * 创建插件 / 主题骨架
	 * ------------------------------------------------------------------- */

	public static function create_plugin_skel( $args ) {
		$name = sanitize_text_field( $args['name'] );
		$slug = isset( $args['slug'] ) ? sanitize_file_name( $args['slug'] ) : '';
		if ( '' === $slug ) {
			$slug = sanitize_title( $name, 'my-plugin' );
		}
		$dir = WP_PLUGIN_DIR . '/' . $slug;
		if ( is_dir( $dir ) ) {
			return array( 'ok' => false, 'message' => '插件目录已存在：' . $slug );
		}
		wp_mkdir_p( $dir );

		$main = $slug . '.php';
		$code = "<?php\n/**\n * Plugin Name: {$name}\n * Description: 由天马智能体创建的插件骨架。\n * Version: 1.0.0\n * Author: Tianma\n */\n\ndefined( 'ABSPATH' ) || exit;\n\nadd_action( 'init', function () {\n\t// 插件初始化代码\n} );\n";
		file_put_contents( $dir . '/' . $main, $code );
		file_put_contents( $dir . '/readme.txt', "=== {$name} ===\nContributors: tianma\nTags: \nRequires at least: 6.0\nTested up to: 7.0\nStable tag: 1.0.0\n\n== Description ==\n由天马智能体创建的插件骨架。\n" );

		return array(
			'ok' => true,
			'message' => '插件骨架已创建：' . $slug,
			'data' => array(
				'目录'  => $dir,
				'主文件' => $dir . '/' . $main,
				'说明'  => '可在「天马智能体 → 对话」中让我读取、修改其中的代码',
			),
		);
	}

	public static function create_theme_skel( $args ) {
		$name = sanitize_text_field( $args['name'] );
		$slug = isset( $args['slug'] ) ? sanitize_file_name( $args['slug'] ) : '';
		if ( '' === $slug ) {
			$slug = sanitize_title( $name, 'my-theme' );
		}
		$dir = get_theme_root() . '/' . $slug;
		if ( is_dir( $dir ) ) {
			return array( 'ok' => false, 'message' => '主题目录已存在：' . $slug );
		}
		wp_mkdir_p( $dir );

		file_put_contents( $dir . '/style.css', "/*\nTheme Name: {$name}\nTheme URI: \nAuthor: Tianma\nDescription: 由天马智能体创建的主题骨架。\nVersion: 1.0.0\n*/\n" );
		file_put_contents( $dir . '/index.php', "<?php get_header(); ?>\n<main class=\"site-main\">\n\t<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>\n\t\t<article>\n\t\t\t<h2><a href=\"<?php the_permalink(); ?>\"><?php the_title(); ?></a></h2>\n\t\t\t<div><?php the_content(); ?></div>\n\t\t</article>\n\t<?php endwhile; endif; ?>\n</main>\n<?php get_footer(); ?>\n" );
		file_put_contents( $dir . '/functions.php', "<?php\n// {$name} 主题函数\n" );
		file_put_contents( $dir . '/header.php', "<!DOCTYPE html>\n<html <?php language_attributes(); ?>>\n<head>\n\t<meta charset=\"<?php bloginfo( 'charset' ); ?>\">\n\t<?php wp_head(); ?>\n</head>\n<body <?php body_class(); ?>>\n\t<header class=\"site-header\"><h1><a href=\"<?php echo esc_url( home_url() ); ?>\"><?php bloginfo( 'name' ); ?></a></h1></header>\n" );
		file_put_contents( $dir . '/footer.php', "\t<?php wp_footer(); ?>\n</body>\n</html>\n" );

		return array(
			'ok' => true,
			'message' => '主题骨架已创建：' . $slug . '（可稍后在「外观 → 主题」中启用）',
			'data' => array( '目录' => $dir ),
		);
	}
}
