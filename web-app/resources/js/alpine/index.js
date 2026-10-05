// Registers every Alpine module of the panel app (docs/design/05 §5.1).
import apBulkBar from './bulk-bar.js';
import apCardPicker from './card-picker.js';
import apConfirm from './confirm.js';
import apCronBuilder from './cron-builder.js';
import apDnsRecord from './dns-record.js';
import apLog from './log.js';
import apMailAccount from './mail-account.js';
import apModal from './modal.js';
import apNameServers from './name-servers.js';
import apNotifications from './notifications.js';
import apPalette from './palette.js';
import apPassword from './password.js';
import { autoTrim, focusFirst } from './plugins.js';
import apRrdGraph from './rrd-graph.js';
import apSidebar from './sidebar.js';
import apTable from './table.js';
import apTabs from './tabs.js';
import apToast from './toast.js';
import apUnlimited from './unlimited.js';
import apUserMenu from './user-menu.js';
import apWizard from './wizard.js';

export const modules = {
	apBulkBar,
	apCardPicker,
	apConfirm,
	apCronBuilder,
	apDnsRecord,
	apLog,
	apMailAccount,
	apModal,
	apNameServers,
	apNotifications,
	apPalette,
	apPassword,
	apRrdGraph,
	apSidebar,
	apTable,
	apTabs,
	apToast,
	apUnlimited,
	apUserMenu,
	apWizard,
};

/** @param {import('alpinejs').Alpine} Alpine */
export function registerAll(Alpine) {
	for (const [name, factory] of Object.entries(modules)) {
		Alpine.data(name, factory);
	}
	Alpine.plugin(() => autoTrim());
	Alpine.plugin(() => focusFirst());
}
