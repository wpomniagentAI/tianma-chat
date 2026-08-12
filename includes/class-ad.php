<?php
/**
 * 广告位（开源插件的盈利方式）—— 加密 + 绑定核心逻辑，防删除防篡改
 *
 * 广告内容（文案/价格/链接）以混淆密文存储，渲染入口被核心页面逻辑
 * （Tianma_Core::render_chat_page）强制调用：
 * - 删除本文件 / 移除调用 → 聊天页直接报错不可用（需恢复原版文件）
 * - 篡改密文 → 解密校验失败 → 聊天页拒绝渲染并提示
 *
 * @package Tianma
 */

defined( 'ABSPATH' ) || exit;

class Tianma_Ad {

	/** 混淆密钥（与生成密文时一致；换密钥需用同密钥重新生成 AD_DATA） */
	private static function key() {
		return 'tianma_ad_2026_x7k9#Qz';
	}

	/** 加密广告数据（base64 + XOR 混淆）。换广告内容请用生成脚本重新生成此串 */
	const AD_DATA = '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';

	/** 解密广告数据；返回数组，失败返回 null */
	public static function data() {
		$raw = base64_decode( self::AD_DATA, true );
		if ( false === $raw || '' === $raw ) {
			return null;
		}
		$key = self::key();
		$dec = '';
		$len = strlen( $raw );
		$kl  = strlen( $key );
		for ( $i = 0; $i < $len; $i++ ) {
			$dec .= chr( ord( $raw[ $i ] ) ^ ord( $key[ $i % $kl ] ) );
		}
		$data = json_decode( $dec, true );
		return is_array( $data ) ? $data : null;
	}

	/** 完整性校验：密文可解密且结构完整。被删/被篡改返回 false */
	public static function verify() {
		$d = self::data();
		if ( ! $d ) {
			return false;
		}
		return isset( $d['head']['title'], $d['long']['title'], $d['year_plans'], $d['long']['plans'], $d['cta'], $d['link'], $d['banner'] );
	}

	/** 渲染顶部轮播广告条（聊天窗口顶部；hot 项特别突出，自动轮播） */
	public static function render_banner() {
		$d = self::data();
		if ( ! $d || empty( $d['banner'] ) ) {
			return '<div class="notice notice-error"><p>天马插件广告组件数据校验失败，请恢复原版插件文件。</p></div>';
		}
		$items = $d['banner'];
		ob_start();
		?>
		<div class="tianma-ad-banner" id="tianma-ad-banner">
			<div class="tianma-ad-banner-track">
				<?php foreach ( $items as $i => $b ) : ?>
					<a class="tianma-ad-banner-item<?php echo ! empty( $b['hot'] ) ? ' hot' : ''; ?>" href="<?php echo esc_url( $b['link'] ); ?>" target="_blank" rel="noopener nofollow">
						<span class="tianma-ad-banner-tag"><?php echo esc_html( $b['tag'] ); ?></span>
						<span class="tianma-ad-banner-title"><?php echo esc_html( $b['title'] ); ?></span>
						<span class="tianma-ad-banner-sub"><?php echo esc_html( $b['sub'] ); ?></span>
						<span class="tianma-ad-banner-go">立即查看 →</span>
					</a>
				<?php endforeach; ?>
			</div>
			<div class="tianma-ad-banner-dots">
				<?php foreach ( $items as $i => $b ) : ?>
					<span class="tianma-ad-banner-dot<?php echo ! empty( $b['hot'] ) ? ' on' : ''; ?>" data-i="<?php echo (int) $i; ?>"></span>
				<?php endforeach; ?>
			</div>
			<script>
			(function () {
				var b = document.getElementById( 'tianma-ad-banner' );
				if ( ! b ) { return; }
				var track = b.querySelector( '.tianma-ad-banner-track' );
				var items = b.querySelectorAll( '.tianma-ad-banner-item' );
				var dots = b.querySelectorAll( '.tianma-ad-banner-dot' );
				var n = items.length;
				if ( n < 2 ) { return; }
				// 默认停在第 3 条（hot 特别突出项），停留更久
				var delays = [];
				for ( var i = 0; i < n; i++ ) { delays.push( items[ i ].classList.contains( 'hot' ) ? 8000 : 5000 ); }
				var cur = n - 1, timer = null;
				function go( i ) {
					cur = ( i + n ) % n;
					track.style.transform = 'translateX(-' + ( cur * 100 ) + '%)';
					for ( var k = 0; k < dots.length; k++ ) {
						dots[ k ].classList.toggle( 'on', k === cur );
					}
				}
				function start() {
					if ( timer ) { clearInterval( timer ); }
					timer = setInterval( function () { go( cur + 1 ); }, delays[ cur ] );
				}
				function stop() {
					if ( timer ) { clearInterval( timer ); timer = null; }
				}
				b.addEventListener( 'mouseenter', stop );
				b.addEventListener( 'mouseleave', start );
				for ( var d = 0; d < dots.length; d++ ) {
					( function ( idx ) {
						dots[ idx ].addEventListener( 'click', function () { go( idx ); start(); } );
					} )( d );
				}
				go( n - 1 );
				start();
			})();
			</script>
		</div>
		<?php
		return ob_get_clean();
	}

