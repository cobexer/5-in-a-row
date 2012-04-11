/**
 * 5-in-a-row <http://cobexer.github.com/5-in-a-row/>
 * Copyright (c) 2012 Obexer Christoph. All rights reserved.
 *
 * Released under the MIT and GPL licenses.
 */
(function() {
	function WrappedWebSocket(host, extensions) {
		var ws = this, eventHandler = ['onopen', 'onclose', 'onerror', 'onmessage'], i;
		this.onopen = null;
		this.onclose = null;
		this.onerror = null;
		this.onmessage = null;
		this._ws = arguments.length < 2 ? new WebSocket(host) : new WebSocket(host, extensions);
		this._boundHandlers = { open: [], close: [], error: [], message: [] };
		this._ws._wws = this;
		i = eventHandler.length;
		while (i--) {
			(function(key) {
				ws._ws[key] = function(event) {
					log(["WrappedWebSocket." + key, event.data, event]);
					if (ws[key]) {
						return ws[key].apply(ws, arguments);
					}
				};
			}(eventHandler[i]));
		}
		return this;
	}
	function pad(val) {
		return val < 10 ? "0" + val : "" + val;
	}
	function log() {
		if (WrappedWebSocket.log) {
			var d = new Date(), formatted = "", ms = pad(d.getMilliseconds());
			formatted += d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate()) + " ";
			formatted += pad(d.getHours()) + ":" + pad(d.getMinutes()) + ":" + pad(d.getSeconds());
			formatted += "." + (ms.length < 3 ? "0" + ms : ms);
			Array.prototype.unshift.call(arguments, formatted);
			WrappedWebSocket.log.apply(this, arguments);
		}
	}
	var i, getSetProps = ['url', 'readyState', 'bufferedAmount', 'extensions', 'protocol', 'binaryType'], memberFunctions = ['close', 'send'];
	i = getSetProps.length;
	while (i--) {
		(function(key) {
			if (WrappedWebSocket.prototype.__defineGetter__) {
				WrappedWebSocket.prototype.__defineGetter__(key, function() {
					return this._ws[key];
				});
			}
			if (WrappedWebSocket.prototype.__defineSetter__) {
				WrappedWebSocket.prototype.__defineSetter__(key, function(val) {
					return this._ws[key] = val;
				});
			}
		}(getSetProps[i]));
	}
	i = memberFunctions.length;
	while (i--) {
		(function(key) {
			WrappedWebSocket.prototype[key] = function() {
				log(["WrappedWebSocket." + key, arguments]);
				return this._ws[key].apply(this._ws, arguments);
			};
		}(memberFunctions[i]));
	}
	WrappedWebSocket.prototype.addEventListener = function(type, fn, capture) {
		log(["WrappedWebSocket.addEventListener", arguments]);
		capture = capture || false;
		var i, handlers = this._boundHandlers[type], handler = function() {
			return fn.apply(this._wws, arguments);
		};
		i = handlers.length;
		while(i--) {
			if (handlers[i][0] === fn && handlers[i][2] === capture) {
				//fn is already bound
				return;
			}
		}
		handlers.push([fn, handler, capture]);
		this._ws.addEventListener(type, handler);
	};
	WrappedWebSocket.prototype.removeEventListener = function(type, fn, capture) {
		log(["WrappedWebSocket.removeEventListener", arguments]);
		var handler, boundHandlers = this._boundHandlers[type];
		capture = capture || false;
		var i = boundHandlers.length;
		while(i--) {
			handler = boundHandlers[i];
			if (handler[0] === fn && handler[2] === capture) {
				boundHandlers.splice(i, 1);
				this._ws.removeEventListener(type, handler[1]);
				return;
			}
		}
	};
	WrappedWebSocket.prototype.dispatchEvent = function(event) {
		log(["WrappedWebSocket.dispatchEvent", arguments]);
		return this._ws.dispatchEvent.apply(this._ws, arguments);
	};
	WrappedWebSocket.prototype.toString = function () {
		return this._ws.toString();
	};
	WrappedWebSocket.log = null; // default is no active logging, override for debugging ;)
	window.WrappedWebSocket = WrappedWebSocket;
}());