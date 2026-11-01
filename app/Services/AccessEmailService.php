<?php

namespace App\Services;

use App\Models\AllowedEmail;

class AccessEmailService
{
    public function databaseEmails(): array
    {
        return AllowedEmail::query()
            ->pluck('email')
            ->map(fn (string $email): string => strtolower(trim($email)))
            ->unique()
            ->values()
            ->all();
    }

    public function all(): array
    {
        return collect(config('registrations.allowed_emails', []))
            ->merge(AllowedEmail::query()->pluck('email'))
            ->filter(fn (mixed $email): bool => is_string($email) && $email !== '')
            ->map(fn (string $email): string => strtolower(trim($email)))
            ->unique()
            ->values()
            ->all();
    }

    public function remove(string $email): bool
    {
        return AllowedEmail::query()
            ->where('email', strtolower(trim($email)))
            ->delete() > 0;
    }

    public function removeMany(array $emails): int
    {
        return AllowedEmail::query()
            ->whereIn('email', array_map(
                static fn (string $email): string => strtolower(trim($email)),
                $emails,
            ))
            ->delete();
    }

    public function isAllowed(string $email): bool
    {
        return in_array(strtolower(trim($email)), $this->all(), true);
    }

    public function add(string $email): bool
    {
        $email = strtolower(trim($email));

        if ($this->isAllowed($email)) {
            return false;
        }

        AllowedEmail::query()->create(['email' => $email]);

        return true;
    }

    public function addMany(array $emails): int
    {
        return collect($emails)
            ->filter(fn (mixed $email): bool => is_string($email) && $this->add($email))
            ->count();
    }
}
