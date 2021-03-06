<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace crafttests\fixtures;

use craft\test\fixtures\elements\GlobalSetFixture as BaseGlobalSetFixture;

/**
 * Class GlobalSetFixture
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @author Global Network Group | Giel Tettelaar <giel@yellowflash.net>
 * @since 3.2.0
 */
class GlobalSetFixture extends BaseGlobalSetFixture
{
    /**
     * @inheritdoc
     */
    public $dataFile = __DIR__ . '/data/global-sets.php';

    /**
     * @inheritdoc
     */
    public $depends = [FieldLayoutFixture::class];
}
