{{-- IP address of a DNS zone: free text with the owner's addresses (NAT where set) as suggestions. --}}
<x-ap-input name="ip" :label="__('IP Address')" :value="$value" required mono autocomplete="off" spellcheck="false" list="dns-zone-ips"
    :disabled="$disabled ?? false" />
<datalist id="dns-zone-ips">
    @foreach ($ips as $address)
        <option value="{{ $address }}"></option>
    @endforeach
</datalist>
