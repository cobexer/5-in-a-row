/**
 * 5-in-a-row <http://cobexer.github.com/5-in-a-row/>
 * Copyright (c) 2012 Obexer Christoph. All rights reserved.
 *
 * Released under the MIT and GPL licenses.
 */
$(function() {
	init();
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
				var sock = this.socket = new WrappedWebSocket("ws://" + location.hostname + ":12345/websocket/Chat");
				WrappedWebSocket.log = function() { console.log.apply(console, arguments); };
				sock.onmessage = function(msg) {
					var msgObj = JSON.parse(msg.data);
					$('#ingame-chat').chat('addMessage', 'Lobby', msgObj);
				};
			}
		});
});