<?php
/**
 * Agent 核心：ReAct 执行循环
 *
 * 理解意图 → 规划 → 调用工具 → 观察结果 → 循环直至完成。
 * 高危工具调用会挂起并生成确认码，用户批准后续跑。
 * 任务结束后自动沉淀记忆（自学习）。
 *
 * @package Tianma
 */

defined( 'ABSPATH' ) || exit;

class Tianma_Agent {

	private $settings;
	private $memory;

	/** 无头模式（定时任务触发）：不弹出交互确认。默认 false */
	public $headless = false;

	/** 无头模式下是否允许自动执行高危操作（由定时任务的授权策略决定）。默认 false */
	public $auto_high = false;

	/**
	 * 聊天页「免授权执行」开关：true = 高危操作直接执行（跳过确认卡片，仍记录审计）。
	 * null = 按全局确认策略（confirm_mode）。默认 null。
	 */
	public $auto_confirm = null;

	/** 当前对话使用的角色对象（null = 总调度/默认 Agent） */
	public $role = null;

	public function __construct() {
		$this->settings = Tianma_Settings::get();
		$this->memory   = new Tianma_Memory();
	}

	/** 角色模式的 LLM 覆盖配置（角色配了独立模型时生效） */
	private function role_llm_override() {
		if ( $this->role && ! empty( $this->role->llm_api_key ) ) {
			return array(
				'provider' => $this->role->llm_provider,
				'base_url' => $this->role->llm_base_url,
				'api_key'  => $this->role->llm_api_key,
				'model'    => $this->role->llm_model,
			);
		}
		return array();
	}

	/** 角色模式的工具名单（角色 tools 为空 = 全部工具） */
	private function role_tools() {
		if ( ! $this->role || empty( $this->role->tools ) ) {
			return null; // null = 不限制
		}
		return array_values( array_filter( (array) $this->role->tools, 'is_string' ) );
	}

	/** 判断当前是否需要弹确认卡片（高危 + 非 auto + 无免授权覆盖） */
	private function need_confirm( $risk ) {
		if ( 'high' !== $risk ) {
			return false;
		}
		if ( 'auto' === $this->settings['confirm_mode'] ) {
			return false;
		}
		if ( $this->headless && $this->auto_high ) {
			return false;
		}
		if ( true === $this->auto_confirm ) {
			return false;
		}
		return true;
	}

	/* ---------------------------------------------------------------------
	 * 主入口：处理一条用户消息
	 * ------------------------------------------------------------------- */

