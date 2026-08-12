<div class="wrap">
	<h1>天马行空 WP 智能体 — 模型设置</h1>

	<?php
	$settings = Tianma_Settings::get();
	$presets  = Tianma_Settings::presets();

	if ( isset( $_POST['tianma_save_settings'] ) && check_admin_referer( 'tianma_settings' ) ) {
		$saved = Tianma_Settings::update( $_POST );
		echo '<div class="notice notice-success is-dismissible"><p>设置已保存。</p></div>';
		$settings = $saved;
	}

	// 各服务商已保存的配置（供前端切换时动态加载；Key 打码）
	$provider_configs = array();
	foreach ( (array) ( isset( $settings['providers'] ) ? $settings['providers'] : array() ) as $pk => $pv ) {
		$pv   = is_array( $pv ) ? $pv : array();
		$key  = isset( $pv['api_key'] ) ? (string) $pv['api_key'] : '';
		$provider_configs[ $pk ] = array(
			'base_url' => isset( $pv['base_url'] ) ? $pv['base_url'] : '',
			'model'    => isset( $pv['model'] ) ? $pv['model'] : '',
			'api_key'  => '' === $key ? '' : substr( $key, 0, 4 ) . '••••••••' . substr( $key, -4 ),
		);
	}
	?>

	<form method="post">
		<?php wp_nonce_field( 'tianma_settings' ); ?>
		<input type="hidden" name="tianma_save_settings" value="1">

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="tianma-provider">模型服务商</label></th>
				<td>
					<select id="tianma-provider" name="provider">
						<?php foreach ( $presets as $key => $p ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['provider'], $key ); ?>>
								<?php echo esc_html( ( ! empty( $p['recommend'] ) ? '★ ' : '' ) . $p['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">选择后会自动填充对应的 API 地址与推荐模型。选择「本地演示模式」无需 Key，可用于验证功能链路。</p>
					<div class="tianma-reco">
						<p class="tianma-reco-title">💡 推荐服务商（新用户开通即送免费额度）</p>
						<?php foreach ( $presets as $key => $p ) : ?>
							<?php if ( empty( $p['recommend'] ) ) { continue; } ?>
							<div class="tianma-reco-card">
								<div class="tianma-reco-info">
									<strong>★ <?php echo esc_html( $p['label'] ); ?></strong>
									<p><?php echo esc_html( $p['free'] ); ?></p>
								</div>
								<a class="button button-primary tianma-reco-btn" href="<?php echo esc_url( $p['link'] ); ?>" target="_blank" rel="noopener nofollow">获取模型与 Key →</a>
							</div>
						<?php endforeach; ?>
						<p class="description" style="margin-top:8px;">其余服务商同样可用、不做推荐，按需选择即可。</p>
					</div>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="tianma-base-url">API 地址（Base URL）</label></th>
				<td>
					<input type="url" class="regular-text" id="tianma-base-url" name="base_url"
						value="<?php echo esc_attr( $settings['base_url'] ); ?>" placeholder="https://api.deepseek.com/v1">
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="tianma-api-key">API Key</label></th>
				<td>
					<input type="password" class="regular-text" id="tianma-api-key" name="api_key"
						value="<?php echo esc_attr( '' === $settings['api_key'] ? '' : substr( $settings['api_key'], 0, 4 ) . '••••••••' . substr( $settings['api_key'], -4 ) ); ?>" autocomplete="off" placeholder="填写 API Key（留空则保持原 Key；含掩码提交不会覆盖）">
					<button type="button" class="button" id="tianma-test-llm">测试连接</button>
					<span id="tianma-test-result"></span>
					<p class="description">Key 已打码显示，直接保存不会丢失原 Key；输入新 Key 即替换。</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="tianma-model">模型名称</label></th>
				<td>
					<input type="text" class="regular-text" id="tianma-model" name="model"
						value="<?php echo esc_attr( $settings['model'] ); ?>" list="tianma-models">
					<datalist id="tianma-models"></datalist>
					<p class="description">可直接输入，或从该服务商的内置模型列表中选择。</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="tianma-temperature">温度（0-2）</label></th>
				<td>
					<input type="number" min="0" max="2" step="0.1" id="tianma-temperature" name="temperature"
						value="<?php echo esc_attr( $settings['temperature'] ); ?>">
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="tianma-max-steps">最大执行步数</label></th>
				<td>
					<input type="number" min="3" max="40" id="tianma-max-steps" name="max_steps"
						value="<?php echo esc_attr( $settings['max_steps'] ); ?>">
					<p class="description">Agent 单次任务最多可执行的工具调用步数（3-40）。</p>
				</td>
			</tr>

			<tr>
				<th scope="row">操作确认策略</th>
				<td>
					<label><input type="radio" name="confirm_mode" value="high" <?php checked( $settings['confirm_mode'], 'high' ); ?>> 仅高危操作需确认（推荐）</label><br>
					<label><input type="radio" name="confirm_mode" value="auto" <?php checked( $settings['confirm_mode'], 'auto' ); ?>> 全自动执行（不留确认）</label><br>
					<label><input type="radio" name="confirm_mode" value="all" <?php checked( $settings['confirm_mode'], 'all' ); ?>> 所有操作都需确认</label>
					<p class="description">高危操作包括：删除文章/页面、删除文件、删除插件主题、安装插件、创建用户、修改站点设置、SQL 查询等。</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="tianma-embedding-model">嵌入模型</label></th>
				<td>
					<input type="text" class="regular-text" id="tianma-embedding-model" name="embedding_model"
						value="<?php echo esc_attr( $settings['embedding_model'] ); ?>">
					<p class="description">用于记忆向量化。DeepSeek 官方 API 未开放嵌入时，建议通义：text-embedding-v1 / text-embedding-v3。</p>
				</td>
			</tr>

			<tr>
				<th scope="row">记忆系统</th>
				<td>
					<input type="hidden" name="memory_enabled" value="0">
					<label><input type="checkbox" name="memory_enabled" value="1" <?php checked( $settings['memory_enabled'], 1 ); ?>> 启用自学习记忆</label>
					<p class="description">关闭后 Agent 不再沉淀与检索历史经验。</p>
				</td>
			</tr>

			<tr>
				<th scope="row">演示模式</th>
				<td>
					<input type="hidden" name="mock_mode" value="0">
					<label><input type="checkbox" name="mock_mode" value="1" <?php checked( $settings['mock_mode'], 1 ); ?>> 启用本地演示模式</label>
					<p class="description">不调用真实模型，用规则模拟对话与工具调用，方便先跑通链路。真实使用时请关闭。</p>
				</td>
			</tr>
		</table>

		<?php submit_button( '保存设置' ); ?>
	</form>

	<hr style="margin: 28px 0;">

	<h2>工作日志</h2>
	<p class="description" style="margin-bottom: 12px;">
		插件全程的关键进展按天记录在这里，跨对话不会丢失。任务结束后系统会自动追加一行摘要；你也可以在此手动编辑、补充或删除。
		AI 可通过 worklog_read / worklog_append / worklog_update 查看与调整这份日志。
	</p>

	<div id="tianma-worklog">
		<div class="tianma-wl-toolbar">
			<button type="button" class="button" id="tianma-wl-new">＋ 新增日志</button>
			<span class="tianma-wl-status"></span>
		</div>

		<div id="tianma-wl-editor" class="tianma-wl-editor" style="display:none;">
			<p>
				<label>日期：<input type="date" id="tianma-wl-day" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"></label>
			</p>
			<textarea id="tianma-wl-content" rows="8" placeholder="记录这一天的关键进展、决策、结果…"></textarea>
			<p>
				<button type="button" class="button button-primary" id="tianma-wl-save">保存</button>
				<button type="button" class="button" id="tianma-wl-cancel">取消</button>
			</p>
		</div>

		<div id="tianma-wl-list"></div>
	</div>

	<hr style="margin: 28px 0;">

	<h2>关于天马行空 WP 智能体</h2>
	<div class="tianma-about">
		<p>🌐 官网及更新：<a href="https://github.com/wpomniagentAI/tianma-chat" target="_blank" rel="noopener nofollow">github.com/wpomniagentAI/tianma-chat</a></p>
		<p>💬 QQ：<a href="https://wpa.qq.com/msgrd?v=3&amp;uin=3908349048&amp;site=qq&amp;menu=yes" target="_blank" rel="noopener nofollow">3908349048</a>（欢迎加我一起讨论拓展该插件）</p>
		<p>🆓 本插件<b>免费开源</b>使用，任何利用插件收费的都是骗子。</p>
		<p>📢 插件内置广告位是作者加的，希望大家有需要的支持下作者，感谢 🙏</p>
	</div>

	<style>
		#tianma-worklog { max-width: 860px; }
		.tianma-wl-toolbar { margin: 8px 0 12px; display: flex; align-items: center; gap: 12px; }
		.tianma-wl-status { color: #2271b1; font-size: 13px; }
		.tianma-wl-status.err { color: #a32d2d; }
		.tianma-wl-editor { background: #fff; border: 1px solid #dcdcde; border-left: 4px solid #2271b1; padding: 14px 16px; margin-bottom: 14px; }
		.tianma-wl-editor textarea { width: 100%; font-family: Consolas, Monaco, monospace; font-size: 13px; line-height: 1.6; }
		.tianma-wl-item { background: #fff; border: 1px solid #dcdcde; border-left: 4px solid #c3c4c7; margin-bottom: 10px; }
		.tianma-wl-item.active { border-left-color: #2271b1; }
		.tianma-wl-head { display: flex; align-items: center; gap: 10px; padding: 10px 14px; cursor: pointer; }
		.tianma-wl-head .tianma-wl-date { font-weight: 600; min-width: 110px; }
		.tianma-wl-head .tianma-wl-meta { color: #646970; font-size: 12px; }
		.tianma-wl-head .tianma-wl-actions { margin-left: auto; }
		.tianma-wl-head .tianma-wl-actions .button { margin-left: 6px; }
		.tianma-wl-preview { color: #50575e; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 420px; }
		.tianma-wl-body { display: none; padding: 0 14px 14px; }
		.tianma-wl-item.active .tianma-wl-body { display: block; }
		.tianma-wl-body textarea { width: 100%; font-family: Consolas, Monaco, monospace; font-size: 13px; line-height: 1.6; }
		.tianma-wl-body .tianma-wl-actions-row { margin-top: 8px; display: flex; gap: 8px; }
		.tianma-wl-empty { color: #646970; padding: 18px 0; }

		/* 推荐服务商 */
		.tianma-reco { margin-top: 10px; max-width: 620px; }
		.tianma-reco-title { margin: 0 0 8px; font-weight: 600; color: #1d2327; }
		.tianma-reco-card {
			display: flex; align-items: center; gap: 12px;
			background: #f6fbff; border: 1px solid #b9d4f5; border-left: 4px solid #1e63f0;
			border-radius: 8px; padding: 10px 14px; margin-bottom: 8px;
		}
		.tianma-reco-info { flex: 1; }
		.tianma-reco-info strong { color: #1d3a8f; }
		.tianma-reco-info p { margin: 4px 0 0; color: #50575e; font-size: 12px; }
		.tianma-reco-btn { flex-shrink: 0; }

		/* 关于插件 */
		.tianma-about { max-width: 620px; background: #fff; border: 1px solid #dcdcde; border-left: 4px solid #c3c4c7; border-radius: 8px; padding: 14px 18px; }
		.tianma-about p { margin: 6px 0; }
	</style>

	<script>
	(function () {
		var presets = <?php echo wp_json_encode( $presets ); ?>;
		function esc( s ) {
			var d = document.createElement( 'div' );
			d.textContent = s == null ? '' : String( s );
			return d.innerHTML;
		}
		var sel = document.getElementById( 'tianma-provider' );
		var base = document.getElementById( 'tianma-base-url' );
		var model = document.getElementById( 'tianma-model' );
		var modelsList = document.getElementById( 'tianma-models' );
		var providerConfigs = <?php echo wp_json_encode( $provider_configs ); ?>;
		var keyInput = document.getElementById( 'tianma-api-key' );

		function applyPreset() {
			var p = presets[ sel.value ];
			if ( ! p ) { return; }
			var saved = providerConfigs[ sel.value ];
			if ( saved ) {
				// 该服务商保存过配置 → 动态加载（切换过来自动恢复自己保存的值）
				if ( saved.base_url ) { base.value = saved.base_url; }
				if ( saved.model ) { model.value = saved.model; }
				if ( saved.api_key ) { keyInput.value = saved.api_key; }
			} else {
				// 从未保存过 → 填充该服务商预设，Key 清空（避免残留上一个服务商的值）
				if ( p.base_url ) { base.value = p.base_url; }
				if ( p.model ) { model.value = p.model; }
				keyInput.value = '';
			}
			if ( p.models && p.models.length ) {
				modelsList.innerHTML = p.models.map( function ( m ) {
					return '<option value="' + esc( m ) + '">';
				} ).join( '' );
			} else {
				modelsList.innerHTML = '';
			}
			if ( sel.value === 'mock' ) {
				keyInput.value = '';
			}
		}
		sel.addEventListener( 'change', applyPreset );
		// 仅填充模型下拉列表；不执行 applyPreset()，避免页面加载时把已保存的 base_url/模型覆盖成预设值
		var p0 = presets[ sel.value ];
		if ( p0 && p0.models && p0.models.length ) {
			modelsList.innerHTML = p0.models.map( function ( m ) {
				return '<option value="' + esc( m ) + '">';
			} ).join( '' );
		}

		document.getElementById( 'tianma-test-llm' ).addEventListener( 'click', function () {
			var btn = this;
			var out = document.getElementById( 'tianma-test-result' );
			btn.disabled = true;
			out.textContent = '测试中…';

			fetch( TIANMA.api + 'test-llm', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': TIANMA.nonce
				},
				body: JSON.stringify( {
					provider: sel.value,
					base_url: base.value,
					api_key: document.getElementById( 'tianma-api-key' ).value,
					model: model.value
				} )
			} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( d ) {
				out.textContent = d.ok ? '✅ ' + d.message : '❌ ' + d.message;
				out.style.color = d.ok ? '#0f6e56' : '#a32d2d';
			} )
			.catch( function () {
				out.textContent = '❌ 请求失败';
				out.style.color = '#a32d2d';
			} )
			.finally( function () { btn.disabled = false; } );
		} );
	})();
	</script>
</div>
