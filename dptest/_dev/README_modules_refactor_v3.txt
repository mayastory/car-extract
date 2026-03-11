JTMES Modules Refactor v3 (Clean Root)
Built: 2026-01-30 04:59:09

Goal:
- Reduce root clutter: root-level *.php files are removed.
- URLs remain the same via root .htaccess rewrite to /public/legacy/*.php
- Real code lives in Module/<Module>/pages/*.php

Apply:
- Use this as a full project folder (extract to new folder under htdocs).
- Access: http://localhost/<folder>/
- Existing direct links: /shipinglist_export_lotlist.php etc continue.

Notes:
- Original root pages backed up: _dev/_root_backup/
