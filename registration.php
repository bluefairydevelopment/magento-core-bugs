<?php
/**
 * @namespace   BlueFairy
 * @module      CoreBugs
 * @author      bluefairydevelopment.com
 * @email       staff@bluefairydevelopment.com
 * @brief       Fixes for confirmed Magento core bugs
 */
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'BlueFairy_CoreBugs',
    __DIR__
);