	public function run( $message, $history, $user_id = 0 ) {
		@set_time_limit( 150 );
		ini_set( 'max_execution_time', '150' );

		// 功能性角色模式：不对话，直接执行绑定工具输出（如生图助手 → generate_image）
		if ( null !== $this->role && 'functional' === $this->role->role_type ) {
			return $this->run_functional_role( $message, $user_id );
		}

		// 角色模式：工具按角色权限过滤
		$role_tools = $this->role_tools();
		if ( null !== $role_tools ) {
			$core = array_intersect( Tianma_Tools::core_names(), $role_tools );
			$tools = Tianma_Tools::schemas_for_names( array_values( array_unique( array_merge( $core, $role_tools ) ) ) );
			$tools[] = Tianma_Tools::group_use_schema();
		} else {
			$tools = Tianma_Tools::schemas();
		}

		$memories = $this->memory->retrieve( $message, 5 );
		$system   = $this->build_system_prompt( $memories, $message );

		$messages   = array();
		$messages[] = array( 'role' => 'system', 'content' => $system );

		// 注入历史（最多 20 条，且总量不超过预算，防止长对话上下文无限膨胀）
		$history = is_array( $history ) ? $history : array();
		$history = array_slice( $history, -20 );
		$hist_budget = 12000; // 历史总字符预算 ≈ 6k token
		$hist_total  = 0;
		$kept        = array();
		foreach ( array_reverse( $history ) as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['role'], $item['content'] ) ) {
				continue;
			}
			$len = mb_strlen( (string) $item['content'] );
			if ( $hist_total + $len > $hist_budget ) {
				break; // 超出预算，丢弃更早的消息
			}
			$hist_total += $len;
			array_unshift( $kept, $item );
		}
		foreach ( $kept as $item ) {
			$role = in_array( $item['role'], array( 'user', 'assistant' ), true ) ? $item['role'] : 'user';
			$messages[] = array(
				'role'    => $role,
				'content' => mb_substr( (string) $item['content'], 0, 4000 ),
			);
		}

		$messages[] = array( 'role' => 'user', 'content' => mb_substr( $message, 0, 8000 ) );

		return $this->agent_loop( $messages, $tools, $message, $user_id );
	}

	/* ---------------------------------------------------------------------
	 * Agent 循环
	 * ------------------------------------------------------------------- */

	private function agent_loop( $messages, $tools, $summary, $user_id, $start_steps = array(), $start_sigs = array(), $start_groups = array() ) {
		$llm        = new Tianma_LLM( $this->role_llm_override() );
		$max_steps  = (int) $this->settings['max_steps'];
		$steps      = $start_steps;
		$confirm    = null;
		$usage      = array( 'prompt_tokens' => 0, 'completion_tokens' => 0 );
		$call_sigs  = is_array( $start_sigs ) ? $start_sigs : array();
		$active_groups = is_array( $start_groups ) ? $start_groups : array();
		$tools      = $this->build_tools( $active_groups );

		for ( $i = 0; $i < $max_steps; $i++ ) {
			if ( $i > 0 && $max_steps - $i <= 3 ) {
				$messages[] = array(
					'role'    => 'system',
					'content' => '系统提示：任务剩余执行步数已不足，请立即停止调用新工具，基于已完成的操作直接向用户总结结果。',
				);
			}

			$resp = $llm->chat( $messages, $tools );

			if ( is_wp_error( $resp ) ) {
				return $this->finish_task(
					$summary,
					'error',
					$steps,
					$user_id,
					array( 'status' => 'error', 'error' => $resp->get_error_message(), 'usage' => $usage )
				);
			}

			$this->accumulate_usage( $usage, $resp );

			// 无工具调用 → 任务完成
			if ( empty( $resp['tool_calls'] ) ) {
				$text = isset( $resp['content'] ) ? (string) $resp['content'] : '';
				if ( '' === trim( $text ) ) {
					$text = $this->fallback_text( $summary, $steps );
				}
				return $this->finish_task(
					$summary,
					'done',
					$steps,
					$user_id,
					array( 'status' => 'done', 'text' => $text, 'steps' => $this->public_steps( $steps ), 'usage' => $usage )
				);
			}

			// 处理模型可能同时发起的多个工具调用
			foreach ( $resp['tool_calls'] as $tc ) {
				$name = sanitize_key( $tc['name'] );
				$args = $this->parse_tool_args( $tc['arguments'] );

				// 循环熔断：仅拦截「已成功执行」的相同调用；失败调用允许模型重试修正参数。
				// 豁免名单：幂等工具（use_tool_group）不参与熔断。
				$no_break = in_array( $name, array( 'use_tool_group' ), true );
				$sig = '';
				if ( ! $no_break ) {
					$sig = $name . ':' . md5( wp_json_encode( $args ) );
					if ( in_array( $sig, $call_sigs, true ) ) {
						$block_msg = '系统提示：工具 ' . $name . ' 已用相同参数成功执行过，为避免重复操作已跳过。请停止重复调用该工具，直接基于已有结果总结。';
						$steps[] = array( 'tool' => $name, 'args' => $args, 'ok' => false, 'msg' => $block_msg );
						$messages[] = array(
							'role'         => 'assistant',
							'content'      => '',
							'tool_calls'   => array( array(
								'id'       => $tc['id'],
								'type'     => 'function',
								'function' => array( 'name' => $name, 'arguments' => $tc['arguments'] ),
							) ),
						);
						$messages[] = array(
							'role'         => 'tool',
							'tool_call_id' => $tc['id'],
							'content'      => $block_msg,
						);
						continue;
					}
				}

				// 虚拟工具：加载工具组
				if ( 'use_tool_group' === $name ) {
					$result = $this->handle_group_load( $args, $active_groups, $tools );
					$steps[] = array( 'tool' => $name, 'args' => $args, 'ok' => $result['ok'], 'msg' => $result['message'] );
					$messages[] = array(
						'role'         => 'assistant',
						'content'      => '',
						'tool_calls'   => array( array(
							'id'       => $tc['id'],
							'type'     => 'function',
							'function' => array( 'name' => $name, 'arguments' => $tc['arguments'] ),
						) ),
					);
					$messages[] = array(
						'role'         => 'tool',
						'tool_call_id' => $tc['id'],
						'content'      => $result['message'],
					);
					continue;
				}

				$risk = Tianma_Tools::risk( $name );

				// 无头模式（定时任务）：未授权高危 → 自动跳过并告知模型
				if ( $this->headless && 'high' === $risk && ! $this->auto_high ) {
					$block_msg = '【定时任务】该操作属于危险操作（' . $name . '），且此任务未开启「允许自动执行危险操作」，已自动跳过。请勿重试该工具，直接基于已完成的操作总结结果。';
					$steps[] = array( 'tool' => $name, 'args' => $args, 'ok' => false, 'msg' => $block_msg );
					$messages[] = array(
						'role'         => 'assistant',
						'content'      => '',
						'tool_calls'   => array( array(
							'id'       => $tc['id'],
							'type'     => 'function',
							'function' => array( 'name' => $name, 'arguments' => $tc['arguments'] ),
						) ),
					);
					$messages[] = array(
						'role'         => 'tool',
						'tool_call_id' => $tc['id'],
						'content'      => $block_msg,
					);
					continue;
				}

				// 高危操作 → 请求用户确认（无头已授权 / 聊天页免授权时直接执行）
				if ( $this->need_confirm( $risk ) ) {
					$payload = array(
						'tool_name' => $name,
						'tool_args' => $args,
						'tool_id'   => $tc['id'],
						'messages'  => $messages,
						'steps'     => $steps,
						'summary'   => $summary,
						'user_id'   => $user_id,
						'usage'     => $usage,
						'call_sigs' => $call_sigs,
						'active_groups' => $active_groups,
					);
					$confirm = Tianma_Confirm::create( $payload );

					return $this->finish_task(
						$summary,
						'pending',
						$steps,
						$user_id,
						array(
							'status'  => 'needs_confirmation',
							'steps'   => $this->public_steps( $steps ),
							'confirm' => $confirm,
						),
						false
					);
				}

				// 执行工具
				$result = $this->execute_tool( $name, $args, $user_id );
				$steps[] = array(
					'tool'  => $name,
					'args'  => $args,
					'ok'    => $result['ok'],
					'msg'   => $result['message'],
				);

				// 仅成功调用记入熔断签名；失败允许模型重试
				if ( $result['ok'] && ! $no_break && '' !== $sig ) {
					$call_sigs[] = $sig;
				}

				$messages[] = array(
					'role'         => 'assistant',
					'content'      => '',
					'tool_calls'   => array( array(
						'id'       => $tc['id'],
						'type'     => 'function',
						'function' => array( 'name' => $name, 'arguments' => $tc['arguments'] ),
					) ),
				);
				$messages[] = array(
					'role'         => 'tool',
					'tool_call_id' => $tc['id'],
					'content'      => $this->tool_result_text( $result ),
				);
			}
		}

		// 达到步数上限
		$closing = $this->smart_close( $messages, $steps, $summary, $llm );
		return $this->finish_task(
			$summary,
			'done',
			$steps,
			$user_id,
			array(
				'status' => 'done',
				'text'   => $closing,
				'steps'  => $this->public_steps( $steps ),
				'usage'  => $usage,
			)
		);
	}

	/** 累加 token 用量 */
	private function accumulate_usage( &$usage, $resp ) {
		if ( empty( $resp['usage'] ) || ! is_array( $resp['usage'] ) ) {
			return;
		}
		$usage['prompt_tokens']     += (int) ( isset( $resp['usage']['prompt_tokens'] ) ? $resp['usage']['prompt_tokens'] : 0 );
		$usage['completion_tokens'] += (int) ( isset( $resp['usage']['completion_tokens'] ) ? $resp['usage']['completion_tokens'] : 0 );
	}

	/** 组装传给模型的工具结果：message + 具体数据（data 过大时截断） */
	private function tool_result_text( $result ) {
		$text = isset( $result['message'] ) ? $result['message'] : '';
		if ( ! empty( $result['data'] ) ) {
			$json = wp_json_encode( $result['data'], JSON_UNESCAPED_UNICODE );
			if ( mb_strlen( $json ) > 3000 ) {
				$json = mb_substr( $json, 0, 3000 ) . '…（数据过长已截断）';
			}
			$text .= "\n【数据】" . $json;
		}
		return $text;
	}

	/**
	 * 容错解析模型返回的工具参数 JSON。
	 * 处理常见畸形：
	 * 1. 模型把参数整体嵌套在 {"arguments": ...} 里（内层为字符串 JSON 或对象）→ 解包
	 * 2. 空键丢失：{"": "wp-content/plugins/..."}（key 生成丢失）→ 还原为 path
	 * 3. JSON 少量破损（引号/花括号不配对）→ 尽力修复
	 */
	private function parse_tool_args( $arguments ) {
		$args = json_decode( (string) $arguments, true );

		// 嵌套 arguments 解包（内层可能是字符串 JSON，也可能是对象）
		if ( is_array( $args ) && 1 === count( $args ) && array_key_exists( 'arguments', $args ) ) {
			$inner = $args['arguments'];
			if ( is_string( $inner ) ) {
				$decoded = json_decode( $inner, true );
				if ( is_array( $decoded ) ) {
					$args = $decoded;
				}
			} elseif ( is_array( $inner ) ) {
				$args = $inner;
			}
		}

		if ( ! is_array( $args ) ) {
			// 轻度修复：补全缺失的右花括号 / 去掉首尾多余字符后重试
			$raw = trim( (string) $arguments );
			$tries = array(
				$raw,
				$raw . '}',
				preg_replace( '/^[^{]*/', '', $raw ),
				preg_replace( '/^[^{]*/', '', $raw ) . '}',
			);
			foreach ( array_unique( $tries ) as $candidate ) {
				$decoded = json_decode( $candidate, true );
				if ( is_array( $decoded ) ) {
					$args = $decoded;
					break;
				}
			}
		}

		if ( is_array( $args ) ) {
			// 空键修复：{"":"wp-content/plugins/..."} → {"path":"..."}
			foreach ( $args as $k => $v ) {
				if ( '' === trim( (string) $k ) && is_string( $v ) && $this->looks_like_path( $v ) ) {
					$args['path'] = $v;
					unset( $args[ $k ] );
					break;
				}
			}
			// 单值且值形如路径：{ "wp-content/plugins/xxx": "" } 或 {"0":"path值"} → 还原为 path
			if ( ! isset( $args['path'] ) && 1 === count( $args ) ) {
				$v = reset( $args );
				if ( is_string( $v ) && $this->looks_like_path( $v ) ) {
					$args = array( 'path' => $v );
				}
			}
		}

		return is_array( $args ) ? $args : array();
	}

	/** 判断字符串是否形如文件/目录路径 */
	private function looks_like_path( $str ) {
		$str = trim( (string) $str );
		if ( '' === $str ) {
			return false;
		}
		// 相对站点路径 或 Windows 盘符 或 Unix 绝对路径，且含目录分隔
		return (bool) preg_match( '#^(wp-content/|wp-admin/|wp-includes/|wp-config|[A-Za-z]:[\\\\/]|/)#', $str );
	}

	/** 按已激活组构建工具 Schema（核心工具 + use_tool_group + 已加载组），降低 prompt 开销 */
	private function build_tools( $active_groups ) {
		$names = Tianma_Tools::core_names();
		foreach ( (array) $active_groups as $g ) {
			$names = array_merge( $names, Tianma_Tools::group_names( $g ) );
		}
		$tools = Tianma_Tools::schemas_for_names( $names );
		$tools[] = Tianma_Tools::group_use_schema();
		return $tools;
	}

	/** 处理 use_tool_group（虚拟工具：加载工具组，不执行真实操作）。返回 array(ok, message) 或 null（无效组） */
	private function handle_group_load( $args, &$active_groups, &$tools ) {
		$raw = isset( $args['group'] ) ? $args['group'] : '';
		$groups = is_array( $raw ) ? array_map( 'sanitize_key', $raw ) : array( sanitize_key( $raw ) );
		$groups = array_values( array_filter( $groups ) );

		$loaded = array();
		$errors = array();
		foreach ( $groups as $group ) {
			if ( ! Tianma_Tools::valid_group( $group ) ) {
				$errors[] = '未知工具组：' . $group . '（可选：content/file/plugin/system/agent）';
				continue;
			}
			if ( in_array( $group, $active_groups, true ) ) {
				$loaded[] = '「' . Tianma_Tools::group_label( $group ) . '」已加载';
				continue;
			}
			$active_groups[] = $group;
			$loaded[] = '已加载「' . Tianma_Tools::group_label( $group ) . '」';
		}
		if ( $loaded ) {
			$tools = $this->build_tools( $active_groups );
		}
		$msg = implode( '；', $loaded );
		if ( $errors ) {
			$msg .= ( $msg ? '；' : '' ) . implode( '；', $errors );
		}
		return array( 'ok' => (bool) $loaded, 'message' => $msg ? $msg : '未指定工具组' );
	}

	/* ---------------------------------------------------------------------
	 * 流式入口：处理一条用户消息（SSE 实时推送进度与内容）
	 * $emitter( $event, $payload ) 由调用方输出
	 * 事件：delta / tool_start / tool_done / confirm / done / error / thinking
	 * ------------------------------------------------------------------- */

	public function run_stream( $message, $history, $user_id = 0, $emitter = null ) {
		@set_time_limit( 200 );
		ini_set( 'max_execution_time', '200' );

		$emitter = is_callable( $emitter ) ? $emitter : function () {};

		// 功能性角色模式：直接执行绑定工具，结果以 done 事件输出
		if ( null !== $this->role && 'functional' === $this->role->role_type ) {
			$res = $this->run_functional_role( $message, $user_id );
			$emitter( 'delta', array( 'text' => $res['text'] ) );
			$emitter( 'done', $res );
			return $res;
		}

		$tools    = Tianma_Tools::schemas();
		$memories = $this->memory->retrieve( $message, 5 );
		$system   = $this->build_system_prompt( $memories, $message );

		$messages   = array();
		$messages[] = array( 'role' => 'system', 'content' => $system );

		$history = is_array( $history ) ? $history : array();
		$history = array_slice( $history, -20 );
		foreach ( $history as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['role'], $item['content'] ) ) {
				continue;
			}
			$role = in_array( $item['role'], array( 'user', 'assistant' ), true ) ? $item['role'] : 'user';
			$messages[] = array(
				'role'    => $role,
				'content' => mb_substr( (string) $item['content'], 0, 4000 ),
			);
		}
		$messages[] = array( 'role' => 'user', 'content' => mb_substr( $message, 0, 8000 ) );

		return $this->agent_loop_stream( $messages, $tools, $message, $user_id, $emitter );
	}

	private function agent_loop_stream( $messages, $tools, $summary, $user_id, $emitter ) {
		$llm       = new Tianma_LLM( $this->role_llm_override() );
		$max_steps = (int) $this->settings['max_steps'];
		$steps     = array();
		$usage     = array( 'prompt_tokens' => 0, 'completion_tokens' => 0 );
		$call_sigs = array(); // 循环熔断：已执行过的工具调用签名
		$consecutive_fail = 0;
		$active_groups = array();
		$tools = $this->build_tools( $active_groups );

		for ( $i = 0; $i < $max_steps; $i++ ) {
			// 接近上限：提示模型收敛
			if ( $i > 0 && $max_steps - $i <= 3 ) {
				$messages[] = array(
					'role'    => 'system',
					'content' => '系统提示：任务剩余执行步数已不足，请立即停止调用新工具，基于已完成的操作直接向用户总结结果。',
				);
			}

			$emitter( 'thinking', array( 'step' => $i + 1 ) );

			$resp = $llm->stream_chat(
				$messages,
				$tools,
				function ( $txt ) use ( $emitter ) {
					$emitter( 'delta', array( 'text' => $txt ) );
				},
				function ( $txt ) use ( $emitter ) {
					$emitter( 'reasoning', array( 'text' => $txt ) );
				}
			);

			if ( is_wp_error( $resp ) ) {
				$emitter( 'error', array( 'message' => $resp->get_error_message() ) );
				return $this->finish_task( $summary, 'error', $steps, $user_id, array( 'status' => 'error', 'error' => $resp->get_error_message(), 'steps' => $this->public_steps( $steps ), 'usage' => $usage ) );
			}

			$this->accumulate_usage( $usage, $resp );

			// 无工具调用 → 完成
			if ( empty( $resp['tool_calls'] ) ) {
				$text = isset( $resp['content'] ) ? (string) $resp['content'] : '';
				if ( '' === trim( $text ) ) {
					$text = $this->fallback_text( $summary, $steps );
				}
				$done = $this->finish_task(
					$summary,
					'done',
					$steps,
					$user_id,
					array(
						'status' => 'done',
						'text'   => $text,
						'steps'  => $this->public_steps( $steps ),
						'usage'  => $usage,
					)
				);
				$emitter( 'done', $done );
				return $done;
			}

			// 工具调用（可能多个）
			foreach ( $resp['tool_calls'] as $tc ) {
				$name = sanitize_key( $tc['name'] );
				$args = $this->parse_tool_args( $tc['arguments'] );

				// 循环熔断：仅拦截「已成功执行」的相同写操作（防重复创建/删除）；
				// 只读工具与 use_tool_group 完全豁免（无副作用，允许重复查询）。
				$no_break = 'use_tool_group' === $name || Tianma_Tools::is_read_only( $name );
				$sig = '';
				if ( ! $no_break ) {
					$sig = $name . ':' . md5( wp_json_encode( $args ) );
					if ( in_array( $sig, $call_sigs, true ) ) {
						$block_msg = '系统提示：工具 ' . $name . ' 已用相同参数成功执行过，为避免重复操作已跳过。请停止重复调用该工具，直接基于已有结果总结。';
						$steps[] = array( 'tool' => $name, 'args' => $args, 'ok' => false, 'msg' => $block_msg );
						$emitter( 'tool_done', array( 'tool' => $name, 'ok' => false, 'message' => $block_msg ) );
						$messages[] = array(
							'role'         => 'assistant',
							'content'      => '',
							'tool_calls'   => array( array(
								'id'       => $tc['id'],
								'type'     => 'function',
								'function' => array( 'name' => $name, 'arguments' => $tc['arguments'] ),
							) ),
						);
						$messages[] = array(
							'role'         => 'tool',
							'tool_call_id' => $tc['id'],
							'content'      => $block_msg,
						);
						continue;
					}
				}

				// 虚拟工具：加载工具组（不执行真实操作，加载后下一轮可用组内工具）
				if ( 'use_tool_group' === $name ) {
					$result = $this->handle_group_load( $args, $active_groups, $tools );
					$steps[] = array( 'tool' => $name, 'args' => $args, 'ok' => $result['ok'], 'msg' => $result['message'] );
					$emitter( 'tool_done', array( 'tool' => $name, 'ok' => $result['ok'], 'message' => $result['message'] ) );
					$messages[] = array(
						'role'         => 'assistant',
						'content'      => '',
						'tool_calls'   => array( array(
							'id'       => $tc['id'],
							'type'     => 'function',
							'function' => array( 'name' => $name, 'arguments' => $tc['arguments'] ),
						) ),
					);
					$messages[] = array(
						'role'         => 'tool',
						'tool_call_id' => $tc['id'],
						'content'      => $result['message'],
					);
					continue;
				}

				$risk = Tianma_Tools::risk( $name );

				// 无头模式（定时任务）：未授权高危 → 自动跳过并告知模型
				if ( $this->headless && 'high' === $risk && ! $this->auto_high ) {
					$block_msg = '【定时任务】该操作属于危险操作（' . $name . '），且此任务未开启「允许自动执行危险操作」，已自动跳过。请勿重试该工具，直接基于已完成的操作总结结果。';
					$steps[] = array( 'tool' => $name, 'args' => $args, 'ok' => false, 'msg' => $block_msg );
					$emitter( 'tool_done', array( 'tool' => $name, 'ok' => false, 'message' => $block_msg ) );
					$messages[] = array(
						'role'         => 'assistant',
						'content'      => '',
						'tool_calls'   => array( array(
							'id'       => $tc['id'],
							'type'     => 'function',
							'function' => array( 'name' => $name, 'arguments' => $tc['arguments'] ),
						) ),
					);
					$messages[] = array(
						'role'         => 'tool',
						'tool_call_id' => $tc['id'],
						'content'      => $block_msg,
					);
					continue;
				}

				// 高危操作 → 请求用户确认（无头已授权 / 聊天页免授权时直接执行）
				if ( $this->need_confirm( $risk ) ) {
					$payload = array(
						'tool_name' => $name,
						'tool_args' => $args,
						'tool_id'   => $tc['id'],
						'messages'  => $messages,
						'steps'     => $steps,
						'summary'   => $summary,
						'user_id'   => $user_id,
						'usage'     => $usage,
						'call_sigs' => $call_sigs,
						'active_groups' => $active_groups,
					);
					$confirm = Tianma_Confirm::create( $payload );
					$emitter( 'confirm', array( 'confirm' => $confirm, 'steps' => $this->public_steps( $steps ) ) );
					return $this->finish_task( $summary, 'pending', $steps, $user_id, array(
						'status'  => 'needs_confirmation',
						'steps'   => $this->public_steps( $steps ),
						'confirm' => $confirm,
					), false );
				}

				$emitter( 'tool_start', array( 'tool' => $name, 'args' => $args ) );

				$result = $this->execute_tool( $name, $args, $user_id );
				$steps[] = array( 'tool' => $name, 'args' => $args, 'ok' => $result['ok'], 'msg' => $result['message'] );

				// 仅成功调用记入熔断签名；失败允许模型重试
				if ( $result['ok'] && ! $no_break && '' !== $sig ) {
					$call_sigs[] = $sig;
				}

				$consecutive_fail = $result['ok'] ? 0 : $consecutive_fail + 1;

				// 统一可视化事件：把工具执行的关键数据附给前端渲染卡片（读取/扫描/写入/创建/删除等），零额外 token
				$emit_data = array( 'tool' => $name, 'ok' => $result['ok'], 'message' => mb_substr( $result['message'], 0, 300 ) );
				if ( $result['ok'] ) {
					$d = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
					if ( in_array( $name, array( 'file_write', 'create_plugin_skel', 'create_theme_skel' ), true ) && ! empty( $d['path'] ) ) {
						$emit_data['visual'] = array(
							'type'    => 'file_edit',
							'path'    => $d['path'],
							'content' => isset( $d['content'] ) ? $d['content'] : '',
						);
					} elseif ( 'file_read' === $name && ! empty( $d['path'] ) ) {
						$emit_data['visual'] = array(
							'type'    => 'file_read',
							'path'    => $d['path'],
							'content' => isset( $d['content'] ) ? $d['content'] : '',
						);
					} elseif ( 'file_list' === $name && ! empty( $d ) ) {
						$emit_data['visual'] = array(
							'type'  => 'file_list',
							'path'  => isset( $args['path'] ) ? (string) $args['path'] : '',
							'items' => $d,
						);
					} elseif ( 'generate_image' === $name && ! empty( $d['image_url'] ) ) {
						$emit_data['visual'] = array(
							'type'   => 'image',
							'url'    => $d['image_url'],
							'prompt' => isset( $d['prompt'] ) ? $d['prompt'] : '',
						);
					} elseif ( 'worklog_read' === $name && isset( $d['content'] ) ) {
						$emit_data['visual'] = array(
							'type'    => 'worklog',
							'day'     => isset( $d['day'] ) ? $d['day'] : ( isset( $d['days'] ) ? '最近 ' . $d['days'] . ' 天' : '' ),
							'content' => $d['content'],
						);
					} elseif ( in_array( $name, array( 'worklog_append', 'worklog_update' ), true ) ) {
						$emit_data['visual'] = array(
							'type'  => 'result',
							'icon'  => '📝',
							'title' => $result['message'],
						);
					} elseif ( 'worklog_delete' === $name ) {
						$emit_data['visual'] = array(
							'type'  => 'result',
							'icon'  => '🗑',
							'title' => $result['message'],
						);
					} elseif ( in_array( $name, array( 'file_create_dir', 'file_rename', 'file_delete', 'validate_php' ), true ) ) {
						$emit_data['visual'] = array(
							'type'  => 'result',
							'icon'  => 'file_create_dir' === $name ? '📁' : ( 'file_rename' === $name ? '✏️' : ( 'file_delete' === $name ? '🗑' : '✅' ) ),
							'title' => $result['message'],
						);
					}
				}
				$emitter( 'tool_done', $emit_data );

				$messages[] = array(
					'role'         => 'assistant',
					'content'      => '',
					'tool_calls'   => array( array(
						'id'       => $tc['id'],
						'type'     => 'function',
						'function' => array( 'name' => $name, 'arguments' => $tc['arguments'] ),
					) ),
				);
				$messages[] = array(
					'role'         => 'tool',
					'tool_call_id' => $tc['id'],
					'content'      => $this->tool_result_text( $result ),
				);

				// 连续失败熔断：同一任务连续 4 次工具失败 → 强制收尾
				if ( $consecutive_fail >= 4 ) {
					$closing = '连续多次工具执行失败，任务中止。最近失败：' . mb_substr( $result['message'], 0, 200 );
					$done = $this->finish_task( $summary, 'done', $steps, $user_id, array(
						'status' => 'done',
						'text'   => $closing,
						'steps'  => $this->public_steps( $steps ),
						'usage'  => $usage,
					) );
					$emitter( 'done', $done );
					return $done;
				}
			}
		}

		// 达到步数上限：让模型基于已执行步骤做智能总结，而非生硬报错
		$closing = $this->smart_close( $messages, $steps, $summary, $llm );
		$done = $this->finish_task( $summary, 'done', $steps, $user_id, array(
			'status' => 'done',
			'text'   => $closing,
			'steps'  => $this->public_steps( $steps ),
			'usage'  => $usage,
		) );
		$emitter( 'done', $done );
		return $done;
	}

	/** 达到步数上限时的智能收尾总结 */
	private function smart_close( $messages, $steps, $summary, $llm ) {
		$step_text = '';
		foreach ( $steps as $s ) {
			$step_text .= '- 工具 ' . ( isset( $s['tool'] ) ? $s['tool'] : '?' ) . '：' . mb_substr( ( isset( $s['msg'] ) ? $s['msg'] : '' ), 0, 120 ) . "\n";
		}

		$resp = $llm->chat(
			array(
				array(
					'role'    => 'system',
					'content' => '你是一个 WordPress 智能体助手。任务因执行步数达到上限而结束，请根据已执行的步骤，用中文向用户简明总结：已完成什么、关键结果、以及可选的下一步建议。不要虚构未执行的操作。',
				),
				array(
					'role'    => 'user',
					'content' => '任务：' . $summary . "\n已执行步骤：\n" . $step_text . "\n请总结。",
				),
			),
			array(),
			0.4
		);

		if ( is_wp_error( $resp ) || empty( $resp['content'] ) ) {
			return '任务执行达到步数上限。已完成 ' . count( $steps ) . ' 个工具操作，如需继续可以告诉我下一步。';
		}
		return $resp['content'];
	}

	/* ---------------------------------------------------------------------
	 * 高危确认后续跑
	 * ------------------------------------------------------------------- */

	public function resume_after_confirm( $hash, $approve, $user_id = 0 ) {
		$pending = Tianma_Confirm::get( $hash );
		if ( ! $pending ) {
			return new WP_Error( 'tianma_confirm_expired', '确认请求不存在或已过期' );
		}
		$payload = $pending['payload'];

		$messages = isset( $payload['messages'] ) ? $payload['messages'] : array();
		$steps    = isset( $payload['steps'] ) ? $payload['steps'] : array();
		$summary  = isset( $payload['summary'] ) ? $payload['summary'] : '任务';
		$tool_id  = isset( $payload['tool_id'] ) ? $payload['tool_id'] : '';
		$name     = isset( $payload['tool_name'] ) ? $payload['tool_name'] : '';
		$args     = isset( $payload['tool_args'] ) ? $payload['tool_args'] : array();
		$usage    = isset( $payload['usage'] ) && is_array( $payload['usage'] ) ? $payload['usage'] : array( 'prompt_tokens' => 0, 'completion_tokens' => 0 );

		if ( $approve ) {
			// 用户批准 → 执行
			$result = $this->execute_tool( $name, $args, $user_id );
			$steps[] = array( 'tool' => $name, 'args' => $args, 'ok' => $result['ok'], 'msg' => $result['message'] );
			$tool_content = $this->tool_result_text( $result );
			Tianma_Confirm::resolve( $hash, 'approved' );
		} else {
			// 用户拒绝 → 告知模型，让其调整方案
			$steps[] = array( 'tool' => $name, 'args' => $args, 'ok' => false, 'msg' => '用户拒绝了该高危操作' );
			$tool_content = '用户拒绝了此操作（' . $name . '），请调整方案，选择其他安全的做法，或向用户说明后果并征询替代方案。';
			Tianma_Confirm::resolve( $hash, 'rejected' );
		}

		$messages[] = array(
			'role'         => 'assistant',
			'content'      => '',
			'tool_calls'   => array( array(
				'id'       => $tool_id,
				'type'     => 'function',
				'function' => array( 'name' => $name, 'arguments' => wp_json_encode( $args, JSON_UNESCAPED_UNICODE ) ),
			) ),
		);
		$messages[] = array(
			'role'         => 'tool',
			'tool_call_id' => $tool_id,
			'content'      => $tool_content,
		);

		$tools = Tianma_Tools::schemas();
		$resp  = $this->agent_loop(
			$messages,
			$tools,
			$summary,
			$user_id,
			$steps,
			isset( $payload['call_sigs'] ) && is_array( $payload['call_sigs'] ) ? $payload['call_sigs'] : array(),
			isset( $payload['active_groups'] ) && is_array( $payload['active_groups'] ) ? $payload['active_groups'] : array()
		);
		if ( is_array( $resp ) && isset( $resp['usage'] ) ) {
			$resp['usage']['prompt_tokens']     += $usage['prompt_tokens'];
			$resp['usage']['completion_tokens'] += $usage['completion_tokens'];
		}
		return $resp;
	}

	/**
	 * 功能性角色执行：不对话，直接把用户消息作为参数执行绑定工具并返回结果。
	 * 例如「生图助手」绑定 generate_image → 用户说"画一只猫" → 直接调用出图。
	 */
	private function run_functional_role( $message, $user_id ) {
		$bind_tool = $this->role->bind_tool ? $this->role->bind_tool : '';
		if ( ! $bind_tool || ! in_array( $bind_tool, Tianma_Tools::names(), true ) ) {
			return array(
				'status' => 'error',
				'error'  => '功能性角色「' . $this->role->name . '」未绑定有效的输出工具（bind_tool）',
				'text'   => '功能性角色「' . $this->role->name . '」未绑定有效的输出工具（bind_tool）',
				'steps'  => array(),
			);
		}
		// 角色独立配置作为工具执行凭据（如生图助手用自己的 Key/模型出图），未配置则用全局
		$tool_args = array( 'prompt' => $message );
		if ( Tianma_Role::has_own_llm( $this->role ) ) {
			if ( ! empty( $this->role->llm_base_url ) ) { $tool_args['base_url'] = $this->role->llm_base_url; }
			if ( ! empty( $this->role->llm_api_key ) )  { $tool_args['api_key'] = $this->role->llm_api_key; }
			if ( ! empty( $this->role->llm_model ) )    { $tool_args['model'] = $this->role->llm_model; }
		}
		$res = Tianma_Tools::execute( $bind_tool, $tool_args );
		if ( ! $res['ok'] && false !== strpos( $res['message'], '缺少必填' ) ) {
			// 工具没有 prompt 参数 → 把用户消息作为唯一参数直接执行
			$res = Tianma_Tools::execute( $bind_tool, array( $message ) );
		}
		return array(
			'status' => $res['ok'] ? 'done' : 'error',
			'text'   => isset( $res['message'] ) ? $res['message'] : ( $res['ok'] ? '执行完成' : '执行失败' ),
			'error'  => $res['ok'] ? '' : ( isset( $res['message'] ) ? $res['message'] : '执行失败' ),
			'steps'  => array( array( 'tool' => $bind_tool, 'args' => $tool_args, 'ok' => $res['ok'], 'msg' => isset( $res['message'] ) ? $res['message'] : '' ) ),
		);
	}

	/* ---------------------------------------------------------------------
	 * 工具执行 + 审计
	 * ------------------------------------------------------------------- */

	private function execute_tool( $name, $args, $user_id ) {
		$result = Tianma_Tools::execute( $name, $args );

		Tianma_Audit::log(
			$name,
			array(
				'args'    => $args,
				'ok'      => $result['ok'],
				'message' => mb_substr( $result['message'], 0, 500 ),
			),
			$user_id
		);
		return $result;
	}

	/* ---------------------------------------------------------------------
	 * 任务收尾：记录 + 学习
	 * ------------------------------------------------------------------- */

	/** 模型未返回任何回复文本时的兜底总结（避免空消息入库） */
	private function fallback_text( $summary, $steps ) {
		$steps = is_array( $steps ) ? $steps : array();
		if ( empty( $steps ) ) {
			return '已收到您的请求。';
		}
		$tools = array();
		foreach ( $steps as $s ) {
			if ( is_array( $s ) && isset( $s['tool'] ) && ! in_array( $s['tool'], $tools, true ) ) {
				$tools[] = $s['tool'];
			}
		}
		$ok = 0;
		foreach ( $steps as $s ) {
			if ( is_array( $s ) && ! empty( $s['ok'] ) ) {
				$ok++;
			}
		}
		$msg = '任务「' . $summary . '」已执行完毕';
		if ( $ok ) {
			$msg .= '，成功完成 ' . $ok . ' 项操作';
		}
		if ( count( $steps ) ) {
			$msg .= '（共 ' . count( $steps ) . ' 步，涉及：' . implode( '、', array_slice( $tools, 0, 6 ) ) . ( count( $tools ) > 6 ? ' 等' : '' ) . '）';
		}
		return $msg . '。';
	}

	private function finish_task( $summary, $status, $steps, $user_id, $response, $learn = true ) {
		global $wpdb;

		$tool_count = count( $steps );
		$wpdb->insert(
			$wpdb->prefix . 'tianma_tasks',
			array(
				'user_id'    => $user_id ? (int) $user_id : get_current_user_id(),
				'summary'    => mb_substr( $summary, 0, 255 ),
				'status'     => $status,
				'step_count' => count( $steps ),
				'tool_count' => $tool_count,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s' )
		);
		$task_id = (int) $wpdb->insert_id;
		$response['task_id'] = $task_id;

		// 工作日志自动沉淀：执行过工具的任务结束后写入当天日志（跨对话可查）
		Tianma_Worklog::auto_log( $summary, $status, $steps );

		if ( $learn && in_array( $status, array( 'done', 'error' ), true ) ) {
			$this->memory->learn( array(
				'summary' => $summary,
				'steps'   => $steps,
				'status'  => $status,
			) );

			// 技能自动提炼：成功任务 → 新技能加入能力库
			if ( 'done' === $status && count( $steps ) >= 2 ) {
				Tianma_Skill::learn_from_task( $summary, $steps );
			}
		}

		return $response;
	}

	/* ---------------------------------------------------------------------
	 * 工具步骤脱敏（给前端展示，避免超大内容）
	 * ------------------------------------------------------------------- */

	private function public_steps( $steps ) {
		$out = array();
		foreach ( $steps as $s ) {
			$arg_text = wp_json_encode( $s['args'], JSON_UNESCAPED_UNICODE );
			$out[] = array(
				'tool'   => $s['tool'],
				'ok'     => (bool) $s['ok'],
				'args'   => mb_substr( $arg_text, 0, 200 ),
				'message'=> mb_substr( $s['msg'], 0, 200 ),
			);
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * System Prompt
	 * ------------------------------------------------------------------- */

	private function build_system_prompt( $memories, $message = '' ) {
		$settings = Tianma_Settings::get();

		// 角色模式：以角色身份运行（角色自己的行为风格 + 职责）
		if ( $this->role ) {
			$role_prompt = trim( (string) $this->role->system_prompt );
			if ( '' === $role_prompt ) {
				$role_prompt = '你是角色「' . $this->role->name . '」：' . $this->role->description;
			}
			$prompt  = $role_prompt . "\n\n";
			$prompt .= "你是 WordPress 站点内的一位 AI 能力角色，通过工具完成你的职责范围内的工作。\n";
			$prompt .= "当前站点信息：\n";
			$prompt .= "- 站点名称：" . get_bloginfo( 'name' ) . "\n";
			$prompt .= "- 站点地址：" . home_url() . "\n";
			$prompt .= "- WordPress 版本：" . get_bloginfo( 'version' ) . "\n\n";
			$prompt .= "工作方式：\n";
			$prompt .= "1. 理解用户意图，规划步骤，调用工具完成任务；工具按你的角色权限提供。\n";
			$prompt .= "2. 涉及删除、覆盖等危险操作时正常调用工具——系统会先向用户请求确认。\n";
			$prompt .= "3. 任务完成后用中文清晰总结：做了什么、关键结果。\n";
			$prompt .= "4. 如果任务超出你的职责或工具范围，如实说明，可建议用户切换回总调度或发起多角色协作。\n\n";
			return $prompt;
		}

		$prompt  = "你是「天马」，一个内置于 WordPress 站点的 AI 智能体助手。你可以通过工具完成站点内容管理、主题插件管理、文件代码编辑、系统维护等任务。\n\n";
		$prompt .= "当前站点信息：\n";
		$prompt .= "- 站点名称：" . get_bloginfo( 'name' ) . "\n";
		$prompt .= "- 站点地址：" . home_url() . "\n";
		$prompt .= "- WordPress 版本：" . get_bloginfo( 'version' ) . "\n\n";

		$prompt .= "工作方式（重要）：\n";
		$prompt .= "1. 先理解用户意图，规划步骤，再调用工具，观察结果后决定下一步，直到任务完成。\n";
		$prompt .= "2. 复杂任务拆解为多个工具调用逐步完成；不要一次性编造未执行的结果。\n";
		$prompt .= "3. 涉及删除、覆盖、修改数据库等危险操作时，正常发起工具调用即可——系统会先向用户请求确认，用户批准后才会真正执行。\n";
		$prompt .= "4. 工具返回的 message 字段是执行结果说明，务必阅读后再决定下一步。\n";
		$prompt .= "5. 发布类操作默认创建草稿，除非用户明确要求直接发布。\n";
		$prompt .= "6. 任务完成后，用中文向用户清晰总结：做了什么、关键结果、以及后续建议。\n";
		$prompt .= "7. 如果用户请求超出你的能力范围，如实告知，不要虚构。\n";
		$prompt .= "8. 调用角色（重要）：用户需求匹配到某个角色时，优先用轻量的 invoke_role 工具直接让该角色执行（功能性角色直接出结果、聊天型角色以该角色身份干活），快速高效、无需确认。只有任务确实需要多个角色分工协作时，才用 start_collaboration 发起多角色协作（会请求确认，多阶段执行后汇总）。\n";
		$prompt .= "9. 当用户让你『记住某个流程/方法/技能』时，使用 create_skill 工具将其固化到技能库。\n";
		$prompt .= "10. 当用户让你『新建/创建一个角色』时，使用 create_role 工具，并明确角色的名称、职责、行为风格与可用工具；需要为角色单独配置模型时，用 create_role/update_role 的 llm_provider、llm_model、llm_api_key 参数；查询任何角色信息（含模型配置）用 list_roles 即可，不要读代码文件。\n";
		$prompt .= "11. 高效原则（重要）：只调用完成任务必需的工具；同一工具不要重复调用（除非参数有实质变化）；查询类信息（如列表）调用一次即可，不要反复查询；任务信息足够后立即停止调用工具并总结。\n";
		$prompt .= "12. 工具按需加载：核心查询工具（列表/详情/站点信息等）可直接使用；当任务需要专业操作时（如创建/编辑文章、上传媒体、修改文件、管理插件主题、修改设置、创建角色技能、发起协作），先调用 use_tool_group 加载对应工具组（content/file/plugin/system/agent），加载后即可使用该组内工具。不要尝试直接调用尚未加载的工具。\n";
		$prompt .= "13. 配置插件/主题/站点设置时：用 list_options 或 get_option 查看相关选项（这些是 WordPress 的 wp_options 配置项，插件配置大多存于此）；需要修改时加载 system 组并使用 set_option（修改前会备份，执行前会请求用户确认）。\n";
		$prompt .= "14. 定时任务（重要）：当用户要求『定时/周期/每天/每周/每小时/定期』执行某任务时（如每天备份数据库、每周一发布文章、每小时检查一次更新），先加载 agent 组，然后用 schedule_create 创建定时任务（指定周期与执行指令）。定时任务到点后会自动唤醒你执行，无需用户在线。可用 schedule_list 查看、schedule_update 修改、schedule_delete 删除、schedule_run_now 立即执行。创建时注意：危险操作默认不会被自动执行（除非 auto_high=true），请向用户说明这一点。\n";
		$prompt .= "15. 文件路径（重要）：传文件/目录路径参数时务必逐段书写、用 / 分隔，不要粘连或省略分段。站点标准目录：wp-content/plugins/、wp-content/themes/、wp-content/uploads/、wp-admin/、wp-includes/。例如读取天马插件设置类：wp-content/plugins/tianma/includes/class-settings.php。不确定路径时，先用 file_list 列出父目录确认实际名称，再操作。\n";
		$prompt .= "16. 能力自我进化（重要）：当用户请求的任务超出你现有工具能力时（如调用某个外部 API、某种特殊处理），不要直接说『做不到/需要装插件』——先向用户说明：插件缺少这个能力，可以新增。若用户同意，加载 tianma 组后用 create_tool 编写实现代码动态注册新工具（php_code 为 PHP 函数体，接收 \$args 返回 array('ok'=>true,'message'=>...,'data'=>...)，可用 wp_remote_get/post、get_option 等 WordPress 函数），创建后该能力立即可用。创建高风险工具前必须获得用户明确同意。\n";
		$prompt .= "17. 角色类型与调用方式（重要）：每个角色在创建时已声明类型，用 list_roles 查看（含『类型』与『绑定工具』字段）。『聊天型』角色：通过对话+工具完成任务，可用 start_collaboration 发起协作让它们执行。『功能性』角色：绑定了一个输出工具（如生图助手绑 generate_image），本身就是直接输出结果的——发起协作时该角色阶段会自动直接执行绑定工具（无需对话、无需担心模型是否支持聊天），你也可以建议用户直接切换到该角色使用。\n";
		$prompt .= "18. 工作日志（重要）：插件维护一份跨对话持久的工作日志（按天保存，任务结束后系统会自动把任务摘要写入当天日志）。用户问『今天/最近做了什么、上次进展、这个插件全程干了什么』时，先用 worklog_read 查看（核心工具，无需加载组）；用户要求『记录一下/记到工作日志』时用 worklog_append 追加（自动带时间戳）；需要修改既有记录时，先 worklog_read 看原文，再加载 tianma 组用 worklog_update 整体重写（保留要保留的历史行），删除某天用 worklog_delete。日志也可在「模型设置 → 工作日志」里人工编辑。\n";
		$prompt .= "19. 记住用户信息（重要）：当用户主动告知个人信息、身份、称呼、偏好或重要约定时（如「我是你老板」「叫我小张」「回复要简洁」），立刻用 worklog_append 记入工作日志（跨对话永久可查）。当用户问『我是谁 / 你还记得我吗 / 我之前说过什么 / 我们聊过什么』时，先用 worklog_read 查看工作日志回忆已记录的信息，结合上下文回答；不要只查数据库用户列表——数据库里只有账号信息，没有你们之间说过的话。\n\n";

		// 注入技能库与角色库
		$skills_context = Tianma_Skill::context_prompt();
		if ( $skills_context ) {
			$prompt .= $skills_context . "\n";
		}
		$roles_context = Tianma_Role::context_prompt();
		if ( $roles_context ) {
			$prompt .= $roles_context . "\n";
		}

		// 任务角色自动匹配：按用户当前需求提示最相关的角色（仅总调度模式，角色模式下已指定身份）
		if ( null === $this->role && ! empty( $message ) ) {
			$suggested = Tianma_Role::match_for_message( $message );
			if ( $suggested ) {
				$prompt .= "【本任务建议角色】根据用户需求，以下角色与任务匹配：若任务可由单个角色完成，请直接用 invoke_role 让该角色执行（快速、无需确认）；若确需多角色分工，再用 start_collaboration：\n";
				foreach ( $suggested as $s ) {
					$prompt .= "- 角色「{$s['role']->name}」：{$s['role']->description}\n";
				}
				$prompt .= "（若任务明确由你直接完成更高效，可忽略本建议）\n\n";
			}
		}

		if ( ! empty( $memories ) ) {
			$prompt .= "【历史经验参考】以下是从你的记忆库中检索到的相关经验，可帮助你更高效地完成任务：\n";
			$mem_budget = 1200; // 记忆注入总字符预算，控制 token 消耗
			foreach ( $memories as $m ) {
				$type = array(
					'episodic'   => '过往任务',
					'semantic'   => '知识经验',
					'procedural' => '最佳技能',
				);
				$label  = isset( $type[ $m['m_type'] ] ) ? $type[ $m['m_type'] ] : $m['m_type'];
				$entry  = "- [{$label}] {$m['title']}：{$m['content']}\n";
				$e_len  = mb_strlen( $entry );
				if ( $e_len > $mem_budget ) {
					$entry = mb_substr( $entry, 0, $mem_budget ) . "…\n";
					$prompt .= $entry;
					break;
				}
				$prompt .= $entry;
				$mem_budget -= $e_len;
			}
			$prompt .= "（经验仅供参考，请结合当前任务实际情况判断）\n\n";
		}

		$prompt .= '当前确认策略：' . ( 'auto' === $settings['confirm_mode'] ? '所有操作自动执行' : ( 'all' === $settings['confirm_mode'] ? '所有操作需确认' : '仅高危操作需确认' ) ) . "。\n";
		$prompt .= '站点语言：' . get_locale();

		return $prompt;
	}
}
