<?php

declare(strict_types=1);

// Windows Lando takes over the containers of the WSL installation, a Windows drive serves slow file I/O
if (getenv('DOSIERO_ALLOW_WINDOWS_LANDO') === '1') {
    exit(0);
}

$windowsLando = getenv('LANDO_HOST_OS') === 'win32';
$mounts = (string)file_get_contents('/proc/self/mountinfo');
$windowsDrive = preg_match('~^\S+ \S+ \S+ \S+ /app \S+.* - 9p \S+ \S*aname=drvfs~m', $mounts) === 1;

if ($windowsLando || $windowsDrive) {
    fwrite(STDERR, sprintf(
        "ERROR: DosieroPhp runs from Linux Lando in WSL2 (~/projekty/DosieroPhp), not from %s.\n"
        . "Run 'lando poweroff' here and 'lando rebuild -y' in WSL.\n",
        $windowsLando ? 'Windows Lando' : 'a Windows drive',
    ));
    exit(1);
}
