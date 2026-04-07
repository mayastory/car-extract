Poket Patch v2 - Keymap + HUD icon size fix

- Key mapping fixed:
  A button     = keyboard 'A'
  B button     = keyboard 'S'
  START button = keyboard 'Z'
  SELECT       = keyboard 'X'
  Movement     = Arrow keys only (WASD disabled to avoid conflicts with A/S)

- START menu now consumes keys before overworld movement.
- SELECT triggers registered-item event (hook point for fishing/item use).
- HUD top buttons (menu icons) now render at original 24x24 size (no forced 16x16, no grayscale filter).

Overwrite into your project root (same folder that has public/).
