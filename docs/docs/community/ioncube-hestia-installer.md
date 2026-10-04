# Install Ioncube on Hestia

::: warning Third-party add-on for HestiaCP
This page describes a community project written for HestiaCP, the project ArcPanel is based on. It is not maintained by the ArcPanel team and has not been tested with ArcPanel. Because ArcPanel keeps the HestiaCP internals (`/usr/local/hestia`, `v-*` commands and the API), it may work, but it may need adaptation.
:::

By [Jaap Marcus](https://github.com/jaapmarcus/)  
[Source code](https://github.com/jaapmarcus/ioncube-hestia-installer)

Simple tool to download and install [Ioncube loaders](https://www.ioncube.com/loaders.php) for each available PHP version that is installed.

```bash
wget https://raw.githubusercontent.com/jaapmarcus/ioncube-hestia-installer/main/install_ioncube.sh
chmod +x install_ioncube.sh
./install_ioncube.sh
```
