<div class="wrap tianma-wrap">
	<h1>定时任务</h1>
	<p class="description">让天马在指定时间自动执行任务，无需你在线。到点后自动唤醒 AI 按指令执行（支持每天备份、每周发文、每小时巡检等）。</p>

	<div class="tianma-sched-toolbar">
		<button type="button" class="button button-primary" id="tianma-sched-new">新建定时任务</button>
		<span class="description" id="tianma-sched-tip" style="margin-left:12px;"></span>
	</div>

	<table class="widefat striped" id="tianma-sched-table">
		<thead>
			<tr>
				<th>名称</th>
				<th>执行指令</th>
				<th>周期</th>
				<th>下次执行</th>
				<th>上次执行</th>
				<th>次数</th>
				<th>状态</th>
				<th>操作</th>
			</tr>
		</thead>
		<tbody><tr><td colspan="8">加载中…</td></tr></tbody>
	</table>

	<div class="tianma-sched-detail" id="tianma-sched-detail" style="display:none;">
		<h2>最近执行结果</h2>
		<div id="tianma-sched-result"></div>
	</div>

	<!-- 新建/编辑弹窗 -->
	<div class="tianma-modal" id="tianma-sched-modal" style="display:none;">
		<div class="tianma-modal-box tianma-sched-modal-box">
			<div class="tianma-modal-head">
				<h3 id="tianma-sched-modal-title">新建定时任务</h3>
				<button type="button" class="tianma-modal-close">×</button>
			</div>
			<div class="tianma-modal-body">
				<input type="hidden" id="tianma-sched-id">
				<p><label>任务名称<br>
					<input type="text" id="tianma-sched-name" class="regular-text" placeholder="如：每日数据库备份">
				</label></p>
				<p><label>到点执行的指令（自然语言，告诉天马做什么）<br>
					<textarea id="tianma-sched-prompt" rows="4" class="large-text" placeholder="如：备份一次数据库并清理 30 天前的过期缓存；或：用内容运营角色写一篇关于 WordPress 安全的文章草稿"></textarea>
				</label></p>
				<p><label>执行周期
					<select id="tianma-sched-interval" class="regular-text">
						<option value="hourly">每小时</option>
						<option value="twicedaily">每 12 小时</option>
						<option value="daily" selected>每天</option>
						<option value="weekly">每周</option>
						<option value="monthly">每月（1 日）</option>
						<option value="minutes">每隔 N 分钟</option>
					</select>
				</label></p>
				<p id="tianma-sched-minutes-row" style="display:none;"><label>间隔分钟数
					<input type="number" id="tianma-sched-minutes" min="1" max="10080" value="60" class="small-text"> 分钟
				</label></p>
				<p id="tianma-sched-time-row"><label>执行时刻
					<input type="time" id="tianma-sched-time" value="09:00">
				</label></p>
				<p id="tianma-sched-dow-row" style="display:none;"><label>星期
					<select id="tianma-sched-dow">
						<option value="1">周一</option>
						<option value="2">周二</option>
						<option value="3">周三</option>
						<option value="4">周四</option>
						<option value="5">周五</option>
						<option value="6">周六</option>
						<option value="0">周日</option>
					</select>
				</label></p>
				<p><label><input type="checkbox" id="tianma-sched-auto-high"> 允许自动执行危险操作（删除/覆盖/改库等）</label>
					<br><span class="description">勾选后定时执行遇到危险操作会直接执行；不勾选则自动跳过并记录，保证安全。</span>
				</p>
				<p><label><input type="checkbox" id="tianma-sched-active" checked> 创建后立即启用</label></p>
			</div>
			<div class="tianma-modal-foot">
				<button type="button" class="button button-primary" id="tianma-sched-save">保存</button>
				<button type="button" class="button tianma-modal-close">取消</button>
			</div>
		</div>
	</div>
</div>
