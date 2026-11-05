<?php

namespace App\Services\Crm;

use App\Models\User;

class PortalNotificationSettings
{
    public const MAX_RECIPIENTS = 10;

    public function values(User $user): array
    {
        $stored = is_array($user->portal_notification_preferences) ? $user->portal_notification_preferences : [];

        return [
            'email_enabled' => ($stored['email_enabled'] ?? false) === true,
            'recipients' => $this->normalizeRecipients(array_key_exists('recipients', $stored) ? $stored['recipients'] : [$user->email]),
            'comment_added' => ($stored['comment_added'] ?? false) === true,
            'document_added' => ($stored['document_added'] ?? false) === true,
            'statuses' => array_values(array_intersect(array_keys(PortalCategoryAccess::catalogue()),
                is_array($stored['statuses'] ?? null) ? array_filter($stored['statuses'], is_string(...)) : [])),
        ];
    }

    public function normalizeRecipients(mixed $recipients): array
    {
        if (! is_array($recipients) || count($recipients) > self::MAX_RECIPIENTS) {
            return [];
        }

        $emails = [];
        foreach ($recipients as $recipient) {
            if (! is_string($recipient)) {
                return [];
            }
            $email = strtolower(trim($recipient));
            if (strlen($email) > 254 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return [];
            }
            $emails[] = $email;
        }

        return array_values(array_unique($emails));
    }

    public function categories(User $user): array
    {
        return array_intersect_key(DossierStatusCatalog::manualStates(), array_flip(app(ClientAccess::class)->categoryKeys($user)));
    }

    public function save(User $user, array $data): void
    {
        abort_unless(app(ClientAccess::class)->active($user) && ! $user->must_change_password, 403);
        $user->forceFill(['portal_notification_preferences' => [
            'email_enabled' => (bool) $data['email_enabled'],
            'recipients' => $this->normalizeRecipients($data['recipients'] ?? $this->values($user)['recipients']),
            'comment_added' => (bool) $data['comment_added'],
            'document_added' => (bool) $data['document_added'],
            'statuses' => array_values(array_intersect(array_keys($this->categories($user)), $data['statuses'] ?? [])),
        ]])->save();
    }
}
