<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

/**
 * Prepares a new user: creates the database by running the migrations for that user.
 *
 * The migrations run in a separate process with the installation-wide environment only
 * (getenv(), never the superglobals): the superglobals hold the settings and secrets of
 * the user that is currently logged in, which must not reach another user's process.
 */
final readonly class MigratingUserProvisioner implements UserProvisioner
{
    public function __construct(
        private string $projectDir,
    ) {
    }

    public function provision(string $name): void
    {
        $environment = getenv();
        unset($environment[TenantEnvironment::USER_VARIABLE]);
        $environment[TenantEnvironment::USER_VARIABLE] = $name;

        $process = proc_open(
            ['php', $this->projectDir.'/bin/console', 'app:db:migrate', '--no-interaction'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->projectDir,
            $environment,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException(sprintf('Could not prepare the database of user "%s"', $name));
        }

        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (0 !== proc_close($process)) {
            throw new \RuntimeException(sprintf('Could not prepare the database of user "%s": %s', $name, trim($output)));
        }
    }
}
