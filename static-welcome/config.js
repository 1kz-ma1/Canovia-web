window.CANOVIA_WELCOME_CONFIG = {
    // Temporary, independently TLS-verified Render origin. app.canovia.com DNS
    // is currently unverified from a public GitHub Actions runner (2026-10-15).
    // Switch back only after DNS/Render custom-domain, auth cookie and device acceptance.
    appUrl: 'https://pacekeeper-d3mm.onrender.com',
    // Explicit publication gates. Flip only after Web Live and device acceptance.
    publicRoadmapReady: false,
    supportAnchorReady: false,
};

// Compatibility alias for an older cached welcome script.
window.PACEKEEPER_WELCOME_CONFIG = window.CANOVIA_WELCOME_CONFIG;
