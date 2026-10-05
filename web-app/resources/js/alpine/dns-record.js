// apDnsRecord: the record form of the DNS records drawer. The priority field
// belongs to MX and SRV records only (templates/pages/add_dns_rec.php); for
// other types it is hidden and disabled, so it posts nothing.

export default function apDnsRecord({ type = 'A', priorityTypes = ['MX', 'SRV'] } = {}) {
	return {
		type,
		priorityTypes,

		get hasPriority() {
			return this.priorityTypes.includes(this.type);
		},
	};
}
