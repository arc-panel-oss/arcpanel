import { defineConfig } from 'vitepress';

// ArcPanel release shown in the navigation. The upstream base version
// is kept separately (VERSION in hestia.conf, install/upstream.conf).
const arcpanelVersion = '1.0.0';
const repo = 'https://github.com/arc-panel-oss/arcpanel';

export default defineConfig({
	lang: 'en-US',
	title: 'ArcPanel',
	description: 'ArcPanel: open-source web server control panel.',

	sitemap: {
		hostname: 'https://docs.arcpanel.top',
	},

	lastUpdated: true,
	cleanUrls: false,

	head: [
		['link', { rel: 'icon', sizes: 'any', href: '/favicon.ico' }],
		['link', { rel: 'icon', type: 'image/svg+xml', sizes: 'any', href: '/icon.svg' }],
		['link', { rel: 'apple-touch-icon', sizes: '180x180', href: '/apple-touch-icon.png' }],
		['link', { rel: 'manifest', href: '/site.webmanifest' }],
		['meta', { name: 'theme-color', content: '#2f55d4' }],
		['meta', { property: 'og:site_name', content: 'ArcPanel' }],
	],

	themeConfig: {
		logo: { src: '/icon.svg', alt: 'ArcPanel' },
		siteTitle: 'ArcPanel',

		nav: nav(),

		socialLinks: [{ icon: 'github', link: repo }],

		sidebar: { '/docs/': sidebarDocs() },

		outline: [2, 3],

		editLink: {
			pattern: `${repo}/edit/master/docs/:path`,
			text: 'Edit this page on GitHub',
		},

		footer: {
			message: 'Based on <a href="https://github.com/hestiacp/hestiacp">HestiaCP</a> · GPL-3.0',
			copyright:
				'Copyright © 2019-present HestiaCP contributors · © 2026-present ArcPanel contributors',
		},

		search: {
			provider: 'local',
		},
	},
});

/** @returns {import("vitepress").DefaultTheme.NavItem[]} */
function nav() {
	return [
		{ text: 'Features', link: '/features' },
		{ text: 'Install', link: '/install' },
		{ text: 'Documentation', link: '/docs/introduction/getting-started', activeMatch: '/docs/' },
		{ text: 'Team', link: '/team' },
		{
			text: `v${arcpanelVersion}`,
			items: [
				{ text: 'Changelog', link: `${repo}/blob/master/CHANGELOG.md` },
				{ text: 'Releases', link: `${repo}/releases` },
				{ text: 'Contributing', link: `${repo}/blob/master/CONTRIBUTING.md` },
				{ text: 'Security policy', link: `${repo}/blob/master/SECURITY.md` },
			],
		},
	];
}
/** @returns {import("vitepress").DefaultTheme.SidebarItem[]} */
function sidebarDocs() {
	return [
		{
			text: 'Introduction',
			collapsed: false,
			items: [
				{ text: 'Getting started', link: '/docs/introduction/getting-started' },
				{ text: 'Best practices', link: '/docs/introduction/best-practices' },
			],
		},
		{
			text: 'User guide',
			collapsed: false,
			items: [
				{ text: 'Account', link: '/docs/user-guide/account' },
				{ text: 'Backups', link: '/docs/user-guide/backups' },
				{ text: 'Cron jobs', link: '/docs/user-guide/cron-jobs' },
				{ text: 'Databases', link: '/docs/user-guide/databases' },
				{ text: 'DNS', link: '/docs/user-guide/dns' },
				{ text: 'File manager', link: '/docs/user-guide/file-manager' },
				{ text: 'Mail domains', link: '/docs/user-guide/mail-domains' },
				{ text: 'Notifications', link: '/docs/user-guide/notifications' },
				{ text: 'Packages', link: '/docs/user-guide/packages' },
				{ text: 'Statistics', link: '/docs/user-guide/statistics' },
				{ text: 'Users', link: '/docs/user-guide/users' },
				{ text: 'Web domains', link: '/docs/user-guide/web-domains' },
			],
		},
		{
			text: 'Server administration',
			collapsed: false,
			items: [
				{ text: 'Backup & restore', link: '/docs/server-administration/backup-restore' },
				{ text: 'Configuration', link: '/docs/server-administration/configuration' },
				{ text: 'Customisation', link: '/docs/server-administration/customisation' },
				{ text: 'Databases & phpMyAdmin', link: '/docs/server-administration/databases' },
				{ text: 'DNS clusters & DNSSEC', link: '/docs/server-administration/dns' },
				{ text: 'Email', link: '/docs/server-administration/email' },
				{ text: 'File manager', link: '/docs/server-administration/file-manager' },
				{ text: 'Firewall', link: '/docs/server-administration/firewall' },
				{ text: 'OS upgrades', link: '/docs/server-administration/os-upgrades' },
				{ text: 'Rest API', link: '/docs/server-administration/rest-api' },
				{ text: 'SSL certificates', link: '/docs/server-administration/ssl-certificates' },
				{ text: 'Web templates & caching', link: '/docs/server-administration/web-templates' },
				{ text: 'Troubleshooting', link: '/docs/server-administration/troubleshooting' },
			],
		},
		{
			text: 'Contributing',
			collapsed: false,
			items: [
				{ text: 'Building Packages', link: '/docs/contributing/building' },
				{ text: 'Development', link: '/docs/contributing/development' },
				{ text: 'Documentation', link: '/docs/contributing/documentation' },
				{ text: 'Quick install app', link: '/docs/contributing/quick-install-app' },
				{ text: 'Releases & signing', link: '/docs/contributing/releases' },
				{ text: 'Testing', link: '/docs/contributing/testing' },
				{ text: 'Translations', link: '/docs/contributing/translations' },
			],
		},
		{
			text: 'Community',
			collapsed: false,
			items: [
				{ text: 'Hestia Nginx Cache', link: '/docs/community/hestia-nginx-cache' },
				{
					text: 'Ioncube installer for Hestia',
					link: '/docs/community/ioncube-hestia-installer',
				},
				{ text: 'Install script generator', link: '/docs/community/install-script-generator' },
			],
		},
		{
			text: 'Reference',
			collapsed: false,
			items: [
				{ text: 'API', link: '/docs/reference/api' },
				{ text: 'CLI', link: '/docs/reference/cli' },
			],
		},
	];
}
