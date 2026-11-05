#!/bin/bash
# E17 check server.swap (spec §4.1): no swap with less than 4 GB of memory.
# shellcheck disable=SC2034
DOCTOR_ID=server.swap
DOCTOR_SCOPE=server
DOCTOR_TITLE='The server has swap or enough memory'
DOCTOR_DOCS='/docs/guides/doctor#server-swap'
DOCTOR_FIX_ALLOWED=''

doctor_check() {
	local swaps meminfo total_kb swap_kb mem_mb
	if ! meminfo=$(doctor_src meminfo cat /proc/meminfo 2> /dev/null); then
		dr_skip 'The memory size could not be read.'
		return
	fi
	total_kb=$(awk '$1 == "MemTotal:" { print $2 }' <<< "$meminfo")
	swap_kb=$(awk '$1 == "SwapTotal:" { print $2 }' <<< "$meminfo")
	if swaps=$(doctor_src swaps cat /proc/swaps 2> /dev/null) && [ -z "$swap_kb" ]; then
		swap_kb=$(awk 'NR > 1 { s += $3 } END { print s + 0 }' <<< "$swaps")
	fi
	[[ "$total_kb" =~ ^[0-9]+$ ]] || {
		dr_skip 'The memory size could not be read.'
		return
	}
	mem_mb=$((total_kb / 1024))
	if [ "${swap_kb:-0}" -eq 0 ] 2> /dev/null && [ "$total_kb" -lt 4000000 ]; then
		dr_warn "The server has $mem_mb MB of memory and no swap: a memory peak stops services. Add a swap file." "$mem_mb MB, no swap"
		return
	fi
	dr_ok "The server has $mem_mb MB of memory and $((${swap_kb:-0} / 1024)) MB of swap." "$mem_mb MB, swap $((${swap_kb:-0} / 1024)) MB"
}
