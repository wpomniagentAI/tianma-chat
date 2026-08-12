<?php
/**
 * 内容管理工具：文章 / 页面 / 媒体 / 分类
 *
 * @package Tianma
 */

defined( 'ABSPATH' ) || exit;

class Tianma_Tools_Content {

	public static function get_site_info( $args ) {
		$count_posts   = wp_count_posts();
		$count_users   = count_users();
		$theme         = wp_get_theme();
		$plugins       = get_plugins();

		return array(
			'ok' => true,
			'message' => '已获取站点信息',
			'data' => array(
				'站点名称'      => get_bloginfo( 'name' ),
				'站点地址'      => home_url(),
				'WordPress 版本' => get_bloginfo( 'version' ),
				'PHP 版本'     => PHP_VERSION,
				'当前主题'      => $theme->get( 'Name' ),
				'插件数量'      => count( $plugins ),
				'已发布文章数'   => isset( $count_posts->publish ) ? (int) $count_posts->publish : 0,
				'草稿数'        => isset( $count_posts->draft ) ? (int) $count_posts->draft : 0,
				'用户数'        => isset( $count_users['total_users'] ) ? $count_users['total_users'] : 0,
			),
		);
	}

	public static function list_posts( $args ) {
		$number = isset( $args['number'] ) ? (int) $args['number'] : 10;
		$number = min( 50, max( 1, $number ) );

		$query = array(
			'post_type'      => 'post',
			'post_status'    => isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'any',
			'posts_per_page' => $number,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( ! empty( $args['search'] ) ) {
			$query['s'] = sanitize_text_field( $args['search'] );
		}

		$posts = get_posts( $query );
		$items = array();
		foreach ( $posts as $p ) {
			$items[] = array(
				'ID'     => $p->ID,
				'标题'   => $p->post_title,
				'状态'   => $p->post_status,
				'日期'   => $p->post_date,
				'链接'   => get_permalink( $p->ID ),
			);
		}
		return array(
			'ok' => true,
			'message' => '共返回 ' . count( $items ) . ' 篇文章',
			'data' => $items,
		);
	}

	public static function get_post( $args ) {
		$post = get_post( (int) $args['post_id'] );
		if ( ! $post || 'post' !== $post->post_type ) {
			return array( 'ok' => false, 'message' => '文章不存在' );
		}
		return array(
			'ok' => true,
			'message' => '已获取文章详情',
			'data' => array(
				'ID'      => $post->ID,
				'标题'    => $post->post_title,
				'状态'    => $post->post_status,
				'作者'    => get_the_author_meta( 'display_name', $post->post_author ),
				'日期'    => $post->post_date,
				'分类'    => wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ),
				'标签'    => wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ),
				'内容'    => wp_strip_all_tags( $post->post_content ),
				'链接'    => get_permalink( $post->ID ),
			),
		);
	}

	public static function create_post( $args ) {
		$title = sanitize_text_field( $args['title'] );
		if ( '' === $title ) {
			return array( 'ok' => false, 'message' => '标题不能为空' );
		}

		$status = isset( $args['status'] ) && 'publish' === $args['status'] ? 'publish' : 'draft';

		$data = array(
			'post_title'   => $title,
			'post_content' => isset( $args['content'] ) ? wp_kses_post( $args['content'] ) : '',
			'post_status'  => $status,
			'post_type'    => 'post',
			'post_author'  => get_current_user_id(),
		);
		if ( ! empty( $args['category_id'] ) ) {
			$data['post_category'] = array( (int) $args['category_id'] );
		}
		if ( ! empty( $args['tags'] ) ) {
			$data['tags_input'] = sanitize_text_field( $args['tags'] );
		}

		$post_id = wp_insert_post( $data, true );
		if ( is_wp_error( $post_id ) ) {
			return array( 'ok' => false, 'message' => '创建失败：' . $post_id->get_error_message() );
		}

		return array(
			'ok' => true,
			'message' => '文章创建成功' . ( 'publish' === $status ? '（已发布）' : '（草稿）' ),
			'data' => array( 'post_id' => $post_id, '链接' => get_permalink( $post_id ) ),
		);
	}

	public static function update_post( $args ) {
		$post_id = (int) $args['post_id'];
		$post    = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return array( 'ok' => false, 'message' => '文章不存在' );
		}

		$data = array( 'ID' => $post_id );
		if ( isset( $args['title'] ) ) {
			$data['post_title'] = sanitize_text_field( $args['title'] );
		}
		if ( isset( $args['content'] ) ) {
			$data['post_content'] = wp_kses_post( $args['content'] );
		}
		if ( isset( $args['status'] ) && in_array( $args['status'], array( 'draft', 'publish', 'pending' ), true ) ) {
			$data['post_status'] = $args['status'];
		}

		$result = wp_update_post( $data, true );
		if ( is_wp_error( $result ) ) {
			return array( 'ok' => false, 'message' => '更新失败：' . $result->get_error_message() );
		}

		return array( 'ok' => true, 'message' => '文章 #' . $post_id . ' 已更新', 'data' => array( 'post_id' => $post_id, '链接' => get_permalink( $post_id ) ) );
	}

	public static function delete_post( $args ) {
		$post_id = (int) $args['post_id'];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return array( 'ok' => false, 'message' => '文章不存在' );
		}
		$title = $post->post_title;
		$ok    = wp_delete_post( $post_id, true );
		if ( ! $ok ) {
			return array( 'ok' => false, 'message' => '删除失败' );
		}
		return array( 'ok' => true, 'message' => '文章《' . $title . '》(#' . $post_id . ') 已永久删除' );
	}

	public static function create_page( $args ) {
		$title = sanitize_text_field( $args['title'] );
		if ( '' === $title ) {
			return array( 'ok' => false, 'message' => '标题不能为空' );
		}
		$status = isset( $args['status'] ) && 'publish' === $args['status'] ? 'publish' : 'draft';

		$page_id = wp_insert_post( array(
			'post_title'   => $title,
			'post_content' => isset( $args['content'] ) ? wp_kses_post( $args['content'] ) : '',
			'post_status'  => $status,
			'post_type'    => 'page',
			'post_author'  => get_current_user_id(),
		), true );

		if ( is_wp_error( $page_id ) ) {
			return array( 'ok' => false, 'message' => '创建失败：' . $page_id->get_error_message() );
		}
		return array( 'ok' => true, 'message' => '页面创建成功' . ( 'publish' === $status ? '（已发布）' : '（草稿）' ), 'data' => array( 'page_id' => $page_id, '链接' => get_permalink( $page_id ) ) );
	}

	public static function update_page( $args ) {
		$page_id = (int) $args['page_id'];
		$page    = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type ) {
			return array( 'ok' => false, 'message' => '页面不存在' );
		}
		$data = array( 'ID' => $page_id );
		if ( isset( $args['title'] ) ) {
			$data['post_title'] = sanitize_text_field( $args['title'] );
		}
		if ( isset( $args['content'] ) ) {
			$data['post_content'] = wp_kses_post( $args['content'] );
		}
		if ( isset( $args['status'] ) && in_array( $args['status'], array( 'draft', 'publish' ), true ) ) {
			$data['post_status'] = $args['status'];
		}
		$result = wp_update_post( $data, true );
		if ( is_wp_error( $result ) ) {
			return array( 'ok' => false, 'message' => '更新失败：' . $result->get_error_message() );
		}
		return array( 'ok' => true, 'message' => '页面 #' . $page_id . ' 已更新' );
	}

	public static function delete_page( $args ) {
		$page_id = (int) $args['page_id'];
		$page    = get_post( $page_id );
		if ( ! $page ) {
			return array( 'ok' => false, 'message' => '页面不存在' );
		}
		$title = $page->post_title;
		$ok    = wp_delete_post( $page_id, true );
		if ( ! $ok ) {
			return array( 'ok' => false, 'message' => '删除失败' );
		}
		return array( 'ok' => true, 'message' => '页面《' . $title . '》已永久删除' );
	}

	public static function list_pages( $args ) {
		$number = isset( $args['number'] ) ? (int) $args['number'] : 10;
		$number = min( 50, max( 1, $number ) );

		$pages = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => 'any',
			'posts_per_page' => $number,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
		$items = array();
		foreach ( $pages as $p ) {
			$items[] = array( 'ID' => $p->ID, '标题' => $p->post_title, '状态' => $p->post_status, '日期' => $p->post_date );
		}
		return array( 'ok' => true, 'message' => '共 ' . count( $items ) . ' 个页面', 'data' => $items );
	}

	public static function list_media( $args ) {
		$number = isset( $args['number'] ) ? (int) $args['number'] : 10;
		$number = min( 50, max( 1, $number ) );

		$query = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $number,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( ! empty( $args['search'] ) ) {
			$query['s'] = sanitize_text_field( $args['search'] );
		}
		$items = array();
		foreach ( get_posts( $query ) as $m ) {
			$file = get_attached_file( $m->ID );
			$items[] = array(
				'ID'    => $m->ID,
				'标题'  => $m->post_title,
				'类型'  => $m->post_mime_type,
				'大小'  => ( $file && is_file( $file ) ) ? size_format( (int) filesize( $file ) ) : '-',
				'链接'  => wp_get_attachment_url( $m->ID ),
			);
		}
		return array( 'ok' => true, 'message' => '共 ' . count( $items ) . ' 个媒体文件', 'data' => $items );
	}

	public static function upload_media( $args ) {
		$url = esc_url_raw( $args['url'] );
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return array( 'ok' => false, 'message' => 'URL 无效' );
		}

		// SSRF 防护：禁止下载本机/内网地址
		$safe = Tianma_Tools::validate_download_url( $url );
		if ( true !== $safe ) {
			return array( 'ok' => false, 'message' => $safe );
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			return array( 'ok' => false, 'message' => '下载失败：' . $tmp->get_error_message() );
		}

		$file_array = array(
			'name'     => basename( parse_url( $url, PHP_URL_PATH ) ?: 'file' ),
			'tmp_name' => $tmp,
		);
		$attach_id = media_handle_sideload( $file_array, 0 );
		if ( is_wp_error( $attach_id ) ) {
			@unlink( $tmp );
			return array( 'ok' => false, 'message' => '上传失败：' . $attach_id->get_error_message() );
		}

		return array(
			'ok' => true,
			'message' => '媒体已入库',
			'data' => array( 'attachment_id' => $attach_id, '链接' => wp_get_attachment_url( $attach_id ) ),
		);
	}

	public static function create_category( $args ) {
		$name = sanitize_text_field( $args['name'] );
		if ( '' === $name ) {
			return array( 'ok' => false, 'message' => '分类名不能为空' );
		}
		$parent = ! empty( $args['parent_id'] ) ? (int) $args['parent_id'] : 0;
		$result = wp_insert_term( $name, 'category', array( 'parent' => $parent ) );
		if ( is_wp_error( $result ) ) {
			return array( 'ok' => false, 'message' => '创建分类失败：' . $result->get_error_message() );
		}
		return array( 'ok' => true, 'message' => '分类「' . $name . '」创建成功', 'data' => array( 'term_id' => $result['term_id'] ) );
	}

	public static function list_categories( $args ) {
		$terms = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) {
			return array( 'ok' => false, 'message' => '获取分类失败' );
		}
		$items = array();
		foreach ( $terms as $t ) {
			$items[] = array( 'ID' => $t->term_id, '名称' => $t->name, 'slug' => $t->slug, '文章数' => $t->count );
		}
		return array( 'ok' => true, 'message' => '共 ' . count( $items ) . ' 个分类', 'data' => $items );
	}

	/* ---------------- 生图（AI 图像生成） ---------------- */

	/**
	 * 调用智谱 CogView 生图 API 生成图片，返回图片 URL。
	 * 支持同步返回 url 与异步任务轮询两种模式。
	 */
	public static function generate_image( $args ) {
		$s = Tianma_Settings::get();

		$prompt = trim( (string) ( isset( $args['prompt'] ) ? $args['prompt'] : '' ) );
		if ( '' === $prompt ) {
			return array( 'ok' => false, 'message' => '请提供生图提示词 prompt（描述你想生成的图片内容）' );
		}

		// 支持可选覆盖：功能性角色（如生图助手）可携带自己的 base_url/api_key/model 出图
		$base    = rtrim( (string) ( isset( $args['base_url'] ) && '' !== $args['base_url'] ? $args['base_url'] : $s['image_base_url'] ), '/' );
		$api_key = (string) ( isset( $args['api_key'] ) && '' !== $args['api_key'] ? $args['api_key'] : $s['image_api_key'] );
		$model   = (string) ( isset( $args['model'] ) && '' !== $args['model'] ? $args['model'] : $s['image_model'] );

		if ( '' === $api_key ) {
			return array( 'ok' => false, 'message' => '尚未配置生图 Key：请在「天马智能体 → 角色与技能 → 编辑生图助手 → 单独配置模型/执行凭据」中填入智谱 API Key（格式：id.secret）' );
		}

		$size = isset( $args['size'] ) ? sanitize_text_field( $args['size'] ) : '1024x1024';
		if ( ! in_array( $size, array( '1024x1024', '1024x1536', '1536x1024' ), true ) ) {
			$size = '1024x1024';
		}

		// URL 兼容：base_url 支持「基础地址」(…/v4) 或「完整生图端点」(…/v4/images/generations) 两种填法
		$base = rtrim( (string) ( isset( $args['base_url'] ) && '' !== $args['base_url'] ? $args['base_url'] : $s['image_base_url'] ), '/' );
		if ( '' === $base ) {
			$base = 'https://open.bigmodel.cn/api/paas/v4';
		}
		if ( preg_match( '#/images/generations$#', $base ) ) {
			$endpoint = $base;
			$api_base = preg_replace( '#/images/generations$#', '', $base );
		} else {
			$api_base = $base;
			$endpoint = $base . '/images/generations';
		}

		$body = array(
			'model'  => $model ? $model : 'cogview-3-flash',
			'prompt' => mb_substr( $prompt, 0, 1000 ),
			'size'   => $size,
		);

		$resp = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 120,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $resp ) ) {
			return array( 'ok' => false, 'message' => '生图请求失败：' . $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code >= 400 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : ( isset( $data['message'] ) ? $data['message'] : 'HTTP ' . $code );
			return array( 'ok' => false, 'message' => '生图失败（HTTP ' . $code . '）：' . $msg );
		}

		// 同步返回：data[0].url
		if ( ! empty( $data['data'][0]['url'] ) ) {
			$url = $data['data'][0]['url'];
			return array(
				'ok'      => true,
				'message' => '图片生成成功：' . $url,
				'data'    => array( 'image_url' => $url, 'model' => $model, 'prompt' => $body['prompt'] ),
			);
		}

		// 异步任务：task_id → 轮询 async-result
		if ( ! empty( $data['task_id'] ) ) {
			$tid = $data['task_id'];
			for ( $i = 0; $i < 12; $i++ ) {
				sleep( 3 );
				$r2 = wp_remote_post(
					$api_base . '/async-result/' . $tid,
					array(
						'timeout' => 30,
						'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
					)
				);
				if ( is_wp_error( $r2 ) ) {
					break;
				}
				$d2 = json_decode( wp_remote_retrieve_body( $r2 ), true );
				if ( ! empty( $d2['data'][0]['url'] ) ) {
					return array(
						'ok'      => true,
						'message' => '图片生成成功：' . $d2['data'][0]['url'],
						'data'    => array( 'image_url' => $d2['data'][0]['url'], 'model' => $model ),
					);
				}
				if ( isset( $d2['task_status'] ) && in_array( $d2['task_status'], array( 'FAIL', 'CANCEL' ), true ) ) {
					break;
				}
			}
			return array( 'ok' => false, 'message' => '生图任务已提交但等待超时，请稍后用返回的任务重试' );
		}

		return array( 'ok' => false, 'message' => '生图响应异常：' . mb_substr( wp_remote_retrieve_body( $resp ), 0, 200 ) );
	}
}
