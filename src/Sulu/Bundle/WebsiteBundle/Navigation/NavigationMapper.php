<?php
/*
 * This file is part of the Sulu CMS.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\WebsiteBundle\Navigation;

use Sulu\Bundle\WebsiteBundle\ContentQuery\ContentQueryBuilderInterface;
use Sulu\Bundle\WebsiteBundle\ContentQuery\ContentQueryInterface;
use Sulu\Component\Content\Mapper\ContentMapperInterface;
use Sulu\Component\Content\StructureInterface;
use Sulu\Component\Content\StructureManagerInterface;
use Sulu\Component\PHPCR\SessionManager\SessionManagerInterface;

/**
 * {@inheritdoc}
 */
class NavigationMapper implements NavigationMapperInterface
{
    /**
     * @var ContentMapperInterface
     */
    private $contentMapper;

    /**
     * @var ContentQueryInterface
     */
    private $contentQuery;

    /**
     * @var ContentQueryBuilderInterface
     */
    private $queryBuilder;

    /**
     * @var SessionManagerInterface
     */
    private $sessionManager;

    function __construct(
        ContentMapperInterface $contentMapper,
        ContentQueryInterface $contentQuery,
        ContentQueryBuilderInterface $queryBuilder,
        SessionManagerInterface $sessionManager
    )
    {
        $this->contentMapper = $contentMapper;
        $this->contentQuery = $contentQuery;
        $this->queryBuilder = $queryBuilder;
        $this->sessionManager = $sessionManager;
    }

    /**
     * {@inheritdoc}
     */
    public function getNavigation($parent, $webspaceKey, $locale, $depth = 1, $flat = false, $context = null)
    {
        if ($depth !== null) {
            $contentDepth = $this->sessionManager->getContentNode($webspaceKey)->getDepth();
            $depth += $contentDepth;
        }

        $this->queryBuilder->init(
            array(
                'depth' => $depth,
                'context' => $context,
                'parent' => $parent
            )
        );
        $result = $this->contentQuery->execute($webspaceKey, array($locale), $this->queryBuilder, $flat);

        return $this->convertArrayToNavigation($result);
    }

    /**
     * {@inheritdoc}
     */
    public function getRootNavigation($webspaceKey, $locale, $depth = 1, $flat = false, $context = null)
    {
        $this->queryBuilder->init(array('depth' => $depth, 'context' => $context));
        $result = $this->contentQuery->execute($webspaceKey, array($locale), $this->queryBuilder, $flat);

        return $this->convertArrayToNavigation($result);
    }

    private function convertArrayToNavigation($items)
    {
        $navigation = array();
        foreach ($items as $item) {
            $children = (isset($item['children']) ? $this->convertArrayToNavigation($item['children']) : array());

            $navigation[] = new NavigationItem(
                null,
                $item['title'],
                $item['url'],
                $children,
                $item['uuid'],
                $item['nodeType']
            );
        }

        return $navigation;
    }

    /**
     * {@inheritdoc}
     */
    public function getBreadcrumb($uuid, $webspace, $language)
    {
        $breadcrumbItems = $this->contentMapper->loadBreadcrumb($uuid, $language, $webspace);

        $result = array();
        foreach ($breadcrumbItems as $item) {
            if ($item->getDepth() === 0) {
                $result[] = $this->contentMapper->loadStartPage($webspace, $language);
            } else {
                $result[] = $this->contentMapper->load($item->getUuid(), $webspace, $language);
            }
        }
        $result[] = $this->contentMapper->load($uuid, $webspace, $language);

        return $this->generateNavigation($result, $webspace, $language, false, null, true);
    }

    /**
     * generate navigation items for given contents
     */
    private function generateNavigation(
        $contents,
        $webspace,
        $language,
        $flat = false,
        $context = null,
        $breakOnNotInNavigation = false
    )
    {
        $result = array();

        /** @var StructureInterface $content */
        foreach ($contents as $content) {
            if ($this->inNavigation($content, $context)) {
                $url = $content->getResourceLocator();
                $title = $content->getNodeName();
                $children = $this->generateChildNavigation($content, $webspace, $language, $flat, $context);

                if (false === $flat) {
                    $result[] = new NavigationItem(
                        $content, $title, $url, $children, $content->getUuid(), $content->getNodeType()
                    );
                } else {
                    $result[] = new NavigationItem(
                        $content, $title, $url, null, $content->getUuid(), $content->getNodeType()
                    );
                    $result = array_merge($result, $children);
                }
            } elseif (true === $flat) {
                $children = $this->generateChildNavigation($content, $webspace, $language, $flat, $context);
                $result = array_merge($result, $children);
            } elseif ($breakOnNotInNavigation) {
                break;
            }
        }

        return $result;
    }

    /**
     * generate child navigation of given content
     */
    private function generateChildNavigation(
        StructureInterface $content,
        $webspace,
        $language,
        $flat = false,
        $context = null
    ) {
        $children = array();
        if (is_array($content->getChildren()) && sizeof($content->getChildren()) > 0) {
            $children = $this->generateNavigation(
                $content->getChildren(),
                $webspace,
                $language,
                $flat,
                $context
            );
        }

        return $children;
    }

    /**
     * checks if content should be displayed
     * @param StructureInterface $content
     * @param string|null $context
     * @return bool
     */
    public function inNavigation(StructureInterface $content, $context = null)
    {
        $contexts = $content->getNavContexts();

        if ($content->getNodeState() !== Structure::STATE_PUBLISHED) {
            // if node state is not published do not show page
            return false;
        }

        if (is_array($contexts) && ($context === null || in_array($context, $contexts))) {
            // all contexts or content has context
            return true;
        }

        // do not show
        return false;
    }
}
