<?php
namespace ETG\DynamicFilterSEOBridge\Config;

require_once __DIR__ . '/ProfileRegistryCoreTrait.php';
require_once __DIR__ . '/ProfileRegistryNormalizationTrait.php';
require_once __DIR__ . '/ProfileRegistryHelperTrait.php';

final class ProfileRegistry {
	private const MAX_PROFILES = 50;
	private const MAX_ROUTES = 20;
	private const MAX_TAXONOMY_RULES = 50;
	private const MAX_COMBINATIONS = 500;

	private $config;
	private $profiles;
	private $normalizationErrors = array();

	use ProfileRegistryCoreTrait;
	use ProfileRegistryNormalizationTrait;
	use ProfileRegistryHelperTrait;
}
