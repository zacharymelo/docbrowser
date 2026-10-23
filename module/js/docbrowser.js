/* Copyright (C) 2026 DPG Supply - Document Browser
 * Dialog to pick files stored in Dolibarr, and its use on core's Send by email form.
 *
 *   DocBrowser.open({
 *     context: {socid, contactid, projectid, origin, originid, trackid} or a function returning it,
 *     onPick: function (files) {}   // [{key, name, size, sizetext, mime, icon, thumb, record}]
 *   });
 *
 * Everything shown comes from ajax/list.php and is built from text nodes. Keys are checked again by the
 * server when the files are attached, so nothing here decides what a user may send.
 */
(function () {
	'use strict';

	var C = window.DocBrowserConfig || { labels: {}, listurl: '', maxbytes: 0 };

	function L(key) { return (C.labels && C.labels[key]) || key; }

	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		if (text !== undefined && text !== null) { n.textContent = text; }
		return n;
	}

	function getJSON(params) {
		var q = new URLSearchParams();
		Object.keys(params).forEach(function (k) {
			if (params[k] !== undefined && params[k] !== null && params[k] !== '') { q.append(k, params[k]); }
		});
		return fetch(C.listurl + '?' + q.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
			.then(function (r) {
				return r.json().catch(function () { return {}; }).then(function (data) {
					if (!r.ok) { var e = new Error(data.error || String(r.status)); e.status = r.status; throw e; }
					return data;
				});
			});
	}

	function debounce(fn, ms) {
		var t = null;
		return function () {
			var args = arguments, self = this;
			clearTimeout(t);
			t = setTimeout(function () { fn.apply(self, args); }, ms);
		};
	}

	function message(box, text, cls) {
		box.textContent = '';
		box.appendChild(el('div', 'docbrowser-msg ' + (cls || 'opacitymedium'), text));
	}

	function failed(box, err) {
		message(box, err && err.status === 403 ? L('DocBrowserNoAccess') : L('DocBrowserError'), 'error');
	}

	/**
	 * The dialog. One instance per open().
	 */
	function Browser(opts) {
		this.opts = opts || {};
		this.selected = {};	// key => item
		this.build();
	}

	Browser.prototype.context = function () {
		var c = this.opts.context;
		return (typeof c === 'function' ? c() : c) || {};
	};

	Browser.prototype.build = function () {
		var self = this;
		var root = el('div', 'docbrowser');
		var tabs = el('div', 'docbrowser-tabs');
		var panels = el('div', 'docbrowser-panels');
		this.status = el('div', 'docbrowser-status opacitymedium');
		this.panels = {};

		[['linked', 'DocBrowserTabLinked'], ['products', 'DocBrowserTabProducts'], ['documents', 'DocBrowserTabDocuments'], ['search', 'DocBrowserTabSearch']].forEach(function (t) {
			var b = el('button', 'docbrowser-tab', L(t[1]));
			b.type = 'button';
			b.setAttribute('data-tab', t[0]);
			b.addEventListener('click', function () { self.show(t[0]); });
			tabs.appendChild(b);
			var p = el('div', 'docbrowser-panel');
			p.setAttribute('data-tab', t[0]);
			panels.appendChild(p);
			self.panels[t[0]] = p;
		});
		this.tabs = tabs;
		root.appendChild(tabs);
		root.appendChild(panels);
		root.appendChild(this.status);
		this.root = root;
		this.loaded = {};
		this.updateStatus();
	};

	Browser.prototype.show = function (tab) {
		Array.prototype.forEach.call(this.tabs.children, function (b) {
			b.classList.toggle('docbrowser-tab-active', b.getAttribute('data-tab') === tab);
		});
		Object.keys(this.panels).forEach(function (k) {
			this.panels[k].style.display = (k === tab) ? '' : 'none';
		}, this);
		if (!this.loaded[tab]) {
			this.loaded[tab] = true;
			this['load_' + tab](this.panels[tab]);
		}
	};

	Browser.prototype.updateStatus = function () {
		var n = Object.keys(this.selected).length;
		var bytes = 0;
		Object.keys(this.selected).forEach(function (k) { bytes += this.selected[k].size || 0; }, this);
		this.status.textContent = n ? L('DocBrowserSelected').replace('__N__', n) + ' · ' + formatSize(bytes) : '';
	};

	/** Grid of files with a tick box each */
	Browser.prototype.grid = function (files, showRecord) {
		var self = this;
		var grid = el('div', 'docbrowser-grid');
		if (!files || !files.length) {
			grid.appendChild(el('div', 'docbrowser-msg opacitymedium', L('DocBrowserNoFiles')));
			return grid;
		}
		files.forEach(function (f) {
			var tile = el('label', 'docbrowser-tile');
			tile.title = f.name + (f.record ? '\n' + f.record : '');
			var cb = el('input');
			cb.type = 'checkbox';
			cb.checked = !!self.selected[f.key];
			cb.addEventListener('change', function () {
				if (cb.checked) { self.selected[f.key] = f; } else { delete self.selected[f.key]; }
				tile.classList.toggle('docbrowser-tile-on', cb.checked);
				self.updateStatus();
			});
			tile.classList.toggle('docbrowser-tile-on', cb.checked);
			var preview = el('span', 'docbrowser-preview');
			if (f.thumb) {
				var img = el('img');
				img.loading = 'lazy';
				img.alt = '';
				img.src = f.thumb;
				preview.appendChild(img);
			} else {
				preview.appendChild(el('span', 'fa fa-2x fa-' + (f.icon || 'file')));
			}
			tile.appendChild(cb);
			tile.appendChild(preview);
			tile.appendChild(el('span', 'docbrowser-name', f.name));
			tile.appendChild(el('span', 'docbrowser-size opacitymedium', f.sizetext + (showRecord && f.record ? ' · ' + f.record : '')));
			grid.appendChild(tile);
		});
		return grid;
	};

	/** Section with a header, files shown now or loaded when opened */
	Browser.prototype.section = function (box, rec) {
		var self = this;
		var sec = el('div', 'docbrowser-section');
		var head = el('div', 'docbrowser-section-head');
		var toggle = el('span', 'fa fa-fw ' + (rec.files ? 'fa-caret-down' : 'fa-caret-right'));
		head.appendChild(toggle);
		head.appendChild(el('span', 'docbrowser-section-label', rec.label));
		if (rec.files) { head.appendChild(el('span', 'opacitymedium', ' (' + rec.files.length + ')')); }
		var body = el('div', 'docbrowser-section-body');
		if (rec.files) {
			body.appendChild(this.grid(rec.files));
		} else {
			body.style.display = 'none';
		}
		head.addEventListener('click', function () {
			var open = body.style.display === 'none';
			body.style.display = open ? '' : 'none';
			toggle.className = 'fa fa-fw ' + (open ? 'fa-caret-down' : 'fa-caret-right');
			if (open && !body.hasChildNodes()) {
				message(body, L('DocBrowserLoading'));
				getJSON({ mode: 'record', element: rec.element, id: rec.id }).then(function (data) {
					body.textContent = '';
					body.appendChild(self.grid(data.files));
				}).catch(function (e) { failed(body, e); });
			}
		});
		sec.appendChild(head);
		sec.appendChild(body);
		box.appendChild(sec);
	};

	Browser.prototype.load_linked = function (panel) {
		var self = this;
		message(panel, L('DocBrowserLoading'));
		var ctx = this.context();
		getJSON({ mode: 'linked', socid: ctx.socid, contactid: ctx.contactid, projectid: ctx.projectid, origin: ctx.origin, originid: ctx.originid, trackid: ctx.trackid }).then(function (data) {
			panel.textContent = '';
			var records = data.records || [];
			if (!records.length) {
				message(panel, L('DocBrowserNoLinked'));
				return;
			}
			var recentDone = false;
			records.forEach(function (r) {
				if (r.recent && !recentDone) {
					recentDone = true;
					panel.appendChild(el('div', 'docbrowser-subtitle', L('DocBrowserRecent')));
				}
				self.section(panel, r);
			});
		}).catch(function (e) { failed(panel, e); });
	};

	Browser.prototype.load_products = function (panel) {
		var self = this;
		panel.textContent = '';
		var input = el('input', 'flat minwidth300');
		input.type = 'search';
		input.placeholder = L('DocBrowserSearchProducts');
		var results = el('div', 'docbrowser-results');
		panel.appendChild(input);
		panel.appendChild(results);
		message(results, L('DocBrowserTypeMore'));
		input.addEventListener('input', debounce(function () {
			var term = input.value.trim();
			if (term.length < 2) { message(results, L('DocBrowserTypeMore')); return; }
			message(results, L('DocBrowserLoading'));
			getJSON({ mode: 'products', term: term }).then(function (data) {
				results.textContent = '';
				if (!data.products || !data.products.length) { message(results, L('DocBrowserNoFiles')); return; }
				data.products.forEach(function (p) {
					self.section(results, { element: 'product', id: p.id, label: p.ref + ' - ' + p.label, files: null });
				});
			}).catch(function (e) { failed(results, e); });
		}, 300));
		setTimeout(function () { input.focus(); }, 0);
	};

	Browser.prototype.load_documents = function (panel) {
		var self = this;
		panel.textContent = '';
		var roots = el('div', 'docbrowser-roots');
		var body = el('div', 'docbrowser-folder');
		[['e', 'DocBrowserDocuments', 'fa-folder-open'], ['m', 'DocBrowserMedias', 'fa-image']].forEach(function (r) {
			var b = el('button', 'button smallpaddingimp');
			b.type = 'button';
			b.appendChild(el('span', 'fa fa-fw ' + r[2]));
			b.appendChild(document.createTextNode(' ' + L(r[1])));
			b.addEventListener('click', function () { self.openFolder(body, r[0], ''); });
			roots.appendChild(b);
		});
		panel.appendChild(roots);
		panel.appendChild(body);
		this.openFolder(body, 'e', '');
	};

	Browser.prototype.openFolder = function (body, kind, folder) {
		var self = this;
		message(body, L('DocBrowserLoading'));
		getJSON({ mode: 'folder', kind: kind, folder: folder }).then(function (data) {
			body.textContent = '';
			var crumbs = el('div', 'docbrowser-crumbs');
			var top = el('a', '', L(kind === 'e' ? 'DocBrowserDocuments' : 'DocBrowserMedias'));
			top.href = '#';
			top.addEventListener('click', function (ev) { ev.preventDefault(); self.openFolder(body, kind, ''); });
			crumbs.appendChild(top);
			var path = '';
			(data.folder ? data.folder.split('/') : []).forEach(function (seg) {
				path = path ? path + '/' + seg : seg;
				var target = path;
				crumbs.appendChild(document.createTextNode(' / '));
				var a = el('a', '', seg);
				a.href = '#';
				a.addEventListener('click', function (ev) { ev.preventDefault(); self.openFolder(body, kind, target); });
				crumbs.appendChild(a);
			});
			body.appendChild(crumbs);
			if (data.folders && data.folders.length) {
				var list = el('div', 'docbrowser-folders');
				data.folders.forEach(function (name) {
					var a = el('a', 'docbrowser-foldername');
					a.href = '#';
					a.appendChild(el('span', 'fa fa-fw fa-folder'));
					a.appendChild(document.createTextNode(' ' + name));
					a.addEventListener('click', function (ev) {
						ev.preventDefault();
						self.openFolder(body, kind, data.folder ? data.folder + '/' + name : name);
					});
					list.appendChild(a);
				});
				body.appendChild(list);
			}
			body.appendChild(self.grid(data.files));
		}).catch(function (e) { failed(body, e); });
	};

	Browser.prototype.load_search = function (panel) {
		var self = this;
		panel.textContent = '';
		var input = el('input', 'flat minwidth300');
		input.type = 'search';
		input.placeholder = L('DocBrowserSearchFiles');
		var help = el('div', 'docbrowser-help opacitymedium', L('DocBrowserSearchHelp'));
		var results = el('div', 'docbrowser-results');
		panel.appendChild(input);
		panel.appendChild(help);
		panel.appendChild(results);
		input.addEventListener('input', debounce(function () {
			var term = input.value.trim();
			if (term.length < 2) { results.textContent = ''; return; }
			message(results, L('DocBrowserLoading'));
			getJSON({ mode: 'search', term: term }).then(function (data) {
				results.textContent = '';
				results.appendChild(self.grid(data.files, true));
			}).catch(function (e) { failed(results, e); });
		}, 300));
		setTimeout(function () { input.focus(); }, 0);
	};

	Browser.prototype.open = function () {
		var self = this;
		var $ = window.jQuery;
		var picked = function () {
			var files = Object.keys(self.selected).map(function (k) { return self.selected[k]; });
			if (files.length && self.opts.onPick) { self.opts.onPick(files); }
		};
		this.show('linked');
		if ($ && $.fn.dialog) {
			$(this.root).dialog({
				title: L('DocBrowserTitle'),
				modal: true,
				width: Math.min(920, window.innerWidth - 20),
				height: Math.min(640, window.innerHeight - 40),
				buttons: [
					{ text: L('DocBrowserAdd'), click: function () { picked(); $(this).dialog('close'); } },
					{ text: L('DocBrowserCancel'), click: function () { $(this).dialog('close'); } }
				],
				open: function () { $(this).dialog('widget').css({ position: 'fixed', top: '20px' }); },	// stays in view while the page scrolls
				close: function () { $(this).dialog('destroy').remove(); }
			});
			return;
		}
		// No jQuery UI: a plain overlay
		var overlay = el('div', 'docbrowser-overlay');
		var box = el('div', 'docbrowser-box');
		var bar = el('div', 'docbrowser-buttons');
		var add = el('button', 'button', L('DocBrowserAdd'));
		var cancel = el('button', 'button button-cancel', L('DocBrowserCancel'));
		add.type = cancel.type = 'button';
		add.addEventListener('click', function () { picked(); overlay.remove(); });
		cancel.addEventListener('click', function () { overlay.remove(); });
		bar.appendChild(add);
		bar.appendChild(cancel);
		box.appendChild(el('div', 'docbrowser-boxtitle', L('DocBrowserTitle')));
		box.appendChild(this.root);
		box.appendChild(bar);
		overlay.appendChild(box);
		document.body.appendChild(overlay);
	};

	function formatSize(bytes) {
		if (bytes < 1024) { return bytes + ' B'; }
		if (bytes < 1048576) { return (bytes / 1024).toFixed(0) + ' KB'; }
		return (bytes / 1048576).toFixed(1) + ' MB';
	}

	window.DocBrowser = {
		open: function (opts) { new Browser(opts).open(); },
		maxBytes: C.maxbytes || 0,
		formatSize: formatSize,
		label: L,
		el: el
	};

	/*
	 * Core's Send by email form (ActionsDocBrowser::getFormMail prints window.DocBrowserFormMail)
	 */
	function formMail(cfg) {
		var attachBtn = document.getElementById(cfg.addfileid);
		if (!attachBtn || document.querySelector('.docbrowser-open')) {
			return;
		}
		var form = attachBtn.form;
		var cell = attachBtn.parentNode;
		var button = el('button', 'button smallpaddingimp docbrowser-open', L('DocBrowserAddFromDolibarr'));
		button.type = 'button';
		attachBtn.insertAdjacentElement('afterend', button);
		attachBtn.insertAdjacentText('afterend', ' ');
		var linkBox = el('div', 'docbrowser-linkrows');
		var fileInput = cell.querySelector('input[type=file]');
		cell.insertBefore(linkBox, fileInput || attachBtn);

		function token() {
			var t = form && form.querySelector('input[name=token]');
			return t ? t.value : '';
		}

		function linkRow(f) {
			var row = el('div', 'docbrowser-linkrow');
			row.appendChild(el('span', 'fa fa-link pictofixedwidth'));
			row.appendChild(document.createTextNode(f.name + ' (' + f.sizetext + ') '));
			row.appendChild(el('span', 'opacitymedium', L('DocBrowserWillLink')));
			var x = el('a', 'docbrowser-x', '×');
			x.href = '#';
			x.title = L('DocBrowserRemove');
			x.addEventListener('click', function (ev) {
				ev.preventDefault();
				post({ action: 'unlink', key: f.key }).then(function () { row.remove(); });
			});
			row.appendChild(x);
			linkBox.appendChild(row);
		}

		function attachRow(f) {
			// Core's own row markup, so its remove button and the next form reload behave the same
			var row = el('div');
			row.id = 'attachfile_' + f.index;
			row.appendChild(el('span', 'fa fa-file-o pictofixedwidth'));
			row.appendChild(document.createTextNode(f.name + ' (' + f.sizetext + ') '));
			var del = el('input', 'removedfile input-nobottom');
			del.type = 'image';
			del.src = cfg.deleteimg;
			del.style.border = '0';
			del.value = String(f.index + 1);
			del.name = 'removedfile_' + f.index;
			del.id = 'removedfile_' + f.index;
			del.addEventListener('click', function () {
				var hidden = form.querySelector('.removedfilehidden');
				if (hidden) { hidden.value = del.value; }
			});
			row.appendChild(del);
			var rows = cell.querySelectorAll('[id^=attachfile_]');
			if (rows.length) {
				rows[rows.length - 1].insertAdjacentElement('afterend', row);
			} else {
				cell.insertBefore(row, linkBox);
			}
		}

		function post(fields) {
			var data = new FormData();
			data.append('token', token());
			data.append('trackid', cfg.trackid);
			Object.keys(fields).forEach(function (k) { data.append(k, fields[k]); });
			return fetch(cfg.attachurl, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) {
				return r.json().then(function (j) { if (!r.ok) { throw new Error(j.error || r.status); } return j; });
			});
		}

		(cfg.pending || []).forEach(linkRow);

		button.addEventListener('click', function () {
			window.DocBrowser.open({
				context: { trackid: cfg.trackid },
				onPick: function (files) {
					// One at a time: each answer depends on the total so far
					files.reduce(function (p, f) {
						return p.then(function () {
							return post({ action: 'attach', key: f.key }).then(function (res) {
								if (res.mode === 'link') { linkRow(res); } else { attachRow(res); }
							}).catch(function (e) { window.alert(f.name + ': ' + e.message); });
						});
					}, Promise.resolve());
				}
			});
		});
	}

	function init() {
		if (window.DocBrowserFormMail) { formMail(window.DocBrowserFormMail); }
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
