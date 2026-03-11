JTMES v3 cleanroot FIX
Built: 2026-01-30 05:14:25

What this fixes:
- Your original .htaccess had RewriteRule/RewriteBase pointing to removed root files (index.php/app.php/etc).
- This file rewrites / -> public/index.php and legacy routes -> public/legacy/*.php

How to apply:
- Unzip into your project root (same folder that contains Module/, public/, _dev/)
- Overwrite .htaccess and index.php when asked.
