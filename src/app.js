/**
 * 5-in-a-row <http://cobexer.github.com/5-in-a-row/>
 * Copyright (c) 2012 Obexer Christoph. All rights reserved.
 *
 * Released under the MIT and GPL licenses.
 */
var gamePlayer = { name: 'Guest' + Math.floor(Math.random() * 10000), method: 'x', color: 'lime' };
$(function() {
	if (typeof window.console !== 'undefined' && typeof window.console.log === 'function' && typeof window.console.log.apply === 'function') {
		WrappedWebSocket.log = function() {
			if (!!console.__proto__ && "log" in console.__proto__) { // detect chromes fu*** console
				console.log(arguments);
			}
			else {
				console.log.apply(console, arguments);
			}
		};
	}
	init();
	initNet();
	$('<div id="ingame-chat"></div>')
		.chat({
			channels: ['Lobby'],
			send: function(event, obj) {
				this.socket && this.socket.send(JSON.stringify(obj));
			},
			disconnect: function(event) {
				this.socket && this.socket.close();
			},
			connect: function(event, channels) {
				this.socket && this.socket.close();
				var sock = this.socket = new WrappedWebSocket("ws://" + location.hostname + ":12345/websocket/Chat");
				if (window.console && window.console.apply) {
					WrappedWebSocket.log = function() { console.log.apply(console, arguments); };
				}
				sock.onmessage = function(msg) {
					var msgObj = JSON.parse(msg.data);
					$('#ingame-chat').chat('addMessage', 'Lobby', msgObj);
				};
			}
		});
	$('#player-name').on('change', function() {
		var val = this.value;
		val = val.replace(/^\s*(.*?)\s*$/g, '$1');
		if (val.length) {
			gamePlayer.name = val;
			send('updatePlayer', gamePlayer);
		}
	})
	.val(gamePlayer.name);

	$('#player-color').on('change', function() {
		gamePlayer.color = this.value;
		send('updatePlayer', gamePlayer);
	})
	.val(gamePlayer.color);

	$('#player-method').on('change', function() {
		gamePlayer.method = this.value;
		send('updatePlayer', gamePlayer);
	})
	.val(gamePlayer.method);
});