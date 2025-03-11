<?php
namespace TGM\TgmCopyright\Domain\Repository;

/***************************************************************
 *
 *  Copyright notice
 *
 *  (c) 2016 Paul Beck <hi@toll-paul.de>, Teamgeist Medien GbR
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TGM\TgmCopyright\Domain\Model\CopyrightReference;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\Exception\AspectNotFoundException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\QueryHelper;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\StartTimeRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\EndTimeRestriction;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * The repository for Copyrights
 */
class CopyrightReferenceRepository extends Repository
{
    /**
     * @return array|\TYPO3\CMS\Extbase\Persistence\QueryResultInterface
     */
    public function findByRootline(array $settings) {
        $displayDuplicates = (int)$settings['displayDuplicateImages'] !== 0;
        $now = time();
        $sysLanguage = $this->getLanguageId();

        // Get the connection pool for sys_file_reference table
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        if (!$displayDuplicates) {
            // When we don't want to display duplicates, first get the distinct file UIDs
            $fileUids = $this->getDistinctFileUids($settings, $now, $sysLanguage);

            if (empty($fileUids)) {
                return [];
            }

            // Then get one reference per file
            $queryBuilder = $this->createFileReferenceQueryBuilder($connectionPool);
            $this->addCommonConstraints($queryBuilder, $now, $sysLanguage);
            $this->addRootlineConstraints($queryBuilder, $settings['rootlines'], (bool) $settings['onlyCurrentPage']);

            // Add file UID constraint
            $queryBuilder->andWhere(
                $queryBuilder->expr()->in('file.uid', $queryBuilder->createNamedParameter($fileUids, Connection::PARAM_INT_ARRAY))
            );
        } else {
            // Standard query without grouping
            $queryBuilder = $this->createFileReferenceQueryBuilder($connectionPool);
            $this->addCommonConstraints($queryBuilder, $now, $sysLanguage);
            $this->addRootlineConstraints($queryBuilder, $settings['rootlines'], (bool) $settings['onlyCurrentPage']);
        }

        $preResults = $queryBuilder->executeQuery()->fetchAllAssociative();

        // Now check if the foreign record has a endtime field which is expired
        $finalRecords = $this->filterPreResultsReturnUids($preResults);

        // Final select
        if (!empty($finalRecords)) {
            return $this->getFinalRecords($connectionPool, $finalRecords);
        }

        return [];
    }

    /**
     * Get distinct file UIDs for non-duplicate image display
     *
     * @param array $settings
     * @param int $now
     * @param int $sysLanguage
     * @return array
     */
    private function getDistinctFileUids(array $settings, int $now, int $sysLanguage): array
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $fileQueryBuilder = $this->createFileReferenceQueryBuilder($connectionPool);

        $fileQueryBuilder->selectLiteral('DISTINCT file.uid as file_uid');
        $this->addCommonConstraints($fileQueryBuilder, $now, $sysLanguage);
        $this->addRootlineConstraints($fileQueryBuilder, $settings['rootlines'], (bool) $settings['onlyCurrentPage']);

        $fileResults = $fileQueryBuilder->executeQuery()->fetchAllAssociative();

        $fileUids = [];
        foreach ($fileResults as $result) {
            $fileUids[] = $result['file_uid'];
        }

