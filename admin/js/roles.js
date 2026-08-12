(function () {
	'use strict';

	var API = TIANMA.api;
	var NONCE = TIANMA.nonce;
	var ALL_TOOLS = TIANMA.all_tools || [];
	var I18N = TIANMA.i18n;

	function apiUrl( path, params ) {
		var u = new URL( API + path, window.location.origin );
		if ( params ) {
			Object.keys( params ).forEach( function ( k ) { u.searchParams.set( k, params[ k ] ); } );
		}
		return u.toString();
	}

	function req( path, opts ) {
		opts = opts || {};
		opts.headers = Object.assign( { 'X-WP-Nonce': NONCE }, opts.headers || {} );
		if ( opts.json !== undefined ) {
			opts.method = 'POST';
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify( opts.json );
		}
		return fetch( apiUrl( path ), opts ).then( function ( r ) { return r.json(); } );
	}

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML;
	}

	/* ---------------- 角色 ---------------- */

	function loadRoles() {
		var tbody = document.querySelector( '#tianma-roles-table tbody' );
		req( 'roles' ).then( function ( d ) {
			var items = d.items || [];
			if ( ! items.length ) {
				tbody.innerHTML = '<tr><td colspan="6">暂无角色</td></tr>';
				return;
			}
			tbody.innerHTML = items.map( function ( r ) {
				var llmInfo = r.has_own_llm
					? '<span title="' + esc( ( r.llm && r.llm.base_url ) || '' ) + '">独立<br><code>' + esc( ( r.llm && r.llm.model ) || '?' ) + '</code></span>'
					: '全局';
				var typeLabel = r.role_type === 'functional'
					? '功能性' + ( r.bind_tool ? '<br><code>' + esc( r.bind_tool ) + '</code>' : '' )
					: '聊天型';
				var builtin = r.is_builtin ? ' <span class="description">(内置)</span>' : '';
				return '<tr>'
					+ '<td><strong>' + esc( r.name ) + '</strong>' + builtin + '</td>'
					+ '<td>' + esc( r.description ) + '</td>'
					+ '<td>' + ( r.tools && r.tools.length ? r.tools.length : '全部' ) + '</td>'
					+ '<td>' + llmInfo + '</td>'
					+ '<td>' + typeLabel + '</td>'
					+ '<td>' + ( r.status === 'active' ? '启用' : '停用' ) + '</td>'
					+ '<td>'
					+ '<button class="button button-small role-edit" data-id="' + r.id + '">编辑</button> '
					+ '<button class="button button-small role-toggle" data-id="' + r.id + '" data-status="' + r.status + '">' + ( r.status === 'active' ? '停用' : '启用' ) + '</button> '
					+ ( r.is_builtin ? '' : '<button class="button button-small role-del" data-id="' + r.id + '">删除</button>' )
					+ '</td></tr>';
			} ).join( '' );
		} );
	}

	function applyRoleTypeUI() {
		var type = document.getElementById( 'tianma-role-type' ).value;
		var isFn = type === 'functional';
		document.getElementById( 'tianma-role-bind-box' ).style.display = isFn ? 'block' : 'none';
		document.getElementById( 'tianma-role-tools-row' ).style.display = isFn ? 'none' : '';
		// 功能性角色直接执行工具，不需要行为风格提示词（不对话）
		document.getElementById( 'tianma-role-prompt-row' ).style.display = isFn ? 'none' : '';
		// 功能性角色保留"单独配置模型/执行凭据"（绑定工具用它调用 API）；切换类型时折叠配置区
		if ( isFn ) {
			document.getElementById( 'tianma-role-llm-box' ).style.display = 'none';
		} else {
			document.getElementById( 'tianma-role-llm-box' ).style.display =
				document.getElementById( 'tianma-role-ownllm' ).checked ? 'block' : 'none';
		}
	}

	function openRoleModal( role ) {
		document.getElementById( 'tianma-role-id' ).value = role ? role.id : '';
		document.getElementById( 'tianma-role-modal-title' ).textContent = role ? '编辑角色：' + role.name : '新建角色';
		document.getElementById( 'tianma-role-name' ).value = role ? role.name : '';
		document.getElementById( 'tianma-role-desc' ).value = role ? role.description : '';
		document.getElementById( 'tianma-role-prompt' ).value = role ? ( role.system_prompt || '' ) : '';
		document.getElementById( 'tianma-role-status' ).checked = ! role || role.status === 'active';

		// 角色类型 + 绑定工具
		document.getElementById( 'tianma-role-type' ).value = role && role.role_type === 'functional' ? 'functional' : 'chat';
		var bindSel = document.getElementById( 'tianma-role-bindtool' );
		bindSel.innerHTML = ALL_TOOLS.map( function ( t ) {
			return '<option value="' + esc( t ) + '"' + ( role && role.bind_tool === t ? ' selected' : '' ) + '>' + esc( t ) + '</option>';
		} ).join( '' );
		applyRoleTypeUI();

		var sel = document.getElementById( 'tianma-role-tools' );
		var allowed = role && role.tools && role.tools.length ? role.tools : [];
		sel.innerHTML = ALL_TOOLS.map( function ( t ) {
			return '<option value="' + esc( t ) + '"' + ( allowed.indexOf( t ) >= 0 ? ' selected' : '' ) + '>' + esc( t ) + '</option>';
		} ).join( '' );

		// 独立模型配置
		var llm = ( role && role.llm ) || {};
		var hasOwn = !!( llm.api_key );
		document.getElementById( 'tianma-role-ownllm' ).checked = hasOwn;
		document.getElementById( 'tianma-role-llm-box' ).style.display = hasOwn ? 'block' : 'none';
		document.getElementById( 'tianma-role-llm-provider' ).value = llm.provider || 'custom';
		document.getElementById( 'tianma-role-llm-baseurl' ).value = llm.base_url || '';
		document.getElementById( 'tianma-role-llm-apikey' ).value = llm.api_key || '';
		document.getElementById( 'tianma-role-llm-model' ).value = llm.model || '';
		document.getElementById( 'tianma-role-llm-result' ).textContent = '';

		document.getElementById( 'tianma-role-modal' ).style.display = 'flex';
	}

	function saveRole() {
		var id = document.getElementById( 'tianma-role-id' ).value;
		var type = document.getElementById( 'tianma-role-type' ).value;
		var tools = Array.prototype.slice.call( document.getElementById( 'tianma-role-tools' ).selectedOptions ).map( function ( o ) { return o.value; } );
		var data = {
			name: document.getElementById( 'tianma-role-name' ).value.trim(),
			description: document.getElementById( 'tianma-role-desc' ).value.trim(),
			system_prompt: document.getElementById( 'tianma-role-prompt' ).value.trim(),
			role_type: type,
			tools: tools,
			status: document.getElementById( 'tianma-role-status' ).checked ? 'active' : 'inactive'
		};
		if ( type === 'functional' ) {
			data.tools = [];
			data.bind_tool = document.getElementById( 'tianma-role-bindtool' ).value;
		}
		if ( ! data.name || ! data.description ) {
			alert( '角色名称与职责描述必填' );
			return;
		}
		if ( document.getElementById( 'tianma-role-ownllm' ).checked ) {
			data.llm = {
				provider: document.getElementById( 'tianma-role-llm-provider' ).value,
				base_url: document.getElementById( 'tianma-role-llm-baseurl' ).value.trim(),
				api_key: document.getElementById( 'tianma-role-llm-apikey' ).value.trim(),
				model: document.getElementById( 'tianma-role-llm-model' ).value.trim()
			};
		} else {
			data.llm = null;
		}
		var p = id ? req( 'roles/' + id, { json: data } ) : req( 'roles', { json: data } );
		p.then( function () {
			document.getElementById( 'tianma-role-modal' ).style.display = 'none';
			loadRoles();
		} );
	}

	document.getElementById( 'tianma-role-new' ).addEventListener( 'click', function () { openRoleModal( null ); } );
	document.getElementById( 'tianma-role-save' ).addEventListener( 'click', saveRole );
	document.getElementById( 'tianma-role-type' ).addEventListener( 'change', applyRoleTypeUI );
	document.querySelectorAll( '.tianma-modal-close' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () { document.getElementById( 'tianma-role-modal' ).style.display = 'none'; } );
	} );

	/* 独立模型配置区 */
	var llmBox = document.getElementById( 'tianma-role-llm-box' );
	var llmProvider = document.getElementById( 'tianma-role-llm-provider' );
	var llmBaseUrl = document.getElementById( 'tianma-role-llm-baseurl' );

	// 服务商选项（presets 去掉 mock）
	llmProvider.innerHTML = Object.keys( TIANMA.presets || {} )
		.filter( function ( k ) { return k !== 'mock'; } )
		.map( function ( k ) {
			return '<option value="' + esc( k ) + '">' + esc( TIANMA.presets[ k ].label ) + '</option>';
		} ).join( '' );

	document.getElementById( 'tianma-role-ownllm' ).addEventListener( 'change', function () {
		llmBox.style.display = this.checked ? 'block' : 'none';
	} );
	llmProvider.addEventListener( 'change', function () {
		var p = TIANMA.presets[ llmProvider.value ];
		if ( p && p.base_url ) { llmBaseUrl.value = p.base_url; }
		if ( p && p.model ) { document.getElementById( 'tianma-role-llm-model' ).value = p.model; }
	} );
	document.getElementById( 'tianma-role-llm-test' ).addEventListener( 'click', function () {
		var btn = this;
		var out = document.getElementById( 'tianma-role-llm-result' );
		btn.disabled = true;
		out.textContent = '测试中…';
		req( 'test-llm', {
			json: {
				provider: llmProvider.value,
				base_url: llmBaseUrl.value,
				api_key: document.getElementById( 'tianma-role-llm-apikey' ).value,
				model: document.getElementById( 'tianma-role-llm-model' ).value
			}
		} ).then( function ( d ) {
			out.textContent = d.ok ? '✅ ' + d.message : '❌ ' + d.message;
			out.style.color = d.ok ? '#0f6e56' : '#a32d2d';
		} ).catch( function () {
			out.textContent = '❌ 请求失败';
			out.style.color = '#a32d2d';
		} ).finally( function () { btn.disabled = false; } );
	} );

	document.addEventListener( 'click', function ( e ) {
		var t = e.target;
		if ( t.classList && t.classList.contains( 'role-edit' ) ) {
			var id = t.dataset.id;
			req( 'roles' ).then( function ( d ) {
				var role = ( d.items || [] ).filter( function ( r ) { return r.id == id; } )[0];
				if ( role ) { openRoleModal( role ); }
			} );
		}
		if ( t.classList && t.classList.contains( 'role-toggle' ) ) {
			req( 'roles/' + t.dataset.id, { json: { status: t.dataset.status === 'active' ? 'inactive' : 'active' } } ).then( loadRoles );
		}
		if ( t.classList && t.classList.contains( 'role-del' ) ) {
			if ( confirm( I18N.confirm_delete_role ) ) {
				req( 'roles/' + t.dataset.id, { method: 'DELETE' } ).then( loadRoles );
			}
		}
		if ( t.classList && t.classList.contains( 'skill-toggle' ) ) {
			req( 'skills/' + t.dataset.id, { json: { status: t.dataset.status === 'active' ? 'disabled' : 'active' } } ).then( loadSkills );
		}
		if ( t.classList && t.classList.contains( 'skill-del' ) ) {
			if ( confirm( I18N.confirm_delete_skill ) ) {
				req( 'skills/' + t.dataset.id, { method: 'DELETE' } ).then( loadSkills );
			}
		}
	} );

	/* ---------------- 技能 ---------------- */

	function loadSkills() {
		var tbody = document.querySelector( '#tianma-skills-table tbody' );
		req( 'skills' ).then( function ( d ) {
			var items = d.items || [];
			if ( ! items.length ) {
				tbody.innerHTML = '<tr><td colspan="7">技能库为空。完成任务后 Agent 会自动提炼技能，你也可以在对话中让天马「记住某个流程」。</td></tr>';
				return;
			}
			tbody.innerHTML = items.map( function ( s ) {
				return '<tr>'
					+ '<td><strong>' + esc( s.name ) + '</strong></td>'
					+ '<td>' + esc( s.description ) + '</td>'
					+ '<td><code>' + esc( ( s.tools || [] ).join( ' → ' ) ) + '</code></td>'
					+ '<td>' + ( s.source === 'auto' ? '自动学习' : '手动' ) + '</td>'
					+ '<td>' + s.usage_count + '</td>'
					+ '<td>' + ( s.status === 'active' ? '启用' : '停用' ) + '</td>'
					+ '<td>'
					+ '<button class="button button-small skill-toggle" data-id="' + s.id + '" data-status="' + s.status + '">' + ( s.status === 'active' ? '停用' : '启用' ) + '</button> '
					+ '<button class="button button-small skill-del" data-id="' + s.id + '">删除</button>'
					+ '</td></tr>';
			} ).join( '' );
		} );
	}

	/* ---------------- 启动 ---------------- */

	loadRoles();
	loadSkills();
})();
