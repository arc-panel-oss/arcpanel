---
type: added
---

- Upload from URL in the File Manager: the server downloads a file into the open folder, as the hosting user, from a URL (Simple), a pasted `curl` command (cURL) or a URL with HTTP headers and Basic or Bearer authentication (Custom). The list of downloads shows progress and state, survives a reload and keeps finished downloads for 24 hours. Private and local addresses are refused (administrators may allow them per download); 3 downloads run at once per account and 20 at most wait or run; files are limited by `FILE_FETCH_MAX_SIZE` (2G) and 1 hour. `FILE_FETCH='no'` turns the feature off. New commands `v-add-fs-fetch`, `v-list-fs-fetches` and `v-delete-fs-fetch`.
