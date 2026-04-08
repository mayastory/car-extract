If you have a parent (site-wide) .htaccess with a catch-all rewrite (e.g. RewriteRule . index.php [L]),
it may intercept /<FOLDER>/ requests BEFORE Apache reaches <FOLDER>/.htaccess.

SYMPTOM:
- Console: Uncaught SyntaxError: Unexpected token '<' (osx-shell.js:1)
- Opening /<FOLDER>/js/osx-shell.js shows HTML instead of JavaScript.

FIX:
Add ONE exception line in the parent .htaccess *BEFORE* the catch-all rule:

  RewriteRule ^<FOLDER>(/|$) - [L]

Replace <FOLDER> with the folder name you use under htdocs (e.g. JTOSX, jtosx, etc).

Then restart Apache and hard-refresh (Ctrl+F5).
