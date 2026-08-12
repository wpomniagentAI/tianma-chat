<?php
/**
 * Agent 生态工具：角色 / 技能 / 多角色协作
 *
 * @package Tianma
 */

defined( 'ABSPATH' ) || exit;

class Tianma_Tools_Agent {

	/* ---------------- 角色 ---------------- */

	public static function list_roles( $args ) {
		$roles = Tianma_Role::list_all( '' );
		$items = array();
		foreach ( $roles as $r ) {
			$llm_label = '使用全局模型';
			if ( ! empty( $r->llm_provider ) ) {
				$llm_label = '独立模型：' . $r->llm_provider . ' / ' . $r->llm_model . ( ! empty( $r->llm_api_key ) ? '（已配 Key）' : '（未配 Key）' );
			}
			$items[] = array(
				'ID'          => $r->id,
				'名称'        => $r->name,
				'职责'        => mb_substr( $r->description, 0, 80 ),
				'状态'        => 'active' === $r->status ? '启用' : '停用',
				'内置'        => $r->is_builtin ? '是' : '否',
				'类型'        => 'functional' === $r->role_type ? '功能性' : '聊天型',
				'绑定工具'    => $r->bind_tool ? $r->bind_tool : '',
				'工具数'      => count( $r->tools ),
				'模型配置'    => $llm_label,
				'llm_provider'=> $r->llm_provider,
				'llm_model'   => $r->llm_model,
				'llm_has_key' => (bool) $r->llm_api_key,
			);
		}
		return array( 'ok' => true, 'message' => '共 ' . count( $items ) . ' 个角色（含每个角色的模型配置）', 'data' => $items );
	}

	public static function create_role( $args ) {
		$name        = isset( $args['name'] ) ? $args['name'] : '';
		$description = isset( $args['description'] ) ? $args['description'] : '';
		$prompt      = isset( $args['system_prompt'] ) ? $args['system_prompt'] : '';
		$tools       = isset( $args['tools'] ) ? $args['tools'] : array();
		if ( is_string( $tools ) ) {
			$tools = array_map( 'trim', explode( ',', $tools ) );
		}
		// 可选：为角色配置独立模型（不传则用全局模型）
		$llm = array();
		foreach ( array( 'provider', 'base_url', 'api_key', 'model' ) as $k ) {
			if ( isset( $args[ 'llm_' . $k ] ) ) {
				$llm[ $k ] = $args[ 'llm_' . $k ];
			}
		}

		// 角色类型：chat 聊天型（对话+工具完成任务）/ functional 功能性（绑定工具直接输出，不走对话）
		$meta = array(
			'role_type' => isset( $args['role_type'] ) ? $args['role_type'] : 'chat',
			'bind_tool' => isset( $args['bind_tool'] ) ? $args['bind_tool'] : '',
		);

		$role_id = Tianma_Role::create( $name, $description, $prompt, $tools, 0, $llm ? $llm : array(), $meta );
		if ( is_wp_error( $role_id ) ) {
			return array( 'ok' => false, 'message' => $role_id->get_error_message() );
		}
		$msg = '角色「' . $name . '」创建成功（ID: ' . $role_id . '）';
		if ( $llm ) {
			$msg .= '，已配置独立模型：' . ( isset( $llm['provider'] ) ? $llm['provider'] : '' ) . ' / ' . ( isset( $llm['model'] ) ? $llm['model'] : '' );
		}
		if ( 'functional' === $meta['role_type'] ) {
			$msg .= '，类型：功能性（绑定工具 ' . ( $meta['bind_tool'] ? $meta['bind_tool'] : '未设置' ) . '，AI 调用时直接执行输出）';
		}
		return array( 'ok' => true, 'message' => $msg, 'data' => array( 'role_id' => $role_id ) );
	}

