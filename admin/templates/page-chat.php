<div class="wrap tianma-wrap">
	<h1 class="tianma-title">
		<span class="dashicons dashicons-superhero"></span>
		天马行空 WP 智能体
		<span class="tianma-ver">v<?php echo esc_html( TIANMA_VERSION ); ?> · 免费开源</span>
	</h1>

	<div class="tianma-sub">
		<p class="tianma-desc">内置 AI 智能体助手：连接外部大模型 API 与内置向量记忆库，用自然语言完成 WordPress 全站管理、开发与维护任务。自学习，越用越聪明。</p>
		<p class="tianma-open-note">
			🆓 本插件免费开源，当前版本可能存在一些问题，有能力的朋友可自行修复，也欢迎联系作者一起改进。
			期待与你共同探讨、拓展 WP 智能体能力，让它更好用、适配更多用户需求场景。
		</p>
		<p class="tianma-about-line">
			🌐 官网及更新：<a href="https://github.com/wpomniagentAI/tianma-chat" target="_blank" rel="noopener nofollow">github.com/wpomniagentAI/tianma-chat</a> ·
			💬 QQ：<a href="https://wpa.qq.com/msgrd?v=3&amp;uin=3908349048&amp;site=qq&amp;menu=yes" target="_blank" rel="noopener nofollow">3908349048</a>（欢迎加我一起讨论拓展该插件）·
			⚠️ 任何利用插件收费的都是骗子 ·
			📢 内置广告位是作者加的，有需要支持下作者
		</p>
	</div>

	<div class="tianma-chat-layout">
		<!-- 侧栏 -->
		<aside class="tianma-sidebar">
			<button type="button" class="button button-primary tianma-btn-new" id="tianma-new-chat">新对话</button>

			<div class="tianma-conv-block">
				<div class="tianma-conv-head">
					<h3>会话记录</h3>
					<button type="button" class="tianma-btn-clear" id="tianma-btn-clear" title="清空聊天记录">清空</button>
				</div>
				<ul class="tianma-conv-list" id="tianma-conv-list">
					<li class="empty">加载中…</li>
				</ul>
			</div>

			<!-- 清空聊天菜单 -->
			<div class="tianma-clear-menu" id="tianma-clear-menu" style="display:none;">
				<button type="button" data-act="current">清空当前会话</button>
				<button type="button" data-act="all">清空全部会话</button>
			</div>

			<div class="tianma-side-block">
				<h3>记忆库</h3>
				<p class="tianma-side-desc">任务经验自动沉淀，越用越聪明</p>
				<button type="button" class="button tianma-btn-memories" id="tianma-btn-memories">查看记忆（<span id="tianma-memory-count">0</span>）</button>
			</div>

			<div class="tianma-side-block">
				<h3>Token 消耗</h3>
				<ul class="tianma-usage" id="tianma-usage">
					<li>当前会话：<strong id="usage-conv">-</strong></li>
					<li>今日：<strong id="usage-today">-</strong></li>
					<li>累计：<strong id="usage-total">-</strong></li>
					<li class="desc">调用 <span id="usage-calls">0</span> 次 · 最近 <span id="usage-last">-</span></li>
				</ul>
			</div>

			<!--TIANMA_AD-->

			<div class="tianma-side-block tianma-side-foot">
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=tianma-settings' ) ); ?>">模型设置</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=tianma-roles' ) ); ?>">角色与技能</a>
			</div>
		</aside>

		<!-- 对话区 -->
		<main class="tianma-chat-main">
			<!--TIANMA_AD_BANNER-->
			<div class="tianma-model-bar">
				<span class="model-bar-label">角色</span>
				<select id="tianma-bar-role" title="切换当前对话角色（总调度可协调所有角色协作）"><option value="0">总调度（天马）</option></select>
				<span class="model-bar-label">模型</span>
				<select id="tianma-bar-provider" title="切换模型服务商"></select>
				<select id="tianma-bar-model" title="切换模型"></select>
				<button type="button" class="button button-small" id="tianma-bar-switch">切换</button>
				<span class="model-bar-current" id="tianma-bar-current"></span>
				<label class="tianma-auto-confirm" title="开启后：删除、覆盖、改库等高危操作将不再逐次确认，直接执行（操作仍会记录审计日志）">
					<input type="checkbox" id="tianma-auto-confirm">
					<span class="auto-confirm-switch"></span>
					免授权执行<span class="auto-confirm-tip"></span>
				</label>
			</div>
			<div class="tianma-messages" id="tianma-messages"></div>
			<div class="tianma-composer">
				<textarea id="tianma-input" rows="1" placeholder="<?php echo esc_attr( '例如：帮我发布一篇介绍网站的文章，并创建分类「新闻」…' ); ?>"></textarea>
				<button type="button" class="button button-primary" id="tianma-send">发送</button>
			</div>
		</main>
	</div>

	<!-- 可视化文件编辑器（AI 修改文件后出现的可编辑卡片） -->
	<div class="tianma-modal" id="tianma-editor-modal" style="display:none;">
		<div class="tianma-modal-inner tianma-editor-inner">
			<h3 style="margin-top:0;">文件编辑器 <code id="tianma-editor-path" class="tianma-editor-path"></code></h3>
			<p class="description" style="margin-top:-6px;">直接修改内容后点「保存」写回文件；保存前自动备份原文件。</p>
			<textarea id="tianma-editor-content" class="tianma-editor-ta" spellcheck="false" placeholder="文件内容…"></textarea>
			<p class="tianma-editor-msg" id="tianma-editor-msg"></p>
			<p>
				<button type="button" class="button button-primary" id="tianma-editor-save">保存</button>
				<button type="button" class="button" id="tianma-editor-close">取消</button>
			</p>
		</div>
	</div>
</div>
