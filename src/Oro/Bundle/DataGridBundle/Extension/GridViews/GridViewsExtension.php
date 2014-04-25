<?php

namespace Oro\Bundle\DataGridBundle\Extension\GridViews;

use Symfony\Component\Config\Definition\Exception\InvalidTypeException;

use Oro\Bundle\DataGridBundle\Extension\AbstractExtension;
use Oro\Bundle\DataGridBundle\Datagrid\ParameterBag;
use Oro\Bundle\DataGridBundle\Datagrid\Common\MetadataObject;
use Oro\Bundle\DataGridBundle\Datagrid\Common\DatagridConfiguration;

class GridViewsExtension extends AbstractExtension
{
    const VIEWS_LIST_KEY  = 'views_list';
    const VIEWS_PARAM_KEY = 'view';

    /**
     * {@inheritDoc}
     */
    public function isApplicable(DatagridConfiguration $config)
    {
        $list = $config->offsetGetOr(self::VIEWS_LIST_KEY, false);

        if ($list !== false && !$list instanceof AbstractViewsList) {
            throw new InvalidTypeException(
                sprintf(
                    'Invalid type for path "%s.%s". Expected AbstractViewsList, but got %s.',
                    $config->getName(),
                    self::VIEWS_LIST_KEY,
                    gettype($list)
                )
            );
        }

        return $list !== false;
    }

    /**
     * {@inheritDoc}
     */
    public function visitMetadata(DatagridConfiguration $config, MetadataObject $data)
    {
        $params      = $this->getParameters()->get(ParameterBag::ADDITIONAL_PARAMETERS);
        $currentView = isset($params[self::VIEWS_PARAM_KEY]) ? $params[self::VIEWS_PARAM_KEY] : null;
        $data->offsetAddToArray('state', ['gridView' => $currentView]);

        /** @var AbstractViewsList $list */
        $list = $config->offsetGetOr(self::VIEWS_LIST_KEY, false);
        if ($list !== false) {
            $data->offsetSet('gridViews', $list->getMetadata());
        }
    }
}
