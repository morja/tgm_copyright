<?php

declare(strict_types=1);

namespace TGM\TgmCopyright\Updates;

use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\AbstractListTypeToCTypeUpdate;

#[UpgradeWizard('tgmTgmCopyrightCTypeMigration')]
final class TGMTgmCopyrightCTypeMigration extends AbstractListTypeToCTypeUpdate
{
    public function getTitle(): string
    {
        return 'Migrate "TGM TgmCopyright" plugins to content elements.';
    }

    public function getDescription(): string
    {
        return 'The "TGM TgmCopyright" plugins are now registered as content element. Update migrates existing records and backend user permissions.';
    }

    /**
     * This must return an array containing the "list_type" to "CType" mapping
     *
     *  Example:
     *
     *  [
     *      'pi_plugin1' => 'pi_plugin1',
     *      'pi_plugin2' => 'new_content_element',
     *  ]
     *
     * @return array<string, string>
     */
    protected function getListTypeToCTypeMapping(): array
    {
        return [
            'tgmcopyright_main' => 'tgmcopyright_main',
        ];
    }
}
