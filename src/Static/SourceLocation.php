<?php

namespace Dgtlss\Clearance\Static;

use Composer\InstalledVersions;

final class SourceLocation
{
    public function forFile(string $path, int $line): array
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (str_starts_with($path, $base)) {
            return ['file' => substr($path, strlen($base)), 'line' => $line];
        }
        $roots = [];
        foreach (InstalledVersions::getInstalledPackages() as $package) {
            $install = InstalledVersions::getInstallPath($package);
            $root = $install !== null ? realpath($install) : false;
            if ($root !== false) {
                $roots[$root.DIRECTORY_SEPARATOR] = 'vendor/'.$package.'/';
            }
        }
        uksort($roots, fn (string $a, string $b) => strlen($b) <=> strlen($a));
        foreach ($roots as $root => $relative) {
            if (str_starts_with($path, $root)) {
                return ['file' => $relative.substr($path, strlen($root)), 'line' => $line];
            }
        }

        return ['file' => 'external/'.basename($path), 'line' => $line];
    }
}
