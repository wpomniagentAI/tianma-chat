<?php
/**
 * 多角色协作编排
 *
 * 复杂任务拆分为多个角色阶段，每个阶段在独立角色上下文中
 * 执行受限的 Agent 循环（可调用工具），最后汇总各阶段产出。
 *
 * @package Tianma
 */

defined( 'ABSPATH' ) || exit;

class Tianma_Collab {

	/**
	 * 执行一次多角色协作
	 *
	 * @param string $task  协作任务描述
	 * @param array  $plan  [ { role, objective, tools? } ... ]
	 * @param int    $user_id
	 * @return array 阶段结果 + 汇总
	 */
	public static function run( $task, $plan, $user_id = 0 ) {
		$settings = Tianma_Settings::get();
		$plan     = is_array( $plan ) ? array_slice( $plan, 0, 5 ) : array();
		if ( ! $plan ) {
			return array( 'ok' => false, 'message' => '协作计划为空' );
		}

		$stages = array();
		$context = array();

		foreach ( $plan as $i => $stage ) {
			$role_name = isset( $stage['role'] ) ? sanitize_text_field( $stage['role'] ) : '';
			$objective = isset( $stage['objective'] ) ? sanitize_text_field( $stage['objective'] ) : '';

			$role = Tianma_Role::get_by_name( $role_name );
			if ( ! $role ) {
				$stages[] = array(
					'role'      => $role_name,
					'objective' => $objective,
					'status'    => 'skipped',
					'result'    => '角色不存在，已跳过',
				);
				continue;
			}

			// 角色级独立模型（如配置则使用角色自己的 LLM）。
			// 不做「非对话型模型」名字判断——能否聊天由实际调用结果决定，
			// 角色模型调用失败时 LLM 层会自动降级全局模型重试，协作不会中断。
			$role_llm = array();
			if ( Tianma_Role::has_own_llm( $role ) ) {
				$role_llm = array(
					'provider' => $role->llm_provider,
					'base_url' => $role->llm_base_url,
					'api_key'  => $role->llm_api_key,
					'model'    => $role->llm_model,
				);
			}
			$llm = new Tianma_LLM( $role_llm );

			// 角色工具白名单过滤（空 = 只发核心查询工具 + 允许按需加载组，避免 68 个工具全量发送）
			$tools = Tianma_Tools::schemas_for_names( Tianma_Tools::core_names() );
			$tools[] = Tianma_Tools::group_use_schema();
			if ( ! empty( $role->tools ) && is_array( $role->tools ) ) {
				$allowed = array_flip( $role->tools );
				$tools   = array_values( array_filter( Tianma_Tools::schemas(), function ( $t ) use ( $allowed ) {
					return isset( $allowed[ $t['function']['name'] ] );
				} ) );
				if ( ! $tools ) {
					$tools = Tianma_Tools::schemas_for_names( Tianma_Tools::core_names() );
					$tools[] = Tianma_Tools::group_use_schema();
				}
			}

			// 功能性角色：绑定工具直接执行输出，不走对话/工具循环
			if ( 'functional' === $role->role_type ) {
				$bind_tool = $role->bind_tool ? $role->bind_tool : '';
				if ( ! $bind_tool || ! in_array( $bind_tool, Tianma_Tools::names(), true ) ) {
					$stages[] = array(
						'role'      => $role->name,
						'objective' => $objective,
						'status'    => 'error',
						'result'    => '功能性角色「' . $role->name . '」未绑定有效的输出工具（bind_tool）',
					);
					continue;
				}
				// 组装执行参数：优先把该角色的目标描述作为 prompt 参数；角色独立配置作为执行凭据
				$f_args = array( 'prompt' => $objective );
				if ( Tianma_Role::has_own_llm( $role ) ) {
					if ( ! empty( $role->llm_base_url ) ) { $f_args['base_url'] = $role->llm_base_url; }
					if ( ! empty( $role->llm_api_key ) )  { $f_args['api_key'] = $role->llm_api_key; }
					if ( ! empty( $role->llm_model ) )    { $f_args['model'] = $role->llm_model; }
				}
				$fresult = Tianma_Tools::execute( $bind_tool, $f_args );
				if ( ! $fresult['ok'] && false !== strpos( $fresult['message'], '缺少必填' ) ) {
					$fresult = Tianma_Tools::execute( $bind_tool, array( $objective ) );
				}
				$stage_result = isset( $fresult['message'] ) ? $fresult['message'] : ( $fresult['ok'] ? '执行完成' : '执行失败' );
				$stages[] = array(
					'role'      => $role->name,
					'objective' => $objective,
					'status'    => $fresult['ok'] ? 'done' : 'error',
					'result'    => $stage_result,
					'bind_tool' => $bind_tool,
				);
				$context[] = '【' . $role->name . ' 产出】' . mb_substr( $stage_result, 0, 500 );
				continue;
			}

			// 角色系统提示
			$sys = "你是「{$role->name}」，本次任务中的职责：{$role->description}\n";
			if ( $role->system_prompt ) {
				$sys .= $role->system_prompt . "\n";
			}
			$sys .= "\n当前协作任务（全局）：{$task}\n";
			$sys .= "你的分工目标：{$objective}\n";
			$sys .= "可用工具仅限你被分配的工具。完成后用中文给出结论。\n";
			$sys .= '站点名称：' . get_bloginfo( 'name' ) . '；站点地址：' . home_url();

			$messages = array( array( 'role' => 'system', 'content' => $sys ) );
			if ( $context ) {
				$messages[] = array(
					'role'    => 'system',
					'content' => "【前面角色的产出，供你参考，不要重复已完成的部分】\n" . implode( "\n---\n", $context ),
				);
			}
			$messages[] = array( 'role' => 'user', 'content' => $objective );

			// 阶段内受限循环（步数收紧，避免协作过慢）
			$stage_steps  = 0;
			$stage_result = '';
			$max_stage    = min( 3, max( 1, (int) $settings['max_steps'] ) );
			$stage_tools  = array();
			$stage_sigs   = array(); // 阶段内防重复

			for ( $j = 0; $j < $max_stage; $j++ ) {
				$resp = $llm->chat( $messages, $tools );

				// 容错：角色模型调用失败（如模型下线/接口异常）→ 降级用全局模型重试一次
				if ( is_wp_error( $resp ) && $role_llm ) {
					$llm = new Tianma_LLM();
					$resp = $llm->chat( $messages, $tools );
				}

				if ( is_wp_error( $resp ) ) {
					$stage_result = '阶段出错：' . $resp->get_error_message();
					break;
				}
				if ( empty( $resp['tool_calls'] ) ) {
					$stage_result = $resp['content'];
					break;
				}

				foreach ( $resp['tool_calls'] as $tc ) {
					$name = sanitize_key( $tc['name'] );
					$args = json_decode( $tc['arguments'], true );
					$args = is_array( $args ) ? $args : array();

					// 阶段内防重复：相同工具+相同参数只执行一次，其余给提示
					$sig = $name . ':' . md5( wp_json_encode( $args ) );
					if ( in_array( $sig, $stage_sigs, true ) ) {
						$result = array( 'ok' => false, 'message' => '该工具已在本阶段执行过，请直接基于已有结果总结，不要重复调用' );
					} else {
						$stage_sigs[] = $sig;
						$result = Tianma_Tools::execute( $name, $args );
					}
					$stage_tools[] = array( 'tool' => $name, 'ok' => $result['ok'], 'message' => mb_substr( $result['message'], 0, 200 ) );
					Tianma_Audit::log( 'collab:' . $name, array( 'args' => $args, 'ok' => $result['ok'], 'message' => mb_substr( $result['message'], 0, 300 ) ), $user_id );
					$stage_steps++;

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
						'content'      => self::tool_result_text( $result ),
					);
				}
			}

			$stages[] = array(
				'role'      => $role->name,
				'objective' => $objective,
				'status'    => 'done',
				'result'    => mb_substr( $stage_result, 0, 2000 ),
				'tools'     => $stage_tools,
			);
			$context[] = '【' . $role->name . '】' . mb_substr( $stage_result, 0, 1000 );
		}

