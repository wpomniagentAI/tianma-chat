/* 天马智能体 — 模型设置页：工作日志管理 */
(function () {
	'use strict';

	var listEl = document.getElementById( 'tianma-wl-list' );
	var statusEl = document.querySelector( '.tianma-wl-status' );
	var editor = document.getElementById( 'tianma-wl-editor' );
	var dayInput = document.getElementById( 'tianma-wl-day' );
	var contentInput = document.getElementById( 'tianma-wl-content' );
	var newBtn = document.getElementById( 'tianma-wl-new' );
	var saveBtn = document.getElementById( 'tianma-wl-save' );
	var cancelBtn = document.getElementById( 'tianma-wl-cancel' );
	var editingDay = null; // 当前编辑器对应的日期（新增模式为 null）

	var api = TIANMA.api;
	var nonce = TIANMA.nonce;
	var i18n = TIANMA.i18n || {};

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML;
	}

	function setStatus( msg, isErr ) {
		if ( ! statusEl ) { return; }
		statusEl.textContent = msg || '';
		statusEl.className = 'tianma-wl-status' + ( isErr ? ' err' : '' );
	}

	function apiCall( path, opts ) {
		opts = opts || {};
		return fetch( api + path, {
			method: opts.method || 'GET',
			headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
			body: opts.body ? JSON.stringify( opts.body ) : undefined
		} ).then( function ( r ) {
			return r.json().catch( function () { return {}; } );
		} );
	}

	function renderList( items ) {
		if ( ! listEl ) { return; }
		if ( ! items || ! items.length ) {
			listEl.innerHTML = '<div class="tianma-wl-empty">暂无工作日志。完成任务后会自动记录，或点「新增日志」手动添加。</div>';
			return;
		}
		listEl.innerHTML = '';
		items.forEach( function ( item ) {
			var div = document.createElement( 'div' );
			div.className = 'tianma-wl-item';
			div.dataset.day = item.log_date;

			var head = document.createElement( 'div' );
			head.className = 'tianma-wl-head';

			var date = document.createElement( 'span' );
			date.className = 'tianma-wl-date';
			date.textContent = item.log_date;

			var preview = document.createElement( 'span' );
			preview.className = 'tianma-wl-preview';
			preview.textContent = item.preview || '（空）';

			var meta = document.createElement( 'span' );
			meta.className = 'tianma-wl-meta';
			meta.textContent = item.line_count + ' 行 · ' + ( item.updated_at || '' );

			var actions = document.createElement( 'span' );
			actions.className = 'tianma-wl-actions';
			var editBtn = document.createElement( 'button' );
			editBtn.type = 'button';
			editBtn.className = 'button button-small';
			editBtn.textContent = '编辑';
			var delBtn = document.createElement( 'button' );
			delBtn.type = 'button';
			delBtn.className = 'button button-small';
			delBtn.textContent = '删除';
			actions.appendChild( editBtn );
			actions.appendChild( delBtn );

			head.appendChild( date );
			head.appendChild( preview );
			head.appendChild( meta );
			head.appendChild( actions );
			div.appendChild( head );

			var body = document.createElement( 'div' );
			body.className = 'tianma-wl-body';
			var ta = document.createElement( 'textarea' );
			ta.rows = 8;
			ta.value = item.content || '';
			var rowBtns = document.createElement( 'div' );
			rowBtns.className = 'tianma-wl-actions-row';
			var saveEdit = document.createElement( 'button' );
			saveEdit.type = 'button';
			saveEdit.className = 'button button-primary button-small';
			saveEdit.textContent = '保存修改';
			var cancelEdit = document.createElement( 'button' );
			cancelEdit.type = 'button';
			cancelEdit.className = 'button button-small';
			cancelEdit.textContent = '收起';
			rowBtns.appendChild( saveEdit );
			rowBtns.appendChild( cancelEdit );
			body.appendChild( ta );
			body.appendChild( rowBtns );
			div.appendChild( body );

			listEl.appendChild( div );

			// 展开/收起
			head.addEventListener( 'click', function ( e ) {
				if ( e.target.closest( '.tianma-wl-actions' ) ) { return; }
				div.classList.toggle( 'active' );
			} );

			editBtn.addEventListener( 'click', function () {
				hideEditor();
				div.classList.add( 'active' );
			} );

			cancelEdit.addEventListener( 'click', function () {
				div.classList.remove( 'active' );
			} );

			saveEdit.addEventListener( 'click', function () {
				var content = ta.value;
				if ( ! content.trim() && ! window.confirm( i18n.empty_confirm || '内容为空将清空该天日志，确定保存？' ) ) {
					return;
				}
				saveEdit.disabled = true;
				saveEdit.textContent = '保存中…';
				apiCall( 'worklogs', { method: 'POST', body: { day: item.log_date, content: content, mode: 'save' } } )
					.then( function ( d ) {
						setStatus( ( d && d.message ) || ( i18n.saved || '已保存' ) );
						return loadList();
					} )
					.catch( function () { setStatus( i18n.save_failed || '保存失败，请重试', true ); } )
					.finally( function () { saveEdit.disabled = false; saveEdit.textContent = '保存修改'; } );
			} );

			delBtn.addEventListener( 'click', function () {
				if ( ! window.confirm( i18n.confirm_delete || '确定删除这一天的工作日志？删除后不可恢复。' ) ) {
					return;
				}
				apiCall( 'worklogs/' + item.log_date, { method: 'DELETE' } )
					.then( function ( d ) {
						setStatus( ( d && d.message ) || '已删除' );
						return loadList();
					} )
					.catch( function () { setStatus( i18n.save_failed || '删除失败', true ); } );
			} );
		} );
	}

	function loadList() {
		return apiCall( 'worklogs?limit=60' ).then( function ( d ) {
			renderList( d && d.items ? d.items : [] );
		} ).catch( function () {
			setStatus( i18n.load_failed || '加载工作日志失败', true );
		} );
	}

	function hideEditor() {
		if ( ! editor ) { return; }
		editor.style.display = 'none';
		editingDay = null;
	}

	function showNewEditor() {
		if ( ! editor ) { return; }
		editingDay = null;
		dayInput.value = TIANMA.today;
		contentInput.value = '';
		editor.style.display = 'block';
		contentInput.focus();
	}

	if ( newBtn ) {
		newBtn.addEventListener( 'click', showNewEditor );
	}
	if ( cancelBtn ) {
		cancelBtn.addEventListener( 'click', hideEditor );
	}
	if ( saveBtn ) {
		saveBtn.addEventListener( 'click', function () {
			var day = dayInput.value || TIANMA.today;
			var content = contentInput.value;
			if ( ! content.trim() && ! window.confirm( i18n.empty_confirm || '内容为空将清空该天日志，确定保存？' ) ) {
				return;
			}
			saveBtn.disabled = true;
			saveBtn.textContent = '保存中…';
			apiCall( 'worklogs', { method: 'POST', body: { day: day, content: content, mode: 'save' } } )
				.then( function ( d ) {
					setStatus( ( d && d.message ) || ( i18n.saved || '已保存' ) );
					hideEditor();
					return loadList();
				} )
				.catch( function () { setStatus( i18n.save_failed || '保存失败，请重试', true ); } )
				.finally( function () { saveBtn.disabled = false; saveBtn.textContent = '保存'; } );
		} );
	}

	loadList();
})();
