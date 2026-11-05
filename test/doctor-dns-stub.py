#!/usr/bin/env python3
"""A DNS stub for test/arcpanel-doctor.bats (E17).

Answers UDP queries on 127.0.0.1:PORT from the zone file DIR/zone, read on
every query. Each line is "NAME TYPE VALUE" (A, AAAA, MX "PRIORITY HOST",
TXT, PTR, CNAME); NAME without the trailing dot. A name listed in DIR/drop
gets no answer at all (a resolver that times out). Names without records
answer NXDOMAIN. Never forwards a query.

    python3 test/doctor-dns-stub.py DIR PORT
"""

import os
import socket
import struct
import sys

TYPES = {'A': 1, 'NS': 2, 'CNAME': 5, 'PTR': 12, 'MX': 15, 'TXT': 16, 'AAAA': 28}


def read_lines(path):
    try:
        with open(path) as f:
            return [line.strip() for line in f if line.strip() and not line.startswith('#')]
    except OSError:
        return []


def encode_name(name):
    out = b''
    for label in name.strip('.').split('.'):
        if label:
            out += bytes([len(label)]) + label.encode()
    return out + b'\0'


def decode_name(data, offset):
    labels = []
    while True:
        length = data[offset]
        offset += 1
        if length == 0:
            break
        labels.append(data[offset:offset + length].decode(errors='replace'))
        offset += length
    return '.'.join(labels), offset


def rdata(rtype, value):
    if rtype == 'A':
        return socket.inet_pton(socket.AF_INET, value)
    if rtype == 'AAAA':
        return socket.inet_pton(socket.AF_INET6, value)
    if rtype == 'MX':
        priority, host = value.split(None, 1)
        return struct.pack('>H', int(priority)) + encode_name(host)
    if rtype == 'TXT':
        raw = value.encode()
        parts = [raw[i:i + 255] for i in range(0, len(raw), 255)] or [b'']
        return b''.join(bytes([len(p)]) + p for p in parts)
    return encode_name(value)


def answer(directory, query):
    ident, flags = struct.unpack('>HH', query[:4])
    name, offset = decode_name(query, 12)
    qtype, qclass = struct.unpack('>HH', query[offset:offset + 4])
    question = query[12:offset + 4]
    lname = name.lower()
    if lname in [n.lower() for n in read_lines(os.path.join(directory, 'drop'))]:
        return None
    records = []
    known = False
    for line in read_lines(os.path.join(directory, 'zone')):
        parts = line.split(None, 2)
        if len(parts) < 3:
            continue
        rname, rtype, value = parts[0].lower(), parts[1].upper(), parts[2]
        if rname != lname:
            continue
        known = True
        if TYPES.get(rtype) == qtype or (rtype == 'CNAME' and qtype != TYPES['CNAME']):
            records.append((rtype, value))
    rcode = 0 if known else 3
    body = b''
    for rtype, value in records:
        data = rdata(rtype, value)
        body += b'\xc0\x0c' + struct.pack('>HHIH', TYPES[rtype], 1, 60, len(data)) + data
    header = struct.pack('>HHHHHH', ident, 0x8180 | (flags & 0x0100) | rcode, 1, len(records), 0, 0)
    return header + question + body


def main():
    directory, port = sys.argv[1], int(sys.argv[2])
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.bind(('127.0.0.1', port))
    while True:
        query, peer = sock.recvfrom(4096)
        try:
            reply = answer(directory, query)
        except Exception:  # noqa: BLE001 - a broken query gets no answer
            reply = None
        if reply:
            sock.sendto(reply, peer)


if __name__ == '__main__':
    main()
