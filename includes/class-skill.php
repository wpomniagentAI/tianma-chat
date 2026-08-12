<?php
/**
 * 技能库：Agent 可动态扩展的能力
 *
 * 技能 = 一组可复用的工具操作序列 + 触发描述。
 * 来源：
 *   - 自动学习：任务成功后由 Agent 提炼（learn 流程中调用）
 *   - 手动传授：用户让 Agent 记住某个流程（create_skill 工具）
 * 技能注入 system prompt，模型自主判断是否采用。
 *
 * @package Tianma
 */

defined( 'ABSPATH' ) || exit;

class Tianma_Skill {

	/* ---------------------------------------------------------------------
	 * 基础 CRUD
	 * ------------------------------------------------------------------- */

	public static function create( $name, $description, $tools, $trigger = '', $source = 'manual' ) {
		global $wpdb;

		$tools = array_values( array_unique( array_filter( (array) $tools, 'is_string' ) ) );
		if ( ! $name || ! $tools ) {
			return new WP_Error( 'tianma_skill', '技能名称与工具序列不能为空' );
		}

		// 查重：同名或相同工具序列
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}tianma_skills WHERE name = %s OR tools = %s LIMIT 1",
				$name,
				wp_json_encode( $tools )
			)
		);
		if ( $exists ) {
			// 已存在则更新使用描述，避免重复
			$wpdb->update(
				$wpdb->prefix . 'tianma_skills',
				array( 'description' => $description, 'trigger_text' => $trigger, 'status' => 'active' ),
				array( 'id' => $exists ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			return (int) $exists;
		}

		// 技能数量上限
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tianma_skills" );
		if ( $count >= 60 ) {
			// 删除最不常用且非手动的旧技能
			$wpdb->query(
				"DELETE FROM {$wpdb->prefix}tianma_skills
				 WHERE source = 'auto' AND status = 'disabled'
				 ORDER BY usage_count ASC LIMIT 10"
			);
		}

		$wpdb->insert(
			$wpdb->prefix . 'tianma_skills',
			array(
				'name'         => mb_substr( $name, 0, 120 ),
				'description'  => $description,
				'tools'        => wp_json_encode( $tools ),
				'trigger_text' => mb_substr( $trigger, 0, 200 ),
				'source'       => $source,
				'usage_count'  => 0,
				'status'       => 'active',
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	public static function update( $id, $fields ) {
		global $wpdb;
		$clean = array();
		if ( isset( $fields['name'] ) ) {
			$clean['name'] = mb_substr( sanitize_text_field( $fields['name'] ), 0, 120 );
		}
		if ( isset( $fields['description'] ) ) {
			$clean['description'] = sanitize_textarea_field( $fields['description'] );
		}
		if ( isset( $fields['trigger'] ) ) {
			$clean['trigger_text'] = mb_substr( sanitize_text_field( $fields['trigger'] ), 0, 200 );
		}
		if ( isset( $fields['status'] ) ) {
			$clean['status'] = in_array( $fields['status'], array( 'active', 'disabled' ), true ) ? $fields['status'] : 'active';
		}
		if ( isset( $fields['tools'] ) && is_array( $fields['tools'] ) ) {
			$clean['tools'] = wp_json_encode( array_values( array_unique( array_filter( $fields['tools'], 'is_string' ) ) ) );
		}
		if ( ! $clean ) {
			return false;
		}
		return $wpdb->update( $wpdb->prefix . 'tianma_skills', $clean, array( 'id' => (int) $id ) );
	}

	public static function delete( $id ) {
		global $wpdb;
		return $wpdb->delete( $wpdb->prefix . 'tianma_skills', array( 'id' => (int) $id ), array( '%d' ) );
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tianma_skills WHERE id = %d", $id )
		);
		if ( $row ) {
			$row->tools = json_decode( $row->tools, true );
		}
		return $row;
	}

	public static function list_all( $status = '' ) {
		global $wpdb;
		$sql = "SELECT * FROM {$wpdb->prefix}tianma_skills";
		if ( $status ) {
			$sql .= $wpdb->prepare( ' WHERE status = %s', $status );
		}
		$sql .= ' ORDER BY id DESC LIMIT 100';
		$rows = $wpdb->get_results( $sql );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		foreach ( $rows as $row ) {
			$row->tools = json_decode( $row->tools, true );
		}
		return $rows;
	}

	/** 技能使用计数 */
	public static function bump_usage( $id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}tianma_skills SET usage_count = usage_count + 1 WHERE id = %d", $id ) );
	}

	/* ---------------------------------------------------------------------
	 * 自动学习：从成功任务提炼技能
	 * ------------------------------------------------------------------- */

	public static function learn_from_task( $summary, $steps ) {
		$tool_seq = array();
		foreach ( (array) $steps as $s ) {
			if ( isset( $s['tool'] ) && $s['tool'] ) {
				$tool_seq[] = sanitize_key( $s['tool'] );
			}
		}
		$tool_seq = array_values( array_unique( $tool_seq ) );
		if ( count( $tool_seq ) < 2 ) {
			return; // 单工具任务不值得沉淀为技能
		}

		$trigger_words = preg_split( '/[\s,，。!?！？;；]+/u', $summary, -1, PREG_SPLIT_NO_EMPTY );
		$trigger       = mb_substr( implode( ' ', array_slice( $trigger_words, 0, 6 ) ), 0, 200 );

		self::create(
			mb_substr( '技能：' . $summary, 0, 100 ),
			'完成任务《' . mb_substr( $summary, 0, 60 ) . '》的有效工具序列：' . implode( ' → ', $tool_seq ) . '。遇到类似需求时可采用此方案。',
			$tool_seq,
			$trigger,
			'auto'
		);
	}

	/* ---------------------------------------------------------------------
	 * 注入 Agent 上下文
	 * ------------------------------------------------------------------- */

	public static function context_prompt( $limit = 6 ) {
		$skills = self::list_all( 'active' );
		if ( ! $skills ) {
			return '';
		}
		// 优先注入使用次数多的技能
		usort( $skills, function ( $a, $b ) {
			return (int) $b->usage_count <=> (int) $a->usage_count;
		} );
		$skills = array_slice( $skills, 0, $limit );
		$out    = "【技能库】高级技能（任务匹配时可按工具序列执行）：\n";
		foreach ( $skills as $s ) {
			$desc = mb_substr( $s->description, 0, 60 );
			$tools_str = $s->tools ? implode( '→', $s->tools ) : '';
			$tools_str = mb_substr( $tools_str, 0, 80 );
			$out .= "- 「{$s->name}」{$desc}";
			if ( $tools_str ) {
				$out .= " [{$tools_str}]";
			}
			$out .= "\n";
		}
		$out .= "（不匹配请忽略；技能详情可用 list_skills 查询）\n";
		return $out;
	}
}
