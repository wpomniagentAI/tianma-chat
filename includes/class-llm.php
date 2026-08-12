<?php
/**
 * LLM 客户端（OpenAI 兼容 API）
 * 支持：chat completions + function calling + embeddings + 本地演示模式
 *
 * @package Tianma
 */

defined( 'ABSPATH' ) || exit;

class Tianma_LLM {

	private $settings;

	/** 是否使用了角色级模型覆盖（用于调用失败时降级全局模型重试） */
	public $using_override = false;

	/** 调用失败后被降级的角色模型名（仅提示用，不做任何规则判断） */
	public $rejected_override_model = '';

	public function __construct( $override = array() ) {
		$settings = Tianma_Settings::get();
		if ( is_array( $override ) && ! empty( $override['api_key'] ) ) {
			$this->using_override = true;
			// 角色级覆盖：仅覆盖明确提供的字段，其余沿用全局。
			// 不做「非对话型模型」名字判断——模型能不能聊天由调用结果说了算（失败自动降级）。
			$settings = array_merge( $settings, array(
				'provider' => ! empty( $override['provider'] ) ? $override['provider'] : $settings['provider'],
				'base_url' => ! empty( $override['base_url'] ) ? $override['base_url'] : $settings['base_url'],
				'api_key'  => $override['api_key'],
				'model'    => ! empty( $override['model'] ) ? $override['model'] : $settings['model'],
			) );
		}
		$this->settings = $settings;
	}

	/* ---------------------------------------------------------------------
	 * 流式 Chat：逐块实时回调内容（SSE），完成后返回完整结果
	 * $on_delta( $text ) 每收到一段文本调用一次
	 * $on_reasoning( $text ) 每收到一段深度思考内容调用一次（模型支持时）
	 * 返回同 chat()：array{ content, tool_calls, usage } 或 WP_Error
	 * ------------------------------------------------------------------- */