	public static function update_role( $args ) {
		$id = (int) ( isset( $args['role_id'] ) ? $args['role_id'] : 0 );
		if ( ! $id ) {
			return array( 'ok' => false, 'message' => '缺少 role_id' );
		}
		$fields = array();
		if ( isset( $args['name'] ) ) { $fields['name'] = $args['name']; }
		if ( isset( $args['description'] ) ) { $fields['description'] = $args['description']; }
		if ( isset( $args['system_prompt'] ) ) { $fields['system_prompt'] = $args['system_prompt']; }
		if ( isset( $args['tools'] ) ) {
			$fields['tools'] = is_string( $args['tools'] ) ? array_map( 'trim', explode( ',', $args['tools'] ) ) : $args['tools'];
		}
		if ( isset( $args['role_type'] ) ) { $fields['role_type'] = $args['role_type']; }
		if ( isset( $args['bind_tool'] ) ) { $fields['bind_tool'] = $args['bind_tool']; }
		if ( isset( $args['status'] ) ) { $fields['status'] = $args['status']; }
		// 独立模型配置：llm 数组（或 llm_provider/llm_base_url/llm_api_key/llm_model 字段）
		if ( isset( $args['llm'] ) || isset( $args['llm_provider'] ) ) {
			$llm = array();
			if ( isset( $args['llm'] ) && is_array( $args['llm'] ) ) {
				$llm = $args['llm'];
			}
			foreach ( array( 'provider', 'base_url', 'api_key', 'model' ) as $k ) {
				if ( isset( $args[ 'llm_' . $k ] ) ) {
					$llm[ $k ] = $args[ 'llm_' . $k ];
				}
			}
			if ( array_key_exists( 'clear_llm', $args ) && $args['clear_llm'] ) {
				$fields['llm'] = null; // 清除独立模型，回到全局
			} elseif ( $llm ) {
				$fields['llm'] = $llm;
			}
		}

		$ok = Tianma_Role::update( $id, $fields );
		if ( false === $ok ) {
			return array( 'ok' => false, 'message' => '更新失败或没有变化' );
		}
		$role = Tianma_Role::get( $id );
		$msg = '角色 #' . $id . ' 已更新';
		if ( $role && ! empty( $role->llm_provider ) ) {
			$msg .= '，独立模型：' . $role->llm_provider . ' / ' . $role->llm_model;
		} elseif ( $role ) {
			$msg .= '，当前使用全局模型';
		}
		return array( 'ok' => true, 'message' => $msg );
	}

	/**
	 * 轻量调用角色执行单个任务（不走完整协作编排）。
	 * 功能性角色 → 直接执行绑定工具输出；聊天型角色 → 以该角色身份执行任务。
	 */
	public static function invoke_role( $args ) {
		$role_name = isset( $args['role'] ) ? trim( (string) $args['role'] ) : '';
		$task      = isset( $args['task'] ) ? trim( (string) $args['task'] ) : '';
		if ( '' === $role_name ) {
			return array( 'ok' => false, 'message' => '请提供 role（角色名，可用 list_roles 查看）' );
		}
		if ( '' === $task ) {
			return array( 'ok' => false, 'message' => '请提供 task（要该角色执行的任务描述）' );
		}

		$role = Tianma_Role::get_by_name( $role_name );
		if ( ! $role ) {
			return array( 'ok' => false, 'message' => '角色「' . $role_name . '」不存在（可用 list_roles 查看）' );
		}
		if ( 'active' !== $role->status ) {
			return array( 'ok' => false, 'message' => '角色「' . $role_name . '」已停用' );
		}

		// 功能性角色：直接执行绑定工具
		if ( 'functional' === $role->role_type ) {
			$bind_tool = $role->bind_tool ? $role->bind_tool : '';
			if ( ! $bind_tool || ! in_array( $bind_tool, Tianma_Tools::names(), true ) ) {
				return array( 'ok' => false, 'message' => '功能性角色「' . $role->name . '」未绑定有效的输出工具（bind_tool）' );
			}
			$f_args = array( 'prompt' => $task );
			if ( Tianma_Role::has_own_llm( $role ) ) {
				if ( ! empty( $role->llm_base_url ) ) { $f_args['base_url'] = $role->llm_base_url; }
				if ( ! empty( $role->llm_api_key ) )  { $f_args['api_key'] = $role->llm_api_key; }
				if ( ! empty( $role->llm_model ) )    { $f_args['model'] = $role->llm_model; }
			}
			$res = Tianma_Tools::execute( $bind_tool, $f_args );
			if ( ! $res['ok'] && false !== strpos( $res['message'], '缺少必填' ) ) {
				$res = Tianma_Tools::execute( $bind_tool, array( $task ) );
			}
			return array(
				'ok'      => $res['ok'],
				'message' => isset( $res['message'] ) ? $res['message'] : ( $res['ok'] ? '执行完成' : '执行失败' ),
				'data'    => array( 'role' => $role->name, 'role_type' => 'functional', 'bind_tool' => $bind_tool, 'result' => $res ),
			);
		}

		// 聊天型角色：以该角色身份执行任务（角色身份 + 工具白名单 + 独立模型）
		$agent = new Tianma_Agent();
		$agent->role = $role;
		$r = $agent->run( $task, array(), get_current_user_id() );
		$text = isset( $r['text'] ) && '' !== $r['text'] ? $r['text'] : ( isset( $r['error'] ) ? $r['error'] : '执行完成' );
		return array(
			'ok'      => isset( $r['status'] ) && 'error' !== $r['status'],
			'message' => $text,
			'data'    => array( 'role' => $role->name, 'role_type' => 'chat', 'steps' => isset( $r['steps'] ) ? $r['steps'] : array() ),
		);
	}

