<?php
/**
 * 设置管理与存取
 *
 * @package Tianma
 */

defined( 'ABSPATH' ) || exit;

class Tianma_Settings {

	const OPTION = 'tianma_settings';

	/** 默认设置 */
	public static function defaults() {
		return array(
			'provider'       => 'deepseek',
			'base_url'       => 'https://api.deepseek.com/v1',
			'api_key'        => '',
			'model'          => 'deepseek-chat',
			'temperature'    => 0.7,
			'max_steps'      => 15,
			'confirm_mode'   => 'high',   // auto | high | all
			'embedding_model'=> 'text-embedding-v1',
			'memory_enabled' => 1,
			'mock_mode'      => 0,
			// 各服务商独立保存的配置（provider => base_url/api_key/model），切换服务商时自动加载
			'providers'      => array(),
			// 生图配置（generate_image 工具使用，可独立于对话模型）
			'image_provider' => 'zhipu',
			'image_base_url' => 'https://open.bigmodel.cn/api/paas/v4',
			'image_api_key'  => '',
			'image_model'    => 'cogview-3-flash',
		);
	}

	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * 更新设置（部分更新：只覆盖传入字段，未传字段保留原值；api_key 传空串表示清空）
	 *
	 * 核心设计：每个服务商（provider）独立保存自己的 base_url / api_key / model（providers 索引）。
	 * - 切换 provider 时：先把当前服务商的配置归档到 providers，再加载新服务商已保存的配置（无则用预设填充）
	 * - 保存时：显式传入的 base_url/model/api_key 覆盖当前服务商的配置并同步进 providers
	 * - 因此「切到哪个服务商，实际调用的就是哪个服务商自己已保存的配置」
	 */
	public static function update( $data ) {
		$current   = self::get();
		$clean     = array();
		$providers = isset( $current['providers'] ) && is_array( $current['providers'] ) ? $current['providers'] : array();

		$old_provider = isset( $current['provider'] ) ? $current['provider'] : '';
		$new_provider = isset( $data['provider'] ) ? sanitize_key( $data['provider'] ) : $old_provider;
		$switching    = ( '' !== $new_provider && $new_provider !== $old_provider );

		// 1) 切换前：归档当前服务商的配置
		if ( $switching && '' !== $old_provider ) {
			$providers[ $old_provider ] = array(
				'base_url' => $current['base_url'],
				'api_key'  => $current['api_key'],
				'model'    => $current['model'],
			);
		}

		// 2) 显式传入字段
		if ( isset( $data['provider'] ) ) {
			$clean['provider'] = $new_provider;
		}
		if ( isset( $data['base_url'] ) ) {
			$clean['base_url'] = esc_url_raw( $data['base_url'] );
		}
		if ( isset( $data['api_key'] ) ) {
			// 掩码（含 •）不覆盖真实 Key；空串表示清空；其余正常更新
			if ( false !== strpos( (string) $data['api_key'], '•' ) ) {
				// 保留原值
			} elseif ( '' === trim( (string) $data['api_key'] ) ) {
				$clean['api_key'] = '';
			} else {
				$clean['api_key'] = sanitize_text_field( $data['api_key'] );
			}
		}
		if ( isset( $data['model'] ) ) {
			$clean['model'] = sanitize_text_field( $data['model'] );
		}
		if ( isset( $data['temperature'] ) ) {
			$clean['temperature'] = (float) $data['temperature'];
		}
		if ( isset( $data['max_steps'] ) ) {
			$clean['max_steps'] = min( 40, max( 3, (int) $data['max_steps'] ) );
		}
		if ( isset( $data['confirm_mode'] ) && in_array( $data['confirm_mode'], array( 'auto', 'high', 'all' ), true ) ) {
			$clean['confirm_mode'] = $data['confirm_mode'];
		}
		if ( isset( $data['embedding_model'] ) ) {
			$clean['embedding_model'] = sanitize_text_field( $data['embedding_model'] );
		}
		if ( isset( $data['memory_enabled'] ) ) {
			$clean['memory_enabled'] = empty( $data['memory_enabled'] ) ? 0 : 1;
		}
		if ( isset( $data['mock_mode'] ) ) {
			$clean['mock_mode'] = empty( $data['mock_mode'] ) ? 0 : 1;
		}
		// 生图配置（generate_image 工具）
		if ( isset( $data['image_provider'] ) ) {
			$clean['image_provider'] = sanitize_key( $data['image_provider'] );
		}
		if ( isset( $data['image_base_url'] ) ) {
			$clean['image_base_url'] = esc_url_raw( $data['image_base_url'] );
		}
		if ( isset( $data['image_api_key'] ) && '' !== trim( (string) $data['image_api_key'] ) && false === strpos( (string) $data['image_api_key'], '•' ) ) {
			$clean['image_api_key'] = sanitize_text_field( $data['image_api_key'] );
		}
		if ( isset( $data['image_model'] ) ) {
			$clean['image_model'] = sanitize_text_field( $data['image_model'] );
		}

		// 3) 切换时：加载新服务商已保存的配置（对未显式传入的字段）；无已保存配置则用预设填充
		if ( $switching ) {
			if ( isset( $providers[ $new_provider ] ) && is_array( $providers[ $new_provider ] ) ) {
				$saved = $providers[ $new_provider ];
				if ( ! isset( $data['base_url'] ) && ! empty( $saved['base_url'] ) ) {
					$clean['base_url'] = $saved['base_url'];
				}
				if ( ! isset( $data['model'] ) && ! empty( $saved['model'] ) ) {
					$clean['model'] = $saved['model'];
				}
				if ( ! isset( $data['api_key'] ) && ! empty( $saved['api_key'] ) ) {
					$clean['api_key'] = $saved['api_key'];
				}
			} else {
				$presets = self::presets();
				if ( isset( $presets[ $new_provider ] ) ) {
					if ( ! isset( $data['base_url'] ) && ! empty( $presets[ $new_provider ]['base_url'] ) ) {
						$clean['base_url'] = $presets[ $new_provider ]['base_url'];
					}
					if ( ! isset( $data['model'] ) && ! empty( $presets[ $new_provider ]['model'] ) ) {
						$clean['model'] = $presets[ $new_provider ]['model'];
					}
				}
				// 目标服务商从未保存过 → 清空 Key，避免把上一个服务商的 Key 带过去调错 API
				if ( ! isset( $data['api_key'] ) ) {
					$clean['api_key'] = '';
				}
			}
		}

		$merged = array_merge( $current, $clean );

		// 4) 把当前生效配置同步进 providers[当前服务商]（显式保存或切换后都同步）
		$merged_prov = $merged['provider'];
		$providers[ $merged_prov ] = array(
			'base_url' => isset( $merged['base_url'] ) ? $merged['base_url'] : '',
			'api_key'  => isset( $merged['api_key'] ) ? $merged['api_key'] : '',
			'model'    => isset( $merged['model'] ) ? $merged['model'] : '',
		);
		$merged['providers'] = $providers;

		update_option( self::OPTION, $merged );
		return $merged;
	}

