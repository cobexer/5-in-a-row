/**
 * 5-in-a-row <http://cobexer.github.com/5-in-a-row/>
 * Copyright (c) 2012 Obexer Christoph. All rights reserved.
 *
 * Released under the MIT and GPL licenses.
 */
(function($) {

$.widget("cobexer.chat", {
	options: {
		lngPlayersOnline: 'Players online',
		lngOpenClose: 'Open / Close',
		lngDisconnectConnect: 'Disconnect / Connect',
		lngOffline: 'offline',
		lngButtonSend: 'Send',
		channels: [],
		open: false
	},
	_create: function() {
		var o = this.options;
		this.state = {
			open: o.open,
			online: true,
			channel: 0,
			magic: 0
		};
		this.$panelButton = $('<div><span class="ui-icon ui-icon-triangle-1-n"></span></div>').attr('title', o.lngOpenClose);
		this.$connectionButton = $('<div><span class="ui-icon ui-icon-cancel"></span></div>').attr('title', o.lngDisconnectConnect);
		this.state.initializing = true;
		this.element
			.appendTo(document.body)
			.addClass('ui-widget ui-widget-header ui-corner-top cobexer-chat')
			.append($('<span></span>').text('0'))
			.append('&nbsp;')
			.append($('<span></span>').text(o.lngPlayersOnline))
			.append(this.$panelButton)
			.append(this.$connectionButton)
			.find('>div')
				.hover(function() {
					var $t = $(this);
					if (!$t.hasClass('ui-state-disabled')) {
						$t.addClass('ui-state-hover');
					}
				}, function() {
					$(this).removeClass('ui-state-hover');
				})
				.addClass('ui-state-default')
				.filter(':eq(0)')
					.on('click', $.proxy(this._toggleOpen, this))
					.css({
						right: 21
					})
				.end()
				.filter(':eq(1)')
					.addClass('ui-corner-tr')
					.css({
						right: -1
					})
					.on('click', $.proxy(this._toggleOnline, this))
				.end()
			.end();
		var i = this.options.channels.length;
		while(i--) {
			this.options.channels[i] = {
				name: this.options.channels[i],
				hasNewMsg: false,
				numNewMsg: 0
			};
		}
		this.state.online = !this.state.online;
		this._toggleOnline();
		this.state.open = !this.state.open;
		this._toggleOpen();
		delete this.state.initializing;
	},

	destroy: function() {
		delete this.$panelButton;
		delete this.$connectionButton;
		this.element.children().remove();
		if (this.chatpanel) {
			this.chatpanel
				.find('>div:last')
					.remove()
				.end()
				.remove();
			delete this.chatpanel;
		}
		$.Widget.prototype.destroy.apply(this, arguments);
		return this;
	},

	_setOption: function(key, value) {
		$.Widget.prototype._setOption.apply(this, arguments);
	},

	_getChannel: function(channel) {
		var c = this.options.channels, i = c.length;
		while(i--) {
			if (channel == c[i].name) {
				return [c[i], i];
			}
		}
		return [null, -1];
	},

	clearChannel: function(channel) {
		var c = this._getChannel(channel)[0];
		if (c && c.msglist) {
			c.msglist.children().remove();
		}
	},

	setOnlineUsers: function(channel, users) {
		var c = this._getChannel(channel)[0];
		if (c && c.userlist) {
			c.userlist.children().remove();
			var $users = $('<ul class="cobexer-chat-userlist"></ul>');
			var idx = 0;
			for (;idx < users.length;idx++) {
				var $user = $('<li><span class="ui-icon ui-icon-person"></span></li>')
					.append($('<a href="#"></a>').text(users[idx].n));
				$users.append($user);
			}
			c.userlist.append($users);
			delete c.usersonline;
		}
		else if (c) {
			c.usersonline = users;
		}
	},

	addMessage: function(channel, message, suppressNew) {
		var c = this._getChannel(channel);
		var idx = c[1];
		c = c[0];
		if (c) {
			if (c.msglist) {
				c.numNewMsg += this._writeMessage(c.msglist, message);
			}
			else {
				c.msgbuffer = c.msgbuffer || [];
				c.msgbuffer = c.msgbuffer.splice(49, 1);
				c.msgbuffer.push(message);
				if('object' == typeof message && message.length) {
					c.numNewMsg += message.length;
				}
				else {
					c.numNewMsg++;
				}
			}
			if (!suppressNew && this.chatpanel && (!this.state.open || this.state.channel !== idx)) {
				this.chatpanel
					.find('>ul:first>li:eq('+idx+')>a>span')
						.show()
						.text(' ('+c.numNewMsg+')')
					.end();
			}
			if (!suppressNew && (this.state.open && this.state.channel !== idx) && !c.hasNewMsg) {
				c.hasNewMsg = true;
				var $p = this.chatpanel.find('>ul:first>li:eq('+idx+')');
				if (!$p.is(':animated')) {
					$p
						.animate({ 'opacity': 0 }, 500)
						.animate({ 'opacity': 1 }, 500)
						.animate({ 'opacity': 0 }, 500)
						.animate({ 'opacity': 1 }, 500);
				}
			}
			else if (!suppressNew && !this.state.open) {
				c.hasNewMsg = true;
				this.element
					.animate({ 'opacity': 0 }, 500)
					.animate({ 'opacity': 1 }, 500)
					.animate({ 'opacity': 0 }, 500)
					.animate({ 'opacity': 1 }, 500);
			}
			else {
				c.numNewMsg = 0;
			}
		}
	},

	_writeMessage: function(list, message) {
		var cnt = 0;
		if (Array == message.constructor) {
			var $msgs = $('<div></div>');
			for(var i = 0; i < message.length; i++) {
				var $msg = $('<div></div>');
				$msg.append($('<span></span>').text(message[i].timestamp));
				$msg.append($('<span></span>').text(' [' + message[i].name + ']: '));
				$msg.append($('<span></span>').text(message[i].text));
				$msgs.append($msg);
				cnt++;
			}
			list
				.append($msgs.children())
				.attr('scrollTop', 20000);
		}
		else {
			var $msg = $('<div></div>');
			$msg.append($('<span></span>').text(message.timestamp));
			$msg.append($('<span></span>').text(' [' + message.name + ']: '));
			$msg.append($('<span></span>').text(message.text));
			cnt++;
			list
				.append($msg)
				.attr('scrollTop', 20000);
		}
		var mcnt = list.children().length - 50;
		if (mcnt > 0) {
			list.find('>div:eq('+mcnt+')').prevAll().remove();
		}
		return cnt;
	},

	_select: function(e, ui) {
		var $t = $(ui.tab), ch;
		this.state.channel = $t.parent().prevAll().length;
		ch = this.options.channels[this.state.channel];
		ch.hasNewMsg = false;
		ch.numNewMsg = 0;
		$t.find('span').text('').hide();
	},

	_send: function(e) {
		var s = this.input.val();
		if (s) {
			this._trigger('send', null, { channel: this.options.channels[this.state.channel].name, text: s });
			this.input.val('')[0].focus();
		}
	},

	_inputhandler: function(e) {
		if (e.which == $.ui.keyCode.ENTER) {
			this._send(e);
		}
	},

	_toggleOnline: function() {
		if (this.state.online) {
			if (this.chatpanel) {
				this.chatpanel.hide();
			}
			this.element
				.find('>span:first')
					.text(this.options.lngOffline)
					.next()
						.hide()
					.end()
				.end()
				.find('>div:first')
					.addClass("ui-state-disabled")
					.find('>.ui-icon')
						.removeClass('ui-icon-triangle-1-s')
						.addClass('ui-icon-triangle-1-n')
					.end()
				.end();
			this.state.open = false;
			this.state.online = false;
			this.oldnumonline = 0;
			if (!this.state.initializing) {
				this._trigger('disconnect');
			}
		}
		else {
			this.element
				.find('>span:first')
					.text("0")
					.next()
						.show()
					.end()
				.end()
				.find('>div:first')
					.removeClass('ui-state-disabled')
				.end();
			this.state.online = true;
			var ch = this.options.channels, idx = ch.length;
			var channels = [];
			while(idx--) {
				channels.push(ch[idx].name);
				ch[idx].msglist && ch[idx].msglist.children().remove();
				ch[idx].userlist && ch[idx].userlist.children().remove();
			}
			this._trigger('connect', null, channels);
		}
	},

	setNumOnlinePlayers: function(num) {
		this.oldnumonline != num ? this.element.find('>span:first').text(num): null;
		this.oldnumonline = num;
	},

	setMagic: function(magic) {
		this.state.magic = magic;
	},

	_toggleOpen: function(e) {
		if (this.$panelButton.hasClass('ui-state-disabled')) return;
		if (!this.chatpanel) {
			this._createChatPanel();
		}
		this.chatpanel[this.state.open ? 'hide' : 'show']();
		if (this.state.open) {
			this.element
				.find('>div:first>span')
					.removeClass('ui-icon-triangle-1-s')
					.addClass('ui-icon-triangle-1-n')
					.css('left', '2px')
				.end();
			this._trigger('closed');
		}
		else {
			this.element
				.find('>div:first>span')
					.removeClass('ui-icon-triangle-1-n')
					.addClass('ui-icon-triangle-1-s')
					.css('left', '1px')
				.end();
			this.input.focus();
			this._trigger('opened');
		}
		this.state.open = !this.state.open;
	},

	_createChatPanel: function() {
		var $c = this.chatpanel = $('<div class="ui-widget ui-widget-content cobexer-chat-panel"></div>');
		var $t = $('<ul></ul>');
		$c
			.appendTo(document.body)
			.append($t);
		var c = this.options.channels;
		var idx = 0, panelId = 'chat-channel-' + c[idx].name.replace(/[^A-Za-z0-9]/g, '-');
		for (;idx < c.length;idx++) {
			$('<li></li>')
				.append($('<a></a>')
					.text(c[idx].name)
					.attr('href', '#' + panelId)
					.append('<span></span>')
				)
				.appendTo($t);
			var $tab = $('<div></div>').attr('id',  panelId);
			this._createChannelTabContent($tab);
			$c.append($tab);
			c[idx].msglist = $tab.find('>div:first');
			c[idx].userlist = c[idx].msglist.next();
			if (c[idx].msgbuffer) {
				var i = 0;
				for (;i<c[idx].msgbuffer.length; i++) {
					this._writeMessage(c[idx].msglist, c[idx].msgbuffer[i]);
				}
				delete c[idx].msgbuffer;
			}
			c[idx].usersonline && this.setOnlineUsers(c[idx].name, c[idx].usersonline);
		}
		$t
			.parent()
				.tabs({
					select: $.proxy(this._select, this)
				})
				.tabs('select', this.state.channel)
			.end()
			.removeClass('ui-corner-all')
			.addClass('ui-corner-top');
		$c
			.append(
					$('<div class="cobexer-chat-panel-input-container"></div>')
						.append('<div><input type="text" class="ui-widget ui-widget-content ui-corner-all"/></div>')
						.find('input:first')
							.on('keyup', $.proxy(this._inputhandler, this))
						.end()
						.append(
							$('<button></button>')
								.button({ label: this.options.lngButtonSend })
								.on('click', $.proxy(this._send, this))
						)
					);
		this.input = $c.find('input:first');
	},

	_createChannelTabContent: function($t) {
		$t
			.css('padding', 0)
			.append('<div></div>')
			.append('<div></div>')
			.find('>div:first')
				.css({
					width: 328,
					height: 227,
					overflow: 'auto',
					float: 'left'
				})
				.addClass('ui-widget-content')
			.end()
			.find('>div:eq(1)')
				.css({
					width: 168,
					height: 227,
					overflow: 'auto',
					float: 'left'
				})
				.addClass('ui-widget-content')
			.end();
	}
});

})(jQuery);