	public static function delete_role( $args ) {
		$id = (int) ( isset( $args['role_id'] ) ? $args['role_id'] : 0 );
		$role = $id ? Tianma_Role::get( $id ) : null;
		if ( ! $role ) {
			return array( 'ok' => false, 'message' => '角色不存在' );
		}
		if ( $role->is_builtin ) {			return array( 'ok' => false, 'message' => '内置角色不可删除，可停用' );
		}
		Tianma_Role::delete( $id );
		return array( 'ok' => true, 'message' => '角色「' . $role->name . '」已删除' );
	}

	/* ---------------- 技能 ---------------- */

	public static function list_skills( $args ) {
		$skills = Tianma_Skill::list_all( '' );
		$items  = array();
		foreach ( $skills as $s ) {
			$items[] = array(
				'ID'        => $s->id,
				'名称'      => $s->name,
				'描述'      => mb_substr( $s->description, 0, 80 ),
				'工具序列'  => implode( ' → ', $s->tools ),
				'来源'      => 'auto' === $s->source ? '自动学习' : '手动',
				'使用次数'  => $s->usage_count,
				'状态'      => 'active' === $s->status ? '启用' : '停用',
			);
		}
		return array( 'ok' => true, 'message' => '共 ' . count( $items ) . ' 个技能', 'data' => $items );
	}

	public static function create_skill( $args ) {
		$name   = isset( $args['name'] ) ? $args['name'] : '';
		$desc   = isset( $args['description'] ) ? $args['description'] : '';
		$tools  = isset( $args['tools'] ) ? $args['tools'] : array();
		$trigger = isset( $args['trigger'] ) ? $args['trigger'] : '';
		if ( is_string( $tools ) ) {
			$tools = array_map( 'trim', explode( ',', $tools ) );
		}

		$skill_id = Tianma_Skill::create( $name, $desc, $tools, $trigger, 'manual' );
		if ( is_wp_error( $skill_id ) ) {
			return array( 'ok' => false, 'message' => $skill_id->get_error_message() );
		}
		return array( 'ok' => true, 'message' => '技能「' . $name . '」已加入能力库（ID: ' . $skill_id . '）', 'data' => array( 'skill_id' => $skill_id ) );
	}

	public static function disable_skill( $args ) {
		$id = (int) ( isset( $args['skill_id'] ) ? $args['skill_id'] : 0 );
		$status = isset( $args['status'] ) && 'active' === $args['status'] ? 'active' : 'disabled';
		$ok = Tianma_Skill::update( $id, array( 'status' => $status ) );
		if ( false === $ok ) {
			return array( 'ok' => false, 'message' => '更新失败' );
		}
		return array( 'ok' => true, 'message' => '技能 #' . $id . ' 已' . ( 'active' === $status ? '启用' : '停用' ) );
	}

	/* ---------------- 多角色协作 ---------------- */

