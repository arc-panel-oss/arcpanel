// Registers every Alpine module of the panel app (docs/design/05 §5.1).
import apAccessKeySecret from './access-key-secret.js';
import apBulkBar from './bulk-bar.js';
import apCardPicker from './card-picker.js';
import apChoiceList from './choice-list.js';
import apConfirm from './confirm.js';
import apCronBuilder from './cron-builder.js';
import apCsr from './csr.js';
import apDnsRecord from './dns-record.js';
import apFormState from './form-state.js';
import apLimit from './limit.js';
import apLog from './log.js';
import apMailAccount from './mail-account.js';
import apModal from './modal.js';
import apNameServers from './name-servers.js';
import apNotifications from './notifications.js';
import apPalette from './palette.js';
import apPassword from './password.js';
import { apNameList, apPhpExtension, apPhpExtensionFilter, apPhpProfile } from './php.js';
import { autoTrim, focusFirst } from './plugins.js';
import apRrdGraph from './rrd-graph.js';
import apSecretStore from './secret-store.js';
import apSectionNav from './section-nav.js';
import apSidebar from './sidebar.js';
import apTable from './table.js';
import apTabs from './tabs.js';
import apTask from './task.js';
import apTerminal from './terminal.js';
import apToast from './toast.js';
import apUnlimited from './unlimited.js';
import apUpdateRelease from './update-release.js';
import apUserMenu from './user-menu.js';
import apUserSwitch from './user-switch.js';
import apWizard from './wizard.js';

export const modules = {
	apAccessKeySecret,
	apBulkBar,
	apCardPicker,
	apChoiceList,
	apConfirm,
	apCronBuilder,
	apCsr,
	apDnsRecord,
	apFormState,
	apLimit,
	apLog,
	apMailAccount,
	apModal,
	apNameList,
	apNameServers,
	apNotifications,
	apPalette,
	apPassword,
	apPhpExtension,
	apPhpExtensionFilter,
	apPhpProfile,
	apRrdGraph,
	apSecretStore,
	apSectionNav,
	apSidebar,
	apTable,
	apTabs,
	apTask,
	apTerminal,
	apToast,
	apUnlimited,
	apUpdateRelease,
	apUserMenu,
	apUserSwitch,
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