	/** 两个 URL 是否同域名（用于判断 base_url 是否属于某个 provider 的预设地址） */
	public static function same_host( $url1, $url2 ) {
		$h1 = parse_url( (string) $url1, PHP_URL_HOST );
		$h2 = parse_url( (string) $url2, PHP_URL_HOST );
		return $h1 && $h2 && strtolower( $h1 ) === strtolower( $h2 );
	}

	/** base_url 规范化：与 provider 预设同域名 → 对齐为预设基础地址；否则视为自定义保留原样 */
	public static function normalize_base_url( $provider, $base_url ) {
		$presets = self::presets();
		if ( isset( $presets[ $provider ]['base_url'] ) && $presets[ $provider ]['base_url'] ) {
			$preset = rtrim( (string) $presets[ $provider ]['base_url'], '/' );
			if ( self::same_host( $base_url, $preset ) ) {
				return $preset;
			}
		}
		return $base_url;
	}

	/** 预设 Provider 模板（含各家 API 地址与可用模型） */
	public static function presets() {
		return array(
			'deepseek' => array(
				'label'    => 'DeepSeek 深度求索',
				'base_url' => 'https://api.deepseek.com/v1',
				'model'    => 'deepseek-chat',
				'models'   => array( 'deepseek-chat', 'deepseek-reasoner' ),
			),
			'zhipu' => array(
				'label'    => '智谱 GLM',
				'base_url' => 'https://open.bigmodel.cn/api/paas/v4',
				'model'    => 'glm-4.5-flash',
				'models'   => array( 'glm-4.5-flash', 'glm-4.5-air', 'glm-4.5-airx', 'glm-4.5', 'glm-4.5-x', 'glm-4-plus', 'glm-4-air', 'glm-4-flash', 'embedding-2' ),
			),
			'qwen' => array(
				'label'    => '通义千问（阿里云）',
				'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
				'model'    => 'qwen-plus',
				'models'   => array( 'qwen-max', 'qwen-plus', 'qwen-turbo', 'qwen-long', 'qwen2.5-72b-instruct', 'qwen2.5-32b-instruct', 'text-embedding-v3', 'text-embedding-v1' ),
				'recommend'=> true,
				'link'     => 'https://www.aliyun.com/product/tongyi?source=5176.29345612&userCode=5ql52pjx',
				'free'     => '新用户开通百炼，每款模型各送 100 万 Tokens（70+ 款模型合计 7000 万+ Tokens，90 天有效）',
			),
			'hunyuan' => array(
				'label'    => '腾讯云混元',
				'base_url' => 'https://api.hunyuan.cloud.tencent.com/v1',
				'model'    => 'hunyuan-turbos',
				'models'   => array( 'hunyuan-turbos', 'hunyuan-turbo', 'hunyuan-turbo-latest', 'hunyuan-standard', 'hunyuan-lite', 'hunyuan-pro' ),
				'recommend'=> true,
				'link'     => 'https://curl.qcloud.com/TwgyEsgI',
				'free'     => '新用户开通即送 100 万 Tokens（1 年有效）+ 100 万 Embedding Tokens，Hunyuan-Lite 永久免费',
			),
			'kimi' => array(
				'label'    => 'KIMI（月之暗面）',
				'base_url' => 'https://api.moonshot.cn/v1',
				'model'    => 'kimi-latest',
				'models'   => array( 'kimi-latest', 'kimi-k2-0711-preview', 'moonshot-v1-8k', 'moonshot-v1-32k', 'moonshot-v1-128k' ),
			),
			'wenxin' => array(
				'label'    => '文心一言（百度千帆）',
				'base_url' => 'https://qianfan.baidubce.com/v2',
				'model'    => 'ernie-4.0-8k',
				'models'   => array( 'ernie-4.0-8k', 'ernie-4.0-turbo-8k', 'ernie-3.5-8k', 'ernie-speed-8k', 'ernie-lite-8k' ),
			),
			'openai' => array(
				'label'    => 'OpenAI',
				'base_url' => 'https://api.openai.com/v1',
				'model'    => 'gpt-4o-mini',
				'models'   => array( 'gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-4', 'gpt-3.5-turbo', 'o3-mini', 'text-embedding-3-small' ),
			),
			'gemini' => array(
				'label'    => 'Gemini（Google，OpenAI 兼容端点）',
				'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
				'model'    => 'gemini-2.0-flash',
				'models'   => array( 'gemini-2.0-flash', 'gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-1.5-pro', 'gemini-1.5-flash' ),
			),
			'claude' => array(
				'label'    => 'Claude（Anthropic，原生适配）',
				'base_url' => 'https://api.anthropic.com',
				'model'    => 'claude-sonnet-4-20250514',
				'models'   => array( 'claude-sonnet-4-20250514', 'claude-opus-4-20250514', 'claude-3-7-sonnet-20250219', 'claude-3-5-sonnet-20241022', 'claude-3-5-haiku-20241022' ),
			),
			'custom' => array(
				'label'    => '自定义（OpenAI 兼容）',
				'base_url' => '',
				'model'    => '',
				'models'   => array(),
			),
			'mock' => array(
				'label'    => '本地演示模式（无需 Key）',
				'base_url' => '',
				'model'    => 'mock',
				'models'   => array(),
			),
		);
	}
}
