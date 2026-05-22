<?php
declare(strict_types=1);

namespace Slash\Booking\Admin;

final class Capabilities
{
    public const MANAGE = 'slashbooking_manage';
    public const VIEW   = 'slashbooking_view';

    /**
     * Roles that get full plugin access. Editor is included because in the
     * typical SMB usage scenario, the WP "Editor" role is held by the office
     * person who actually handles bookings — not the dev / IT admin.
     */
    private const GRANTED_ROLES = ['administrator', 'editor'];

    /**
     * Bumped whenever the cap layout changes. {@see syncOnUpgrade()} compares
     * this against the stored revision to decide whether to re-run install()
     * on sites that activated under an older revision.
     */
    private const REVISION = 2;
    private const REVISION_OPTION = 'slashbooking_caps_revision';

    public static function install(): void
    {
        foreach (self::GRANTED_ROLES as $roleName) {
            $role = get_role($roleName);
            if ($role === null) {
                continue;
            }
            // add_cap() is idempotent — calling it on a role that already has
            // the cap is a no-op for the in-memory state. WP_Roles still
            // writes the option only when the cap value actually changes.
            $role->add_cap(self::MANAGE);
            $role->add_cap(self::VIEW);
        }
    }

    /**
     * Idempotent migration. Grants the current cap layout when the stored
     * revision is behind {@see self::REVISION}. Designed to be called on every
     * Plugin::register() — the option read is cheap and the actual install()
     * call only fires once per revision bump.
     */
    public static function syncOnUpgrade(): void
    {
        $stored = (int) get_option(self::REVISION_OPTION, 0);
        if ($stored >= self::REVISION) {
            return;
        }
        self::install();
        update_option(self::REVISION_OPTION, self::REVISION, false);
    }

    public static function uninstall(): void
    {
        foreach (self::GRANTED_ROLES as $roleName) {
            $role = get_role($roleName);
            if ($role === null) {
                continue;
            }
            $role->remove_cap(self::MANAGE);
            $role->remove_cap(self::VIEW);
        }
        delete_option(self::REVISION_OPTION);
    }
}
