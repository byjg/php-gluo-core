<?php

namespace Tests\Fixture;

/**
 * A class target whose two methods have the same command name: send-mail.
 */
class LauncherCollidingCommands
{
    public function sendMail(): void
    {
    }

    public function send_mail(): void
    {
    }
}
