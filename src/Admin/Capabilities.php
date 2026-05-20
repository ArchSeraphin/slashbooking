<?php
declare(strict_types=1);

namespace Trinity\Booking\Admin;

final class Capabilities
{
    public const MANAGE = 'trinity_booking_manage';
    public const VIEW   = 'trinity_booking_view';

    public static function install(): void
    {
        $role = get_role('administrator');
        if ($role === null) {
            return;
        }
        $role->add_cap(self::MANAGE);
        $role->add_cap(self::VIEW);
    }

    public static function uninstall(): void
    {
        foreach (['administrator', 'editor'] as $roleName) {
            $role = get_role($roleName);
            if ($role === null) {
                continue;
            }
            $role->remove_cap(self::MANAGE);
            $role->remove_cap(self::VIEW);
        }
    }
}
