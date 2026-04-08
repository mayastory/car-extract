This package keeps the original alanagoyal-style layout:
- All web assets live under ./public (Next.js public/ behavior)
- URLs still look like /finder.png, /js/osx-shell.js, /desktop/..., etc
- JTOSX/.htaccess routes requests to router.php, and router.php serves static files from ./public.

Do NOT copy assets out of ./public.
