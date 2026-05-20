<?php
declare(strict_types=1);

namespace Trinity\Booking\Notifications;

use Trinity\Booking\Notifications\Events\EventKey;

final class DefaultTemplates
{
    /**
     * @return array<string, array{subject:string, html_body:string}>
     */
    public static function all(): array
    {
        return [
            EventKey::PENDING_CLIENT->value => [
                'subject'   => __('Votre demande de RDV — en attente de validation', 'trinity-booking'),
                'html_body' => self::pendingClient(),
            ],
            EventKey::PENDING_ADMIN->value => [
                'subject'   => __('Nouvelle demande de RDV : {{service_name}} — {{customer_name}}', 'trinity-booking'),
                'html_body' => self::pendingAdmin(),
            ],
            EventKey::CONFIRMED_CLIENT->value => [
                'subject'   => __('RDV confirmé — {{appointment_date}} à {{appointment_time}}', 'trinity-booking'),
                'html_body' => self::confirmedClient(),
            ],
            EventKey::REJECTED_CLIENT->value => [
                'subject'   => __("Votre demande de RDV n'a pas pu être confirmée", 'trinity-booking'),
                'html_body' => self::rejectedClient(),
            ],
            EventKey::CANCELLED_CLIENT->value => [
                'subject'   => __('Annulation de votre RDV confirmée', 'trinity-booking'),
                'html_body' => self::cancelledClient(),
            ],
            EventKey::REMINDER_CLIENT->value => [
                'subject'   => __('Rappel : RDV demain à {{appointment_time}}', 'trinity-booking'),
                'html_body' => self::reminderClient(),
            ],
        ];
    }

    private static function pendingClient(): string
    {
        return <<<HTML
<p>Bonjour {{customer_name}},</p>
<p>Nous avons bien reçu votre demande de rendez-vous pour <strong>{{service_name}}</strong> le <strong>{{appointment_date}}</strong> à <strong>{{appointment_time}}</strong>.</p>
<p>Notre équipe vous contactera très vite pour la confirmer.</p>
<p>Vous pouvez annuler à tout moment : <a href="{{cancel_url}}">annuler ce RDV</a>.</p>
<p>— {{site_name}}</p>
HTML;
    }

    private static function pendingAdmin(): string
    {
        return <<<HTML
<p>Nouvelle demande de RDV à valider :</p>
<ul>
  <li><strong>Service :</strong> {{service_name}} ({{service_duration}})</li>
  <li><strong>Quand :</strong> {{appointment_date}} de {{appointment_time}} à {{appointment_end}}</li>
  <li><strong>Client :</strong> {{customer_name}} — {{customer_email}} — {{customer_phone}}</li>
  <li><strong>Adresse :</strong> {{customer_address}}</li>
  <li><strong>Notes :</strong> {{notes}}</li>
</ul>
<p>
  <a href="{{confirm_url}}" style="background:#16a34a;color:#fff;padding:10px 16px;text-decoration:none;border-radius:4px;">Confirmer</a>
  &nbsp;
  <a href="{{reject_url}}" style="background:#dc2626;color:#fff;padding:10px 16px;text-decoration:none;border-radius:4px;">Refuser</a>
</p>
<p style="font-size:12px;color:#666">Les liens expirent dans 72 h.</p>
HTML;
    }

    private static function confirmedClient(): string
    {
        return <<<HTML
<p>Bonjour {{customer_name}},</p>
<p>Votre RDV <strong>{{service_name}}</strong> est confirmé pour le <strong>{{appointment_date}}</strong> de {{appointment_time}} à {{appointment_end}} ({{timezone}}).</p>
<p>Adresse renseignée : {{customer_address}}</p>
<p>Vous pouvez l'ajouter à votre agenda via la pièce jointe .ics, ou <a href="{{cancel_url}}">annuler ce RDV</a>.</p>
<p>À très vite !<br>{{site_name}}</p>
HTML;
    }

    private static function rejectedClient(): string
    {
        return <<<HTML
<p>Bonjour {{customer_name}},</p>
<p>Désolé, votre demande de RDV pour le {{appointment_date}} à {{appointment_time}} n'a pas pu être confirmée.</p>
<p>N'hésitez pas à <a href="{{site_url}}">choisir un autre créneau</a>.</p>
<p>— {{site_name}}</p>
HTML;
    }

    private static function cancelledClient(): string
    {
        return <<<HTML
<p>Bonjour {{customer_name}},</p>
<p>Nous avons bien pris en compte l'annulation de votre RDV {{service_name}} du {{appointment_date}} à {{appointment_time}}.</p>
<p>À très vite ! <a href="{{site_url}}">Reprendre un RDV</a>.</p>
HTML;
    }

    private static function reminderClient(): string
    {
        return <<<HTML
<p>Bonjour {{customer_name}},</p>
<p>Petit rappel : votre RDV <strong>{{service_name}}</strong> est prévu <strong>demain</strong> à {{appointment_time}} ({{timezone}}).</p>
<p>Adresse : {{customer_address}}</p>
<p>Besoin d'annuler ? <a href="{{cancel_url}}">Cliquer ici</a>.</p>
<p>— {{site_name}}</p>
HTML;
    }
}
