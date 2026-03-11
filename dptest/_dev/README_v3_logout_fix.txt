v3 logout redirect fix
Built: 2026-01-30 05:18:31

Fixes:
- After v3 cleanroot, logout.php lives under /public/legacy/
- Old logic redirected to /public/legacy/index -> 404
- Now redirects to project root /index

Apply:
- Unzip into your project root (same folder that contains public/, Module/, _dev/)
- Overwrite:
  - public/legacy/logout.php
  - Module/Account/pages/logout.php (if exists)
