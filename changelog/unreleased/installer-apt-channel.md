---
type: added
---

- The installers accept `ARCPANEL_APT_CHANNEL=stable` or `ARCPANEL_APT_CHANNEL=beta` to choose the ArcPanel APT channel instead of deriving it from the version, for example to install a development build with `--with-debs` and take the engine packages from the stable channel. Without the variable nothing changes.
