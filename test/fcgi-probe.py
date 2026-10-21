#!/usr/bin/env python3
# Run one PHP file through a PHP-FPM socket and print its response body.
# Used by test/arcpanel-panel-headers.bats to check the settings of the
# panel's own pool (E39 W5 spec §10.1). Usage: fcgi-probe.py SOCKET SCRIPT
import socket
import struct
import sys


def record(kind, content, request_id=1):
    return struct.pack('!BBHHBx', 1, kind, request_id, len(content), 0) + content


def pair(name, value):
    name, value = name.encode(), value.encode()
    out = b''
    for part in (name, value):
        out += struct.pack('!B', len(part)) if len(part) < 128 else struct.pack('!I', len(part) | 0x80000000)
    return out + name + value


def main():
    sock_path, script = sys.argv[1], sys.argv[2]
    params = b''.join(pair(k, v) for k, v in {
        'SCRIPT_FILENAME': script,
        'SCRIPT_NAME': '/probe.php',
        'REQUEST_METHOD': 'GET',
        'SERVER_PROTOCOL': 'HTTP/1.1',
        'GATEWAY_INTERFACE': 'CGI/1.1',
        'REMOTE_ADDR': '127.0.0.1',
    }.items())
    sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    sock.connect(sock_path)
    sock.sendall(record(1, struct.pack('!HB5x', 1, 0)) + record(4, params) + record(4, b'') + record(5, b''))
    data = b''
    while True:
        chunk = sock.recv(65536)
        if not chunk:
            break
        data += chunk
    sock.close()
    out, pos = b'', 0
    while pos + 8 <= len(data):
        _, kind, _, length, padding = struct.unpack('!BBHHB', data[pos:pos + 7])
        if kind == 6:
            out += data[pos + 8:pos + 8 + length]
        pos += 8 + length + padding
    sys.stdout.write(out.decode(errors='replace').split('\r\n\r\n', 1)[-1])


main()
