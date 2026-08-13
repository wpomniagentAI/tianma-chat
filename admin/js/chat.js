(function () {
	'use strict';

	var API = TIANMA.api;
	var NONCE = TIANMA.nonce;
	var I18N = TIANMA.i18n;

	var messagesEl = document.getElementById( 'tianma-messages' );
	var inputEl = document.getElementById( 'tianma-input' );
	var sendBtn = document.getElementById( 'tianma-send' );
	var newBtn = document.getElementById( 'tianma-new-chat' );
	var convListEl = document.getElementById( 'tianma-conv-list' );
	var memoryCountEl = document.getElementById( 'tianma-memory-count' );
	var memoriesBtn = document.getElementById( 'tianma-btn-memories' );

	var currentConv = null;   // 当前会话 ID
	var convMsgs = [];        // 当前会话上下文（user/assistant 文本）
	var busy = false;
	var pendingConfirm = null;

	var THINK_PHRASES = [
		'正在理解你的需求…',
		'正在规划执行步骤…',
		'正在检索记忆库…',
		'正在调用工具…',
		'正在组织回复…'
	];

	/* ---------------- 工具函数 ---------------- */

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML;
	}

	function scrollBottom() {
		messagesEl.scrollTop = messagesEl.scrollHeight;
	}

	function addMsg( cls, html ) {
		var div = document.createElement( 'div' );
		div.className = 'tianma-msg ' + cls;
		div.innerHTML = html;
		messagesEl.appendChild( div );
		scrollBottom();
		return div;
	}

	function textToHtml( text ) {
		return esc( text ).replace( /\n/g, '<br>' );
	}

	function apiUrl( path, params ) {
		var u = new URL( API + path, window.location.origin );
		if ( params ) {
			Object.keys( params ).forEach( function ( k ) { u.searchParams.set( k, params[ k ] ); } );
		}
		return u.toString();
	}

	function post( path, data ) {
		return fetch( API + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
			body: JSON.stringify( data || {} )
		} ).then( function ( r ) {
			return r.json().then( function ( d ) {
				if ( ! r.ok ) {
					var err = new Error( d.message || ( 'HTTP ' + r.status ) );
					err.data = d;
					throw err;
				}
				return d;
			} ).catch( function ( e ) {
				if ( e.data ) { throw e; }
				throw new Error( 'HTTP ' + r.status + '（响应解析失败）' );
			} );
		} );
	}

	function errText( e ) {
		if ( e && e.data && e.data.message ) { return e.data.message; }
		if ( e && e.message ) { return e.message; }
		return I18N.error;
	}

	/* ---------------- 流式请求 ---------------- */

	/**
	 * 优先走「后台异步」：POST 拿到 task_id 后轮询 /task-stream 增量拉取，
	 * Web 请求保持轻量 → 不再阻塞整站。后端若降级为同步流式（text/event-stream），则走原 fetch 直读。
	 */
	function fetchStream( data, ui ) {
		// 后端为异步进程，会持续把结果写入流文件；此处不再因「长时间无数据」而中断轮询，
		// 只在超过阈值时给出柔和提示并继续等待后端最终产出，避免误判为超时/中断。
		var lastData = Date.now();
		var stallSoft = false;
		var stallHard = false;
		var stall = setInterval( function () {
			var idle = Date.now() - lastData;
			if ( ! stallSoft && idle > 180000 && ui.thinkText ) {
				ui.thinkText.textContent = '任务执行时间较长，仍在后台运行中…（可随时刷新页面查看）';
				stallSoft = true;
			}
			if ( ! stallHard && idle > 1800000 && ui.thinkText ) {
				ui.thinkText.textContent = '任务已运行超过 30 分钟，仍在后台继续；本页保持等待，亦可关闭后稍回会话查看结果。';
				stallHard = true;
			}
		}, 5000 );
		var phraseTimer = setInterval( function () {
			phraseIdx = ( phraseIdx + 1 ) % THINK_PHRASES.length;
			if ( ui.thinkText ) { ui.thinkText.textContent = THINK_PHRASES[ phraseIdx ]; }
		}, 2600 );
		var phraseIdx = 0;

		function finishWith( errMsg ) {
			clearInterval( stall );
			clearInterval( phraseTimer );
			if ( errMsg ) { ui.showError( errMsg ); }
			busy = false;
			sendBtn.disabled = false;
			inputEl.focus();
		}

		function legacyStream( r ) {
			// 后端同步降级：直接读 body 流（原逻辑）
			var reader = r.body.getReader();
			var decoder = new TextDecoder();
			var buf = '';
			function loop() {
				return reader.read().then( function ( res ) {
					if ( res.done ) { return; }
					buf += decoder.decode( res.value, { stream: true } );
					lastData = Date.now();
					var idx;
					while ( ( idx = buf.indexOf( '\n\n' ) ) >= 0 ) {
						handleEvent( buf.slice( 0, idx ), ui );
						buf = buf.slice( idx + 2 );
					}
					return loop();
				} );
			}
			return loop().then( function () { finishWith(); } ).catch( function ( e ) {
				finishWith( errText( e ) );
			} );
		}

		function pollLoop( taskId ) {
			var offset = 0;
			var residual = '';
			function poll() {
				fetch( apiUrl( 'task-stream', { task_id: taskId, offset: offset } ), {
					headers: { 'X-WP-Nonce': NONCE }
				} ).then( function ( r ) {
					if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); }
					return r.json();
				} ).then( function ( resp ) {
					lastData = Date.now();
					residual += resp.data || '';
					var idx;
					while ( ( idx = residual.indexOf( '\n\n' ) ) >= 0 ) {
						handleEvent( residual.slice( 0, idx ), ui );
						residual = residual.slice( idx + 2 );
					}
					offset = resp.offset;
					if ( 'done' === resp.status || 'error' === resp.status || 'needs_confirm' === resp.status ) {
						// 事件已渲染 UI；这里仅结束轮询（勿重复 finish 以免重复反馈框）
						if ( 'needs_confirm' === resp.status ) {
							// 等待用户在前端点击确认（api_confirm 同步续跑），释放 busy
							finishWith();
						} else {
							finishWith();
						}
						return;
					}
					setTimeout( poll, 700 );
				} ).catch( function ( e ) {
					finishWith( errText( e ) );
				} );
			}
			poll();
		}

		return fetch( API + 'chat', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
			body: JSON.stringify( Object.assign( {}, data, { stream: true } ) )
		} ).then( function ( r ) {
			if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); }
			var ct = r.headers.get( 'content-type' ) || '';
			if ( ct.indexOf( 'text/event-stream' ) >= 0 ) {
				return legacyStream( r ); // 同步降级
			}
			return r.json().then( function ( d ) {
				if ( d && d.task_id ) { pollLoop( d.task_id ); return; }
				throw new Error( '后端未返回任务标识，可能已降级' );
			} );
		} ).catch( function ( e ) {
			finishWith( errText( e ) );
		} );
	}

	/** 非流式 JSON 结果降级渲染 */
	function applyResult( d, ui ) {
		var data = d && d.data ? d.data : d;
		if ( ! data ) { return; }
		if ( data.status === 'needs_confirmation' ) { ui.showConfirm( data ); return; }
		if ( data.status === 'error' ) { ui.showError( data.error || I18N.error ); return; }
		ui.finish( data );
	}

	function handleEvent( raw, ui ) {
		var ev = '';
		var data = null;
		raw.split( '\n' ).forEach( function ( l ) {
			if ( l.indexOf( 'event:' ) === 0 ) { ev = l.slice( 6 ).trim(); }
			else if ( l.indexOf( 'data:' ) === 0 ) {
				try { data = JSON.parse( l.slice( 5 ).trim() ); } catch ( e ) {}
			}
		} );
		if ( ! ev || data === null ) { return; }

		switch ( ev ) {
			case 'thinking':
				ui.thinkText.textContent = '思考中（第 ' + ( data.step || 1 ) + ' 轮）…';
				break;
			case 'reasoning':
				ui.appendReasoning( data.text || '' );
				break;
			case 'delta':
				ui.appendDelta( data.text || '' );
				break;
			case 'tool_start':
				ui.addTool( data.tool, data.args || {} );
				break;
			case 'tool_done':
				ui.updateTool( data );
				if ( data.visual ) {
					ui.addVisual( data.visual );
				}
				break;
			case 'confirm':
				ui.showConfirm( data );
				break;
			case 'done':
				ui.finish( data );
				break;
			case 'error':
				ui.showError( data.message || I18N.error );
				break;
		}
	}

	/* ---------------- 流式 UI ---------------- */

	function streamUI() {
		var el = addMsg( 'assistant', ''
			+ '<div class="tianma-stream">'
			+ '<div class="tianma-think"><span class="dot"></span><span class="dot"></span><span class="dot"></span>'
			+ '<span class="think-text">' + esc( I18N.thinking ) + '</span></div>'
			+ '<div class="tianma-reasoning" style="display:none;"><span class="reasoning-head">深度思考中…</span><div class="reasoning-body"></div></div>'
			+ '<div class="tianma-tools"></div>'
			+ '<div class="tianma-out"></div>'
			+ '</div>'
		);
		return {
			el: el,
			thinkText: el.querySelector( '.think-text' ),
			reasoningBox: el.querySelector( '.tianma-reasoning' ),
			reasoningBody: el.querySelector( '.reasoning-body' ),
			toolsBox: el.querySelector( '.tianma-tools' ),
			outBox: el.querySelector( '.tianma-out' ),

			appendReasoning: function ( text ) {
				this.reasoningBox.style.display = 'block';
				this.reasoningBody.innerHTML += esc( text ).replace( /\n/g, '<br>' );
				scrollBottom();
			},

			appendDelta: function ( text ) {
				this.outBox.style.display = 'block';
				this.outBox.innerHTML += esc( text ).replace( /\n/g, '<br>' );
				scrollBottom();
			},

			addTool: function ( name, args ) {
				var card = document.createElement( 'div' );
				card.className = 'tianma-tool-card running';
				card.dataset.tool = name;
				card.innerHTML = '<span class="tc-spin"></span><span class="tc-name">' + esc( name ) + '</span>'
					+ '<span class="tc-args">' + esc( JSON.stringify( args || {} ) ) + '</span>';
				this.toolsBox.appendChild( card );
				scrollBottom();
			},

			updateTool: function ( d ) {
				var cards = this.toolsBox.querySelectorAll( '.tianma-tool-card' );
				var card = null;
				for ( var i = cards.length - 1; i >= 0; i-- ) {
					if ( cards[ i ].dataset.tool === d.tool ) { card = cards[ i ]; break; }
				}
				if ( card ) {
					card.classList.remove( 'running' );
					card.classList.add( d.ok ? 'ok' : 'fail' );
					card.querySelector( '.tc-name' ).textContent = d.tool + ( d.ok ? ' ✓' : ' ✗' );
					var msg = card.querySelector( '.tc-msg' );
					if ( ! msg ) {
						msg = document.createElement( 'span' );
						msg.className = 'tc-msg';
						card.appendChild( msg );
					}
					msg.textContent = d.message || '';
				}
				scrollBottom();
			},

			addVisual: function ( v ) {
				if ( 'image' === v.type && v.url ) {
					this.addImageCard( v.url, v.prompt );
				} else if ( 'file_edit' === v.type ) {
					this.addFileCard( v.path, v.content, '可编辑文件' );
				} else if ( 'file_read' === v.type ) {
					this.addFileCard( v.path, v.content, '已读取文件' );
				} else if ( 'file_list' === v.type ) {
					this.addDirCard( v.path, v.items || [] );
				} else if ( 'result' === v.type ) {
					var r = document.createElement( 'div' );
					r.className = 'tianma-visual-result';
					r.innerHTML = '<span class="tianma-visual-icon">' + esc( v.icon || '✅' ) + '</span>'
						+ '<span class="tianma-visual-title">' + esc( v.title || '' ) + '</span>';
					this.el.appendChild( r );
					scrollBottom();
				} else if ( 'worklog' === v.type ) {
					this.addWorklogCard( v.day || '', v.content || '' );
				}
			},

			addWorklogCard: function ( day, content ) {
				var card = document.createElement( 'div' );
				card.className = 'tianma-file-card tianma-worklog-card';
				card.innerHTML = '<div class="tianma-file-head">'
					+ '<span class="tianma-file-icon">📓</span>'
					+ '<code class="tianma-file-path">工作日志' + ( day ? ' · ' + esc( day ) : '' ) + '</code>'
					+ '</div>'
					+ '<pre class="tianma-file-preview tianma-worklog-preview">' + esc( ( content || '' ).slice( 0, 1500 ) )
					+ ( ( content || '' ).length > 1500 ? '…' : '' ) + '</pre>';
				this.el.appendChild( card );
				scrollBottom();
			},

			addImageCard: function ( url, prompt ) {
				var card = document.createElement( 'div' );
				card.className = 'tianma-image-card';
				card.innerHTML = '<div class="tianma-file-head">'
					+ '<span class="tianma-file-icon">🖼</span>'
					+ '<span class="tianma-image-prompt">' + esc( prompt || 'AI 生成的图片' ) + '</span>'
					+ '<a class="button button-small" href="' + esc( url ) + '" target="_blank" rel="noopener">打开原图</a>'
					+ '</div>'
					+ '<img class="tianma-image-preview" src="' + esc( url ) + '" alt="' + esc( prompt || '' ) + '" loading="lazy">';
				this.el.appendChild( card );
				scrollBottom();
			},

			addFileCard: function ( path, content, label ) {
				var card = document.createElement( 'div' );
				card.className = 'tianma-file-card';
				card.innerHTML = '<div class="tianma-file-head">'
					+ '<span class="tianma-file-icon">📄</span>'
					+ '<code class="tianma-file-path">' + esc( path ) + '</code>'
					+ '<button type="button" class="button button-small tianma-file-edit">打开编辑器修改</button>'
					+ '</div>'
					+ '<div class="tianma-file-label">' + esc( label || '' ) + '</div>'
					+ '<pre class="tianma-file-preview">' + esc( ( content || '' ).slice( 0, 600 ) )
					+ ( ( content || '' ).length > 600 ? '…' : '' ) + '</pre>';
				this.el.appendChild( card );
				card.querySelector( '.tianma-file-edit' ).addEventListener( 'click', function () {
					openEditor( path, content || '' );
				} );
				scrollBottom();
			},

			addDirCard: function ( path, items ) {
				var card = document.createElement( 'div' );
				card.className = 'tianma-dir-card';
				var rows = items.slice( 0, 50 ).map( function ( it ) {
					var isDir = it['类型'] === '目录';
					return '<div class="tianma-dir-row"><span class="tianma-dir-icon">' + ( isDir ? '📁' : '📄' ) + '</span>'
						+ '<span class="tianma-dir-name">' + esc( it['名称'] ) + '</span>'
						+ '<span class="tianma-dir-meta">' + ( isDir ? '目录' : ( it['大小'] || '' ) ) + '</span></div>';
				} ).join( '' );
				card.innerHTML = '<div class="tianma-file-head">'
					+ '<span class="tianma-file-icon">📂</span>'
					+ '<code class="tianma-file-path">' + esc( path || '(站点根目录)' ) + '</code>'
					+ '<span class="tianma-dir-count">' + items.length + ' 项</span>'
					+ '</div>'
					+ '<div class="tianma-dir-list">' + rows + '</div>';
				this.el.appendChild( card );
				scrollBottom();
			},

			showConfirm: function ( d ) {
				var confirm = d.confirm || {};
				this.el.querySelector( '.tianma-think' ).style.display = 'none';
				pendingConfirm = { id: confirm.confirm_id, taskId: d.task_id };
				var box = document.createElement( 'div' );
				box.className = 'tianma-confirm';
				box.innerHTML = '<div class="tianma-confirm-title">⚠ ' + esc( I18N.confirm ) + '</div>'
					+ '<div class="tianma-confirm-tool">工具：<code>' + esc( confirm.tool_name ) + '</code></div>'
					+ '<div class="tianma-confirm-desc">' + esc( confirm.summary ) + '</div>'
					+ '<pre class="tianma-confirm-args">' + esc( JSON.stringify( confirm.args || {}, null, 2 ) ) + '</pre>'
					+ '<div class="tianma-confirm-btns">'
					+ '<button class="button button-primary tianma-confirm-approve">' + esc( I18N.approve ) + '</button> '
					+ '<button class="button tianma-confirm-reject">' + esc( I18N.reject ) + '</button></div>';
				this.el.appendChild( box );
				var self = this;
				box.querySelector( '.tianma-confirm-approve' ).addEventListener( 'click', function () {
					submitConfirm( true, self );
				} );
				box.querySelector( '.tianma-confirm-reject' ).addEventListener( 'click', function () {
					submitConfirm( false, self );
				} );
				scrollBottom();
			},

			finish: function ( d ) {
				this.el.querySelector( '.tianma-think' ).style.display = 'none';
				if ( d.text && this.outBox.innerHTML === '' ) {
					this.outBox.innerHTML = textToHtml( d.text );
				}
				if ( ! this.outBox.innerHTML ) {
					this.outBox.style.display = 'none';
				}
				var fb = document.createElement( 'div' );
				fb.className = 'tianma-feedback';
				fb.dataset.task = d.task_id || 0;
				fb.innerHTML = '这次任务满意吗？<button class="btn-fb up" title="满意">👍</button><button class="btn-fb down" title="不满意">👎</button>';
				this.el.appendChild( fb );
				convMsgs.push( { role: 'assistant', content: d.text || '（任务已完成）' } );
				loadConversations();
				loadUsage();
				scrollBottom();
			},

			showError: function ( msg ) {
				this.el.querySelector( '.tianma-think' ).style.display = 'none';
				this.el.querySelector( '.tianma-stream' ).insertAdjacentHTML( 'beforeend',
					'<div class="bubble tianma-err">⚠ ' + esc( msg ) + '</div>' );
				scrollBottom();
			}
		};
	}

	/* ---------------- 发送 ---------------- */

	function send() {
		var text = inputEl.value.trim();
		if ( ! text || busy ) { return; }

		var doSend = function ( convId ) {
			currentConv = convId;
			localStorage.setItem( 'tianma_conv_id', String( convId ) );
			convMsgs.push( { role: 'user', content: text } );
			inputEl.value = '';
			inputEl.style.height = 'auto';
			addMsg( 'user', '<div class="bubble">' + textToHtml( text ) + '</div>' );

			busy = true;
			sendBtn.disabled = true;
			highlightConv( convId );

			var ui = streamUI();
			fetchStream( {
				message: text,
				history: convMsgs.slice( 0, -1 ),
				conversation_id: convId,
				stream: true,
				auto_confirm: autoConfirmEl.checked,
				role_id: parseInt( barRole.value || '0', 10 ) || 0,
				max_steps: parseInt( maxStepsEl ? maxStepsEl.value : '0', 10 ) || 0,
				php_timeout: parseInt( phpTimeoutEl ? phpTimeoutEl.value : '0', 10 ) || 0
			}, ui ).catch( function ( e ) {
				ui.showError( errText( e ) + '（可稍后重试）' );
			} );
		};

		if ( currentConv ) {
			doSend( currentConv );
		} else {
			post( 'conversations', {} ).then( function ( d ) {
				doSend( d.id );
			} ).catch( function ( e ) {
				alert( errText( e ) );
			} );
		}
	}

	/* ---------------- 高危确认（非流式续跑） ---------------- */

	function submitConfirm( approve, ui ) {
		if ( ! pendingConfirm ) { return; }
		var id = pendingConfirm.id;
		pendingConfirm = null;

		var box = ui.el.querySelector( '.tianma-confirm' );
		if ( box ) { box.remove(); }

		ui.outBox.style.display = 'block';
		ui.outBox.innerHTML = '<div class="tianma-think"><span class="dot"></span><span class="dot"></span><span class="dot"></span>'
			+ '<span class="think-text">' + esc( I18N.thinking ) + '</span></div>';

		busy = true;
		sendBtn.disabled = true;

		post( 'confirm', { confirm_id: id, approve: approve } )
			.then( function ( d ) {
				var data = d && d.data ? d.data : d;
				ui.el.querySelector( '.tianma-think' ).style.display = 'none';
				if ( data && data.status === 'needs_confirmation' ) {
					ui.showConfirm( data );
					return;
				}
				if ( ! data || data.status === 'error' ) {
					ui.showError( ( data && data.error ) || I18N.error );
					return;
				}
				ui.outBox.innerHTML = data.text ? textToHtml( data.text ) : '';
				var fb = document.createElement( 'div' );
				fb.className = 'tianma-feedback';
				fb.dataset.task = data.task_id || 0;
				fb.innerHTML = '这次任务满意吗？<button class="btn-fb up" title="满意">👍</button><button class="btn-fb down" title="不满意">👎</button>';
				ui.el.appendChild( fb );
				convMsgs.push( { role: 'assistant', content: data.text || '（任务已完成）' } );
				loadConversations();
				loadUsage();
			} )
			.catch( function ( e ) {
				ui.showError( errText( e ) );
			} )
			.finally( function () {
				busy = false;
				sendBtn.disabled = false;
			} );
	}

	/* ---------------- 会话管理 ---------------- */

	function highlightConv( id ) {
		Array.prototype.forEach.call( convListEl.children, function ( li ) {
			li.classList.toggle( 'active', li.dataset.id === String( id ) );
		} );
	}

	function loadConversations() {
		fetch( apiUrl( 'conversations' ), { headers: { 'X-WP-Nonce': NONCE } } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( d ) {
				var items = d.items || [];
				if ( ! items.length ) {
					convListEl.innerHTML = '<li class="empty">暂无会话，点击「新对话」开始</li>';
					return;
				}
				convListEl.innerHTML = items.map( function ( c ) {
					var preview = ( c.last_reply || '' ).replace( /\s+/g, ' ' ).slice( 0, 40 );
					return '<li data-id="' + c.id + '">'
						+ '<div class="conv-title">' + esc( c.title ) + '</div>'
						+ '<div class="conv-preview">' + esc( preview || '（空对话）' ) + '</div>'
						+ '<span class="conv-del" data-id="' + c.id + '" title="删除会话">×</span>'
						+ '</li>';
				} ).join( '' );
				if ( currentConv ) {
					highlightConv( currentConv );
				}
			} );
	}

	function setConv( id ) {
		if ( busy ) { return; }
		currentConv = id;
		localStorage.setItem( 'tianma_conv_id', String( id ) );
		convMsgs = [];
		pendingConfirm = null;
		messagesEl.innerHTML = '';
		highlightConv( id );

		fetch( apiUrl( 'conversations/' + id + '/messages' ), { headers: { 'X-WP-Nonce': NONCE } } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( d ) {
				( d.items || [] ).forEach( function ( m ) {
					convMsgs.push( { role: m.role, content: m.content } );
					addMsg( m.role === 'user' ? 'user' : 'assistant', '<div class="bubble">' + textToHtml( m.content ) + '</div>' );
				} );
				if ( ! ( d.items || [] ).length ) {
					addMsg( 'assistant', '<div class="bubble">这个会话还没有消息，说点什么吧。</div>' );
				}
				loadUsage();
			} );
	}

	function deleteConv( id ) {
		if ( ! confirm( '确定删除该会话及全部消息？' ) ) { return; }
		fetch( apiUrl( 'conversations/' + id ), { method: 'DELETE', headers: { 'X-WP-Nonce': NONCE } } )
			.then( function ( r ) { return r.json(); } )
			.then( function () {
				if ( String( currentConv ) === String( id ) ) {
					currentConv = null;
					convMsgs = [];
					messagesEl.innerHTML = '';
					localStorage.removeItem( 'tianma_conv_id' );
				}
				loadConversations();
			} );
	}

	convListEl.addEventListener( 'click', function ( e ) {
		var del = e.target.closest && e.target.closest( '.conv-del' );
		if ( del ) {
			e.stopPropagation();
			deleteConv( del.dataset.id );
			return;
		}
		var li = e.target.closest && e.target.closest( 'li[data-id]' );
		if ( li ) {
			setConv( li.dataset.id );
		}
	} );

	/* ---------------- 清空聊天 ---------------- */

	var clearBtn = document.getElementById( 'tianma-btn-clear' );
	var clearMenu = document.getElementById( 'tianma-clear-menu' );

	clearBtn.addEventListener( 'click', function ( e ) {
		e.stopPropagation();
		clearMenu.style.display = clearMenu.style.display === 'none' ? 'block' : 'none';
	} );

	document.addEventListener( 'click', function ( e ) {
		if ( ! clearMenu.contains( e.target ) && e.target !== clearBtn ) {
			clearMenu.style.display = 'none';
		}
	} );

	clearMenu.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest && e.target.closest( '[data-act]' );
		if ( ! btn ) { return; }
		clearMenu.style.display = 'none';

		var act = btn.dataset.act;
		if ( act === 'current' ) {
			if ( ! currentConv ) { alert( '当前没有打开的会话' ); return; }
			if ( ! confirm( '确定清空当前会话的全部消息吗？会话会保留，标题重置为「新对话」。' ) ) { return; }
			post( 'conversations/' + currentConv + '/clear', {} ).then( function () {
				convMsgs = [];
				pendingConfirm = null;
				messagesEl.innerHTML = '';
				addMsg( 'assistant', '<div class="bubble">当前会话已清空，说点什么开始吧。</div>' );
				loadConversations();
				loadUsage();
			} );
		} else if ( act === 'all' ) {
			if ( ! confirm( '确定清空全部会话吗？所有聊天记录将被永久删除，此操作不可恢复！' ) ) { return; }
			post( 'conversations/clear-all', {} ).then( function () {
				currentConv = null;
				convMsgs = [];
				pendingConfirm = null;
				messagesEl.innerHTML = '';
				localStorage.removeItem( 'tianma_conv_id' );
				loadConversations();
				loadUsage();
				inputEl.focus();
			} );
		}
	} );

	/* ---------------- Token 用量 ---------------- */

	function fmtTokens( n ) {
		return n == null ? '-' : Number( n ).toLocaleString( 'zh-CN' );
	}

	function loadUsage() {
		fetch( apiUrl( 'usage', currentConv ? { conversation_id: currentConv } : {} ), { headers: { 'X-WP-Nonce': NONCE } } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( d ) {
				var conv = d.conversation;
				document.getElementById( 'usage-conv' ).textContent = conv
					? fmtTokens( conv.total_tokens ) + ' tok'
					: '0 tok';
				document.getElementById( 'usage-today' ).textContent = d.today
					? fmtTokens( d.today.total_tokens ) + ' tok' : '0 tok';
				document.getElementById( 'usage-total' ).textContent = d.total
					? fmtTokens( d.total.total_tokens ) + ' tok' : '0 tok';
				document.getElementById( 'usage-calls' ).textContent = d.calls || 0;
				var last = d.last_call;
				document.getElementById( 'usage-last' ).textContent = last
					? fmtTokens( last.total_tokens ) + ' tok' : '-';
			} );
	}

	/* ---------------- 模型切换条 + 免授权开关 ---------------- */

	var barProvider = document.getElementById( 'tianma-bar-provider' );
	var barModel = document.getElementById( 'tianma-bar-model' );
	var barSwitch = document.getElementById( 'tianma-bar-switch' );
	var barCurrent = document.getElementById( 'tianma-bar-current' );
	var barRole = document.getElementById( 'tianma-bar-role' );
	var autoConfirmEl = document.getElementById( 'tianma-auto-confirm' );
	var maxStepsEl = document.getElementById( 'tianma-max-steps' );
	var phpTimeoutEl = document.getElementById( 'tianma-php-timeout' );
	var presetsData = null;
	var barSettings = null;

	// 角色切换：加载全部角色 + 记住上次选择
	function loadRoles() {
		if ( ! barRole ) { return; }
		fetch( apiUrl( 'roles' ), { headers: { 'X-WP-Nonce': NONCE } } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( d ) {
				var items = d.items || [];
				barRole.innerHTML = '<option value="0">总调度（天马）</option>'
					+ items.filter( function ( r ) { return r.status === 'active'; } ).map( function ( r ) {
						return '<option value="' + r.id + '"' + ( r.id === currentRoleId ? ' selected' : '' ) + '>'
							+ esc( r.name ) + '</option>';
					} ).join( '' );
				barRole.value = currentRoleId ? String( currentRoleId ) : '0';
				updateRoleTip();
			} );
	}

	function updateRoleTip() {
		var rid = parseInt( barRole.value, 10 ) || 0;
		localStorage.setItem( 'tianma_role_id', String( rid ) );
		if ( rid && barRole.selectedOptions[ 0 ] ) {
			barCurrent.textContent = '当前角色：' + barRole.selectedOptions[ 0 ].text;
		} else {
			updateBarCurrent();
		}
	}

	var currentRoleId = parseInt( localStorage.getItem( 'tianma_role_id' ) || '0', 10 ) || 0;
	if ( barRole ) {
		barRole.addEventListener( 'change', updateRoleTip );
	}

	// 恢复上次的免授权状态 + 持久化
	if ( autoConfirmEl ) {
		autoConfirmEl.checked = localStorage.getItem( 'tianma_auto_confirm' ) === '1';
		autoConfirmEl.addEventListener( 'change', function () {
			localStorage.setItem( 'tianma_auto_confirm', autoConfirmEl.checked ? '1' : '0' );
			autoConfirmEl.closest( '.tianma-auto-confirm' ).classList.toggle( 'on', autoConfirmEl.checked );
			var tip = autoConfirmEl.closest( '.tianma-model-bar' ).querySelector( '.auto-confirm-tip' );
			if ( tip ) {
				tip.textContent = autoConfirmEl.checked ? '⚠ 高危操作将直接执行' : '';
			}
		} );
		autoConfirmEl.dispatchEvent( new Event( 'change' ) );
	}

	// 恢复上次的执行参数（最大工具步数 / PHP 超时）并持久化
	if ( maxStepsEl ) {
		var savedMs = parseInt( localStorage.getItem( 'tianma_max_steps' ), 10 );
		if ( ! isNaN( savedMs ) ) { maxStepsEl.value = savedMs; }
		maxStepsEl.addEventListener( 'change', function () {
			localStorage.setItem( 'tianma_max_steps', String( parseInt( maxStepsEl.value, 10 ) || 0 ) );
		} );
	}
	if ( phpTimeoutEl ) {
		var savedPt = parseInt( localStorage.getItem( 'tianma_php_timeout' ), 10 );
		if ( ! isNaN( savedPt ) ) { phpTimeoutEl.value = savedPt; }
		phpTimeoutEl.addEventListener( 'change', function () {
			localStorage.setItem( 'tianma_php_timeout', String( parseInt( phpTimeoutEl.value, 10 ) || 0 ) );
		} );
	}

	function loadModelBar() {
		fetch( apiUrl( 'settings' ), { headers: { 'X-WP-Nonce': NONCE } } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( d ) {
				presetsData = d.presets || {};
				barSettings = d.settings || {};

				barProvider.innerHTML = Object.keys( presetsData ).map( function ( k ) {
					var p = presetsData[ k ];
					return '<option value="' + esc( k ) + '"' + ( k === barSettings.provider ? ' selected' : '' ) + '>'
						+ esc( p.label ) + '</option>';
				} ).join( '' );

				fillBarModels( barSettings.provider, barSettings.model );
				updateBarCurrent();
			} );
	}

	function fillBarModels( providerKey, selectedModel ) {
		var p = presetsData[ providerKey ] || {};
		var models = p.models || [];
		var html = '';
		models.forEach( function ( m ) {
			html += '<option value="' + esc( m ) + '"' + ( m === selectedModel ? ' selected' : '' ) + '>' + esc( m ) + '</option>';
		} );
		// 当前模型不在列表中也允许保留（可手动输入场景）
		if ( selectedModel && models.indexOf( selectedModel ) < 0 ) {
			html += '<option value="' + esc( selectedModel ) + '" selected>' + esc( selectedModel ) + '</option>';
		}
		barModel.innerHTML = html;
	}

	function updateBarCurrent() {
		var p = presetsData[ barSettings.provider ] || {};
		var label = p.label || barSettings.provider;
		var keyInfo = barSettings.has_key ? '' : '（未配置 Key）';
		barCurrent.textContent = '当前：' + label + ' · ' + barSettings.model + keyInfo;
	}

	barProvider.addEventListener( 'change', function () {
		var p = presetsData[ barProvider.value ] || {};
		fillBarModels( barProvider.value, p.model || '' );
	} );

	barSwitch.addEventListener( 'click', function () {
		var p = presetsData[ barProvider.value ] || {};
		if ( ! p.base_url ) {
			alert( '该服务商无预设 API 地址，请到「模型设置」中配置' );
			return;
		}
		barSwitch.disabled = true;
		// 只切换 provider + model；base_url 由后端智能处理（自定义地址不会被覆盖）
		post( 'settings', {
			provider: barProvider.value,
			model: barModel.value
		} ).then( function ( d ) {
			barSettings = d.settings;
			updateBarCurrent();
			barSwitch.textContent = '✓ 已切换';
			setTimeout( function () { barSwitch.textContent = '切换'; }, 1500 );
		} ).catch( function ( e ) {
			alert( '切换失败：' + errText( e ) );
		} ).finally( function () {
			barSwitch.disabled = false;
		} );
	} );

	/* ---------------- 反馈 ---------------- */

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest && e.target.closest( '.btn-fb' );
		if ( ! btn ) { return; }
		var box = btn.closest( '.tianma-feedback' );
		if ( ! box || box.dataset.done ) { return; }
		box.dataset.done = '1';
		var rating = btn.classList.contains( 'up' ) ? 5 : 1;
		post( 'feedback', { task_id: box.dataset.task, rating: rating, note: '' } ).then( function () {
			box.textContent = '已记录你的反馈，天马会越用越聪明 ✨';
		} );
	} );

	/* ---------------- 输入框 ---------------- */

	inputEl.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Enter' && ! e.shiftKey ) {
			e.preventDefault();
			send();
		}
	} );
	inputEl.addEventListener( 'input', function () {
		inputEl.style.height = 'auto';
		inputEl.style.height = Math.min( inputEl.scrollHeight, 160 ) + 'px';
	} );
	sendBtn.addEventListener( 'click', send );

	/* ---------------- 新对话 ---------------- */

	newBtn.addEventListener( 'click', function () {
		if ( busy ) { return; }
		currentConv = null;
		convMsgs = [];
		pendingConfirm = null;
		messagesEl.innerHTML = '';
		localStorage.removeItem( 'tianma_conv_id' );
		highlightConv( null );
		inputEl.focus();
	} );

	/* ---------------- 记忆库 ---------------- */

	function loadMemories() {
		fetch( apiUrl( 'memories' ), { headers: { 'X-WP-Nonce': NONCE } } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( d ) {
				memoryCountEl.textContent = ( d.items || [] ).length;
			} );
	}

	memoriesBtn.addEventListener( 'click', function () {
		fetch( apiUrl( 'memories' ), { headers: { 'X-WP-Nonce': NONCE } } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( d ) {
				var items = d.items || [];
				var html = '<div class="tianma-memories-modal">'
					+ '<div class="modal-inner"><h2>记忆库（' + items.length + ' 条）</h2>'
					+ '<button class="button modal-close">关闭</button>';
				if ( ! items.length ) {
					html += '<p>暂无记忆。完成任务后经验会自动沉淀到这里。</p>';
				}
				html += '<ul class="mem-list">';
				items.forEach( function ( m ) {
					var labels = { episodic: '情景', semantic: '语义', procedural: '技能' };
					html += '<li><span class="tag">' + ( labels[ m.m_type ] || m.m_type ) + '</span>'
						+ '<strong>' + esc( m.title ) + '</strong>'
						+ '<div class="meta">权重 ' + esc( m.weight ) + ' · 命中 ' + esc( m.hit_count ) + ' 次 · ' + esc( m.created_at ) + '</div>'
						+ '<div class="preview">' + esc( m.preview ) + '</div></li>';
				} );
				html += '</ul></div></div>';

				var modal = document.createElement( 'div' );
				modal.innerHTML = html;
				document.body.appendChild( modal );
				modal.querySelector( '.modal-close' ).addEventListener( 'click', function () { modal.remove(); } );
				modal.addEventListener( 'click', function ( e ) { if ( e.target === modal ) { modal.remove(); } } );
			} );
	} );

	/* ---------------- 可视化文件编辑器 ---------------- */

	var editorModal = document.getElementById( 'tianma-editor-modal' );
	var editorPath = document.getElementById( 'tianma-editor-path' );
	var editorContent = document.getElementById( 'tianma-editor-content' );
	var editorMsg = document.getElementById( 'tianma-editor-msg' );

	function openEditor( path, content ) {
		editorPath.textContent = path;
		editorContent.value = content || '';
		editorMsg.textContent = '';
		editorModal.style.display = 'flex';
		editorContent.focus();
	}

	function closeEditor() {
		editorModal.style.display = 'none';
		editorMsg.textContent = '';
	}

	function saveEditor() {
		var path = editorPath.textContent;
		var content = editorContent.value;
		if ( ! path ) { return; }
		var btn = document.getElementById( 'tianma-editor-save' );
		btn.disabled = true;
		editorMsg.textContent = '保存中…';
		editorMsg.style.color = '#888';
		post( 'file-save', { path: path, content: content } ).then( function ( d ) {
			if ( d.code && d.message ) {
				editorMsg.textContent = '❌ ' + d.message;
				editorMsg.style.color = '#a32d2d';
			} else if ( d.ok ) {
				editorMsg.textContent = '✅ ' + ( d.message || '已保存' );
				editorMsg.style.color = '#0f6e56';
				addMsg( 'assistant', '<div class="bubble">✅ 你手动保存了文件：<code>' + esc( path ) + '</code></div>' );
			} else {
				editorMsg.textContent = '❌ 保存失败';
				editorMsg.style.color = '#a32d2d';
			}
		} ).catch( function ( e ) {
			editorMsg.textContent = '❌ 请求失败：' + errText( e );
			editorMsg.style.color = '#a32d2d';
		} ).finally( function () { btn.disabled = false; } );
	}

	document.getElementById( 'tianma-editor-save' ).addEventListener( 'click', saveEditor );
	document.getElementById( 'tianma-editor-close' ).addEventListener( 'click', closeEditor );
	editorModal.addEventListener( 'click', function ( e ) { if ( e.target === editorModal ) { closeEditor(); } } );
	editorContent.addEventListener( 'keydown', function ( e ) {
		if ( ( e.ctrlKey || e.metaKey ) && 's' === e.key.toLowerCase() ) { e.preventDefault(); saveEditor(); }
	} );

	/* ---------------- 启动：恢复上次会话 ---------------- */

	loadConversations();
	loadMemories();
	loadUsage();
	loadModelBar();
	loadRoles();

	var savedConv = localStorage.getItem( 'tianma_conv_id' );
	if ( savedConv ) {
		fetch( apiUrl( 'conversations' ), { headers: { 'X-WP-Nonce': NONCE } } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( d ) {
				var items = d.items || [];
				var found = items.filter( function ( c ) { return String( c.id ) === savedConv; } )[ 0 ];
				if ( found ) { setConv( found.id ); }
				else if ( items[ 0 ] ) { setConv( items[ 0 ].id ); }
			} );
	}
	inputEl.focus();
})();
