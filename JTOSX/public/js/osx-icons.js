// Minimal inline SVG icon set for JTOSX (vanilla JS).
// Purpose: replace missing glyphs/emoji with deterministic SVG.
// Icons are rendered with currentColor where possible.

(function(){
  'use strict';

  function svg(attrs, inner){
    return `<svg ${attrs}>${inner}</svg>`;
  }

  // Common stroke icon wrapper (Lucide-like)
  function strokeIcon(inner, size){
    const s = size || 16;
    return svg(
      `xmlns="http://www.w3.org/2000/svg" width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"`,
      inner
    );
  }

  // Common filled icon wrapper
  function fillIcon(inner, size, viewBox){
    const s = size || 16;
    const vb = viewBox || '0 0 24 24';
    return svg(
      `xmlns="http://www.w3.org/2000/svg" width="${s}" height="${s}" viewBox="${vb}" fill="currentColor" aria-hidden="true" focusable="false"`,
      inner
    );
  }

  // NOTE: These are simplified but stable SVGs.
  const ICONS = {
    // Apple logo (simple silhouette)
    apple: (size=14) => fillIcon(
      '<path d="M16.2 13.4c0 2.8 2.5 3.7 2.5 3.7s-1.9 5.4-4.5 5.4c-1.2 0-2.1-.8-3.4-.8s-2.3.8-3.5.8C5.9 22.5 3 17.7 3 13.6 3 9.7 5.4 7.8 7.7 7.8c1.2 0 2.3.8 3.1.8.8 0 2.1-.9 3.6-.9.6 0 2.3.1 3.4 1.8-.1.1-2 1.2-2 3.9Zm-2.4-7.2c.8-1 1.4-2.4 1.2-3.8-1.2.1-2.6.8-3.4 1.8-.8.9-1.5 2.3-1.3 3.7 1.3.1 2.7-.7 3.5-1.7Z"/>'
    , size, '0 0 24 24'),

    // FontAwesome-like status icons (filled)
    wifi: (size=14) => fillIcon(
      '<path d="M12 18.5a1.5 1.5 0 0 1-1.06-2.56 1.5 1.5 0 0 1 2.12 2.12A1.5 1.5 0 0 1 12 18.5Zm4.95-3.6a.9.9 0 0 1-.64-.27 6.1 6.1 0 0 0-8.62 0 .9.9 0 1 1-1.28-1.27 7.9 7.9 0 0 1 11.18 0 .9.9 0 0 1-.64 1.54Zm2.95-2.95a.9.9 0 0 1-.64-.27 10.3 10.3 0 0 0-14.52 0 .9.9 0 1 1-1.28-1.28 12.1 12.1 0 0 1 17.08 0 .9.9 0 0 1-.64 1.55Z"/>'
    , size, '0 0 24 24'),

    batteryFull: (size=14) => fillIcon(
      '<path d="M20 8h1a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1h-1v1a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v1Zm-2 0V7H4v10h14v-1h1V9h-1Z"/>'
    , size, '0 0 24 24'),

    sliders: (size=14) => fillIcon(
      '<path d="M6 6h12v2H6V6Zm-2 0h2v2H4V6Zm14 0h2v2h-2V6ZM6 11h12v2H6v-2Zm-2 0h2v2H4v-2Zm14 0h2v2h-2v-2ZM6 16h12v2H6v-2Zm-2 0h2v2H4v-2Zm14 0h2v2h-2v-2Z"/>'
    , size, '0 0 24 24'),

    // Lucide-like (stroke) icons
    monitor: (size=16) => strokeIcon('<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/>', size),
    settings: (size=16) => strokeIcon('<path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z"/><path d="M19.4 15a7.8 7.8 0 0 0 .1-1l2-1.2-2-3.5-2.3.4a7.4 7.4 0 0 0-.8-.6L15 6l-4-1-1 2.1a7.4 7.4 0 0 0-1 .1L7.8 5.3l-3 2.8 1.1 2a7.4 7.4 0 0 0-.4 1L3 12l1.5 2.7 2.3-.4c.2.2.5.4.8.6L9 18l4 1 1-2.1c.3 0 .7-.1 1-.1l1.2 1.9 3-2.8-1.1-2Z"/>', size),
    moon: (size=16) => strokeIcon('<path d="M21 12.8A8.5 8.5 0 1 1 11.2 3a6.5 6.5 0 0 0 9.8 9.8Z"/>', size),
    rotateCcw: (size=16) => strokeIcon('<path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 1-15 2"/><path d="M21 7a9 9 0 0 0-15-2"/>', size),
    power: (size=16) => strokeIcon('<path d="M12 2v10"/><path d="M6.4 4.6a9 9 0 1 0 11.2 0"/>', size),
    lock: (size=16) => strokeIcon('<rect x="5" y="11" width="14" height="11" rx="2"/><path d="M7 11V8a5 5 0 0 1 10 0v3"/>', size),
    logOut: (size=16) => strokeIcon('<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>', size),
    info: (size=16) => strokeIcon('<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>', size),
    x: (size=16) => strokeIcon('<path d="M18 6 6 18"/><path d="M6 6l12 12"/>', size),
    chevronRight: (size=16) => strokeIcon('<path d="M9 18l6-6-6-6"/>', size),
    bluetooth: (size=16) => strokeIcon('<path d="M6 7l12 10-6 5V2l6 5L6 17"/>', size),
    sun: (size=16) => strokeIcon('<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="M4.2 4.2l1.4 1.4"/><path d="M18.4 18.4l1.4 1.4"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="M4.2 19.8l1.4-1.4"/><path d="M18.4 5.6l1.4-1.4"/>', size),
    volume2: (size=16) => strokeIcon('<path d="M11 5 6 9H2v6h4l5 4V5Z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M18 6a8.5 8.5 0 0 1 0 12"/>', size),
    battery: (size=16) => strokeIcon('<rect x="2" y="7" width="18" height="10" rx="2"/><path d="M22 11v2"/><path d="M6 11h10"/>', size),
    smartphone: (size=16) => strokeIcon('<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M12 18h.01"/>', size),
    bedDouble: (size=16) => strokeIcon('<path d="M3 17v4"/><path d="M21 17v4"/><path d="M3 12h18"/><path d="M5 12V7a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v5"/><path d="M7 10h4"/><path d="M13 10h4"/>', size),
    slidersHorizontal: (size=16) => strokeIcon('<path d="M4 21v-7"/><path d="M4 10V3"/><path d="M12 21v-9"/><path d="M12 8V3"/><path d="M20 21v-5"/><path d="M20 12V3"/><path d="M2 14h4"/><path d="M10 8h4"/><path d="M18 16h4"/>', size),
    play: (size=16) => strokeIcon('<path d="M8 5v14l11-7Z"/>', size),
    pause: (size=16) => strokeIcon('<path d="M6 5h4v14H6z"/><path d="M14 5h4v14h-4z"/>', size),
    skipBack: (size=16) => strokeIcon('<path d="M19 20V4"/><path d="M5 19V5"/><path d="M19 12 5 19V5Z"/>', size),
    skipForward: (size=16) => strokeIcon('<path d="M5 4v16"/><path d="M19 5v14"/><path d="M5 12l14 7V5Z"/>', size),
  };

  window.OSX_ICONS = ICONS;
})();
