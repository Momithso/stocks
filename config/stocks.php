<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Setup Token
    |--------------------------------------------------------------------------
    |
    | Secret that must be entered in the setup wizard (/setup). It prevents a
    | stranger from setting up a freshly started instance and becoming admin.
    | The wizard is disabled if no token is set.
    |
    */

    'setup_token' => env('SETUP_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Core Permissions
    |--------------------------------------------------------------------------
    |
    | Permissions of the core with their German description. Modules add their
    | own permissions in their module.json. Names follow "area.action".
    |
    */

    'permissions' => [
        'users.view' => 'Kontenliste sehen',
        'users.invite' => 'Konten einladen und Einladungen zurückziehen',
        'users.manage' => 'Konten bearbeiten, deaktivieren und die PIN zurücksetzen',
        'users.delete' => 'Konten löschen',
        'roles.manage' => 'Rollen anlegen, ändern, löschen und zuweisen',
        'modules.manage' => 'Module ein- und ausschalten',
        'settings.manage' => 'Einstellungen der Instanz ändern',
        'kiosk.devices.manage' => 'Kiosk-Geräte koppeln und sperren',
        'kiosk.use' => 'Sich am Kiosk mit PIN anmelden',
        'audit.view' => 'Änderungsprotokoll lesen',
    ],

    /*
    |--------------------------------------------------------------------------
    | Start Roles
    |--------------------------------------------------------------------------
    |
    | Roles created on the first setup. The admin role is a system role with all
    | permissions (also those of modules added later). The names of the other
    | roles can be changed in the setup and later in the role management.
    |
    */

    'admin_role' => 'Admin',

    'start_roles' => [
        'Vorstand' => [
            'description' => 'Leitet die Organisation.',
            'permissions' => ['users.view', 'users.invite', 'users.manage', 'roles.manage', 'settings.manage', 'kiosk.use', 'audit.view'],
        ],
        'FSR-Mitglied' => [
            'description' => 'Gewähltes Mitglied.',
            'permissions' => ['users.view', 'kiosk.use'],
        ],
        'Freies Mitglied' => [
            'description' => 'Hilft mit, ohne gewählt zu sein.',
            'permissions' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Invitations
    |--------------------------------------------------------------------------
    */

    'invitation_lifetime_days' => 7,

    /*
    |--------------------------------------------------------------------------
    | Leaked Passwords
    |--------------------------------------------------------------------------
    |
    | Reject passwords that appear in known data leaks. The check sends the
    | first five characters of the SHA-1 hash of the password to the service
    | "Have I Been Pwned", so it is off unless switched on.
    |
    */

    'check_leaked_passwords' => env('CHECK_LEAKED_PASSWORDS', false),

];
