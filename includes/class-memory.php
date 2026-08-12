<?php
/**
 * 记忆系统：情景 / 语义 / 程序三种记忆 + 向量化检索 + 学习沉淀
 *
 * - episodic  ：任务全程记录（日记）
 * - semantic  ：提炼的知识要点（向量检索）
 * - procedural：高频成功操作序列（技能）
 *
 * @package Tianma
 */

defined( 'ABSPATH' ) || exit;

class Tianma_Memory {

	private $llm;

	public function __construct() {
		$this->llm = new Tianma_LLM();
	}

	/* ---------------------------------------------------------------------
	 * 写入记忆
	 * ------------------------------------------------------------------- */

	public function add( $type, $title, $content, $meta = array(), $weight = 1.0 ) {
		global $wpdb;

		$embedding = null;
		$emb       = $this->llm->embed( array( $title . '。' . mb_substr( $content, 0, 600 ) ) );
		if ( ! is_wp_error( $emb ) && ! empty( $emb[0] ) ) {
			$embedding = wp_json_encode( $emb[0] );
		}

		$wpdb->insert(
			$wpdb->prefix . 'tianma_memories',
			array(
				'm_type'     => $type,
				'title'      => mb_substr( $title, 0, 255 ),
				'content'    => $content,
				'embedding'  => $embedding,
				'meta'       => wp_json_encode( $meta, JSON_UNESCAPED_UNICODE ),
				'weight'     => $weight,
				'hit_count'  => 0,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%f', '%d', '%s' )
		);
		return $wpdb->insert_id;
	}

	/* ---------------------------------------------------------------------
	 * 检索记忆（向量优先，关键词降级）
	 * ------------------------------------------------------------------- */

	public function retrieve( $text, $k = 5 ) {
		global $wpdb;
		$settings = Tianma_Settings::get();
		if ( empty( $settings['memory_enabled'] ) ) {
			return array();
		}

		$query = "SELECT * FROM {$wpdb->prefix}tianma_memories ORDER BY id DESC LIMIT 600";
		$rows = $wpdb->get_results( $query, ARRAY_A );
		if ( ! $rows ) {
			return array();
		}

		// 向量检索
		$emb = $this->llm->embed( array( mb_substr( $text, 0, 600 ) ) );
		if ( ! is_wp_error( $emb ) && ! empty( $emb[0] ) ) {
			$query_vec = $emb[0];
			$scored    = array();
			foreach ( $rows as $row ) {
				if ( empty( $row['embedding'] ) ) {
					continue;
				}
				$vec = json_decode( $row['embedding'], true );
				if ( ! is_array( $vec ) ) {
					continue;
				}
				$score = self::cosine( $query_vec, $vec );
			$scored[] = array(
				'm_type' => $row['m_type'],
				'title'  => mb_substr( $row['title'], 0, 60 ),
				'content'=> mb_substr( $row['content'], 0, 260 ),
				'weight' => (float) $row['weight'],
				'score'  => $score,
				'id'     => (int) $row['id'],
			);
		}
		usort( $scored, function ( $a, $b ) {
			return ( $b['score'] * $b['weight'] ) <=> ( $a['score'] * $a['weight'] );
		} );
		$scored = array_slice( $scored, 0, $k );
			$this->bump_hits( array_column( $scored, 'id' ) );
			return $scored;
		}

		// 关键词降级检索（中文按 2-gram 匹配，提高召回）
		$keywords = preg_split( '/[\s,，。!?！？;；]+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$keywords = array_slice( $keywords, 0, 6 );
		$grams    = array();
		$len      = mb_strlen( $text );
		for ( $i = 0; $i < $len - 1; $i++ ) {
			$g = mb_substr( $text, $i, 2 );
			if ( ! in_array( $g, $grams, true ) ) {
				$grams[] = $g;
			}
		}
		$grams = array_slice( $grams, 0, 10 );
		$scored = array();
		foreach ( $rows as $row ) {
			$haystack = $row['title'] . $row['content'];
			$hits     = 0;
			// 完整句子命中
			if ( mb_strlen( $text ) >= 4 && false !== mb_stripos( $haystack, mb_substr( $text, 0, 12 ) ) ) {
				$hits += 3;
			}
			foreach ( $keywords as $kw ) {
				if ( mb_strlen( $kw ) > 1 && false !== mb_stripos( $haystack, $kw ) ) {
					$hits += 2;
				}
			}
			foreach ( $grams as $g ) {
				if ( false !== mb_stripos( $haystack, $g ) ) {
					$hits += 1;
				}
			}
			if ( $hits > 0 ) {
				$scored[] = array(
					'm_type' => $row['m_type'],
					'title'  => mb_substr( $row['title'], 0, 60 ),
					'content'=> mb_substr( $row['content'], 0, 260 ),
					'weight' => (float) $row['weight'],
					'score'  => $hits / max( 1, count( $keywords ) + count( $grams ) ),
					'id'     => (int) $row['id'],
				);
			}
		}
		usort( $scored, function ( $a, $b ) {
			return ( $b['score'] * $b['weight'] ) <=> ( $a['score'] * $a['weight'] );
		} );
		$scored = array_slice( $scored, 0, $k );
		$this->bump_hits( array_column( $scored, 'id' ) );
		return $scored;
	}

	private function bump_hits( $ids ) {
		if ( ! $ids ) {
			return;
		}
		global $wpdb;
		$ids = array_map( 'intval', $ids );
		$wpdb->query( "UPDATE {$wpdb->prefix}tianma_memories SET hit_count = hit_count + 1 WHERE id IN (" . implode( ',', $ids ) . ')' );
	}

	/* ---------------------------------------------------------------------
	 * 学习沉淀：任务结束后自动调用
	 * ------------------------------------------------------------------- */

	public function learn( $task ) {
		$settings = Tianma_Settings::get();
		if ( empty( $settings['memory_enabled'] ) ) {
			return;
		}
		if ( ! empty( $settings['mock_mode'] ) ) {
			return; // 演示模式不沉淀记忆（防测试污染）
		}

		$steps    = isset( $task['steps'] ) ? $task['steps'] : array();
		$tool_seq = array();
		foreach ( $steps as $s ) {
			if ( isset( $s['tool'] ) ) {
				$tool_seq[] = $s['tool'];
			}
		}
		$tool_seq = array_values( array_unique( $tool_seq ) );
		if ( ! $tool_seq ) {
			return; // 纯聊天（未调用任何工具）不沉淀记忆，避免无意义记忆与嵌入消耗
		}

		// 每日学习上限（防止记忆与嵌入调用无限膨胀）
		$key   = 'tianma_learn_' . gmdate( 'Y-m-d' );
		$count = (int) get_transient( $key );
		if ( $count >= 30 ) {
			return;
		}
		set_transient( $key, $count + 1, DAY_IN_SECONDS );

		$summary = isset( $task['summary'] ) ? $task['summary'] : '任务';
		$status  = isset( $task['status'] ) ? $task['status'] : 'done';

		// 1) 情景记忆：任务全程
		$this->add(
			'episodic',
			$summary,
			wp_json_encode( $steps, JSON_UNESCAPED_UNICODE ),
			array( 'status' => $status, 'tools' => $tool_seq ),
			0.6
		);

		// 2) 语义记忆：提炼要点
		$semantic = $summary . '。执行方式：' . ( $tool_seq ? implode( ' → ', $tool_seq ) : '直接回答' );
		if ( 'done' === $status ) {
			$semantic .= '。该任务执行成功。';
		} else {
			$semantic .= '。该任务未完成或出现错误，需注意。';
		}
		$this->add(
			'semantic',
			$summary,
			$semantic,
			array( 'status' => $status, 'tools' => $tool_seq ),
			'done' === $status ? 1.0 : 0.4
		);

		// 3) 程序记忆：成功且步骤足够 → 固化为技能
		if ( 'done' === $status && count( $tool_seq ) >= 2 ) {
			$this->add(
				'procedural',
				'技能：' . $summary,
				'完成任务《' . $summary . '》的有效工具序列：' . implode( ' → ', $tool_seq ) . '。下次遇到同类需求可优先采用此方案。',
				array( 'tools' => $tool_seq ),
				1.2
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * 用户反馈：加权记忆
	 * ------------------------------------------------------------------- */

	public function apply_feedback( $task_id, $user_id, $rating, $note = '' ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'tianma_feedback',
			array(
				'task_id'    => $task_id,
				'user_id'    => $user_id,
				'rating'     => $rating,
				'note'       => $note,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%s', '%s' )
		);

		// 找到该任务关联的最新记忆并加权
		$task = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT summary FROM {$wpdb->prefix}tianma_tasks WHERE id = %d",
				$task_id
			)
		);
		if ( ! $task ) {
			return;
		}
		$mem = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}tianma_memories WHERE title LIKE %s AND m_type IN ('semantic','procedural') ORDER BY id DESC LIMIT 1",
				'%' . $wpdb->esc_like( mb_substr( $task->summary, 0, 30 ) ) . '%'
			)
		);
		if ( ! $mem ) {
			return;
		}

		$delta = 0;
		if ( $rating >= 4 ) {
			$delta = 0.5;
		} elseif ( $rating <= 2 ) {
			$delta = -0.5;
		}
		if ( $rating <= 1 ) {
			$delta = -0.8;
		}
		if ( 0.0 !== $delta ) {
			$new_weight = max( 0.1, (float) $mem->weight + $delta );
			$wpdb->update(
				$wpdb->prefix . 'tianma_memories',
				array( 'weight' => $new_weight ),
				array( 'id' => $mem->id ),
				array( '%f' ),
				array( '%d' )
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * 管理
	 * ------------------------------------------------------------------- */

	public function list_all( $limit = 100 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, m_type, title, meta, weight, hit_count, created_at, LEFT(content, 200) AS preview
				 FROM {$wpdb->prefix}tianma_memories ORDER BY id DESC LIMIT %d",
				$limit
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * 余弦相似度
	 * ------------------------------------------------------------------- */

	public static function cosine( $a, $b ) {
		$dot = 0;
		$na  = 0;
		$nb  = 0;
		$len = min( count( $a ), count( $b ) );
		for ( $i = 0; $i < $len; $i++ ) {
			$dot += (float) $a[ $i ] * (float) $b[ $i ];
			$na  += (float) $a[ $i ] * (float) $a[ $i ];
			$nb  += (float) $b[ $i ] * (float) $b[ $i ];
		}
		$den = sqrt( $na ) * sqrt( $nb );
		return $den > 0 ? $dot / $den : 0;
	}
}
