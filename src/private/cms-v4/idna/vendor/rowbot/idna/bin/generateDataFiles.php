<?php

declare (strict_types=1);
namespace DiscussionBridge\CmsV4\IdnaVendor;

use DiscussionBridge\CmsV4\IdnaVendor\Rowbot\Idna\Bin\IdnaDataBuilder;
use DiscussionBridge\CmsV4\IdnaVendor\Rowbot\Idna\Bin\RegexBuilder;
use DiscussionBridge\CmsV4\IdnaVendor\Rowbot\Idna\Bin\ViramaDataBuilder;
const DS = \DIRECTORY_SEPARATOR;
const ROOT_DIR = __DIR__ . \DiscussionBridge\CmsV4\IdnaVendor\DS . '..';
const OUTPUT_DIR = \DiscussionBridge\CmsV4\IdnaVendor\ROOT_DIR . \DiscussionBridge\CmsV4\IdnaVendor\DS . 'resources';
require \DiscussionBridge\CmsV4\IdnaVendor\ROOT_DIR . \DiscussionBridge\CmsV4\IdnaVendor\DS . 'vendor' . \DiscussionBridge\CmsV4\IdnaVendor\DS . 'autoload.php';
ViramaDataBuilder::buildHashMap(\DiscussionBridge\CmsV4\IdnaVendor\OUTPUT_DIR);
RegexBuilder::buildRegexClass(\DiscussionBridge\CmsV4\IdnaVendor\OUTPUT_DIR);
IdnaDataBuilder::buildHashMaps(\DiscussionBridge\CmsV4\IdnaVendor\OUTPUT_DIR);