        return $fileUids;
    }

    /**
     * Create a QueryBuilder for sys_file_reference with common joins
     *
     * @param ConnectionPool $connectionPool
     * @return QueryBuilder
     */
    private function createFileReferenceQueryBuilder(ConnectionPool $connectionPool): QueryBuilder
    {
        $queryBuilder = $connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();

        $queryBuilder
            ->select('ref.*')
            ->from('sys_file_reference', 'ref')
            ->leftJoin(
                'ref',
                'sys_file',
                'file',
                $queryBuilder->expr()->eq('file.uid', 'ref.uid_local')
            )
            ->leftJoin(
                'file',
                'sys_file_metadata',
                'meta',
                $queryBuilder->expr()->eq('file.uid', 'meta.file')
            )
            ->leftJoin(
                'ref',
                'pages',
                'p',
                $queryBuilder->expr()->eq('ref.pid', 'p.uid')
            );

        return $queryBuilder;
    }

    /**
     * Add common constraints to a query builder
     *
     * @param QueryBuilder $queryBuilder
     * @param int $now
     * @param int $sysLanguage
     * @return void
     */
    private function addCommonConstraints(QueryBuilder $queryBuilder, int $now, int $sysLanguage): void
    {
        $queryBuilder->where(
            $queryBuilder->expr()->or(
                $queryBuilder->expr()->isNotNull('ref.copyright'),
                $queryBuilder->expr()->neq('meta.copyright', $queryBuilder->createNamedParameter(''))
            ),
            $queryBuilder->expr()->eq('p.deleted', 0),
            $queryBuilder->expr()->eq('p.hidden', 0),
            $queryBuilder->expr()->or(
                $queryBuilder->expr()->eq('p.starttime', 0),
                $queryBuilder->expr()->lte('p.starttime', $now)
            ),
            $queryBuilder->expr()->or(
                $queryBuilder->expr()->eq('p.endtime', 0),
                $queryBuilder->expr()->gte('p.endtime', $now)
            ),
            $queryBuilder->expr()->eq('file.missing', 0),
            $queryBuilder->expr()->isNotNull('file.uid'),
            $queryBuilder->expr()->eq('ref.deleted', 0),
            $queryBuilder->expr()->eq('ref.hidden', 0),
            $queryBuilder->expr()->eq('ref.t3ver_wsid', 0),
            $queryBuilder->expr()->eq('ref.sys_language_uid', $sysLanguage)
        );
    }

    /**
     * Get final records after filtering
     *
     * @param ConnectionPool $connectionPool
     * @param array $finalRecords
     * @return array
     */
    private function getFinalRecords(ConnectionPool $connectionPool, array $finalRecords): array
    {
        $finalQueryBuilder = $connectionPool->getQueryBuilderForTable('sys_file_reference');
        $finalQueryBuilder->getRestrictions()->removeAll();

        $finalResults = $finalQueryBuilder
            ->select('*')
            ->from('sys_file_reference')
            ->where(
                $finalQueryBuilder->expr()->eq('deleted', 0),
                $finalQueryBuilder->expr()->eq('hidden', 0),
                $finalQueryBuilder->expr()->in('uid', $finalQueryBuilder->createNamedParameter($finalRecords, Connection::PARAM_INT_ARRAY))
            )
            ->executeQuery();

        $records = $finalResults->fetchAllAssociative();

        // Convert to domain objects
        $dataMapper = GeneralUtility::makeInstance(DataMapper::class);
        return $dataMapper->map(CopyrightReference::class, $records);
    }

    /**
     * Get the current language ID
     *
     * @return int
     */
    private function getLanguageId(): int
    {
        $context = GeneralUtility::makeInstance(Context::class);
        return (int) $context->getPropertyFromAspect('language', 'id');
    }

    /**
     * Helper method to add rootline constraints to a query builder
     *
     * @param QueryBuilder $queryBuilder
     * @param string|null $rootlines
     * @param bool $onlyCurrentPage
     * @return void
     */
    private function addRootlineConstraints(QueryBuilder $queryBuilder, $rootlines, bool $onlyCurrentPage = false): void {
        if ($onlyCurrentPage === true) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('ref.pid', $queryBuilder->createNamedParameter($GLOBALS['TSFE']->id, Connection::PARAM_INT))
            );
        } else if ($rootlines !== '' && $rootlines !== null) {
            $extendedPidList = $this->extendPidListByChildren($rootlines);
            $pidList = GeneralUtility::intExplode(',', $extendedPidList, true);

            $queryBuilder->andWhere(
                $queryBuilder->expr()->in('ref.pid', $queryBuilder->createNamedParameter($pidList, Connection::PARAM_INT_ARRAY))
            );
        }
    }

    /**
     * @param string|null $rootlines
     * @return array
     */
    public function findForSitemap($rootlines) {
        $sysLanguage = $this->getLanguageId();
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable('sys_file_reference');

        $constraints = [
            $queryBuilder->expr()->eq('ref.sys_language_uid', $sysLanguage),
            $queryBuilder->expr()->eq('missing', 0),
            $queryBuilder->expr()->isNotNull('file.uid'),
            $queryBuilder->expr()->in('file.type', [2, 5]),
            $queryBuilder->expr()->eq('p.no_index', 0),
            $queryBuilder->expr()->eq('p.no_follow', 0),
            $queryBuilder->expr()->eq('p.hidden', 0),
        ];

        if ($rootlines !== '' && $rootlines !== null) {
            $extendedPidList = $this->extendPidListByChildren($rootlines);
            $pidList = GeneralUtility::intExplode(',', $extendedPidList, true);

            $constraints[] = $queryBuilder->expr()->in('ref.pid', $queryBuilder->createNamedParameter($pidList, Connection::PARAM_INT_ARRAY));
        }

        $preResults = $queryBuilder
            ->selectLiteral('ref.uid', 'ref.tablenames', 'ref.uid_foreign')
            ->from('sys_file_reference', 'ref')
            ->leftJoin(
                'ref',
                'sys_file',
                'file',
                $queryBuilder->expr()->eq('file.uid', 'ref.uid_local')
            )
            ->join(
                'ref',
                'pages',
                'p',
                $queryBuilder->expr()->eq('ref.pid', 'p.uid')
            )->where(...$constraints)->executeQuery();

        $preResults = $preResults->fetchAllAssociative();

        // Now check if the foreign record has a endtime field which is expired
        $finalRecords = $this->filterPreResultsReturnUids($preResults);

        // Final select
        if (!empty($finalRecords)) {
            $queryBuilder = $connectionPool->getQueryBuilderForTable('sys_file_reference');
            $records = $queryBuilder
                ->select('*')
                ->from('sys_file_reference')
                ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($finalRecords, Connection::PARAM_INT_ARRAY)))
                ->executeQuery();

            $records = $records->fetchAllAssociative();
            $dataMapper = GeneralUtility::makeInstance(DataMapper::class);

            return $dataMapper->map(CopyrightReference::class, $records);
        }

        return [];
    }

    /**
     * This function will remove results which related table records are not hidden by endtime
     * @param array $preResults raw sql results to filter
     */
    public function filterPreResultsReturnUids($preResults): array {
        $finalRecords = [];

        // Get Schema to check if tables exist before accessing them
        /** @var Connection $connection */
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content');
        $dbSchema = $connection->getSchemaInformation();

        foreach($preResults as $preResult) {
            if((isset($preResult['tablenames']) && isset($preResult['uid_foreign']))
                && (strlen($preResult['tablenames']) > 0 && strlen($preResult['uid_foreign']) > 0)
                && true === in_array($preResult['tablenames'], $dbSchema->listTableNames())
            ) {
                /*
                 * Thanks to the QueryBuilder we don't have to check end- and starttime, deleted, hidden manually before because of the default RestrictionContainers
                 * Just check if there is a result or not
                 */
                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($preResult['tablenames']);
                $foreignRecord = $queryBuilder
                    ->select('uid')
                    ->from($preResult['tablenames'])
                    ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($preResult['uid_foreign'], Connection::PARAM_INT)))
                    ->executeQuery();

                $foreignRecord = $foreignRecord->fetchAssociative();

                if($foreignRecord === false) {
                    // Exclude if nothing found
                    continue;
                }

                // Add the record to the final select if the foreign record is not expired or does not have a field endtime
                $finalRecords[] = $preResult['uid'];
            }
        }

        return $finalRecords;
    }

    /**
     * Find all ids from given ids and level by Georg Ringer
     * @param string|null $pidList comma separated list of ids
     * @param int $recursive recursive levels
     * @return string comma separated list of ids
     */
    private function extendPidListByChildren(?string $pidList = ''): string
    {
        if ($pidList === null) {
            return '';
        }

        $recursive = 1000;
        $recursiveStoragePids = $pidList;
        $storagePids = GeneralUtility::intExplode(',', $pidList);
        foreach ($storagePids as $startPid) {
            // MODIFIED: function getTreeList copied from TYPO3 11's
            // \TYPO3\CMS\Core\Database\QueryGenerator because it has been removed in v12.
            $pids = $this->getTreeList($startPid, $recursive, 0, 1);
            if (strlen($pids) > 0) {
                $recursiveStoragePids .= ',' . $pids;
            }
        }
        return $recursiveStoragePids;
    }

    /**
     * Recursively fetch all descendants of a given page. MODIFIED:
     * Copied from TYPO3 11's \TYPO3\CMS\Core\Database\QueryGenerator.
     *
     * @param int $id uid of the page
     * @param int $depth
     * @param int $begin
     * @param string $permClause
     * @return string comma separated list of descendant pages
     */
    protected function getTreeList($id, $depth, $begin = 0, $permClause = ''): string
    {
        $depth = (int)$depth;
        $begin = (int)$begin;
        $id = (int)$id;
        if ($id < 0) {
            $id = abs($id);
        }
        if ($begin == 0) {
            $theList = (string)$id;
        } else {
            $theList = '';
        }
        if ($id && $depth > 0) {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $queryBuilder->select('uid')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($id, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('sys_language_uid', 0)
                )
                ->orderBy('uid');
            if ($permClause !== '') {
                $queryBuilder->andWhere(QueryHelper::stripLogicalOperatorPrefix($permClause));
            }
            $statement = $queryBuilder->executeQuery();
            while ($row = $statement->fetchAssociative()) {
                if ($begin <= 0) {
                    $theList .= ',' . $row['uid'];
                }
                if ($depth > 1) {
                    $theSubList = $this->getTreeList($row['uid'], $depth - 1, $begin - 1, $permClause);
                    if (!empty($theList) && !empty($theSubList) && ($theSubList[0] !== ',')) {
                        $theList .= ',';
                    }
                    $theList .= $theSubList;
                }
            }
        }
        return $theList;
    }
}
