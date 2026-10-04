// Single source of truth for per-panel labels, default ports and form copy,
// shared by Servers/Create.jsx and Servers/Edit.jsx so the two can't drift.
// Keys must match the servers.panel_type enum and config('autopilot.panel_types').
export const PANELS = {
    cpanel: {
        label: 'cPanel',
        port: 2083,
        portHint: 'Port 2083',
        sshUserHint: 'Your cPanel account username',
        sshUserPlaceholder: 'cpanelusername',
        webUserHint: 'OS user that PHP-FPM runs as. On cPanel this is usually the same as your SSH user.',
        tokenLabel: 'Panel API Token',
        tokenHint: 'cPanel → Security → Manage API Tokens',
    },
    aapanel: {
        label: 'aaPanel',
        port: 7800,
        portHint: 'Port 7800 / 7843',
        sshUserHint: 'Usually root or your VPS user',
        sshUserPlaceholder: 'root',
        webUserHint: 'OS user that PHP-FPM runs as. aaPanel default is "www". Used to chown the deploy path so Laravel can write storage/ and bootstrap/cache/.',
        tokenLabel: 'Panel API Token',
        tokenHint: 'aaPanel → Settings → API Interface',
    },
    openpanel: {
        label: 'OpenPanel',
        // The REST API lives on the OpenAdmin port, not the end-user panel (2083).
        port: 2087,
        portHint: 'Port 2087 (OpenAdmin)',
        sshUserHint: 'Usually root or your VPS user',
        sshUserPlaceholder: 'root',
        webUserHint: 'OS user that PHP-FPM runs as. OpenPanel runs each account in its own container — this is normally the account username, not root. Used to chown the deploy path so Laravel can write storage/ and bootstrap/cache/.',
        // OpenPanel mints a JWT from username + password rather than issuing a
        // static token, so this field holds the OpenAdmin password.
        tokenLabel: 'OpenAdmin Password',
        tokenHint: 'Password for the OpenAdmin user above — OpenPanel exchanges it for a JWT.',
    },
};

export const PANEL_KEYS = Object.keys(PANELS);

export const panelFor = (type) => PANELS[type] ?? PANELS.cpanel;

/** Default web_user placeholder for a panel, given the entered SSH user. */
export const webUserPlaceholder = (type, sshUser) =>
    type === 'aapanel' ? 'www' : (sshUser || panelFor(type).sshUserPlaceholder);