		// 汇总：单阶段协作直接返回该阶段结果（省一次 LLM 调用）；多阶段才由协调者总结
		if ( count( $stages ) > 1 ) {
			$summary = self::summarize( $task, $stages, new Tianma_LLM() );
		} else {
			$summary = isset( $stages[0]['result'] ) ? $stages[0]['result'] : '协作完成';
		}

		return array(
			'ok'      => true,
			'message' => '多角色协作完成（' . count( $stages ) . ' 个角色阶段）',
			'data'    => array(
				'task'   => $task,
				'stages' => $stages,
				'final'  => $summary,
			),
		);
	}

	/** 组装传给模型的工具结果（message + 具体数据） */
	private static function tool_result_text( $result ) {
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

	/** 协调者汇总 */
	private static function summarize( $task, $stages, $llm ) {
		$settings = Tianma_Settings::get();

		$summary_prompt = "你是协作任务的协调者。以下是多角色协作完成的任务与各角色产出，请用中文汇总成一份简洁的最终报告（目标、各环节结论、下一步建议）：\n\n任务：{$task}\n\n";
		foreach ( $stages as $s ) {
			$summary_prompt .= "【{$s['role']}】{$s['result']}\n";
		}

		$resp = $llm->chat(
			array(
				array( 'role' => 'system', 'content' => '你是一个严谨的任务协调者，负责汇总多角色协作结果。' ),
				array( 'role' => 'user', 'content' => mb_substr( $summary_prompt, 0, 12000 ) ),
			),
			array(),
			0.3
		);

		if ( is_wp_error( $resp ) || empty( $resp['content'] ) ) {
			// 降级：简单拼接
			$fallback = '协作完成。各角色产出：';
			foreach ( $stages as $s ) {
				$fallback .= '【' . $s['role'] . '】' . mb_substr( $s['result'], 0, 200 );
			}
			return $fallback;
		}
		return $resp['content'];
	}
}
