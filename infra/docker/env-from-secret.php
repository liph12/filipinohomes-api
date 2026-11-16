<?php
// Runs a command with the settings in FILIPINOHOMES_ENV_JSON added to its environment.
//   php env-from-secret.php <command> [args...]
//
// On ECS, the task definition passes the whole filipinohomes/api secret (the production .env as
// one JSON object of name => value) in this single variable, so the task definitions don't list
// each of its ~90 names. The entrypoint runs this first; it replaces itself with the command, so
// the command keeps the same process ID.
//
// A variable that's already set wins over the secret, just as a real environment variable wins
// over .env in Laravel. That's how a task definition overrides a setting (LOG_CHANNEL=stderr).
// The exception is the AWS region: the ECS agent sets AWS_REGION and AWS_DEFAULT_REGION in every
// container, so for those two the secret wins.
// FILIPINOHOMES_ENV_JSON itself is removed. Error messages name settings but never show values.

declare(strict_types=1);

const SECRET_VAR = 'FILIPINOHOMES_ENV_JSON';
const SECRET_WINS = ['AWS_REGION', 'AWS_DEFAULT_REGION'];

function fail(string $message): never
{
    fwrite(STDERR, "env-from-secret: {$message}\n");
    exit(1);
}

$command = $argv[1] ?? fail('usage: php env-from-secret.php <command> [args...]');
$args = array_slice($argv, 2);

$env = getenv();
$json = $env[SECRET_VAR] ?? '';
unset($env[SECRET_VAR]);

if ($json !== '') {
    try {
        $settings = json_decode($json, true, 2, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fail(SECRET_VAR.' is not a JSON object of strings ('.$e->getMessage().')');
    }
    if (! is_array($settings) || ($settings !== [] && array_is_list($settings))) {
        fail(SECRET_VAR.' is not a JSON object');
    }
    foreach ($settings as $name => $value) {
        $name = (string) $name;
        if ($value === null) {
            continue; // a name with no value in .env sets nothing
        }
        if (! is_string($value)) {
            fail("{$name} in ".SECRET_VAR.' is not a string');
        }
        if ($name === '' || strpbrk($name, "=\0") !== false || str_contains($value, "\0")) {
            fail("invalid setting name or value in ".SECRET_VAR.": {$name}");
        }
        if (in_array($name, SECRET_WINS, true)) {
            $env[$name] = $value;
        } else {
            $env[$name] ??= $value;
        }
    }
}

// Find the command on PATH, as exec does.
$path = $command;
if (! str_contains($command, '/')) {
    $path = null;
    foreach (explode(':', $env['PATH'] ?? '/usr/local/bin:/usr/bin:/bin') as $dir) {
        $candidate = ($dir === '' ? '.' : $dir).'/'.$command;
        if (is_file($candidate) && is_executable($candidate)) {
            $path = $candidate;
            break;
        }
    }
    $path ?? fail("command not found: {$command}");
}

pcntl_exec($path, $args, $env);
fail("could not run {$command}: ".pcntl_strerror(pcntl_get_last_error()));