	/** 渲染广告卡片 HTML（由核心页面逻辑调用；校验失败返回警示横幅） */
	public static function render() {
		$d = self::data();
		if ( ! $d ) {
			return '<div class="notice notice-error"><p>天马插件广告组件数据校验失败，请恢复原版插件文件。</p></div>';
		}
		$link = esc_url( $d['link'] );
		$cta  = esc_html( $d['cta'] );
		ob_start();
		?>
		<a class="tianma-ad" href="<?php echo $link; ?>" target="_blank" rel="noopener nofollow">
			<div class="tianma-ad-head">
				<span class="tianma-ad-badge"><?php echo esc_html( $d['head']['badge'] ); ?></span>
				<h3><?php echo esc_html( $d['head']['title'] ); ?></h3>
				<p class="tianma-ad-sub"><?php echo esc_html( $d['head']['sub'] ); ?></p>
			</div>
			<ul class="tianma-ad-plans">
				<?php foreach ( $d['year_plans'] as $p ) : ?>
					<li>
						<span class="tianma-ad-p-name"><?php echo esc_html( $p['name'] ); ?></span>
						<span class="tianma-ad-p-tag"><?php echo esc_html( $p['tag'] ); ?></span>
						<span class="tianma-ad-p-price"><del><?php echo esc_html( $p['price'] ); ?></del><b><?php echo esc_html( $p['sale'] ); ?></b></span>
					</li>
				<?php endforeach; ?>
			</ul>

			<div class="tianma-ad-long">
				<div class="tianma-ad-head">
					<span class="tianma-ad-badge tianma-ad-badge-long"><?php echo esc_html( $d['long']['badge'] ); ?></span>
					<h3><?php echo esc_html( $d['long']['title'] ); ?></h3>
					<p class="tianma-ad-sub"><?php echo esc_html( $d['long']['sub'] ); ?></p>
				</div>
				<ul class="tianma-ad-plans">
					<?php foreach ( $d['long']['plans'] as $p ) : ?>
						<li>
							<span class="tianma-ad-p-disc"><?php echo esc_html( $p['badge'] ); ?></span>
							<span class="tianma-ad-p-name"><?php echo esc_html( $p['name'] ); ?></span>
							<span class="tianma-ad-p-tag"><?php echo esc_html( $p['tag'] ); ?></span>
							<span class="tianma-ad-p-price"><del><?php echo esc_html( $p['price'] ); ?></del><b><?php echo esc_html( $p['sale'] ); ?></b></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<span class="tianma-ad-cta"><?php echo $cta; ?></span>
		</a>
		<?php
		return ob_get_clean();
	}
}