	public static function start_collaboration( $args ) {
		$task = isset( $args['task'] ) ? trim( (string) $args['task'] ) : '';
		$plan = isset( $args['plan'] ) ? $args['plan'] : array();

		// 容错 0：plan 是字符串 → 若缺 task 则作为任务描述；否则尝试解析为角色名列表
		if ( is_string( $plan ) ) {
			$plan_str = trim( $plan );
			$plan = array();
			if ( '' !== $plan_str ) {
				if ( '' === $task ) {
					$task = $plan_str; // 字符串 plan 更像任务描述
				} else {
					$decoded = json_decode( $plan_str, true );
					if ( is_array( $decoded ) ) {
						$plan = $decoded;
					} else {
						$plan = array_values( array_filter( array_map( 'trim', explode( ',', trim( $plan_str, '[]" ' ) ) ) ) );
					}
				}
			}
		}
		if ( ! is_array( $plan ) ) {
			$plan = array();
		}

		// 容错 1（优先）：模型用 roles（角色名列表）→ 直接作为分工计划
		if ( isset( $args['roles'] ) && ( ! $plan || self::plan_has_no_valid_role( $plan ) ) ) {
			$roles = $args['roles'];
			if ( is_string( $roles ) ) {
				$decoded = json_decode( $roles, true );
				$roles = is_array( $decoded ) ? $decoded : array_values( array_filter( array_map( 'trim', explode( ',', trim( $roles, '[]" ' ) ) ) ) );
			}
			$plan = array();
			foreach ( (array) $roles as $r ) {
				if ( is_string( $r ) && '' !== trim( $r ) ) {
					$plan[] = array( 'role' => trim( $r ), 'objective' => $task ? $task : '执行该角色职责' );
				}
			}
		}

		// 容错 2：plan 项规范化——字符串项 → {role, objective}
		foreach ( $plan as $i => $p ) {
			if ( is_string( $p ) ) {
				$plan[ $i ] = array( 'role' => trim( $p ), 'objective' => '执行该角色职责' );
			} elseif ( is_array( $p ) && isset( $p['role'] ) ) {
				$plan[ $i ]['objective'] = isset( $p['objective'] ) && '' !== trim( (string) $p['objective'] )
					? $p['objective'] : '执行该角色职责';
			} else {
				unset( $plan[ $i ] );
			}
		}
		$plan = array_values( $plan );

		if ( '' === $task ) {
			$task = '用户请求的协作任务';
		}
		if ( ! $plan ) {
			return array( 'ok' => false, 'message' => '协作需要角色：请提供 plan（分工数组，每项含 role 与 objective）或 roles（角色名列表，如 ["生图助手","内容运营"]）' );
		}

		$res = Tianma_Collab::run( $task, $plan, get_current_user_id() );		if ( ! $res['ok'] ) {
			return array( 'ok' => false, 'message' => $res['message'] );
		}

		$lines = array();
		foreach ( $res['data']['stages'] as $s ) {
			$lines[] = '【' . $s['role'] . '】' . mb_substr( $s['result'], 0, 300 );
		}
		$lines[] = '【汇总】' . mb_substr( $res['data']['final'], 0, 300 );

		return array(
			'ok' => true,
			'message' => '多角色协作完成：' . $task . "\n" . implode( "\n", $lines ),
			'data' => $res['data'],
		);
	}

	/** plan 中是否没有任何有效角色（用于判断是否改用 roles 参数） */
	private static function plan_has_no_valid_role( $plan ) {
		foreach ( (array) $plan as $p ) {
			$role_name = is_array( $p ) && isset( $p['role'] ) ? $p['role'] : ( is_string( $p ) ? $p : '' );
			if ( is_string( $role_name ) && '' !== trim( $role_name ) && Tianma_Role::get_by_name( trim( $role_name ) ) ) {
				return false;
			}
		}
		return true;
	}

	/* ---------------- 定时任务 ---------------- */
	public static function schedule_list( $args ) {
		$tasks = Tianma_Schedule::list_all();
		if ( ! $tasks ) {
			return array( 'ok' => true, 'message' => '当前没有定时任务', 'data' => array() );
		}
		$items = array();
		foreach ( $tasks as $t ) {
			$items[] = array(
				'id'          => (int) $t->id,
				'name'        => $t->name,
				'prompt'      => mb_substr( $t->prompt, 0, 120 ),
				'interval'    => Tianma_Schedule::describe_interval( $t ),
				'status'      => $t->status,
				'next_run'    => $t->next_run,
				'last_run'    => $t->last_run,
				'run_count'   => (int) $t->run_count,
				'auto_high'   => (int) $t->auto_high,
			);
		}
		return array( 'ok' => true, 'message' => '共 ' . count( $items ) . ' 个定时任务', 'data' => $items );
	}

