/* 天马 - 定时任务管理页 */
( function () {
	'use strict';

	var API = TIANMA.api;
	var NONCE = TIANMA.nonce;
	var I18N = TIANMA.i18n || {};

	function esc( s ) {
		return String( s == null ? '' : s )
			.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
	}

	function req( path, opts ) {
		opts = opts || {};
		return fetch( API + path, {
			method: opts.method || 'GET',
			headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
			body: opts.json ? JSON.stringify( opts.json ) : undefined
		} ).then( function ( r ) { return r.json(); } );
	}

	function errText( e ) {
		if ( e && e.message ) return e.message;
		if ( e && e.data && e.data.message ) return e.data.message;
		return '请求失败';
	}

	var table = document.getElementById( 'tianma-sched-table' );
	var tbody = table.querySelector( 'tbody' );

	function intervalRows() {
		var v = document.getElementById( 'tianma-sched-interval' ).value;
		document.getElementById( 'tianma-sched-minutes-row' ).style.display = ( v === 'minutes' ) ? '' : 'none';
		document.getElementById( 'tianma-sched-time-row' ).style.display = ( v === 'daily' || v === 'weekly' || v === 'monthly' ) ? '' : 'none';
		document.getElementById( 'tianma-sched-dow-row' ).style.display = ( v === 'weekly' ) ? '' : 'none';
	}

	function openModal( item ) {
		var modal = document.getElementById( 'tianma-sched-modal' );
		document.getElementById( 'tianma-sched-modal-title' ).textContent = item ? '编辑定时任务' : '新建定时任务';
		document.getElementById( 'tianma-sched-id' ).value = item ? item.id : '';
		document.getElementById( 'tianma-sched-name' ).value = item ? item.name : '';
		document.getElementById( 'tianma-sched-prompt' ).value = item ? item.prompt : '';
		document.getElementById( 'tianma-sched-interval' ).value = item ? item.interval_type : 'daily';
		document.getElementById( 'tianma-sched-minutes' ).value = item ? item.interval_minutes : 60;
		document.getElementById( 'tianma-sched-time' ).value = item ? ( item.at_time || '09:00' ) : '09:00';
		document.getElementById( 'tianma-sched-dow' ).value = item ? String( item.day_of_week ) : '1';
		document.getElementById( 'tianma-sched-auto-high' ).checked = ! ! ( item && item.auto_high );
		document.getElementById( 'tianma-sched-active' ).checked = ! item || item.status === 'active';
		intervalRows();
		modal.style.display = 'flex';
	}

	function closeModal() {
		document.getElementById( 'tianma-sched-modal' ).style.display = 'none';
	}

	function saveSchedule() {
		var id = document.getElementById( 'tianma-sched-id' ).value;
		var data = {
			name: document.getElementById( 'tianma-sched-name' ).value.trim(),
			prompt: document.getElementById( 'tianma-sched-prompt' ).value.trim(),
			interval_type: document.getElementById( 'tianma-sched-interval' ).value,
			at_time: document.getElementById( 'tianma-sched-time' ).value || '09:00',
			day_of_week: parseInt( document.getElementById( 'tianma-sched-dow' ).value, 10 ),
			interval_minutes: parseInt( document.getElementById( 'tianma-sched-minutes' ).value, 10 ) || 60,
			auto_high: document.getElementById( 'tianma-sched-auto-high' ).checked ? 1 : 0,
			status: document.getElementById( 'tianma-sched-active' ).checked ? 'active' : 'paused'
		};
		if ( ! data.name || ! data.prompt ) {
			alert( '任务名称与执行指令必填' );
			return;
		}
		var p = id ? req( 'schedules/' + id, { method: 'POST', json: data } ) : req( 'schedules', { method: 'POST', json: data } );
		p.then( function ( d ) {
			if ( d && d.code && ! d.ok ) { alert( errText( d ) ); return; }
			closeModal();
			load();
		} ).catch( function ( e ) { alert( errText( e ) ); } );
	}

	function renderResult( item ) {
		var box = document.getElementById( 'tianma-sched-detail' );
		var out = document.getElementById( 'tianma-sched-result' );
		var r = item.last_result;
		if ( ! r ) {
			box.style.display = 'none';
			return;
		}
		var html = '<p><strong>执行时间：</strong>' + esc( r.time || '' ) + '　'
			+ '<strong>状态：</strong>' + ( r.ok ? '<span style="color:#1d7f32">成功</span>' : '<span style="color:#b32d2e">' + esc( r.status || '失败' ) + '</span>' )
			+ '　<strong>触发：</strong>' + ( r.trigger === 'cron' ? '自动' : '手动' ) + '</p>';
		if ( r.text ) {
			html += '<p><strong>结果：</strong>' + esc( r.text ) + '</p>';
		}
		if ( r.steps && r.steps.length ) {
			html += '<p><strong>执行步骤：</strong></p><ul>';
			r.steps.forEach( function ( s ) {
				html += '<li>' + ( s.ok ? '✓' : '✗' ) + ' <code>' + esc( s.tool ) + '</code>　' + esc( String( s.message || s.msg || '' ).slice( 0, 120 ) ) + '</li>';
			} );
			html += '</ul>';
		}
		if ( r.usage && ( r.usage.total_tokens || r.usage.prompt_tokens ) ) {
			html += '<p><strong>Token 消耗：</strong>' + esc( ( r.usage.total_tokens || r.usage.prompt_tokens + r.usage.completion_tokens ) || 0 ) + '</p>';
		}
		out.innerHTML = html;
		box.style.display = 'block';
	}

	function load() {
		req( 'schedules' ).then( function ( d ) {
			var items = d.items || [];
			document.getElementById( 'tianma-sched-tip' ).textContent = '共 ' + items.length + ' 个任务';
			if ( ! items.length ) {
				tbody.innerHTML = '<tr><td colspan="8">还没有定时任务。点「新建定时任务」创建，或直接对天马说：<em>“每天凌晨 2 点备份数据库”</em>、<em>“每周一发布一篇技术文章”</em>。</td></tr>';
				return;
			}
			tbody.innerHTML = items.map( function ( t ) {
				var last = t.last_result;
				var lastStr = t.last_run ? t.last_run + ( last ? ' · ' + ( last.ok ? '成功' : ( last.status || '失败' ) ) : '' ) : '—';
				return '<tr>'
					+ '<td><strong>' + esc( t.name ) + '</strong></td>'
					+ '<td title="' + esc( t.prompt ) + '">' + esc( String( t.prompt ).slice( 0, 40 ) ) + ( t.prompt.length > 40 ? '…' : '' ) + '</td>'
					+ '<td>' + esc( t.interval_desc ) + ( t.auto_high ? ' <span class="description">(含高危授权)</span>' : '' ) + '</td>'
					+ '<td>' + esc( t.next_run || '—' ) + '</td>'
					+ '<td>' + esc( lastStr ) + '</td>'
					+ '<td>' + t.run_count + '</td>'
					+ '<td>' + ( t.status === 'active' ? '<span style="color:#1d7f32">运行中</span>' : '<span class="description">已暂停</span>' ) + '</td>'
					+ '<td class="row-actions">'
					+ '<button class="button button-small sched-run" data-id="' + t.id + '" title="立即执行一次">▶ 执行</button> '
					+ '<button class="button button-small sched-toggle" data-id="' + t.id + '" data-status="' + t.status + '">' + ( t.status === 'active' ? '暂停' : '启用' ) + '</button> '
					+ '<button class="button button-small sched-edit" data-id="' + t.id + '">编辑</button> '
					+ '<button class="button button-small sched-del" data-id="' + t.id + '">删除</button>'
					+ '</td></tr>';
			} ).join( '' );

			// 若当前展示的结果属于某个任务，刷新后保持展示
			var shown = document.getElementById( 'tianma-sched-detail' );
			if ( shown.style.display === 'block' ) {
				var first = items[ 0 ];
				if ( first ) { renderResult( first ); }
			}
		} ).catch( function () {
			tbody.innerHTML = '<tr><td colspan="8">加载失败，请刷新页面重试。</td></tr>';
		} );
	}

	/* ---------------- 事件绑定 ---------------- */

	document.getElementById( 'tianma-sched-new' ).addEventListener( 'click', function () { openModal( null ); } );
	document.getElementById( 'tianma-sched-save' ).addEventListener( 'click', saveSchedule );
	document.getElementById( 'tianma-sched-interval' ).addEventListener( 'change', intervalRows );
	document.querySelectorAll( '.tianma-modal-close' ).forEach( function ( b ) {
		b.addEventListener( 'click', closeModal );
	} );

	tbody.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( 'button' );
		if ( ! btn ) { return; }
		var id = btn.dataset.id;

		if ( btn.classList.contains( 'sched-run' ) ) {
			if ( ! confirm( I18N.confirm_run || '立即执行该任务？' ) ) { return; }
			btn.textContent = '执行中…';
			btn.disabled = true;
			req( 'schedules/' + id + '/run', { method: 'POST' } )
				.then( function ( d ) {
					if ( d && d.code && ! d.ok ) { alert( errText( d ) ); }
					else {
						var r = d.result || {};
						var lines = [ ( r.ok ? '✓' : '✗' ) + ' 状态：' + ( r.status || '' ) ];
						if ( r.text ) { lines.push( '结果：' + r.text ); }
						if ( r.steps && r.steps.length ) {
							lines.push( '步骤：' + r.steps.map( function ( s ) { return ( s.ok ? '✓' : '✗' ) + ' ' + s.tool; } ).join('，') );
						}
						alert( lines.join( '\n' ) );
					}
					load();
				} )
				.catch( function ( e ) { alert( errText( e ) ); load(); } );
			return;
		}

		if ( btn.classList.contains( 'sched-toggle' ) ) {
			req( 'schedules/' + id, { method: 'POST', json: { status: btn.dataset.status === 'active' ? 'paused' : 'active' } } )
				.then( load ).catch( function ( e ) { alert( errText( e ) ); } );
			return;
		}

		if ( btn.classList.contains( 'sched-edit' ) ) {
			req( 'schedules' ).then( function ( d ) {
				var it = ( d.items || [] ).filter( function ( x ) { return String( x.id ) === String( id ); } )[ 0 ];
				if ( it ) { openModal( it ); }
			} );
			return;
		}

		if ( btn.classList.contains( 'sched-del' ) ) {
			if ( ! confirm( I18N.confirm_delete || '确定删除该定时任务？' ) ) { return; }
			req( 'schedules/' + id, { method: 'DELETE' } ).then( load ).catch( function ( e ) { alert( errText( e ) ); } );
		}
	} );

	// 表格行点击查看最近结果
	tbody.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( 'button' ) ) { return; }
		var tr = e.target.closest( 'tr' );
		if ( ! tr ) { return; }
		req( 'schedules' ).then( function ( d ) {
			var it = ( d.items || [] ).filter( function ( x ) { return String( x.id ) === String( tr.dataset ? tr.dataset.id : '' ); } )[ 0 ];
			renderResult( it );
		} );
	} );

	load();
} )();
