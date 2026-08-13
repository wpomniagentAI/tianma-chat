<?php
/**
 * 天马智能体 · 后台异步执行进程
 *
 * 由 class-tianma.php 的 api_chat 以独立 OS 进程拉起（Windows: start /B；Linux: exec &）。
 * 目标：把耗时且会阻塞 Web worker 的智能体主循环挪到独立进程，
 * 让 Web 请求（POST 启动 + 轮询拉取）几乎瞬时返回，彻底消除"AI 回复期间整站卡顿"。
 *
 * 仅允许 CLI / CLI-SERVER 调用；被浏览器直接访问时立即退出。
 */

if ( PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server' ) {
	header( 'HTTP/1.1 403 Forbidden' );
	echo 'Forbidden';
	exit( 1 );
}

$run_id = '';
foreach ( $argv as $a ) {
	if ( 0 === strpos( $a, '--run=' ) ) {
		$run_id = substr( $a, 6 );
	}
}
if ( '' === $run_id || ! preg_match( '/^[A-Za-z0-9_.-]+$/', $run_id ) ) {
	fwrite( STDERR, "bad run id\n" );
	exit( 1 );
}

// 定位 WordPress 根目录的 wp-load.php（本文件位于 wp-content/plugins/tianma/includes/）
$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	fwrite( STDERR, "wp-load.php not found\n" );
	exit( 1 );
}
require_once $wp_load;

if ( ! class_exists( 'Tianma_Agent' ) ) {
	fwrite( STDERR, "Tianma_Agent not loaded\n" );
	exit( 1 );
}

$upload = wp_upload_dir();
$dir    = $upload['basedir'] . '/tianma-stream';
if ( ! wp_mkdir_p( $dir ) ) {
	fwrite( STDERR, "cannot create stream dir\n" );
	exit( 1 );
}
$stream_file = $dir . '/' . $run_id . '.stream';
$meta_file   = $dir . '/' . $run_id . '.meta';

if ( ! file_exists( $meta_file ) ) {
	fwrite( STDERR, "meta not found\n" );
	exit( 1 );
}
$meta = json_decode( file_get_contents( $meta_file ), true );
if ( ! is_array( $meta ) ) {
	fwrite( STDERR, "bad meta\n" );
	exit( 1 );
}

function tianma_rw_meta( $meta, $meta_file ) {
	file_put_contents( $meta_file, wp_json_encode( $meta, JSON_UNESCAPED_UNICODE ) );
}

$meta['status']     = 'running';
$meta['updated_at'] = time();
tianma_rw_meta( $meta, $meta_file );

// 事件发射器：把 SSE 事件块追加到 stream 文件，并刷新 meta.updated_at（供前端判活）
$emitter = function ( $event, $payload ) use ( $stream_file, $meta_file, &$meta ) {
	$block = 'event: ' . $event . "\n" . 'data: ' . wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ) . "\n\n";
	file_put_contents( $stream_file, $block, FILE_APPEND );
	$meta['updated_at'] = time();
	tianma_rw_meta( $meta, $meta_file );
};

// 客户端断开也不要中止本进程（它已脱离 Web 请求）；按聊天页设置决定执行时限（0 = 不限制）
ignore_user_abort( true );
$php_timeout = isset( $meta['php_timeout'] ) ? (int) $meta['php_timeout'] : 0;
set_time_limit( $php_timeout > 0 ? $php_timeout : 0 );

$agent                = new Tianma_Agent();
$agent->auto_confirm = isset( $meta['auto_confirm'] ) ? $meta['auto_confirm'] : null;
if ( ! empty( $meta['role_id'] ) ) {
	$agent->role = Tianma_Role::get( (int) $meta['role_id'] );
}
// 聊天页可覆盖单条消息的最大工具步数（0 = 不限制）
if ( isset( $meta['max_steps'] ) && '' !== (string) $meta['max_steps'] ) {
	$agent->set_max_steps( (int) $meta['max_steps'] );
}

try {
	$done = $agent->run_stream( $meta['message'], $meta['history'], (int) $meta['user_id'], $emitter );

	// 流式结束后：保存助手回复 + 记录 token 用量（与旧 stream_chat 行为一致）
	$conv_id = ! empty( $meta['conv_id'] ) ? (int) $meta['conv_id'] : 0;
	if ( $conv_id && isset( $done['text'] ) && '' !== trim( (string) $done['text'] ) ) {
		Tianma_Conversation::add_message( $conv_id, 'assistant', $done['text'] );
	}
	if ( isset( $done['usage'] ) && is_array( $done['usage'] ) ) {
		Tianma_Usage::log( (int) $meta['user_id'], $conv_id, $done['usage']['prompt_tokens'], $done['usage']['completion_tokens'] );
	}

	// 高危确认：run_stream 返回 needs_confirmation 即暂停，进程在此退出；
	// 后续由前端 api_confirm（同步续跑）接管，期间服务器完全空闲，用户可自由浏览。
	if ( isset( $done['status'] ) && 'needs_confirmation' === $done['status'] ) {
		$meta['status'] = 'needs_confirm';
	} else {
		$meta['status'] = 'done';
	}
	$meta['final']      = $done;
	$meta['updated_at'] = time();
	tianma_rw_meta( $meta, $meta_file );

	file_put_contents( $stream_file, "event: end\ndata: {}\n\n", FILE_APPEND );
} catch ( \Throwable $e ) {
	file_put_contents( $stream_file, 'event: error' . "\n" . 'data: ' . wp_json_encode( array( 'message' => $e->getMessage() ), JSON_UNESCAPED_UNICODE ) . "\n\n", FILE_APPEND );
	file_put_contents( $stream_file, "event: end\ndata: {}\n\n", FILE_APPEND );
	$meta['status']      = 'error';
	$meta['error']       = $e->getMessage();
	$meta['updated_at']  = time();
	tianma_rw_meta( $meta, $meta_file );
}

exit( 0 );
