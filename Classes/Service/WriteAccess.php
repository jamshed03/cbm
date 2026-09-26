<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Where the backend module may write files: only in the contexts of the extension setting "writableContexts"
 * (default: Development), e.g. also on staging to let editors try out changes, and never into packages installed
 * into vendor/, which the next composer install would overwrite.
 *
 * It guards the backend module only: the cbm:* commands are tools for developers and deployments and are not restricted.
 */
final readonly class WriteAccess
{
    public function __construct(
        private PackageManager $packageManager,
    ) {
    }

    public function isContextAllowed(): bool
    {
        $context = (string) Environment::getContext();
        foreach ($this->getWritableContexts() as $allowed) {
            if ($context === $allowed || str_starts_with($context, $allowed . '/')) {
                return true;
            }
        }
        return false;
    }

    public function isExtensionWritable(string $extension): bool
    {
        if (!$this->isContextAllowed() || !$this->packageManager->isPackageAvailable($extension)) {
            return false;
        }
        $packagePath = (string) realpath($this->packageManager->getPackage($extension)->getPackagePath());
        return !str_starts_with($packagePath, Environment::getProjectPath() . '/vendor/');
    }

    /**
     * @return list<string>
     */
    private function getWritableContexts(): array
    {
        // Not ExtensionConfiguration::get(): while the setting was never saved, it synchronizes the configuration of
        // all extensions and rewrites config/system/settings.php, including values additional.php sets at runtime.
        $setting = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['cbm']['writableContexts'] ?? 'Development';
        return GeneralUtility::trimExplode(',', (string) $setting, true);
    }
}