	public static function schedule_create( $args ) {
		$data = array(
			'name'             => isset( $args['name'] ) ? $args['name'] : '',
			'prompt'           => isset( $args['prompt'] ) ? $args['prompt'] : '',
			'interval_type'    => isset( $args['interval_type'] ) ? $args['interval_type'] : 'daily',
			'at_time'          => isset( $args['at_time'] ) ? $args['at_time'] : '09:00',
			'day_of_week'      => isset( $args['day_of_week'] ) ? $args['day_of_week'] : 1,
			'interval_minutes' => isset( $args['interval_minutes'] ) ? $args['interval_minutes'] : 60,
			'auto_high'        => empty( $args['auto_high'] ) ? 0 : 1,
		);
		$id = Tianma_Schedule::create( $data, isset( $args['_user_id'] ) ? (int) $args['_user_id'] : 0 );
		if ( is_wp_error( $id ) ) {
			return array( 'ok' => false, 'message' => $id->get_error_message() );
		}
		$t = Tianma_Schedule::get( $id );
		return array(
			'ok' => true,
			'message' => '定时任务「' . $t->name . '」已创建（' . Tianma_Schedule::describe_interval( $t ) . '，下次执行：' . $t->next_run . '）',
			'data' => array( 'id' => $id, 'next_run' => $t->next_run ),
		);
	}

	public static function schedule_update( $args ) {
		$id = (int) ( isset( $args['schedule_id'] ) ? $args['schedule_id'] : 0 );
		if ( ! $id || ! Tianma_Schedule::get( $id ) ) {
			return array( 'ok' => false, 'message' => '定时任务不存在' );
		}
		$fields = array();
		foreach ( array( 'name', 'prompt', 'interval_type', 'at_time', 'day_of_week', 'interval_minutes', 'auto_high', 'status' ) as $k ) {
			if ( array_key_exists( $k, $args ) && null !== $args[ $k ] ) {
				$fields[ $k ] = $args[ $k ];
			}
		}
		if ( ! $fields ) {
			return array( 'ok' => false, 'message' => '没有需要更新的字段' );
		}
		Tianma_Schedule::update( $id, $fields );
		$t = Tianma_Schedule::get( $id );
		return array(
			'ok' => true,
			'message' => '定时任务「' . $t->name . '」已更新（' . Tianma_Schedule::describe_interval( $t ) . '，下次执行：' . $t->next_run . '）',
		);
	}

	public static function schedule_delete( $args ) {
		$id = (int) ( isset( $args['schedule_id'] ) ? $args['schedule_id'] : 0 );
		$t  = Tianma_Schedule::get( $id );
		if ( ! $t ) {
			return array( 'ok' => false, 'message' => '定时任务不存在' );
		}
		Tianma_Schedule::delete( $id );
		return array( 'ok' => true, 'message' => '定时任务「' . $t->name . '」已删除' );
	}

	public static function schedule_run_now( $args ) {
		$id = (int) ( isset( $args['schedule_id'] ) ? $args['schedule_id'] : 0 );
		$t  = Tianma_Schedule::get( $id );
		if ( ! $t ) {
			return array( 'ok' => false, 'message' => '定时任务不存在' );
		}
		$res = Tianma_Schedule::run( $id, 'manual' );
		$lines = array();
		$lines[] = ( $res['ok'] ? '✓' : '✗' ) . ' 执行状态：' . $res['status'];
		if ( ! empty( $res['text'] ) ) {
			$lines[] = '结果：' . mb_substr( $res['text'], 0, 500 );
		}
		if ( ! empty( $res['steps'] ) ) {
			$lines[] = '工具步骤：';
			foreach ( $res['steps'] as $s ) {
				$msg = isset( $s['message'] ) ? $s['message'] : ( isset( $s['msg'] ) ? $s['msg'] : '' );
				$lines[] = '  - ' . ( $s['ok'] ? '✓' : '✗' ) . ' ' . $s['tool'] . '：' . mb_substr( $msg, 0, 120 );
			}
		}
		return array( 'ok' => true, 'message' => '定时任务「' . $t->name . '」已执行：' . implode( "\n", $lines ) );
	}
}