	public function stream_chat( $messages, $tools = array(), $on_delta = null, $on_reasoning = null ) {
		$settings = $this->settings;

		if ( ! empty( $settings['mock_mode'] ) || 'mock' === $settings['provider'] ) {
			$resp = $this->mock_chat( $messages );
			if ( $on_delta && ! empty( $resp['content'] ) ) {
				$on_delta( $resp['content'] );
			}
			return $resp;
		}

		if ( $this->is_claude() ) {
			return $this->claude_stream( $messages, $tools, $on_delta, $on_reasoning );
		}

		if ( '' === $settings['api_key'] ) {
			return new WP_Error( 'tianma_no_key', '尚未配置模型 API Key，请在「天马智能体 → 模型设置」中完成配置' );
		}

		$body = array(
			'model'       => $settings['model'],
			'messages'    => $messages,
			'temperature' => (float) $settings['temperature'],
			'stream'      => true,
		);
		if ( ! empty( $tools ) ) {
			$body['tools']       = $tools;
			$body['tool_choice'] = 'auto';
		}

		$content = '';
		$tool_calls = array();
		$usage   = array();

		$ch = curl_init( $this->endpoint( 'chat/completions' ) );
		curl_setopt_array( $ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 150,
			CURLOPT_CAINFO         => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/json',
				'Authorization: Bearer ' . $settings['api_key'],
			),
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
			CURLOPT_WRITEFUNCTION  => function ( $ch, $chunk ) use ( &$content, &$tool_calls, &$usage, $on_delta, $on_reasoning ) {
				$lines = preg_split( "/\r?\n/", $chunk );
				foreach ( $lines as $line ) {
					$line = trim( $line );
					if ( '' === $line || 'data:' !== substr( $line, 0, 5 ) ) {
						continue;
					}
					$payload = trim( substr( $line, 5 ) );
					if ( '[DONE]' === $payload ) {
						continue;
					}
					$json = json_decode( $payload, true );
					if ( ! is_array( $json ) ) {
						continue;
					}
					// 流式末尾的 usage（多数兼容模型会返回）
					if ( ! empty( $json['usage'] ) && is_array( $json['usage'] ) ) {
						$usage = $json['usage'];
					}
					if ( empty( $json['choices'][0] ) ) {
						continue;
					}
					$delta = isset( $json['choices'][0]['delta'] ) ? $json['choices'][0]['delta'] : array();
					// 深度思考内容（glm-4.5 等模型返回 reasoning_content）
					if ( is_callable( $on_reasoning ) && isset( $delta['reasoning_content'] ) && is_string( $delta['reasoning_content'] ) && '' !== $delta['reasoning_content'] ) {
						$on_reasoning( $delta['reasoning_content'] );
					}
					if ( isset( $delta['content'] ) && is_string( $delta['content'] ) && '' !== $delta['content'] ) {
						$content .= $delta['content'];
						if ( is_callable( $on_delta ) ) {
							$on_delta( $delta['content'] );
						}
					}
					if ( ! empty( $delta['tool_calls'] ) && is_array( $delta['tool_calls'] ) ) {
						foreach ( $delta['tool_calls'] as $tc ) {
							$idx = isset( $tc['index'] ) ? (int) $tc['index'] : 0;
							if ( ! isset( $tool_calls[ $idx ] ) ) {
								$tool_calls[ $idx ] = array( 'id' => '', 'name' => '', 'arguments' => '' );
							}
							if ( ! empty( $tc['id'] ) ) {
								$tool_calls[ $idx ]['id'] = $tc['id'];
							}
							if ( ! empty( $tc['function']['name'] ) ) {
								$tool_calls[ $idx ]['name'] = $tc['function']['name'];
							}
							if ( isset( $tc['function']['arguments'] ) ) {
								$tool_calls[ $idx ]['arguments'] .= $tc['function']['arguments'];
							}
						}
					}
				}
				return strlen( $chunk );
			},
		) );

		$resp_body = curl_exec( $ch );
		$err       = curl_error( $ch );
		$code      = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		curl_close( $ch );

		if ( '' !== $err ) {
			return new WP_Error( 'tianma_http', $err );
		}
		if ( $code >= 400 ) {
			// 通用降级（不依赖模型名规则）：角色覆盖模型流式调用失败 → 自动用全局模型重试一次
			if ( $this->using_override ) {
				$this->rejected_override_model = $this->settings['model'];
				$global = new Tianma_LLM();
				$retry  = $global->stream_chat( $messages, $tools, $on_delta, $on_reasoning );
				if ( ! is_wp_error( $retry ) ) {
					return $retry;
				}
			}
			return new WP_Error( 'tianma_api', '模型服务返回错误（HTTP ' . $code . '），请稍后重试或更换模型' );
		}

		// 汇总 tool_calls（流式增量已拼好）
		$out_calls = array();
		foreach ( $tool_calls as $tc ) {
			$out_calls[] = array(
				'id'        => $tc['id'],
				'name'      => $tc['name'],
				'arguments' => '' === $tc['arguments'] ? '{}' : $tc['arguments'],
			);
		}

		return array(
			'content'    => $content,
			'tool_calls' => $out_calls,
			'usage'      => $this->extract_usage( $usage ),
		);
	}

	/* ---------------------------------------------------------------------
	 * URL 规范化：兼容「基础地址」与「完整 chat/completions 端点」两种填法
	 * ------------------------------------------------------------------- */

	private function endpoint( $type, $base_url = '' ) {
		$base = rtrim( $base_url ? $base_url : $this->settings['base_url'], '/' );
		// 用户填的地址若已包含完整端点 → 原样使用，绝不重复拼接/截断重拼
		$suffix = '/' . $type;
		if ( substr( $base, -strlen( $suffix ) ) === $suffix ) {
			return $base;
		}
		return $base . $suffix;
	}

	/* ---------------------------------------------------------------------
	 * Chat（含工具调用）
	 * 返回 array{ content: string, tool_calls: array }
	 * 失败返回 WP_Error
	 * ------------------------------------------------------------------- */

	public function chat( $messages, $tools = array(), $temperature = null ) {
		$settings = $this->settings;

		if ( ! empty( $settings['mock_mode'] ) || 'mock' === $settings['provider'] ) {
			return $this->mock_chat( $messages );
		}

		if ( $this->is_claude() ) {
			return $this->claude_chat( $messages, $tools, $temperature );
		}

		if ( '' === $settings['api_key'] ) {
			return new WP_Error( 'tianma_no_key', '尚未配置模型 API Key，请在「天马智能体 → 模型设置」中完成配置' );
		}

		$temperature = null === $temperature ? $settings['temperature'] : (float) $temperature;

		$body = array(
			'model'       => $settings['model'],
			'messages'    => $messages,
			'temperature' => $temperature,
		);

		if ( ! empty( $tools ) ) {
			$body['tools']     = $tools;
			$body['tool_choice'] = 'auto';
		}

		$resp = wp_remote_post(
			$this->endpoint( 'chat/completions' ),
			array(
				'timeout' => 120,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $settings['api_key'],
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'tianma_http', $resp->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( $code >= 400 || empty( $data['choices'][0] ) ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			// 通用降级（不依赖模型名规则）：角色覆盖模型调用失败 → 自动用全局模型重试一次。
			// 例如把生图模型当对话模型用时接口会 404，重试即可正常完成。
			if ( $this->using_override ) {
				$this->rejected_override_model = $this->settings['model'];
				$global = new Tianma_LLM();
				$retry  = $global->chat( $messages, $tools, $temperature );
				if ( ! is_wp_error( $retry ) ) {
					return $retry;
				}
			}
			return new WP_Error( 'tianma_api', $msg );
		}

		$choice  = $data['choices'][0]['message'];
		$content = isset( $choice['content'] ) && is_string( $choice['content'] ) ? $choice['content'] : '';

		$tool_calls = array();
		if ( ! empty( $choice['tool_calls'] ) && is_array( $choice['tool_calls'] ) ) {
			foreach ( $choice['tool_calls'] as $tc ) {
				if ( 'function' !== $tc['type'] ) {
					continue;
				}
				$tool_calls[] = array(
					'id'       => isset( $tc['id'] ) ? $tc['id'] : '',
					'name'     => isset( $tc['function']['name'] ) ? $tc['function']['name'] : '',
					'arguments'=> isset( $tc['function']['arguments'] ) ? $tc['function']['arguments'] : '{}',
				);
			}
		}

		return array(
			'content'    => $content,
			'tool_calls' => $tool_calls,
			'usage'      => $this->extract_usage( isset( $data['usage'] ) ? $data['usage'] : array() ),
		);
	}

	/** 从 API usage 提取 token 统计（缺省时估算） */
	private function extract_usage( $usage ) {
		$p = isset( $usage['prompt_tokens'] ) ? (int) $usage['prompt_tokens'] : 0;
		$c = isset( $usage['completion_tokens'] ) ? (int) $usage['completion_tokens'] : 0;
		return array(
			'prompt_tokens'     => $p,
			'completion_tokens' => $c,
			'total_tokens'      => $p + $c,
		);
	}

	/* ---------------------------------------------------------------------
	 * Claude（Anthropic）原生适配：API 格式与 OpenAI 不同，需单独转换
	 * ------------------------------------------------------------------- */

	private function is_claude() {
		return 'claude' === $this->settings['provider'] || false !== strpos( (string) $this->settings['base_url'], 'api.anthropic.com' );
	}

	private function claude_url() {
		$base = rtrim( $this->settings['base_url'], '/' );
		if ( substr( $base, -3 ) === '/v1' ) {
			$base = substr( $base, 0, -3 );
		}
		return $base . '/v1/messages';
	}

	private function claude_headers() {
		return array(
			'Content-Type'      => 'application/json',
			'x-api-key'         => $this->settings['api_key'],
			'anthropic-version' => '2023-06-01',
		);
	}

	/** OpenAI 消息 → Claude 消息（system 提出，tool 结果合并到上一条 assistant） */
	private function to_claude_messages( $messages ) {
		$system = '';
		$out    = array();
		foreach ( (array) $messages as $m ) {
			$role = isset( $m['role'] ) ? $m['role'] : 'user';
			if ( 'system' === $role ) {
				$system .= ( $system ? "\n" : '' ) . ( is_string( $m['content'] ) ? $m['content'] : '' );
				continue;
			}
			if ( 'tool' === $role ) {
				$block = array(
					'type'        => 'tool_result',
					'tool_use_id' => isset( $m['tool_call_id'] ) ? $m['tool_call_id'] : '',
					'content'     => mb_substr( (string) $m['content'], 0, 4000 ),
				);
				$last = count( $out ) - 1;
				if ( $last >= 0 && 'assistant' === $out[ $last ]['role'] && isset( $out[ $last ]['content'] ) && is_array( $out[ $last ]['content'] ) ) {
					$out[ $last ]['content'][] = $block;
				} else {
					$out[] = array( 'role' => 'user', 'content' => array( $block ) );
				}
				continue;
			}
			if ( 'assistant' === $role ) {
				$content = array();
				if ( isset( $m['content'] ) && '' !== $m['content'] ) {
					$content[] = array( 'type' => 'text', 'text' => $m['content'] );
				}
				if ( ! empty( $m['tool_calls'] ) ) {
					foreach ( $m['tool_calls'] as $tc ) {
						$input = json_decode( $tc['function']['arguments'], true );
						// Claude 要求 input 为对象；空参数用空对象
						if ( ! is_array( $input ) || empty( $input ) ) {
							$input = new stdClass();
						}
						$content[] = array(
							'type'  => 'tool_use',
							'id'    => $tc['id'],
							'name'  => $tc['function']['name'],
							'input' => $input,
						);
					}
				}
				if ( ! $content ) {
					$content[] = array( 'type' => 'text', 'text' => '' );
				}
				$out[] = array( 'role' => 'assistant', 'content' => $content );
				continue;
			}
			$out[] = array( 'role' => 'user', 'content' => is_string( $m['content'] ) ? $m['content'] : '' );
		}
		return array( $system, $out );
	}

	private function to_claude_tools( $tools ) {
		$out = array();
		foreach ( (array) $tools as $t ) {
			$f = isset( $t['function'] ) ? $t['function'] : array();
			$out[] = array(
				'name'         => isset( $f['name'] ) ? $f['name'] : '',
				'description'  => isset( $f['description'] ) ? $f['description'] : '',
				'input_schema' => isset( $f['parameters'] ) ? $f['parameters'] : (object) array(),
			);
		}
		return $out;
	}

	private function parse_claude_response( $data ) {
		$content    = '';
		$tool_calls = array();
		if ( ! empty( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( 'text' === $block['type'] ) {
					$content .= $block['text'];
				} elseif ( 'tool_use' === $block['type'] ) {
					$tool_calls[] = array(
						'id'        => isset( $block['id'] ) ? $block['id'] : '',
						'name'      => isset( $block['name'] ) ? $block['name'] : '',
						'arguments' => wp_json_encode( isset( $block['input'] ) ? $block['input'] : new stdClass(), JSON_UNESCAPED_UNICODE ),
					);
				}
			}
		}
		$usage = isset( $data['usage'] ) ? $data['usage'] : array();
		return array(
			'content'    => $content,
			'tool_calls' => $tool_calls,
			'usage'      => $this->extract_usage( array(
				'prompt_tokens'     => isset( $usage['input_tokens'] ) ? $usage['input_tokens'] : 0,
				'completion_tokens' => isset( $usage['output_tokens'] ) ? $usage['output_tokens'] : 0,
			) ),
		);
	}

	private function claude_chat( $messages, $tools, $temperature = null ) {
		list( $system, $conv ) = $this->to_claude_messages( $messages );

		$body = array(
			'model'      => $this->settings['model'],
			'messages'   => $conv,
			'max_tokens' => 4096,
		);
		if ( $system ) {
			$body['system'] = $system;
		}
		if ( null !== $temperature ) {
			$body['temperature'] = (float) $temperature;
		}
		$claude_tools = $this->to_claude_tools( $tools );
		if ( $claude_tools ) {
			$body['tools'] = $claude_tools;
		}

		$resp = wp_remote_post( $this->claude_url(), array(
			'timeout' => 120,
			'headers' => $this->claude_headers(),
			'body'    => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'tianma_http', $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code >= 400 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'tianma_api', $msg );
		}
		return $this->parse_claude_response( $data );
	}

	/** Claude 流式：解析 Anthropic SSE（text_delta / input_json_delta） */
	private function claude_stream( $messages, $tools, $on_delta, $on_reasoning ) {
		list( $system, $conv ) = $this->to_claude_messages( $messages );

		$body = array(
			'model'      => $this->settings['model'],
			'messages'   => $conv,
			'max_tokens' => 4096,
			'stream'     => true,
		);
		if ( $system ) {
			$body['system'] = $system;
		}
		$claude_tools = $this->to_claude_tools( $tools );
		if ( $claude_tools ) {
			$body['tools'] = $claude_tools;
		}

		$content = '';
		$tool_blocks = array(); // index → {id,name,args}
		$usage = array();

		$ch = curl_init( $this->claude_url() );
		curl_setopt_array( $ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 150,
			CURLOPT_CAINFO         => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/json',
				'x-api-key: ' . $this->settings['api_key'],
				'anthropic-version: 2023-06-01',
			),
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
			CURLOPT_WRITEFUNCTION  => function ( $ch, $chunk ) use ( &$content, &$tool_blocks, &$usage, $on_delta, $on_reasoning ) {
				$lines = preg_split( "/\r?\n/", $chunk );
				foreach ( $lines as $line ) {
					$line = trim( $line );
					if ( 'event:' === substr( $line, 0, 6 ) ) {
						continue; // event 行，无需处理
					}
					if ( 'data:' !== substr( $line, 0, 5 ) ) {
						continue;
					}
					$json = json_decode( trim( substr( $line, 5 ) ), true );
					if ( ! is_array( $json ) ) {
						continue;
					}
					// message_start / message_delta 携带 usage
					if ( ! empty( $json['message']['usage'] ) ) {
						$usage = $json['message']['usage'];
					}
					if ( ! empty( $json['usage'] ) ) {
						$usage = $json['usage'];
					}
					if ( 'content_block_start' === $json['type'] && ! empty( $json['content_block'] ) ) {
						$cb = $json['content_block'];
						if ( 'tool_use' === $cb['type'] ) {
							$tool_blocks[ (int) $json['index'] ] = array(
								'id'   => isset( $cb['id'] ) ? $cb['id'] : '',
								'name' => isset( $cb['name'] ) ? $cb['name'] : '',
								'args' => '',
							);
						}
						continue;
					}
					if ( 'content_block_delta' === $json['type'] && ! empty( $json['delta'] ) ) {
						$d = $json['delta'];
						if ( 'text_delta' === $d['type'] && isset( $d['text'] ) ) {
							$content .= $d['text'];
							if ( is_callable( $on_delta ) ) {
								$on_delta( $d['text'] );
							}
						} elseif ( 'input_json_delta' === $d['type'] && isset( $d['partial_json'] ) ) {
							$idx = (int) $json['index'];
							if ( isset( $tool_blocks[ $idx ] ) ) {
								$tool_blocks[ $idx ]['args'] .= $d['partial_json'];
							}
						} elseif ( 'thinking_delta' === $d['type'] && isset( $d['thinking'] ) && is_callable( $on_reasoning ) ) {
							$on_reasoning( $d['thinking'] );
						}
					}
				}
				return strlen( $chunk );
			},
		) );

		curl_exec( $ch );
		$err  = curl_error( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		curl_close( $ch );

		if ( '' !== $err ) {
			return new WP_Error( 'tianma_http', $err );
		}
		if ( $code >= 400 ) {
			return new WP_Error( 'tianma_api', 'Claude 服务返回错误（HTTP ' . $code . '）' );
		}

		$tool_calls = array();
		foreach ( $tool_blocks as $tb ) {
			$tool_calls[] = array(
				'id'        => $tb['id'],
				'name'      => $tb['name'],
				'arguments' => '' === $tb['args'] ? '{}' : $tb['args'],
			);
		}

		return array(
			'content'    => $content,
			'tool_calls' => $tool_calls,
			'usage'      => $this->extract_usage( array(
				'prompt_tokens'     => isset( $usage['input_tokens'] ) ? $usage['input_tokens'] : 0,
				'completion_tokens' => isset( $usage['output_tokens'] ) ? $usage['output_tokens'] : 0,
			) ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Embeddings：文本 → 向量
	 * 返回 array( int index => array( float... ) )，失败返回 WP_Error
	 * ------------------------------------------------------------------- */

	public function embed( $texts ) {
		$settings = $this->settings;

		if ( ! empty( $settings['mock_mode'] ) || 'mock' === $settings['provider'] ) {
			return new WP_Error( 'tianma_no_embed', '演示模式不支持向量嵌入，记忆将使用关键词检索' );
		}
		if ( '' === $settings['api_key'] ) {
			return new WP_Error( 'tianma_no_key', '尚未配置模型 API Key' );
		}

		// 嵌入接口熔断：当前服务商不支持嵌入（如 DeepSeek 无 embedding API）时，
		// 60 分钟内直接快速降级，不再每次对话都白调一次失败请求
		if ( get_transient( 'tianma_embed_down' ) ) {
			return new WP_Error( 'tianma_no_embed', '当前模型服务商不支持向量嵌入（已临时停用，记忆使用关键词检索）' );
		}

		$texts = array_map( 'strval', (array) $texts );
		if ( ! $texts ) {
			return array();
		}

		// 单条查询向量缓存（1 小时），避免重复问题重复调用嵌入 API
		$cache_key = null;
		if ( 1 === count( $texts ) ) {
			$cache_key = 'tianma_embed_' . md5( $texts[0] );
			$cached    = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return array( 0 => $cached );
			}
		}

		$resp = wp_remote_post(
			$this->endpoint( 'embeddings' ),
			array(
				'timeout' => 60,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $settings['api_key'],
				),
				'body'    => wp_json_encode( array(
					'model' => $settings['embedding_model'],
					'input' => $texts,
				) ),
			)
		);

		if ( is_wp_error( $resp ) ) {
			set_transient( 'tianma_embed_down', 1, HOUR_IN_SECONDS );
			return new WP_Error( 'tianma_http', $resp->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( $code >= 400 || empty( $data['data'] ) ) {
			// 4xx/5xx（如 DeepSeek 404 无嵌入接口）→ 熔断 1 小时，避免每次对话白调
			if ( $code >= 400 ) {
				set_transient( 'tianma_embed_down', 1, HOUR_IN_SECONDS );
			}
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'tianma_api', $msg );
		}

		$out = array();
		foreach ( $data['data'] as $item ) {
			$out[ $item['index'] ] = $item['embedding'];
		}

		if ( $cache_key && isset( $out[0] ) ) {
			set_transient( $cache_key, $out[0], HOUR_IN_SECONDS );
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * 测试连接
	 * ------------------------------------------------------------------- */

	public function test_connection( $provider = '', $base_url = '', $api_key = '', $model = '' ) {
		if ( 'mock' === $provider ) {
			return true;
		}
		if ( '' === $api_key || '' === $base_url ) {
			return false;
		}

		// Claude 原生端点测试
		if ( 'claude' === $provider || false !== strpos( $base_url, 'api.anthropic.com' ) ) {
			$base = rtrim( $base_url, '/' );
			if ( substr( $base, -3 ) === '/v1' ) {
				$base = substr( $base, 0, -3 );
			}
			$resp = wp_remote_post(
				$base . '/v1/messages',
				array(
					'timeout' => 30,
					'headers' => array(
						'Content-Type'      => 'application/json',
						'x-api-key'         => $api_key,
						'anthropic-version' => '2023-06-01',
					),
					'body' => wp_json_encode( array(
						'model'      => $model,
						'messages'   => array( array( 'role' => 'user', 'content' => 'ping' ) ),
						'max_tokens' => 5,
					) ),
				)
			);
			if ( is_wp_error( $resp ) ) {
				return false;
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
			return $code < 400;
		}

		$base = rtrim( $base_url, '/' );
		// 已含完整端点则直接用，否则补上（绝不重复拼接）
		$endpoint = ( substr( $base, -17 ) === '/chat/completions' ) ? $base : $base . '/chat/completions';

		$resp = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body' => wp_json_encode( array(
					'model'       => $model,
					'messages'    => array( array( 'role' => 'user', 'content' => 'ping' ) ),
					'max_tokens'  => 5,
				) ),
			)
		);

		if ( is_wp_error( $resp ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		return $code < 400;
	}

	/* ---------------------------------------------------------------------
	 * 演示模式：模拟模型行为（无 Key 时验证全链路）
	 * 根据用户消息关键词触发演示工具调用，以便验证 Agent 循环
	 * ------------------------------------------------------------------- */

	private function mock_chat( $messages ) {
		$last_user = '';
		$total_in  = 0;
		foreach ( $messages as $m ) {
			$len = is_string( $m['content'] ) ? mb_strlen( $m['content'] ) : 0;
			$total_in += $len;
			if ( 'user' === $m['role'] && is_string( $m['content'] ) ) {
				$last_user = $m['content'];
			}
		}
		$est_prompt = (int) ceil( $total_in / 2 );

		// 如果上一条是工具结果，则直接总结
		$last = end( $messages );
		if ( $last && 'tool' === $last['role'] ) {
			$reply = '已为你完成操作。演示模式返回：' . substr( $last['content'], 0, 200 ) . '（配置真实模型 API Key 后可获得完整能力）';
			return array(
				'content'    => $reply,
				'tool_calls' => array(),
				'usage'      => $this->extract_usage( array(
					'prompt_tokens'     => $est_prompt,
					'completion_tokens' => (int) ceil( mb_strlen( $reply ) / 2 ),
				) ),
			);
		}

		$tool_call = $this->mock_match( $last_user );

		if ( $tool_call ) {
			return array(
				'content'    => '',
				'tool_calls' => array( $tool_call ),
				'usage'      => $this->extract_usage( array(
					'prompt_tokens'     => $est_prompt,
					'completion_tokens' => 8,
				) ),
			);
		}

		$reply = $this->mock_reply( $last_user );
		return array(
			'content'    => $reply,
			'tool_calls' => array(),
			'usage'      => $this->extract_usage( array(
				'prompt_tokens'     => $est_prompt,
				'completion_tokens' => (int) ceil( mb_strlen( $reply ) / 2 ),
			) ),
		);
	}

	private function mock_match( $text ) {
		$t = mb_strtolower( $text );

		$has_create = ( false !== mb_strpos( $t, '发布' ) || false !== mb_strpos( $t, '创建' ) || false !== mb_strpos( $t, '写一' ) || false !== mb_strpos( $t, '写篇' ) );
		$has_list   = ( false !== mb_strpos( $t, '列表' ) || false !== mb_strpos( $t, '查看' ) || false !== mb_strpos( $t, '查询' ) || false !== mb_strpos( $t, '有什么' ) );
		$has_delete = ( false !== mb_strpos( $t, '删除' ) );
		$has_post   = ( false !== mb_strpos( $t, '文章' ) || false !== mb_strpos( $t, 'post' ) );
		$has_page   = ( false !== mb_strpos( $t, '页面' ) );

		if ( $has_create && $has_post ) {
			return array(
				'id'        => 'call_mock_1',
				'name'      => 'create_post',
				'arguments' => wp_json_encode( array(
					'title'   => '演示文章：天马智能体生成的标题',
					'content' => '这是一篇由天马智能体在演示模式下生成的文章内容，用于验证工具调用链路是否正常。',
					'status'  => 'draft',
				) ),
			);
		}
		if ( $has_create && $has_page ) {
			return array(
				'id'        => 'call_mock_2',
				'name'      => 'create_page',
				'arguments' => wp_json_encode( array( 'title' => '关于我们（演示）', 'content' => '示例页面内容' ) ),
			);
		}
		if ( $has_list ) {
			return array(
				'id'        => 'call_mock_3',
				'name'      => 'list_posts',
				'arguments' => wp_json_encode( array( 'number' => 5 ) ),
			);
		}
		if ( $has_delete ) {
			return array(
				'id'        => 'call_mock_4',
				'name'      => 'delete_post',
				'arguments' => wp_json_encode( array( 'post_id' => 1 ) ),
			);
		}
		return null;
	}

	private function mock_reply( $text ) {
		$t = mb_strtolower( $text );
		if ( false !== mb_strpos( $t, '你好' ) || false !== mb_strpos( $t, 'hello' ) ) {
			return '你好，我是天马——内置在这个 WordPress 站点的 AI 智能体。你可以让我发布文章、管理插件主题、编辑文件代码、维护网站等。试试对我说「帮我发布一篇测试文章」或「查看最近的文章列表」。';
		}
		if ( false !== mb_strpos( $t, '能力' ) || false !== mb_strpos( $t, '会什么' ) || false !== mb_strpos( $t, '帮助' ) ) {
			return '我可以：1) 发布/编辑/删除文章与页面；2) 管理主题与插件（安装、启用、改代码）；3) 读写站点文件；4) 系统设置、备份、日志分析。配置模型 API Key 后即可获得完整智能体能力。';
		}
		return '收到你的消息。演示模式会自动识别一些关键词（如「发布文章」「查看列表」）来演示工具调用。建议前往「模型设置」配置真实的模型 API Key，解锁完整能力。';
	}
}
