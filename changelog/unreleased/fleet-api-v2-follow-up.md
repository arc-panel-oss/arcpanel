---
type: added
order: 30
---

- The REST API v2 document names the permission set of each operation (`x-arcpanel-permission-set`), the values of `Task.status`, the 409 answers of `createBackup`, `restoreBackup` and `createNodeApp`, how a Let's Encrypt request reports a failure and renews a certificate, and how `setNodeAppEnv` keeps values.
- The one-off task of app processes is a task of `GET /tasks/{id}` and `listUserTasks` (`app-process:USER:ID`), and `startAppProcessTask` sends `Link` to it.
- `createNodeApp` answers a domain that already has a Node.js app with `409 E_EXISTS` and `existing`, the path of that app.
